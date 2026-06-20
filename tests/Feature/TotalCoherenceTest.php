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
 * AID-193 — ImporteTotal / CuotaTotal coherence guard.
 *
 * Validaciones doc v1.1.5 §16/§17:
 *   CuotaTotal   = Σ(CuotaRepercutida + CuotaRecargoEquivalencia)
 *   ImporteTotal = Σ(BaseImponibleOimporteNoSujeto + CuotaRepercutida + CuotaRecargoEquivalencia)
 * with an AEAT margin of ±10,00 EUR. Beyond it AEAT does not reject — it accepts
 * the record "con errores" (subsanable) — so the package fails loud to keep the
 * consumer from shipping a record it would have to amend. Exempt breakdowns emit
 * only BaseImponibleOimporteNoSujeto, so they contribute base only.
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

function coherenceTaxedBreakdown(float $base, float $taxRate, float $taxAmount, ?float $surchargeRate = null, ?float $surchargeAmount = null): InvoiceBreakdownContract
{
    $breakdown = Mockery::mock(InvoiceBreakdownContract::class);
    $breakdown->shouldReceive('getTaxType')->andReturn(TaxTypeEnum::IVA);
    $breakdown->shouldReceive('getTaxRate')->andReturn($taxRate);
    $breakdown->shouldReceive('getBaseAmount')->andReturn($base);
    $breakdown->shouldReceive('getTaxAmount')->andReturn($taxAmount);
    $breakdown->shouldReceive('getSurchargeRate')->andReturn($surchargeRate);
    $breakdown->shouldReceive('getSurchargeAmount')->andReturn($surchargeAmount);
    $breakdown->shouldReceive('isExempt')->andReturn(false);
    $breakdown->shouldReceive('getCalificacion')->andReturn(null);
    $breakdown->shouldReceive('getExemptionReason')->andReturn(null);

    return $breakdown;
}

function coherenceExemptBreakdown(float $base, string $reason = 'E1'): InvoiceBreakdownContract
{
    $breakdown = Mockery::mock(InvoiceBreakdownContract::class);
    $breakdown->shouldReceive('getTaxType')->andReturn(TaxTypeEnum::IVA);
    $breakdown->shouldReceive('getTaxRate')->andReturn(0.0);
    $breakdown->shouldReceive('getBaseAmount')->andReturn($base);
    $breakdown->shouldReceive('getTaxAmount')->andReturn(0.0);
    $breakdown->shouldReceive('getSurchargeRate')->andReturn(null);
    $breakdown->shouldReceive('getSurchargeAmount')->andReturn(null);
    $breakdown->shouldReceive('isExempt')->andReturn(true);
    $breakdown->shouldReceive('getCalificacion')->andReturn(null);
    $breakdown->shouldReceive('getExemptionReason')->andReturn($reason);

    return $breakdown;
}

/**
 * @param  array<int, InvoiceBreakdownContract>  $breakdowns
 */
function coherenceInvoice(array $breakdowns, float $declaredCuota, float $declaredImporte): InvoiceContract
{
    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn('89890001K');
    $invoice->shouldReceive('getSerie')->andReturn(null);
    $invoice->shouldReceive('getNumber')->andReturn('FAC-2026-001');
    $invoice->shouldReceive('getInvoiceNumber')->andReturn('FAC-2026-001');
    $invoice->shouldReceive('getIssueDatetime')->andReturn(Carbon::parse('2026-06-01 10:00:00'));
    $invoice->shouldReceive('getType')->andReturn(InvoiceTypeEnum::COMPLETE);
    $invoice->shouldReceive('getInvoiceType')->andReturn(InvoiceTypeEnum::COMPLETE);
    $invoice->shouldReceive('getDescription')->andReturn('Servicios facturados');
    $invoice->shouldReceive('getRegimeType')->andReturn(RegimeTypeEnum::GENERAL);
    $invoice->shouldReceive('getTaxAmount')->andReturn($declaredCuota);
    $invoice->shouldReceive('getTotalAmount')->andReturn($declaredImporte);
    $recipient = Mockery::mock(RecipientContract::class);
    $recipient->shouldReceive('getNif')->andReturn('12345678Z');
    $recipient->shouldReceive('getName')->andReturn('Cliente Ejemplo');
    $recipient->shouldReceive('getIdType')->andReturn(null);
    $recipient->shouldReceive('getId')->andReturn(null);
    $recipient->shouldReceive('getCountry')->andReturn('ES');
    $invoice->shouldReceive('hasRecipient')->andReturn(true);
    $invoice->shouldReceive('getRecipient')->andReturn($recipient);
    $invoice->shouldReceive('getBreakdowns')->andReturn(collect($breakdowns));

    return $invoice;
}

function coherenceChain(): RegistryChain
{
    return new RegistryChain(
        hash: str_repeat('A', 64),
        generatedAt: Carbon::parse('2026-06-01T10:00:30+02:00'),
        previous: null,
    );
}

it('passes when the declared totals match the breakdown sums', function () {
    // base 100, 21% -> cuota 21; CuotaTotal 21, ImporteTotal 121.
    $invoice = coherenceInvoice([coherenceTaxedBreakdown(100.0, 21.0, 21.0)], 21.0, 121.0);

    $xml = $this->builder->buildRegistrationXml($invoice, coherenceChain());

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain('<sf:CuotaTotal>21.00</sf:CuotaTotal>')
        ->and($xml)->toContain('<sf:ImporteTotal>121.00</sf:ImporteTotal>');
});

