<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Exceptions\AeatAuthenticationException;
use AichaDigital\LaraVerifactu\Exceptions\AeatConnectionException;
use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Exceptions\AeatRejectionException;
use AichaDigital\LaraVerifactu\Exceptions\CertificateException;
use AichaDigital\LaraVerifactu\Exceptions\ConfigurationException;
use AichaDigital\LaraVerifactu\Exceptions\HashException;
use AichaDigital\LaraVerifactu\Exceptions\ValidationException;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Exceptions\XmlException;

// ========================================
// VerifactuException Tests
// ========================================

describe('VerifactuException', function () {
    it('can be created with message', function () {
        $exception = new VerifactuException('Test message');

        expect($exception)
            ->toBeInstanceOf(VerifactuException::class)
            ->getMessage()->toBe('Test message');
    });

    it('can be created with code and previous exception', function () {
        $previous = new \Exception('Previous error');
        $exception = new VerifactuException('Test message', 500, $previous);

        expect($exception->getCode())->toBe(500);
        expect($exception->getPrevious())->toBe($previous);
    });

    it('can be created using factory method', function () {
        $exception = VerifactuException::make('Factory message', 400);

        expect($exception)
            ->toBeInstanceOf(VerifactuException::class)
            ->getMessage()->toBe('Factory message')
            ->getCode()->toBe(400);
    });
});

// ========================================
// CertificateException Tests
// ========================================

describe('CertificateException', function () {
    it('creates file not found exception', function () {
        $exception = CertificateException::fileNotFound('/path/to/cert.p12');

        expect($exception)
            ->toBeInstanceOf(CertificateException::class)
            ->getMessage()->toContain('Certificate file not found')
            ->getMessage()->toContain('/path/to/cert.p12');
    });

    it('creates invalid password exception', function () {
        $exception = CertificateException::invalidPassword();

        expect($exception)
            ->toBeInstanceOf(CertificateException::class)
            ->getMessage()->toBe('Invalid certificate password.');
    });

    it('creates invalid format exception', function () {
        $exception = CertificateException::invalidFormat();

        expect($exception)
            ->toBeInstanceOf(CertificateException::class)
            ->getMessage()->toBe('Invalid certificate format.');
    });

    it('creates expired exception', function () {
        $exception = CertificateException::expired();

        expect($exception)
            ->toBeInstanceOf(CertificateException::class)
            ->getMessage()->toBe('Certificate has expired.');
    });

    it('creates not yet valid exception', function () {
        $exception = CertificateException::notYetValid();

        expect($exception)
            ->toBeInstanceOf(CertificateException::class)
            ->getMessage()->toBe('Certificate is not yet valid.');
    });

    it('creates invalid private key exception', function () {
        $exception = CertificateException::invalidPrivateKey();

        expect($exception)
            ->toBeInstanceOf(CertificateException::class)
            ->getMessage()->toContain('Invalid or missing private key');
    });
});

// ========================================
// AeatException Tests
// ========================================

describe('AeatException', function () {
    it('creates communication error exception', function () {
        $exception = AeatException::communicationError('Connection reset');

        expect($exception)
            ->toBeInstanceOf(AeatException::class)
            ->getMessage()->toContain('AEAT communication error')
            ->getMessage()->toContain('Connection reset');
    });

    it('creates timeout exception', function () {
        $exception = AeatException::timeout();

        expect($exception)
            ->toBeInstanceOf(AeatException::class)
            ->getMessage()->toBe('AEAT request timeout.');
    });

    it('creates invalid response exception', function () {
        $exception = AeatException::invalidResponse('Empty body');

        expect($exception)
            ->toBeInstanceOf(AeatException::class)
            ->getMessage()->toContain('Invalid AEAT response')
            ->getMessage()->toContain('Empty body');
    });

    it('creates connection failed exception', function () {
        $exception = AeatException::connectionFailed('Host unreachable');

        expect($exception)
            ->toBeInstanceOf(AeatException::class)
            ->getMessage()->toContain('AEAT connection failed')
            ->getMessage()->toContain('Host unreachable');
    });
});

// ========================================
// AeatConnectionException Tests
// ========================================

describe('AeatConnectionException', function () {
    it('creates cannot connect exception', function () {
        $exception = AeatConnectionException::cannotConnect('https://api.aeat.es');

        expect($exception)
            ->toBeInstanceOf(AeatConnectionException::class)
            ->toBeInstanceOf(AeatException::class)
            ->getMessage()->toContain('Cannot connect to AEAT endpoint')
            ->getMessage()->toContain('https://api.aeat.es');
    });

    it('creates ssl error exception', function () {
        $exception = AeatConnectionException::sslError('Certificate verification failed');

        expect($exception)
            ->toBeInstanceOf(AeatConnectionException::class)
            ->getMessage()->toContain('SSL error')
            ->getMessage()->toContain('Certificate verification failed');
    });
});

