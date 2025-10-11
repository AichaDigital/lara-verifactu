<?php

declare(strict_types=1);

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

it('returns certificate information after loading', function () {
    // Note: This test would need a real certificate file to work
    // For now, we test the interface exists
    expect($this->manager)->toBeInstanceOf(CertificateManager::class);
})->skip('Requires real certificate file for testing');

it('can sign content after loading certificate', function () {
    // Note: This test would need a real certificate file to work
    expect($this->manager)->toBeInstanceOf(CertificateManager::class);
})->skip('Requires real certificate file for testing');

it('can verify signed content', function () {
    // Note: This test would need a real certificate file to work
    expect($this->manager)->toBeInstanceOf(CertificateManager::class);
})->skip('Requires real certificate file for testing');

it('validates certificate dates', function () {
    // Note: This test would need a real certificate file to work
    expect($this->manager)->toBeInstanceOf(CertificateManager::class);
})->skip('Requires real certificate file for testing');

it('has all required methods', function () {
    expect(method_exists($this->manager, 'load'))->toBeTrue();
    expect(method_exists($this->manager, 'sign'))->toBeTrue();
    expect(method_exists($this->manager, 'verify'))->toBeTrue();
    expect(method_exists($this->manager, 'getCertificateInfo'))->toBeTrue();
});

it('implements CertificateManagerContract', function () {
    expect($this->manager)->toBeInstanceOf(\AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract::class);
});
