<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Exceptions\AeatAuthenticationException;
use AichaDigital\LaraVerifactu\Exceptions\AeatConnectionException;
use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Exceptions\AeatRejectionException;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use SoapClient;
use SoapFault;

final class AeatClient implements AeatClientContract
{
    private ?SoapClient $client = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $tempCertFiles = null;

    public function __construct(
        private readonly string $endpoint,
        private readonly CertificateManagerContract $certificateManager,
        private readonly int $timeout = 30,
        private readonly bool $verifySSL = true,
    ) {}

    /**
     * Cleanup temp certificate files
     */
    public function __destruct()
    {
        if ($this->tempCertFiles !== null) {
            foreach ($this->tempCertFiles as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Send registration to AEAT
     *
     * @throws AeatConnectionException|AeatAuthenticationException|AeatRejectionException
     */
    public function sendRegistration(RegistryContract $registry): AeatResponse
    {
        try {
            $this->ensureClientInitialized();

            $xml = $registry->getSignedXml() ?? $registry->getXml();

            if ($xml === '') {
                throw AeatException::connectionFailed('Empty XML content for registry');
            }

            // Sign XML with certificate
            $signedXml = $this->signXml($xml);

            // Send to AEAT
            if ($this->client === null) {
                throw AeatConnectionException::cannotConnect($this->endpoint);
            }

            // Parse XML to use correct SOAP structure
            $xmlElement = new SimpleXMLElement($signedXml);

            // Call AEAT web service with the correct operation name from WSDL
            $response = $this->client->__soapCall('RegFactuSistemaFacturacion', [
                'RegFactuSistemaFacturacion' => $xmlElement,
            ]);

            Log::channel(config('verifactu.logging.channel', 'verifactu'))
                ->info('Registry sent to AEAT', [
                    'registry_number' => $registry->getRegistryNumber(),
                    'response' => $response,
                ]);

            return $this->parseResponse($response);
        } catch (SoapFault $e) {
            return $this->handleSoapFault($e);
        } catch (\Exception $e) {
            throw AeatException::connectionFailed($e->getMessage());
        }
    }

    /**
     * Send cancellation to AEAT
     *
     * @throws AeatConnectionException|AeatAuthenticationException|AeatRejectionException
     */
    public function sendCancellation(string $registryId): AeatResponse
    {
        try {
            $this->ensureClientInitialized();

            if ($this->client === null) {
                throw AeatConnectionException::cannotConnect($this->endpoint);
            }

            $response = $this->client->anularRegistro([
                'registryId' => $registryId,
            ]);

            Log::channel(config('verifactu.logging.channel', 'verifactu'))
                ->info('Cancellation sent to AEAT', [
                    'registry_id' => $registryId,
                    'response' => $response,
                ]);

            return $this->parseResponse($response);
        } catch (SoapFault $e) {
            return $this->handleSoapFault($e);
        }
    }

    /**
     * Send batch of registrations to AEAT
     *
     * @param  \Illuminate\Support\Collection<int, RegistryContract>  $registries
     * @return \Illuminate\Support\Collection<int, AeatResponse>
     *
     * @throws AeatConnectionException|AeatAuthenticationException
     */
    public function sendBatch(Collection $registries): Collection
    {
        $responses = collect();

        foreach ($registries as $registry) {
            try {
                $response = $this->sendRegistration($registry);
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
     * Query registry status from AEAT
     *
     * @throws AeatConnectionException|AeatAuthenticationException
     */
    public function queryRegistry(string $registryId): AeatResponse
    {
        try {
            $this->ensureClientInitialized();

            if ($this->client === null) {
                throw AeatConnectionException::cannotConnect($this->endpoint);
            }

            $response = $this->client->consultarRegistro([
                'registryId' => $registryId,
            ]);

            return $this->parseResponse($response);
        } catch (SoapFault $e) {
            return $this->handleSoapFault($e);
        }
    }

    /**
     * Validate QR code with AEAT
     *
     * @throws AeatConnectionException|AeatAuthenticationException
     */
    public function validateQr(string $qrCode): AeatResponse
    {
        try {
            $this->ensureClientInitialized();

            if ($this->client === null) {
                throw AeatConnectionException::cannotConnect($this->endpoint);
            }

            $response = $this->client->validarQR([
                'qrCode' => $qrCode,
            ]);

            return $this->parseResponse($response);
        } catch (SoapFault $e) {
            return $this->handleSoapFault($e);
        }
    }

    /**
     * Ensure SOAP client is initialized
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
            $wsdl = config("verifactu.aeat.wsdl.{$environment}");

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
     * Sign XML with certificate
     */
    private function signXml(string $xml): string
    {
        return $this->certificateManager->sign($xml);
    }

    /**
     * Parse AEAT response
     */
    private function parseResponse(mixed $response): AeatResponse
    {
        // Log raw response for debugging
        if ($this->client !== null) {
            Log::debug('AEAT SOAP Request', [
                'request' => $this->client->__getLastRequest(),
            ]);
            Log::debug('AEAT SOAP Response', [
                'response' => $this->client->__getLastResponse(),
            ]);
        }

        // Parse response based on AEAT structure
        if (is_object($response)) {
            // Check for success indicators (CSV = Código Seguro de Verificación)
            $success = property_exists($response, 'CSV') ||
                       (property_exists($response, 'EstadoEnvio') && $response->EstadoEnvio === 'Correcto');

            if ($success) {
                $data = [
                    'csv' => $response->CSV ?? null,
                    'estado' => $response->EstadoEnvio ?? null,
                    'codigo_seguro' => $response->CodigoSeguro ?? null,
                    'timestamp' => $response->TimestampPresentacion ?? null,
                ];

                return AeatResponse::success(
                    $data,
                    'Registro enviado correctamente'
                );
            }

            // Handle errors
            $errors = [];
            if (property_exists($response, 'RegistroDuplicado')) {
                $errors[] = 'Registro duplicado';
            }
            if (property_exists($response, 'RespuestaLinea')) {
                foreach ((array) $response->RespuestaLinea as $linea) {
                    if (isset($linea->CodigoErrorRegistro)) {
                        $errors[] = $linea->DescripcionErrorRegistro ?? $linea->CodigoErrorRegistro;
                    }
                }
            }

            if (! empty($errors)) {
                return AeatResponse::failure(
                    $errors,
                    'Error en el envío del registro',
                    $response->CodigoErrorRegistro ?? null
                );
            }

            // Check for old-style response format
            if (isset($response->resultado)) {
                if ($response->resultado === 'ACEPTADO') {
                    return AeatResponse::success(
                        data: (array) $response,
                        message: 'Registry accepted by AEAT'
                    );
                }

                if ($response->resultado === 'RECHAZADO') {
                    $errorList = isset($response->errores) ? (array) $response->errores : [];
                    $errorMessages = array_map(fn ($e) => is_object($e) ? ($e->descripcion ?? 'Unknown error') : (string) $e, $errorList);

                    return AeatResponse::failure(
                        errors: $errorMessages,
                        message: 'Registry rejected by AEAT',
                        code: $response->codigo ?? null
                    );
                }
            }
        }

        return AeatResponse::failure(
            ['Respuesta inválida del servidor AEAT'],
            'Error desconocido'
        );
    }

    /**
     * Handle SOAP fault exceptions
     *
     * @throws AeatConnectionException|AeatAuthenticationException|AeatRejectionException
     */
    private function handleSoapFault(SoapFault $e): AeatResponse
    {
        Log::channel(config('verifactu.logging.channel', 'verifactu'))
            ->error('SOAP Fault from AEAT', [
                'code' => $e->faultcode,
                'message' => $e->faultstring,
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
