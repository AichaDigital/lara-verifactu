<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use DateTimeInterface;

/**
 * Chain data of the registry being built: its own fingerprint, the exact
 * generation timestamp hashed into it (FechaHoraHusoGenRegistro) and the
 * previous registry reference (null on the first record of the chain).
 */
final readonly class RegistryChain
{
    public function __construct(
        public string $hash,
        public DateTimeInterface $generatedAt,
        public ?PreviousRegistry $previous = null,
    ) {}
}
