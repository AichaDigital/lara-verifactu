<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Support\AeatLogSanitizer;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| redactPayload — GROUP A (sensitive) elements masked
|--------------------------------------------------------------------------
*/

it('redacts the text of every sensitive (GROUP A) leaf element', function (string $element, string $value) {
    $xml = "<Root><{$element}>{$value}</{$element}></Root>";

    $out = AeatLogSanitizer::redactPayload($xml);

    expect($out)
        ->toContain("<{$element}>[REDACTED]</{$element}>")
        ->not->toContain($value);
})->with([
    // Party identification
    'NIF' => ['NIF', '50704972T'],
    'NombreRazon' => ['NombreRazon', 'ACME SOLUCIONES SL'],
    'IDOtro' => ['IDOtro', 'FR-PARTY-9988'],
    'CodigoPais' => ['CodigoPais', 'FR'],
    'NombreRazonEmisor' => ['NombreRazonEmisor', 'EMISOR EJEMPLO SL'],
    'NifRepresentante' => ['NifRepresentante', 'B12345678'],
    // Invoice identification
    'NumSerieFactura' => ['NumSerieFactura', 'FA-2026-0001'],
    'NumSerieFacturaAnulada' => ['NumSerieFacturaAnulada', 'FA-2026-0009'],
    'FechaExpedicionFactura' => ['FechaExpedicionFactura', '2026-06-18'],
    'FechaOperacion' => ['FechaOperacion', '2026-06-17'],
    'DescripcionOperacion' => ['DescripcionOperacion', 'Servicios de consultoria junio'],
    // Amounts / bases / quotas
    'ImporteTotal' => ['ImporteTotal', '1210.00'],
    'CuotaTotal' => ['CuotaTotal', '210.00'],
    'BaseImponibleOimporteNoSujeto' => ['BaseImponibleOimporteNoSujeto', '1000.00'],
    'CuotaRepercutida' => ['CuotaRepercutida', '210.00'],
    // Hash chain
    'Huella' => ['Huella', 'F3A9C1B2D4E5F6A7B8C9D0E1F2A3B4C5'],
    // Response receipt + errors
    'CSV' => ['CSV', 'A-945EPP9FM8YB66'],
    'DescripcionErrorRegistro' => ['DescripcionErrorRegistro', 'NIF 50704972T no identificado'],
    // codex challenge additions
    'RefRequerimiento' => ['RefRequerimiento', 'REQ-2026-XYZ'],
    'NumeroInstalacion' => ['NumeroInstalacion', 'INST-001'],
    'NumRegistroAcuerdoFacturacion' => ['NumRegistroAcuerdoFacturacion', 'ACU-77'],
    'IDType' => ['IDType', '02'],
    'Desde' => ['Desde', '2026-01-01'],
    'Hasta' => ['Hasta', '2026-12-31'],
    // XAdES signature material
    'X509Certificate' => ['X509Certificate', 'MIIFakeBase64CertData=='],
    'SignatureValue' => ['SignatureValue', 'c2lnbmF0dXJlVmFsdWU='],
    'DigestValue' => ['DigestValue', 'ZGlnZXN0VmFsdWU='],
    'KeyName' => ['KeyName', 'certificate-alias-prod'],
    'Modulus' => ['Modulus', 'AKfAkeModulus=='],
]);

it('preserves codes and enums (GROUP B) untouched', function (string $element, string $value) {
    $xml = "<Root><{$element}>{$value}</{$element}></Root>";

    $out = AeatLogSanitizer::redactPayload($xml);

    expect($out)->toContain("<{$element}>{$value}</{$element}>");
})->with([
    'IDVersion' => ['IDVersion', '1.0'],
    'TipoFactura' => ['TipoFactura', 'F1'],
    'TipoRectificativa' => ['TipoRectificativa', 'I'],
    'ClaveRegimen' => ['ClaveRegimen', '01'],
    'CalificacionOperacion' => ['CalificacionOperacion', 'S1'],
    'OperacionExenta' => ['OperacionExenta', 'E1'],
    'Impuesto' => ['Impuesto', '01'],
    'TipoImpositivo' => ['TipoImpositivo', '21'],
    'TipoRecargoEquivalencia' => ['TipoRecargoEquivalencia', '5.2'],
    'EstadoRegistro' => ['EstadoRegistro', 'Correcto'],
    'CodigoErrorRegistro' => ['CodigoErrorRegistro', '1142'],
    'Ejercicio' => ['Ejercicio', '2026'],
    'Periodo' => ['Periodo', '06'],
]);

it('masks sensitive children inside a nested container leaving structure intact', function () {
    $xml = '<RegistroAlta>'
        . '<IDFactura>'
        . '<IDEmisorFactura>50704972T</IDEmisorFactura>'
        . '<NumSerieFactura>FA-2026-0001</NumSerieFactura>'
        . '<FechaExpedicionFactura>2026-06-18</FechaExpedicionFactura>'
        . '</IDFactura>'
        . '<TipoFactura>F1</TipoFactura>'
        . '</RegistroAlta>';

    $out = AeatLogSanitizer::redactPayload($xml);

    expect($out)
        ->not->toContain('50704972T')
        ->not->toContain('FA-2026-0001')
        ->not->toContain('2026-06-18')
        ->toContain('<IDFactura>')          // structure preserved
        ->toContain('<TipoFactura>F1</TipoFactura>'); // code preserved
});

