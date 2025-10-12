<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

interface QrGeneratorContract
{
    /**
     * Generate QR code for an invoice
     */
    public function generate(InvoiceContract $invoice, string $hash): string;

    /**
     * Get validation URL for QR code
     */
    public function getValidationUrl(InvoiceContract $invoice, string $hash): string;

    /**
     * Generate QR code URL
     */
    public function generateUrl(InvoiceContract $invoice, string $hash): string;

    /**
     * Generate QR code as SVG
     */
    public function generateSvg(InvoiceContract $invoice, string $hash): string;

    /**
     * Generate QR code as PNG
     */
    public function generatePng(InvoiceContract $invoice, string $hash): string;
}
