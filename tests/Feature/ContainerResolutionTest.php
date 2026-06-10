<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Services\AeatClient;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\QrGenerator;
use AichaDigital\LaraVerifactu\Verifactu;

/**
 * The package must be resolvable from the container out of the box:
 * services with scalar constructor parameters need bindings that read
 * their values from config.
 */
it('defines company and system identification keys in the config file', function () {
    expect(config('verifactu.company'))->toBeArray()->toHaveKeys(['tax_id', 'name'])
        ->and(config('verifactu.system'))->toBeArray()->toHaveKeys(['vendor_name', 'vendor_nif', 'name', 'id', 'version', 'installation_number']);
});

it('resolves the QR generator from the container', function () {
    $generator = app(QrGeneratorContract::class);

    expect($generator)->toBeInstanceOf(QrGenerator::class);
});

it('resolves the QR validation URL according to the AEAT environment', function () {
    config()->set('verifactu.aeat.environment', 'sandbox');

    $generator = app(QrGeneratorContract::class);
    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn('89890001K');
    $invoice->shouldReceive('getInvoiceNumber')->andReturn('A-1');
    $invoice->shouldReceive('getIssueDatetime')->andReturn(Carbon\Carbon::parse('2026-01-01'));
    $invoice->shouldReceive('getTotalAmount')->andReturn(10.0);

    expect($generator->getValidationUrl($invoice))
        ->toStartWith('https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?');
});

it('resolves the AEAT client from the container', function () {
    $client = app(AeatClientContract::class);

    expect($client)->toBeInstanceOf(AeatClient::class);
});

it('resolves the invoice registrar orchestrator from the container', function () {
    $registrar = app(InvoiceRegistrar::class);

    expect($registrar)->toBeInstanceOf(InvoiceRegistrar::class);
});

it('resolves the verifactu facade root from the container', function () {
    $verifactu = app('verifactu');

    expect($verifactu)->toBeInstanceOf(Verifactu::class);
});
