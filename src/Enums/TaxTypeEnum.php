<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Enums;

/**
 * Impuesto — AEAT list L1 / XSD ImpuestoType.
 *
 * Only the four values the XSD admits: 01 (IVA), 02 (IPSI), 03 (IGIC), 05 (Otros).
 * IRPF ('04') was removed — it is a direct tax, not in L1 nor ImpuestoType, and
 * emitting it produces XML AEAT rejects (error 1218).
 */
enum TaxTypeEnum: string
{
    case IVA = '01'; // Impuesto sobre el Valor Añadido
    case IPSI = '02'; // IPSI de Ceuta y Melilla
    case IGIC = '03'; // Impuesto General Indirecto Canario
    case OTHER = '05'; // Otros

    public function getDescription(): string
    {
        return match ($this) {
            self::IVA => 'Impuesto sobre el Valor Añadido (IVA)',
            self::IPSI => 'Impuesto sobre la Producción, los Servicios y la Importación (IPSI) de Ceuta y Melilla',
            self::IGIC => 'Impuesto General Indirecto Canario (IGIC)',
            self::OTHER => 'Otros',
        };
    }

    public function isIndirectTax(): bool
    {
        return in_array($this, [self::IVA, self::IPSI, self::IGIC], true);
    }
}
