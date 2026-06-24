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

            return $this->generateRegistrationFromParts(
                issuerTaxId: $invoice->getIssuerTaxId(),
                numSerieFactura: $invoiceNumber,
                fechaExpedicion: $invoice->getIssueDatetime()->format('d-m-Y'),
                tipoFactura: $invoice->getType()->value,
                cuotaTotal: $this->formatAmount($invoice->getTaxAmount()),
                importeTotal: $this->formatAmount($invoice->getTotalAmount()),
                previousHash: $previousHash,
                fechaHoraHusoGen: $this->formatTimestamp($generatedAt ?? now()),
            );
        } catch (\Throwable $e) {
            throw HashException::cannotGenerateHash($e->getMessage());
        }
    }

    /**
     * Registration fingerprint from already-formatted primitive parts. Inputs
     * must already be AEAT-formatted: fechaExpedicion as d-m-Y, cuota/importe
     * as 2-decimal dot strings, fechaHoraHusoGen as ISO-8601 with offset.
     * Calls buildChain() so the AEAT formula lives in exactly one place.
     */
    public function generateRegistrationFromParts(
        string $issuerTaxId,
        string $numSerieFactura,
        string $fechaExpedicion,
        string $tipoFactura,
        string $cuotaTotal,
        string $importeTotal,
        ?string $previousHash,
        string $fechaHoraHusoGen,
    ): string {
        $chain = $this->buildChain([
            'IDEmisorFactura' => $issuerTaxId,
            'NumSerieFactura' => $numSerieFactura,
            'FechaExpedicionFactura' => $fechaExpedicion,
            'TipoFactura' => $tipoFactura,
            'CuotaTotal' => $cuotaTotal,
            'ImporteTotal' => $importeTotal,
            'Huella' => $previousHash,
            'FechaHoraHusoGenRegistro' => $fechaHoraHusoGen,
        ]);

        return strtoupper(hash('sha256', $chain));
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
            return $this->generateCancellationFromParts(
                issuerTaxId: $issuerTaxId,
                numSerieFactura: $invoiceNumber,
                fechaExpedicion: $issueDate->format('d-m-Y'),
                previousHash: $previousHash,
                fechaHoraHusoGen: $this->formatTimestamp($generatedAt ?? now()),
            );
        } catch (\Throwable $e) {
            throw HashException::cannotGenerateHash($e->getMessage());
        }
    }

    /**
     * Cancellation fingerprint from already-formatted primitive parts.
     */
    public function generateCancellationFromParts(
        string $issuerTaxId,
        string $numSerieFactura,
        string $fechaExpedicion,
        ?string $previousHash,
        string $fechaHoraHusoGen,
    ): string {
        $chain = $this->buildChain([
            'IDEmisorFacturaAnulada' => $issuerTaxId,
            'NumSerieFacturaAnulada' => $numSerieFactura,
            'FechaExpedicionFacturaAnulada' => $fechaExpedicion,
            'Huella' => $previousHash,
            'FechaHoraHusoGenRegistro' => $fechaHoraHusoGen,
        ]);

        return strtoupper(hash('sha256', $chain));
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
