<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Enums;

/**
 * ClaveRegimen — AEAT list L8A (IVA) / L8B (IGIC).
 *
 * Backs the XSD type IdOperacionesTrascendenciaTributariaType (a single shared
 * type whose numeric values are 01-11, 14, 15, 17-21). The SAME numeric code can
 * mean different things depending on the Impuesto of the breakdown (notably 17,
 * 18, 19 diverge between IVA L8A and IGIC L8B). Case names follow L8A (IVA, the
 * primary list); the canonical identity is the code plus the breakdown's Impuesto.
 * Use descriptionFor() when the tax context is known; getDescription() returns the
 * L8A (IVA) label and must not be trusted for IGIC-specific codes (17/18/19).
 *
 * Source of truth: resources/xsd/SuministroInformacion.xsd +
 * docs/verifactu/_extracted/sheet06-6-listas.tsv.
 */
enum RegimeTypeEnum: string
{
    case GENERAL = '01';
    case EXPORT = '02';
    case SPECIAL_USED_GOODS = '03';
    case SPECIAL_GOLD_INVESTMENT = '04';
    case SPECIAL_TRAVEL_AGENCIES = '05';
    case SPECIAL_GROUP_ENTITIES = '06';
    case SPECIAL_CASH_BASIS = '07';
    case SUBJECT_IPSI_IGIC = '08';
    case TRAVEL_AGENCY_MEDIATION = '09';
    case THIRD_PARTY_COLLECTION = '10';
    case BUSINESS_PREMISES_LEASE = '11';
    case PENDING_ACCRUAL_PUBLIC_WORKS = '14';
    case PENDING_ACCRUAL_SUCCESSIVE_TRACT = '15';
    case OSS_IOSS = '17';
    case EQUIVALENCE_SURCHARGE = '18';
    case REAGYP = '19';
    case SIMPLIFIED_REGIME = '20';
    case UNDOCUMENTED_21 = '21';

    /**
     * L8A (IVA) description. Default label; for IGIC-specific semantics of codes
     * 17/18/19 use descriptionFor(TaxTypeEnum::IGIC).
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::GENERAL => 'Operación de régimen general',
            self::EXPORT => 'Exportación',
            self::SPECIAL_USED_GOODS => 'Régimen especial de bienes usados, objetos de arte, antigüedades y objetos de colección',
            self::SPECIAL_GOLD_INVESTMENT => 'Régimen especial del oro de inversión',
            self::SPECIAL_TRAVEL_AGENCIES => 'Régimen especial de las agencias de viajes',
            self::SPECIAL_GROUP_ENTITIES => 'Régimen especial grupo de entidades en IVA (Nivel Avanzado)',
            self::SPECIAL_CASH_BASIS => 'Régimen especial del criterio de caja',
            self::SUBJECT_IPSI_IGIC => 'Operaciones sujetas al IPSI / IGIC',
            self::TRAVEL_AGENCY_MEDIATION => 'Facturación de prestaciones de servicios de agencias de viaje mediadoras (D.A.4ª RD 1619/2012)',
            self::THIRD_PARTY_COLLECTION => 'Cobros por cuenta de terceros de honorarios profesionales o derechos de la propiedad industrial/autor',
            self::BUSINESS_PREMISES_LEASE => 'Operaciones de arrendamiento de local de negocio',
            self::PENDING_ACCRUAL_PUBLIC_WORKS => 'Factura con IVA pendiente de devengo en certificaciones de obra a Administración Pública',
            self::PENDING_ACCRUAL_SUCCESSIVE_TRACT => 'Factura con IVA pendiente de devengo en operaciones de tracto sucesivo',
            self::OSS_IOSS => 'Operación acogida a los regímenes del Capítulo XI del Título IX (OSS e IOSS)',
            self::EQUIVALENCE_SURCHARGE => 'Recargo de equivalencia',
            self::REAGYP => 'Operaciones incluidas en el Régimen Especial de Agricultura, Ganadería y Pesca (REAGYP)',
            self::SIMPLIFIED_REGIME => 'Régimen simplificado',
            self::UNDOCUMENTED_21 => 'Código 21 (válido en XSD, sin etiqueta oficial en L8A/L8B)',
        };
    }

    /**
     * Tax-context-aware description. IGIC (L8B) overrides the codes that diverge
     * from IVA (L8A); every other code shares the L8A label. IPSI/OTHER fall back
     * to the L8A label (no dedicated L8 list exists for them).
     */
    public function descriptionFor(TaxTypeEnum $tax): string
    {
        if ($tax === TaxTypeEnum::IGIC) {
            return match ($this) {
                self::SPECIAL_GROUP_ENTITIES => 'Régimen especial grupo de entidades en IGIC (Nivel Avanzado)',
                self::SUBJECT_IPSI_IGIC => 'Operaciones sujetas al IPSI / IVA',
                self::OSS_IOSS => 'Régimen especial de comerciante minorista',
                self::EQUIVALENCE_SURCHARGE => 'Régimen especial del pequeño empresario o profesional',
                self::REAGYP => 'Operaciones interiores exentas por aplicación del artículo 25 Ley 19/1994',
                default => $this->getDescription(),
            };
        }

        return $this->getDescription();
    }

    public function isSpecialRegime(): bool
    {
        return $this !== self::GENERAL;
    }

    /**
     * v1.0 honest-core allow-list (default-deny). Only the general regime (01)
     * is core; every special regime is post-1.0 (matrix L8A "01 core; rest post").
     *
     * This deliberately rejects the regimes that force a non-S1 CalificacionOperacion
     * — 04 (gold investment, reverse charge / rule 1147), 08 (→N2, rule 1252),
     * 10 (→N1, rule 1205), 20 (→N2, rule 1293) — since the core only emits S1.
     * Widen this set as specific domestic specials are validated for v1.0.
     */
    public function isSupportedInV10Core(): bool
    {
        return $this === self::GENERAL;
    }
}
