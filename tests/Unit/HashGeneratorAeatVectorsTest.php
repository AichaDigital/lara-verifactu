<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use Carbon\Carbon;

/**
 * Official AEAT test vectors from "Detalle de las especificaciones técnicas
 * para generación de la huella o hash de los registros de facturación" v0.1.2
 * (docs/verifactu/Veri-Factu_especificaciones_huella_hash_registros.pdf, section 6).
 *
 * These hashes are the authoritative conformance proof: if they do not match,
 * AEAT marks submissions as "Aceptado con errores".
 */
beforeEach(function () {
    $this->generator = new HashGenerator;
});

// Case 1 expected chain:
// IDEmisorFactura=89890001K&NumSerieFactura=12345678/G33&FechaExpedicionFactura=01-01-2024
// &TipoFactura=F1&CuotaTotal=12.35&ImporteTotal=123.45&Huella=
// &FechaHoraHusoGenRegistro=2024-01-01T19:20:30+01:00
it('matches official AEAT vector case 1: first registration record', function () {
    $invoice = createAeatVectorInvoice('12345678/G33');
    $generatedAt = Carbon::parse('2024-01-01T19:20:30+01:00');

    $hash = $this->generator->generate($invoice, null, $generatedAt);

    expect($hash)->toBe('3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60');
});

it('matches official AEAT vector case 2: registration record chained to a previous one', function () {
    $invoice = createAeatVectorInvoice('12345679/G34');
    $generatedAt = Carbon::parse('2024-01-01T19:20:35+01:00');
    $previousHash = '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60';

    $hash = $this->generator->generate($invoice, $previousHash, $generatedAt);

    expect($hash)->toBe('F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97');
});

// Case 3 expected chain:
// IDEmisorFacturaAnulada=89890001K&NumSerieFacturaAnulada=12345679/G34
// &FechaExpedicionFacturaAnulada=01-01-2024&Huella=F7B9...&FechaHoraHusoGenRegistro=2024-01-01T19:20:40+01:00
it('matches official AEAT vector case 3: cancellation record chained to a previous one', function () {
    $generatedAt = Carbon::parse('2024-01-01T19:20:40+01:00');
    $previousHash = 'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97';

    $hash = $this->generator->generateCancellation(
        '89890001K',
        '12345679/G34',
        Carbon::parse('2024-01-01'),
        $previousHash,
        $generatedAt,
    );

    expect($hash)->toBe('177547C0D57AC74748561D054A9CEC14B4C4EA23D1BEFD6F2E69E3A388F90C68');
});

it('produces a 64-character uppercase hexadecimal hash', function () {
    $invoice = createAeatVectorInvoice('12345678/G33');
    $generatedAt = Carbon::parse('2024-01-01T19:20:30+01:00');

    $hash = $this->generator->generate($invoice, null, $generatedAt);

    expect($hash)->toHaveLength(64)->toMatch('/^[A-F0-9]{64}$/');
});

it('verifies a stored hash using the persisted previous hash and generation timestamp', function () {
    $invoice = createAeatVectorInvoice('12345679/G34');
    $generatedAt = Carbon::parse('2024-01-01T19:20:35+01:00');
    $previousHash = '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60';

    $result = $this->generator->verify(
        'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97',
        $invoice,
        $previousHash,
        $generatedAt,
    );

    expect($result)->toBeTrue();
});

it('rejects a hash that does not match the persisted chain data', function () {
    $invoice = createAeatVectorInvoice('12345679/G34');
    $generatedAt = Carbon::parse('2024-01-01T19:20:35+01:00');

    $result = $this->generator->verify(
        'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97',
        $invoice,
        null, // wrong: chain says there was a previous hash
        $generatedAt,
    );

    expect($result)->toBeFalse();
});

/**
 * Build an invoice mock carrying the official AEAT vector input data.
 */
function createAeatVectorInvoice(string $invoiceNumber): InvoiceContract
{
    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn('89890001K');
    $invoice->shouldReceive('getSerie')->andReturn(null);
    $invoice->shouldReceive('getNumber')->andReturn($invoiceNumber);
    $invoice->shouldReceive('getInvoiceNumber')->andReturn($invoiceNumber);
    $invoice->shouldReceive('getIssueDatetime')->andReturn(Carbon::parse('2024-01-01 10:00:00'));
    $invoice->shouldReceive('getType')->andReturn(InvoiceTypeEnum::COMPLETE);
    $invoice->shouldReceive('getInvoiceType')->andReturn(InvoiceTypeEnum::COMPLETE);
    $invoice->shouldReceive('getTaxAmount')->andReturn(12.35);
    $invoice->shouldReceive('getTotalAmount')->andReturn(123.45);

    return $invoice;
}
