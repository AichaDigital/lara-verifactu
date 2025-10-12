<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

interface CertificateManagerContract
{
    /**
     * Load certificate from file
     */
    public function load(string $path, string $password): void;

    /**
     * Sign content with certificate
     */
    public function sign(string $content): string;

    /**
     * Verify signature
     */
    public function verify(string $content, string $signature): bool;

    /**
     * Get certificate information
     *
     * @return array<string, mixed>
     */
    public function getCertificateInfo(): array;
}