// ========================================
// AeatAuthenticationException Tests
// ========================================

describe('AeatAuthenticationException', function () {
    it('creates invalid credentials exception', function () {
        $exception = AeatAuthenticationException::invalidCredentials();

        expect($exception)
            ->toBeInstanceOf(AeatAuthenticationException::class)
            ->toBeInstanceOf(AeatException::class)
            ->getMessage()->toBe('Invalid AEAT credentials.');
    });

    it('creates certificate not accepted exception', function () {
        $exception = AeatAuthenticationException::certificateNotAccepted();

        expect($exception)
            ->toBeInstanceOf(AeatAuthenticationException::class)
            ->getMessage()->toBe('Certificate not accepted by AEAT.');
    });

    it('creates unauthorized exception', function () {
        $exception = AeatAuthenticationException::unauthorized();

        expect($exception)
            ->toBeInstanceOf(AeatAuthenticationException::class)
            ->getMessage()->toBe('Unauthorized access to AEAT services.');
    });
});

// ========================================
// HashException Tests
// ========================================

describe('HashException', function () {
    it('creates invalid hash exception', function () {
        $exception = HashException::invalidHash('abc123');

        expect($exception)
            ->toBeInstanceOf(HashException::class)
            ->toBeInstanceOf(VerifactuException::class)
            ->getMessage()->toContain('Invalid hash')
            ->getMessage()->toContain('abc123');
    });

    it('creates hash mismatch exception', function () {
        $exception = HashException::hashMismatch();

        expect($exception)
            ->toBeInstanceOf(HashException::class)
            ->getMessage()->toContain('Hash verification failed');
    });

    it('creates chain broken exception', function () {
        $exception = HashException::chainBroken('INV-001');

        expect($exception)
            ->toBeInstanceOf(HashException::class)
            ->getMessage()->toContain('Blockchain chain is broken')
            ->getMessage()->toContain('INV-001');
    });

    it('creates cannot generate hash exception', function () {
        $exception = HashException::cannotGenerateHash('Missing data');

        expect($exception)
            ->toBeInstanceOf(HashException::class)
            ->getMessage()->toContain('Cannot generate hash')
            ->getMessage()->toContain('Missing data');
    });
});

// ========================================
// ValidationException Tests
// ========================================

describe('ValidationException', function () {
    it('creates validation exception with errors', function () {
        $errors = ['field1' => 'error1', 'field2' => 'error2'];
        $exception = ValidationException::withErrors($errors);

        expect($exception)
            ->toBeInstanceOf(ValidationException::class)
            ->toBeInstanceOf(VerifactuException::class)
            ->getMessage()->toContain('Validation failed')
            ->getErrors()->toBe($errors);
    });

    it('creates invalid xml exception', function () {
        $exception = ValidationException::invalidXml('Malformed structure');

        expect($exception)
            ->toBeInstanceOf(ValidationException::class)
            ->getMessage()->toContain('Invalid XML')
            ->getMessage()->toContain('Malformed structure');
    });

    it('creates xsd validation failed exception', function () {
        $errors = ['Error 1', 'Error 2'];
        $exception = ValidationException::xsdValidationFailed($errors);

        expect($exception)
            ->toBeInstanceOf(ValidationException::class)
            ->getErrors()->toBe($errors);
    });

    it('creates invalid invoice data exception', function () {
        $exception = ValidationException::invalidInvoiceData('total_amount', 'must be positive');

        expect($exception)
            ->toBeInstanceOf(ValidationException::class)
            ->getMessage()->toContain("Invalid invoice data for field 'total_amount'")
            ->getMessage()->toContain('must be positive');
    });

    it('returns empty errors array by default', function () {
        $exception = ValidationException::make('Simple validation error');

        expect($exception->getErrors())->toBe([]);
    });
});

// ========================================
// AeatRejectionException Tests
// ========================================

