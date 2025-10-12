<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Jobs\ProcessInvoiceRegistrationJob;
use AichaDigital\LaraVerifactu\Jobs\RetryFailedRegistriesJob;
use AichaDigital\LaraVerifactu\Jobs\SubmitRegistryToAeatJob;
use AichaDigital\LaraVerifactu\Jobs\VerifyBlockchainIntegrityJob;
use Illuminate\Support\Facades\Queue;

it('can dispatch process invoice registration job', function () {
    Queue::fake();

    ProcessInvoiceRegistrationJob::dispatch(1);

    Queue::assertPushed(ProcessInvoiceRegistrationJob::class, function ($job) {
        return $job->invoiceId === 1;
    });
});

it('can dispatch submit registry to aeat job', function () {
    Queue::fake();

    SubmitRegistryToAeatJob::dispatch(1);

    Queue::assertPushed(SubmitRegistryToAeatJob::class, function ($job) {
        return $job->registryId === 1;
    });
});

it('can dispatch retry failed registries job', function () {
    Queue::fake();

    RetryFailedRegistriesJob::dispatch();

    Queue::assertPushed(RetryFailedRegistriesJob::class);
});

it('can dispatch verify blockchain integrity job', function () {
    Queue::fake();

    VerifyBlockchainIntegrityJob::dispatch();

    Queue::assertPushed(VerifyBlockchainIntegrityJob::class);
});

it('process invoice registration job has correct configuration', function () {
    $job = new ProcessInvoiceRegistrationJob(1);

    expect($job->tries)->toBeGreaterThan(0)
        ->and($job->timeout)->toBeGreaterThan(0)
        ->and($job->invoiceId)->toBe(1);
});

it('submit registry job has correct configuration', function () {
    $job = new SubmitRegistryToAeatJob(1);

    expect($job->tries)->toBeGreaterThan(0)
        ->and($job->timeout)->toBeGreaterThan(0)
        ->and($job->registryId)->toBe(1);
});

it('retry failed job has correct configuration', function () {
    $job = new RetryFailedRegistriesJob();

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBeGreaterThan(0)
        ->and($job->maxAttempts)->toBe(3)
        ->and($job->limit)->toBe(50);
});

it('verify blockchain job has correct configuration', function () {
    $job = new VerifyBlockchainIntegrityJob();

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBeGreaterThan(0);
});

