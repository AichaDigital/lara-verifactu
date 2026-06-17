<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use DateTimeInterface;

/**
 * Identification of the invoice being cancelled, used to build the
 * RegistroAnulacion/IDFactura block (IDFacturaExpedidaBajaType), plus the optional
 * anulación circumstances (SinRegistroPrevio / RechazoPrevio / GeneradoPor +
 * Generador). The circumstance fields are appended last so positional callers stay
 * backward-compatible.
 */
final readonly class CancellationRecord
{
    public function __construct(
        public string $issuerTaxId,
        public string $invoiceNumber,
        public DateTimeInterface $issueDate,
        /** SinRegistroPrevio=S: cancelling an invoice never sent to the AEAT. */
        public bool $withoutPriorRegistry = false,
        /** RechazoPrevio=S: cancellation after a previous rejection. */
        public bool $rejectedPreviously = false,
        /** GeneradoPor (L16: E/D/T). When set, the Generador group is required (rule 1224). */
        public ?string $generatedBy = null,
        /** Generador/NombreRazon. */
        public ?string $generatorName = null,
        /** Generador/NIF (Spanish NIF; IDOtro is post-1.0). */
        public ?string $generatorNif = null,
    ) {}
}
