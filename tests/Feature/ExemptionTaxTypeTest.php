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
 * AID-195 (part B) — OperacionExenta x Impuesto guard (AEAT rule 1199).
 *
 * With Impuesto 01 (IVA) or 03 (IGIC) and ClaveRegimen 01 (the general regime,
 * the only one in the v1.0 core), exemption causes E2 (art. 21) and E3 (art. 22)
 * cannot be used. They remain valid for IPSI (02), which rule 1199 does not name.
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

function exemptBreakdown(TaxTypeEnum $taxType, string $exemptionReason): InvoiceBreakdownContract
{
    $breakdown = Mockery::mock(InvoiceBreakdownContract::class);
    $breakdown->shouldReceive('getTaxType')->andReturn($taxType);
    $breakdown->shouldReceive('getTaxRate')->andReturn(0.0);
    $breakdown->shouldReceive('getBaseAmount')->andReturn(100.0);
    $breakdown->shouldReceive('getTaxAmount')->andReturn(0.0);
    $breakdown->shouldReceive('getSurchargeRate')->andReturn(null);
    $breakdown->shouldReceive('getSurchargeAmount')->andReturn(null);
    $breakdown->shouldReceive('isExempt')->andReturn(true);
    $breakdown->shouldReceive('getCalificacion')->andReturn(null);
    $breakdown->shouldReceive('getExemptionReason')->andReturn($exemptionReason);

    return $breakdown;
}

function exemptInvoice(InvoiceBreakdownContract $breakdown): InvoiceContract
{
    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn('89890001K');
    $invoice->shouldReceive('getSerie')->andReturn(null);
    $invoice->shouldReceive('getNumber')->andReturn('FAC-2026-001');
    $invoice->shouldReceive('getInvoiceNumber')->andReturn('FAC-2026-001');
    $invoice->shouldReceive('getIssueDatetime')->andReturn(Carbon::parse('2026-06-01 10:00:00'));
    $invoice->shouldReceive('getType')->andReturn(InvoiceTypeEnum::COMPLETE);
    $invoice->shouldReceive('getInvoiceType')->andReturn(InvoiceTypeEnum::COMPLETE);
    $invoice->shouldReceive('getDescription')->andReturn('Operación exenta');
    $invoice->shouldReceive('getRegimeType')->andReturn(RegimeTypeEnum::GENERAL);
    $invoice->shouldReceive('getTaxAmount')->andReturn(0.0);
    $invoice->shouldReceive('getTotalAmount')->andReturn(100.0);
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

function exemptChain(): RegistryChain
{
    return new RegistryChain(
        hash: str_repeat('A', 64),
        generatedAt: Carbon::parse('2026-06-01T10:00:30+02:00'),
        previous: null,
    );
}

it('rejects E2/E3 with IVA or IGIC under the general regime (rule 1199)', function (TaxTypeEnum $taxType, string $cause) {
    $invoice = exemptInvoice(exemptBreakdown($taxType, $cause));

    expect(fn () => $this->builder->buildRegistrationXml($invoice, exemptChain()))
        ->toThrow(ValidationException::class, "field 'exemption_reason'");
})->with([
    'IVA + E2' => [TaxTypeEnum::IVA, 'E2'],
    'IVA + E3' => [TaxTypeEnum::IVA, 'E3'],
    'IGIC + E2' => [TaxTypeEnum::IGIC, 'E2'],
    'IGIC + E3' => [TaxTypeEnum::IGIC, 'E3'],
]);

it('cites rule 1199 in the rejection message', function () {
    $invoice = exemptInvoice(exemptBreakdown(TaxTypeEnum::IVA, 'E2'));

    expect(fn () => $this->builder->buildRegistrationXml($invoice, exemptChain()))
        ->toThrow(ValidationException::class, '1199');
});

it('allows E2/E3 with IPSI (rule 1199 does not cover Impuesto 02)', function (string $cause) {
    $invoice = exemptInvoice(exemptBreakdown(TaxTypeEnum::IPSI, $cause));

    $xml = $this->builder->buildRegistrationXml($invoice, exemptChain());

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain("<sf:OperacionExenta>{$cause}</sf:OperacionExenta>");
})->with([
    'IPSI + E2' => ['E2'],
    'IPSI + E3' => ['E3'],
]);

it('keeps the other core exemption causes valid with IVA (no regression)', function (string $cause) {
    $invoice = exemptInvoice(exemptBreakdown(TaxTypeEnum::IVA, $cause));

    $xml = $this->builder->buildRegistrationXml($invoice, exemptChain());

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain("<sf:OperacionExenta>{$cause}</sf:OperacionExenta>");
})->with([
    'IVA + E1' => ['E1'],
    'IVA + E4' => ['E4'],
    'IVA + E6' => ['E6'],
]);
