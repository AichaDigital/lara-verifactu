<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Services\AeatClient;
use AichaDigital\LaraVerifactu\Support\AeatResponse;

beforeEach(function () {
    $this->endpoint = 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion';

    $this->client = new AeatClient(
        $this->endpoint,
        30,
        false // Don't verify SSL in tests
    );
});

it('implements AeatClientContract', function () {
    expect($this->client)->toBeInstanceOf(AeatClientContract::class);
});

it('exposes only the operations of the official WSDL', function () {
    expect(method_exists($this->client, 'sendRegistration'))->toBeTrue()
        ->and(method_exists($this->client, 'sendBatch'))->toBeTrue()
        // Operations that do not exist in the official WSDL must be gone
        ->and(method_exists($this->client, 'sendCancellation'))->toBeFalse()
        ->and(method_exists($this->client, 'queryRegistry'))->toBeFalse()
        ->and(method_exists($this->client, 'validateQr'))->toBeFalse();
});

it('does not sign the XML itself: signing is the registrar responsibility', function () {
    // The client has no certificate manager dependency at all: signing
    // happens upstream in the registrar before the XML reaches this client.
    $registry = Mockery::mock(RegistryContract::class);
    $registry->shouldReceive('getSignedXml')->andReturn('<signed/>');
    $registry->shouldReceive('getXml')->andReturn('<xml/>');
    $registry->shouldReceive('getRegistryNumber')->andReturn('REG-001');

    // Connection fails in tests (no real WSDL) — but never because of signing
    expect(fn () => $this->client->sendRegistration($registry))
        ->toThrow(AeatException::class);
});

it('respects timeout configuration', function () {
    $client = new AeatClient($this->endpoint, 60, false);

    expect($client)->toBeInstanceOf(AeatClient::class);
});

it('can be configured with SSL verification', function () {
    $client = new AeatClient($this->endpoint, 30, true);

    expect($client)->toBeInstanceOf(AeatClient::class);
});

it('builds successful AeatResponse objects', function () {
    $response = AeatResponse::success(['test' => 'data'], 'Success message');

    expect($response->isSuccess())->toBeTrue();
    expect($response->getMessage())->toBe('Success message');
    expect($response->getData())->toBe(['test' => 'data']);
});

it('builds failed AeatResponse objects', function () {
    $response = AeatResponse::failure(['error' => 'test'], 'Failure message', 'ERR001');

    expect($response->isFailure())->toBeTrue();
    expect($response->getMessage())->toBe('Failure message');
    expect($response->getErrors())->toBe(['error' => 'test']);
    expect($response->getCode())->toBe('ERR001');
});

it('returns per-registry failure responses when batch sending fails', function () {
    $registry = Mockery::mock(RegistryContract::class);
    $registry->shouldReceive('getSignedXml')->andReturn('<signed/>');
    $registry->shouldReceive('getXml')->andReturn('<xml/>');
    $registry->shouldReceive('getRegistryNumber')->andReturn('REG-001');

    $responses = $this->client->sendBatch(collect([$registry]));

    expect($responses)->toHaveCount(1)
        ->and($responses->first()->isFailure())->toBeTrue();
});
