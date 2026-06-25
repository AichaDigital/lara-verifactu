<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum;

/**
 * Registry-level circumstances of a RegistroAlta: whether it is a subsanación
 * (Subsanacion=S) and, if so, the RechazoPrevio value. These describe the
 * registry, not the invoice (spec §4), so they are passed alongside the
 * invoice rather than read from InvoiceContract. A default instance means a
 * normal alta — the builder emits neither element.
 */
final readonly class RegistrationCircumstances
{
    public function __construct(
        public bool $subsanacion = false,
        public ?RechazoPrevioEnum $rechazoPrevio = null,
    ) {}
}
