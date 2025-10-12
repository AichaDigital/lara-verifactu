<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Models;

use AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invoice Breakdown Model
 *
 * Native implementation of InvoiceBreakdownContract for Verifactu system.
 * Represents the tax breakdown of an invoice.
 */
class InvoiceBreakdown extends Model implements InvoiceBreakdownContract
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'verifactu_invoice_breakdowns';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'invoice_id',
        'tax_type',
        'tax_rate',
        'base_amount',
        'tax_amount',
        'surcharge_rate',
        'surcharge_amount',
        'exempt',
        'exemption_reason',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tax_rate' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'surcharge_rate' => 'decimal:2',
        'surcharge_amount' => 'decimal:2',
        'exempt' => 'boolean',
        'metadata' => 'array',
        'tax_type' => TaxTypeEnum::class,
    ];

    /**
     * Get the invoice that owns this breakdown.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    // ========================================
    // InvoiceBreakdownContract Implementation
    // ========================================

    /**
     * Get the tax type.
     */
    public function getTaxType(): TaxTypeEnum
    {
        return $this->tax_type;
    }

    /**
     * Get the tax rate (percentage).
     */
    public function getTaxRate(): float
    {
        return (float) $this->tax_rate;
    }

    /**
     * Get the taxable base amount.
     */
    public function getBaseAmount(): float
    {
        return (float) $this->base_amount;
    }

    /**
     * Get the tax amount.
     */
    public function getTaxAmount(): float
    {
        return (float) $this->tax_amount;
    }

    /**
     * Get the surcharge rate (if applicable).
     */
    public function getSurchargeRate(): ?float
    {
        return $this->surcharge_rate ? (float) $this->surcharge_rate : null;
    }

    /**
     * Get the surcharge amount (if applicable).
     */
    public function getSurchargeAmount(): ?float
    {
        return $this->surcharge_amount ? (float) $this->surcharge_amount : null;
    }

    /**
     * Check if this breakdown is tax-exempt.
     */
    public function isExempt(): bool
    {
        return $this->exempt;
    }

    /**
     * Get the exemption reason (if exempt).
     */
    public function getExemptionReason(): ?string
    {
        return $this->exemption_reason;
    }
}
