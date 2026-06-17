<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Enums;

/**
 * GeneradoPor — AEAT list L16 / XSD GeneradoPorType.
 *
 * Identifies who generated a RegistroAnulacion: the issuer obliged to expedite the
 * cancelled invoice (E), its recipient (D), or a third party (T). When present, the
 * Generador group must identify that party (AEAT rules 1224/1227/1228).
 */
enum GeneradoPorEnum: string
{
    case ISSUER = 'E'; // Expedidor (obligado a expedir la factura anulada)
    case RECIPIENT = 'D'; // Destinatario
    case THIRD_PARTY = 'T'; // Tercero

    public function getDescription(): string
    {
        return match ($this) {
            self::ISSUER => 'Expedidor',
            self::RECIPIENT => 'Destinatario',
            self::THIRD_PARTY => 'Tercero',
        };
    }
}
