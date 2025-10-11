<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Exceptions\HashException;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use Carbon\Carbon;

beforeEach(function () {
    $this->generator = new HashGenerator;
});

it('generates a valid SHA-256 hash', function () {
    $invoice = createMockInvoice();

    $hash = $this->generator->generate($invoice);

    expect($hash)
        ->toBeString()
        ->toHaveLength(64)
        ->toMatch('/^[a-f0-9]{64}$/');
});

it('generates consistent hashes for same invoice data', function () {
    $invoice = createMockInvoice();

    $hash1 = $this->generator->generate($invoice);
    $hash2 = $this->generator->generate($invoice);

    expect($hash1)->toBe($hash2);
});

it('generates different hashes for different invoices', function () {
    $invoice1 = createMockInvoice(['number' => 'F-2025-001']);
    $invoice2 = createMockInvoice(['number' => 'F-2025-002']);

    $hash1 = $this->generator->generate($invoice1);
    $hash2 = $this->generator->generate($invoice2);

    expect($hash1)->not->toBe($hash2);
});

it('verifies correct hash', function () {
    $invoice = createMockInvoice();
    $hash = $this->generator->generate($invoice);

    $result = $this->generator->verify($hash, $invoice);

    expect($result)->toBeTrue();
});

it('rejects incorrect hash', function () {
    $invoice = createMockInvoice();
    $wrongHash = hash('sha256', 'wrong data');

    $result = $this->generator->verify($wrongHash, $invoice);

    expect($result)->toBeFalse();
});

it('includes issuer tax id in hash', function () {
    $invoice1 = createMockInvoice(['issuer_tax_id' => 'B12345678']);
    $invoice2 = createMockInvoice(['issuer_tax_id' => 'B87654321']);

    $hash1 = $this->generator->generate($invoice1);
    $hash2 = $this->generator->generate($invoice2);

    expect($hash1)->not->toBe($hash2);
});

it('includes invoice number in hash', function () {
    $invoice1 = createMockInvoice(['number' => 'F-001']);
    $invoice2 = createMockInvoice(['number' => 'F-002']);

    $hash1 = $this->generator->generate($invoice1);
    $hash2 = $this->generator->generate($invoice2);

    expect($hash1)->not->toBe($hash2);
});

it('includes issue date in hash', function () {
    $invoice1 = createMockInvoice(['issue_date' => Carbon::parse('2025-01-01')]);
    $invoice2 = createMockInvoice(['issue_date' => Carbon::parse('2025-01-02')]);

    $hash1 = $this->generator->generate($invoice1);
    $hash2 = $this->generator->generate($invoice2);

    expect($hash1)->not->toBe($hash2);
});

it('includes invoice type in hash', function () {
    $invoice1 = createMockInvoice(['type' => InvoiceTypeEnum::COMPLETE]);
    $invoice2 = createMockInvoice(['type' => InvoiceTypeEnum::SIMPLIFIED]);

    $hash1 = $this->generator->generate($invoice1);
    $hash2 = $this->generator->generate($invoice2);

    expect($hash1)->not->toBe($hash2);
});

it('includes total amounts in hash', function () {
    $invoice1 = createMockInvoice([
        'total_amount' => '121.00',
        'total_tax_amount' => '21.00',
    ]);
    $invoice2 = createMockInvoice([
        'total_amount' => '242.00',
        'total_tax_amount' => '42.00',
    ]);

    $hash1 = $this->generator->generate($invoice1);
    $hash2 = $this->generator->generate($invoice2);

    expect($hash1)->not->toBe($hash2);
});

it('includes previous hash if exists', function () {
    $invoice1 = createMockInvoice(['previous_hash' => null]);
    $invoice2 = createMockInvoice(['previous_hash' => hash('sha256', 'previous')]);

    $hash1 = $this->generator->generate($invoice1);
    $hash2 = $this->generator->generate($invoice2);

    expect($hash1)->not->toBe($hash2);
});

it('throws exception on invalid invoice data', function () {
    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andThrow(new \Exception('Invalid data'));

    $this->generator->generate($invoice);
})->throws(HashException::class);

it('formats amounts with 2 decimals', function () {
    $invoice = createMockInvoice([
        'total_amount' => '100',
        'total_tax_amount' => '21',
    ]);

    $hash = $this->generator->generate($invoice);

    expect($hash)->toBeString();
});

it('formats date in dd-mm-yyyy format', function () {
    $invoice = createMockInvoice([
        'issue_date' => Carbon::parse('2025-10-11'),
    ]);

    $hash = $this->generator->generate($invoice);

    expect($hash)->toBeString();
});

// Helper function to create mock invoice
function createMockInvoice(array $overrides = []): InvoiceContract
{
    $defaults = [
        'issuer_tax_id' => 'B12345678',
        'number' => 'F-2025-001',
        'issue_date' => Carbon::parse('2025-10-11'),
        'type' => InvoiceTypeEnum::COMPLETE,
        'total_amount' => '121.00',
        'total_tax_amount' => '21.00',
        'previous_hash' => null,
    ];

    $data = array_merge($defaults, $overrides);

    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn($data['issuer_tax_id']);
    $invoice->shouldReceive('getInvoiceNumber')->andReturn($data['number']);
    $invoice->shouldReceive('getIssueDate')->andReturn($data['issue_date']);
    $invoice->shouldReceive('getInvoiceType')->andReturn($data['type']);
    $invoice->shouldReceive('getTotalAmount')->andReturn($data['total_amount']);
    $invoice->shouldReceive('getTotalTaxAmount')->andReturn($data['total_tax_amount']);
    $invoice->shouldReceive('getPreviousHash')->andReturn($data['previous_hash']);

    return $invoice;
}
