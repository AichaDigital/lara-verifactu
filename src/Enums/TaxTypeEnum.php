<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Enums;

enum TaxTypeEnum: string
{
    case IVA = '01'; // Value Added Tax
    case IPSI = '02'; // Tax on Production, Services and Imports
    case IGIC = '03'; // Canary Islands General Indirect Tax
    case IRPF = '04'; // Personal Income Tax
    case OTHER = '05'; // Other taxes

    public function getDescription(): string
    {
        return match ($this) {
            self::IVA => 'IVA - Impuesto sobre el Valor Añadido',
            self::IPSI => 'IPSI - Impuesto sobre la Producción, los Servicios y la Importación',
            self::IGIC => 'IGIC - Impuesto General Indirecto Canario',
            self::IRPF => 'IRPF - Impuesto sobre la Renta de las Personas Físicas',
            self::OTHER => 'Otros impuestos',
        };
    }

    public function isIndirectTax(): bool
    {
        return in_array($this, [self::IVA, self::IPSI, self::IGIC]);
    }

    public function isDirectTax(): bool
    {
        return $this === self::IRPF;
    }
}
