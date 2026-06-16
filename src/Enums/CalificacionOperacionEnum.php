<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Enums;

/**
 * CalificacionOperacion — AEAT list L9 / XSD CalificacionOperacionType.
 *
 * Operation classification for a breakdown detail. In the v1.0 honest core only
 * S1 is supported; S2/N1/N2 are post-1.0 (fail-loud rejection handled by AID-179).
 */
enum CalificacionOperacionEnum: string
{
    case S1 = 'S1'; // Sujeta y no exenta, sin inversión del sujeto pasivo
    case S2 = 'S2'; // Sujeta y no exenta, con inversión del sujeto pasivo
    case N1 = 'N1'; // No sujeta (artículo 7, 14, otros)
    case N2 = 'N2'; // No sujeta por reglas de localización

    public function getDescription(): string
    {
        return match ($this) {
            self::S1 => 'Operación sujeta y no exenta, sin inversión del sujeto pasivo',
            self::S2 => 'Operación sujeta y no exenta, con inversión del sujeto pasivo',
            self::N1 => 'Operación no sujeta (artículo 7, 14, otros)',
            self::N2 => 'Operación no sujeta por reglas de localización',
        };
    }

    /** Subject to tax (S1 or S2). */
    public function isSubject(): bool
    {
        return $this === self::S1 || $this === self::S2;
    }

    /** Reverse charge (inversión del sujeto pasivo). */
    public function isReverseCharge(): bool
    {
        return $this === self::S2;
    }

    /** Not subject to tax (N1 or N2). */
    public function isNotSubject(): bool
    {
        return $this === self::N1 || $this === self::N2;
    }
}
