<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Enums;

/**
 * TipoRectificativa — AEAT list L3 / XSD ClaveTipoRectificativaType.
 *
 * Rectification mode: S (por sustitución) or I (por diferencias). Today the value
 * travels as a raw string and XmlBuilder collapses anything other than 'S' to 'I'
 * (silent default); wiring this enum into the flow with fail-loud validation is
 * AID-179.
 */
enum RectificativeTypeEnum: string
{
    case BY_SUBSTITUTION = 'S'; // Por sustitución
    case BY_DIFFERENCES = 'I'; // Por diferencias

    public function getDescription(): string
    {
        return match ($this) {
            self::BY_SUBSTITUTION => 'Por sustitución',
            self::BY_DIFFERENCES => 'Por diferencias',
        };
    }
}
