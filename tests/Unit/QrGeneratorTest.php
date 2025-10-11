<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Services\QrGenerator;
use Carbon\Carbon;

beforeEach(function () {
    $this->validationUrl = 'https://www.aeat.es/verifactu/qr';
    $this->generator = new QrGenerator($this->validationUrl, 'svg');
});

it('generates validation URL with correct parameters', function () {
    $invoice = createMockInvoiceForQr();
    $hash = hash('sha256', 'test');

    $url = $this->generator->getValidationUrl($invoice, $hash);

    expect($url)
        ->toStartWith('https://www.aeat.es/verifactu/qr?')
        ->toContain('nif=B12345678')
        ->toContain('num=F-2025-001')
        ->toContain('fecha=11-10-2025')
        ->toContain('tipo=F1')
        ->toContain('hash=' . $hash);
});

it('generates SVG QR code', function () {
    $generator = new QrGenerator($this->validationUrl, 'svg');
    $invoice = createMockInvoiceForQr();
    $hash = hash('sha256', 'test');

    $qr = $generator->generate($invoice, $hash);

    expect($qr)
        ->toBeString()
        ->toContain('<svg')
        ->toContain('</svg>');
});

it('generates PNG QR code as base64', function () {
    $generator = new QrGenerator($this->validationUrl, 'png');
    $invoice = createMockInvoiceForQr();
    $hash = hash('sha256', 'test');

    $qr = $generator->generate($invoice, $hash);

    expect($qr)
        ->toBeString()
        ->toStartWith('data:image/png;base64,');
})->skip(! extension_loaded('imagick'), 'Imagick extension not available');

it('encodes special characters in URL parameters', function () {
    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn('B12345678');
    $invoice->shouldReceive('getInvoiceNumber')->andReturn('F-2025/001');
    $invoice->shouldReceive('getIssueDate')->andReturn(Carbon::parse('2025-10-11'));
    $invoice->shouldReceive('getInvoiceType')->andReturn(InvoiceTypeEnum::COMPLETE);

    $hash = 'abc123';
    $url = $this->generator->getValidationUrl($invoice, $hash);

    expect($url)
        ->toContain('F-2025%2F001'); // URL encoded slash
});

it('formats date correctly in URL', function () {
    $invoice = createMockInvoiceForQr(['issue_date' => Carbon::parse('2025-01-05')]);
    $hash = hash('sha256', 'test');

    $url = $this->generator->getValidationUrl($invoice, $hash);

    expect($url)->toContain('fecha=05-01-2025');
});

it('includes invoice type in URL', function () {
    $invoice = createMockInvoiceForQr(['type' => InvoiceTypeEnum::SIMPLIFIED]);
    $hash = hash('sha256', 'test');

    $url = $this->generator->getValidationUrl($invoice, $hash);

    expect($url)->toContain('tipo=F2');
});

it('generates different QR codes for different invoices', function () {
    $invoice1 = createMockInvoiceForQr(['number' => 'F-001']);
    $invoice2 = createMockInvoiceForQr(['number' => 'F-002']);
    $hash = hash('sha256', 'test');

    $qr1 = $this->generator->generate($invoice1, $hash);
    $qr2 = $this->generator->generate($invoice2, $hash);

    expect($qr1)->not->toBe($qr2);
});

it('generates different QR codes for different hashes', function () {
    $invoice = createMockInvoiceForQr();
    $hash1 = hash('sha256', 'test1');
    $hash2 = hash('sha256', 'test2');

    $qr1 = $this->generator->generate($invoice, $hash1);
    $qr2 = $this->generator->generate($invoice, $hash2);

    expect($qr1)->not->toBe($qr2);
});

it('uses custom validation URL', function () {
    $customUrl = 'https://custom.example.com/validate';
    $generator = new QrGenerator($customUrl, 'svg');
    $invoice = createMockInvoiceForQr();
    $hash = hash('sha256', 'test');

    $url = $generator->getValidationUrl($invoice, $hash);

    expect($url)->toStartWith($customUrl);
});

it('respects custom QR size', function () {
    $generator = new QrGenerator($this->validationUrl, 'svg', 200);
    $invoice = createMockInvoiceForQr();
    $hash = hash('sha256', 'test');

    $qr = $generator->generate($invoice, $hash);

    expect($qr)
        ->toBeString()
        ->toContain('<svg');
});

// Helper function to create mock invoice for QR tests
function createMockInvoiceForQr(array $overrides = []): InvoiceContract
{
    $defaults = [
        'issuer_tax_id' => 'B12345678',
        'number' => 'F-2025-001',
        'issue_date' => Carbon::parse('2025-10-11'),
        'type' => InvoiceTypeEnum::COMPLETE,
    ];

    $data = array_merge($defaults, $overrides);

    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn($data['issuer_tax_id']);
    $invoice->shouldReceive('getInvoiceNumber')->andReturn($data['number']);
    $invoice->shouldReceive('getIssueDate')->andReturn($data['issue_date']);
    $invoice->shouldReceive('getInvoiceType')->andReturn($data['type']);

    return $invoice;
}
