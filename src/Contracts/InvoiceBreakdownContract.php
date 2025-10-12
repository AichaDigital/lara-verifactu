<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;

/**
 * Invoice Breakdown Contract
 *
 * Defines the interface for invoice tax breakdown.
 * Models must implement this interface to work with Verifactu.
 */
interface InvoiceBreakdownContract
{
    /**
     * Get the tax type (IVA, IGIC, IPSI).
     */
    public function getTaxType(): TaxTypeEnum;

    /**
     * Get the tax rate (percentage).
     */
    public function getTaxRate(): float;

    /**
     * Get the taxable base amount.
     */
    public function getBaseAmount(): float;

    /**
     * Get the tax amount.
     */
    public function getTaxAmount(): float;

    /**
     * Get the surcharge rate (if applicable).
     */
    public function getSurchargeRate(): ?float;

    /**
     * Get the surcharge amount (if applicable).
     */
    public function getSurchargeAmount(): ?float;

    /**
     * Check if this breakdown is tax-exempt.
     */
    public function isExempt(): bool;

    /**
     * Get the exemption reason (if exempt).
     */
    public function getExemptionReason(): ?string;
}
