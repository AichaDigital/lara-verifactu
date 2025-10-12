<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Exceptions\AeatAuthenticationException;
use AichaDigital\LaraVerifactu\Exceptions\AeatConnectionException;
use AichaDigital\LaraVerifactu\Exceptions\AeatRejectionException;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use SoapClient;
use SoapFault;

final class AeatClient implements AeatClientContract
{
    private ?SoapClient $client = null;

    public function __construct(
        private readonly string $endpoint,
        private readonly CertificateManagerContract $certificateManager,
        private readonly int $timeout = 30,
        private readonly bool $verifySSL = true,
    ) {}

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

            // Sign XML with certificate
            $signedXml = $this->signXml($xml);

            // Send to AEAT
            if ($this->client === null) {
                throw AeatConnectionException::cannotConnect($this->endpoint);
            }

            $response = $this->client->enviarRegistro([
                'xml' => $signedXml,
            ]);

            Log::channel(config('verifactu.logging.channel', 'verifactu'))
                ->info('Registry sent to AEAT', [
                    'registry_number' => $registry->getRegistryNumber(),
                    'response' => $response,
                ]);

            return $this->parseResponse($response);
        } catch (SoapFault $e) {
            return $this->handleSoapFault($e);
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
            $this->client = new SoapClient(
                $this->endpoint . '?wsdl',
                [
                    'connection_timeout' => $this->timeout,
                    'cache_wsdl' => WSDL_CACHE_NONE,
                    'trace' => true,
                    'exceptions' => true,
                    'soap_version' => SOAP_1_1,
                    'stream_context' => stream_context_create([
                        'ssl' => [
                            'verify_peer' => $this->verifySSL,
                            'verify_peer_name' => $this->verifySSL,
                        ],
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
        // Note: Actual AEAT response parsing would be more complex
        // This is a simplified version for the initial implementation

        if (is_object($response) && isset($response->resultado)) {
            if ($response->resultado === 'ACEPTADO') {
                return AeatResponse::success(
                    data: (array) $response,
                    message: 'Registry accepted by AEAT'
                );
            }

            if ($response->resultado === 'RECHAZADO') {
                $errors = isset($response->errores) ? (array) $response->errores : [];
                $errorMessages = array_map(fn ($e) => is_object($e) ? ($e->descripcion ?? 'Unknown error') : (string) $e, $errors);

                return AeatResponse::failure(
                    errors: $errorMessages,
                    message: 'Registry rejected by AEAT',
                    code: $response->codigo ?? null
                );
            }
        }

        return AeatResponse::success(data: (array) $response);
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
