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
 * AID-195 (part A) — equivalence-surcharge magnitude guard (#9).
 *
 * AEAT rules 1160/1162/1163/1164 fix the TipoRecargoEquivalencia allowed for a
 * given TipoImpositivo. Only the date-independent subset is in the v1.0 core:
 *   21% -> {5,2 ; 1,75}   10% -> {1,4}   4% -> {0,5}
 * The 5% pair {0,5 ; 0,62} is date-windowed (rules 1167/1168/1194) and the
 * transitional rates 0/2/7,5% (rules 1165-1170/1277) need effective-date logic,
 * which is post-1.0 — a surcharge on those rates is rejected fail-loud.
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

/**
 * Build a non-exempt breakdown with coherent amounts (base 100): the tax and
 * surcharge cuotas are derived from the rates so the scenario is also
 * business-consistent (AEAT 2005/2006), not just XSD-valid.
 */
function surchargeMagnitudeBreakdown(float $taxRate, ?float $surchargeRate, ?float $surchargeAmount = null): InvoiceBreakdownContract
{
    $base = 100.0;
    $breakdown = Mockery::mock(InvoiceBreakdownContract::class);
    $breakdown->shouldReceive('getTaxType')->andReturn(TaxTypeEnum::IVA);
    $breakdown->shouldReceive('getTaxRate')->andReturn($taxRate);
    $breakdown->shouldReceive('getBaseAmount')->andReturn($base);
    $breakdown->shouldReceive('getTaxAmount')->andReturn(round($base * $taxRate / 100, 2));
    $breakdown->shouldReceive('getSurchargeRate')->andReturn($surchargeRate);
    $breakdown->shouldReceive('getSurchargeAmount')->andReturn(
        $surchargeAmount ?? ($surchargeRate !== null ? round($base * $surchargeRate / 100, 2) : null)
    );
    $breakdown->shouldReceive('isExempt')->andReturn(false);
    $breakdown->shouldReceive('getExemptionReason')->andReturn(null);

    return $breakdown;
}

function surchargeMagnitudeInvoice(InvoiceBreakdownContract $breakdown): InvoiceContract
{
    $base = $breakdown->getBaseAmount();
    $tax = $breakdown->getTaxAmount();
    $surcharge = $breakdown->getSurchargeAmount() ?? 0.0;
    $cuotaTotal = round($tax + $surcharge, 2);
    $importeTotal = round($base + $cuotaTotal, 2);

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
    $invoice->shouldReceive('getTaxAmount')->andReturn($cuotaTotal);
    $invoice->shouldReceive('getTotalAmount')->andReturn($importeTotal);
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

function surchargeMagnitudeChain(): RegistryChain
{
    return new RegistryChain(
        hash: str_repeat('A', 64),
        generatedAt: Carbon::parse('2026-06-01T10:00:30+02:00'),
        previous: null,
    );
}

it('accepts every stable rate/surcharge pair and validates against the XSD', function (float $taxRate, float $surchargeRate, string $expectedSurcharge) {
    $invoice = surchargeMagnitudeInvoice(surchargeMagnitudeBreakdown($taxRate, $surchargeRate));

    $xml = $this->builder->buildRegistrationXml($invoice, surchargeMagnitudeChain());

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain("<sf:TipoRecargoEquivalencia>{$expectedSurcharge}</sf:TipoRecargoEquivalencia>");
})->with([
    'general 21% -> 5,2' => [21.0, 5.2, '5.20'],
    'tobacco 21% -> 1,75' => [21.0, 1.75, '1.75'],
    'reduced 10% -> 1,4' => [10.0, 1.4, '1.40'],
    'super-reduced 4% -> 0,5' => [4.0, 0.5, '0.50'],
]);

it('rejects a surcharge magnitude that does not match its tax rate', function (float $taxRate, float $surchargeRate) {
    $invoice = surchargeMagnitudeInvoice(surchargeMagnitudeBreakdown($taxRate, $surchargeRate));

    expect(fn () => $this->builder->buildRegistrationXml($invoice, surchargeMagnitudeChain()))
        ->toThrow(ValidationException::class, "field 'surcharge'");
})->with([
    '21% with 1,4 (10% value)' => [21.0, 1.4],
    '21% with 0,5 (4% value)' => [21.0, 0.5],
    '10% with 0,5 (4% value)' => [10.0, 0.5],
    '10% with 1,75 (21% value)' => [10.0, 1.75],
    '4% with 0,62 (5% value)' => [4.0, 0.62],
]);

it('rejects the date-windowed 5% rate with a distinct transitional message', function (float $surchargeRate) {
    $invoice = surchargeMagnitudeInvoice(surchargeMagnitudeBreakdown(5.0, $surchargeRate));

    expect(fn () => $this->builder->buildRegistrationXml($invoice, surchargeMagnitudeChain()))
        ->toThrow(ValidationException::class, 'date-windowed transitional');
})->with([
    '5% -> 0,5' => [0.5],
    '5% -> 0,62' => [0.62],
]);

it('rejects other date-windowed transitional rates with a surcharge', function (float $taxRate, float $surchargeRate) {
    $invoice = surchargeMagnitudeInvoice(surchargeMagnitudeBreakdown($taxRate, $surchargeRate));

    expect(fn () => $this->builder->buildRegistrationXml($invoice, surchargeMagnitudeChain()))
        ->toThrow(ValidationException::class, 'date-windowed transitional');
})->with([
    '0% -> 0,26' => [0.0, 0.26],
    '2% -> 0,26' => [2.0, 0.26],
    '7,5% -> 1' => [7.5, 1.0],
]);

it('rejects a surcharge on a rate that has no core magnitude at all', function () {
    // 8% is neither a stable surcharge rate nor a known transitional one.
    $invoice = surchargeMagnitudeInvoice(surchargeMagnitudeBreakdown(8.0, 1.0));

    expect(fn () => $this->builder->buildRegistrationXml($invoice, surchargeMagnitudeChain()))
        ->toThrow(ValidationException::class, 'no equivalence-surcharge magnitude');
});
