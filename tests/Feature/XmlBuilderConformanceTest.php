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
    $recipient = Mockery::mock(RecipientContract::class);
    $recipient->shouldReceive('getNif')->andReturn('12345678Z');
    $recipient->shouldReceive('getName')->andReturn('Cliente Ejemplo');
    $recipient->shouldReceive('getIdType')->andReturn(null);
    $recipient->shouldReceive('getId')->andReturn(null);
    $recipient->shouldReceive('getCountry')->andReturn('ES');
    $invoice->shouldReceive('hasRecipient')->andReturn(true);
    $invoice->shouldReceive('getRecipient')->andReturn($recipient);
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

describe('rectificative registration XML (AID-135)', function () {
    function rectificativeInvoice(?string $rectificationType = 'I', array $rectifiedInvoices = [], ?array $rectificationAmounts = null): InvoiceContract
    {
        $invoice = Mockery::mock(InvoiceContract::class);
        $invoice->shouldReceive('getIssuerTaxId')->andReturn('89890001K');
        $invoice->shouldReceive('getSerie')->andReturn(null);
        $invoice->shouldReceive('getNumber')->andReturn('FAC-2026-RECT-1');
        $invoice->shouldReceive('getInvoiceNumber')->andReturn('FAC-2026-RECT-1');
        $invoice->shouldReceive('getIssueDatetime')->andReturn(Carbon::parse('2026-06-05 09:00:00'));
        $invoice->shouldReceive('getType')->andReturn(InvoiceTypeEnum::RECTIFICATIVE);
        $invoice->shouldReceive('getInvoiceType')->andReturn(InvoiceTypeEnum::RECTIFICATIVE);
        $invoice->shouldReceive('getRectificationType')->andReturn($rectificationType);
        $invoice->shouldReceive('getRectifiedInvoices')->andReturn($rectifiedInvoices);
        $invoice->shouldReceive('getRectificationAmounts')->andReturn($rectificationAmounts);
        $invoice->shouldReceive('getDescription')->andReturn('Rectificación por diferencias');
        $invoice->shouldReceive('getRegimeType')->andReturn(RegimeTypeEnum::GENERAL);
        $invoice->shouldReceive('getTaxAmount')->andReturn(21.0);
        $invoice->shouldReceive('getTotalAmount')->andReturn(121.0);
        $recipient = Mockery::mock(RecipientContract::class);
        $recipient->shouldReceive('getNif')->andReturn('12345678Z');
        $recipient->shouldReceive('getName')->andReturn('Cliente Ejemplo');
        $recipient->shouldReceive('getIdType')->andReturn(null);
        $recipient->shouldReceive('getId')->andReturn(null);
        $recipient->shouldReceive('getCountry')->andReturn('ES');
        $invoice->shouldReceive('hasRecipient')->andReturn(true);
        $invoice->shouldReceive('getRecipient')->andReturn($recipient);
        $invoice->shouldReceive('getBreakdowns')->andReturn(collect([conformanceBreakdown()]));

        return $invoice;
    }

    it('emits TipoRectificativa and FacturasRectificadas and validates against the XSD', function () {
        $chain = new RegistryChain(
            hash: str_repeat('D', 64),
            generatedAt: Carbon::parse('2026-06-05T09:00:30+02:00'),
            previous: null,
        );

        $invoice = rectificativeInvoice('I', [
            ['number' => 'FAC-2026-001', 'issue_date' => Carbon::parse('2026-06-01')],
        ]);

        $xml = $this->builder->buildRegistrationXml($invoice, $chain);

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:TipoFactura>R1</sf:TipoFactura>')
            ->and($xml)->toContain('<sf:TipoRectificativa>I</sf:TipoRectificativa>')
            ->and($xml)->toContain('<sf:FacturasRectificadas>')
            ->and($xml)->toContain('<sf:IDFacturaRectificada>')
            ->and($xml)->toContain('<sf:IDEmisorFactura>89890001K</sf:IDEmisorFactura>')
            ->and($xml)->toContain('<sf:NumSerieFactura>FAC-2026-001</sf:NumSerieFactura>')
            ->and($xml)->toContain('<sf:FechaExpedicionFactura>01-06-2026</sf:FechaExpedicionFactura>');
    });

    it('emits ImporteRectificacion with BaseRectificada and CuotaRectificada for substitution (S) and validates against the XSD', function () {
        $chain = new RegistryChain(
            hash: str_repeat('E', 64),
            generatedAt: Carbon::parse('2026-06-05T10:00:00+02:00'),
            previous: null,
        );

        $invoice = rectificativeInvoice('S', [
            ['number' => 'FAC-2026-002', 'issue_date' => Carbon::parse('2026-06-02')],
        ], ['base' => 100.0, 'tax' => 21.0, 'surcharge' => null]);

        $xml = $this->builder->buildRegistrationXml($invoice, $chain);

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:TipoRectificativa>S</sf:TipoRectificativa>')
            ->and($xml)->toContain('<sf:ImporteRectificacion>')
            ->and($xml)->toContain('<sf:BaseRectificada>100.00</sf:BaseRectificada>')
            ->and($xml)->toContain('<sf:CuotaRectificada>21.00</sf:CuotaRectificada>')
            ->and($xml)->not->toContain('CuotaRecargoRectificado');

        // XSD sequence: ImporteRectificacion goes after FacturasRectificadas and
        // before DescripcionOperacion. Assert the order directly, not only presence.
        $posFacturas = strpos($xml, 'FacturasRectificadas');
        $posImporte = strpos($xml, 'ImporteRectificacion');
        $posDescripcion = strpos($xml, 'DescripcionOperacion');
        expect($posImporte)->toBeGreaterThan($posFacturas)
            ->and($posImporte)->toBeLessThan($posDescripcion);
    });

    it('emits CuotaRecargoRectificado when the substitution carries a surcharge', function () {
        $chain = new RegistryChain(
            hash: str_repeat('E', 64),
            generatedAt: Carbon::parse('2026-06-05T10:30:00+02:00'),
            previous: null,
        );

        $invoice = rectificativeInvoice('S', [
            ['number' => 'FAC-2026-002', 'issue_date' => Carbon::parse('2026-06-02')],
        ], ['base' => 100.0, 'tax' => 21.0, 'surcharge' => 5.2]);

        $xml = $this->builder->buildRegistrationXml($invoice, $chain);

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:CuotaRecargoRectificado>5.20</sf:CuotaRecargoRectificado>');
    });

    it('emits a zero BaseRectificada because 0.00 is a valid fiscal amount', function () {
        $chain = new RegistryChain(
            hash: str_repeat('E', 64),
            generatedAt: Carbon::parse('2026-06-05T10:45:00+02:00'),
            previous: null,
        );

        $invoice = rectificativeInvoice('S', [
            ['number' => 'FAC-2026-002', 'issue_date' => Carbon::parse('2026-06-02')],
        ], ['base' => 0.0, 'tax' => 0.0, 'surcharge' => null]);

        $xml = $this->builder->buildRegistrationXml($invoice, $chain);

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:BaseRectificada>0.00</sf:BaseRectificada>')
            ->and($xml)->toContain('<sf:CuotaRectificada>0.00</sf:CuotaRectificada>');
    });

    it('throws ValidationException for a substitution (S) without rectification amounts', function () {
        $chain = new RegistryChain(
            hash: str_repeat('E', 64),
            generatedAt: Carbon::parse('2026-06-05T11:00:00+02:00'),
            previous: null,
        );

        $invoice = rectificativeInvoice('S', [
            ['number' => 'FAC-2026-002', 'issue_date' => Carbon::parse('2026-06-02')],
        ], null);

        expect(fn () => $this->builder->buildRegistrationXml($invoice, $chain))
            ->toThrow(ValidationException::class);
    });

    it('rejects a legacy non-{S,I} TipoRectificativa (R1) fail-loud (AID-179)', function () {
        $chain = new RegistryChain(
            hash: str_repeat('F', 64),
            generatedAt: Carbon::parse('2026-06-05T11:00:00+02:00'),
            previous: null,
        );

        // Before AID-179 this collapsed silently to 'I'. The honest core now
        // rejects any TipoRectificativa outside {S,I} instead of guessing.
        $invoice = rectificativeInvoice('R1', []);

        expect(fn () => $this->builder->buildRegistrationXml($invoice, $chain))
            ->toThrow(ValidationException::class);
    });

    it('does not emit rectification elements for non-rectificative invoices', function () {
        $chain = new RegistryChain(
            hash: str_repeat('G', 64),
            generatedAt: Carbon::parse('2026-06-05T12:00:00+02:00'),
            previous: null,
        );

        $xml = $this->builder->buildRegistrationXml(conformanceInvoice(), $chain);

        expect($xml)->not->toContain('TipoRectificativa')
            ->and($xml)->not->toContain('FacturasRectificadas');
    });
});

