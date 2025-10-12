<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Exceptions\CertificateException;

final class CertificateManager implements CertificateManagerContract
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $certificate = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $privateKey = null;

    /**
     * Load certificate from file
     *
     * Supports PFX/P12 certificates commonly used with AEAT
     *
     * @throws CertificateException
     */
    public function load(string $path, string $password): void
    {
        if (! file_exists($path)) {
            throw CertificateException::fileNotFound($path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw CertificateException::fileNotFound($path);
        }

        $result = openssl_pkcs12_read($content, $certs, $password);

        if (! $result) {
            throw CertificateException::invalidPassword();
        }

        if (! isset($certs['cert']) || ! isset($certs['pkey'])) {
            throw CertificateException::invalidFormat();
        }

        // Parse certificate
        $certData = openssl_x509_parse($certs['cert']);
        if ($certData === false) {
            throw CertificateException::invalidFormat();
        }

        // Validate certificate dates
        $this->validateCertificateDates($certData);

        $this->certificate = $certData;

        // Extract private key
        $privateKey = openssl_pkey_get_private($certs['pkey']);
        if ($privateKey === false) {
            throw CertificateException::invalidPrivateKey();
        }

        $pkeyDetails = openssl_pkey_get_details($privateKey);
        if ($pkeyDetails === false) {
            throw CertificateException::invalidPrivateKey();
        }

        $this->privateKey = $pkeyDetails;
    }

    /**
     * Sign content with certificate
     *
     * @throws CertificateException
     */
    public function sign(string $content): string
    {
        if ($this->certificate === null || $this->privateKey === null) {
            throw CertificateException::make('Certificate not loaded');
        }

        $signature = '';
        $result = openssl_sign($content, $signature, $this->privateKey['key'] ?? '', OPENSSL_ALGO_SHA256);

        if (! $result) {
            throw CertificateException::make('Failed to sign content');
        }

        return base64_encode($signature);
    }

    /**
     * Verify signature
     *
     * @throws CertificateException
     */
    public function verify(string $content, string $signature): bool
    {
        if ($this->certificate === null) {
            throw CertificateException::make('Certificate not loaded');
        }

        $publicKey = $this->privateKey['key'] ?? null;
        if ($publicKey === null) {
            throw CertificateException::make('Public key not available');
        }

        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false) {
            return false;
        }

        $result = openssl_verify($content, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    /**
     * Get certificate information
     */
    public function getCertificateInfo(): array
    {
        if ($this->certificate === null) {
            throw CertificateException::make('Certificate not loaded');
        }

        return [
            'subject' => $this->certificate['subject'] ?? [],
            'issuer' => $this->certificate['issuer'] ?? [],
            'valid_from' => $this->certificate['validFrom_time_t'] ?? null,
            'valid_to' => $this->certificate['validTo_time_t'] ?? null,
            'serial_number' => $this->certificate['serialNumberHex'] ?? $this->certificate['serialNumber'] ?? null,
            'version' => $this->certificate['version'] ?? null,
        ];
    }

    /**
     * Validate certificate dates
     *
     * @param  array<string, mixed>  $certData
     *
     * @throws CertificateException
     */
    private function validateCertificateDates(array $certData): void
    {
        $now = time();

        if (isset($certData['validFrom_time_t']) && $now < $certData['validFrom_time_t']) {
            throw CertificateException::notYetValid();
        }

        if (isset($certData['validTo_time_t']) && $now > $certData['validTo_time_t']) {
            throw CertificateException::expired();
        }
    }
}
