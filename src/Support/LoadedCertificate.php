<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use DateTimeImmutable;

/**
 * Typed result of loading a PKCS#12 certificate: PEM-encoded credentials
 * plus the metadata needed for signing and endpoint decisions. No business
 * logic — structure only.
 */
final readonly class LoadedCertificate
{
    /**
     * @param  array<int, string>  $chain  PEM-encoded extra certificates (issuer chain)
     */
    public function __construct(
        public string $privateKey,
        public string $certificate,
        public array $chain,
        public string $commonName,
        public DateTimeImmutable $validFrom,
        public DateTimeImmutable $validTo,
    ) {}

    public function isExpired(): bool
    {
        return $this->validTo < new DateTimeImmutable;
    }
}
