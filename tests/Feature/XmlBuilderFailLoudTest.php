<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RecipientContract;
use AichaDigital\LaraVerifactu\Enums\CalificacionOperacionEnum;
use AichaDigital\LaraVerifactu\Enums\IdTypeEnum;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;
use AichaDigital\LaraVerifactu\Exceptions\ValidationException;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\RegistryChain;
use Carbon\Carbon;

/**
 * Fail-loud guards (AID-179, block A): XmlBuilder must reject out-of-core data
 * with an explicit ValidationException instead of silently mapping it to a
 * default that produces XML the AEAT would reject.
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
 * @param  array<string, mixed>  $overrides
 */
function flBreakdown(array $overrides = []): InvoiceBreakdownContract
{
    $defaults = [
        'getTaxType' => TaxTypeEnum::IVA,
        'getTaxRate' => 21.0,
        'getBaseAmount' => 100.0,
        'getTaxAmount' => 21.0,
        'getSurchargeRate' => null,
        'getSurchargeAmount' => null,
        'isExempt' => false,
        'getExemptionReason' => null,
        'getCalificacion' => null,
    ];

    $breakdown = Mockery::mock(InvoiceBreakdownContract::class);

    foreach (array_merge($defaults, $overrides) as $method => $value) {
        $breakdown->shouldReceive($method)->andReturn($value);
    }

    return $breakdown;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function flInvoice(array $overrides = [], ?InvoiceBreakdownContract $breakdown = null, ?RecipientContract $recipient = null): InvoiceContract
{
    $type = $overrides['getType'] ?? InvoiceTypeEnum::COMPLETE;

    // Rule 1189: F1/F3/R1 require a recipient. Default one in so fixtures that
    // are not specifically about a missing recipient stay valid under guard #8.
    // F2/R5 (rule 1190) and any test that passes its own recipient — or forces
    // hasRecipient via overrides — are left untouched.
    if ($recipient === null
        && $type instanceof InvoiceTypeEnum
        && $type->requiresRecipientInV10Core()
        && ! array_key_exists('hasRecipient', $overrides)) {
        $recipient = flRecipient('12345678Z');
    }

    $defaults = [
        'getIssuerTaxId' => '89890001K',
        'getSerie' => null,
        'getNumber' => 'FL-2026-001',
        'getInvoiceNumber' => 'FL-2026-001',
        'getIssueDatetime' => Carbon::parse('2026-06-01 10:00:00'),
        'getType' => InvoiceTypeEnum::COMPLETE,
        'getInvoiceType' => InvoiceTypeEnum::COMPLETE,
        'getDescription' => 'Servicios de prueba',
        'getRegimeType' => RegimeTypeEnum::GENERAL,
        'getTaxAmount' => 21.0,
        'getTotalAmount' => 121.0,
        'hasRecipient' => $recipient instanceof RecipientContract,
        'getRecipient' => $recipient,
    ];

    $invoice = Mockery::mock(InvoiceContract::class);

    foreach (array_merge($defaults, $overrides) as $method => $value) {
        $invoice->shouldReceive($method)->andReturn($value);
    }

    $invoice->shouldReceive('getBreakdowns')->andReturn(collect([$breakdown ?? flBreakdown()]));

    return $invoice;
}

function flChain(): RegistryChain
{
    return new RegistryChain(
        hash: str_repeat('A', 64),
        generatedAt: Carbon::parse('2026-06-01T10:00:30+02:00'),
        previous: null,
    );
}

function flRecipient(?string $nif): RecipientContract
{
    $recipient = Mockery::mock(RecipientContract::class);
    $recipient->shouldReceive('getNif')->andReturn($nif);
    $recipient->shouldReceive('getName')->andReturn('Cliente Ejemplo');
    $recipient->shouldReceive('getIdType')->andReturn(null);
    $recipient->shouldReceive('getId')->andReturn(null);
    $recipient->shouldReceive('getCountry')->andReturn('ES');

    return $recipient;
}

describe('Impuesto guard (#3)', function () {
    it('rejects a breakdown whose Impuesto is outside the core {01,02,03}', function () {
        $invoice = flInvoice(breakdown: flBreakdown(['getTaxType' => TaxTypeEnum::OTHER]));

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class);
    });

    it('accepts IGIC (03) as core', function () {
        $invoice = flInvoice(breakdown: flBreakdown(['getTaxType' => TaxTypeEnum::IGIC]));

        $xml = $this->builder->buildRegistrationXml($invoice, flChain());

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:Impuesto>03</sf:Impuesto>');
    });
});

