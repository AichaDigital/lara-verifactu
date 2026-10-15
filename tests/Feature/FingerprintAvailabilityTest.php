<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Facades\Verifactu;
use AichaDigital\LaraVerifactu\Models\Invoice;

/**
 * AID-1656 (openmizan delivery, AID-1607 §15): the chained fingerprint
 * (huella) and the chain link must be readable SYNCHRONOUSLY through the
 * public surface as soon as an invoice is registered, and must NOT wait for
 * the AEAT submission or any queue. Downstream consumers deliver the issued
 * invoice right away — a fingerprint that only materialises once a queued
 * submission runs arrives too late and the delivery gets rejected with
 * `verifactu.huella_required`.
 */
beforeEach(function () {
    config()->set('verifactu.company.tax_id', '89890001K');
    config()->set('verifactu.company.name', 'Empresa Ejemplo SL');
    config()->set('verifactu.system.vendor_name', 'AichaDigital SL');
    config()->set('verifactu.system.vendor_nif', 'B70123456');

    $qrGenerator = Mockery::mock(QrGeneratorContract::class);
    $qrGenerator->shouldReceive('generate')->andReturn('<svg/>');
    $qrGenerator->shouldReceive('generateUrl')->andReturn('https://example.test/qr');
    $qrGenerator->shouldReceive('generateSvg')->andReturn('<svg/>');
    $qrGenerator->shouldReceive('generatePng')->andReturn('png-binary');
    $this->app->instance(QrGeneratorContract::class, $qrGenerator);
});

it('exposes the huella and the chain link synchronously after registration, without any AEAT submission', function () {
    $first = Invoice::factory()->create();
    $second = Invoice::factory()->create();

    $firstReturned = Verifactu::register($first, submitToAeat: false);
    $secondReturned = Verifactu::register($second, submitToAeat: false);

    $firstRegistry = Verifactu::status($first);
    $secondRegistry = Verifactu::status($second);

    expect($firstRegistry)->toBeInstanceOf(RegistryContract::class)
        // 64 uppercase hex characters — the AEAT record shape the consumer must
        // hand downstream, available the moment register() returned.
        ->and($firstRegistry->getHash())->toMatch('/^[A-F0-9]{64}$/')
        ->and($firstRegistry->hash_generated_at)->not->toBeNull()
        ->and($firstRegistry->getStatus())->toBe(RegistryStatusEnum::PENDING)
        // register()'s return value and a fresh status() read never contradict
        // each other about the same invoice's fingerprint.
        ->and($firstReturned->getHash())->toBe($firstRegistry->getHash())
        ->and($secondRegistry)->toBeInstanceOf(RegistryContract::class)
        ->and($secondRegistry->getHash())->toMatch('/^[A-F0-9]{64}$/')
        // The chain link is synchronous too: the second registration chains
        // onto the first invoice's huella, no queue in between.
        ->and($secondRegistry->getPreviousHash())->toBe($firstRegistry->getHash())
        ->and($secondReturned->getPreviousHash())->toBe($secondRegistry->getPreviousHash());
});
