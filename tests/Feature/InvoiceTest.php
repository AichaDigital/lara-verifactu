<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Database\Factories\InvoiceBreakdownFactory;
use AichaDigital\LaraVerifactu\Enums\IdTypeEnum;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\OperationTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use AichaDigital\LaraVerifactu\Models\Invoice;
use Carbon\Carbon;

beforeEach(function () {
    // Run migrations
    $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
});

it('can create an invoice', function () {
    $invoice = Invoice::factory()->create([
        'serie' => 'AA',
        'number' => 'INV-0001',
    ]);

    expect($invoice)->toBeInstanceOf(Invoice::class)
        ->and($invoice->serie)->toBe('AA')
        ->and($invoice->number)->toBe('INV-0001')
        ->and($invoice->exists)->toBeTrue();
});

it('implements InvoiceContract methods', function () {
    $invoice = Invoice::factory()->create([
        'serie' => 'BB',
        'number' => 'INV-0002',
        'base_amount' => 100.00,
        'tax_amount' => 21.00,
        'total_amount' => 121.00,
    ]);

    expect($invoice->getSerie())->toBe('BB')
        ->and($invoice->getNumber())->toBe('INV-0002')
        ->and($invoice->getBaseAmount())->toBe(100.00)
        ->and($invoice->getTaxAmount())->toBe(21.00)
        ->and($invoice->getTotalAmount())->toBe(121.00)
        ->and($invoice->getCurrency())->toBe('EUR')
        ->and($invoice->getIssueDate())->toBeInstanceOf(Carbon::class)
        ->and($invoice->getIssueTime())->toBeInstanceOf(Carbon::class)
        ->and($invoice->getType())->toBeInstanceOf(InvoiceTypeEnum::class)
        ->and($invoice->getRegimeType())->toBeInstanceOf(RegimeTypeEnum::class)
        ->and($invoice->getOperationKey())->toBeInstanceOf(OperationTypeEnum::class);
});

it('can create a simplified invoice', function () {
    $invoice = Invoice::factory()->simplified()->create();

    expect($invoice->isSimplified())->toBeTrue()
        ->and($invoice->hasRecipient())->toBeFalse()
        ->and($invoice->getRecipient())->toBeNull();
});

it('can create an invoice with recipient', function () {
    $invoice = Invoice::factory()->create([
        'recipient_nif' => '12345678A',
        'recipient_name' => 'Test Company',
    ]);

    expect($invoice->hasRecipient())->toBeTrue()
        ->and($invoice->getRecipient())->not->toBeNull()
        ->and($invoice->getRecipient()->getNif())->toBe('12345678A')
        ->and($invoice->getRecipient()->getName())->toBe('Test Company');
});

it('can create a foreign recipient invoice', function () {
    $invoice = Invoice::factory()->foreignRecipient()->create([
        'recipient_id_type' => IdTypeEnum::PASSPORT,
        'recipient_id' => 'ABC123456',
        'recipient_country' => 'FR',
    ]);

    expect($invoice->hasRecipient())->toBeTrue()
        ->and($invoice->getRecipient())->not->toBeNull()
        ->and($invoice->getRecipient()->getIdType())->toBe(IdTypeEnum::PASSPORT)
        ->and($invoice->getRecipient()->getId())->toBe('ABC123456')
        ->and($invoice->getRecipient()->getCountry())->toBe('FR');
});

it('can create a rectification invoice', function () {
    $invoice = Invoice::factory()->rectification()->create();

    expect($invoice->getType())->toBe(InvoiceTypeEnum::F2)
        ->and($invoice->getRectificationType())->toBe('S');
});

it('can handle metadata', function () {
    $invoice = Invoice::factory()->create([
        'metadata' => ['custom_field' => 'custom_value'],
    ]);

    expect($invoice->getMetadata())->toBeArray()
        ->and($invoice->getMetadata())->toHaveKey('custom_field')
        ->and($invoice->getMetadata()['custom_field'])->toBe('custom_value');
});

it('casts amounts to decimal', function () {
    $invoice = Invoice::factory()->create([
        'base_amount' => 100.5,
        'tax_amount' => 21.11,
        'total_amount' => 121.61,
    ]);

    expect($invoice->base_amount)->toBeString()
        ->and($invoice->base_amount)->toBe('100.50')
        ->and($invoice->getBaseAmount())->toBe(100.50);
});

it('can have breakdowns', function () {
    $invoice = Invoice::factory()->create();

    InvoiceBreakdownFactory::new()
        ->forInvoice($invoice)
        ->create();

    $invoice->refresh();

    expect($invoice->breakdowns)->toHaveCount(1)
        ->and($invoice->getBreakdowns())->toHaveCount(1);
});

it('enforces unique serie and number combination', function () {
    Invoice::factory()->create([
        'serie' => 'XX',
        'number' => 'DUP-001',
    ]);

    // This should throw a database exception
    Invoice::factory()->create([
        'serie' => 'XX',
        'number' => 'DUP-001',
    ]);
})->throws(Exception::class);

it('can soft delete invoices', function () {
    $invoice = Invoice::factory()->create();
    $invoiceId = $invoice->id;

    $invoice->delete();

    expect(Invoice::find($invoiceId))->toBeNull()
        ->and(Invoice::withTrashed()->find($invoiceId))->not->toBeNull();
});

it('indexes serie and number for performance', function () {
    $invoice = Invoice::factory()->create([
        'serie' => 'AA',
        'number' => 'IDX-001',
    ]);

    // Query using indexed fields should be fast
    $found = Invoice::where('serie', 'AA')
        ->where('number', 'IDX-001')
        ->first();

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($invoice->id);
});