describe('ClaveRegimen guard (#4)', function () {
    it('rejects regime 04 (gold investment, forces non-S1 — rule 1147)', function () {
        $invoice = flInvoice(['getRegimeType' => RegimeTypeEnum::SPECIAL_GOLD_INVESTMENT]);

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class);
    });

    it('rejects regime 08 (IPSI/IGIC → N2, rule 1252)', function () {
        $invoice = flInvoice(['getRegimeType' => RegimeTypeEnum::SUBJECT_IPSI_IGIC]);

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class);
    });

    it('rejects regime 10 (third-party collection → N1, rule 1205)', function () {
        $invoice = flInvoice(['getRegimeType' => RegimeTypeEnum::THIRD_PARTY_COLLECTION]);

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class);
    });

    it('rejects regime 20 (simplified → N2, rule 1293)', function () {
        $invoice = flInvoice(['getRegimeType' => RegimeTypeEnum::SIMPLIFIED_REGIME]);

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class);
    });

    it('accepts the general regime 01 as core', function () {
        $invoice = flInvoice(['getRegimeType' => RegimeTypeEnum::GENERAL]);

        $xml = $this->builder->buildRegistrationXml($invoice, flChain());

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:ClaveRegimen>01</sf:ClaveRegimen>');
    });
});

describe('CalificacionOperacion guard (#1)', function () {
    it('emits S1 from the enum for a subject, non-exempt breakdown', function () {
        $xml = $this->builder->buildRegistrationXml(flInvoice(), flChain());

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:CalificacionOperacion>S1</sf:CalificacionOperacion>');
    });
});

describe('OperacionExenta guard (#2)', function () {
    it('rejects an exempt breakdown with no exemption reason', function () {
        $invoice = flInvoice(breakdown: flBreakdown([
            'isExempt' => true,
            'getExemptionReason' => null,
        ]));

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class);
    });

    it('rejects an exempt breakdown with an invalid cause (E9)', function () {
        $invoice = flInvoice(breakdown: flBreakdown([
            'isExempt' => true,
            'getExemptionReason' => 'E9',
        ]));

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class);
    });

    it('rejects E5 (requires IDOtro per rule 1289, post-1.0)', function () {
        $invoice = flInvoice(breakdown: flBreakdown([
            'isExempt' => true,
            'getExemptionReason' => 'E5',
        ]));

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class);
    });

    it('accepts an explicit core cause (E1) and validates against the XSD', function () {
        $invoice = flInvoice(['getTaxAmount' => 0.0, 'getTotalAmount' => 100.0], breakdown: flBreakdown([
            'isExempt' => true,
            'getExemptionReason' => 'E1',
        ]));

        $xml = $this->builder->buildRegistrationXml($invoice, flChain());

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:OperacionExenta>E1</sf:OperacionExenta>');
    });
});

describe('IDType / IDOtro guard (#5)', function () {
    it('rejects a recipient without a Spanish NIF (IDOtro branch is post-1.0)', function () {
        $invoice = flInvoice(recipient: flRecipient(null));

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class);
    });

    it('accepts a recipient with a Spanish NIF', function () {
        $invoice = flInvoice(recipient: flRecipient('12345678Z'));

        $xml = $this->builder->buildRegistrationXml($invoice, flChain());

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:NIF>12345678Z</sf:NIF>');
    });
});

