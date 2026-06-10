<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RecipientContract;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\CancellationRecord;
use AichaDigital\LaraVerifactu\Support\PreviousRegistry;
use AichaDigital\LaraVerifactu\Support\RegistryChain;
use Carbon\Carbon;

/**
 * XML conformance against the official AEAT schemas bundled in resources/xsd/
 * (SuministroLR.xsd + SuministroInformacion.xsd). schemaValidate() passing is
 * the acceptance proof for AID-128 scope item 1.
 */
beforeEach(function () {
    config()->set('verifactu.company.tax_id', '89890001K');
    config()->set('verifactu.company.name', 'Empresa Ejemplo SL');
    config()->set('verifactu.system.vendor_name', 'AichaDigital SL');
    config()->set('verifactu.system.vendor_nif', 'B70123456');
    config()->set('verifactu.system.name', 'LaraVerifactu');
    config()->set('verifactu.system.id', 'LV');
    config()->set('verifactu.system.version', '0.4.0');
    config()->set('verifactu.system.installation_number', '1');

    $this->builder = new XmlBuilder;
});

function conformanceBreakdown(): InvoiceBreakdownContract
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

function conformanceInvoice(): InvoiceContract
{
    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn('89890001K');
    $invoice->shouldReceive('getSerie')->andReturn(null);
    $invoice->shouldReceive('getNumber')->andReturn('FAC-2026-001');
    $invoice->shouldReceive('getInvoiceNumber')->andReturn('FAC-2026-001');
    $invoice->shouldReceive('getIssueDatetime')->andReturn(Carbon::parse('2026-06-01 10:00:00'));
    $invoice->shouldReceive('getType')->andReturn(InvoiceTypeEnum::COMPLETE);
    $invoice->shouldReceive('getInvoiceType')->andReturn(InvoiceTypeEnum::COMPLETE);
    $invoice->shouldReceive('getDescription')->andReturn('Servicios de hosting junio 2026');
    $invoice->shouldReceive('getRegimeType')->andReturn(RegimeTypeEnum::GENERAL);
    $invoice->shouldReceive('getTaxAmount')->andReturn(21.0);
    $invoice->shouldReceive('getTotalAmount')->andReturn(121.0);
    $invoice->shouldReceive('hasRecipient')->andReturn(false);
    $invoice->shouldReceive('getRecipient')->andReturn(null);
    $invoice->shouldReceive('getBreakdowns')->andReturn(collect([conformanceBreakdown()]));

    return $invoice;
}

it('builds a first-record registration XML that validates against the official XSD', function () {
    $chain = new RegistryChain(
        hash: str_repeat('A', 64),
        generatedAt: Carbon::parse('2026-06-01T10:00:30+02:00'),
        previous: null,
    );

    $xml = $this->builder->buildRegistrationXml(conformanceInvoice(), $chain);

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain('<sf:PrimerRegistro>S</sf:PrimerRegistro>')
        ->and($xml)->toContain('<sf:TipoHuella>01</sf:TipoHuella>')
        ->and($xml)->toContain('<sf:Huella>' . str_repeat('A', 64) . '</sf:Huella>')
        ->and($xml)->toContain('<sf:FechaHoraHusoGenRegistro>2026-06-01T10:00:30+02:00</sf:FechaHoraHusoGenRegistro>');
});

it('builds a chained registration XML with RegistroAnterior that validates against the official XSD', function () {
    $chain = new RegistryChain(
        hash: str_repeat('B', 64),
        generatedAt: Carbon::parse('2026-06-01T11:00:00+02:00'),
        previous: new PreviousRegistry(
            issuerTaxId: '89890001K',
            invoiceNumber: 'FAC-2026-000',
            issueDate: Carbon::parse('2026-05-31'),
            hash: str_repeat('A', 64),
        ),
    );

    $xml = $this->builder->buildRegistrationXml(conformanceInvoice(), $chain);

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain('<sf:RegistroAnterior>')
        ->and($xml)->toContain('<sf:Huella>' . str_repeat('A', 64) . '</sf:Huella>')
        ->and($xml)->toContain('<sf:FechaExpedicionFactura>31-05-2026</sf:FechaExpedicionFactura>');
});

it('builds a cancellation XML that validates against the official XSD', function () {
    $record = new CancellationRecord(
        issuerTaxId: '89890001K',
        invoiceNumber: 'FAC-2026-001',
        issueDate: Carbon::parse('2026-06-01'),
    );

    $chain = new RegistryChain(
        hash: str_repeat('C', 64),
        generatedAt: Carbon::parse('2026-06-02T09:00:00+02:00'),
        previous: new PreviousRegistry(
            issuerTaxId: '89890001K',
            invoiceNumber: 'FAC-2026-001',
            issueDate: Carbon::parse('2026-06-01'),
            hash: str_repeat('B', 64),
        ),
    );

    $xml = $this->builder->buildCancellationXml($record, $chain);

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain('<sf:RegistroAnulacion>')
        ->and($xml)->toContain('<sf:IDEmisorFacturaAnulada>89890001K</sf:IDEmisorFacturaAnulada>')
        ->and($xml)->toContain('<sf:NumSerieFacturaAnulada>FAC-2026-001</sf:NumSerieFacturaAnulada>');
});

it('includes the recipient as Destinatarios when the invoice has one', function () {
    $recipient = Mockery::mock(RecipientContract::class);
    $recipient->shouldReceive('getNif')->andReturn('12345678Z');
    $recipient->shouldReceive('getName')->andReturn('Cliente Ejemplo');
    $recipient->shouldReceive('getIdType')->andReturn(null);
    $recipient->shouldReceive('getId')->andReturn(null);
    $recipient->shouldReceive('getCountry')->andReturn('ES');

    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn('89890001K');
    $invoice->shouldReceive('getSerie')->andReturn(null);
    $invoice->shouldReceive('getNumber')->andReturn('FAC-2026-002');
    $invoice->shouldReceive('getInvoiceNumber')->andReturn('FAC-2026-002');
    $invoice->shouldReceive('getIssueDatetime')->andReturn(Carbon::parse('2026-06-01 12:00:00'));
    $invoice->shouldReceive('getType')->andReturn(InvoiceTypeEnum::COMPLETE);
    $invoice->shouldReceive('getInvoiceType')->andReturn(InvoiceTypeEnum::COMPLETE);
    $invoice->shouldReceive('getDescription')->andReturn('Servicios');
    $invoice->shouldReceive('getRegimeType')->andReturn(RegimeTypeEnum::GENERAL);
    $invoice->shouldReceive('getTaxAmount')->andReturn(21.0);
    $invoice->shouldReceive('getTotalAmount')->andReturn(121.0);
    $invoice->shouldReceive('hasRecipient')->andReturn(true);
    $invoice->shouldReceive('getRecipient')->andReturn($recipient);
    $invoice->shouldReceive('getBreakdowns')->andReturn(collect([conformanceBreakdown()]));

    $chain = new RegistryChain(str_repeat('D', 64), Carbon::parse('2026-06-01T12:00:30+02:00'));

    $xml = $this->builder->buildRegistrationXml($invoice, $chain);

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain('<sf:Destinatarios>')
        ->and($xml)->toContain('<sf:NIF>12345678Z</sf:NIF>');
});
