<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuDisabledException;
use AichaDigital\LaraVerifactu\Facades\Verifactu;
use AichaDigital\LaraVerifactu\Jobs\ProcessInvoiceRegistrationJob;
use AichaDigital\LaraVerifactu\Jobs\RetryFailedRegistriesJob;
use AichaDigital\LaraVerifactu\Jobs\SubmitRegistryToAeatJob;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Support\LazyAeatClient;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\ActivationHarness;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\FakeSoapTransport;

/*
 * AID-1907 §7 upgrade path, on the suite's real engine (MariaDB 11.4 / MySQL
 * 8.4): a 1.6 consumer with registries upgrades and never sets the key.
 */

beforeEach(function () {
    ActivationHarness::configureIssuer();
    config()->set('verifactu.aeat.environment', 'sandbox');
    config()->set('verifactu.retry.resend_pending', true);

    $this->manager = ActivationHarness::registryManager();
    $this->transport = new FakeSoapTransport;
    $this->registrar = ActivationHarness::bindRegistrar(
        $this->manager,
        LazyAeatClient::eager(fn () => FakeSoapTransport::clientWith($this->transport)),
    );

    // The chain a 1.6 consumer left behind: accepted, pending and error.
    $accepted = $this->manager->createRegistry(Invoice::factory()->create());
    $this->manager->markAsAccepted($accepted, 'CSV-ACCEPTED', json_encode(['estado_envio' => 'Correcto']));

    $this->pending = $this->manager->createRegistry(Invoice::factory()->create());
    tamperRegistryColumns($this->pending->id, ['created_at' => now()->subHour()]);

    $this->error = $this->manager->createRegistry(Invoice::factory()->create());
    tamperRegistryColumns($this->error->id, [
        'status' => RegistryStatusEnum::ERROR->value,
        'submission_attempts' => 1,
        'aeat_error' => 'connection reset',
        'created_at' => now()->subHour(),
    ]);

    // The upgrade: the consumer's published config has no `enabled` key.
    $config = config('verifactu');
    unset($config['enabled']);
    config()->set('verifactu', $config);
});

it('refuses every write and effect surface, changes no row, sends nothing — and keeps reads working', function () {
    $before = ActivationHarness::registrySnapshot();
    $invoice = Invoice::factory()->create();

    $surfaces = [
        fn () => Verifactu::register($invoice),
        fn () => Verifactu::cancel(Invoice::findOrFail($this->pending->invoice_id)),
        fn () => Verifactu::amendRejected($this->error, $invoice),
        fn () => Verifactu::sendBatch([$invoice]),
        fn () => $this->registrar->submitToAeat($this->pending),
        fn () => $this->manager->createRegistry($invoice),
        fn () => $this->manager->createCancellationRegistry(Invoice::findOrFail($this->pending->invoice_id)),
        fn () => app(AeatClientContract::class)->sendRegistration($this->pending),
    ];

    foreach ($surfaces as $surface) {
        expect($surface)->toThrow(VerifactuDisabledException::class);
    }

    ProcessInvoiceRegistrationJob::dispatchSync($invoice->id);
    SubmitRegistryToAeatJob::dispatchSync($this->pending->id);
    RetryFailedRegistriesJob::dispatchSync();

    $this->artisan('verifactu:retry-failed')
        ->expectsOutputToContain('nothing sent, registries kept')
        ->assertSuccessful();

    expect(ActivationHarness::registrySnapshot())->toBe($before)
        ->and($this->transport->calls)->toBe(0);

    // Reads keep working while disabled (O5).
    expect(Verifactu::status(Invoice::findOrFail($this->pending->invoice_id))?->getId())->toBe($this->pending->id)
        ->and(Verifactu::validateChain()['valid'])->toBeTrue();
    app()->instance(QrGeneratorContract::class, $qr = Mockery::mock(QrGeneratorContract::class));
    $qr->shouldReceive('generate')->andReturn('qr-bytes');
    expect(app(AichaDigital\LaraVerifactu\Verifactu::class)->qr($invoice))->toBe('qr-bytes');
    $this->artisan('verifactu:verify-blockchain')->assertSuccessful();
    $this->artisan('verifactu:status')->expectsOutputToContain('VeriFACTU is disabled')->assertSuccessful();
});

it('continues the chain from the last hash once the consumer turns the switch on', function () {
    $head = Registry::withTrashed()->orderByDesc('id')->firstOrFail();

    config()->set('verifactu.enabled', true);
    $next = $this->registrar->register(Invoice::factory()->create());

    expect($next->fresh()->previous_hash)->toBe($head->hash)
        ->and($this->registrar->verifyBlockchain()['valid'])->toBeTrue();

    // The rows kept while disabled are recovered by the existing path.
    $this->artisan('verifactu:retry-failed')->assertSuccessful();
    expect($this->pending->fresh()->status)->toBe(RegistryStatusEnum::SENT)
        ->and($this->error->fresh()->status)->toBe(RegistryStatusEnum::SENT);
});
