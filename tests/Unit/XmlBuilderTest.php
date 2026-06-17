<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RecipientContract;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\PreviousRegistry;
use AichaDigital\LaraVerifactu\Support\RegistryChain;
use Carbon\Carbon;

beforeEach(function () {
    config()->set('verifactu.company.tax_id', 'B12345678');
    config()->set('verifactu.company.name', 'Empresa Test SL');

    $this->builder = new XmlBuilder;
    $this->chain = new RegistryChain(
        hash: str_repeat('A', 64),
        generatedAt: Carbon::parse('2025-10-11T10:30:30+02:00'),
    );
});

it('builds registration XML with the invoice identification block', function () {
    $invoice = createMockInvoiceForXml();

    $xml = $this->builder->buildRegistrationXml($invoice, $this->chain);

    expect($xml)
        ->toContain('<sf:IDEmisorFactura>B12345678</sf:IDEmisorFactura>')
        ->toContain('<sf:NumSerieFactura>F-2025-001</sf:NumSerieFactura>')
        ->toContain('<sf:FechaExpedicionFactura>11-10-2025</sf:FechaExpedicionFactura>');
});

it('includes invoice type and amounts', function () {
    $invoice = createMockInvoiceForXml();

    $xml = $this->builder->buildRegistrationXml($invoice, $this->chain);

    expect($xml)
        ->toContain('<sf:TipoFactura>F1</sf:TipoFactura>')
        ->toContain('<sf:CuotaTotal>21.00</sf:CuotaTotal>')
        ->toContain('<sf:ImporteTotal>121.00</sf:ImporteTotal>');
});

it('includes the issuer name from config as NombreRazonEmisor', function () {
    $invoice = createMockInvoiceForXml();

    $xml = $this->builder->buildRegistrationXml($invoice, $this->chain);

    expect($xml)->toContain('<sf:NombreRazonEmisor>Empresa Test SL</sf:NombreRazonEmisor>');
});

it('marks the first record of the chain with PrimerRegistro', function () {
    $invoice = createMockInvoiceForXml();

    $xml = $this->builder->buildRegistrationXml($invoice, $this->chain);

    expect($xml)
        ->toContain('<sf:PrimerRegistro>S</sf:PrimerRegistro>')
        ->not->toContain('<sf:RegistroAnterior>');
});

it('chains to the previous registry with RegistroAnterior', function () {
    $invoice = createMockInvoiceForXml();
    $chain = new RegistryChain(
        hash: str_repeat('B', 64),
        generatedAt: Carbon::parse('2025-10-11T11:00:00+02:00'),
        previous: new PreviousRegistry('B12345678', 'F-2025-000', Carbon::parse('2025-10-10'), str_repeat('A', 64)),
    );

    $xml = $this->builder->buildRegistrationXml($invoice, $chain);

    expect($xml)
        ->toContain('<sf:RegistroAnterior>')
        ->toContain('<sf:Huella>' . str_repeat('A', 64) . '</sf:Huella>')
        ->not->toContain('<sf:PrimerRegistro>');
});

it('carries the chain hash, hash type and generation timestamp', function () {
    $invoice = createMockInvoiceForXml();

    $xml = $this->builder->buildRegistrationXml($invoice, $this->chain);

    expect($xml)
        ->toContain('<sf:TipoHuella>01</sf:TipoHuella>')
        ->toContain('<sf:Huella>' . str_repeat('A', 64) . '</sf:Huella>')
        ->toContain('<sf:FechaHoraHusoGenRegistro>2025-10-11T10:30:30+02:00</sf:FechaHoraHusoGenRegistro>');
});

it('generates well-formed XML', function () {
    $invoice = createMockInvoiceForXml();

    $xml = $this->builder->buildRegistrationXml($invoice, $this->chain);

    $dom = new DOMDocument;

    expect($dom->loadXML($xml))->toBeTrue();
});

