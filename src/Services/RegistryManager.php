<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\RegistryCreatedEvent;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Models\Registry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registry Manager Service
 *
 * Manages registry operations including creation, blockchain validation,
 * hash generation, QR code generation, and XML building.
 */
final class RegistryManager
{
    public function __construct(
        private readonly HashGeneratorContract $hashGenerator,
        private readonly QrGeneratorContract $qrGenerator,
        private readonly XmlBuilderContract $xmlBuilder,
    ) {}

    /**
     * Create a new registry for an invoice
     *
     * This method handles the complete registry creation process:
     * 1. Get previous hash from blockchain
     * 2. Generate current hash
     * 3. Build XML
     * 4. Generate QR codes
     * 5. Save registry to database
     *
     * @throws VerifactuException
     */
    public function createRegistry(InvoiceContract $invoice): RegistryContract
    {
        return DB::transaction(function () use ($invoice) {
            // Get previous hash for blockchain
            $previousHash = $this->getPreviousHash();

            // Generate hash for this invoice. The generation timestamp is part
            // of the hashed chain (FechaHoraHusoGenRegistro) and must be
            // persisted so the hash can be verified later.
            $generatedAt = Carbon::now();
            $hash = $this->hashGenerator->generate($invoice, $previousHash, $generatedAt);

            // Generate registry number
            $registryNumber = $this->generateRegistryNumber();

            // Build XML
            $xml = $this->xmlBuilder->buildRegistrationXml($invoice);

            // Generate QR codes (AEAT cotejo URL: nif, numserie, fecha, importe)
            $qrUrl = $this->qrGenerator->generateUrl($invoice);
            $qrSvg = $this->qrGenerator->generateSvg($invoice);
            $qrPng = $this->qrGenerator->generatePng($invoice);

            // Create registry
            $registry = Registry::create([
                'invoice_id' => $invoice->id ?? null,
                'registry_number' => $registryNumber,
                'registry_date' => Carbon::now(),
                'hash' => $hash,
                'previous_hash' => $previousHash,
                'hash_generated_at' => $generatedAt->format('c'),
                'qr_url' => $qrUrl,
                'qr_svg' => $qrSvg,
                'qr_png' => $qrPng,
                'xml' => $xml,
                'status' => RegistryStatusEnum::PENDING->value,
                'submission_attempts' => 0,
            ]);

            // Dispatch event
            event(new RegistryCreatedEvent($registry, $invoice));

            return $registry;
        });
    }

    /**
     * Get the previous hash from the blockchain
     *
     * Returns the hash of the last registry in the chain, or null if this is the first.
     */
    public function getPreviousHash(): ?string
    {
        $lastRegistry = Registry::orderBy('registry_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return $lastRegistry?->hash;
    }

    /**
     * Verify blockchain integrity
     *
     * Checks that all registries in the chain are properly linked.
     *
     * @return array{valid: bool, errors: array<string>}
     */
    public function verifyBlockchain(): array
    {
        $errors = [];
        $registries = Registry::orderBy('registry_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $previousHash = null;

        foreach ($registries as $registry) {
            // Check if previous hash matches
            if ($registry->previous_hash !== $previousHash) {
                $errors[] = sprintf(
                    'Registry %s has invalid previous hash. Expected: %s, Got: %s',
                    $registry->registry_number,
                    $previousHash ?? 'null',
                    $registry->previous_hash ?? 'null'
                );
            }

            // Verify hash by rebuilding the chain with the persisted data
            try {
                $isValid = $this->hashGenerator->verify(
                    $registry->hash,
                    $registry->invoice,
                    $registry->previous_hash,
                    $registry->hash_generated_at !== null ? Carbon::parse($registry->hash_generated_at) : null,
                );
                if (! $isValid) {
                    $errors[] = sprintf(
                        'Registry %s has invalid hash',
                        $registry->registry_number
                    );
                }
            } catch (\Throwable $e) {
                $errors[] = sprintf(
                    'Registry %s hash verification failed: %s',
                    $registry->registry_number,
                    $e->getMessage()
                );
            }

            $previousHash = $registry->hash;
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * Mark a registry as submitted to AEAT
     *
     * Uses atomic update with state verification to prevent
     * overwriting a successful submission.
     */
    public function markAsSubmitted(
        RegistryContract $registry,
        string $aeatCsv,
        string $aeatResponse
    ): void {
        if ($registry instanceof Registry) {
            DB::transaction(function () use ($registry, $aeatCsv, $aeatResponse): void {
                // Refresh to get latest state within transaction
                $registry->refresh();

                // Only update if not already sent (idempotency)
                if ($registry->status === RegistryStatusEnum::SENT) {
                    return;
                }

                // Get current attempts before update
                $currentAttempts = $registry->submission_attempts ?? 0;

                $registry->update([
                    'status' => RegistryStatusEnum::SENT->value,
                    'submitted_at' => Carbon::now(),
                    'aeat_csv' => $aeatCsv,
                    'aeat_response' => $aeatResponse,
                    'submission_attempts' => $currentAttempts + 1,
                ]);
            });
        }
    }

    /**
     * Mark a registry as failed
     *
     * Uses atomic update with state verification to prevent
     * overwriting a successful submission with an error.
     */
    public function markAsFailed(
        RegistryContract $registry,
        string $error
    ): void {
        if ($registry instanceof Registry) {
            DB::transaction(function () use ($registry, $error): void {
                // Refresh to get latest state within transaction
                $registry->refresh();

                // Never overwrite SENT status with ERROR (idempotency)
                if ($registry->status === RegistryStatusEnum::SENT) {
                    return;
                }

                // Get current attempts before update
                $currentAttempts = $registry->submission_attempts ?? 0;

                $registry->update([
                    'status' => RegistryStatusEnum::ERROR->value,
                    'aeat_error' => $error,
                    'submission_attempts' => $currentAttempts + 1,
                ]);
            });
        }
    }

    /**
     * Get pending registries for submission
     *
     * @return Collection<int, Registry>
     */
    public function getPendingRegistries(int $limit = 100): Collection
    {
        return Registry::where('status', RegistryStatusEnum::PENDING->value)
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get failed registries that can be retried
     *
     * @return Collection<int, Registry>
     */
    public function getRetryableRegistries(int $maxAttempts = 3, int $limit = 50): Collection
    {
        return Registry::where('status', RegistryStatusEnum::ERROR->value)
            ->where('submission_attempts', '<', $maxAttempts)
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Generate a unique registry number
     *
     * Format: REG-YYYYMMDD-NNNNNN
     *
     * Uses pessimistic locking to prevent race conditions
     * when multiple registries are created concurrently.
     */
    private function generateRegistryNumber(): string
    {
        $date = Carbon::now()->format('Ymd');

        // Use pessimistic locking to get consistent count
        $count = Registry::whereDate('created_at', Carbon::today())
            ->lockForUpdate()
            ->count() + 1;

        return sprintf('REG-%s-%06d', $date, $count);
    }
}
