<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;
use AichaDigital\LaraVerifactu\Facades\Verifactu;
use AichaDigital\LaraVerifactu\Tests\Fixtures\CustomInvoice;

/**
 * AID-344: proves Registry::invoice() and the registries.invoice_id FK no
 * longer assume the native Invoice model/table — a genuinely external
 * Eloquent model (own table, no relation to verifactu_invoices) registers,
 * cancels and reports status correctly through the facade.
 */
beforeEach(function () {
    config()->set('verifactu.company.tax_id', '89890001K');
    config()->set('verifactu.company.name', 'Empresa Ejemplo SL');
    config()->set('verifactu.system.vendor_name', 'AichaDigital SL');
    config()->set('verifactu.system.vendor_nif', 'B70123456');
    config()->set('verifactu.models.invoice', CustomInvoice::class);

    $qrGenerator = Mockery::mock(QrGeneratorContract::class);
    $qrGenerator->shouldReceive('generate')->andReturn('<svg/>');
    $qrGenerator->shouldReceive('generateUrl')->andReturn('https://example.test/qr');
    $qrGenerator->shouldReceive('generateSvg')->andReturn('<svg/>');
    $qrGenerator->shouldReceive('generatePng')->andReturn('png-binary');
    $this->app->instance(QrGeneratorContract::class, $qrGenerator);
});

function createCustomInvoice(): CustomInvoice
{
    return CustomInvoice::create([
        'serie' => 'CM',
        'number' => 'INV-0001',
        'issue_datetime' => now(),
        'base_amount' => 100.00,
        'tax_amount' => 21.00,
        'total_amount' => 121.00,
        'recipient_nif' => '12345678A',
        'recipient_name' => 'John Doe',
        'recipient_country' => 'ES',
        'description' => 'Custom-mode fixture invoice',
    ]);
}

it('registers an external custom-mode invoice without hitting the native FK', function () {
    $invoice = createCustomInvoice();

    $registry = Verifactu::register($invoice, submitToAeat: false);

    expect($registry)->toBeInstanceOf(RegistryContract::class)
        ->and($registry->getHash())->toMatch('/^[A-F0-9]{64}$/');
});

it('resolves Registry::invoice() to the configured custom model, not the native one', function () {
    $invoice = createCustomInvoice();
    $registry = Verifactu::register($invoice, submitToAeat: false);

    expect($registry->getInvoice())->toBeInstanceOf(CustomInvoice::class)
        ->and($registry->getInvoice()->getId())->toBe($invoice->getId());
});

it('cancels an external custom-mode invoice creating a chained cancellation registry', function () {
    $invoice = createCustomInvoice();
    $registration = Verifactu::register($invoice, submitToAeat: false);

    $cancellation = Verifactu::cancel($invoice, submitToAeat: false);

    expect($cancellation->getRegistryType())->toBe(RegistryTypeEnum::CANCELLATION)
        ->and($cancellation->getPreviousHash())->toBe($registration->getHash());
});

it('returns the latest registry of an external custom-mode invoice as its status', function () {
    $invoice = createCustomInvoice();
    Verifactu::register($invoice, submitToAeat: false);

    $registry = Verifactu::status($invoice);

    expect($registry)->toBeInstanceOf(RegistryContract::class)
        ->and($registry->getInvoice()->getId())->toBe($invoice->getId());
});
