<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use Carbon\Carbon;

/**
 * Registry Contract
 *
 * Defines the interface for registry entries within the Verifactu blockchain.
 */
interface RegistryContract
{
    /**
     * Get the registry unique number.
     */
    public function getRegistryNumber(): string;

    /**
     * Get the registry date.
     */
    public function getRegistryDate(): Carbon;

    /**
     * Get the associated invoice.
     */
    public function getInvoice(): InvoiceContract;

    /**
     * Get the registry hash (SHA-256).
     */
    public function getHash(): string;

    /**
     * Get the previous registry hash (blockchain).
     */
    public function getPreviousHash(): ?string;

    /**
     * Get the QR code URL.
     */
    public function getQrUrl(): ?string;

    /**
     * Get the QR code as SVG.
     */
    public function getQrSvg(): ?string;

    /**
     * Get the QR code as PNG (binary).
     */
    public function getQrPng(): ?string;

    /**
     * Get the XML representation.
     */
    public function getXml(): ?string;

    /**
     * Get the signed XML (with electronic signature).
     */
    public function getSignedXml(): ?string;

    /**
     * Get the registry status.
     */
    public function getStatus(): RegistryStatusEnum;

    /**
     * Get the submission date to AEAT.
     */
    public function getSubmittedAt(): ?Carbon;

    /**
     * Get the AEAT CSV (confirmation code).
     */
    public function getAeatCsv(): ?string;

    /**
     * Get the AEAT response.
     *
     * @return array<string, mixed>|null
     */
    public function getAeatResponse(): ?array;

    /**
     * Get the AEAT error message (if any).
     */
    public function getAeatError(): ?string;

    /**
     * Get the number of submission attempts.
     */
    public function getSubmissionAttempts(): int;

    /**
     * Check if the registry was successfully submitted.
     */
    public function isSubmitted(): bool;

    /**
     * Check if the registry is pending submission.
     */
    public function isPending(): bool;

    /**
     * Check if the registry has errors.
     */
    public function hasErrors(): bool;
}
