<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use AichaDigital\LaraVerifactu\Verifactu;

beforeEach(function () {
    $this->hashGenerator = Mockery::mock(HashGeneratorContract::class);
    $this->qrGenerator = Mockery::mock(QrGeneratorContract::class);
    $this->xmlBuilder = Mockery::mock(XmlBuilderContract::class);
    $this->aeatClient = Mockery::mock(AeatClientContract::class);

    $this->verifactu = new Verifactu(
        $this->hashGenerator,
        $this->qrGenerator,
        $this->xmlBuilder,
        $this->aeatClient
    );
});

// ========================================
// Fake Mode Tests
// ========================================

describe('Fake Mode', function () {
    it('can enable fake mode', function () {
        $this->verifactu->fake();

        $invoice = Mockery::mock(InvoiceContract::class);
        $response = $this->verifactu->register($invoice);

        expect($response)->toBeInstanceOf(AeatResponse::class)
            ->and($response->isSuccess())->toBeTrue();
    });

    it('returns success response for register in fake mode', function () {
        $this->verifactu->fake();
        $invoice = Mockery::mock(InvoiceContract::class);

        $response = $this->verifactu->register($invoice);

        expect($response->isSuccess())->toBeTrue();
    });

    it('returns success response for cancel in fake mode', function () {
        $this->verifactu->fake();

        $response = $this->verifactu->cancel('REG-001');

        expect($response->isSuccess())->toBeTrue();
    });

    it('returns empty collection for sendBatch in fake mode', function () {
        $this->verifactu->fake();

        $invoices = collect([
            Mockery::mock(InvoiceContract::class),
            Mockery::mock(InvoiceContract::class),
        ]);

        $result = $this->verifactu->sendBatch($invoices);

        expect($result)->toBeEmpty();
    });

    it('returns success response for status in fake mode', function () {
        $this->verifactu->fake();
        $invoice = Mockery::mock(InvoiceContract::class);

        $response = $this->verifactu->status($invoice);

        expect($response->isSuccess())->toBeTrue();
    });

    it('returns fake qr code in fake mode', function () {
        $this->verifactu->fake();
        $invoice = Mockery::mock(InvoiceContract::class);

        $qr = $this->verifactu->qr($invoice);

        expect($qr)->toBe('fake-qr-code');
    });

    it('returns true for validateChain in fake mode', function () {
        $this->verifactu->fake();
        $invoice = Mockery::mock(InvoiceContract::class);

        $result = $this->verifactu->validateChain($invoice);

        expect($result)->toBeTrue();
    });
});

// ========================================
// Non-Fake Mode Tests
// ========================================

describe('Non-Fake Mode', function () {
    it('throws not implemented for register when not in fake mode', function () {
        $invoice = Mockery::mock(InvoiceContract::class);

        $this->verifactu->register($invoice);
    })->throws(\RuntimeException::class, 'Not implemented yet');

    it('throws not implemented for cancel when not in fake mode', function () {
        $this->verifactu->cancel('REG-001');
    })->throws(\RuntimeException::class, 'Not implemented yet');

    it('throws not implemented for sendBatch when not in fake mode', function () {
        $invoices = collect([Mockery::mock(InvoiceContract::class)]);

        $this->verifactu->sendBatch($invoices);
    })->throws(\RuntimeException::class, 'Not implemented yet');

    it('throws not implemented for status when not in fake mode', function () {
        $invoice = Mockery::mock(InvoiceContract::class);

        $this->verifactu->status($invoice);
    })->throws(\RuntimeException::class, 'Not implemented yet');

    it('throws not implemented for qr when not in fake mode', function () {
        $invoice = Mockery::mock(InvoiceContract::class);

        $this->verifactu->qr($invoice);
    })->throws(\RuntimeException::class, 'Not implemented yet');

    it('throws not implemented for validateChain when not in fake mode', function () {
        $invoice = Mockery::mock(InvoiceContract::class);

        $this->verifactu->validateChain($invoice);
    })->throws(\RuntimeException::class, 'Not implemented yet');
});

// ========================================
// Assertion Tests
// ========================================

describe('Test Assertions', function () {
    it('throws exception for assertRegistered when not in fake mode', function () {
        $invoice = Mockery::mock(InvoiceContract::class);

        $this->verifactu->assertRegistered($invoice);
    })->throws(\RuntimeException::class, 'Cannot assert when not in fake mode');

    it('throws exception for assertNotSent when not in fake mode', function () {
        $invoice = Mockery::mock(InvoiceContract::class);

        $this->verifactu->assertNotSent($invoice);
    })->throws(\RuntimeException::class, 'Cannot assert when not in fake mode');

    it('can call assertRegistered in fake mode without exception', function () {
        $this->verifactu->fake();
        $invoice = Mockery::mock(InvoiceContract::class);

        // Should not throw
        $this->verifactu->assertRegistered($invoice);

        expect(true)->toBeTrue();
    });

    it('can call assertNotSent in fake mode without exception', function () {
        $this->verifactu->fake();
        $invoice = Mockery::mock(InvoiceContract::class);

        // Should not throw
        $this->verifactu->assertNotSent($invoice);

        expect(true)->toBeTrue();
    });
});
