<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use AichaDigital\LaraVerifactu\Exceptions\CertificateException;
use AichaDigital\LaraVerifactu\Support\LoadedCertificate;

interface CertificateManagerContract
{
    /**
     * Load a PKCS#12 certificate from file, exposing the PEM credentials
     * and chain for later use (e.g. XAdES signing).
     *
     * @throws CertificateException When the file
     *                              does not exist, the password is wrong or the format is not valid PKCS#12
     */
    public function load(string $path, string $password): LoadedCertificate;

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
