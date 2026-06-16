<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;
use AichaDigital\LaraVerifactu\Exceptions\ValidationException;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\RegistryChain;
use Carbon\Carbon;

/**
 * AID-175: percentage fields formatted by formatRate() must fit XSD Tipo2.2Type
 * (\d{1,3}(\.\d{0,2})?) — finite, non-negative (no sign), max 3 integer digits.
 * Covers both consumers: TipoImpositivo (getTaxRate) and TipoRecargoEquivalencia
 * (getSurchargeRate). formatRate() throws ValidationException before producing
 * XSD-invalid XML.
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

function rateBreakdown(float $taxRate, ?float $surchargeRate = null, ?float $surchargeAmount = null): InvoiceBreakdownContract
{
    $breakdown = Mockery::mock(InvoiceBreakdownContract::class);
    $breakdown->shouldReceive('getTaxType')->andReturn(TaxTypeEnum::IVA);
    $breakdown->shouldReceive('getTaxRate')->andReturn($taxRate);
    $breakdown->shouldReceive('getBaseAmount')->andReturn(100.0);
    $breakdown->shouldReceive('getTaxAmount')->andReturn(21.0);
    $breakdown->shouldReceive('getSurchargeRate')->andReturn($surchargeRate);
    $breakdown->shouldReceive('getSurchargeAmount')->andReturn($surchargeAmount);
    $breakdown->shouldReceive('isExempt')->andReturn(false);
    $breakdown->shouldReceive('getExemptionReason')->andReturn(null);

    return $breakdown;
}

function rateInvoice(InvoiceBreakdownContract $breakdown): InvoiceContract
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
    $invoice->shouldReceive('getBreakdowns')->andReturn(collect([$breakdown]));

    return $invoice;
}

function rateChain(): RegistryChain
{
    return new RegistryChain(
        hash: str_repeat('A', 64),
        generatedAt: Carbon::parse('2026-06-01T10:00:30+02:00'),
        previous: null,
    );
}

it('accepts a tax rate at the 3 integer-digit boundary and validates against the XSD', function () {
    $xml = $this->builder->buildRegistrationXml(rateInvoice(rateBreakdown(taxRate: 999.99)), rateChain());

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain('<sf:TipoImpositivo>999.99</sf:TipoImpositivo>');
});

it('rejects a tax rate that exceeds Tipo2.2Type (4 integer digits)', function () {
    expect(fn () => $this->builder->buildRegistrationXml(rateInvoice(rateBreakdown(taxRate: 1000.0)), rateChain()))
        ->toThrow(ValidationException::class, "field 'TipoImpositivo'");
});

it('rejects a tax rate that rounds up into 4 integer digits', function () {
    // 999.999 rounds to 1000.00 (4 integer digits); the check must count AFTER formatting.
    expect(fn () => $this->builder->buildRegistrationXml(rateInvoice(rateBreakdown(taxRate: 999.999)), rateChain()))
        ->toThrow(ValidationException::class, "field 'TipoImpositivo'");
});

it('rejects a negative tax rate (Tipo2.2Type has no sign)', function () {
    expect(fn () => $this->builder->buildRegistrationXml(rateInvoice(rateBreakdown(taxRate: -5.0)), rateChain()))
        ->toThrow(ValidationException::class, "field 'TipoImpositivo'");
});

it('rejects non-finite tax rates', function (float $bad) {
    expect(fn () => $this->builder->buildRegistrationXml(rateInvoice(rateBreakdown(taxRate: $bad)), rateChain()))
        ->toThrow(ValidationException::class, "field 'TipoImpositivo'");
})->with([INF, -INF, NAN]);

it('also validates TipoRecargoEquivalencia via the surcharge rate', function () {
    // tax rate is valid; the surcharge rate overflows Tipo2.2Type.
    $breakdown = rateBreakdown(taxRate: 21.0, surchargeRate: 1000.0, surchargeAmount: 5.0);

    expect(fn () => $this->builder->buildRegistrationXml(rateInvoice($breakdown), rateChain()))
        ->toThrow(ValidationException::class, "field 'TipoRecargoEquivalencia'");
});
