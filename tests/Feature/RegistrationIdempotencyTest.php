<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;
use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;

/**
 * AID-726 — one invoice, one root registration.
 *
 * Nothing used to stop a second REGISTRATION for the same invoice: no unique in
 * the database, and register() went straight to createRegistry(). The unique on
 * `hash` does not help, because the hash includes the generation timestamp, so
 * two attempts produce different hashes and both go in.
 *
 * The realistic sequence, opened by AID-717: submit → timeout → the record stays
 * in ERROR and the command reports failure → the operator re-runs the command,
 * which is the natural thing to do → a second chain link for an invoice the
 * agency may already have on file, carrying a different XML from the one filed.
 *
 * The guard lives inside createRegistry(), AFTER acquireChainLock(): checking it
 * in InvoiceRegistrar before the lock would let two concurrent callers clear the
 * same exists() at once. And it counts trashed rows, matching the historical
 * protection amendRejected() already applies (Guard 5): the contract is one root
 * registration EVER, not one visible right now.
 *
 * A UNIQUE index is deliberately NOT the mechanism here. `amendRejected()`
 * creates a legitimate SECOND row with registry_type=registration for the same
 * invoice (AID-137), so UNIQUE(invoice_id, registry_type) would break it; and a
 * constraint that invalidates already-persisted consumer data is a MAJOR by
 * VERSIONING.md, which the authorised 1.x line does not permit.
 */
beforeEach(function () {
    config()->set('verifactu.company.tax_id', '89890001K');
    config()->set('verifactu.company.name', 'Empresa Ejemplo SL');
    config()->set('verifactu.system.vendor_name', 'AichaDigital SL');
    config()->set('verifactu.system.vendor_nif', 'B70123456');
    config()->set('verifactu.system.name', 'LaraVerifactu');
    config()->set('verifactu.system.id', 'LV');
    config()->set('verifactu.system.version', '1.0');
    config()->set('verifactu.system.installation_number', '1');

    $qrGenerator = Mockery::mock(QrGeneratorContract::class);
    $qrGenerator->shouldReceive('generateUrl')->andReturn('https://example.test/qr');
    $qrGenerator->shouldReceive('generateSvg')->andReturn('<svg/>');
    $qrGenerator->shouldReceive('generatePng')->andReturn('png-binary');

    $this->registryManager = new RegistryManager(new HashGenerator, $qrGenerator, new XmlBuilder);
    $this->aeatClient = Mockery::mock(AeatClientContract::class);

    $this->registrar = new InvoiceRegistrar(
        $this->registryManager,
        Mockery::mock(CertificateManagerContract::class),
        $this->aeatClient,
    );
});

/** Count REGISTRATION rows for an invoice, trashed included. */
function idempotencyRegistrationCount(int $invoiceId): int
{
    return Registry::withTrashed()
        ->where('invoice_id', $invoiceId)
        ->where('registry_type', RegistryTypeEnum::REGISTRATION->value)
        ->count();
}

it('refuses a second registration for an invoice that already has one', function () {
    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $invoice = Invoice::factory()->create();

    $this->registrar->register($invoice, submitToAeat: false);

    expect(fn () => $this->registrar->register($invoice, submitToAeat: false))
        ->toThrow(VerifactuException::class, 'already has a registration');

    expect(idempotencyRegistrationCount($invoice->id))->toBe(1);
});

it('refuses to re-register after a failed submission left the record in ERROR', function () {
    // The exact sequence AID-717 made reachable and this ticket closes: the
    // submission fails, the record survives in ERROR so it can be retried, and
    // the operator re-runs the register command instead of retry-failed.
    $this->aeatClient->shouldReceive('sendRegistration')
        ->once()
        ->andThrow(new RuntimeException('connection reset by peer'));

    $invoice = Invoice::factory()->create();

    expect(fn () => $this->registrar->register($invoice))->toThrow(AeatException::class);

    $first = Registry::query()->where('invoice_id', $invoice->id)->firstOrFail();
    expect($first->status)->toBe(RegistryStatusEnum::ERROR);

    // Re-registering would mint a second link with a new timestamp, hash and
    // XML — for an invoice the agency may already have on file.
    expect(fn () => $this->registrar->register($invoice))
        ->toThrow(VerifactuException::class, 'verifactu:retry-failed');

    expect(idempotencyRegistrationCount($invoice->id))->toBe(1)
        ->and(Registry::query()->where('invoice_id', $invoice->id)->firstOrFail()->id)->toBe($first->id);
});

it('counts a soft-deleted registration, so deleting one does not reopen the slot', function () {
    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $invoice = Invoice::factory()->create();

    $registry = $this->registrar->register($invoice, submitToAeat: false);
    Registry::query()->whereKey($registry->getId())->delete();

    // The chain links over what EXISTED (AID-728), so a soft-deleted root still
    // holds its place. Allowing a new root here would leave two records claiming
    // the same predecessor — the fork the chain exists to make impossible.
    expect(fn () => $this->registrar->register($invoice, submitToAeat: false))
        ->toThrow(VerifactuException::class, 'already has a registration');
});

it('still allows a cancellation for an invoice that has a registration', function () {
    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $invoice = Invoice::factory()->create();

    $this->registrar->register($invoice, submitToAeat: false);
    $cancellation = $this->registrar->cancel($invoice, submitToAeat: false);

    // Alta and anulación are different registry types: the guard is about a
    // second ALTA, not about every record for the invoice.
    expect($cancellation->getRegistryType())->toBe(RegistryTypeEnum::CANCELLATION);
});

it('refuses a second cancellation for an invoice already cancelled', function () {
    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $invoice = Invoice::factory()->create();

    $this->registrar->register($invoice, submitToAeat: false);
    $this->registrar->cancel($invoice, submitToAeat: false);

    expect(fn () => $this->registrar->cancel($invoice, submitToAeat: false))
        ->toThrow(VerifactuException::class, 'already has a cancellation');
});
