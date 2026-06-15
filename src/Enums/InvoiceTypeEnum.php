<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Enums;

enum InvoiceTypeEnum: string
{
    case COMPLETE = 'F1'; // Complete invoice
    case SIMPLIFIED = 'F2'; // Simplified invoice
    case SUBSTITUTE = 'F3'; // Invoice issued to substitute simplified invoices
    case RECTIFICATIVE = 'R1'; // Rectificative invoice
    case RECTIFICATIVE_SIMPLIFIED = 'R2'; // Rectificative simplified invoice
    case RECTIFICATIVE_ART_80_4 = 'R3'; // Rectificative invoice (Art. 80.4) - uncollectable debts
    case RECTIFICATIVE_SUMMARY = 'R4'; // Rectificative summary invoice
    case RECTIFICATIVE_SUMMARY_SIMPLIFIED = 'R5'; // Rectificative summary simplified invoice

    public function getDescription(): string
    {
        return match ($this) {
            self::COMPLETE => 'Factura completa',
            self::SIMPLIFIED => 'Factura simplificada',
            self::SUBSTITUTE => 'Factura emitida en sustitución de facturas simplificadas',
            self::RECTIFICATIVE => 'Factura rectificativa',
            self::RECTIFICATIVE_SIMPLIFIED => 'Factura rectificativa simplificada',
            self::RECTIFICATIVE_ART_80_4 => 'Factura rectificativa (Art. 80.4)',
            self::RECTIFICATIVE_SUMMARY => 'Factura rectificativa resumen',
            self::RECTIFICATIVE_SUMMARY_SIMPLIFIED => 'Factura rectificativa resumen simplificada',
        };
    }

    public function isRectificative(): bool
    {
        return in_array($this, [
            self::RECTIFICATIVE,
            self::RECTIFICATIVE_SIMPLIFIED,
            self::RECTIFICATIVE_ART_80_4,
            self::RECTIFICATIVE_SUMMARY,
            self::RECTIFICATIVE_SUMMARY_SIMPLIFIED,
        ]);
    }

    public function isSimplified(): bool
    {
        return in_array($this, [
            self::SIMPLIFIED,
            self::RECTIFICATIVE_SIMPLIFIED,
            self::RECTIFICATIVE_SUMMARY_SIMPLIFIED,
        ]);
    }
}
