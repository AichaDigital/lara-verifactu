<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use DateTimeInterface;

/**
 * Reference to the previous registry in the fingerprint chain, used to
 * build the Encadenamiento/RegistroAnterior block (AEAT SuministroInformacion.xsd).
 */
final readonly class PreviousRegistry
{
    public function __construct(
        public string $issuerTaxId,
        public string $invoiceNumber,
        public DateTimeInterface $issueDate,
        public string $hash,
    ) {}
}
