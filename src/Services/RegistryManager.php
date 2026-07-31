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
use Illuminate\Database\Eloquent\SoftDeletes;
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

            // One invoice, one root registration (AID-726). Deliberately INSIDE
            // the lock: checked before it, two concurrent callers would clear
            // the same check and both insert.
            $this->assertNoRootRegistration($invoice, $circumstances);

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
                previous: $this->describePrevious($previousRegistry),
            );

            $xml = $this->xmlBuilder->buildRegistrationXml($invoice, $chain, $circumstances);

            // Generate QR codes (AEAT cotejo URL: nif, numserie, fecha, importe)
            $qrUrl = $this->qrGenerator->generateUrl($invoice);
            $qrSvg = $this->qrGenerator->generateSvg($invoice);
            $qrPng = $this->qrGenerator->generatePng($invoice);

            // Create registry. The integrity attributes are forceFill'd: they
            // are out of $fillable on purpose (AID-730), so this generator is
            // the only thing that writes them.
            $registry = new Registry;
            $registry->forceFill([
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
            ])->save();

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

            // One invoice, one cancellation (AID-726). Same reason as in
            // createRegistry() for living inside the lock.
            $this->assertNoCancellation($invoice);

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
                previous: $this->describePrevious($previousRegistry),
            );

            $xml = $this->xmlBuilder->buildCancellationXml($record, $chain);

            // forceFill for the same reason as createRegistry() (AID-730).
            $registry = new Registry;
            $registry->forceFill([
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
            ])->save();

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
     * Describe the previous link for the chain data of the record being built.
     *
     * Resolves the previous record's invoice INCLUDING soft-deleted ones. Since
     * AID-728 the chain links over what existed, so the previous link may well
     * be one whose invoice was deleted — Invoice::delete() cascades to its
     * registries, so the pair goes together. Without this, building the next
     * record died on a null invoice.
     *
     * The consumer's invoice model is configurable (AID-344) and need not use
     * SoftDeletes, so the scope is only lifted when it actually applies.
     */
    private function describePrevious(?Registry $previousRegistry): ?PreviousRegistry
    {
        if ($previousRegistry === null) {
            return null;
        }

        $relation = $previousRegistry->invoice();
        $usesSoftDeletes = in_array(
            SoftDeletes::class,
            class_uses_recursive($relation->getRelated()),
            true,
        );

        $invoice = $usesSoftDeletes
            ? $relation->withTrashed()->first()
            : $relation->first();

        if (! $invoice instanceof InvoiceContract) {
            throw VerifactuException::make(sprintf(
                'Cannot chain over registry %s: its invoice (id %s) is missing, so the '
                . 'previous link cannot be described. The chain requires every link it '
                . 'builds upon to remain resolvable.',
                $previousRegistry->registry_number,
                (string) $previousRegistry->invoice_id,
            ));
        }

        return new PreviousRegistry(
            issuerTaxId: $invoice->getIssuerTaxId(),
            invoiceNumber: $invoice->getInvoiceNumber(),
            issueDate: $invoice->getIssueDatetime(),
            hash: $previousRegistry->hash,
        );
    }

    /**
     * Refuse a second root registration for an invoice (AID-726).
     *
     * Nothing used to stop one: no unique on invoice_id, and the unique on
     * `hash` does not help because the hash includes the generation timestamp,
     * so two attempts hash differently and both go in. The reachable sequence,
     * opened by AID-717: submit → timeout → the record survives in ERROR → the
     * operator re-runs `verifactu:register`, which is the natural thing to do →
     * a second chain link, with a new timestamp, hash and XML, for an invoice
     * the agency may already have on file.
     *
     * A «subsanación» is the ONE legitimate second registration (AID-137), and
     * it is identified by its circumstances, not by amends_registry_id: that
     * column is still null at this point, because amendRejected() creates the
     * row first and links it afterwards.
     *
     * withTrashed() on purpose: the chain links over what EXISTED (AID-728), so
     * a soft-deleted root still holds its place. This mirrors Guard 5 of
     * amendRejected(), which already reasons over trashed rows.
     *
     * No UNIQUE index backs this up, deliberately. UNIQUE(invoice_id,
     * registry_type) would break amendRejected's legitimate second row, and a
     * constraint invalidating already-persisted consumer data is a MAJOR under
     * VERSIONING.md — outside the authorised 1.x line.
     *
     * @throws VerifactuException
     */
    private function assertNoRootRegistration(
        InvoiceContract $invoice,
        ?RegistrationCircumstances $circumstances
    ): void {
        if ($circumstances?->subsanacion === true) {
            return;
        }

        $existing = Registry::withTrashed()
            ->where('invoice_id', $invoice->getId())
            ->where('registry_type', RegistryTypeEnum::REGISTRATION->value)
            ->orderBy('id')
            ->first();

        if ($existing === null) {
            return;
        }

        throw VerifactuException::make(sprintf(
            'Invoice %s already has a registration (%s, status %s). Creating another would '
            . 'add a second chain link for one invoice, with a different hash and XML from the '
            . 'one already filed. To re-send the existing record use `verifactu:retry-failed`; '
            . 'to correct a rejected one use amendRejected().',
            (string) $invoice->getId(),
            $existing->registry_number,
            $existing->status->value,
        ));
    }

    /**
     * Refuse a second cancellation for an invoice (AID-726).
     *
     * Same reasoning as assertNoRootRegistration(): an anulación is a chain link
     * of its own, so issuing two for one invoice forks the record just as a
     * double alta does. There is no «subsanación» equivalent here.
     *
     * @throws VerifactuException
     */
    private function assertNoCancellation(InvoiceContract $invoice): void
    {
        $existing = Registry::withTrashed()
            ->where('invoice_id', $invoice->getId())
            ->where('registry_type', RegistryTypeEnum::CANCELLATION->value)
            ->orderBy('id')
            ->first();

        if ($existing === null) {
            return;
        }

        throw VerifactuException::make(sprintf(
            'Invoice %s already has a cancellation (%s, status %s). To re-send it use '
            . '`verifactu:retry-failed`.',
            (string) $invoice->getId(),
            $existing->registry_number,
            $existing->status->value,
        ));
    }

    /**
     * Get the previous registry in the chain, or null if this is the first.
     *
     * withTrashed() is load-bearing (AID-728). Registry uses SoftDeletes, so
     * without it a deleted head is invisible here and the next record chains
     * against the one BEFORE it — leaving two records declaring the same
     * predecessor. That is a fork, the one state the VeriFactu chain exists to
     * make impossible, and it happened silently: verifyBlockchain() excluded the
     * deleted rows too, so it walked a chain that looked linear and reported
     * valid.
     *
     * The chain links over what EXISTED, not over what is still visible.
     */
    public function getPreviousRegistry(): ?Registry
    {
        return Registry::withTrashed()
            ->orderBy('registry_date', 'desc')
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

        // withTrashed(), for the same reason as getPreviousRegistry() (AID-728):
        // the chain is built over every link that ever existed. Excluding the
        // deleted ones made the remaining links look like a well-formed chain
        // and returned valid — a compliance tool reporting green on a broken
        // chain, which is the worst failure mode available to it.
        $registries = Registry::withTrashed()
            ->orderBy('registry_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $previousHash = null;

        foreach ($registries as $registry) {
            // A deleted link is reported, not hidden. The record was declared to
            // the tax agency and remains part of the chain; a local delete does
            // not remove it from what was filed. Cancelling a record is what
            // RegistroAnulacion is for.
            if ($registry->deleted_at !== null) {
                $errors[] = sprintf(
                    'Registry %s is soft-deleted but remains part of the chain (deleted at %s). '
                    . 'A filed link cannot be removed locally; use a cancellation record instead.',
                    $registry->registry_number,
                    $registry->deleted_at->toIso8601String(),
                );
            }

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
    private function verifyRegistryHash(Registry $registry, ?string $xmlOverride = null): bool
    {
        $xml = $xmlOverride ?? $registry->xml;

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
     * Re-read a registry under a row lock before deciding its outcome (AID-731).
     *
     * A plain refresh() reads the transaction's snapshot. Under REPEATABLE READ
     * — the default on MySQL and MariaDB — that snapshot is fixed at the
     * transaction's first read, so a worker could see a stale ERROR while
     * another worker's SENT was still uncommitted, clear the guard, and write
     * afterwards. lockForUpdate() reads the committed row and holds it for the
     * rest of the transaction, which closes that window.
     *
     * Must be called inside the caller's transaction: outside one, the lock
     * would be released immediately and buy nothing.
     */
    private function lockAndRefresh(Registry $registry): void
    {
        $locked = Registry::withTrashed()
            ->whereKey($registry->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked !== null) {
            $registry->setRawAttributes($locked->getAttributes(), sync: true);
        }
    }

    /**
     * Refuse to transmit a payload that no longer matches the stored hash
     * (AID-730).
     *
     * AID-717 created a window that did not exist before: a failed submission
     * now persists in ERROR and `verifactu:retry-failed` re-sends it later. That
     * is sold, rightly, as re-sending THE SAME record — but sameness was
     * guaranteed by the identity of the row, not by the immutability of its
     * contents. If anything rewrites the XML between attempts, the retry
     * presents the agency bytes different from those it may already have
     * accepted, under the same registry number.
     *
     * Checked against the bytes that will actually leave — `signed_xml ?? xml`,
     * the same expression AeatClient::sendRegistration() uses — not just the
     * unsigned XML, or tampering with the signed copy would slip past.
     *
     * @throws VerifactuException
     */
    public function assertSubmissionPayloadIntact(Registry $registry): void
    {
        $payload = $registry->signed_xml ?? $registry->xml;

        if ($payload === null || $payload === '') {
            throw VerifactuException::make(sprintf(
                'Registry %s has no XML to submit.',
                $registry->registry_number,
            ));
        }

        if ($this->verifyRegistryHash($registry, $payload)) {
            return;
        }

        throw VerifactuException::make(sprintf(
            'Registry %s will not be submitted: its XML no longer matches the stored hash %s. '
            . 'The record was modified after its fingerprint was generated, so transmitting it '
            . 'would present the agency different bytes under the same registry number. '
            . 'Investigate what rewrote it; the chain link itself must not be edited.',
            $registry->registry_number,
            mb_substr($registry->hash, 0, 16) . '…',
        ));
    }

    /**
     * Mark a registry as submitted to AEAT
     *
     * Uses atomic update with state verification to prevent
     * overwriting a successful submission.
     */
    public function markAsSubmitted(
        RegistryContract $registry,
        ?string $aeatCsv,
        string $aeatResponse
    ): void {
        if ($registry instanceof Registry) {
            DB::transaction(function () use ($registry, $aeatCsv, $aeatResponse): void {
                // Refresh to get latest state within transaction
                // Locked read: a plain refresh sees the REPEATABLE READ snapshot (AID-731).
                $this->lockAndRefresh($registry);

                // Only update if the agency does not already hold it (idempotency)
                if ($registry->status->isFiledAtAeat()) {
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
     * Mark a registry as already filed at the agency (AID-727).
     *
     * Reached when AEAT answers «registro duplicado»: it holds this record, so
     * the outcome is success, not refusal. ACCEPTED is final and non-retryable,
     * which is what that means.
     *
     * The CSV stays null when the duplicate answer carries none — never '',
     * which would collide on the UNIQUE index of `aeat_csv` for the second such
     * record.
     */
    public function markAsAccepted(
        RegistryContract $registry,
        ?string $aeatCsv,
        string $aeatResponse
    ): void {
        if ($registry instanceof Registry) {
            DB::transaction(function () use ($registry, $aeatCsv, $aeatResponse): void {
                // Locked read: a plain refresh sees the REPEATABLE READ snapshot (AID-731).
                $this->lockAndRefresh($registry);

                if ($registry->status->isFiledAtAeat()) {
                    return;
                }

                $currentAttempts = $registry->submission_attempts ?? 0;

                $registry->update([
                    'status' => RegistryStatusEnum::ACCEPTED->value,
                    'submitted_at' => $registry->submitted_at ?? Carbon::now(),
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
                // Locked read: a plain refresh sees the REPEATABLE READ snapshot (AID-731).
                $this->lockAndRefresh($registry);

                // Never overwrite an outcome the agency already produced with
                // ERROR (idempotency). That includes REJECTED, which is a
                // verdict and terminal: downgrading it to a retryable ERROR
                // would make the package re-send something the agency refused
                // on validation grounds (AID-729).
                if ($registry->status->hasAgencyVerdict()) {
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
                // Locked read: a plain refresh sees the REPEATABLE READ snapshot (AID-731).
                $this->lockAndRefresh($registry);

                if ($registry->status->isFiledAtAeat()) {
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
     * Generate the internal registry number. Format: REG-YYYYMMDD-NNNNNN.
     *
     * NOT an AEAT field: the number declared to the tax agency is
     * NumSerieFactura, taken from the issuer's own invoice number. This one is
     * a package-internal identifier with a UNIQUE index.
     *
     * AID-715 replaced `COUNT(*) + 1` under `lockForUpdate()`, which was broken
     * two independent ways:
     *
     * 1. Locking a COUNT filtered by whereDate(created_at) takes a GAP LOCK on
     *    the very range every writer then inserts into. Measured with the chain
     *    lock temporarily disabled, that killed N-1 of N concurrent writers with
     *    SQLSTATE[40001] 1213 Deadlock, identically on MySQL 8.4 and MariaDB
     *    12.3 — the AID-700 pattern. It stayed dormant only because
     *    acquireChainLock() serializes writers upstream in the same transaction.
     * 2. Registry uses SoftDeletes and the UNIQUE index does not. COUNT(*) is
     *    filtered by the SoftDeletingScope, so one soft-deleted row was enough
     *    to hand out a number still held by it and die on the constraint. No
     *    concurrency required.
     *
     * So: MAX over the day's prefix, WITH trashed rows, and NO row lock. An
     * issued number is never reused, and nothing locks a range. Do not add
     * lockForUpdate() back "for safety" — that is what caused the deadlock.
     * Serialization is not this method's job: it is acquireChainLock()'s, and
     * that lock is what guarantees the chain cannot fork. The UNIQUE index is
     * an additional defence, not the guarantee.
     *
     * No retry (`attempts:`) is wired here or in the callers, deliberately.
     * Beyond repeating RegistryCreatedEvent, which is dispatched inside the
     * transaction, the callers' transactions are NESTED: InvoiceRegistrar opens
     * its own and calls createRegistry() inside it, so an inner `attempts:`
     * would sit on a savepoint and could not retry a deadlock at all (AID-570).
     * Making it effective would mean retrying InvoiceRegistrar's outer
     * transaction, whose closure submits to the AEAT — a retry there would
     * re-send to the tax agency. A deadlock introduced by the consumer's own
     * context belongs to the consumer's outermost transaction, and only if that
     * closure is free of non-transactional side effects.
     */
    private function generateRegistryNumber(): string
    {
        $prefix = sprintf('REG-%s-', Carbon::now()->format('Ymd'));

        // Sargable prefix scan on the registry_number index. The previous
        // whereDate(created_at) wrapped the column in a function and could not
        // use an index at all.
        $last = Registry::withTrashed()
            ->where('registry_number', 'like', $prefix . '%')
            ->max('registry_number');

        $next = $last === null ? 1 : ((int) mb_substr((string) $last, -6)) + 1;

        return sprintf('%s%06d', $prefix, $next);
    }
}
