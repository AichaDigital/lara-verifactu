<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;
use AichaDigital\LaraVerifactu\Events\BlockchainVerifiedEvent;
use AichaDigital\LaraVerifactu\Events\InvoiceRegisteredEvent;
use AichaDigital\LaraVerifactu\Events\RegistryFailedEvent;
use AichaDigital\LaraVerifactu\Events\RegistrySubmittedEvent;
use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Exceptions\ValidationException;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Support\AeatLogSanitizer;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use AichaDigital\LaraVerifactu\Support\RegistrationCircumstances;
use DOMDocument;
use DOMXPath;
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
        $this->assertCanSubmitFromHere($submitToAeat, 'register');

        // The transaction covers creating and signing the record — and NOTHING
        // else (AID-717). It used to wrap the AEAT call and the event too, which
        // held the AID-258 chain lock across the whole round trip to the agency
        // and made it possible to roll back a record the agency had accepted.
        $registry = DB::transaction(function () use ($invoice) {
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->debug('Creating registry for invoice', [
                    'invoice_number' => $invoice->getNumber(),
                    'serie' => $invoice->getSerie(),
                ]);

            $registry = $this->registryManager->createRegistry($invoice);

            // Sign XML (opt-in: VERI*FACTU records are not signed, the chained
            // fingerprint replaces the signature)
            $this->signRegistryXml($registry);

            return $registry;
        });

        // Durable: the chain lock is released and the link exists on its own —
        // guaranteed because assertCanSubmitFromHere() refused to get here from
        // inside a caller's transaction, where this commit would have been a
        // RELEASE SAVEPOINT and the row still revertible (AID-725).
        //
        // A submission that now fails leaves a persisted record in ERROR that
        // RetryFailedCommand can re-send — the same link, hash and number, never
        // a new one.
        if ($submitToAeat) {
            $this->submitToAeat($registry);
        }

        event(new InvoiceRegisteredEvent($invoice, $registry, $submitToAeat));

        return $registry;
    }

    /**
     * Cancel an invoice in the Verifactu system
     *
     * Creates a cancellation registry (RegistroAnulacion) chained to the
     * previous record, signs its XML and optionally submits it to AEAT.
     *
     * @throws VerifactuException
     */
    public function cancel(InvoiceContract $invoice, bool $submitToAeat = true): RegistryContract
    {
        $this->assertCanSubmitFromHere($submitToAeat, 'cancel');

        // Same boundary as register() (AID-717): the transaction ends where the
        // record is durable, and the AEAT call happens outside it.
        $registry = DB::transaction(function () use ($invoice) {
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->debug('Creating cancellation registry for invoice', [
                    'invoice_number' => $invoice->getNumber(),
                    'serie' => $invoice->getSerie(),
                ]);

            $registry = $this->registryManager->createCancellationRegistry($invoice);

            $this->signRegistryXml($registry);

            return $registry;
        });

        if ($submitToAeat) {
            $this->submitToAeat($registry);
        }

        return $registry;
    }

    /**
     * Submit a registry to AEAT.
     *
     * Deliberately opens NO transaction of its own (AID-717). It used to wrap
     * everything — including the network call — in one, which kept a transaction
     * open for the duration of the round trip and made the outcome of a
     * successful submission revertible.
     *
     * Atomicity is not lost: each outcome is persisted by markAsSubmitted() /
     * markAsRejected() / markAsFailed(), and each of those opens its own short
     * transaction around its own write. What is gained is that the record of an
     * outcome the agency already produced can no longer be rolled back by
     * something that happens afterwards.
     *
     * @throws AeatException
     */
    public function submitToAeat(RegistryContract $registry): AeatResponse
    {
        Log::channel(config('verifactu.logging.channel', 'single'))
            ->debug('Submitting registry to AEAT', [
                'registry_number' => $registry->getRegistryNumber(),
            ]);

        // Refresh to get the latest persisted state before deciding. Outside the
        // try: none of this is transport, and misreporting it as a connection
        // failure is the defect AID-729 fixes.
        if ($registry instanceof Registry) {
            $registry->refresh();

            // Idempotency check: skip if the agency already holds it —
            // SENT, or ACCEPTED via a duplicate answer (AID-727).
            if ($registry->status->isFiledAtAeat()) {
                Log::channel(config('verifactu.logging.channel', 'single'))
                    ->debug('Registry already filed at AEAT, skipping', [
                        'registry_number' => $registry->getRegistryNumber(),
                        'csv' => $registry->aeat_csv,
                    ]);

                return new AeatResponse(
                    success: true,
                    code: $registry->aeat_csv,
                    message: 'Already submitted'
                );
            }

            // The bytes about to leave must still be the bytes the stored hash
            // covers (AID-730). A retry can happen long after the first attempt,
            // and presenting different bytes under the same registry number
            // would diverge from what the agency may already hold.
            $this->registryManager->assertSubmissionPayloadIntact($registry);
        }

        try {
            // Submit to AEAT. This call, and ONLY this call, is what the catch
            // below is allowed to classify as a transport failure (AID-729).
            $response = $this->aeatClient->sendRegistration($registry);
        } catch (\Throwable $e) {
            $this->registryManager->markAsFailed($registry, $e->getMessage());

            Log::channel(config('verifactu.logging.channel', 'single'))
                ->error('Exception during AEAT submission', [
                    'registry_number' => $registry->getRegistryNumber(),
                    'error' => AeatLogSanitizer::redactText($e->getMessage()),
                    ...AeatLogSanitizer::traceContext($e),
                ]);

            $this->dispatchQuietly(
                new RegistryFailedEvent($registry, $e->getMessage(), $registry->getSubmissionAttempts()),
                $registry,
            );

            throw AeatException::connectionFailed($e->getMessage());
        }

        // Past this point the agency has answered. The outcome of the operation
        // is fixed by that answer, and nothing that happens now may reclassify
        // it — which is exactly what the old single catch did (AID-729).
        if ($response->isSuccess()) {
            if ($response->isDuplicate()) {
                // The agency already holds this record (AID-727): the expected
                // answer when a submission was accepted and its response lost.
                // Reconcile instead of refusing.
                $this->registryManager->markAsAccepted(
                    $registry,
                    $response->getCsv(),
                    $response->getMessage() ?? ''
                );
            } else {
                $this->registryManager->markAsSubmitted(
                    $registry,
                    $response->getCsv(),
                    $response->getMessage() ?? ''
                );
            }

            Log::channel(config('verifactu.logging.channel', 'single'))
                ->info('Registry submitted successfully', [
                    'registry_number' => $registry->getRegistryNumber(),
                    'csv' => $response->getCsv(),
                    'duplicate' => $response->isDuplicate(),
                ]);

            $this->dispatchQuietly(new RegistrySubmittedEvent($registry, $response), $registry);

            return $response;
        }

        if ($response->isValidationRejection()) {
            $this->registryManager->markAsRejected(
                $registry,
                $response->getErrorMessage(),
                $response->getData()
            );
        } else {
            $this->registryManager->markAsFailed(
                $registry,
                $response->getErrorMessage()
            );
        }

        Log::channel(config('verifactu.logging.channel', 'single'))
            ->error('Registry submission failed', [
                'registry_number' => $registry->getRegistryNumber(),
                'error' => AeatLogSanitizer::redactText((string) $response->getErrorMessage()),
            ]);

        $this->dispatchQuietly(
            new RegistryFailedEvent($registry, $response->getErrorMessage(), $registry->getSubmissionAttempts()),
            $registry,
        );

        return $response;
    }

    /**
     * Dispatch an outcome event without letting a consumer listener rewrite the
     * outcome (AID-729).
     *
     * These events are dispatched AFTER the agency's answer has been persisted.
     * A listener that throws is a defect in the consumer, not a failure of the
     * round trip, and must not turn a successful submission into
     * AeatException::connectionFailed nor a terminal REJECTED into a retryable
     * ERROR — both of which the previous single catch did.
     *
     * The failure is logged rather than propagated: the operation's result is
     * already durable and already returned. Swallowing it here keeps the return
     * value and the persisted state truthful about what the agency said.
     */
    private function dispatchQuietly(object $event, RegistryContract $registry): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->error('A listener threw while handling a Verifactu outcome event', [
                    'registry_number' => $registry->getRegistryNumber(),
                    'event' => $event::class,
                    'error' => AeatLogSanitizer::redactText($e->getMessage()),
                    ...AeatLogSanitizer::traceContext($e),
                ]);
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

    /** AEAT SuministroInformacion namespace for sf: XPath on persisted XML. */
    private const SF_NS = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';

    /**
     * Amend a rejected initial registration («ALTA POR RECHAZO», AID-137).
     *
     * Re-sends the corrected invoice as a NEW chain link with Subsanacion=S and
     * RechazoPrevio=X, linked to the rejected record via amends_registry_id. The
     * rejected record and its XML stay immutable. Five fail-loud guards (in order)
     * prove the operation is the «ALTA POR RECHAZO» variant before any XML is built.
     *
     * @throws VerifactuException
     */
    public function amendRejected(
        RegistryContract $rejectedRegistry,
        InvoiceContract $correctedInvoice,
        bool $submitToAeat = true
    ): RegistryContract {
        $this->assertCanSubmitFromHere($submitToAeat, 'amendRejected');

        $registry = DB::transaction(function () use ($rejectedRegistry, $correctedInvoice) {
            // Guard 1: only a REGISTRATION can be amended by rejection.
            if ($rejectedRegistry->getRegistryType() !== RegistryTypeEnum::REGISTRATION) {
                throw VerifactuException::make(
                    'amendRejected expects a REGISTRATION registry, got ' . $rejectedRegistry->getRegistryType()->value
                );
            }

            // Guard 2: status must be REJECTED (reachable via AID-257).
            if ($rejectedRegistry->getStatus() !== RegistryStatusEnum::REJECTED) {
                throw VerifactuException::make(
                    'amendRejected: only a REJECTED registration can be amended; status is '
                    . $rejectedRegistry->getStatus()->value
                    . ' (an accepted/registered key uses the AID-209 subsanación flow)'
                );
            }

            // Guard 3: the rejection must prove the key is NOT in AEAT. A
            // duplicate-key / already-registered line means the key exists, so
            // RechazoPrevio=X would be re-rejected.
            $this->assertRejectionProvesNotInAeat($rejectedRegistry);

            // Guard 4: the corrected invoice's IDFactura must match the rejected
            // record's persisted historical XML (immutable), using the builder's
            // getSerie().getNumber() convention.
            $this->assertIdFacturaMatchesPersistedXml($rejectedRegistry, $correctedInvoice);

            // Guard 5: no prior amendment of this rejected record (withTrashed).
            $alreadyAmended = Registry::withTrashed()
                ->where('amends_registry_id', $rejectedRegistry->getId() ?? null)
                ->exists();

            if ($alreadyAmended) {
                throw VerifactuException::make(
                    'amendRejected: registry ' . ($rejectedRegistry->getId() ?? '?') . ' has already been amended'
                );
            }

            // Build the new chained amendment registry with S + X circumstances.
            $registry = $this->registryManager->createRegistry(
                $correctedInvoice,
                new RegistrationCircumstances(
                    subsanacion: true,
                    rechazoPrevio: RechazoPrevioEnum::X,
                ),
            );

            if ($registry instanceof Registry) {
                $registry->update(['amends_registry_id' => $rejectedRegistry->getId()]);
            }

            $this->signRegistryXml($registry);

            return $registry;
        });

        // Outside the transaction, same as register()/cancel() (AID-717).
        if ($submitToAeat) {
            $this->submitToAeat($registry);
        }

        event(new InvoiceRegisteredEvent($correctedInvoice, $registry, $submitToAeat));

        return $registry;
    }

    /**
     * Guard 3 helper: the persisted AEAT rejection (AID-257) must show the key
     * is not in AEAT. Fail-loud conditions:
     *  - null/empty response → cannot prove not-in-AEAT.
     *  - lineas is not a non-empty array → unknown/malformed shape, cannot prove not-in-AEAT.
     *  - any line lacks the `registro_duplicado` key → incomplete shape, cannot prove not-in-AEAT.
     *  - any line has registro_duplicado === true → key exists in AEAT; RechazoPrevio=X invalid.
     */
    private function assertRejectionProvesNotInAeat(RegistryContract $rejected): void
    {
        $response = $rejected->getAeatResponse();

        if ($response === null) {
            throw VerifactuException::make(
                'amendRejected: the rejection carries no AEAT response metadata, so the key cannot be proven absent from AEAT'
            );
        }

        $lines = $response['lineas'] ?? null;

        if (! is_array($lines) || $lines === []) {
            throw VerifactuException::make(
                'amendRejected: the rejection lineas are empty or malformed — cannot prove the key is absent from AEAT'
            );
        }

        foreach ($lines as $line) {
            if (! is_array($line) || ! array_key_exists('registro_duplicado', $line)) {
                throw VerifactuException::make(
                    'amendRejected: a rejection line is missing the registro_duplicado key — cannot prove the key is absent from AEAT'
                );
            }

            if ($line['registro_duplicado'] === true) {
                throw VerifactuException::make(
                    'amendRejected: rejection is a duplicate-key/already-registered code; the key exists in AEAT, so RechazoPrevio=X is invalid (use the AID-209 flow)'
                );
            }
        }
    }

    /**
     * Guard 4 helper: the corrected invoice's IDFactura (IDEmisorFactura /
     * NumSerieFactura built as getSerie().getNumber() / FechaExpedicionFactura
     * d-m-Y) must equal the rejected record's persisted historical XML. Fail-loud
     * on null/empty XML or any missing node. Reads the immutable XML, never
     * getInvoice().
     */
    private function assertIdFacturaMatchesPersistedXml(
        RegistryContract $rejected,
        InvoiceContract $corrected
    ): void {
        $xml = $rejected->getXml();

        if ($xml === null || $xml === '') {
            throw VerifactuException::make('amendRejected: the rejected record has no persisted XML to match IDFactura against');
        }

        $previousUseErrors = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($xml)) {
                throw VerifactuException::make('amendRejected: the rejected record XML is unparseable');
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('sf', self::SF_NS);

            $xmlIssuer = $this->xpathText($xpath, '//sf:RegistroAlta/sf:IDFactura/sf:IDEmisorFactura');
            $xmlNumSerie = $this->xpathText($xpath, '//sf:RegistroAlta/sf:IDFactura/sf:NumSerieFactura');
            $xmlFecha = $this->xpathText($xpath, '//sf:RegistroAlta/sf:IDFactura/sf:FechaExpedicionFactura');

            if ($xmlIssuer === null || $xmlNumSerie === null || $xmlFecha === null) {
                throw VerifactuException::make('amendRejected: the rejected record XML is missing an IDFactura node');
            }

            $correctedNumSerie = $corrected->getSerie()
                ? $corrected->getSerie() . $corrected->getNumber()
                : $corrected->getNumber();

            if ($xmlIssuer !== $corrected->getIssuerTaxId()
                || $xmlNumSerie !== $correctedNumSerie
                || $xmlFecha !== $corrected->getIssueDatetime()->format('d-m-Y')) {
                throw VerifactuException::make(
                    'amendRejected: the corrected invoice IDFactura does not match the rejected record (the amendment must re-send the same key with corrected data)'
                );
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }
    }

    private function xpathText(DOMXPath $xpath, string $query): ?string
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
     * Refuse to submit to the AEAT from inside a transaction the CALLER opened
     * (AID-725).
     *
     * AID-717 took the AEAT call out of the transaction this package opens. It
     * could do nothing about one the consumer already had open: from inside it,
     * `DB::transaction()` here is a SAVEPOINT and its close is a RELEASE
     * SAVEPOINT, not a COMMIT. The record is not durable, the SOAP call leaves
     * anyway, and an outer rollback erases a record the agency has accepted —
     * a divergence with the tax agency that cannot be reconciled locally.
     *
     * Only the damaging combination is refused. Creating the record inside a
     * caller's transaction WITHOUT submitting stays supported: with no external
     * effect there is nothing to diverge from, and composing issuance with the
     * registry in one unit of work is a legitimate consumer pattern.
     *
     * @throws VerifactuException
     */
    private function assertCanSubmitFromHere(bool $submitToAeat, string $operation): void
    {
        if (! $submitToAeat) {
            return;
        }

        $baseline = (int) config('verifactu.transaction_guard.baseline_level', 0);
        $level = DB::transactionLevel();

        if ($level <= $baseline) {
            return;
        }

        throw VerifactuException::make(sprintf(
            '%s() cannot submit to the AEAT from inside a database transaction '
            . '(nesting level %d, baseline %d). The record would not be durable when the '
            . 'submission leaves, so an outer rollback would erase a record the agency had '
            . 'already accepted. Either commit before submitting, or call %s(..., submitToAeat: false) '
            . 'inside the transaction and submit afterwards.',
            $operation,
            $level,
            $baseline,
            $operation,
        ));
    }

    /**
     * Sign the registry XML when signing is enabled.
     *
     * Signing is opt-in (verifactu.signing.enabled, default false): in
     * VERI*FACTU mode records are not signed — the chained fingerprint
     * replaces the signature. Failures are logged and never abort the flow.
     */
    private function signRegistryXml(RegistryContract $registry): void
    {
        if (! (bool) config('verifactu.signing.enabled', false)) {
            return;
        }

        try {
            $xml = $registry->getXml();

            if ($xml === null || $xml === '') {
                throw ValidationException::invalidXml('Registry XML content is missing.');
            }

            $signedXml = $this->signXml($xml);

            if ($registry instanceof Registry) {
                // forceFill: signed_xml is out of $fillable (AID-730), and this
                // is the code that generates it.
                $registry->forceFill(['signed_xml' => $signedXml])->save();
            }
        } catch (\Throwable $e) {
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->warning('Failed to sign XML', [
                    'registry_number' => $registry->getRegistryNumber(),
                    'error' => $e->getMessage(),
                ]);
            // Continue without signed XML (optional feature)
        }
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
