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

it('parses a duplicate record as already filed, not as a failure', function () {
    // Changed by AID-727. This used to assert isFailure(): a duplicate answer
    // was classified as a validation rejection, which drove the record to a
    // terminal REJECTED without CSV even though the agency held it as filed.
    // «Duplicado» means the agency already has it — a success, not a refusal.
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

    expect($result->isDuplicate())->toBeTrue()
        ->and($result->isSuccess())->toBeTrue()
        ->and($result->isValidationRejection())->toBeFalse()
        // The error detail is preserved: it is why the record is a duplicate.
        ->and($result->getErrors())->toContain('3000: Registro de facturación duplicado');
});

it('parses a duplicate of an accepted-with-errors record as already filed', function () {
    // This is what the agency actually answers (verified against the prewww1
    // sandbox): a record accepted seconds earlier with a CSV comes back as
    // `AceptadaConErrores`, because the submission was ParcialmenteCorrecto.
    // L19 of Veri-Factu_Descripcion_SWeb.pdf: such a record «se registra en el
    // sistema». Requiring `Correcta` left this branch unreachable in practice.
    $response = (object) [
        'EstadoEnvio' => 'Incorrecto',
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'Incorrecto',
            'CodigoErrorRegistro' => '3000',
            'DescripcionErrorRegistro' => 'Registro de facturación duplicado.',
            'RegistroDuplicado' => (object) ['EstadoRegistroDuplicado' => 'AceptadaConErrores'],
        ],
    ];

    $result = $this->parser->parse($response);

    expect($result->isDuplicate())->toBeTrue()
        ->and($result->isSuccess())->toBeTrue()
        ->and($result->isValidationRejection())->toBeFalse();
});

it('treats a duplicate of an ANNULLED record as a rejection', function () {
    // `Anulada` is the one L21 state that must NOT reconcile: what the agency
    // holds is an annulled record, not ours. FAQ §6 — once a cancellation is
    // filed, an alta reusing that number is refused for good, so this has to
    // stay a rejection and keep feeding Guard 3 of amendRejected(). Verified
    // against the sandbox with the FAQ §6 recipe: alta, anulación, alta.
    $response = (object) [
        'EstadoEnvio' => 'Incorrecto',
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'Incorrecto',
            'CodigoErrorRegistro' => '3000',
            'DescripcionErrorRegistro' => 'Registro de facturación duplicado.',
            'RegistroDuplicado' => (object) ['EstadoRegistroDuplicado' => 'Anulada'],
        ],
    ];

    $result = $this->parser->parse($response);

    expect($result->isDuplicate())->toBeFalse()
        ->and($result->isValidationRejection())->toBeTrue();
});

it('treats an unknown duplicate state as a rejection', function () {
    // The three values of L21 are exhaustive today. Anything else is a schema
    // the package has not seen, and guessing «already filed» would stop
    // retrying something that may never have been filed.
    $response = (object) [
        'EstadoEnvio' => 'Incorrecto',
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'Incorrecto',
            'CodigoErrorRegistro' => '3000',
            'DescripcionErrorRegistro' => 'Registro de facturación duplicado.',
            'RegistroDuplicado' => (object) ['EstadoRegistroDuplicado' => 'EstadoQueNoExiste'],
        ],
    ];

    $result = $this->parser->parse($response);

    expect($result->isDuplicate())->toBeFalse()
        ->and($result->isValidationRejection())->toBeTrue();
});

it('treats a mixed response with only some duplicate lines as a rejection', function () {
    // All lines must agree. A mixed answer keeps feeding Guard 3 of
    // amendRejected(), which reasons over registro_duplicado.
    $response = (object) [
        'EstadoEnvio' => 'Incorrecto',
        'RespuestaLinea' => [
            (object) [
                'EstadoRegistro' => 'Incorrecto',
                'CodigoErrorRegistro' => '3000',
                'DescripcionErrorRegistro' => 'Registro de facturación duplicado',
                'RegistroDuplicado' => (object) ['EstadoRegistroDuplicado' => 'Correcta'],
            ],
            (object) [
                'EstadoRegistro' => 'Incorrecto',
                'CodigoErrorRegistro' => '3002',
                'DescripcionErrorRegistro' => 'NIF del IDFactura no identificado',
            ],
        ],
    ];

    $result = $this->parser->parse($response);

    expect($result->isDuplicate())->toBeFalse()
        ->and($result->isValidationRejection())->toBeTrue();
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
        ->and($result->getData()['lineas'][0]['descripcion'])->toBe('NIF del IDFactura no identificado')
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

    // AID-727 moved this out of the rejection branch — the signal itself is
    // still preserved in the line metadata, which is what Guard 3 of
    // amendRejected() reads, plus the state that decides reconciliation.
    expect($result->isDuplicate())->toBeTrue()
        ->and($result->getData()['lineas'][0]['registro_duplicado'])->toBeTrue()
        ->and($result->getData()['lineas'][0]['registro_duplicado_estado'])->toBe('Correcta');
});

it('treats a non-object response as a transport failure, not a rejection', function () {
    $result = $this->parser->parse('unexpected');

    expect($result->isFailure())->toBeTrue()
        ->and($result->isValidationRejection())->toBeFalse();
});