describe('AeatRejectionException', function () {
    it('creates rejection exception with errors', function () {
        $errors = [
            ['code' => 'ERR001', 'description' => 'Invalid NIF'],
            ['code' => 'ERR002', 'description' => 'Missing field'],
        ];
        $exception = AeatRejectionException::withErrors($errors);

        expect($exception)
            ->toBeInstanceOf(AeatRejectionException::class)
            ->toBeInstanceOf(AeatException::class)
            ->getMessage()->toContain('Invoice rejected by AEAT')
            ->getAeatErrors()->toBe($errors);
    });

    it('creates non-retryable rejection by default', function () {
        $errors = [['code' => 'ERR001', 'description' => 'Fatal error']];
        $exception = AeatRejectionException::withErrors($errors);

        expect($exception->isRetryable())->toBeFalse();
    });

    it('creates retryable rejection when specified', function () {
        $errors = [['code' => 'ERR002', 'description' => 'Temporary error']];
        $exception = AeatRejectionException::withErrors($errors, true);

        expect($exception->isRetryable())->toBeTrue();
    });

    it('includes error descriptions in message', function () {
        $errors = [
            ['description' => 'First error'],
            ['description' => 'Second error'],
        ];
        $exception = AeatRejectionException::withErrors($errors);

        expect($exception->getMessage())
            ->toContain('First error')
            ->toContain('Second error');
    });
});

// ========================================
// ConfigurationException Tests
// ========================================

describe('ConfigurationException', function () {
    it('creates missing certificate exception', function () {
        $exception = ConfigurationException::missingCertificate();

        expect($exception)
            ->toBeInstanceOf(ConfigurationException::class)
            ->toBeInstanceOf(VerifactuException::class)
            ->getMessage()->toContain('Certificate path is not configured')
            ->getMessage()->toContain('VERIFACTU_CERT_PATH');
    });

    it('creates missing certificate password exception', function () {
        $exception = ConfigurationException::missingCertificatePassword();

        expect($exception)
            ->toBeInstanceOf(ConfigurationException::class)
            ->getMessage()->toContain('Certificate password is not configured')
            ->getMessage()->toContain('VERIFACTU_CERT_PASSWORD');
    });

    it('creates invalid mode exception', function () {
        $exception = ConfigurationException::invalidMode('invalid');

        expect($exception)
            ->toBeInstanceOf(ConfigurationException::class)
            ->getMessage()->toContain("Invalid mode 'invalid'")
            ->getMessage()->toContain('native')
            ->getMessage()->toContain('custom');
    });

    it('creates invalid environment exception', function () {
        $exception = ConfigurationException::invalidEnvironment('staging');

        expect($exception)
            ->toBeInstanceOf(ConfigurationException::class)
            ->getMessage()->toContain("Invalid environment 'staging'")
            ->getMessage()->toContain('production')
            ->getMessage()->toContain('sandbox');
    });

    it('creates model does not implement contract exception', function () {
        $exception = ConfigurationException::modelDoesNotImplementContract(
            'App\\Models\\CustomInvoice',
            'AichaDigital\\LaraVerifactu\\Contracts\\InvoiceContract'
        );

        expect($exception)
            ->toBeInstanceOf(ConfigurationException::class)
            ->getMessage()->toContain('App\\Models\\CustomInvoice')
            ->getMessage()->toContain('does not implement')
            ->getMessage()->toContain('InvoiceContract');
    });
});

// ========================================
// XmlException Tests
// ========================================

describe('XmlException', function () {
    it('creates cannot build xml exception', function () {
        $exception = XmlException::cannotBuildXml('Missing required element');

        expect($exception)
            ->toBeInstanceOf(XmlException::class)
            ->toBeInstanceOf(VerifactuException::class)
            ->getMessage()->toContain('Cannot build XML')
            ->getMessage()->toContain('Missing required element');
    });

    it('creates cannot parse xml exception', function () {
        $exception = XmlException::cannotParseXml('Invalid syntax at line 10');

        expect($exception)
            ->toBeInstanceOf(XmlException::class)
            ->getMessage()->toContain('Cannot parse XML')
            ->getMessage()->toContain('Invalid syntax at line 10');
    });

    it('creates xsd schema not found exception', function () {
        $exception = XmlException::xsdSchemaNotFound('/path/to/schema.xsd');

        expect($exception)
            ->toBeInstanceOf(XmlException::class)
            ->getMessage()->toContain('XSD schema not found')
            ->getMessage()->toContain('/path/to/schema.xsd');
    });

    it('creates invalid xsd schema exception', function () {
        $exception = XmlException::invalidXsdSchema('Schema has circular references');

        expect($exception)
            ->toBeInstanceOf(XmlException::class)
            ->getMessage()->toContain('Invalid XSD schema')
            ->getMessage()->toContain('Schema has circular references');
    });
});
