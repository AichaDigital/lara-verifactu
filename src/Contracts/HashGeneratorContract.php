<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

interface HashGeneratorContract
{
    /**
     * Generate SHA-256 hash for an invoice according to AEAT specifications
     */
    public function generate(InvoiceContract $invoice): string;

    /**
     * Verify if a hash matches an invoice
     */
    public function verify(string $hash, InvoiceContract $invoice): bool;
}
