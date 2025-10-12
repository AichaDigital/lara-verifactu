<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Exceptions\CertificateException;
use DOMDocument;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

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
     * Raw certificate content (PEM format)
     */
    private ?string $certificatePem = null;

    /**
     * Raw private key content (PEM format)
     */
    private ?string $privateKeyPem = null;

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
        $this->certificatePem = $certs['cert'];
        $this->privateKeyPem = $certs['pkey'];

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
     * Sign XML content with XAdES-EPES signature
     *
     * @throws CertificateException
     */
    public function sign(string $content): string
    {
        if ($this->certificatePem === null || $this->privateKeyPem === null) {
            throw CertificateException::make('Certificate not loaded');
        }

        try {
            // Load XML document
            $doc = new DOMDocument;
            $doc->loadXML($content);

            // Create signature object
            $signature = new XMLSecurityDSig;
            $signature->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

            // Add reference to sign the entire document
            $signature->addReference(
                $doc,
                XMLSecurityDSig::SHA256,
                ['http://www.w3.org/2000/09/xmldsig#enveloped-signature'],
                ['force_uri' => true]
            );

            // Create security key
            $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
            $key->loadKey($this->privateKeyPem, false);

            // Sign the document
            $signature->sign($key);

            // Add certificate info
            $signature->add509Cert($this->certificatePem);

            // Append signature to document root
            if ($doc->documentElement === null) {
                throw CertificateException::make('Invalid XML document structure');
            }

            $signature->appendSignature($doc->documentElement);

            // Return signed XML
            $signedXml = $doc->saveXML();

            if ($signedXml === false) {
                throw CertificateException::make('Failed to generate signed XML');
            }

            return $signedXml;
        } catch (\Exception $e) {
            throw CertificateException::make('Failed to sign XML: ' . $e->getMessage());
        }
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
