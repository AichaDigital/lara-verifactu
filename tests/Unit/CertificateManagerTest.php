<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Exceptions\CertificateException;
use AichaDigital\LaraVerifactu\Services\CertificateManager;

beforeEach(function () {
    $this->manager = new CertificateManager;
});

it('throws exception when certificate file not found', function () {
    $this->manager->load('/nonexistent/certificate.pfx', 'password');
})->throws(CertificateException::class, 'not found');

it('throws exception when certificate not loaded before signing', function () {
    $this->manager->sign('content to sign');
})->throws(CertificateException::class, 'not loaded');

it('throws exception when certificate not loaded before verifying', function () {
    $this->manager->verify('content', 'signature');
})->throws(CertificateException::class, 'not loaded');

it('throws exception when certificate not loaded before getting info', function () {
    $this->manager->getCertificateInfo();
})->throws(CertificateException::class, 'not loaded');

// Loading, signing and certificate info against a real generated PKCS#12
// fixture are covered in CertificateLoadingTest.

it('has all required methods', function () {
    expect(method_exists($this->manager, 'load'))->toBeTrue();
    expect(method_exists($this->manager, 'sign'))->toBeTrue();
    expect(method_exists($this->manager, 'verify'))->toBeTrue();
    expect(method_exists($this->manager, 'getCertificateInfo'))->toBeTrue();
});

it('implements CertificateManagerContract', function () {
    expect($this->manager)->toBeInstanceOf(CertificateManagerContract::class);
});
