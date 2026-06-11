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
     * Get unique invoice ID
     */
    public function getId(): ?int;

    /**
     * Get issuer tax ID (NIF/CIF)
     */
    public function getIssuerTaxId(): string;

    /**
     * Get the invoice serie (optional).
     */
    public function getSerie(): ?string;

    /**
     * Get the invoice number.
     */
    public function getNumber(): string;

    /**
     * Get complete invoice number (serie + number)
     */
    public function getInvoiceNumber(): string;

    /**
     * Get the invoice issue datetime (combined date and time).
     *
     * This is the primary method for temporal ordering.
     */
    public function getIssueDatetime(): Carbon;

    /**
     * Get the invoice issue date.
     *
     * @deprecated Use getIssueDatetime() instead. Returns date portion only.
     */
    public function getIssueDate(): Carbon;

    /**
     * Get the invoice issue time.
     *
     * @deprecated Use getIssueDatetime() instead. Returns time portion only.
     */
    public function getIssueTime(): Carbon;

    /**
     * Get the invoice type (F1, F2, etc.).
     */
    public function getType(): InvoiceTypeEnum;

    /**
     * Alias for getType() for backwards compatibility
     */
    public function getInvoiceType(): InvoiceTypeEnum;

    /**
     * Check if the invoice is simplified.
     */
    public function isSimplified(): bool;

    /**
     * Get the rectification type (if applicable).
     */
    public function getRectificationType(): ?string;

    /**
     * Get the invoices rectified by this one (AEAT FacturasRectificadas).
     *
     * Each entry identifies one rectified invoice: its full serie+number and
     * its issue date. The issuer NIF is taken from getIssuerTaxId() per the
     * XSD ("El NIF se cogerá del NIF indicado en el bloque IDFactura").
     * Return an empty array when not a rectificative invoice or when the
     * rectified invoices are not identified.
     *
     * @return array<int, array{number: string, issue_date: Carbon}>
     */
    public function getRectifiedInvoices(): array;

    /**
     * Get previous invoice ID for rectifications
     */
    public function getPreviousInvoiceId(): ?string;

    /**
     * Get previous invoice hash for rectifications
     */
    public function getPreviousHash(): ?string;

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
