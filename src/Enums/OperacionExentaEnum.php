<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Enums;

/**
 * OperacionExenta — AEAT list L10.
 *
 * Models the six documented exemption causes E1-E6. The XSD OperacionExentaType
 * additionally admits E7/E8, but list L10 documents only E1-E6, so those two are
 * intentionally not modeled here (discrepancy tracked for AID-183 docs). An
 * exemption with no explicit, valid cause must be rejected (fail-loud, AID-179),
 * never defaulted.
 */
enum OperacionExentaEnum: string
{
    case E1 = 'E1'; // Exenta por el artículo 20
    case E2 = 'E2'; // Exenta por el artículo 21
    case E3 = 'E3'; // Exenta por el artículo 22
    case E4 = 'E4'; // Exenta por los artículos 23 y 24
    case E5 = 'E5'; // Exenta por el artículo 25
    case E6 = 'E6'; // Exenta por otros

    public function getDescription(): string
    {
        return match ($this) {
            self::E1 => 'Exenta por el artículo 20',
            self::E2 => 'Exenta por el artículo 21',
            self::E3 => 'Exenta por el artículo 22',
            self::E4 => 'Exenta por los artículos 23 y 24',
            self::E5 => 'Exenta por el artículo 25',
            self::E6 => 'Exenta por otros',
        };
    }
}