describe('TipoRectificativa guard (#6)', function () {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function flRectificative(?string $rectificationType): InvoiceContract
    {
        return flInvoice([
            'getType' => InvoiceTypeEnum::RECTIFICATIVE,
            'getInvoiceType' => InvoiceTypeEnum::RECTIFICATIVE,
            'getRectificationType' => $rectificationType,
            'getRectifiedInvoices' => [],
            'getRectificationAmounts' => null,
        ]);
    }

    it('rejects a TipoRectificativa outside {S,I} (legacy R1)', function () {
        expect(fn () => $this->builder->buildRegistrationXml(flRectificative('R1'), flChain()))
            ->toThrow(ValidationException::class);
    });

    it('rejects a null TipoRectificativa on a rectificative invoice', function () {
        expect(fn () => $this->builder->buildRegistrationXml(flRectificative(null), flChain()))
            ->toThrow(ValidationException::class);
    });

    it('accepts I (por diferencias)', function () {
        $xml = $this->builder->buildRegistrationXml(flRectificative('I'), flChain());

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:TipoRectificativa>I</sf:TipoRectificativa>');
    });
});

describe('TipoFactura guard (#7)', function () {
    /**
     * Post-1.0 rectificative fixture (R2/R3/R4). The rectificative fields carry
     * valid 'I' values so that, WITHOUT guard #7, the invoice would silently
     * build XSD-valid XML carrying an out-of-core TipoFactura — the exact defect
     * AID-185 closes. The XSD itself permits R2/R3/R4, so validate() cannot catch
     * this; only the domain guard can. With the guard, it fails before emission.
     */
    function flPost10Rectificative(InvoiceTypeEnum $type): InvoiceContract
    {
        return flInvoice([
            'getType' => $type,
            'getInvoiceType' => $type,
            'getRectificationType' => 'I',
            'getRectifiedInvoices' => [],
            'getRectificationAmounts' => null,
        ]);
    }

    it('rejects R2 (Art. 80.3, post-1.0) fail-loud', function () {
        expect(fn () => $this->builder->buildRegistrationXml(
            flPost10Rectificative(InvoiceTypeEnum::RECTIFICATIVE_ART_80_3),
            flChain()
        ))->toThrow(ValidationException::class, 'TipoFactura R2');
    });

    it('rejects R3 (Art. 80.4, post-1.0) fail-loud', function () {
        expect(fn () => $this->builder->buildRegistrationXml(
            flPost10Rectificative(InvoiceTypeEnum::RECTIFICATIVE_ART_80_4),
            flChain()
        ))->toThrow(ValidationException::class, 'TipoFactura R3');
    });

    it('rejects R4 (Resto, post-1.0) fail-loud', function () {
        expect(fn () => $this->builder->buildRegistrationXml(
            flPost10Rectificative(InvoiceTypeEnum::RECTIFICATIVE_OTHER),
            flChain()
        ))->toThrow(ValidationException::class, 'TipoFactura R4');
    });

    it('accepts F1 (factura completa) as core', function () {
        $xml = $this->builder->buildRegistrationXml(flInvoice(), flChain());

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:TipoFactura>F1</sf:TipoFactura>');
    });

    it('accepts F2 (factura simplificada) as core', function () {
        $invoice = flInvoice([
            'getType' => InvoiceTypeEnum::SIMPLIFIED,
            'getInvoiceType' => InvoiceTypeEnum::SIMPLIFIED,
        ]);

        $xml = $this->builder->buildRegistrationXml($invoice, flChain());

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:TipoFactura>F2</sf:TipoFactura>');
    });

    it('accepts F3 (sustitución de simplificadas) as core', function () {
        $invoice = flInvoice([
            'getType' => InvoiceTypeEnum::SUBSTITUTE,
            'getInvoiceType' => InvoiceTypeEnum::SUBSTITUTE,
            'getSubstitutedInvoices' => [
                ['number' => 'S-2026-009', 'issue_date' => Carbon::parse('2026-05-01')],
            ],
        ], recipient: flRecipient('12345678Z'));

        $xml = $this->builder->buildRegistrationXml($invoice, flChain());

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:TipoFactura>F3</sf:TipoFactura>');
    });

    it('accepts R1 (rectificativa por diferencias) as core', function () {
        $invoice = flInvoice([
            'getType' => InvoiceTypeEnum::RECTIFICATIVE,
            'getInvoiceType' => InvoiceTypeEnum::RECTIFICATIVE,
            'getRectificationType' => 'I',
            'getRectifiedInvoices' => [],
            'getRectificationAmounts' => null,
        ]);

        $xml = $this->builder->buildRegistrationXml($invoice, flChain());

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:TipoFactura>R1</sf:TipoFactura>');
    });

    it('accepts R5 (rectificativa en facturas simplificadas, no Destinatarios per rule 1190) as core', function () {
        $invoice = flInvoice([
            'getType' => InvoiceTypeEnum::RECTIFICATIVE_SIMPLIFIED_INVOICES,
            'getInvoiceType' => InvoiceTypeEnum::RECTIFICATIVE_SIMPLIFIED_INVOICES,
            'getRectificationType' => 'I',
            'getRectifiedInvoices' => [],
            'getRectificationAmounts' => null,
        ]);

        $xml = $this->builder->buildRegistrationXml($invoice, flChain());

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:TipoFactura>R5</sf:TipoFactura>');
    });
});

