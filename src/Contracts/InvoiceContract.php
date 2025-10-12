<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\OperationTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Invoice Contract
 *
 * Defines the interface for invoices within the Verifactu system.
 * Both native models and custom user models must implement this interface.
 */
interface InvoiceContract
{
    /**
     * Get the invoice serie (optional).
     */
    public function getSerie(): ?string;

    /**
     * Get the invoice number.
     */
    public function getNumber(): string;

    /**
     * Get the invoice issue date.
     */
    public function getIssueDate(): Carbon;

    /**
     * Get the invoice issue time.
     */
    public function getIssueTime(): Carbon;

    /**
     * Get the invoice type (F1, F2, etc.).
     */
    public function getType(): InvoiceTypeEnum;

    /**
     * Check if the invoice is simplified.
     */
    public function isSimplified(): bool;

    /**
     * Get the rectification type (if applicable).
     */
    public function getRectificationType(): ?string;

    /**
     * Get the invoice base amount.
     */
    public function getBaseAmount(): float;

    /**
     * Get the invoice tax amount.
     */
    public function getTaxAmount(): float;

    /**
     * Get the invoice total amount.
     */
    public function getTotalAmount(): float;

    /**
     * Get the invoice currency (default: EUR).
     */
    public function getCurrency(): string;

    /**
     * Get the recipient (if exists).
     */
    public function getRecipient(): ?RecipientContract;

    /**
     * Check if the invoice has recipient information.
     */
    public function hasRecipient(): bool;

    /**
     * Get the tax breakdowns.
     *
     * @return Collection<int, InvoiceBreakdownContract>
     */
    public function getBreakdowns(): Collection;

    /**
     * Get the tax regime type.
     */
    public function getRegimeType(): RegimeTypeEnum;

    /**
     * Get the operation key.
     */
    public function getOperationKey(): OperationTypeEnum;

    /**
     * Get the invoice description (optional).
     */
    public function getDescription(): ?string;

    /**
     * Get additional metadata as array.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array;
}
