<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final class QrGenerator implements QrGeneratorContract
{
    private const QR_SIZE = 300;

    private const QR_MARGIN = 0;

    public function __construct(
        private readonly string $validationUrl,
        private readonly string $format = 'png',
        private readonly int $size = self::QR_SIZE,
    ) {}

    /**
     * Generate QR code for an invoice
     *
     * According to AEAT specifications, the QR must contain:
     * - Validation URL
     * - Issuer Tax ID
     * - Invoice Number
     * - Issue Date
     * - Invoice Type
     * - Hash
     *
     * Format: URL?nif=XXX&num=YYY&fecha=ZZZ&tipo=TTT&hash=HHH
     */
    public function generate(InvoiceContract $invoice, string $hash): string
    {
        $url = $this->getValidationUrl($invoice, $hash);

        return $this->generateQrCode($url);
    }

    /**
     * Get validation URL for QR code
     */
    public function getValidationUrl(InvoiceContract $invoice, string $hash): string
    {
        $params = [
            'nif' => $invoice->getIssuerTaxId(),
            'num' => $invoice->getInvoiceNumber(),
            'fecha' => $invoice->getIssueDate()->format('d-m-Y'),
            'tipo' => $invoice->getInvoiceType()->value,
            'hash' => $hash,
        ];

        return $this->validationUrl . '?' . http_build_query($params);
    }

    /**
     * {@inheritDoc}
     */
    public function generateUrl(InvoiceContract $invoice, string $hash): string
    {
        return $this->getValidationUrl($invoice, $hash);
    }

    /**
     * {@inheritDoc}
     */
    public function generateSvg(InvoiceContract $invoice, string $hash): string
    {
        return $this->generate($invoice, $hash);
    }

    /**
     * {@inheritDoc}
     */
    public function generatePng(InvoiceContract $invoice, string $hash): string
    {
        $url = $this->getValidationUrl($invoice, $hash);

        $renderer = new ImageRenderer(
            new RendererStyle(self::QR_SIZE, self::QR_MARGIN),
            new \BaconQrCode\Renderer\Image\ImagickImageBackEnd
        );

        $writer = new Writer($renderer);

        return $writer->writeString($url);
    }

    /**
     * Generate QR code image from URL
     */
    private function generateQrCode(string $url): string
    {
        if ($this->format === 'svg') {
            return $this->generateSvgQr($url);
        }

        return $this->generatePngQr($url);
    }

    /**
     * Generate SVG QR code
     */
    private function generateSvgQr(string $url): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($this->size, self::QR_MARGIN),
            new SvgImageBackEnd
        );

        $writer = new Writer($renderer);

        return $writer->writeString($url);
    }

    /**
     * Generate PNG QR code (base64 encoded)
     */
    private function generatePngQr(string $url): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($this->size, self::QR_MARGIN),
            new \BaconQrCode\Renderer\Image\ImagickImageBackEnd
        );

        $writer = new Writer($renderer);
        $qrCode = $writer->writeString($url);

        return 'data:image/png;base64,' . base64_encode($qrCode);
    }
}