it('masks namespaced sensitive elements regardless of prefix', function () {
    $xml = '<sum:Root xmlns:sum="urn:x" xmlns:ds="urn:ds">'
        . '<sum:NIF>50704972T</sum:NIF>'
        . '<ds:X509Certificate>MIIFakeCert==</ds:X509Certificate>'
        . '</sum:Root>';

    $out = AeatLogSanitizer::redactPayload($xml);

    expect($out)
        ->not->toContain('50704972T')
        ->not->toContain('MIIFakeCert==');
});

it('wipes the whole subtree of a sensitive element, not only its direct text', function () {
    // Defensive: should a denylisted element ever carry child nodes, none must leak.
    $xml = '<Root><NombreRazon>ACME <b>SECRET</b> SL</NombreRazon></Root>';

    $out = AeatLogSanitizer::redactPayload($xml);

    expect($out)
        ->not->toContain('SECRET')
        ->toContain('<NombreRazon>[REDACTED]</NombreRazon>');
});

/*
|--------------------------------------------------------------------------
| redactPayload — fail closed
|--------------------------------------------------------------------------
*/

it('returns a safe placeholder and never the raw input on malformed XML', function () {
    $raw = '<Root><NIF>50704972T</NIF'; // unterminated

    $out = AeatLogSanitizer::redactPayload($raw);

    expect($out)
        ->toBe('[verifactu] unparseable SOAP payload redacted')
        ->and($out)->not->toContain('50704972T');
});

it('returns the placeholder on empty input without crashing', function () {
    expect(AeatLogSanitizer::redactPayload(''))
        ->toBe('[verifactu] unparseable SOAP payload redacted');
    expect(AeatLogSanitizer::redactPayload(null))
        ->toBe('[verifactu] unparseable SOAP payload redacted');
});

it('refuses DOCTYPE payloads to prevent XXE, returning the placeholder', function () {
    $xxe = '<?xml version="1.0"?>'
        . '<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
        . '<Root><NIF>&xxe;</NIF></Root>';

    $out = AeatLogSanitizer::redactPayload($xxe);

    expect($out)
        ->toBe('[verifactu] unparseable SOAP payload redacted')
        ->and($out)->not->toContain('passwd');
});

/*
|--------------------------------------------------------------------------
| redactText — id/NIF pattern masking in free text
|--------------------------------------------------------------------------
*/

it('masks Spanish tax-id variants in free text', function (string $text) {
    $out = AeatLogSanitizer::redactText($text);

    expect($out)->toContain('[REDACTED-ID]');
})->with([
    'DNI/NIF' => ['Rechazado para 50704972T en el registro'],
    'CIF' => ['Obligado B12345678 no censado'],
    'NIE' => ['Destinatario X1234567L invalido'],
    'ES VAT prefix' => ['Contraparte ESB12345678 erronea'],
    'hyphen separator' => ['Documento 12345678-Z'],
    'lowercase' => ['nif 50704972t minuscula'],
]);

it('leaves plain text without ids untouched', function () {
    $msg = 'SOAP communication error: connection timeout after 30s';

    expect(AeatLogSanitizer::redactText($msg))->toBe($msg);
});

/*
|--------------------------------------------------------------------------
| traceContext — gated behind the explicit debug flag
|--------------------------------------------------------------------------
*/

it('omits the stack trace unless the debug flag is enabled', function () {
    config()->set('verifactu.logging.debug', false);

    expect(AeatLogSanitizer::traceContext(new RuntimeException('boom')))->toBe([]);
});

it('includes the stack trace when the debug flag is enabled', function () {
    config()->set('verifactu.logging.debug', true);

    $ctx = AeatLogSanitizer::traceContext(new RuntimeException('boom'));

    expect($ctx)->toHaveKey('trace')
        ->and($ctx['trace'])->toBeString();
});

/*
|--------------------------------------------------------------------------
| logExchange — opt-in gate + channel + always redacted
|--------------------------------------------------------------------------
*/

it('logs nothing when the soap payload flag is disabled', function () {
    config()->set('verifactu.logging.log_soap_payload', false);
    Log::shouldReceive('channel')->never();

    AeatLogSanitizer::logExchange('<Root><NIF>50704972T</NIF></Root>', '<Ok/>');
});

it('logs redacted request and response on the verifactu channel when enabled', function () {
    config()->set('verifactu.logging.log_soap_payload', true);
    config()->set('verifactu.logging.channel', 'verifactu');

    $logged = [];
    Log::shouldReceive('channel')->with('verifactu')->andReturnSelf();
    Log::shouldReceive('debug')->andReturnUsing(function ($message, $context) use (&$logged) {
        $logged[$message] = $context;
    });

    AeatLogSanitizer::logExchange(
        '<Root><NIF>50704972T</NIF><TipoFactura>F1</TipoFactura></Root>',
        '<Resp><CSV>A-945EPP9FM8YB66</CSV></Resp>'
    );

    expect($logged)->toHaveKeys(['AEAT SOAP Request', 'AEAT SOAP Response']);
    expect($logged['AEAT SOAP Request']['request'])
        ->not->toContain('50704972T')
        ->toContain('F1'); // code preserved
    expect($logged['AEAT SOAP Response']['response'])
        ->not->toContain('A-945EPP9FM8YB66');
});
