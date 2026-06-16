<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\CalificacionOperacionEnum;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\OperacionExentaEnum;
use AichaDigital\LaraVerifactu\Enums\RectificativeTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;

/**
 * Anti-regression guardrail (AID-178): every enum that mirrors an official AEAT
 * code list must stay in sync with the XSD enumeration. This is exactly what would
 * have caught the RegimeTypeEnum 12/13 and TaxTypeEnum IRPF=04 defects in CI.
 */
const XSD_NS = 'http://www.w3.org/2001/XMLSchema';

/** @return list<string> enumeration values of an XSD simpleType, by name */
function xsdEnumValues(string $typeName): array
{
    $path = dirname(__DIR__, 3) . '/resources/xsd/SuministroInformacion.xsd';
    $doc = new DOMDocument;
    $doc->load($path);

    foreach ($doc->getElementsByTagNameNS(XSD_NS, 'simpleType') as $simpleType) {
        if ($simpleType->getAttribute('name') !== $typeName) {
            continue;
        }
        $values = [];
        foreach ($simpleType->getElementsByTagNameNS(XSD_NS, 'enumeration') as $enum) {
            $values[] = $enum->getAttribute('value');
        }

        return $values;
    }

    throw new RuntimeException("simpleType {$typeName} not found in the XSD");
}

/** @return list<string> backing values of a string-backed enum */
function enumValues(string $enumClass): array
{
    return array_map(static fn ($case) => $case->value, $enumClass::cases());
}

it('TaxTypeEnum matches XSD ImpuestoType exactly', function () {
    expect(enumValues(TaxTypeEnum::class))->toEqualCanonicalizing(xsdEnumValues('ImpuestoType'));
});

it('InvoiceTypeEnum matches XSD ClaveTipoFacturaType exactly', function () {
    expect(enumValues(InvoiceTypeEnum::class))->toEqualCanonicalizing(xsdEnumValues('ClaveTipoFacturaType'));
});

it('CalificacionOperacionEnum matches XSD CalificacionOperacionType exactly', function () {
    expect(enumValues(CalificacionOperacionEnum::class))->toEqualCanonicalizing(xsdEnumValues('CalificacionOperacionType'));
});

it('RectificativeTypeEnum matches XSD ClaveTipoRectificativaType exactly', function () {
    expect(enumValues(RectificativeTypeEnum::class))->toEqualCanonicalizing(xsdEnumValues('ClaveTipoRectificativaType'));
});

it('RegimeTypeEnum matches the XSD numeric regime codes exactly', function () {
    // The shared ClaveRegimen type may carry non-numeric entries; the enum models
    // only the two-digit regime codes (01-11, 14, 15, 17-21).
    $xsd = array_values(array_filter(
        xsdEnumValues('IdOperacionesTrascendenciaTributariaType'),
        static fn ($value) => preg_match('/^\d{2}$/', $value) === 1
    ));
    expect(enumValues(RegimeTypeEnum::class))->toEqualCanonicalizing($xsd);
});

it('OperacionExentaEnum is a subset of XSD OperacionExentaType (E1-E6 of E1-E8)', function () {
    $xsd = xsdEnumValues('OperacionExentaType');
    foreach (enumValues(OperacionExentaEnum::class) as $value) {
        expect($xsd)->toContain($value);
    }
    // Intentionally NOT total: E7/E8 are XSD-valid but undocumented in list L10.
    expect(enumValues(OperacionExentaEnum::class))->toHaveCount(6);
});
