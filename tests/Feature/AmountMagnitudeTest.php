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
 * AID-168: every ImporteSgn12.2Type amount must fit in 12 integer digits.
 * formatAmount() throws ValidationException before producing XSD-invalid XML,
 * instead of emitting XML that AEAT (or schemaValidate) would reject.
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

function magnitudeBreakdown(float $baseAmount, float $taxAmount): InvoiceBreakdownContract
{
    $breakdown = Mockery::mock(InvoiceBreakdownContract::class);
    $breakdown->shouldReceive('getTaxType')->andReturn(TaxTypeEnum::IVA);
    $breakdown->shouldReceive('getTaxRate')->andReturn(21.0);
    $breakdown->shouldReceive('getBaseAmount')->andReturn($baseAmount);
    $breakdown->shouldReceive('getTaxAmount')->andReturn($taxAmount);
    $breakdown->shouldReceive('getSurchargeRate')->andReturn(null);
    $breakdown->shouldReceive('getSurchargeAmount')->andReturn(null);
    $breakdown->shouldReceive('isExempt')->andReturn(false);
    $breakdown->shouldReceive('getExemptionReason')->andReturn(null);

    return $breakdown;
}

function magnitudeInvoice(float $taxAmount, float $totalAmount, ?InvoiceBreakdownContract $breakdown = null): InvoiceContract
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
    $invoice->shouldReceive('getTaxAmount')->andReturn($taxAmount);
    $invoice->shouldReceive('getTotalAmount')->andReturn($totalAmount);
    $recipient = Mockery::mock(RecipientContract::class);
    $recipient->shouldReceive('getNif')->andReturn('12345678Z');
    $recipient->shouldReceive('getName')->andReturn('Cliente Ejemplo');
    $recipient->shouldReceive('getIdType')->andReturn(null);
    $recipient->shouldReceive('getId')->andReturn(null);
    $recipient->shouldReceive('getCountry')->andReturn('ES');
    $invoice->shouldReceive('hasRecipient')->andReturn(true);
    $invoice->shouldReceive('getRecipient')->andReturn($recipient);
    $invoice->shouldReceive('getBreakdowns')->andReturn(collect([$breakdown ?? magnitudeBreakdown(100.0, 21.0)]));

    return $invoice;
}

function magnitudeChain(): RegistryChain
{
    return new RegistryChain(
        hash: str_repeat('A', 64),
        generatedAt: Carbon::parse('2026-06-01T10:00:30+02:00'),
        previous: null,
    );
}

it('throws ValidationException when an amount exceeds ImporteSgn12.2Type (13 integer digits)', function () {
    // CuotaTotal = getTaxAmount(); 1e12 has 13 integer digits.
    $invoice = magnitudeInvoice(taxAmount: 1000000000000.0, totalAmount: 121.0);

    expect(fn () => $this->builder->buildRegistrationXml($invoice, magnitudeChain()))
        ->toThrow(ValidationException::class, "field 'CuotaTotal': value 1000000000000.00 exceeds ImporteSgn12.2Type: maximum 12 integer digits");
});

it('accepts an amount at the 12 integer-digit boundary and validates against the XSD', function () {
    // Base 0 so the 12-digit boundary lands on the cuota and the declared totals
    // (CuotaTotal = ImporteTotal = cuota) stay coherent with the desglose.
    $breakdown = magnitudeBreakdown(baseAmount: 0.0, taxAmount: 999999999999.99);
    $invoice = magnitudeInvoice(taxAmount: 999999999999.99, totalAmount: 999999999999.99, breakdown: $breakdown);

    $xml = $this->builder->buildRegistrationXml($invoice, magnitudeChain());

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain('<sf:CuotaTotal>999999999999.99</sf:CuotaTotal>')
        ->and($xml)->toContain('<sf:ImporteTotal>999999999999.99</sf:ImporteTotal>');
});

it('does not count the sign: a negative amount at the 12-digit boundary is accepted', function () {
    $breakdown = magnitudeBreakdown(baseAmount: 0.0, taxAmount: -999999999999.99);
    $invoice = magnitudeInvoice(taxAmount: -999999999999.99, totalAmount: -999999999999.99, breakdown: $breakdown);

    expect(fn () => $this->builder->buildRegistrationXml($invoice, magnitudeChain()))
        ->not->toThrow(ValidationException::class);
});

it('rejects an amount that rounds up into 13 integer digits', function () {
    // 999999999999.999 has 12 integer digits raw, but number_format rounds it to
    // 1000000000000.00 (13 digits). The check must count AFTER formatting.
    $invoice = magnitudeInvoice(taxAmount: 999999999999.999, totalAmount: 121.0);

    expect(fn () => $this->builder->buildRegistrationXml($invoice, magnitudeChain()))
        ->toThrow(ValidationException::class, "field 'CuotaTotal'");
});

it('rejects a negative amount that rounds up into 13 integer digits', function () {
    // -999999999999.999 rounds to -1000000000000.00; the sign is excluded, leaving 13 digits.
    $invoice = magnitudeInvoice(taxAmount: -999999999999.999, totalAmount: 121.0);

    expect(fn () => $this->builder->buildRegistrationXml($invoice, magnitudeChain()))
        ->toThrow(ValidationException::class, "field 'CuotaTotal'");
});

it('accepts a zero amount', function () {
    $breakdown = magnitudeBreakdown(baseAmount: 0.0, taxAmount: 0.0);
    $invoice = magnitudeInvoice(taxAmount: 0.0, totalAmount: 0.0, breakdown: $breakdown);

    $xml = $this->builder->buildRegistrationXml($invoice, magnitudeChain());

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain('<sf:CuotaTotal>0.00</sf:CuotaTotal>');
});

it('rejects non-finite amounts that would emit XSD-invalid XML', function (float $bad) {
    // number_format(INF) => "inf" (<= 12 chars), so without the is_finite guard
    // these would slip through and produce XSD-invalid XML instead of throwing.
    $invoice = magnitudeInvoice(taxAmount: $bad, totalAmount: 121.0);

    expect(fn () => $this->builder->buildRegistrationXml($invoice, magnitudeChain()))
        ->toThrow(ValidationException::class, "field 'CuotaTotal'");
})->with([INF, -INF, NAN]);
