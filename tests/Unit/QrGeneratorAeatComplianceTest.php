<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Services\QrGenerator;
use Carbon\Carbon;

/**
 * QR content per AEAT spec "Detalle de las especificaciones técnicas del
 * código QR de la factura" v0.4.7 (docs/verifactu/DetalleEspecificacTecnCodigoQRfactura.pdf):
 * the URL carries exactly nif, numserie, fecha and importe, URL-encoded (UTF-8),
 * against the official cotejo service.
 */
function createQrComplianceInvoice(string $invoiceNumber = '12345678/G33', float $total = 241.40): InvoiceContract
{
    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn('89890001K');
    $invoice->shouldReceive('getInvoiceNumber')->andReturn($invoiceNumber);
    $invoice->shouldReceive('getIssueDatetime')->andReturn(Carbon::parse('2024-01-01 10:00:00'));
    $invoice->shouldReceive('getTotalAmount')->andReturn($total);

    return $invoice;
}

it('builds the validation URL with exactly the four official AEAT parameters', function () {
    $generator = new QrGenerator('https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR');

    $url = $generator->getValidationUrl(createQrComplianceInvoice());

    expect($url)->toBe(
        'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?nif=89890001K&numserie=12345678%2FG33&fecha=01-01-2024&importe=241.40'
    );
});

it('url-encodes reserved characters in numserie as in the official example', function () {
    $generator = new QrGenerator('https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR');

    // Official spec example: numserie "12345678&G33" must encode as 12345678%26G33
    $url = $generator->getValidationUrl(createQrComplianceInvoice('12345678&G33', 241.40));

    expect($url)->toContain('numserie=12345678%26G33')
        ->and($url)->not->toContain('numserie=12345678&G33');
});

it('does not include hash or invoice type parameters in the QR URL', function () {
    $generator = new QrGenerator('https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR');

    $url = $generator->getValidationUrl(createQrComplianceInvoice());

    expect($url)->not->toContain('hash=')
        ->and($url)->not->toContain('tipo=')
        ->and($url)->not->toContain('num=1'); // legacy param name
});

it('generates an SVG QR containing the validation URL data', function () {
    $generator = new QrGenerator('https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR', 'svg');

    $svg = $generator->generateSvg(createQrComplianceInvoice());

    expect($svg)->toContain('<svg');
});
