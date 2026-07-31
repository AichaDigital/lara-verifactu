<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Support\Facades\DB;

/**
 * AID-730 — the bytes sent must be the bytes the hash covers.
 *
 * v1.1.0 had no window between attempts: a failed submission rolled back and
 * left no record. AID-717 opens one deliberately — the record persists in ERROR
 * and `verifactu:retry-failed` re-sends it later — and the CHANGELOG sells that,
 * rightly, as re-sending THE SAME record: same link, hash and number.
 *
 * But «the same record» was guaranteed by the identity of the row, not by the
 * immutability of its contents. The integrity attributes were mass-assignable
 * and the client transmitted `signed_xml ?? xml` verbatim, without checking it
 * still matched the stored hash. Anything modifying the XML between attempts —
 * a consumer observer, a data backfill — meant retry-failed presenting the
 * agency bytes different from those it may already have accepted, under the
 * same registry number. No bad faith required.
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

it('refuses to transmit a payload that no longer matches the stored hash', function () {
    /** @var Registry $registry */
    $registry = $this->registryManager->createRegistry(Invoice::factory()->create());

    // Something outside the package rewrites the XML between attempts: an
    // observer, a backfill, a well-meaning fix.
    DB::table('verifactu_registries')
        ->where('id', $registry->getId())
        ->update(['xml' => str_replace('<sf:CuotaTotal>', '<sf:CuotaTotal>9', (string) $registry->xml)]);

    // The submission must not leave. `never()` is the assertion.
    $this->aeatClient->shouldReceive('sendRegistration')->never();

    expect(fn () => $this->registrar->submitToAeat($registry->fresh()))
        ->toThrow(VerifactuException::class, 'no longer matches');
});

it('transmits normally when the payload still matches', function () {
    /** @var Registry $registry */
    $registry = $this->registryManager->createRegistry(Invoice::factory()->create());

    $this->aeatClient->shouldReceive('sendRegistration')
        ->once()
        ->andReturn(new AeatResponse(success: true, code: 'CSV-OK', message: 'Correcto'));

    $this->registrar->submitToAeat($registry);

    // The guard must not fire on an untouched record, or it would block every
    // legitimate submission rather than the tampered ones.
    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::SENT);
});

it('blocks retry-failed on a record whose XML drifted', function () {
    /** @var Registry $registry */
    $registry = $this->registryManager->createRegistry(Invoice::factory()->create());

    $this->registryManager->markAsFailed($registry, 'connection reset');

    DB::table('verifactu_registries')
        ->where('id', $registry->getId())
        ->update(['xml' => '<sf:RegistroAlta>tampered</sf:RegistroAlta>']);

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    // retryFailed() swallows per-record failures by design, so the assertion is
    // that nothing was transmitted and the record did not become SENT.
    $this->registrar->retryFailed();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::ERROR);
});

it('keeps the integrity attributes out of mass assignment', function () {
    /** @var Registry $registry */
    $registry = $this->registryManager->createRegistry(Invoice::factory()->create());

    $originalHash = $registry->hash;

    // A consumer observer or a careless update() must not be able to rewrite
    // the chain's integrity attributes.
    $registry->update([
        'hash' => str_repeat('0', 64),
        'previous_hash' => str_repeat('1', 64),
        'xml' => '<tampered/>',
    ]);

    $fresh = $registry->fresh();

    expect($fresh->hash)->toBe($originalHash)
        ->and($fresh->xml)->not->toBe('<tampered/>');
});
