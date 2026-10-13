<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\RegistryRetriesExhaustedEvent;
use AichaDigital\LaraVerifactu\Events\RegistryStatusChangedEvent;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Support\Facades\Event;

/**
 * AID-1626 — `verifactu:retry-failed` resends stale `pending` rows (abandoned
 * by submitToAeat=false or a crash), sweeps over-budget `error` rows into
 * `exhausted`, and keeps `--max-attempts` as the governing budget (clientes
 * schedules 18, AID-721).
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

    $this->app->instance(InvoiceRegistrar::class, new InvoiceRegistrar(
        $this->registryManager,
        Mockery::mock(CertificateManagerContract::class),
        $this->aeatClient,
    ));
});

/**
 * A real, chain-valid registry (its payload passes the AID-730 check) moved to
 * the requested state and age, the way a backfill would.
 */
function resendCommandTestRegistry(
    RegistryManager $manager,
    RegistryStatusEnum $status,
    int $attempts = 0,
    int $ageSeconds = 3600,
): Registry {
    /** @var Registry $registry */
    $registry = $manager->createRegistry(Invoice::factory()->create());

    tamperRegistryColumns($registry->id, [
        'status' => $status->value,
        'submission_attempts' => $attempts,
        'aeat_error' => $status === RegistryStatusEnum::PENDING ? null : 'connection reset',
        'created_at' => now()->subSeconds($ageSeconds),
    ]);

    return $registry->fresh();
}

it('resends a pending registry once it is older than the grace period', function () {
    config()->set('verifactu.retry.resend_pending', true);
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::PENDING, ageSeconds: 301);

    $this->aeatClient->shouldReceive('sendRegistration')->once()
        ->andReturn(new AeatResponse(success: true, code: 'CSV-PENDING-OK', message: 'Correcto'));

    $this->artisan('verifactu:retry-failed')->assertSuccessful();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::SENT);
});

it('leaves a pending registry younger than the grace period alone', function () {
    config()->set('verifactu.retry.resend_pending', true);
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::PENDING, ageSeconds: 10);

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $this->artisan('verifactu:retry-failed')
        ->expectsOutputToContain('No failed registries to retry')
        ->assertSuccessful();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::PENDING);
});

it('applies a 300 second grace for a published config that predates the key', function () {
    config()->set('verifactu.retry', ['enabled' => true, 'max_attempts' => 1, 'delay' => 60, 'resend_pending' => true]);

    resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::PENDING, ageSeconds: 290);
    $stale = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::PENDING, ageSeconds: 310);

    $this->aeatClient->shouldReceive('sendRegistration')->once()
        ->withArgs(fn (RegistryContract $registry) => $registry->getId() === $stale->id)
        ->andReturn(new AeatResponse(success: true, code: 'CSV-GRACE-OK', message: 'Correcto'));

    $this->artisan('verifactu:retry-failed')->assertSuccessful();
});

it('resends an error registry under budget', function () {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::ERROR, attempts: 1);

    $this->aeatClient->shouldReceive('sendRegistration')->once()
        ->andReturn(new AeatResponse(success: true, code: 'CSV-ERROR-OK', message: 'Correcto'));

    $this->artisan('verifactu:retry-failed')->assertSuccessful();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::SENT);
});

it('sweeps an over-budget error registry to exhausted without resending it', function () {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::ERROR, attempts: 3);
    Event::fake([RegistryStatusChangedEvent::class, RegistryRetriesExhaustedEvent::class]);

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $this->artisan('verifactu:retry-failed')
        ->expectsOutputToContain('Swept to exhausted')
        ->assertSuccessful();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED);
    Event::assertDispatchedTimes(RegistryRetriesExhaustedEvent::class, 1);
    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->from === RegistryStatusEnum::ERROR
        && $event->to === RegistryStatusEnum::EXHAUSTED
        && $event->error === 'connection reset');
});

it('exhausts a registry whose last attempt fails', function () {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::ERROR, attempts: 2);
    Event::fake([RegistryRetriesExhaustedEvent::class]);

    $this->aeatClient->shouldReceive('sendRegistration')->once()->andThrow(new RuntimeException('connection timed out'));

    $this->artisan('verifactu:retry-failed')->assertFailed();

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::EXHAUSTED)
        ->submission_attempts->toBe(3);
    Event::assertDispatchedTimes(RegistryRetriesExhaustedEvent::class, 1);
});

it('governs selection and exhaustion with --max-attempts=18', function () {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::ERROR, attempts: 5);

    $this->aeatClient->shouldReceive('sendRegistration')->once()->andThrow(new RuntimeException('connection timed out'));

    $this->artisan('verifactu:retry-failed', ['--max-attempts' => 18])->assertFailed();

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::ERROR)
        ->submission_attempts->toBe(6);

    // Without the option the configured budget (3) applies: swept, not resent.
    $this->artisan('verifactu:retry-failed')->assertSuccessful();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED);
});

