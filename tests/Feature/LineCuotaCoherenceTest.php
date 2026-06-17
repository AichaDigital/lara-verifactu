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
 * AID-196 — per-line CuotaRepercutida coherence guard (Validaciones §15.7).
 *
 * For an S1 line, CuotaRepercutida must share BaseImponibleOimporteNoSujeto's sign
 * and equal base × TipoImpositivo / 100 within ±10,00 EUR. The check is skipped for
 * rectifications by differences (TipoRectificativa I) — and R2/R3, already rejected
 * by guard #7. Beyond the margin AEAT accepts the record "con errores" (subsanable).
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

function lineCuotaBreakdown(float $base, float $taxRate, float $taxAmount): InvoiceBreakdownContract
{
    $breakdown = Mockery::mock(InvoiceBreakdownContract::class);
    $breakdown->shouldReceive('getTaxType')->andReturn(TaxTypeEnum::IVA);
    $breakdown->shouldReceive('getTaxRate')->andReturn($taxRate);
    $breakdown->shouldReceive('getBaseAmount')->andReturn($base);
    $breakdown->shouldReceive('getTaxAmount')->andReturn($taxAmount);
    $breakdown->shouldReceive('getSurchargeRate')->andReturn(null);
    $breakdown->shouldReceive('getSurchargeAmount')->andReturn(null);
    $breakdown->shouldReceive('isExempt')->andReturn(false);
    $breakdown->shouldReceive('getExemptionReason')->andReturn(null);

    return $breakdown;
}

/**
 * @param  array<int, InvoiceBreakdownContract>  $breakdowns
 */
function lineCuotaInvoice(array $breakdowns, float $declaredCuota, float $declaredImporte, string $type = 'F1', ?string $rectType = null): InvoiceContract
{
    $invoiceType = $type === 'R1' ? InvoiceTypeEnum::RECTIFICATIVE : InvoiceTypeEnum::COMPLETE;

    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn('89890001K');
    $invoice->shouldReceive('getSerie')->andReturn(null);
    $invoice->shouldReceive('getNumber')->andReturn('FAC-2026-001');
    $invoice->shouldReceive('getInvoiceNumber')->andReturn('FAC-2026-001');
    $invoice->shouldReceive('getIssueDatetime')->andReturn(Carbon::parse('2026-06-01 10:00:00'));
    $invoice->shouldReceive('getType')->andReturn($invoiceType);
    $invoice->shouldReceive('getInvoiceType')->andReturn($invoiceType);
    $invoice->shouldReceive('getRectificationType')->andReturn($rectType);
    $invoice->shouldReceive('getRectifiedInvoices')->andReturn([]);
    $invoice->shouldReceive('getRectificationAmounts')->andReturn(
        $rectType === 'S' ? ['base' => 100.0, 'tax' => 21.0, 'surcharge' => null] : null
    );
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

function lineCuotaChain(): RegistryChain
{
    return new RegistryChain(
        hash: str_repeat('A', 64),
        generatedAt: Carbon::parse('2026-06-01T10:00:30+02:00'),
        previous: null,
    );
}

it('passes when CuotaRepercutida equals base × rate', function () {
    $invoice = lineCuotaInvoice([lineCuotaBreakdown(100.0, 21.0, 21.0)], 21.0, 121.0);

    expect($this->builder->validate($this->builder->buildRegistrationXml($invoice, lineCuotaChain())))->toBeTrue();
});

it('accepts a deviation up to ±10,00 EUR (10.00 included)', function () {
    // expected 21; declare cuota 31 (diff exactly 10).
    $invoice = lineCuotaInvoice([lineCuotaBreakdown(100.0, 21.0, 31.0)], 31.0, 131.0);

    expect($this->builder->validate($this->builder->buildRegistrationXml($invoice, lineCuotaChain())))->toBeTrue();
});

it('rejects a CuotaRepercutida off by more than 10,00 EUR', function (float $cuota) {
    $invoice = lineCuotaInvoice([lineCuotaBreakdown(100.0, 21.0, $cuota)], $cuota, 100.0 + $cuota);

    expect(fn () => $this->builder->buildRegistrationXml($invoice, lineCuotaChain()))
        ->toThrow(ValidationException::class, "field 'cuota_repercutida'");
})->with([
    'just over (31.01)' => [31.01],
    'badly off (40.00)' => [40.0],
]);

it('rejects a CuotaRepercutida with the opposite sign to the base', function () {
    // base +100, cuota -21: opposite signs.
    $invoice = lineCuotaInvoice([lineCuotaBreakdown(100.0, 21.0, -21.0)], -21.0, 79.0);

    expect(fn () => $this->builder->buildRegistrationXml($invoice, lineCuotaChain()))
        ->toThrow(ValidationException::class, "field 'cuota_repercutida'");
});

it('accepts a 0% line with zero cuota', function () {
    $invoice = lineCuotaInvoice([lineCuotaBreakdown(100.0, 0.0, 0.0)], 0.0, 100.0);

    expect($this->builder->validate($this->builder->buildRegistrationXml($invoice, lineCuotaChain())))->toBeTrue();
});

it('rejects a 0% line carrying a cuota', function () {
    // expected 0; declare 50.
    $invoice = lineCuotaInvoice([lineCuotaBreakdown(100.0, 0.0, 50.0)], 50.0, 150.0);

    expect(fn () => $this->builder->buildRegistrationXml($invoice, lineCuotaChain()))
        ->toThrow(ValidationException::class, "field 'cuota_repercutida'");
});

it('exempts a rectification by differences (TipoRectificativa I) from the equality check', function () {
    // R1/I: cuota 40 over base 100 at 21% would fail the equality, but §15.7 skips it.
    $invoice = lineCuotaInvoice([lineCuotaBreakdown(100.0, 21.0, 40.0)], 40.0, 140.0, type: 'R1', rectType: 'I');

    expect($this->builder->validate($this->builder->buildRegistrationXml($invoice, lineCuotaChain())))->toBeTrue();
});

it('still validates a rectification by substitution (TipoRectificativa S)', function () {
    // R1/S is not exempt: an incoherent cuota must fail.
    $invoice = lineCuotaInvoice([lineCuotaBreakdown(100.0, 21.0, 40.0)], 40.0, 140.0, type: 'R1', rectType: 'S');

    expect(fn () => $this->builder->buildRegistrationXml($invoice, lineCuotaChain()))
        ->toThrow(ValidationException::class, "field 'cuota_repercutida'");
});

it('rejects when any line is incoherent', function () {
    // First line coherent, second off by >10.
    $invoice = lineCuotaInvoice(
        [lineCuotaBreakdown(100.0, 21.0, 21.0), lineCuotaBreakdown(100.0, 21.0, 40.0)],
        61.0,
        261.0,
    );

    expect(fn () => $this->builder->buildRegistrationXml($invoice, lineCuotaChain()))
        ->toThrow(ValidationException::class, "field 'cuota_repercutida'");
});
