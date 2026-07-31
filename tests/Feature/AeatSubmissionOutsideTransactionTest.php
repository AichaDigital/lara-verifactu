<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\InvoiceRegisteredEvent;
use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * AID-717 — the AEAT call must not happen inside a database transaction.
 *
 * It used to: register() opened a transaction covering registry creation, the
 * SOAP call and the event, and submitToAeat() opened another one around the call
 * itself. Three consequences, all covered here:
 *
 * 1. The chain lock (a FOR UPDATE on the sentinel row, AID-258) was held for the
 *    whole round trip to the tax agency, so every other issuance queued behind
 *    the AEAT's latency.
 * 2. Anything throwing after a successful submission rolled the registry back
 *    while the record stayed filed at the agency — an irreconcilable divergence.
 * 3. markAsFailed() was rolled back with everything else, so a failed submission
 *    left nothing behind and RetryFailedCommand had nothing to retry.
 *
 * AID-725 closes the half AID-717 could not reach: the transaction the CONSUMER
 * already had open, under which the package's own commit is only a savepoint
 * release. See the block above those tests at the bottom of this file.
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

it('opens no transaction of its own around the AEAT call', function () {
    // RefreshDatabase wraps every test in a transaction, so the level here is 1,
    // not 0 — which is exactly what verifactu.transaction_guard.baseline_level
    // declares (AID-725): the harness's own transaction is not a caller's. What
    // must hold is that registering adds NO further level: the package opens no
    // transaction of its own around the network call. Measured against the code
    // before AID-717 this was 3 — register() plus submitToAeat(), one on top of
    // the other.
    $baseline = DB::transactionLevel();
    $levelDuringCall = null;

    $this->aeatClient->shouldReceive('sendRegistration')
        ->andReturnUsing(function () use (&$levelDuringCall): AeatResponse {
            // Captured at the exact moment the network call leaves.
            $levelDuringCall = DB::transactionLevel();

            return new AeatResponse(success: true, code: 'CSV-OK', message: 'Correcto');
        });

    $this->registrar->register(Invoice::factory()->create());

    // Any level above the baseline means a transaction is held open across the
    // round trip to the tax agency — and with it the AID-258 chain lock, which
    // queues every other issuance behind the AEAT's latency.
    expect($levelDuringCall)->toBe($baseline);
});

it('keeps the registry when submission fails, so it can be retried', function () {
    $this->aeatClient->shouldReceive('sendRegistration')
        ->andThrow(new RuntimeException('connection reset by peer'));

    $invoice = Invoice::factory()->create();

    expect(fn () => $this->registrar->register($invoice))->toThrow(AeatException::class);

    // The registry survives the failed submission: same link, same hash, same
    // number. A retry re-sends THIS record rather than creating a new one, which
    // is what keeps the chain reconcilable when the remote outcome is unknown.
    $registry = Registry::query()->latest('id')->first();

    expect($registry)->not->toBeNull()
        ->and($registry->status)->toBe(RegistryStatusEnum::ERROR)
        ->and($registry->submission_attempts)->toBe(1);

    // And it is picked up by the package's own retry mechanism.
    expect($this->registryManager->getRetryableRegistries()->pluck('id'))
        ->toContain($registry->id);
});

it('does not roll back a registry the AEAT already accepted', function () {
    $this->aeatClient->shouldReceive('sendRegistration')
        ->andReturn(new AeatResponse(success: true, code: 'CSV-OK', message: 'Correcto'));

    // A consumer listener that blows up after the submission succeeded. While
    // the event was dispatched inside the transaction, this rolled the registry
    // back — leaving the record filed at the agency and absent locally.
    Event::listen(InvoiceRegisteredEvent::class, function (): void {
        throw new RuntimeException('consumer listener blew up');
    });

    $invoice = Invoice::factory()->create();

    expect(fn () => $this->registrar->register($invoice))
        ->toThrow(RuntimeException::class, 'consumer listener blew up');

    $registry = Registry::query()->latest('id')->first();

    expect($registry)->not->toBeNull()
        ->and($registry->status)->toBe(RegistryStatusEnum::SENT)
        ->and($registry->aeat_csv)->toBe('CSV-OK');
});

/*
 * AID-725 — the caller's own transaction.
 *
 * AID-717 moved the AEAT call out of the transaction the package opens. It did
 * not, and could not, do anything about a transaction the CONSUMER already had
 * open: a nested DB::transaction() is a SAVEPOINT, so the commit above is a
 * RELEASE SAVEPOINT, the record is not durable yet, and an outer rollback erases
 * a submission the agency already accepted.
 *
 * The guard refuses only the combination that can actually cause that damage —
 * submitting from inside a caller's transaction. Creating the record inside one
 * without submitting stays legal: no external effect, nothing to diverge from.
 */
it('refuses to submit from inside the caller transaction', function () {
    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $invoice = Invoice::factory()->create();

    // One level above the declared baseline: a transaction the caller owns.
    expect(fn () => DB::transaction(fn () => $this->registrar->register($invoice)))
        ->toThrow(VerifactuException::class, 'inside a database transaction');

    // Nothing was submitted, and no half-built link survives the refusal.
    expect(Registry::query()->where('invoice_id', $invoice->id)->exists())->toBeFalse();
});

it('still allows creating the record inside the caller transaction when not submitting', function () {
    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $invoice = Invoice::factory()->create();

    $registry = DB::transaction(fn () => $this->registrar->register($invoice, submitToAeat: false));

    // The legitimate case the guard must NOT break: the consumer composes its
    // own unit of work around issuing the invoice and its registry, and submits
    // later (queued job, scheduled command) once the row is durable.
    expect($registry->getStatus())->toBe(RegistryStatusEnum::PENDING)
        ->and(Registry::query()->where('invoice_id', $invoice->id)->exists())->toBeTrue();
});

it('refuses to submit a cancellation from inside the caller transaction', function () {
    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $invoice = Invoice::factory()->create();

    expect(fn () => DB::transaction(fn () => $this->registrar->cancel($invoice)))
        ->toThrow(VerifactuException::class, 'inside a database transaction');
});
