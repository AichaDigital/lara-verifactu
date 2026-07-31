<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Support\Facades\Cache;

/**
 * AID-731 — two retry passes must not process the same record.
 *
 * Candidate selection neither claims nor locks, so nothing stopped two
 * overlapping runs of `verifactu:retry-failed` from picking up the same record
 * and racing to write its outcome. The residual window is narrow — both
 * markAs*() methods do guard against overwriting a filed record — but the
 * refresh inside them read the REPEATABLE READ snapshot, so a worker could see
 * a stale ERROR while another's SENT was still uncommitted, clear the guard,
 * and write afterwards.
 *
 * It gained relevance with v1.2.0: before AID-717 a failed submission rolled
 * back and left almost nothing in ERROR to retry. Now there routinely is, and
 * this command is the main recovery path, so overlap stops being theoretical —
 * especially on a short schedule.
 *
 * Note on the source ticket: the tool that raised it claimed markAsSubmitted()
 * and markAsFailed() perform "an unconditional primary-key update". They do
 * not — both have an early-return guard. Recorded so nobody re-escalates this
 * without reading why it was lowered to Medium.
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

it('skips a retry pass while another one holds the lock', function () {
    /** @var Registry $registry */
    $registry = $this->registryManager->createRegistry(Invoice::factory()->create());
    $this->registryManager->markAsFailed($registry, 'connection reset');

    // Stand in for a pass already running: hold the lock this command takes.
    $held = Cache::lock('verifactu:retry-failed', 300);
    expect($held->get())->toBeTrue();

    // Nothing may be submitted while another pass owns the lock.
    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $this->artisan('verifactu:retry-failed')
        ->expectsOutputToContain('still in progress')
        ->assertSuccessful();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::ERROR);

    $held->release();
});

it('runs normally once the lock is free, and releases it afterwards', function () {
    /** @var Registry $registry */
    $registry = $this->registryManager->createRegistry(Invoice::factory()->create());
    $this->registryManager->markAsFailed($registry, 'connection reset');

    $this->aeatClient->shouldReceive('sendRegistration')
        ->once()
        ->andReturn(new AeatResponse(success: true, code: 'CSV-OK', message: 'Correcto'));

    $this->artisan('verifactu:retry-failed')->assertSuccessful();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::SENT);

    // Released afterwards: a leaked lock would wedge every later run until it
    // expired. The release sits in a finally, so it also covers the throwing
    // path — not asserted here, because forcing a throw would mean inventing a
    // seam through a final class to test a language guarantee.
    $probe = Cache::lock('verifactu:retry-failed', 10);
    expect($probe->get())->toBeTrue();
    $probe->release();
});
