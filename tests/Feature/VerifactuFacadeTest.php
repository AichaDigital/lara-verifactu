<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;
use AichaDigital\LaraVerifactu\Facades\Verifactu;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Public API of the package: the Verifactu facade delegates to the real
 * services (InvoiceRegistrar / RegistryManager / QrGenerator) and offers
 * a fake mode with assertions for consumer tests.
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

it('registers an invoice and returns the persisted registry', function () {
    $invoice = Invoice::factory()->create();

    $registry = Verifactu::register($invoice, submitToAeat: false);

    expect($registry)->toBeInstanceOf(RegistryContract::class)
        ->and($registry->getHash())->toMatch('/^[A-F0-9]{64}$/')
        ->and($registry->getXml())->toContain('<sf:RegistroAlta>');
});

it('cancels an invoice creating a chained cancellation registry', function () {
    $invoice = Invoice::factory()->create();
    $registration = Verifactu::register($invoice, submitToAeat: false);

    $cancellation = Verifactu::cancel($invoice, submitToAeat: false);

    expect($cancellation->registry_type)->toBe(RegistryTypeEnum::CANCELLATION)
        ->and($cancellation->getPreviousHash())->toBe($registration->getHash());
});

it('returns the latest registry of an invoice as its status', function () {
    $invoice = Invoice::factory()->create();
    Verifactu::register($invoice, submitToAeat: false);

    $registry = Verifactu::status($invoice);

    expect($registry)->toBeInstanceOf(RegistryContract::class)
        ->and($registry->getInvoice()->getId())->toBe($invoice->getId());
});

it('returns null status for an unregistered invoice', function () {
    $invoice = Invoice::factory()->create();

    expect(Verifactu::status($invoice))->toBeNull();
});

it('generates the QR for an invoice', function () {
    $invoice = Invoice::factory()->create();

    expect(Verifactu::qr($invoice))->toBe('<svg/>');
});

it('validates the whole registry chain', function () {
    $invoice = Invoice::factory()->create();
    Verifactu::register($invoice, submitToAeat: false);

    $result = Verifactu::validateChain();

    expect($result['valid'])->toBeTrue()
        ->and($result['errors'])->toBeEmpty();
});

it('intercepts registrations in fake mode without persisting anything', function () {
    Verifactu::fake();

    $invoice = Invoice::factory()->create();
    Verifactu::register($invoice);

    Verifactu::assertRegistered($invoice);

    expect(Registry::count())->toBe(0);
});

it('asserts an invoice was not sent in fake mode', function () {
    Verifactu::fake();

    $invoice = Invoice::factory()->create();

    Verifactu::assertNotSent($invoice);
});

it('fails the assertion when the invoice was not registered in fake mode', function () {
    Verifactu::fake();

    $invoice = Invoice::factory()->create();

    expect(fn () => Verifactu::assertRegistered($invoice))
        ->toThrow(AssertionFailedError::class);
});

it('asserts cancellations in fake mode', function () {
    Verifactu::fake();

    $invoice = Invoice::factory()->create();
    Verifactu::cancel($invoice);

    Verifactu::assertCancelled($invoice);

    expect(Registry::count())->toBe(0);
});

it('refuses assertions outside fake mode', function () {
    $invoice = Invoice::factory()->create();

    expect(fn () => Verifactu::assertRegistered($invoice))
        ->toThrow(RuntimeException::class, 'Cannot assert when not in fake mode');
});
