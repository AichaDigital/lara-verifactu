<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Exceptions\HashException;

final class HashGenerator implements HashGeneratorContract
{
    /**
     * Generate SHA-256 hash for an invoice according to AEAT specifications
     *
     * The hash is calculated from a concatenation of specific invoice fields
     * separated by ampersands (&) and then hashed with SHA-256.
     *
     * According to AEAT specs, the fields are:
     * - IDEmisorFactura (Issuer Tax ID)
     * - NumSerieFactura (Invoice Number)
     * - FechaExpedicion (Issue Date in dd-mm-yyyy format)
     * - TipoFactura (Invoice Type)
     * - CuotaTotal (Total Tax Amount in decimal format)
     * - ImporteTotal (Total Amount in decimal format)
     * - Huella (Previous Hash, if exists)
     * - FechaHoraHusoGenRegistro (Registry generation timestamp)
     *
     * @throws HashException If hash cannot be generated
     */
    public function generate(InvoiceContract $invoice): string
    {
        try {
            $data = $this->prepareDataForHash($invoice);

            return hash('sha256', $data);
        } catch (\Throwable $e) {
            throw HashException::cannotGenerateHash($e->getMessage());
        }
    }

    /**
     * Verify if a hash matches an invoice
     *
     * @throws HashException If verification fails
     */
    public function verify(string $hash, InvoiceContract $invoice): bool
    {
        try {
            $calculatedHash = $this->generate($invoice);

            return hash_equals($hash, $calculatedHash);
        } catch (\Throwable $e) {
            throw HashException::hashMismatch();
        }
    }

    /**
     * Prepare invoice data for hash generation according to AEAT specs
     */
    private function prepareDataForHash(InvoiceContract $invoice): string
    {
        $parts = [
            'IDEmisorFactura' => $this->sanitize($invoice->getIssuerTaxId()),
            'NumSerieFactura' => $this->sanitize($invoice->getInvoiceNumber()),
            'FechaExpedicionFactura' => $this->formatDate($invoice->getIssueDate()),
            'TipoFactura' => $invoice->getInvoiceType()->value,
            'CuotaTotal' => $this->formatAmount($invoice->getTotalTaxAmount()),
            'ImporteTotal' => $this->formatAmount($invoice->getTotalAmount()),
        ];

        // Add previous hash if exists (for blockchain)
        if ($invoice->getPreviousHash()) {
            $parts['Huella'] = $invoice->getPreviousHash();
        }

        // Add timestamp (current time in ISO 8601 format with timezone)
        $parts['FechaHoraHusoGenRegistro'] = $this->getCurrentTimestamp();

        return $this->buildHashString($parts);
    }

    /**
     * Build hash string from parts according to AEAT format
     */
    private function buildHashString(array $parts): string
    {
        $segments = [];

        foreach ($parts as $key => $value) {
            $segments[] = sprintf('%s=%s', $key, $value);
        }

        return implode('&', $segments);
    }

    /**
     * Sanitize string value for hash generation
     */
    private function sanitize(string $value): string
    {
        // Remove special characters and trim
        return trim($value);
    }

    /**
     * Format date for hash generation (dd-mm-yyyy format)
     */
    private function formatDate(\Carbon\Carbon $date): string
    {
        return $date->format('d-m-Y');
    }

    /**
     * Format amount for hash generation
     * According to AEAT specs: decimal with 2 decimals, dot as separator
     */
    private function formatAmount(string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Get current timestamp in ISO 8601 format with timezone
     * Format: YYYY-MM-DDThh:mm:ssTZD (e.g., 2024-10-11T10:30:00+02:00)
     */
    private function getCurrentTimestamp(): string
    {
        return now()->format('c');
    }
}
