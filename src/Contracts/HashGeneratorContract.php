<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use DateTimeInterface;

interface HashGeneratorContract
{
    /**
     * Generate the SHA-256 fingerprint (huella) for a registration record
     * according to AEAT specifications (uppercase hexadecimal, 64 chars).
     *
     * The generation timestamp (FechaHoraHusoGenRegistro) is part of the
     * hashed chain and MUST be persisted alongside the hash so the value
     * can be reproduced and verified later. When omitted, the current time
     * is used — callers are then responsible for persisting it.
     */
    public function generate(
        InvoiceContract $invoice,
        ?string $previousHash = null,
        ?DateTimeInterface $generatedAt = null,
    ): string;

    /**
     * Generate the SHA-256 fingerprint (huella) for a cancellation record
     * according to AEAT specifications.
     */
    public function generateCancellation(
        string $issuerTaxId,
        string $invoiceNumber,
        DateTimeInterface $issueDate,
        ?string $previousHash = null,
        ?DateTimeInterface $generatedAt = null,
    ): string;

    /**
     * Verify a stored hash by rebuilding the chain with the persisted
     * previous hash and generation timestamp.
     */
    public function verify(
        string $hash,
        InvoiceContract $invoice,
        ?string $previousHash = null,
        ?DateTimeInterface $generatedAt = null,
    ): bool;
}
