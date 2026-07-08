<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Tests\Fixtures;

use AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract;
use AichaDigital\LaraVerifactu\Enums\CalificacionOperacionEnum;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;

/**
 * Minimal InvoiceBreakdownContract implementation for AID-344's custom-mode
 * fixture. A plain value object — breakdown storage doesn't need its own
 * table, it's derived from the owning CustomInvoice's totals.
 */
final class CustomInvoiceBreakdown implements InvoiceBreakdownContract
{
    public function __construct(
        private readonly float $baseAmount,
        private readonly float $taxAmount,
        private readonly float $taxRate,
    ) {}

    public function getTaxType(): TaxTypeEnum
    {
        return TaxTypeEnum::IVA;
    }

    public function getTaxRate(): float
    {
        return $this->taxRate;
    }

    public function getBaseAmount(): float
    {
        return $this->baseAmount;
    }

    public function getTaxAmount(): float
    {
        return $this->taxAmount;
    }

    public function getSurchargeRate(): ?float
    {
        return null;
    }

    public function getSurchargeAmount(): ?float
    {
        return null;
    }

    public function isExempt(): bool
    {
        return false;
    }

    public function getExemptionReason(): ?string
    {
        return null;
    }

    public function getCalificacion(): ?CalificacionOperacionEnum
    {
        return null;
    }
}
