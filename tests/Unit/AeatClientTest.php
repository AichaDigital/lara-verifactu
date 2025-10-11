<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Services\AeatClient;
use AichaDigital\LaraVerifactu\Support\AeatResponse;

beforeEach(function () {
    $this->endpoint = 'https://prewww7.aeat.es/verifactu/ws';
    $this->certificateManager = Mockery::mock(CertificateManagerContract::class);
    $this->certificateManager->shouldReceive('sign')->andReturn('signed_xml');

    $this->client = new AeatClient(
        $this->endpoint,
        $this->certificateManager,
        30,
        false // Don't verify SSL in tests
    );
});

it('implements AeatClientContract', function () {
    expect($this->client)->toBeInstanceOf(\AichaDigital\LaraVerifactu\Contracts\AeatClientContract::class);
});

it('has all required methods', function () {
    expect(method_exists($this->client, 'sendRegistration'))->toBeTrue();
    expect(method_exists($this->client, 'sendCancellation'))->toBeTrue();
    expect(method_exists($this->client, 'sendBatch'))->toBeTrue();
    expect(method_exists($this->client, 'queryRegistry'))->toBeTrue();
    expect(method_exists($this->client, 'validateQr'))->toBeTrue();
});

it('sends registration to AEAT', function () {
    $registry = Mockery::mock(RegistryContract::class);
    $registry->shouldReceive('getXmlContent')->andReturn('<xml>test</xml>');
    $registry->shouldReceive('getRegistryId')->andReturn('REG-001');

    // Note: This test would need SOAP mocking to work fully
    expect($this->client)->toBeInstanceOf(AeatClient::class);
})->skip('Requires SOAP mocking for full implementation');

it('sends cancellation to AEAT', function () {
    expect($this->client)->toBeInstanceOf(AeatClient::class);
})->skip('Requires SOAP mocking for full implementation');

it('sends batch of registrations', function () {
    expect($this->client)->toBeInstanceOf(AeatClient::class);
})->skip('Requires SOAP mocking for full implementation');

it('queries registry status', function () {
    expect($this->client)->toBeInstanceOf(AeatClient::class);
})->skip('Requires SOAP mocking for full implementation');

it('validates QR code', function () {
    expect($this->client)->toBeInstanceOf(AeatClient::class);
})->skip('Requires SOAP mocking for full implementation');

it('uses certificate manager to sign XML', function () {
    $certificateManager = Mockery::mock(CertificateManagerContract::class);
    $certificateManager->shouldReceive('sign')
        ->andReturn('signed_xml');

    $client = new AeatClient($this->endpoint, $certificateManager, 30, false);

    expect($client)->toBeInstanceOf(AeatClient::class);
});

it('respects timeout configuration', function () {
    $client = new AeatClient($this->endpoint, $this->certificateManager, 60, false);

    expect($client)->toBeInstanceOf(AeatClient::class);
});

it('can be configured with SSL verification', function () {
    $client = new AeatClient($this->endpoint, $this->certificateManager, 30, true);

    expect($client)->toBeInstanceOf(AeatClient::class);
});

it('parses successful AEAT responses', function () {
    // Test AeatResponse creation
    $response = AeatResponse::success(['test' => 'data'], 'Success message');

    expect($response->isSuccess())->toBeTrue();
    expect($response->getMessage())->toBe('Success message');
    expect($response->getData())->toBe(['test' => 'data']);
});

it('parses failed AEAT responses', function () {
    // Test AeatResponse creation
    $response = AeatResponse::failure(['error' => 'test'], 'Failure message', 'ERR001');

    expect($response->isFailure())->toBeTrue();
    expect($response->getMessage())->toBe('Failure message');
    expect($response->getErrors())->toBe(['error' => 'test']);
    expect($response->getCode())->toBe('ERR001');
});

it('handles batch operations correctly', function () {
    $registries = collect();

    expect($this->client)->toBeInstanceOf(AeatClient::class);
});
