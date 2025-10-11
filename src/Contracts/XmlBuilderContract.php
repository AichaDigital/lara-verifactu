<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use Illuminate\Support\Collection;

interface XmlBuilderContract
{
    /**
     * Build XML for invoice registration
     */
    public function buildRegistrationXml(InvoiceContract $invoice): string;

    /**
     * Build XML for invoice cancellation
     */
    public function buildCancellationXml(string $registryId): string;

    /**
     * Build XML for batch submission
     */
    public function buildBatchXml(Collection $invoices): string;

    /**
     * Validate XML against XSD schema
     */
    public function validate(string $xml): bool;
}
