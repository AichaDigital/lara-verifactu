<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\OperationTypeEnum;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use Carbon\Carbon;

beforeEach(function () {
    $this->builder = new XmlBuilder;
});

it('builds valid registration XML', function () {
    $invoice = createMockInvoiceForXml();

    $xml = $this->builder->buildRegistrationXml($invoice);

    expect($xml)
        ->toBeString()
        ->toContain('<?xml version="1.0" encoding="UTF-8"?>')
        ->toContain('RegFactuSistemaFacturacion')
        ->toContain('Cabecera')
        ->toContain('RegistroFactura');
});

it('includes issuer tax ID in XML', function () {
    $invoice = createMockInvoiceForXml(['issuer_tax_id' => 'B12345678']);

    $xml = $this->builder->buildRegistrationXml($invoice);

    expect($xml)
        ->toContain('<NIF>B12345678</NIF>')
        ->toContain('<IDEmisorFactura>B12345678</IDEmisorFactura>');
});

it('includes invoice number in XML', function () {
    $invoice = createMockInvoiceForXml(['number' => 'F-2025-001']);

    $xml = $this->builder->buildRegistrationXml($invoice);

    expect($xml)->toContain('<NumSerieFactura>F-2025-001</NumSerieFactura>');
});

it('includes issue date in correct format', function () {
    $invoice = createMockInvoiceForXml(['issue_date' => Carbon::parse('2025-10-11')]);

    $xml = $this->builder->buildRegistrationXml($invoice);

    expect($xml)->toContain('<FechaExpedicionFactura>11-10-2025</FechaExpedicionFactura>');
});

it('includes invoice type in XML', function () {
    $invoice = createMockInvoiceForXml(['type' => InvoiceTypeEnum::COMPLETE]);

    $xml = $this->builder->buildRegistrationXml($invoice);

    expect($xml)->toContain('<TipoFactura>F1</TipoFactura>');
});

it('includes total amount formatted correctly', function () {
    $invoice = createMockInvoiceForXml(['total_amount' => '121.50']);

    $xml = $this->builder->buildRegistrationXml($invoice);

    expect($xml)->toContain('<ImporteTotal>121.50</ImporteTotal>');
});

it('includes tax breakdowns when present', function () {
    $breakdown = Mockery::mock(InvoiceBreakdownContract::class);
    $breakdown->shouldReceive('getTaxType')->andReturn(TaxTypeEnum::IVA);
    $breakdown->shouldReceive('getBaseAmount')->andReturn('100.00');
    $breakdown->shouldReceive('getTaxAmount')->andReturn('21.00');
    $breakdown->shouldReceive('getOperationType')->andReturn(OperationTypeEnum::NORMAL);

    $invoice = createMockInvoiceForXml(['breakdowns' => collect([$breakdown])]);

    $xml = $this->builder->buildRegistrationXml($invoice);

    expect($xml)
        ->toContain('<Desgloses>')
        ->toContain('<TipoImpuesto>01</TipoImpuesto>')
        ->toContain('<BaseImponible>100.00</BaseImponible>')
        ->toContain('<Cuota>21.00</Cuota>');
});

it('includes blockchain data when previous hash exists', function () {
    $invoice = createMockInvoiceForXml([
        'previous_hash' => hash('sha256', 'previous'),
        'previous_invoice_id' => 'PREV-001',
    ]);

    $xml = $this->builder->buildRegistrationXml($invoice);

    expect($xml)
        ->toContain('<Encadenamiento>')
        ->toContain('<RegistroAnterior>')
        ->toContain('<IDRegistroAnterior>PREV-001</IDRegistroAnterior>')
        ->toContain('<HuellaAnterior>');
});

it('omits blockchain data when no previous hash', function () {
    $invoice = createMockInvoiceForXml(['previous_hash' => null]);

    $xml = $this->builder->buildRegistrationXml($invoice);

    expect($xml)->not->toContain('<Encadenamiento>');
});

it('includes system information in header', function () {
    $invoice = createMockInvoiceForXml();

    $xml = $this->builder->buildRegistrationXml($invoice);

    expect($xml)
        ->toContain('<SistemaInformatico>')
        ->toContain('<NombreSistema>LaraVerifactu</NombreSistema>')
        ->toContain('<Version>1.0</Version>');
});

it('builds valid cancellation XML', function () {
    $xml = $this->builder->buildCancellationXml('REG-001');

    expect($xml)
        ->toBeString()
        ->toContain('<RegistroAnulacion>')
        ->toContain('<IDRegistro>REG-001</IDRegistro>');
});

it('builds valid batch XML with multiple invoices', function () {
    $invoice1 = createMockInvoiceForXml(['number' => 'F-001']);
    $invoice2 = createMockInvoiceForXml(['number' => 'F-002']);

    $xml = $this->builder->buildBatchXml(collect([$invoice1, $invoice2]));

    expect($xml)
        ->toContain('<NumSerieFactura>F-001</NumSerieFactura>')
        ->toContain('<NumSerieFactura>F-002</NumSerieFactura>');
});

it('generates well-formed XML', function () {
    $invoice = createMockInvoiceForXml();
    $xml = $this->builder->buildRegistrationXml($invoice);

    $dom = new DOMDocument;
    $result = $dom->loadXML($xml);

    expect($result)->toBeTrue();
});

it('uses proper XML namespaces', function () {
    $invoice = createMockInvoiceForXml();
    $xml = $this->builder->buildRegistrationXml($invoice);

    expect($xml)->toContain('xmlns:sflr');
});

// Helper function to create mock invoice for XML tests
function createMockInvoiceForXml(array $overrides = []): InvoiceContract
{
    $defaults = [
        'issuer_tax_id' => 'B12345678',
        'number' => 'F-2025-001',
        'issue_date' => Carbon::parse('2025-10-11'),
        'type' => InvoiceTypeEnum::COMPLETE,
        'total_amount' => '121.00',
        'total_tax_amount' => '21.00',
        'breakdowns' => collect([]),
        'previous_hash' => null,
        'previous_invoice_id' => null,
    ];

    $data = array_merge($defaults, $overrides);

    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn($data['issuer_tax_id']);
    $invoice->shouldReceive('getInvoiceNumber')->andReturn($data['number']);
    $invoice->shouldReceive('getIssueDate')->andReturn($data['issue_date']);
    $invoice->shouldReceive('getInvoiceType')->andReturn($data['type']);
    $invoice->shouldReceive('getTotalAmount')->andReturn($data['total_amount']);
    $invoice->shouldReceive('getTotalTaxAmount')->andReturn($data['total_tax_amount']);
    $invoice->shouldReceive('getBreakdowns')->andReturn($data['breakdowns']);
    $invoice->shouldReceive('getPreviousHash')->andReturn($data['previous_hash']);
    $invoice->shouldReceive('getPreviousInvoiceId')->andReturn($data['previous_invoice_id']);

    return $invoice;
}
