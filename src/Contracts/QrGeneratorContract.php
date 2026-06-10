<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

interface QrGeneratorContract
{
    /**
     * Generate QR code for an invoice in the configured format
     */
    public function generate(InvoiceContract $invoice): string;

    /**
     * Get the AEAT cotejo (validation) URL encoded in the QR.
     *
     * Carries exactly the four official parameters: nif, numserie, fecha, importe.
     */
    public function getValidationUrl(InvoiceContract $invoice): string;

    /**
     * Alias of getValidationUrl()
     */
    public function generateUrl(InvoiceContract $invoice): string;

    /**
     * Generate QR code as SVG
     */
    public function generateSvg(InvoiceContract $invoice): string;

    /**
     * Generate QR code as PNG (base64 data URI)
     */
    public function generatePng(InvoiceContract $invoice): string;
}
