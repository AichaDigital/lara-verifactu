<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Events\BlockchainVerifiedEvent;
use AichaDigital\LaraVerifactu\Events\InvoiceRegisteredEvent;
use AichaDigital\LaraVerifactu\Events\RegistryCreatedEvent;
use AichaDigital\LaraVerifactu\Events\RegistryFailedEvent;
use AichaDigital\LaraVerifactu\Events\RegistrySubmittedEvent;
use AichaDigital\LaraVerifactu\Listeners\LogBlockchainVerification;
use AichaDigital\LaraVerifactu\Listeners\LogInvoiceRegistration;
use AichaDigital\LaraVerifactu\Listeners\LogRegistryCreation;
use AichaDigital\LaraVerifactu\Listeners\LogRegistryFailure;
use AichaDigital\LaraVerifactu\Listeners\LogRegistrySubmission;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

// ========================================
// LogRegistryCreation Tests
// ========================================

describe('LogRegistryCreation', function () {
    it('logs registry creation with details', function () {
        $loggerMock = Mockery::mock(LoggerInterface::class);
        $loggerMock->shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'New registry created'
                    && isset($context['registry_number'])
                    && isset($context['registry_hash'])
                    && isset($context['invoice_number'])
                    && isset($context['invoice_serie'])
                    && isset($context['previous_hash']);
            });

        Log::shouldReceive('channel')
            ->once()
            ->andReturn($loggerMock);

        $registry = Mockery::mock(RegistryContract::class);
        $registry->shouldReceive('getRegistryNumber')->andReturn('REG-001');
        $registry->shouldReceive('getHash')->andReturn(str_repeat('a', 64));
        $registry->shouldReceive('getPreviousHash')->andReturn(null);

        $invoice = Mockery::mock(InvoiceContract::class);
        $invoice->shouldReceive('getNumber')->andReturn('INV-001');
        $invoice->shouldReceive('getSerie')->andReturn('A');

        $event = new RegistryCreatedEvent($registry, $invoice);
        $listener = new LogRegistryCreation;
        $listener->handle($event);
    });

    it('logs previous hash when present', function () {
        $loggerMock = Mockery::mock(LoggerInterface::class);
        $loggerMock->shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'New registry created'
                    && str_contains($context['previous_hash'], '...');
            });

        Log::shouldReceive('channel')
            ->once()
            ->andReturn($loggerMock);

        $registry = Mockery::mock(RegistryContract::class);
        $registry->shouldReceive('getRegistryNumber')->andReturn('REG-002');
        $registry->shouldReceive('getHash')->andReturn(str_repeat('b', 64));
        $registry->shouldReceive('getPreviousHash')->andReturn(str_repeat('a', 64));

        $invoice = Mockery::mock(InvoiceContract::class);
        $invoice->shouldReceive('getNumber')->andReturn('INV-002');
        $invoice->shouldReceive('getSerie')->andReturn('B');

        $event = new RegistryCreatedEvent($registry, $invoice);
        $listener = new LogRegistryCreation;
        $listener->handle($event);
    });
});

// ========================================
// LogRegistrySubmission Tests
// ========================================

describe('LogRegistrySubmission', function () {
    it('logs successful submission with csv', function () {
        $loggerMock = Mockery::mock(LoggerInterface::class);
        $loggerMock->shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Registry successfully submitted to AEAT'
                    && isset($context['registry_number'])
                    && isset($context['aeat_csv']);
            });

        Log::shouldReceive('channel')
            ->once()
            ->andReturn($loggerMock);

        $registry = Mockery::mock(RegistryContract::class);
        $registry->shouldReceive('getRegistryNumber')->andReturn('REG-001');
        $registry->shouldReceive('getHash')->andReturn(str_repeat('a', 64));

        $response = new AeatResponse(
            success: true,
            code: 'CSV123',
            message: 'OK'
        );

        $event = new RegistrySubmittedEvent($registry, $response);
        $listener = new LogRegistrySubmission;
        $listener->handle($event);
    });
});

// ========================================
// LogRegistryFailure Tests
// ========================================

