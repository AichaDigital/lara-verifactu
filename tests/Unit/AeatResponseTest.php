<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Support\AeatResponse;

// ========================================
// Success Response Tests
// ========================================

describe('Success Response', function () {
    it('creates success response', function () {
        $response = AeatResponse::success();

        expect($response->isSuccess())->toBeTrue()
            ->and($response->isFailure())->toBeFalse();
    });

    it('creates success response with data', function () {
        $data = ['csv' => 'ABC123', 'timestamp' => '2025-01-01'];
        $response = AeatResponse::success($data);

        expect($response->isSuccess())->toBeTrue()
            ->and($response->getData())->toBe($data);
    });

    it('creates success response with message', function () {
        $response = AeatResponse::success(null, 'Operation completed');

        expect($response->getMessage())->toBe('Operation completed');
    });

    it('success response has no errors', function () {
        $response = AeatResponse::success();

        expect($response->hasErrors())->toBeFalse()
            ->and($response->getErrors())->toBeNull();
    });
});

// ========================================
// Failure Response Tests
// ========================================

describe('Failure Response', function () {
    it('creates failure response', function () {
        $response = AeatResponse::failure();

        expect($response->isSuccess())->toBeFalse()
            ->and($response->isFailure())->toBeTrue();
    });

    it('creates failure response with errors', function () {
        $errors = ['Error 1', 'Error 2'];
        $response = AeatResponse::failure($errors);

        expect($response->hasErrors())->toBeTrue()
            ->and($response->getErrors())->toBe($errors);
    });

    it('creates failure response with message and code', function () {
        $response = AeatResponse::failure(null, 'Operation failed', 'ERR001');

        expect($response->getMessage())->toBe('Operation failed')
            ->and($response->getCode())->toBe('ERR001');
    });

    it('failure with empty errors array has no errors', function () {
        $response = AeatResponse::failure([]);

        expect($response->hasErrors())->toBeFalse();
    });
});

// ========================================
// Constructor Tests
// ========================================

describe('Constructor', function () {
    it('creates response with all parameters', function () {
        $response = new AeatResponse(
            success: true,
            code: 'CSV123',
            message: 'Registry accepted',
            data: ['field' => 'value'],
            errors: null
        );

        expect($response->isSuccess())->toBeTrue()
            ->and($response->getCode())->toBe('CSV123')
            ->and($response->getMessage())->toBe('Registry accepted')
            ->and($response->getData())->toBe(['field' => 'value'])
            ->and($response->getErrors())->toBeNull();
    });
});

// ========================================
// Getter Methods Tests
// ========================================

describe('Getter Methods', function () {
    it('getCsv returns code', function () {
        $response = new AeatResponse(true, 'CSV123');

        expect($response->getCsv())->toBe('CSV123');
    });

    it('getErrorMessage returns message or default', function () {
        $responseWithMessage = new AeatResponse(false, null, 'Custom error');
        $responseWithoutMessage = new AeatResponse(false);

        expect($responseWithMessage->getErrorMessage())->toBe('Custom error')
            ->and($responseWithoutMessage->getErrorMessage())->toBe('Unknown error');
    });
});

// ========================================
// Validation Rejection Tests
// ========================================

it('flags a rejection as a validation rejection failure carrying data', function () {
    $response = AeatResponse::rejection(
        errors: ['3002: NIF del IDFactura no identificado'],
        message: 'Incorrecto',
        data: ['estado_envio' => 'Incorrecto'],
    );

    expect($response->isFailure())->toBeTrue()
        ->and($response->isValidationRejection())->toBeTrue()
        ->and($response->getData())->toBe(['estado_envio' => 'Incorrecto'])
        ->and($response->getErrors())->toContain('3002: NIF del IDFactura no identificado');
});

it('treats a plain failure as transport, not a validation rejection', function () {
    $response = AeatResponse::failure(errors: ['Invalid response from AEAT server']);

    expect($response->isFailure())->toBeTrue()
        ->and($response->isValidationRejection())->toBeFalse();
});

// ========================================
// toArray Tests
// ========================================

describe('toArray', function () {
    it('converts response to array', function () {
        $response = new AeatResponse(
            success: true,
            code: 'ABC',
            message: 'OK',
            data: ['key' => 'value'],
            errors: ['error1']
        );

        $array = $response->toArray();

        expect($array)->toBeArray()
            ->and($array['success'])->toBeTrue()
            ->and($array['code'])->toBe('ABC')
            ->and($array['message'])->toBe('OK')
            ->and($array['data'])->toBe(['key' => 'value'])
            ->and($array['errors'])->toBe(['error1']);
    });

    it('handles null values in toArray', function () {
        $response = new AeatResponse(false);

        $array = $response->toArray();

        expect($array['success'])->toBeFalse()
            ->and($array['code'])->toBeNull()
            ->and($array['message'])->toBeNull()
            ->and($array['data'])->toBeNull()
            ->and($array['errors'])->toBeNull();
    });
});
