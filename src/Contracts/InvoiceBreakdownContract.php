<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use AichaDigital\LaraVerifactu\Enums\CalificacionOperacionEnum;
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

    /**
     * Get the operation classification (AEAT list L9 / CalificacionOperacion).
     *
     * Null means S1 (subject, not exempt, no reverse charge) — the default when
     * a breakdown does not express a calificación. The v1.0 core supports S1 and
     * N2 (no sujeta por reglas de localización); S2/N1 are rejected fail-loud.
     */
    public function getCalificacion(): ?CalificacionOperacionEnum;
}
