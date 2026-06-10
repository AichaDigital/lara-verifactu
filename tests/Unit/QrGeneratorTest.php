<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Services\QrGenerator;
use Carbon\Carbon;

beforeEach(function () {
    $this->validationUrl = 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR';
    $this->generator = new QrGenerator($this->validationUrl, 'svg');
});

it('generates validation URL with the official parameters', function () {
    $invoice = createMockInvoiceForQr();

    $url = $this->generator->getValidationUrl($invoice);

    expect($url)
        ->toStartWith('https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?')
        ->toContain('nif=B12345678')
        ->toContain('numserie=F-2025-001')
        ->toContain('fecha=11-10-2025')
        ->toContain('importe=121.00');
});

it('generates SVG QR code', function () {
    $generator = new QrGenerator($this->validationUrl, 'svg');
    $invoice = createMockInvoiceForQr();

    $qr = $generator->generate($invoice);

    expect($qr)
        ->toBeString()
        ->toContain('<svg')
        ->toContain('</svg>');
});

it('generates PNG QR code as base64', function () {
    $generator = new QrGenerator($this->validationUrl, 'png');
    $invoice = createMockInvoiceForQr();

    $qr = $generator->generate($invoice);

    expect($qr)
        ->toBeString()
        ->toStartWith('data:image/png;base64,');
})->skip(! extension_loaded('imagick'), 'Imagick extension not available');

it('encodes special characters in URL parameters', function () {
    $invoice = createMockInvoiceForQr(['number' => 'F-2025/001']);

    $url = $this->generator->getValidationUrl($invoice);

    expect($url)
        ->toContain('F-2025%2F001'); // URL encoded slash
});

it('formats date correctly in URL', function () {
    $invoice = createMockInvoiceForQr(['issue_date' => Carbon::parse('2025-01-05')]);

    $url = $this->generator->getValidationUrl($invoice);

    expect($url)->toContain('fecha=05-01-2025');
});

it('includes the invoice total amount in URL', function () {
    $invoice = createMockInvoiceForQr(['total_amount' => 241.4]);

    $url = $this->generator->getValidationUrl($invoice);

    expect($url)->toContain('importe=241.40');
});

it('generates different QR codes for different invoices', function () {
    $invoice1 = createMockInvoiceForQr(['number' => 'F-001']);
    $invoice2 = createMockInvoiceForQr(['number' => 'F-002']);

    $qr1 = $this->generator->generate($invoice1);
    $qr2 = $this->generator->generate($invoice2);

    expect($qr1)->not->toBe($qr2);
});

it('uses custom validation URL', function () {
    $customUrl = 'https://custom.example.com/validate';
    $generator = new QrGenerator($customUrl, 'svg');
    $invoice = createMockInvoiceForQr();

    $url = $generator->getValidationUrl($invoice);

    expect($url)->toStartWith($customUrl);
});

it('respects custom QR size', function () {
    $generator = new QrGenerator($this->validationUrl, 'svg', 200);
    $invoice = createMockInvoiceForQr();

    $qr = $generator->generate($invoice);

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
        'issue_datetime' => Carbon::parse('2025-10-11 10:30:00'),
        'total_amount' => 121.00,
    ];

    // Support legacy 'issue_date' key for backwards compatibility
    if (isset($overrides['issue_date']) && ! isset($overrides['issue_datetime'])) {
        $overrides['issue_datetime'] = $overrides['issue_date'];
        unset($overrides['issue_date']);
    }

    $data = array_merge($defaults, $overrides);

    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn($data['issuer_tax_id']);
    $invoice->shouldReceive('getInvoiceNumber')->andReturn($data['number']);
    $invoice->shouldReceive('getIssueDatetime')->andReturn($data['issue_datetime']);
    $invoice->shouldReceive('getIssueDate')->andReturn($data['issue_datetime']->startOfDay());
    $invoice->shouldReceive('getIssueTime')->andReturn($data['issue_datetime']);
    $invoice->shouldReceive('getTotalAmount')->andReturn((float) $data['total_amount']);

    return $invoice;
}
