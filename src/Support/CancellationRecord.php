<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use DateTimeInterface;

/**
 * Identification of the invoice being cancelled, used to build the
 * RegistroAnulacion/IDFactura block (IDFacturaExpedidaBajaType).
 */
final readonly class CancellationRecord
{
    public function __construct(
        public string $issuerTaxId,
        public string $invoiceNumber,
        public DateTimeInterface $issueDate,
    ) {}
}