describe('Destinatarios × TipoFactura guard (#8)', function () {
    // AEAT rule 1189: F1/F3/R1 require a Destinatarios block. Rule 1190: F2/R5
    // (simplified) forbid it. R2/R3/R4 are already rejected by guard #7.

    it('rejects F1 without a recipient (rule 1189)', function () {
        $invoice = flInvoice(['hasRecipient' => false, 'getRecipient' => null]);

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class, 'rule 1189');
    });

    it('rejects R1 without a recipient (rule 1189)', function () {
        $invoice = flInvoice([
            'getType' => InvoiceTypeEnum::RECTIFICATIVE,
            'getInvoiceType' => InvoiceTypeEnum::RECTIFICATIVE,
            'getRectificationType' => 'I',
            'getRectifiedInvoices' => [],
            'getRectificationAmounts' => null,
            'hasRecipient' => false,
            'getRecipient' => null,
        ]);

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class, 'rule 1189');
    });

    it('accepts F1 with a NIF recipient', function () {
        $invoice = flInvoice(recipient: flRecipient('12345678Z'));

        $xml = $this->builder->buildRegistrationXml($invoice, flChain());

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:NIF>12345678Z</sf:NIF>');
    });

    it('rejects F2 carrying a recipient (rule 1190)', function () {
        $invoice = flInvoice([
            'getType' => InvoiceTypeEnum::SIMPLIFIED,
            'getInvoiceType' => InvoiceTypeEnum::SIMPLIFIED,
        ], recipient: flRecipient('12345678Z'));

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class, 'rule 1190');
    });

    it('rejects R5 carrying a recipient (rule 1190)', function () {
        $invoice = flInvoice([
            'getType' => InvoiceTypeEnum::RECTIFICATIVE_SIMPLIFIED_INVOICES,
            'getInvoiceType' => InvoiceTypeEnum::RECTIFICATIVE_SIMPLIFIED_INVOICES,
            'getRectificationType' => 'I',
            'getRectifiedInvoices' => [],
            'getRectificationAmounts' => null,
        ], recipient: flRecipient('12345678Z'));

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class, 'rule 1190');
    });

    it('accepts F2 without a recipient', function () {
        $invoice = flInvoice([
            'getType' => InvoiceTypeEnum::SIMPLIFIED,
            'getInvoiceType' => InvoiceTypeEnum::SIMPLIFIED,
        ]);

        $xml = $this->builder->buildRegistrationXml($invoice, flChain());

        expect($this->builder->validate($xml))->toBeTrue();
    });

    it('rejects a contract reporting hasRecipient with a null getRecipient (1189 needs an emittable recipient)', function () {
        $invoice = flInvoice(['hasRecipient' => true, 'getRecipient' => null]);

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class, 'rule 1189');
    });
});

