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
use Illuminate\Support\Facades\Event;

it('invoice registered event can be dispatched', function () {
    Event::fake();

    Event::assertListening(
        InvoiceRegisteredEvent::class,
        LogInvoiceRegistration::class
    );
});

it('registry created event can be dispatched', function () {
    Event::fake();

    Event::assertListening(
        RegistryCreatedEvent::class,
        LogRegistryCreation::class
    );
});

it('registry submitted event can be dispatched', function () {
    Event::fake();

    Event::assertListening(
        RegistrySubmittedEvent::class,
        LogRegistrySubmission::class
    );
});

it('registry failed event can be dispatched', function () {
    Event::fake();

    Event::assertListening(
        RegistryFailedEvent::class,
        LogRegistryFailure::class
    );
});

it('blockchain verified event can be dispatched', function () {
    Event::fake();

    Event::assertListening(
        BlockchainVerifiedEvent::class,
        LogBlockchainVerification::class
    );
});

it('blockchain verified event contains result data', function () {
    $result = [
        'valid' => true,
        'errors' => [],
    ];

    $event = new BlockchainVerifiedEvent($result);

    expect($event->result)->toBe($result)
        ->and($event->result['valid'])->toBeTrue()
        ->and($event->result['errors'])->toBeArray();
});

// ========================================
// Event Property Tests
// ========================================

it('registry created event contains registry and invoice', function () {
    $registry = Mockery::mock(RegistryContract::class);
    $invoice = Mockery::mock(InvoiceContract::class);

    $event = new RegistryCreatedEvent($registry, $invoice);

    expect($event->registry)->toBe($registry)
        ->and($event->invoice)->toBe($invoice);
});

it('registry submitted event contains registry and response', function () {
    $registry = Mockery::mock(RegistryContract::class);
    $response = AeatResponse::success(['csv' => 'ABC123']);

    $event = new RegistrySubmittedEvent($registry, $response);

    expect($event->registry)->toBe($registry)
        ->and($event->response)->toBe($response)
        ->and($event->response->isSuccess())->toBeTrue();
});

it('registry failed event contains registry error and attempt', function () {
    $registry = Mockery::mock(RegistryContract::class);
    $error = 'Connection timeout';
    $attempt = 3;

    $event = new RegistryFailedEvent($registry, $error, $attempt);

    expect($event->registry)->toBe($registry)
        ->and($event->error)->toBe($error)
        ->and($event->attempt)->toBe($attempt);
});

it('invoice registered event contains invoice registry and submission flag', function () {
    $invoice = Mockery::mock(InvoiceContract::class);
    $registry = Mockery::mock(RegistryContract::class);

    $eventNotSubmitted = new InvoiceRegisteredEvent($invoice, $registry, false);
    $eventSubmitted = new InvoiceRegisteredEvent($invoice, $registry, true);

    expect($eventNotSubmitted->invoice)->toBe($invoice)
        ->and($eventNotSubmitted->registry)->toBe($registry)
        ->and($eventNotSubmitted->submittedToAeat)->toBeFalse()
        ->and($eventSubmitted->submittedToAeat)->toBeTrue();
});

it('blockchain verified event handles failed verification', function () {
    $result = [
        'valid' => false,
        'errors' => ['Hash mismatch at invoice INV-001', 'Chain broken'],
    ];

    $event = new BlockchainVerifiedEvent($result);

    expect($event->result['valid'])->toBeFalse()
        ->and($event->result['errors'])->toHaveCount(2)
        ->and($event->result['errors'][0])->toContain('Hash mismatch');
});
