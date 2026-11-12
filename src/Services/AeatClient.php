<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Exceptions\AeatAuthenticationException;
use AichaDigital\LaraVerifactu\Exceptions\AeatConnectionException;
use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Exceptions\ValidationException;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuDisabledException;
use AichaDigital\LaraVerifactu\Support\AeatLogSanitizer;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use AichaDigital\LaraVerifactu\Support\SendAdmission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use SoapClient;
use SoapFault;
use SoapVar;

/**
 * SOAP client for the AEAT Verifactu web service.
 *
 * The official WSDL exposes a single operation, RegFactuSistemaFacturacion,
 * which carries both registration (RegistroAlta) and cancellation
 * (RegistroAnulacion) records. Authentication uses a client certificate
 * (mutual TLS); the registry XML must arrive already signed — signing is
 * the registrar's responsibility, not this client's.
 */
final class AeatClient implements AeatClientContract
{
    private const SOAP_OPERATION = 'RegFactuSistemaFacturacion';

    private ?SoapClient $client = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $tempCertFiles = null;

    public function __construct(
        private readonly string $endpoint,
        private readonly int $timeout = 30,
        private readonly bool $verifySSL = true,
        private readonly AeatResponseParser $responseParser = new AeatResponseParser,
    ) {}

    /**
     * Cleanup temp certificate files
     */
    public function __destruct()
    {
        if ($this->tempCertFiles !== null) {
            foreach ($this->tempCertFiles as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * Send a registry record (registration or cancellation) to AEAT
     *
     * Guarded by the activation switch (AID-1907), for direct users of this
     * class. The registrar never reaches this method: its admitted send goes
     * through sendCore() with the admission its re-check granted.
     *
     * @throws AeatConnectionException|AeatAuthenticationException
     * @throws VerifactuDisabledException When the package is disabled.
     */
    public function sendRegistration(RegistryContract $registry): AeatResponse
    {
        return $this->sendCore($registry, SendAdmission::grant(
            fn () => VerifactuDisabledException::forOperation('AeatClient::sendRegistration'),
        ));
    }

    /**
     * The admitted send (AID-1907, spec §4.1 rule (b)): it reads no activation,
     * because the caller's admission already carries the decision, and it
     * cannot be reached without one (gate round 1, finding 1). The admission
     * is spent here, so it covers this one send.
     *
     * @internal
     *
     * @throws AeatConnectionException|AeatAuthenticationException
     * @throws \LogicException When the admission was already spent.
     */
    public function sendCore(RegistryContract $registry, SendAdmission $admission): AeatResponse
    {
        $admission->spend();

        return $this->transmit($registry);
    }

    /**
     * The SOAP exchange of one registry, after admission.
     *
     * @throws AeatConnectionException|AeatAuthenticationException
     */
    private function transmit(RegistryContract $registry): AeatResponse
    {
        try {
            $this->ensureClientInitialized();

            $xml = $registry->getSignedXml() ?? $registry->getXml();

            if ($xml === null || $xml === '') {
                throw ValidationException::invalidXml('Registry XML content is missing.');
            }

            if ($this->client === null) {
                throw AeatConnectionException::cannotConnect($this->endpoint);
            }

            // Inject the document literally: the registry XML is already a
            // complete RegFactuSistemaFacturacion element. The XML declaration
            // must be stripped — embedded inside the SOAP Body it would make
            // the envelope invalid.
            $payload = new SoapVar($this->stripXmlDeclaration($xml), XSD_ANYXML);

            $response = $this->client->__soapCall(self::SOAP_OPERATION, [$payload]);

            Log::channel(config('verifactu.logging.channel', 'verifactu'))
                ->info('Registry sent to AEAT', [
                    'registry_number' => $registry->getRegistryNumber(),
                ]);

            $this->logSoapExchange();

            return $this->responseParser->parse($response);
        } catch (SoapFault $e) {
            return $this->handleSoapFault($e);
        } catch (\Exception $e) {
            throw AeatException::connectionFailed($e->getMessage());
        }
    }

    /**
     * Send batch of registry records to AEAT
     *
     * A batch is one admitted effect (AID-1907): activation is checked once,
     * here, and a switch change during the loop neither stops it nor turns
     * the remaining items into generic failures.
     *
     * @param  Collection<int, RegistryContract>  $registries
     * @return Collection<int, AeatResponse>
     *
     * @throws VerifactuDisabledException When the package is disabled; nothing is sent.
     */
    public function sendBatch(Collection $registries): Collection
    {
        return $this->sendBatchCore($registries, SendAdmission::grant(
            fn () => VerifactuDisabledException::forOperation('AeatClient::sendBatch'),
        ));
    }

    /**
     * The admitted batch loop (AID-1907). One admission, spent at entry,
     * covers the whole batch: the loop reads no activation, so a switch
     * change mid-batch leaves every item its real answer. Its only callers
     * are sendBatch() and LazyAeatClient::sendBatch().
     *
     * @internal
     *
     * @param  Collection<int, RegistryContract>  $registries
     * @return Collection<int, AeatResponse>
     *
     * @throws \LogicException When the admission was already spent.
     */
    public function sendBatchCore(Collection $registries, SendAdmission $admission): Collection
    {
        $admission->spend();

        $responses = collect();

        foreach ($registries as $registry) {
            try {
                $response = $this->transmit($registry);
                $responses->push($response);
            } catch (\Throwable $e) {
                $responses->push(AeatResponse::failure(
                    errors: [$e->getMessage()],
                    message: 'Failed to send registry',
                    code: $e->getCode() ? (string) $e->getCode() : null
                ));
            }
        }

        return $responses;
    }

    /**
     * Ensure SOAP client is initialized with mutual TLS authentication
     *
     * @throws AeatConnectionException
     */
    private function ensureClientInitialized(): void
    {
        if ($this->client !== null) {
            return;
        }

        try {
            $environment = config('verifactu.aeat.environment', 'production');

            // Default to the bundled official WSDL (offline, no network
            // dependency). The endpoint is forced via 'location' below —
            // the WSDL declares 8 ports and SoapClient would otherwise
            // pick the first one regardless of environment.
            $wsdl = config("verifactu.aeat.wsdl.{$environment}")
                ?? __DIR__ . '/../../resources/wsdl/SistemaFacturacion.wsdl';

            // Get certificate configuration
            $certPath = config('verifactu.certificate.path');
            $certPassword = config('verifactu.certificate.password');

            // Prepare SSL context with certificate authentication
            $sslContext = [
                'verify_peer' => $this->verifySSL,
                'verify_peer_name' => $this->verifySSL,
                'allow_self_signed' => ! $this->verifySSL,
            ];

            // Add certificate if provided
            if ($certPath && file_exists($certPath)) {
                // For .p12 certificates, we need to extract cert and key to temp files
                $pkcs12 = file_get_contents($certPath);

                if ($pkcs12 === false) {
                    throw AeatConnectionException::cannotConnect($this->endpoint);
                }

                $certs = [];

                if (openssl_pkcs12_read($pkcs12, $certs, $certPassword ?? '')) {
                    // Create temporary files for cert and key
                    $tempCertFile = tempnam(sys_get_temp_dir(), 'verifactu_cert_');
                    $tempKeyFile = tempnam(sys_get_temp_dir(), 'verifactu_key_');

                    file_put_contents($tempCertFile, $certs['cert']);
                    file_put_contents($tempKeyFile, $certs['pkey']);

                    $sslContext['local_cert'] = $tempCertFile;
                    $sslContext['local_pk'] = $tempKeyFile;
                    $sslContext['passphrase'] = $certPassword ?? '';

                    // Store temp files for cleanup
                    $this->tempCertFiles = [
                        'cert' => $tempCertFile,
                        'key' => $tempKeyFile,
                    ];
                }
            }

            $this->client = new SoapClient(
                $wsdl,
                [
                    'location' => $this->endpoint,
                    'connection_timeout' => $this->timeout,
                    'cache_wsdl' => WSDL_CACHE_NONE,
                    'trace' => true,
                    'exceptions' => true,
                    'soap_version' => SOAP_1_1,
                    'stream_context' => stream_context_create([
                        'ssl' => $sslContext,
                    ]),
                ]
            );
        } catch (\Throwable $e) {
            throw AeatConnectionException::cannotConnect($this->endpoint);
        }
    }

    /**
     * Strip the XML declaration so the document can be embedded in a SOAP Body
     */
    private function stripXmlDeclaration(string $xml): string
    {
        return (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $xml);
    }

    /**
     * Log the SOAP exchange — opt-in and always redacted (see AeatLogSanitizer).
     */
    private function logSoapExchange(): void
    {
        if ($this->client === null) {
            return;
        }

        AeatLogSanitizer::logExchange(
            $this->client->__getLastRequest(),
            $this->client->__getLastResponse()
        );
    }

    /**
     * Handle SOAP fault exceptions
     *
     * @throws AeatConnectionException|AeatAuthenticationException
     */
    private function handleSoapFault(SoapFault $e): AeatResponse
    {
        Log::channel(config('verifactu.logging.channel', 'verifactu'))
            ->error('SOAP Fault from AEAT', [
                'code' => $e->faultcode,
                'message' => AeatLogSanitizer::redactText((string) $e->faultstring),
            ]);

        // Check for authentication errors
        if (str_contains(strtolower($e->faultstring), 'auth')) {
            throw AeatAuthenticationException::invalidCredentials();
        }

        // Check for connection errors
        if (str_contains(strtolower($e->faultstring), 'connect')) {
            throw AeatConnectionException::cannotConnect($this->endpoint);
        }

        return AeatResponse::failure(
            errors: [$e->faultstring],
            message: 'SOAP communication error',
            code: (string) $e->faultcode
        );
    }
}