describe('CalificacionOperacion guard (#1) — AID-223', function () {
    it('rejects S2 (con inversión del sujeto pasivo) as post-1.0', function () {
        $invoice = flInvoice(breakdown: flBreakdown(['getCalificacion' => CalificacionOperacionEnum::S2]));

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class, 'S1, N2');
    });

    it('rejects N1 (no sujeta artículo 7/14) as post-1.0', function () {
        $invoice = flInvoice(breakdown: flBreakdown(['getCalificacion' => CalificacionOperacionEnum::N1]));

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class, 'S1, N2');
    });

    it('accepts N2 (no sujeta por reglas de localización) for an intra-EU service', function () {
        $invoice = flInvoice(
            ['getTaxAmount' => 0.0, 'getTotalAmount' => 100.0],
            breakdown: flBreakdown([
                'getCalificacion' => CalificacionOperacionEnum::N2,
                'getTaxRate' => 0.0,
                'getTaxAmount' => 0.0,
            ]),
        );

        $xml = $this->builder->buildRegistrationXml($invoice, flChain());

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:CalificacionOperacion>N2</sf:CalificacionOperacion>')
            ->and($xml)->not->toContain('<sf:CuotaRepercutida>');
    });

    it('rejects an N2 line that carries a TipoImpositivo/CuotaRepercutida (rule 1237)', function () {
        $invoice = flInvoice(breakdown: flBreakdown([
            'getCalificacion' => CalificacionOperacionEnum::N2,
            'getTaxRate' => 21.0,
            'getTaxAmount' => 21.0,
        ]));

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class, 'rule 1237');
    });
});

function flForeignRecipient(?IdTypeEnum $idType, ?string $id, ?string $country): RecipientContract
{
    $recipient = Mockery::mock(RecipientContract::class);
    $recipient->shouldReceive('getNif')->andReturn(null);
    $recipient->shouldReceive('getName')->andReturn('Foreign Co');
    $recipient->shouldReceive('getIdType')->andReturn($idType);
    $recipient->shouldReceive('getId')->andReturn($id);
    $recipient->shouldReceive('getCountry')->andReturn($country);

    return $recipient;
}

describe('IDOtro recipient guard — AID-223', function () {
    it('emits IDOtro for a foreign NIF-IVA (02) recipient without CodigoPais', function () {
        $invoice = flInvoice(recipient: flForeignRecipient(IdTypeEnum::NIF, 'DE129273398', 'DE'));

        $xml = $this->builder->buildRegistrationXml($invoice, flChain());

        expect($this->builder->validate($xml))->toBeTrue()
            ->and($xml)->toContain('<sf:IDType>02</sf:IDType>')
            ->and($xml)->toContain('<sf:ID>DE129273398</sf:ID>')
            ->and($xml)->not->toContain('<sf:CodigoPais>');
    });

    it('requires a non-ES CodigoPais for IDType 04', function () {
        $invoice = flInvoice(recipient: flForeignRecipient(IdTypeEnum::OFFICIAL_DOC, 'X-123', null));

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class, 'non-ES CodigoPais');
    });

    it('rejects CodigoPais=ES for IDType 04', function () {
        $invoice = flInvoice(recipient: flForeignRecipient(IdTypeEnum::OFFICIAL_DOC, 'X-123', 'ES'));

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class, 'non-ES CodigoPais');
    });

    it('rejects IDType 07 (No Censado) as a domestic case', function () {
        $invoice = flInvoice(recipient: flForeignRecipient(IdTypeEnum::NOT_REGISTERED, '12345678Z', 'ES'));

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class, 'No Censado');
    });

    it('rejects a non-NIF recipient with no IDType/ID', function () {
        $invoice = flInvoice(recipient: flForeignRecipient(null, null, 'DE'));

        expect(fn () => $this->builder->buildRegistrationXml($invoice, flChain()))
            ->toThrow(ValidationException::class, 'IDOtro block');
    });
});
