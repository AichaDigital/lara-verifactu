<?php

declare(strict_types=1);

/**
 * verifactu:test-connection against a generated PKCS#12 fixture.
 * The --cert-info flag stops before the network stage, so the command
 * is fully testable offline.
 */
function generateConnectionTestP12(string $path, string $password): void
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    $csr = openssl_csr_new(['commonName' => 'CONN TEST CERT (R: B00000000)'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);

    openssl_pkcs12_export_to_file($cert, $path, $key, $password);
}

beforeEach(function () {
    $this->p12Path = sys_get_temp_dir() . '/verifactu_conn_' . uniqid() . '.p12';
    generateConnectionTestP12($this->p12Path, 'conn-secret');

    config()->set('verifactu.aeat.environment', 'sandbox');
    config()->set('verifactu.certificate.path', $this->p12Path);
    config()->set('verifactu.certificate.password', 'conn-secret');
    config()->set('verifactu.certificate.type', 'representante');
});

afterEach(function () {
    if (file_exists($this->p12Path)) {
        unlink($this->p12Path);
    }
});

it('reports certificate details without errors using --cert-info', function () {
    $this->artisan('verifactu:test-connection', ['--cert-info' => true])
        ->expectsOutputToContain('Certificate loaded successfully')
        ->expectsOutputToContain('CONN TEST CERT (R: B00000000)')
        ->assertSuccessful();
});

it('fails cleanly when the certificate password is wrong', function () {
    config()->set('verifactu.certificate.password', 'wrong');

    $this->artisan('verifactu:test-connection', ['--cert-info' => true])
        ->assertFailed();
});
