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
 * AID-181 — core completeness gaps:
 *   Macrodato (1139): emit Macrodato=S when |ImporteTotal| >= 100.000.000.
 *   F2 limit (1150):  F2 Σ(base + cuota) must not exceed 3.000 EUR (+10 margin).
 *   Date validity:    FechaExpedicionFactura not in the future (1112) nor before
 *                     28-10-2024 (1152).
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

function aid181Breakdown(float $base, float $taxRate, float $cuota, ?float $surchargeRate = null, ?float $surchargeAmount = null): InvoiceBreakdownContract
{
    $breakdown = Mockery::mock(InvoiceBreakdownContract::class);
    $breakdown->shouldReceive('getTaxType')->andReturn(TaxTypeEnum::IVA);
    $breakdown->shouldReceive('getTaxRate')->andReturn($taxRate);
    $breakdown->shouldReceive('getBaseAmount')->andReturn($base);
    $breakdown->shouldReceive('getTaxAmount')->andReturn($cuota);
    $breakdown->shouldReceive('getSurchargeRate')->andReturn($surchargeRate);
    $breakdown->shouldReceive('getSurchargeAmount')->andReturn($surchargeAmount);
    $breakdown->shouldReceive('isExempt')->andReturn(false);
    $breakdown->shouldReceive('getCalificacion')->andReturn(null);
    $breakdown->shouldReceive('getExemptionReason')->andReturn(null);

    return $breakdown;
}

/**
 * @param  array<int, InvoiceBreakdownContract>  $breakdowns
 */
function aid181Invoice(
    InvoiceTypeEnum $type,
    array $breakdowns,
    float $cuotaTotal,
    float $importeTotal,
    ?Carbon $issueDatetime = null,
    bool $withRecipient = true,
): InvoiceContract {
    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn('89890001K');
    $invoice->shouldReceive('getSerie')->andReturn(null);
    $invoice->shouldReceive('getNumber')->andReturn('FAC-2026-001');
    $invoice->shouldReceive('getInvoiceNumber')->andReturn('FAC-2026-001');
    $invoice->shouldReceive('getIssueDatetime')->andReturn($issueDatetime ?? Carbon::parse('2026-06-01 10:00:00'));
    $invoice->shouldReceive('getType')->andReturn($type);
    $invoice->shouldReceive('getInvoiceType')->andReturn($type);
    $invoice->shouldReceive('getRectificationType')->andReturn(null);
    $invoice->shouldReceive('getDescription')->andReturn('Operación de prueba');
    $invoice->shouldReceive('getRegimeType')->andReturn(RegimeTypeEnum::GENERAL);
    $invoice->shouldReceive('getTaxAmount')->andReturn($cuotaTotal);
    $invoice->shouldReceive('getTotalAmount')->andReturn($importeTotal);

    if ($withRecipient) {
        $recipient = Mockery::mock(RecipientContract::class);
        $recipient->shouldReceive('getNif')->andReturn('12345678Z');
        $recipient->shouldReceive('getName')->andReturn('Cliente Ejemplo');
        $recipient->shouldReceive('getIdType')->andReturn(null);
        $recipient->shouldReceive('getId')->andReturn(null);
        $recipient->shouldReceive('getCountry')->andReturn('ES');
        $invoice->shouldReceive('hasRecipient')->andReturn(true);
        $invoice->shouldReceive('getRecipient')->andReturn($recipient);
    } else {
        $invoice->shouldReceive('hasRecipient')->andReturn(false);
        $invoice->shouldReceive('getRecipient')->andReturn(null);
    }

    $invoice->shouldReceive('getBreakdowns')->andReturn(collect($breakdowns));

    return $invoice;
}

function aid181Chain(): RegistryChain
{
    return new RegistryChain(
        hash: str_repeat('A', 64),
        generatedAt: Carbon::parse('2026-06-01T10:00:30+02:00'),
        previous: null,
    );
}

describe('Macrodato (1139)', function () {
    it('emits Macrodato=S when ImporteTotal reaches 100.000.000', function () {
        // base 100M at 0% -> ImporteTotal 100M, CuotaTotal 0 (coherent).
        $invoice = aid181Invoice(InvoiceTypeEnum::COMPLETE, [aid181Breakdown(100000000.0, 0.0, 0.0)], 0.0, 100000000.0);

        $xml = $this->builder->buildRegistrationXml($invoice, aid181Chain());

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:Macrodato>S</sf:Macrodato>');
    });

    it('omits Macrodato below the threshold', function () {
        $invoice = aid181Invoice(InvoiceTypeEnum::COMPLETE, [aid181Breakdown(99999999.99, 0.0, 0.0)], 0.0, 99999999.99);

        $xml = $this->builder->buildRegistrationXml($invoice, aid181Chain());

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->not->toContain('Macrodato');
    });

    it('uses the absolute value (negative ImporteTotal at the threshold)', function () {
        $invoice = aid181Invoice(InvoiceTypeEnum::COMPLETE, [aid181Breakdown(-100000000.0, 0.0, 0.0)], 0.0, -100000000.0);

        $xml = $this->builder->buildRegistrationXml($invoice, aid181Chain());

        expect($xml)->toContain('<sf:Macrodato>S</sf:Macrodato>');
    });
});

