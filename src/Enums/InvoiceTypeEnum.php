<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Enums;

/**
 * TipoFactura — AEAT list L2 / XSD ClaveTipoFacturaType.
 *
 * Case names align with the official L2 semantics. Note (BREAKING vs <=0.11):
 * the rectificative cases were renamed to match the law articles, fixing prior
 * mislabels (R2 was wrongly "simplified", R4 "summary", R5 "summary simplified").
 */
enum InvoiceTypeEnum: string
{
    case COMPLETE = 'F1'; // Factura (art. 6, 7.2 y 7.3 RD 1619/2012)
    case SIMPLIFIED = 'F2'; // Factura simplificada / sin identificación del destinatario
    case SUBSTITUTE = 'F3'; // Factura en sustitución de simplificadas facturadas y declaradas
    case RECTIFICATIVE = 'R1'; // Rectificativa (error fundado en derecho, Art. 80 Uno, Dos y Seis LIVA)
    case RECTIFICATIVE_ART_80_3 = 'R2'; // Rectificativa (Art. 80.3)
    case RECTIFICATIVE_ART_80_4 = 'R3'; // Rectificativa (Art. 80.4)
    case RECTIFICATIVE_OTHER = 'R4'; // Rectificativa (Resto)
    case RECTIFICATIVE_SIMPLIFIED_INVOICES = 'R5'; // Rectificativa en facturas simplificadas

    public function getDescription(): string
    {
        return match ($this) {
            self::COMPLETE => 'Factura (art. 6, 7.2 y 7.3 del RD 1619/2012)',
            self::SIMPLIFIED => 'Factura simplificada y facturas sin identificación del destinatario art. 6.1.d) RD 1619/2012',
            self::SUBSTITUTE => 'Factura emitida en sustitución de facturas simplificadas facturadas y declaradas',
            self::RECTIFICATIVE => 'Factura rectificativa (error fundado en derecho y art. 80 Uno, Dos y Seis LIVA)',
            self::RECTIFICATIVE_ART_80_3 => 'Factura rectificativa (art. 80.3)',
            self::RECTIFICATIVE_ART_80_4 => 'Factura rectificativa (art. 80.4)',
            self::RECTIFICATIVE_OTHER => 'Factura rectificativa (Resto)',
            self::RECTIFICATIVE_SIMPLIFIED_INVOICES => 'Factura rectificativa en facturas simplificadas',
        };
    }

    public function isRectificative(): bool
    {
        return in_array($this, [
            self::RECTIFICATIVE,
            self::RECTIFICATIVE_ART_80_3,
            self::RECTIFICATIVE_ART_80_4,
            self::RECTIFICATIVE_OTHER,
            self::RECTIFICATIVE_SIMPLIFIED_INVOICES,
        ], true);
    }

    /**
     * Simplified invoice types per AEAT rules 1185/1190: only F2 (factura
     * simplificada) and R5 (rectificativa en facturas simplificadas). R2 is NOT
     * simplified (it is Art. 80.3) — the prior implementation wrongly included it.
     */
    public function isSimplified(): bool
    {
        return in_array($this, [
            self::SIMPLIFIED,
            self::RECTIFICATIVE_SIMPLIFIED_INVOICES,
        ], true);
    }
}