it('uses the official AEAT namespaces', function () {
    $invoice = createMockInvoiceForXml();

    $xml = $this->builder->buildRegistrationXml($invoice, $this->chain);

    expect($xml)
        ->toContain('xmlns:sfLR="https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd"')
        ->toContain('xmlns:sf="https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd"');
});

it('escapes XML special characters in text values', function () {
    $invoice = createMockInvoiceForXml(['description' => 'Servicios <hosting> & "dominios"']);

    $xml = $this->builder->buildRegistrationXml($invoice, $this->chain);

    expect($xml)
        ->toContain('Servicios &lt;hosting&gt; &amp; "dominios"')
        ->not->toContain('<hosting>');
});

// Helper function to create mock invoice for XML tests
function xmlMockBreakdown(): InvoiceBreakdownContract
{
    $breakdown = Mockery::mock(InvoiceBreakdownContract::class);
    $breakdown->shouldReceive('getTaxType')->andReturn(TaxTypeEnum::IVA);
    $breakdown->shouldReceive('getTaxRate')->andReturn(21.0);
    $breakdown->shouldReceive('getBaseAmount')->andReturn(100.0);
    $breakdown->shouldReceive('getTaxAmount')->andReturn(21.0);
    $breakdown->shouldReceive('getSurchargeRate')->andReturn(null);
    $breakdown->shouldReceive('getSurchargeAmount')->andReturn(null);
    $breakdown->shouldReceive('isExempt')->andReturn(false);
    $breakdown->shouldReceive('getExemptionReason')->andReturn(null);

    return $breakdown;
}

function createMockInvoiceForXml(array $overrides = []): InvoiceContract
{
    $defaults = [
        'issuer_tax_id' => 'B12345678',
        'number' => 'F-2025-001',
        'issue_datetime' => Carbon::parse('2025-10-11 10:30:00'),
        'type' => InvoiceTypeEnum::COMPLETE,
        'description' => 'Servicios profesionales',
        'total_amount' => '121.00',
        'total_tax_amount' => '21.00',
        'breakdowns' => collect([xmlMockBreakdown()]),
    ];

    $data = array_merge($defaults, $overrides);

    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getSerie')->andReturn(null);
    $invoice->shouldReceive('getNumber')->andReturn($data['number']);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn($data['issuer_tax_id']);
    $invoice->shouldReceive('getInvoiceNumber')->andReturn($data['number']);
    $invoice->shouldReceive('getIssueDatetime')->andReturn($data['issue_datetime']);
    $invoice->shouldReceive('getIssueDate')->andReturn($data['issue_datetime']->startOfDay());
    $invoice->shouldReceive('getIssueTime')->andReturn($data['issue_datetime']);
    $invoice->shouldReceive('getType')->andReturn($data['type']);
    $invoice->shouldReceive('getInvoiceType')->andReturn($data['type']);
    $invoice->shouldReceive('getDescription')->andReturn($data['description']);
    $invoice->shouldReceive('getRegimeType')->andReturn(RegimeTypeEnum::GENERAL);
    $invoice->shouldReceive('getTaxAmount')->andReturn(floatval($data['total_tax_amount']));
    $invoice->shouldReceive('getTotalAmount')->andReturn(floatval($data['total_amount']));
    $recipient = Mockery::mock(RecipientContract::class);
    $recipient->shouldReceive('getNif')->andReturn('12345678Z');
    $recipient->shouldReceive('getName')->andReturn('Cliente Ejemplo');
    $recipient->shouldReceive('getIdType')->andReturn(null);
    $recipient->shouldReceive('getId')->andReturn(null);
    $recipient->shouldReceive('getCountry')->andReturn('ES');
    $invoice->shouldReceive('hasRecipient')->andReturn(true);
    $invoice->shouldReceive('getRecipient')->andReturn($recipient);
    $invoice->shouldReceive('getBreakdowns')->andReturn($data['breakdowns']);

    return $invoice;
}
