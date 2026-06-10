<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR generator per AEAT QR spec v0.4.7 (art. 20-21 of the implementing order):
 * error correction level M, content limited to the cotejo URL (nif, numserie,
 * fecha, importe). When printed, the QR must measure 30x30 to 40x40 mm and be
 * preceded by the text "QR tributario:" — presentation is the consumer's
 * responsibility.
 */
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
     * Generate QR code for an invoice in the configured format.
     *
     * Per AEAT QR spec v0.4.7 (art. 21 of the implementing order) the QR
     * uses error correction level M and encodes the cotejo URL carrying
     * exactly: nif, numserie, fecha, importe.
     */
    public function generate(InvoiceContract $invoice): string
    {
        $url = $this->getValidationUrl($invoice);

        if ($this->format === 'svg') {
            return $this->generateSvgQr($url);
        }

        return $this->generatePngQr($url);
    }

    /**
     * Get the AEAT cotejo (validation) URL encoded in the QR.
     *
     * Official parameters and order: nif, numserie, fecha (dd-mm-yyyy),
     * importe. Values are URL-encoded (RFC 3986, UTF-8).
     */
    public function getValidationUrl(InvoiceContract $invoice): string
    {
        $params = [
            'nif' => trim($invoice->getIssuerTaxId()),
            'numserie' => trim($invoice->getInvoiceNumber()),
            'fecha' => $invoice->getIssueDatetime()->format('d-m-Y'),
            'importe' => $this->formatAmount($invoice->getTotalAmount()),
        ];

        return $this->validationUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function generateUrl(InvoiceContract $invoice): string
    {
        return $this->getValidationUrl($invoice);
    }

    public function generateSvg(InvoiceContract $invoice): string
    {
        return $this->generateSvgQr($this->getValidationUrl($invoice));
    }

    public function generatePng(InvoiceContract $invoice): string
    {
        return $this->generatePngQr($this->getValidationUrl($invoice));
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

        return $this->writeQr($renderer, $url);
    }

    /**
     * Generate PNG QR code (base64 data URI)
     */
    private function generatePngQr(string $url): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($this->size, self::QR_MARGIN),
            new ImagickImageBackEnd
        );

        return 'data:image/png;base64,' . base64_encode($this->writeQr($renderer, $url));
    }

    /**
     * Write QR content with error correction level M (AEAT requirement)
     */
    private function writeQr(ImageRenderer $renderer, string $url): string
    {
        $writer = new Writer($renderer);

        return $writer->writeString(
            $url,
            Encoder::DEFAULT_BYTE_MODE_ENCODING,
            ErrorCorrectionLevel::M(),
        );
    }

    /**
     * Format amount for the QR URL (2 decimals, dot separator)
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
