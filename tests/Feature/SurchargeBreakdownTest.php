<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RecipientContract;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;
use AichaDigital\LaraVerifactu\Exceptions\ValidationException;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\RegistryChain;
use Carbon\Carbon;

/**
 * AID-173: equivalence-surcharge fields in the breakdown. When a breakdown
 * carries recargo de equivalencia, XmlBuilder must emit TipoRecargoEquivalencia
 * (Tipo2.2Type) and CuotaRecargoEquivalencia (ImporteSgn12.2Type) after
 * CuotaRepercutida, in XSD DetalleType sequence order. Rate and amount are a
 * semantic pair: both or neither.
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

function surchargeBreakdown(?float $surchargeRate, ?float $surchargeAmount): InvoiceBreakdownContract
{
    $breakdown = Mockery::mock(InvoiceBreakdownContract::class);
    $breakdown->shouldReceive('getTaxType')->andReturn(TaxTypeEnum::IVA);
    $breakdown->shouldReceive('getTaxRate')->andReturn(21.0);
    $breakdown->shouldReceive('getBaseAmount')->andReturn(100.0);
    $breakdown->shouldReceive('getTaxAmount')->andReturn(21.0);
    $breakdown->shouldReceive('getSurchargeRate')->andReturn($surchargeRate);
    $breakdown->shouldReceive('getSurchargeAmount')->andReturn($surchargeAmount);
    $breakdown->shouldReceive('isExempt')->andReturn(false);
    $breakdown->shouldReceive('getExemptionReason')->andReturn(null);

    return $breakdown;
}

function surchargeInvoice(InvoiceBreakdownContract $breakdown): InvoiceContract
{
    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn('89890001K');
    $invoice->shouldReceive('getSerie')->andReturn(null);
    $invoice->shouldReceive('getNumber')->andReturn('FAC-2026-001');
    $invoice->shouldReceive('getInvoiceNumber')->andReturn('FAC-2026-001');
    $invoice->shouldReceive('getIssueDatetime')->andReturn(Carbon::parse('2026-06-01 10:00:00'));
    $invoice->shouldReceive('getType')->andReturn(InvoiceTypeEnum::COMPLETE);
    $invoice->shouldReceive('getInvoiceType')->andReturn(InvoiceTypeEnum::COMPLETE);
    $invoice->shouldReceive('getDescription')->andReturn('Servicios con recargo de equivalencia');
    $invoice->shouldReceive('getRegimeType')->andReturn(RegimeTypeEnum::GENERAL);
    $invoice->shouldReceive('getTaxAmount')->andReturn(21.0);
    $invoice->shouldReceive('getTotalAmount')->andReturn(126.2);
    $recipient = Mockery::mock(RecipientContract::class);
    $recipient->shouldReceive('getNif')->andReturn('12345678Z');
    $recipient->shouldReceive('getName')->andReturn('Cliente Ejemplo');
    $recipient->shouldReceive('getIdType')->andReturn(null);
    $recipient->shouldReceive('getId')->andReturn(null);
    $recipient->shouldReceive('getCountry')->andReturn('ES');
    $invoice->shouldReceive('hasRecipient')->andReturn(true);
    $invoice->shouldReceive('getRecipient')->andReturn($recipient);
    $invoice->shouldReceive('getBreakdowns')->andReturn(collect([$breakdown]));

    return $invoice;
}

function surchargeChain(): RegistryChain
{
    return new RegistryChain(
        hash: str_repeat('A', 64),
        generatedAt: Carbon::parse('2026-06-01T10:00:30+02:00'),
        previous: null,
    );
}

it('emits the surcharge pair after CuotaRepercutida in XSD sequence order', function () {
    $invoice = surchargeInvoice(surchargeBreakdown(surchargeRate: 5.2, surchargeAmount: 5.2));

    $xml = $this->builder->buildRegistrationXml($invoice, surchargeChain());

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain('<sf:TipoRecargoEquivalencia>5.20</sf:TipoRecargoEquivalencia>')
        ->and($xml)->toContain('<sf:CuotaRecargoEquivalencia>5.20</sf:CuotaRecargoEquivalencia>');

    // XSD DetalleType order: CuotaRepercutida < TipoRecargoEquivalencia < CuotaRecargoEquivalencia
    expect(strpos($xml, 'CuotaRepercutida'))->toBeLessThan(strpos($xml, 'TipoRecargoEquivalencia'))
        ->and(strpos($xml, 'TipoRecargoEquivalencia'))->toBeLessThan(strpos($xml, 'CuotaRecargoEquivalencia'));
});

it('omits the surcharge fields when the breakdown has no surcharge', function () {
    $invoice = surchargeInvoice(surchargeBreakdown(surchargeRate: null, surchargeAmount: null));

    $xml = $this->builder->buildRegistrationXml($invoice, surchargeChain());

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->not->toContain('RecargoEquivalencia');
});

it('throws when only the surcharge rate is present', function () {
    $invoice = surchargeInvoice(surchargeBreakdown(surchargeRate: 5.2, surchargeAmount: null));

    expect(fn () => $this->builder->buildRegistrationXml($invoice, surchargeChain()))
        ->toThrow(ValidationException::class);
});

it('throws when only the surcharge amount is present', function () {
    $invoice = surchargeInvoice(surchargeBreakdown(surchargeRate: null, surchargeAmount: 5.2));

    expect(fn () => $this->builder->buildRegistrationXml($invoice, surchargeChain()))
        ->toThrow(ValidationException::class);
});