it('leaves exhausted registries out unless --include-exhausted is given', function () {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::EXHAUSTED, attempts: 3);

    $this->aeatClient->shouldReceive('sendRegistration')->once()
        ->andReturn(new AeatResponse(success: true, code: 'CSV-EXHAUSTED-OK', message: 'Correcto'));

    $this->artisan('verifactu:retry-failed')->assertSuccessful();
    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED);

    $this->artisan('verifactu:retry-failed', ['--include-exhausted' => true])->assertSuccessful();

    // History is kept: the counter is not reset.
    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::SENT)
        ->submission_attempts->toBe(4);
});

it('keeps an exhausted registry exhausted, silently, when its extra attempt fails', function () {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::EXHAUSTED, attempts: 3);
    Event::fake([RegistryRetriesExhaustedEvent::class]);

    $this->aeatClient->shouldReceive('sendRegistration')->once()->andThrow(new RuntimeException('connection timed out'));

    $this->artisan('verifactu:retry-failed', ['--include-exhausted' => true])->assertFailed();

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::EXHAUSTED)
        ->submission_attempts->toBe(4);
    Event::assertNotDispatched(RegistryRetriesExhaustedEvent::class);
});

it('refuses a zero or non-numeric --max-attempts and touches nothing', function (string $value) {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::ERROR, attempts: 1);

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $this->artisan('verifactu:retry-failed', ['--max-attempts' => $value])->assertFailed();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::ERROR);
})->with(['0', 'abc', '1O', '18x', '1.9', '-2']);

it('refuses a non-positive --limit and touches nothing', function (string $value) {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::ERROR, attempts: 3);

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $this->artisan('verifactu:retry-failed', ['--limit' => $value])->assertFailed();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::ERROR);
})->with(['0', '-1', 'x']);

it('leaves pending registries alone unless resend_pending is enabled', function () {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::PENDING, ageSeconds: 3600);

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $this->artisan('verifactu:retry-failed')
        ->expectsOutputToContain('No failed registries to retry')
        ->assertSuccessful();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::PENDING);
});

it('treats a published config without resend_pending as disabled', function () {
    config()->set('verifactu.retry', ['enabled' => true, 'max_attempts' => 1, 'delay' => 60]);

    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::PENDING, ageSeconds: 3600);

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $this->artisan('verifactu:retry-failed')->assertSuccessful();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::PENDING);
});

it('refuses an invalid pending_grace when pending resend is enabled and touches nothing', function (mixed $grace) {
    config()->set('verifactu.retry.resend_pending', true);
    config()->set('verifactu.retry.pending_grace', $grace);

    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::PENDING, ageSeconds: 3600);
    $overBudget = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::ERROR, attempts: 3);

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $this->artisan('verifactu:retry-failed')->assertFailed();

    // Nothing sent, and the sweep did not run either: the configuration is
    // validated before any registry is touched.
    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::PENDING)
        ->and($overBudget->fresh()->status)->toBe(RegistryStatusEnum::ERROR);
})->with(['abc', -5]);

it('gives a registry swept in this run its extra attempt on the next run, not this one', function () {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::ERROR, attempts: 3);

    $this->aeatClient->shouldReceive('sendRegistration')->once()
        ->andReturn(new AeatResponse(success: true, code: 'CSV-NEXT-RUN', message: 'Correcto'));

    $this->artisan('verifactu:retry-failed', ['--include-exhausted' => true])->assertSuccessful();
    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED);

    $this->artisan('verifactu:retry-failed', ['--include-exhausted' => true])->assertSuccessful();
    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::SENT);
});

it('resends the oldest candidates first', function () {
    config()->set('verifactu.retry.resend_pending', true);
    $older = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::PENDING, ageSeconds: 7200);
    resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::PENDING, ageSeconds: 3600);

    $this->aeatClient->shouldReceive('sendRegistration')->once()
        ->withArgs(fn (RegistryContract $registry) => $registry->getId() === $older->id)
        ->andReturn(new AeatResponse(success: true, code: 'CSV-OLDEST-OK', message: 'Correcto'));

    $this->artisan('verifactu:retry-failed', ['--limit' => 1])->assertSuccessful();
});

it('keeps the skipped key, at zero, and reports the sweep in retryFailed()', function () {
    resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::ERROR, attempts: 3);

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    expect(app(InvoiceRegistrar::class)->retryFailed())
        ->toBe(['success' => 0, 'failed' => 0, 'skipped' => 0, 'exhausted' => 1]);
});
