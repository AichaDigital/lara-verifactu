<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu;

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Support\Collection;

final class Verifactu
{
    protected bool $fakeMode = false;

    public function __construct(
        protected HashGeneratorContract $hashGenerator,
        protected QrGeneratorContract $qrGenerator,
        protected XmlBuilderContract $xmlBuilder,
        protected AeatClientContract $aeatClient,
    ) {}

    /**
     * Register an invoice with Verifactu
     */
    public function register(InvoiceContract $invoice): AeatResponse
    {
        if ($this->fakeMode) {
            return AeatResponse::success();
        }

        // Implementation will be added in next phase
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Cancel a registry
     */
    public function cancel(string $registryId): AeatResponse
    {
        if ($this->fakeMode) {
            return AeatResponse::success();
        }

        // Implementation will be added in next phase
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Send batch of invoices
     */
    public function sendBatch(Collection $invoices): Collection
    {
        if ($this->fakeMode) {
            return collect([]);
        }

        // Implementation will be added in next phase
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Get status of an invoice
     */
    public function status(InvoiceContract $invoice): AeatResponse
    {
        if ($this->fakeMode) {
            return AeatResponse::success();
        }

        // Implementation will be added in next phase
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Get QR code for an invoice
     */
    public function qr(InvoiceContract $invoice): string
    {
        if ($this->fakeMode) {
            return 'fake-qr-code';
        }

        // Implementation will be added in next phase
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Validate blockchain chain
     */
    public function validateChain(InvoiceContract $invoice): bool
    {
        if ($this->fakeMode) {
            return true;
        }

        // Implementation will be added in next phase
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Enable fake mode for testing
     */
    public function fake(): void
    {
        $this->fakeMode = true;
    }

    /**
     * Assert invoice was registered
     */
    public function assertRegistered(InvoiceContract $invoice): void
    {
        if (! $this->fakeMode) {
            throw new \RuntimeException('Cannot assert when not in fake mode');
        }

        // Implementation will be added in next phase
    }

    /**
     * Assert invoice was not sent
     */
    public function assertNotSent(InvoiceContract $invoice): void
    {
        if (! $this->fakeMode) {
            throw new \RuntimeException('Cannot assert when not in fake mode');
        }

        // Implementation will be added in next phase
    }
}
