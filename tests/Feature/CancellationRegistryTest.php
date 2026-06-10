<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;

use function Spatie\PestPluginTestTime\testTime;

/**
 * A cancellation (RegistroAnulacion) is itself a link of the fingerprint
 * chain: it gets its own hash chained to the previous registry and is
 * persisted so the chain remains verifiable end to end.
 */
beforeEach(function () {
    config()->set('verifactu.company.tax_id', '89890001K');
    config()->set('verifactu.company.name', 'Empresa Ejemplo SL');
    config()->set('verifactu.system.vendor_name', 'AichaDigital SL');
    config()->set('verifactu.system.vendor_nif', 'B70123456');

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

it('creates a cancellation registry chained after the registration', function () {
    testTime()->freeze('2026-06-10 10:00:00');

    $invoice = Invoice::factory()->create();
    $registration = $this->registryManager->createRegistry($invoice);

    testTime()->addHours(2);

    $cancellation = $this->registryManager->createCancellationRegistry($invoice);

    expect($cancellation->registry_type)->toBe(RegistryTypeEnum::CANCELLATION)
        ->and($cancellation->previous_hash)->toBe($registration->hash)
        ->and($cancellation->hash)->toMatch('/^[A-F0-9]{64}$/')
        ->and($cancellation->xml)->toContain('<sf:RegistroAnulacion>')
        ->and($cancellation->xml)->toContain('<sf:NumSerieFacturaAnulada>');
});

it('keeps the blockchain verifiable across registrations and cancellations', function () {
    testTime()->freeze('2026-06-10 10:00:00');

    $invoice1 = Invoice::factory()->create();
    $this->registryManager->createRegistry($invoice1);

    testTime()->addHours(1);

    $this->registryManager->createCancellationRegistry($invoice1);

    testTime()->addHours(1);

    $invoice2 = Invoice::factory()->create();
    $this->registryManager->createRegistry($invoice2);

    testTime()->addDays(3);

    $result = $this->registryManager->verifyBlockchain();

    expect($result['valid'])->toBeTrue()
        ->and($result['errors'])->toBeEmpty();
});

it('marks registration registries with the registration type by default', function () {
    $invoice = Invoice::factory()->create();

    $registry = $this->registryManager->createRegistry($invoice);

    expect($registry->registry_type)->toBe(RegistryTypeEnum::REGISTRATION);
});
