<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Database\Factories\InvoiceFactory;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;
use AichaDigital\LaraVerifactu\Models\InvoiceBreakdown;

beforeEach(function () {
    // Run migrations
    $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
});

it('can create an invoice breakdown', function () {
    $breakdown = InvoiceBreakdown::factory()->create([
        'tax_rate' => 21.00,
        'base_amount' => 100.00,
        'tax_amount' => 21.00,
    ]);

    expect($breakdown)->toBeInstanceOf(InvoiceBreakdown::class)
        ->and($breakdown->tax_rate)->toBe('21.00')
        ->and($breakdown->base_amount)->toBe('100.00')
        ->and($breakdown->exists)->toBeTrue();
});

it('implements InvoiceBreakdownContract methods', function () {
    $breakdown = InvoiceBreakdown::factory()->create([
        'tax_type' => TaxTypeEnum::IVA,
        'tax_rate' => 21.00,
        'base_amount' => 100.00,
        'tax_amount' => 21.00,
    ]);

    expect($breakdown->getTaxType())->toBe(TaxTypeEnum::IVA)
        ->and($breakdown->getTaxRate())->toBe(21.00)
        ->and($breakdown->getBaseAmount())->toBe(100.00)
        ->and($breakdown->getTaxAmount())->toBe(21.00)
        ->and($breakdown->isExempt())->toBeFalse();
});

it('belongs to an invoice', function () {
    $invoice = InvoiceFactory::new()->create();
    $breakdown = InvoiceBreakdown::factory()->forInvoice($invoice)->create();

    expect($breakdown->invoice)->not->toBeNull()
        ->and($breakdown->invoice->id)->toBe($invoice->id);
});

it('can create IVA 21% breakdown', function () {
    $breakdown = InvoiceBreakdown::factory()
        ->iva21()
        ->create(['base_amount' => 100.00]);

    expect($breakdown->getTaxType())->toBe(TaxTypeEnum::IVA)
        ->and($breakdown->getTaxRate())->toBe(21.00)
        ->and($breakdown->getTaxAmount())->toBe(21.00);
});

it('can create IVA 10% breakdown', function () {
    $breakdown = InvoiceBreakdown::factory()
        ->iva10()
        ->create(['base_amount' => 100.00]);

    expect($breakdown->getTaxRate())->toBe(10.00)
        ->and($breakdown->getTaxAmount())->toBe(10.00);
});

it('can create IVA 4% breakdown', function () {
    $breakdown = InvoiceBreakdown::factory()
        ->iva4()
        ->create(['base_amount' => 100.00]);

    expect($breakdown->getTaxRate())->toBe(4.00)
        ->and($breakdown->getTaxAmount())->toBe(4.00);
});

it('can create exempt breakdown', function () {
    $breakdown = InvoiceBreakdown::factory()
        ->exempt('E1')
        ->create();

    expect($breakdown->isExempt())->toBeTrue()
        ->and($breakdown->getExemptionReason())->toBe('E1')
        ->and($breakdown->getTaxRate())->toBe(0.00)
        ->and($breakdown->getTaxAmount())->toBe(0.00);
});

it('can include surcharge', function () {
    $breakdown = InvoiceBreakdown::factory()
        ->withSurcharge(5.2)
        ->create(['base_amount' => 100.00]);

    expect($breakdown->getSurchargeRate())->toBe(5.2)
        ->and($breakdown->getSurchargeAmount())->toBe(5.20);
});

it('handles null surcharge', function () {
    $breakdown = InvoiceBreakdown::factory()->create([
        'surcharge_rate' => null,
        'surcharge_amount' => null,
    ]);

    expect($breakdown->getSurchargeRate())->toBeNull()
        ->and($breakdown->getSurchargeAmount())->toBeNull();
});

it('casts amounts to decimal', function () {
    $breakdown = InvoiceBreakdown::factory()->create([
        'base_amount' => 100.5,
        'tax_amount' => 21.11,
    ]);

    expect($breakdown->base_amount)->toBeString()
        ->and($breakdown->base_amount)->toBe('100.50')
        ->and($breakdown->getBaseAmount())->toBe(100.50);
});

it('calculates tax amount correctly for different rates', function () {
    $baseAmount = 1000.00;

    $breakdown21 = InvoiceBreakdown::factory()
        ->iva21()
        ->create(['base_amount' => $baseAmount]);

    $breakdown10 = InvoiceBreakdown::factory()
        ->iva10()
        ->create(['base_amount' => $baseAmount]);

    $breakdown4 = InvoiceBreakdown::factory()
        ->iva4()
        ->create(['base_amount' => $baseAmount]);

    expect($breakdown21->getTaxAmount())->toBe(210.00)
        ->and($breakdown10->getTaxAmount())->toBe(100.00)
        ->and($breakdown4->getTaxAmount())->toBe(40.00);
});
