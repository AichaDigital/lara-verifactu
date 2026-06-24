<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Services\AeatResponseParser;

/**
 * Parses the RegFactuSistemaFacturacion SOAP response (RespuestaSuministro):
 * EstadoEnvio is Correcto | ParcialmenteCorrecto | Incorrecto; each
 * RespuestaLinea carries EstadoRegistro Correcto | AceptadoConErrores |
 * Incorrecto plus error code/description (see Validaciones_Errores_Veri-Factu.pdf).
 */
beforeEach(function () {
    $this->parser = new AeatResponseParser;
});

it('parses an accepted submission with CSV', function () {
    $response = (object) [
        'CSV' => 'CSV1234567890',
        'EstadoEnvio' => 'Correcto',
        'DatosPresentacion' => (object) [
            'NIFPresentador' => '89890001K',
            'TimestampPresentacion' => '2026-06-10T12:00:00+02:00',
        ],
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'Correcto',
        ],
    ];

    $result = $this->parser->parse($response);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->getCsv())->toBe('CSV1234567890')
        ->and($result->hasErrors())->toBeFalse();
});

it('parses a record accepted with errors as success carrying the error details', function () {
    $response = (object) [
        'CSV' => 'CSV0987654321',
        'EstadoEnvio' => 'ParcialmenteCorrecto',
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'AceptadoConErrores',
            'CodigoErrorRegistro' => '2003',
            'DescripcionErrorRegistro' => 'Huella incorrecta',
        ],
    ];

    $result = $this->parser->parse($response);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->getCsv())->toBe('CSV0987654321')
        ->and($result->hasErrors())->toBeTrue()
        ->and($result->getErrors())->toContain('2003: Huella incorrecta');
});

it('parses a rejected submission as failure with line errors', function () {
    $response = (object) [
        'EstadoEnvio' => 'Incorrecto',
        'RespuestaLinea' => [
            (object) [
                'EstadoRegistro' => 'Incorrecto',
                'CodigoErrorRegistro' => '3002',
                'DescripcionErrorRegistro' => 'NIF del IDFactura no identificado',
            ],
        ],
    ];

    $result = $this->parser->parse($response);

    expect($result->isFailure())->toBeTrue()
        ->and($result->getErrors())->toContain('3002: NIF del IDFactura no identificado')
        ->and($result->getCsv())->toBeNull();
});

it('parses a duplicate record rejection', function () {
    $response = (object) [
        'EstadoEnvio' => 'Incorrecto',
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'Incorrecto',
            'CodigoErrorRegistro' => '3000',
            'DescripcionErrorRegistro' => 'Registro de facturación duplicado',
            'RegistroDuplicado' => (object) [
                'EstadoRegistroDuplicado' => 'Correcta',
            ],
        ],
    ];

    $result = $this->parser->parse($response);

    expect($result->isFailure())->toBeTrue()
        ->and($result->getErrors())->toContain('3000: Registro de facturación duplicado');
});

it('returns failure for a non-object response', function () {
    $result = $this->parser->parse('unexpected');

    expect($result->isFailure())->toBeTrue();
});

it('classifies a validation rejection and preserves line metadata', function () {
    $response = (object) [
        'EstadoEnvio' => 'Incorrecto',
        'RespuestaLinea' => [
            (object) [
                'EstadoRegistro' => 'Incorrecto',
                'CodigoErrorRegistro' => '3002',
                'DescripcionErrorRegistro' => 'NIF del IDFactura no identificado',
            ],
        ],
    ];

    $result = $this->parser->parse($response);

    expect($result->isValidationRejection())->toBeTrue()
        ->and($result->getData()['estado_envio'])->toBe('Incorrecto')
        ->and($result->getData()['lineas'][0]['codigo'])->toBe('3002')
        ->and($result->getData()['lineas'][0]['estado_registro'])->toBe('Incorrecto')
        ->and($result->getData()['lineas'][0]['registro_duplicado'])->toBeFalse();
});

it('preserves the RegistroDuplicado signal on a duplicate-key rejection', function () {
    $response = (object) [
        'EstadoEnvio' => 'Incorrecto',
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'Incorrecto',
            'CodigoErrorRegistro' => '3000',
            'DescripcionErrorRegistro' => 'Registro de facturación duplicado',
            'RegistroDuplicado' => (object) ['EstadoRegistroDuplicado' => 'Correcta'],
        ],
    ];

    $result = $this->parser->parse($response);

    expect($result->isValidationRejection())->toBeTrue()
        ->and($result->getData()['lineas'][0]['registro_duplicado'])->toBeTrue();
});

it('treats a non-object response as a transport failure, not a rejection', function () {
    $result = $this->parser->parse('unexpected');

    expect($result->isFailure())->toBeTrue()
        ->and($result->isValidationRejection())->toBeFalse();
});
