<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\InvoiceBreakdown;
use AichaDigital\LaraVerifactu\Models\Registry;

beforeEach(function () {
    // Run migrations
    $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
});

it('invoice has one registry relationship', function () {
    $invoice = Invoice::factory()->create();
    $registry = Registry::factory()->forInvoice($invoice)->create();

    expect($invoice->registry)->not->toBeNull()
        ->and($invoice->registry)->toBeInstanceOf(Registry::class)
        ->and($invoice->registry->id)->toBe($registry->id);
});

it('invoice can have multiple breakdowns', function () {
    $invoice = Invoice::factory()->create();

    InvoiceBreakdown::factory()
        ->forInvoice($invoice)
        ->count(3)
        ->create();

    expect($invoice->breakdowns)->toHaveCount(3)
        ->and($invoice->breakdowns->first())->toBeInstanceOf(InvoiceBreakdown::class);
});

it('registry belongs to invoice', function () {
    $invoice = Invoice::factory()->create();
    $registry = Registry::factory()->forInvoice($invoice)->create();

    expect($registry->invoice)->not->toBeNull()
        ->and($registry->invoice)->toBeInstanceOf(Invoice::class)
        ->and($registry->invoice->id)->toBe($invoice->id);
});

it('breakdown belongs to invoice', function () {
    $invoice = Invoice::factory()->create();
    $breakdown = InvoiceBreakdown::factory()->forInvoice($invoice)->create();

    expect($breakdown->invoice)->not->toBeNull()
        ->and($breakdown->invoice)->toBeInstanceOf(Invoice::class)
        ->and($breakdown->invoice->id)->toBe($invoice->id);
});

it('deleting invoice cascades to registry', function () {
    $invoice = Invoice::factory()->create();
    $registry = Registry::factory()->forInvoice($invoice)->create();

    $registryId = $registry->id;
    $invoice->delete();

    expect(Registry::find($registryId))->toBeNull();
});

it('deleting invoice cascades to breakdowns', function () {
    $invoice = Invoice::factory()->create();

    InvoiceBreakdown::factory()
        ->forInvoice($invoice)
        ->count(2)
        ->create();

    $breakdownIds = $invoice->breakdowns->pluck('id')->toArray();
    $invoice->delete();

    foreach ($breakdownIds as $id) {
        expect(InvoiceBreakdown::find($id))->toBeNull();
    }
});

it('can eager load invoice relationships', function () {
    $invoice = Invoice::factory()->create();

    Registry::factory()->forInvoice($invoice)->create();
    InvoiceBreakdown::factory()->forInvoice($invoice)->count(2)->create();

    $loadedInvoice = Invoice::with(['registry', 'breakdowns'])->find($invoice->id);

    expect($loadedInvoice->relationLoaded('registry'))->toBeTrue()
        ->and($loadedInvoice->relationLoaded('breakdowns'))->toBeTrue()
        ->and($loadedInvoice->registry)->not->toBeNull()
        ->and($loadedInvoice->breakdowns)->toHaveCount(2);
});

it('can query invoices through registry', function () {
    $invoice = Invoice::factory()->create(['number' => 'REL-001']);
    Registry::factory()->forInvoice($invoice)->submitted()->create();

    $foundInvoice = Invoice::whereHas('registry', function ($query) {
        $query->where('status', 'submitted');
    })->first();

    expect($foundInvoice)->not->toBeNull()
        ->and($foundInvoice->number)->toBe('REL-001');
});

it('can query invoices through breakdowns', function () {
    $invoice = Invoice::factory()->create(['number' => 'REL-002']);
    InvoiceBreakdown::factory()->forInvoice($invoice)->iva21()->create();

    $foundInvoice = Invoice::whereHas('breakdowns', function ($query) {
        $query->where('tax_rate', 21.00);
    })->first();

    expect($foundInvoice)->not->toBeNull()
        ->and($foundInvoice->number)->toBe('REL-002');
});

it('can create complete invoice with all relationships', function () {
    $invoice = Invoice::factory()->create();

    $registry = Registry::factory()
        ->forInvoice($invoice)
        ->submitted()
        ->withQr()
        ->signed()
        ->create();

    $breakdowns = InvoiceBreakdown::factory()
        ->forInvoice($invoice)
        ->count(2)
        ->sequence(
            ['tax_rate' => 21.00, 'base_amount' => 100.00, 'tax_amount' => 21.00],
            ['tax_rate' => 10.00, 'base_amount' => 50.00, 'tax_amount' => 5.00]
        )
        ->create();

    $loadedInvoice = Invoice::with(['registry', 'breakdowns'])->find($invoice->id);

    expect($loadedInvoice)->not->toBeNull()
        ->and($loadedInvoice->registry)->not->toBeNull()
        ->and($loadedInvoice->registry->isSubmitted())->toBeTrue()
        ->and($loadedInvoice->breakdowns)->toHaveCount(2)
        ->and($loadedInvoice->breakdowns->sum('tax_amount'))->toBe(26.00);
});

it('maintains referential integrity with foreign keys', function () {
    $invoice = Invoice::factory()->create();
    $registry = Registry::factory()->forInvoice($invoice)->create();

    expect($registry->invoice_id)->toBe($invoice->id)
        ->and($registry->invoice->id)->toBe($invoice->id);
});

it('can count related models', function () {
    $invoice = Invoice::factory()->create();

    InvoiceBreakdown::factory()->forInvoice($invoice)->count(3)->create();
    Registry::factory()->forInvoice($invoice)->create();

    expect($invoice->breakdowns()->count())->toBe(3)
        ->and($invoice->registry()->count())->toBe(1);
});
