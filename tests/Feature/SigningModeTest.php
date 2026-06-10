<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;

/**
 * In VERI*FACTU mode registry records are NOT signed: the chained
 * fingerprint (huella) replaces the signature, and the XSD only allows
 * ds:Signature inside the record for the non-Verifactu modality. Signing
 * is therefore opt-in via verifactu.signing.enabled (default false).
 */
function generateSigningTestP12(string $path, string $password): void
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'Signing Test'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
    openssl_pkcs12_export_to_file($cert, $path, $key, $password);
}

beforeEach(function () {
    config()->set('verifactu.company.tax_id', '89890001K');
    config()->set('verifactu.company.name', 'Empresa Ejemplo SL');

    $qrGenerator = Mockery::mock(QrGeneratorContract::class);
    $qrGenerator->shouldReceive('generateUrl')->andReturn('https://example.test/qr');
    $qrGenerator->shouldReceive('generateSvg')->andReturn('<svg/>');
    $qrGenerator->shouldReceive('generatePng')->andReturn('png-binary');
    $this->app->instance(QrGeneratorContract::class, $qrGenerator);

    $this->app->instance(AeatClientContract::class, Mockery::mock(AeatClientContract::class));
});

it('does not sign registry records by default (VERI*FACTU mode)', function () {
    // A configured VALID certificate must NOT trigger signing when disabled
    $p12 = sys_get_temp_dir() . '/verifactu_signing_' . uniqid() . '.p12';
    generateSigningTestP12($p12, 'sign-secret');
    config()->set('verifactu.certificate.path', $p12);
    config()->set('verifactu.certificate.password', 'sign-secret');

    try {
        $registry = app(InvoiceRegistrar::class)->register(Invoice::factory()->create(), submitToAeat: false);
    } finally {
        unlink($p12);
    }

    expect($registry->getSignedXml())->toBeNull();
});

it('signs registry records when signing is explicitly enabled', function () {
    $p12 = sys_get_temp_dir() . '/verifactu_signing_' . uniqid() . '.p12';
    generateSigningTestP12($p12, 'sign-secret');
    config()->set('verifactu.signing.enabled', true);
    config()->set('verifactu.certificate.path', $p12);
    config()->set('verifactu.certificate.password', 'sign-secret');

    try {
        $registry = app(InvoiceRegistrar::class)->register(Invoice::factory()->create(), submitToAeat: false);
    } finally {
        unlink($p12);
    }

    expect($registry->getSignedXml())->toContain('Signature');
});
