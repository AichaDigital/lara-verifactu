<?php

declare(strict_types=1);

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

