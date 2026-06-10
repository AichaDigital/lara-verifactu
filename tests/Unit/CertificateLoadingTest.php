<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Exceptions\CertificateException;
use AichaDigital\LaraVerifactu\Services\CertificateManager;
use AichaDigital\LaraVerifactu\Support\LoadedCertificate;

/**
 * Certificate loading against a real PKCS#12 fixture generated with the
 * native openssl extension in the test setup — no external fixture files
 * and no real certificates involved.
 */
function generateTestP12(string $path, string $password, string $commonName = 'Test Verifactu Cert'): void
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);

    openssl_pkcs12_export_to_file($cert, $path, $key, $password);
}

beforeEach(function () {
    $this->manager = new CertificateManager;
    $this->p12Path = sys_get_temp_dir() . '/verifactu_test_' . uniqid() . '.p12';
    generateTestP12($this->p12Path, 'secret-password');
});

afterEach(function () {
    if (file_exists($this->p12Path)) {
        unlink($this->p12Path);
    }
});

it('loads a PKCS#12 certificate and returns a typed LoadedCertificate', function () {
    $loaded = $this->manager->load($this->p12Path, 'secret-password');

    expect($loaded)->toBeInstanceOf(LoadedCertificate::class)
        ->and($loaded->certificate)->toContain('BEGIN CERTIFICATE')
        ->and($loaded->privateKey)->toContain('PRIVATE KEY')
        ->and($loaded->chain)->toBeArray()
        ->and($loaded->commonName)->toBe('Test Verifactu Cert')
        ->and($loaded->validTo)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($loaded->validTo->getTimestamp())->toBeGreaterThan(time());
});

it('throws a typed exception when the certificate file does not exist', function () {
    $this->manager->load('/nonexistent/path/cert.p12', 'whatever');
})->throws(CertificateException::class, 'not found');

it('throws a typed exception for a wrong password', function () {
    $this->manager->load($this->p12Path, 'wrong-password');
})->throws(CertificateException::class, 'password');

it('throws a typed exception for a file that is not PKCS#12', function () {
    $invalidPath = sys_get_temp_dir() . '/verifactu_invalid_' . uniqid() . '.p12';
    file_put_contents($invalidPath, 'this is plain text, not a certificate');

    try {
        $this->manager->load($invalidPath, 'whatever');
    } finally {
        unlink($invalidPath);
    }
})->throws(CertificateException::class, 'format');

it('signs XML content after loading and verifies the signature elements', function () {
    $this->manager->load($this->p12Path, 'secret-password');

    $signed = $this->manager->sign('<?xml version="1.0"?><root><data>test</data></root>');

    expect($signed)
        ->toContain('<ds:Signature')
        ->toContain('SignatureValue')
        ->toContain('X509Certificate');
});

it('exposes certificate information after loading', function () {
    $this->manager->load($this->p12Path, 'secret-password');

    $info = $this->manager->getCertificateInfo();

    expect($info)->toBeArray()
        ->and($info['subject']['CN'] ?? null)->toBe('Test Verifactu Cert');
});
