<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Events\BlockchainVerifiedEvent;
use AichaDigital\LaraVerifactu\Events\InvoiceRegisteredEvent;
use AichaDigital\LaraVerifactu\Events\RegistryFailedEvent;
use AichaDigital\LaraVerifactu\Events\RegistrySubmittedEvent;
use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Invoice Registrar Service
 *
 * Main orchestrator service for invoice registration process.
 * Handles the complete flow from registry creation to AEAT submission.
 */
final class InvoiceRegistrar
{
    public function __construct(
        private readonly RegistryManager $registryManager,
        private readonly CertificateManagerContract $certificateManager,
        private readonly AeatClientContract $aeatClient
    ) {}

    /**
     * Register an invoice in the Verifactu system
     *
     * Complete flow:
     * 1. Create registry with hash, QR, and XML
     * 2. Sign XML with certificate
     * 3. Submit to AEAT
     * 4. Update registry status based on response
     *
     * @throws VerifactuException
     */
    public function register(InvoiceContract $invoice, bool $submitToAeat = true): RegistryContract
    {
        return DB::transaction(function () use ($invoice, $submitToAeat) {
            // Step 1: Create registry
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->info('Creating registry for invoice', [
                    'invoice_number' => $invoice->getNumber(),
                    'serie' => $invoice->getSerie(),
                ]);

            $registry = $this->registryManager->createRegistry($invoice);

            // Step 2: Sign XML
            try {
                $signedXml = $this->signXml($registry->getXml());

                if ($registry instanceof \AichaDigital\LaraVerifactu\Models\Registry) {
                    $registry->update(['signed_xml' => $signedXml]);
                }
            } catch (\Throwable $e) {
                Log::channel(config('verifactu.logging.channel', 'single'))
                    ->warning('Failed to sign XML', [
                        'registry_number' => $registry->getRegistryNumber(),
                        'error' => $e->getMessage(),
                    ]);
                // Continue without signed XML (optional feature)
            }

            // Step 3: Submit to AEAT if requested
            if ($submitToAeat) {
                $this->submitToAeat($registry);
            }

            // Dispatch event
            event(new InvoiceRegisteredEvent($invoice, $registry, $submitToAeat));

            return $registry;
        });
    }

    /**
     * Submit a registry to AEAT
     *
     * @throws AeatException
     */
    public function submitToAeat(RegistryContract $registry): AeatResponse
    {
        try {
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->info('Submitting registry to AEAT', [
                    'registry_number' => $registry->getRegistryNumber(),
                ]);

            // Submit to AEAT
            $response = $this->aeatClient->sendRegistration($registry);

            // Update registry based on response
            if ($response->isSuccess()) {
                $this->registryManager->markAsSubmitted(
                    $registry,
                    $response->getCsv() ?? '',
                    $response->getMessage() ?? ''
                );

                Log::channel(config('verifactu.logging.channel', 'single'))
                    ->info('Registry submitted successfully', [
                        'registry_number' => $registry->getRegistryNumber(),
                        'csv' => $response->getCsv(),
                    ]);

                // Dispatch success event
                event(new RegistrySubmittedEvent($registry, $response));
            } else {
                $this->registryManager->markAsFailed(
                    $registry,
                    $response->getErrorMessage()
                );

                Log::channel(config('verifactu.logging.channel', 'single'))
                    ->error('Registry submission failed', [
                        'registry_number' => $registry->getRegistryNumber(),
                        'error' => $response->getErrorMessage(),
                    ]);

                // Dispatch failure event
                event(new RegistryFailedEvent($registry, $response->getErrorMessage(), $registry->getSubmissionAttempts()));
            }

            return $response;
        } catch (\Throwable $e) {
            $this->registryManager->markAsFailed($registry, $e->getMessage());

            Log::channel(config('verifactu.logging.channel', 'single'))
                ->error('Exception during AEAT submission', [
                    'registry_number' => $registry->getRegistryNumber(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

            // Dispatch failure event
            event(new RegistryFailedEvent($registry, $e->getMessage(), $registry->getSubmissionAttempts()));

            throw AeatException::connectionFailed($e->getMessage());
        }
    }

    /**
     * Batch register multiple invoices
     *
     * @param  array<InvoiceContract>  $invoices
     * @return array{success: int, failed: int, registries: array<RegistryContract>}
     */
    public function batchRegister(array $invoices, bool $submitToAeat = true): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'registries' => [],
        ];

        foreach ($invoices as $invoice) {
            try {
                $registry = $this->register($invoice, $submitToAeat);
                $results['registries'][] = $registry;
                $results['success']++;
            } catch (\Throwable $e) {
                $results['failed']++;

                Log::channel(config('verifactu.logging.channel', 'single'))
                    ->error('Failed to register invoice in batch', [
                        'invoice_number' => $invoice->getNumber(),
                        'error' => $e->getMessage(),
                    ]);
            }
        }

        return $results;
    }

    /**
     * Retry failed registries
     *
     * @return array{success: int, failed: int, skipped: int}
     */
    public function retryFailed(int $maxAttempts = 3, int $limit = 50): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $registries = $this->registryManager->getRetryableRegistries($maxAttempts, $limit);

        foreach ($registries as $registry) {
            // Skip if max attempts reached
            if ($registry->getSubmissionAttempts() >= $maxAttempts) {
                $results['skipped']++;

                continue;
            }

            try {
                $response = $this->submitToAeat($registry);

                if ($response->isSuccess()) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                }
            } catch (\Throwable $e) {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Verify blockchain integrity
     *
     * @return array{valid: bool, errors: array<string>}
     */
    public function verifyBlockchain(): array
    {
        $result = $this->registryManager->verifyBlockchain();

        // Dispatch event
        event(new BlockchainVerifiedEvent($result));

        return $result;
    }

    /**
     * Sign XML with certificate
     *
     * @throws VerifactuException
     */
    private function signXml(string $xml): string
    {
        $certificatePath = config('verifactu.certificate.path');
        $certificatePassword = config('verifactu.certificate.password');

        if (! $certificatePath || ! file_exists($certificatePath)) {
            throw VerifactuException::make('Certificate file not found');
        }

        $this->certificateManager->load($certificatePath, $certificatePassword);

        return $this->certificateManager->sign($xml);
    }
}