describe('substitution invoice F3 (AID-166)', function () {
    function substituteInvoice(bool $withRecipient = true, array $substituted = [['number' => 'SIMP-0001', 'issue_date' => '2026-06-01']]): InvoiceContract
    {
        $recipient = null;
        if ($withRecipient) {
            $recipient = Mockery::mock(RecipientContract::class);
            $recipient->shouldReceive('getNif')->andReturn('12345678Z');
            $recipient->shouldReceive('getName')->andReturn('Cliente Ejemplo');
            $recipient->shouldReceive('getIdType')->andReturn(null);
            $recipient->shouldReceive('getId')->andReturn(null);
            $recipient->shouldReceive('getCountry')->andReturn('ES');
        }

        $mapped = array_map(
            fn (array $e): array => ['number' => $e['number'], 'issue_date' => Carbon::parse($e['issue_date'])],
            $substituted,
        );

        $invoice = Mockery::mock(InvoiceContract::class);
        $invoice->shouldReceive('getIssuerTaxId')->andReturn('89890001K');
        $invoice->shouldReceive('getSerie')->andReturn(null);
        $invoice->shouldReceive('getNumber')->andReturn('FAC-2026-F3-1');
        $invoice->shouldReceive('getInvoiceNumber')->andReturn('FAC-2026-F3-1');
        $invoice->shouldReceive('getIssueDatetime')->andReturn(Carbon::parse('2026-06-05 09:00:00'));
        $invoice->shouldReceive('getType')->andReturn(InvoiceTypeEnum::SUBSTITUTE);
        $invoice->shouldReceive('getInvoiceType')->andReturn(InvoiceTypeEnum::SUBSTITUTE);
        $invoice->shouldReceive('getSubstitutedInvoices')->andReturn($mapped);
        $invoice->shouldReceive('getDescription')->andReturn('Factura en sustitución de simplificadas');
        $invoice->shouldReceive('getRegimeType')->andReturn(RegimeTypeEnum::GENERAL);
        $invoice->shouldReceive('getTaxAmount')->andReturn(21.0);
        $invoice->shouldReceive('getTotalAmount')->andReturn(121.0);
        $invoice->shouldReceive('hasRecipient')->andReturn($withRecipient);
        $invoice->shouldReceive('getRecipient')->andReturn($recipient);
        $invoice->shouldReceive('getBreakdowns')->andReturn(collect([conformanceBreakdown()]));

        return $invoice;
    }

    it('emits FacturasSustituidas for F3 and validates against the XSD', function () {
        $chain = new RegistryChain(hash: str_repeat('H', 64), generatedAt: Carbon::parse('2026-06-05T09:00:30+02:00'), previous: null);

        $xml = $this->builder->buildRegistrationXml(substituteInvoice(), $chain);

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:TipoFactura>F3</sf:TipoFactura>')
            ->and($xml)->toContain('<sf:FacturasSustituidas>')
            ->and($xml)->toContain('<sf:IDFacturaSustituida>')
            ->and($xml)->toContain('<sf:NumSerieFactura>SIMP-0001</sf:NumSerieFactura>')
            ->and($xml)->toContain('<sf:FechaExpedicionFactura>01-06-2026</sf:FechaExpedicionFactura>');

        // XSD sequence: FacturasSustituidas before DescripcionOperacion.
        expect(strpos($xml, 'FacturasSustituidas'))->toBeLessThan(strpos($xml, 'DescripcionOperacion'));
    });

    it('throws ValidationException for an F3 without a recipient (AEAT rule 1189)', function () {
        $chain = new RegistryChain(hash: str_repeat('H', 64), generatedAt: Carbon::parse('2026-06-05T09:30:00+02:00'), previous: null);

        expect(fn () => $this->builder->buildRegistrationXml(substituteInvoice(withRecipient: false), $chain))
            ->toThrow(ValidationException::class);
    });

    it('throws ValidationException for an F3 without substituted invoices', function () {
        $chain = new RegistryChain(hash: str_repeat('H', 64), generatedAt: Carbon::parse('2026-06-05T09:45:00+02:00'), previous: null);

        expect(fn () => $this->builder->buildRegistrationXml(substituteInvoice(substituted: []), $chain))
            ->toThrow(ValidationException::class);
    });
});