it('accepts a divergence up to the ±10,00 EUR margin (10.00 included)', function () {
    // sum ImporteTotal 121; declare 131.00 (diff exactly 10.00) -> within margin.
    $invoice = coherenceInvoice([coherenceTaxedBreakdown(100.0, 21.0, 21.0)], 21.0, 131.0);

    expect($this->builder->validate($this->builder->buildRegistrationXml($invoice, coherenceChain())))->toBeTrue();
});

it('rejects an ImporteTotal that diverges beyond 10,00 EUR', function (float $declaredImporte) {
    $invoice = coherenceInvoice([coherenceTaxedBreakdown(100.0, 21.0, 21.0)], 21.0, $declaredImporte);

    expect(fn () => $this->builder->buildRegistrationXml($invoice, coherenceChain()))
        ->toThrow(ValidationException::class, "field 'importe_total'");
})->with([
    'just over the margin (131.01)' => [131.01],
    'badly miscomputed (200.00)' => [200.0],
]);

it('rejects a CuotaTotal that diverges beyond 10,00 EUR', function () {
    // ImporteTotal coherent (121); CuotaTotal declared 50 vs sum 21 (diff 29).
    $invoice = coherenceInvoice([coherenceTaxedBreakdown(100.0, 21.0, 21.0)], 50.0, 121.0);

    expect(fn () => $this->builder->buildRegistrationXml($invoice, coherenceChain()))
        ->toThrow(ValidationException::class, "field 'cuota_total'");
});

it('counts the equivalence surcharge in both sums', function () {
    // base 1000, 21% -> cuota 210, surcharge 5,2% -> 52. sumCuota 262, sumImporte 1262.
    $breakdown = coherenceTaxedBreakdown(1000.0, 21.0, 210.0, surchargeRate: 5.2, surchargeAmount: 52.0);
    $invoice = coherenceInvoice([$breakdown], 262.0, 1262.0);

    expect($this->builder->validate($this->builder->buildRegistrationXml($invoice, coherenceChain())))->toBeTrue();
});

it('rejects a CuotaTotal that ignores the surcharge', function () {
    // Declaring 210 (tax only) omits the 52 surcharge -> diff 52 > 10.
    $breakdown = coherenceTaxedBreakdown(1000.0, 21.0, 210.0, surchargeRate: 5.2, surchargeAmount: 52.0);
    $invoice = coherenceInvoice([$breakdown], 210.0, 1262.0);

    expect(fn () => $this->builder->buildRegistrationXml($invoice, coherenceChain()))
        ->toThrow(ValidationException::class, "field 'cuota_total'");
});

it('treats an exempt breakdown as base-only', function () {
    // Fully exempt: sumImporte 100, sumCuota 0.
    $invoice = coherenceInvoice([coherenceExemptBreakdown(100.0, 'E1')], 0.0, 100.0);

    $xml = $this->builder->buildRegistrationXml($invoice, coherenceChain());

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain('<sf:ImporteTotal>100.00</sf:ImporteTotal>');
});

it('handles a mix of taxed and exempt breakdowns', function () {
    // taxed (100,21%->21) + exempt (50). sumCuota 21, sumImporte 171.
    $invoice = coherenceInvoice(
        [coherenceTaxedBreakdown(100.0, 21.0, 21.0), coherenceExemptBreakdown(50.0, 'E1')],
        21.0,
        171.0,
    );

    expect($this->builder->validate($this->builder->buildRegistrationXml($invoice, coherenceChain())))->toBeTrue();
});

it('rejects when an exempt base is left out of ImporteTotal', function () {
    // sumImporte 171 but declared 121 (the exempt 50 base dropped) -> diff 50 > 10.
    $invoice = coherenceInvoice(
        [coherenceTaxedBreakdown(100.0, 21.0, 21.0), coherenceExemptBreakdown(50.0, 'E1')],
        21.0,
        121.0,
    );

    expect(fn () => $this->builder->buildRegistrationXml($invoice, coherenceChain()))
        ->toThrow(ValidationException::class, "field 'importe_total'");
});

it('handles signed (negative) amounts coherently', function () {
    // A rectification by differences that reduces: negative desglose, base -100,
    // 21% -> cuota -21. sumCuota -21, sumImporte -121, matching the declared totals.
    $invoice = coherenceInvoice([coherenceTaxedBreakdown(-100.0, 21.0, -21.0)], -21.0, -121.0);

    expect($this->builder->validate($this->builder->buildRegistrationXml($invoice, coherenceChain())))->toBeTrue();
});

it('rejects mismatched signs between the totals and the desglose', function () {
    // Negative totals over a positive desglose: |−21 − 21| = 42 > 10.
    $invoice = coherenceInvoice([coherenceTaxedBreakdown(100.0, 21.0, 21.0)], -21.0, -121.0);

    expect(fn () => $this->builder->buildRegistrationXml($invoice, coherenceChain()))
        ->toThrow(ValidationException::class, "field 'cuota_total'");
});
