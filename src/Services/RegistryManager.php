<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;
use AichaDigital\LaraVerifactu\Events\RegistryCreatedEvent;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Support\CancellationRecord;
use AichaDigital\LaraVerifactu\Support\PreviousRegistry;
use AichaDigital\LaraVerifactu\Support\RegistrationCircumstances;
use AichaDigital\LaraVerifactu\Support\RegistryChain;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
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
    /** AEAT SuministroInformacion namespace — the sf: prefix used in persisted XML. */
    private const SF_NS = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';

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
    public function createRegistry(
        InvoiceContract $invoice,
        ?RegistrationCircumstances $circumstances = null
    ): RegistryContract {
        return DB::transaction(function () use ($invoice, $circumstances) {
            // Serialize concurrent chain writes before reading the head (AID-258).
            $this->acquireChainLock();

            // Get previous registry for blockchain chaining
            $previousRegistry = $this->getPreviousRegistry();
            $previousHash = $previousRegistry?->hash;

            // Generate hash for this invoice. The generation timestamp is part
            // of the hashed chain (FechaHoraHusoGenRegistro) and must be
            // persisted so the hash can be verified later.
            $generatedAt = Carbon::now();
            $hash = $this->hashGenerator->generate($invoice, $previousHash, $generatedAt);

            // Generate registry number
            $registryNumber = $this->generateRegistryNumber();

            // Build AEAT-conformant XML carrying the chain data
            $chain = new RegistryChain(
                hash: $hash,
                generatedAt: $generatedAt,
                previous: $previousRegistry !== null ? new PreviousRegistry(
                    issuerTaxId: $previousRegistry->invoice->getIssuerTaxId(),
                    invoiceNumber: $previousRegistry->invoice->getInvoiceNumber(),
                    issueDate: $previousRegistry->invoice->getIssueDatetime(),
                    hash: $previousRegistry->hash,
                ) : null,
            );

            $xml = $this->xmlBuilder->buildRegistrationXml($invoice, $chain, $circumstances);

            // Generate QR codes (AEAT cotejo URL: nif, numserie, fecha, importe)
            $qrUrl = $this->qrGenerator->generateUrl($invoice);
            $qrSvg = $this->qrGenerator->generateSvg($invoice);
            $qrPng = $this->qrGenerator->generatePng($invoice);

            // Create registry
            $registry = Registry::create([
                'invoice_id' => $invoice->getId(),
                'registry_number' => $registryNumber,
                'registry_date' => Carbon::now(),
                'registry_type' => RegistryTypeEnum::REGISTRATION->value,
                'subsanacion' => $circumstances !== null && $circumstances->subsanacion,
                'rechazo_previo' => $circumstances?->rechazoPrevio?->value,
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
     * Create a cancellation registry (RegistroAnulacion) for an invoice.
     *
     * The cancellation is a link of the fingerprint chain in its own right:
     * it gets its own hash chained to the previous registry and is persisted
     * with registry_type=cancellation.
     *
     * @throws VerifactuException
     */
    public function createCancellationRegistry(InvoiceContract $invoice): RegistryContract
    {
        return DB::transaction(function () use ($invoice) {
            // Serialize concurrent chain writes before reading the head (AID-258).
            $this->acquireChainLock();

            $previousRegistry = $this->getPreviousRegistry();
            $previousHash = $previousRegistry?->hash;

            $generatedAt = Carbon::now();
            $hash = $this->hashGenerator->generateCancellation(
                $invoice->getIssuerTaxId(),
                $invoice->getInvoiceNumber(),
                $invoice->getIssueDatetime(),
                $previousHash,
                $generatedAt,
            );

            $registryNumber = $this->generateRegistryNumber();

            $record = new CancellationRecord(
                issuerTaxId: $invoice->getIssuerTaxId(),
                invoiceNumber: $invoice->getInvoiceNumber(),
                issueDate: $invoice->getIssueDatetime(),
            );

            $chain = new RegistryChain(
                hash: $hash,
                generatedAt: $generatedAt,
                previous: $previousRegistry !== null ? new PreviousRegistry(
                    issuerTaxId: $previousRegistry->invoice->getIssuerTaxId(),
                    invoiceNumber: $previousRegistry->invoice->getInvoiceNumber(),
                    issueDate: $previousRegistry->invoice->getIssueDatetime(),
                    hash: $previousRegistry->hash,
                ) : null,
            );

            $xml = $this->xmlBuilder->buildCancellationXml($record, $chain);

            $registry = Registry::create([
                'invoice_id' => $invoice->getId(),
                'registry_number' => $registryNumber,
                'registry_date' => Carbon::now(),
                'registry_type' => RegistryTypeEnum::CANCELLATION->value,
                'hash' => $hash,
                'previous_hash' => $previousHash,
                'hash_generated_at' => $generatedAt->format('c'),
                'xml' => $xml,
                'status' => RegistryStatusEnum::PENDING->value,
                'submission_attempts' => 0,
            ]);

            event(new RegistryCreatedEvent($registry, $invoice));

            return $registry;
        });
    }

    /**
     * Acquire the exclusive chain-head lock (AID-258).
     *
     * Takes a FOR UPDATE lock on the chain's sentinel row so that concurrent
     * registry creations serialize their "read chain head + insert" section and
     * cannot fork the fingerprint chain. Must be called inside the surrounding
     * DB::transaction, before getPreviousRegistry(). The dedicated sentinel row
     * (vs. locking the head row) also covers the empty-chain case.
     */
    private function acquireChainLock(): void
    {
        DB::table('verifactu_chain_locks')
            ->where('scope', 'global')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Get the previous registry in the chain, or null if this is the first.
     */
    public function getPreviousRegistry(): ?Registry
    {
        return Registry::orderBy('registry_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Get the previous hash from the blockchain
     *
     * Returns the hash of the last registry in the chain, or null if this is the first.
     */
    public function getPreviousHash(): ?string
    {
        return $this->getPreviousRegistry()?->hash;
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

            // Verify hash by rebuilding the chain with the persisted data,
            // using the algorithm matching the registry type
            try {
                $isValid = $this->verifyRegistryHash($registry);
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
     * Rebuild and compare a registry's hash from its PERSISTED XML (spec §8).
     *
     * The hash inputs are read from the immutable historical XML via namespaced
     * (sf:) XPath plus the previous_hash / hash_generated_at columns — never from
     * $registry->invoice, which is mutable and would make verification lie after
     * an amendment feeds corrected data. Fail-loud: a null/unparseable XML or any
     * missing node returns false (chain invalid). Covers RegistroAlta and
     * RegistroAnulacion (both had the mutable-invoice bug).
     */
    private function verifyRegistryHash(Registry $registry): bool
    {
        $xml = $registry->xml;

        if ($xml === null || $xml === '') {
            return false;
        }

        $previousUseErrors = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($xml)) {
                return false;
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('sf', self::SF_NS);

            $generatedAt = $registry->hash_generated_at;

            if ($generatedAt === null) {
                return false;
            }

            if ($registry->registry_type === RegistryTypeEnum::CANCELLATION) {
                $issuer = $this->xpathValue($xpath, '//sf:RegistroAnulacion/sf:IDFactura/sf:IDEmisorFacturaAnulada');
                $numSerie = $this->xpathValue($xpath, '//sf:RegistroAnulacion/sf:IDFactura/sf:NumSerieFacturaAnulada');
                $fecha = $this->xpathValue($xpath, '//sf:RegistroAnulacion/sf:IDFactura/sf:FechaExpedicionFacturaAnulada');

                if ($issuer === null || $numSerie === null || $fecha === null) {
                    return false;
                }

                $expected = $this->hashGenerator->generateCancellationFromParts(
                    issuerTaxId: $issuer,
                    numSerieFactura: $numSerie,
                    fechaExpedicion: $fecha,
                    previousHash: $registry->previous_hash,
                    fechaHoraHusoGen: $generatedAt,
                );

                return hash_equals($expected, strtoupper($registry->hash));
            }

            $issuer = $this->xpathValue($xpath, '//sf:RegistroAlta/sf:IDFactura/sf:IDEmisorFactura');
            $numSerie = $this->xpathValue($xpath, '//sf:RegistroAlta/sf:IDFactura/sf:NumSerieFactura');
            $fecha = $this->xpathValue($xpath, '//sf:RegistroAlta/sf:IDFactura/sf:FechaExpedicionFactura');
            $tipo = $this->xpathValue($xpath, '//sf:RegistroAlta/sf:TipoFactura');
            $cuota = $this->xpathValue($xpath, '//sf:RegistroAlta/sf:CuotaTotal');
            $importe = $this->xpathValue($xpath, '//sf:RegistroAlta/sf:ImporteTotal');

            if ($issuer === null || $numSerie === null || $fecha === null
                || $tipo === null || $cuota === null || $importe === null) {
                return false;
            }

            $expected = $this->hashGenerator->generateRegistrationFromParts(
                issuerTaxId: $issuer,
                numSerieFactura: $numSerie,
                fechaExpedicion: $fecha,
                tipoFactura: $tipo,
                cuotaTotal: $cuota,
                importeTotal: $importe,
                previousHash: $registry->previous_hash,
                fechaHoraHusoGen: $generatedAt,
            );

            return hash_equals($expected, strtoupper($registry->hash));
        } catch (\Throwable) {
            return false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }
    }

    /**
     * Read the trimmed text of the first node matching $query, or null when the
     * node is absent (fail-loud: a missing required node invalidates the hash).
     */
    private function xpathValue(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        if (! $node instanceof \DOMNode) {
            return null;
        }

        $value = $node->textContent;

        return $value !== '' ? trim($value) : null;
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
     * Mark a registry as a validation rejection (REJECTED) and persist the
     * structured AEAT response. Mirrors markAsFailed's SENT-idempotency guard.
     * REJECTED is terminal for retry: getRetryableRegistries() selects only ERROR.
     *
     * @param  array<string, mixed>|null  $aeatResponse
     */
    public function markAsRejected(
        RegistryContract $registry,
        string $error,
        ?array $aeatResponse = null
    ): void {
        if ($registry instanceof Registry) {
            DB::transaction(function () use ($registry, $error, $aeatResponse): void {
                $registry->refresh();

                if ($registry->status === RegistryStatusEnum::SENT) {
                    return;
                }

                $currentAttempts = $registry->submission_attempts ?? 0;

                $registry->update([
                    'status' => RegistryStatusEnum::REJECTED->value,
                    'aeat_error' => $error,
                    'aeat_response' => $aeatResponse,
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