describe('LogRegistryFailure', function () {
    it('logs failure with error and attempt', function () {
        $loggerMock = Mockery::mock(LoggerInterface::class);
        $loggerMock->shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Registry submission failed'
                    && $context['error'] === 'Connection timeout'
                    && $context['attempt'] === 2;
            });

        Log::shouldReceive('channel')
            ->once()
            ->andReturn($loggerMock);

        $registry = Mockery::mock(RegistryContract::class);
        $registry->shouldReceive('getRegistryNumber')->andReturn('REG-001');
        $registry->shouldReceive('getHash')->andReturn(str_repeat('a', 64));

        $event = new RegistryFailedEvent($registry, 'Connection timeout', 2);
        $listener = new LogRegistryFailure;
        $listener->handle($event);
    });
});

// ========================================
// LogInvoiceRegistration Tests
// ========================================

describe('LogInvoiceRegistration', function () {
    it('logs invoice registration with submission status', function () {
        $loggerMock = Mockery::mock(LoggerInterface::class);
        $loggerMock->shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Invoice registered in Verifactu system'
                    && $context['submitted_to_aeat'] === true;
            });

        Log::shouldReceive('channel')
            ->once()
            ->andReturn($loggerMock);

        $invoice = Mockery::mock(InvoiceContract::class);
        $invoice->id = 1;
        $invoice->shouldReceive('getNumber')->andReturn('INV-001');
        $invoice->shouldReceive('getSerie')->andReturn('A');

        $registry = Mockery::mock(RegistryContract::class);
        $registry->shouldReceive('getRegistryNumber')->andReturn('REG-001');
        $registry->shouldReceive('getHash')->andReturn(str_repeat('a', 64));

        $event = new InvoiceRegisteredEvent($invoice, $registry, true);
        $listener = new LogInvoiceRegistration;
        $listener->handle($event);
    });

    it('logs invoice registration without submission', function () {
        $loggerMock = Mockery::mock(LoggerInterface::class);
        $loggerMock->shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Invoice registered in Verifactu system'
                    && $context['submitted_to_aeat'] === false;
            });

        Log::shouldReceive('channel')
            ->once()
            ->andReturn($loggerMock);

        $invoice = Mockery::mock(InvoiceContract::class);
        $invoice->id = 2;
        $invoice->shouldReceive('getNumber')->andReturn('INV-002');
        $invoice->shouldReceive('getSerie')->andReturn('B');

        $registry = Mockery::mock(RegistryContract::class);
        $registry->shouldReceive('getRegistryNumber')->andReturn('REG-002');
        $registry->shouldReceive('getHash')->andReturn(str_repeat('b', 64));

        $event = new InvoiceRegisteredEvent($invoice, $registry, false);
        $listener = new LogInvoiceRegistration;
        $listener->handle($event);
    });
});

// ========================================
// LogBlockchainVerification Tests
// ========================================

describe('LogBlockchainVerification', function () {
    it('logs successful verification', function () {
        $loggerMock = Mockery::mock(LoggerInterface::class);
        $loggerMock->shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $level === 'info'
                    && str_contains($message, 'passed')
                    && $context['valid'] === true;
            });

        Log::shouldReceive('channel')
            ->once()
            ->andReturn($loggerMock);

        $result = ['valid' => true, 'errors' => []];
        $event = new BlockchainVerifiedEvent($result);
        $listener = new LogBlockchainVerification;
        $listener->handle($event);
    });

    it('logs failed verification with errors', function () {
        $loggerMock = Mockery::mock(LoggerInterface::class);
        $loggerMock->shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $level === 'error'
                    && str_contains($message, 'failed')
                    && $context['valid'] === false
                    && $context['error_count'] === 2;
            });

        Log::shouldReceive('channel')
            ->once()
            ->andReturn($loggerMock);

        $result = [
            'valid' => false,
            'errors' => ['Error 1', 'Error 2'],
        ];
        $event = new BlockchainVerifiedEvent($result);
        $listener = new LogBlockchainVerification;
        $listener->handle($event);
    });
});
