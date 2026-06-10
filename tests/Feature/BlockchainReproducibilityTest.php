<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\RegistryManager;

use function Spatie\PestPluginTestTime\testTime;

/**
 * Blockchain reproducibility: hashes must be verifiable long after creation,
 * which requires the generation timestamp (FechaHoraHusoGenRegistro) to be
 * persisted and reused during verification (AEAT hash spec v0.1.2).
 */
beforeEach(function () {
    config()->set('verifactu.company.tax_id', '89890001K');

    $qrGenerator = Mockery::mock(QrGeneratorContract::class);
    $qrGenerator->shouldReceive('generateUrl')->andReturn('https://example.test/qr');
    $qrGenerator->shouldReceive('generateSvg')->andReturn('<svg/>');
    $qrGenerator->shouldReceive('generatePng')->andReturn('png-binary');

    $xmlBuilder = Mockery::mock(XmlBuilderContract::class);
    $xmlBuilder->shouldReceive('buildRegistrationXml')->andReturn('<xml/>');

    $this->registryManager = new RegistryManager(
        new HashGenerator,
        $qrGenerator,
        $xmlBuilder,
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