describe('F2 3.000 limit (1150)', function () {
    it('accepts an F2 at or below 3.000 (including the +10 margin)', function (float $base) {
        $invoice = aid181Invoice(InvoiceTypeEnum::SIMPLIFIED, [aid181Breakdown($base, 0.0, 0.0)], 0.0, $base, withRecipient: false);

        expect($this->builder->validate($this->builder->buildRegistrationXml($invoice, aid181Chain())))->toBeTrue();
    })->with([
        'exactly 3.000' => [3000.0],
        'within the +10 margin' => [3010.0],
    ]);

    it('rejects an F2 whose base+cuota exceeds 3.000 + 10', function () {
        $invoice = aid181Invoice(InvoiceTypeEnum::SIMPLIFIED, [aid181Breakdown(3500.0, 0.0, 0.0)], 0.0, 3500.0, withRecipient: false);

        expect(fn () => $this->builder->buildRegistrationXml($invoice, aid181Chain()))
            ->toThrow(ValidationException::class, "field 'f2_limit'");
    });

    it('counts the cuota, not only the base', function () {
        // base 2600 (<3000 alone) + cuota 546 (21%) = 3146 -> over the limit.
        $invoice = aid181Invoice(InvoiceTypeEnum::SIMPLIFIED, [aid181Breakdown(2600.0, 21.0, 546.0)], 546.0, 3146.0, withRecipient: false);

        expect(fn () => $this->builder->buildRegistrationXml($invoice, aid181Chain()))
            ->toThrow(ValidationException::class, "field 'f2_limit'");
    });

    it('excludes the equivalence surcharge from the F2 limit (§15.8 sums base + cuota only)', function () {
        // base 2400 + cuota 504 = 2904 (within the cap); the 124,80 surcharge would
        // push it over if counted, but §15.8 omits CuotaRecargoEquivalencia.
        $invoice = aid181Invoice(InvoiceTypeEnum::SIMPLIFIED, [aid181Breakdown(2400.0, 21.0, 504.0, 5.2, 124.8)], 628.8, 3028.8, withRecipient: false);

        expect($this->builder->validate($this->builder->buildRegistrationXml($invoice, aid181Chain())))->toBeTrue();
    });

    it('does not apply the limit to F1', function () {
        $invoice = aid181Invoice(InvoiceTypeEnum::COMPLETE, [aid181Breakdown(5000.0, 0.0, 0.0)], 0.0, 5000.0);

        expect($this->builder->validate($this->builder->buildRegistrationXml($invoice, aid181Chain())))->toBeTrue();
    });
});

describe('Date validity (1112 / 1152)', function () {
    it('rejects a FechaExpedicionFactura in the future', function () {
        // generatedAt is 2026-06-01; issue date the next day is in the future.
        $invoice = aid181Invoice(InvoiceTypeEnum::COMPLETE, [aid181Breakdown(100.0, 21.0, 21.0)], 21.0, 121.0, issueDatetime: Carbon::parse('2026-06-02 09:00:00'));

        expect(fn () => $this->builder->buildRegistrationXml($invoice, aid181Chain()))
            ->toThrow(ValidationException::class, "field 'issue_date'");
    });

    it('rejects a FechaExpedicionFactura before 28-10-2024', function () {
        $invoice = aid181Invoice(InvoiceTypeEnum::COMPLETE, [aid181Breakdown(100.0, 21.0, 21.0)], 21.0, 121.0, issueDatetime: Carbon::parse('2024-10-27 12:00:00'));

        expect(fn () => $this->builder->buildRegistrationXml($invoice, aid181Chain()))
            ->toThrow(ValidationException::class, "field 'issue_date'");
    });

    it('accepts the 28-10-2024 cutoff date', function () {
        $invoice = aid181Invoice(InvoiceTypeEnum::COMPLETE, [aid181Breakdown(100.0, 21.0, 21.0)], 21.0, 121.0, issueDatetime: Carbon::parse('2024-10-28 09:00:00'));

        expect($this->builder->validate($this->builder->buildRegistrationXml($invoice, aid181Chain())))->toBeTrue();
    });

    it('accepts an issue date on the generation day', function () {
        $invoice = aid181Invoice(InvoiceTypeEnum::COMPLETE, [aid181Breakdown(100.0, 21.0, 21.0)], 21.0, 121.0, issueDatetime: Carbon::parse('2026-06-01 09:00:00'));

        expect($this->builder->validate($this->builder->buildRegistrationXml($invoice, aid181Chain())))->toBeTrue();
    });
});
