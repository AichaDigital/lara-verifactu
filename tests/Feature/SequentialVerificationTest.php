<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Jobs\ProcessInvoiceRegistrationJob;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Run migrations
    $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

    // Enable lock for sequential tests
    config(['verifactu.lock.enabled' => true]);
    config(['verifactu.lock.timeout' => 300]);
});

it('has correct job configuration for v2.0', function () {
    $job = new ProcessInvoiceRegistrationJob(1, false);

    expect($job->tries)->toBe(1) // v2.0: No automatic retries
        ->and($job->queue)->toBe('fiscal_verification'); // v2.0: Dedicated queue
});

it('validates invoices must exist in same serie', function () {
    // Serie A: Invoice 1, 2, 3
    $invoice1 = Invoice::factory()->create([
        'serie' => 'A',
        'number' => 'FAC-A-001',
        'issue_datetime' => now(),
    ]);

    $invoice2 = Invoice::factory()->create([
        'serie' => 'A',
        'number' => 'FAC-A-002',
        'issue_datetime' => now(),
    ]);

    // Serie B: Invoice 1
    $invoiceB = Invoice::factory()->create([
        'serie' => 'B',
        'number' => 'FAC-B-001',
        'issue_datetime' => now(),
    ]);

    // Verify data is correct
    expect(Invoice::where('serie', 'A')->count())->toBe(2)
        ->and(Invoice::where('serie', 'B')->count())->toBe(1);
});

it('validates invoices must exist in same fiscal year', function () {
    // 2024: Invoice 1
    $invoice2024 = Invoice::factory()->create([
        'serie' => 'A',
        'number' => 'FAC-2024-001',
        'issue_datetime' => now()->year(2024),
    ]);

    // 2025: Invoice 1
    $invoice2025 = Invoice::factory()->create([
        'serie' => 'A',
        'number' => 'FAC-2025-001',
        'issue_datetime' => now()->year(2025),
    ]);

    // Verify fiscal years are different
    expect($invoice2024->issue_datetime->year)->toBe(2024)
        ->and($invoice2025->issue_datetime->year)->toBe(2025);
});

it('can create registry for invoice', function () {
    $invoice = Invoice::factory()->create();

    $registry = Registry::factory()->create([
        'invoice_id' => $invoice->id,
        'registry_number' => 'REG-001',
    ]);

    expect($registry->invoice_id)->toBe($invoice->id)
        ->and($invoice->registry()->exists())->toBeTrue();
});

it('can check if invoice has registry', function () {
    $invoice1 = Invoice::factory()->create();
    $invoice2 = Invoice::factory()->create();

    // Invoice 1 has registry
    Registry::factory()->create([
        'invoice_id' => $invoice1->id,
        'registry_number' => 'REG-001',
    ]);

    expect($invoice1->registry()->exists())->toBeTrue()
        ->and($invoice2->registry()->exists())->toBeFalse();
});

it('can dispatch job to fiscal_verification queue', function () {
    Queue::fake();

    ProcessInvoiceRegistrationJob::dispatch(1, false);

    Queue::assertPushedOn('fiscal_verification', ProcessInvoiceRegistrationJob::class);
});
