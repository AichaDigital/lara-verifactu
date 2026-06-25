<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;

use function Spatie\PestPluginTestTime\testTime;

/**
 * Blockchain reproducibility: hashes must be verifiable long after creation,
 * which requires the generation timestamp (FechaHoraHusoGenRegistro) to be
 * persisted and reused during verification (AEAT hash spec v0.1.2).
 *
 * The RegistryManager is constructed with the REAL XmlBuilder (not a mock)
 * so verifyRegistryHash can parse the real sf: namespaced XML it produces.
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

    $this->registryManager = new RegistryManager(
        new HashGenerator,
        $qrGenerator,
        new XmlBuilder,
    );
});

it('persists the hash generation timestamp on the registry', function () {
    $invoice = Invoice::factory()->create();

    $registry = $this->registryManager->createRegistry($invoice);

    expect($registry->hash_generated_at)
        ->not->toBeNull()
        ->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
});

it('verifies blockchain integrity across registries created at different times', function () {
    testTime()->freeze('2026-06-10 10:00:00');

    $this->registryManager->createRegistry(Invoice::factory()->create());

    testTime()->addHours(3);

    $this->registryManager->createRegistry(Invoice::factory()->create());

    testTime()->addDays(5);

    $result = $this->registryManager->verifyBlockchain();

    expect($result['valid'])->toBeTrue()
        ->and($result['errors'])->toBeEmpty();
});

it('detects tampering when a persisted hash no longer matches the chain data', function () {
    testTime()->freeze('2026-06-10 10:00:00');

    $registry = $this->registryManager->createRegistry(Invoice::factory()->create());

    testTime()->addHours(1);

    $registry->update(['hash' => strtoupper(hash('sha256', 'tampered'))]);

    $result = $this->registryManager->verifyBlockchain();

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty();
});

it('fails verification when the persisted XML is missing', function () {
    $invoice = Invoice::factory()->create();
    $registry = $this->registryManager->createRegistry($invoice);

    // Empty the XML the verify path depends on (simulating corruption).
    // The xml column is NOT NULL so we use '' rather than null.
    $registry->update(['xml' => '']);

    $result = $this->registryManager->verifyBlockchain();

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty();
});

it('fails verification when the persisted XML is unparseable', function () {
    $invoice = Invoice::factory()->create();
    $registry = $this->registryManager->createRegistry($invoice);

    $registry->update(['xml' => 'not-xml <<<']);

    $result = $this->registryManager->verifyBlockchain();

    expect($result['valid'])->toBeFalse();
});

it('verifies a cancellation registry hash from its persisted XML', function () {
    $invoice = Invoice::factory()->create();
    $this->registryManager->createRegistry($invoice);
    $this->registryManager->createCancellationRegistry($invoice);

    $result = $this->registryManager->verifyBlockchain();

    expect($result['valid'])->toBeTrue()
        ->and($result['errors'])->toBeEmpty();
});
