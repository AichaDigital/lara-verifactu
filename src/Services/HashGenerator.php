<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Exceptions\HashException;
use DateTimeInterface;

final class HashGenerator implements HashGeneratorContract
{
    /**
     * Generate the fingerprint for a registration record (RegistroAlta).
     *
     * Chain format per AEAT spec v0.1.2 (field order is mandatory):
     * IDEmisorFactura=...&NumSerieFactura=...&FechaExpedicionFactura=dd-mm-yyyy
     * &TipoFactura=...&CuotaTotal=...&ImporteTotal=...&Huella=...
     * &FechaHoraHusoGenRegistro=ISO8601-with-offset
     *
     * Every field name is always present; an absent value renders as "name=".
     * Output is SHA-256 in uppercase hexadecimal (64 chars).
     *
     * @throws HashException If hash cannot be generated
     */
    public function generate(
        InvoiceContract $invoice,
        ?string $previousHash = null,
        ?DateTimeInterface $generatedAt = null,
    ): string {
        try {
            $invoiceNumber = $invoice->getSerie()
                ? $invoice->getSerie() . $invoice->getNumber()
                : $invoice->getNumber();

            $chain = $this->buildChain([
                'IDEmisorFactura' => $invoice->getIssuerTaxId(),
                'NumSerieFactura' => $invoiceNumber,
                'FechaExpedicionFactura' => $invoice->getIssueDatetime()->format('d-m-Y'),
                'TipoFactura' => $invoice->getType()->value,
                'CuotaTotal' => $this->formatAmount($invoice->getTaxAmount()),
                'ImporteTotal' => $this->formatAmount($invoice->getTotalAmount()),
                'Huella' => $previousHash,
                'FechaHoraHusoGenRegistro' => $this->formatTimestamp($generatedAt ?? now()),
            ]);

            return strtoupper(hash('sha256', $chain));
        } catch (\Throwable $e) {
            throw HashException::cannotGenerateHash($e->getMessage());
        }
    }

    /**
     * Generate the fingerprint for a cancellation record (RegistroAnulacion).
     *
     * Chain format per AEAT spec v0.1.2:
     * IDEmisorFacturaAnulada=...&NumSerieFacturaAnulada=...
     * &FechaExpedicionFacturaAnulada=dd-mm-yyyy&Huella=...
     * &FechaHoraHusoGenRegistro=ISO8601-with-offset
     *
     * @throws HashException If hash cannot be generated
     */
    public function generateCancellation(
        string $issuerTaxId,
        string $invoiceNumber,
        DateTimeInterface $issueDate,
        ?string $previousHash = null,
        ?DateTimeInterface $generatedAt = null,
    ): string {
        try {
            $chain = $this->buildChain([
                'IDEmisorFacturaAnulada' => $issuerTaxId,
                'NumSerieFacturaAnulada' => $invoiceNumber,
                'FechaExpedicionFacturaAnulada' => $issueDate->format('d-m-Y'),
                'Huella' => $previousHash,
                'FechaHoraHusoGenRegistro' => $this->formatTimestamp($generatedAt ?? now()),
            ]);

            return strtoupper(hash('sha256', $chain));
        } catch (\Throwable $e) {
            throw HashException::cannotGenerateHash($e->getMessage());
        }
    }

    /**
     * Verify a stored hash by rebuilding the chain with persisted data.
     *
     * @throws HashException If verification fails
     */
    public function verify(
        string $hash,
        InvoiceContract $invoice,
        ?string $previousHash = null,
        ?DateTimeInterface $generatedAt = null,
    ): bool {
        try {
            $calculatedHash = $this->generate($invoice, $previousHash, $generatedAt);

            return hash_equals($calculatedHash, strtoupper($hash));
        } catch (\Throwable $e) {
            throw HashException::hashMismatch();
        }
    }

    /**
     * Build the input chain: every field is always present, values are
     * trimmed, and an absent value renders as "name=" (AEAT spec v0.1.2).
     *
     * @param  array<string, string|null>  $parts
     */
    private function buildChain(array $parts): string
    {
        $segments = [];

        foreach ($parts as $key => $value) {
            $segments[] = sprintf('%s=%s', $key, trim($value ?? ''));
        }

        return implode('&', $segments);
    }

    /**
     * Format amount as decimal with 2 positions and dot separator.
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Format FechaHoraHusoGenRegistro as ISO 8601 with timezone offset
     * (e.g. 2024-01-01T19:20:30+01:00).
     */
    private function formatTimestamp(DateTimeInterface $generatedAt): string
    {
        return $generatedAt->format('c');
    }
}
