<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Exceptions\ConfigurationException;
use AichaDigital\LaraVerifactu\Jobs\ProcessInvoiceRegistrationJob;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Support\OverlapLockStore;
use Illuminate\Support\Facades\Cache;

/**
 * The overlap locks are only worth what the consumer's cache store is worth.
 *
 * `verifactu:retry-failed` takes a cache lock so two overlapping runs cannot
 * hand the same record to both (AID-731), and ProcessInvoiceRegistrationJob
 * takes one to keep submissions sequential. Both guarantees are conditional on
 * a store shared by the processes that could overlap — a condition the package
 * neither stated nor checked.
 *
 * Measured, not assumed:
 *  - `null` returns NoLock, whose acquire() returns TRUE unconditionally. Every
 *    overlap check passes. The protection is not weak, it is absent and silent.
 *  - `array` returns a real ArrayLock, but it lives in the memory of one
 *    process, so two workers never see each other's.
 */
it('refuses to take an overlap lock on a store that cannot share it', function (string $driver) {
    config()->set('cache.default', $driver);

    expect(fn () => OverlapLockStore::assertUsable('verifactu:test'))
        ->toThrow(ConfigurationException::class);
})->with(['array', 'null']);

it('accepts a store that other processes can see', function () {
    config()->set('cache.default', 'file');

    OverlapLockStore::assertUsable('verifactu:test');
})->throwsNoExceptions();

it('names the store and the setting in the failure, so the fix is obvious', function () {
    config()->set('cache.default', 'null');

    try {
        OverlapLockStore::assertUsable('verifactu:retry-failed');
        $this->fail('expected a ConfigurationException');
    } catch (ConfigurationException $e) {
        expect($e->getMessage())->toContain('null')
            ->and($e->getMessage())->toContain('CACHE_STORE');
    }
});

it('stops verifactu:retry-failed before it pretends to hold a lock', function () {
    config()->set('cache.default', 'null');

    // It throws rather than returning FAILURE, deliberately: this is not a run
    // that went wrong, it is a configuration that cannot deliver what the
    // command promises. The scheduler surfaces it as a failed task, which is
    // what an operator needs to see.
    expect(fn () => $this->artisan('verifactu:retry-failed')->run())
        ->toThrow(ConfigurationException::class);
});

it('stops the registration job before it pretends to hold a lock', function () {
    config()->set('cache.default', 'null');
    $invoice = Invoice::factory()->create();

    expect(fn () => ProcessInvoiceRegistrationJob::dispatchSync(
        $invoice->id,
        false,
    ))->toThrow(ConfigurationException::class);
});

it('leaves the lock working on an acceptable store', function () {
    // Regression guard: the check must not become the thing that breaks the
    // lock it is defending.
    config()->set('cache.default', 'file');

    $lock = Cache::lock('verifactu:test-acceptable', 5);

    expect($lock->get())->toBeTrue();

    $lock->release();
});
