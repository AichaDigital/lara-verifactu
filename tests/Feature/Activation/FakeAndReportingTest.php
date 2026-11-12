<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Exceptions\ConfigurationException;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuDisabledException;
use AichaDigital\LaraVerifactu\Facades\Verifactu;
use AichaDigital\LaraVerifactu\Jobs\ProcessInvoiceRegistrationJob;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Support\LazyAeatClient;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\ActivationHarness;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\FakeSoapTransport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;

/*
 * AID-1907 §5, §6, §8 and the unchanged 1.x series-order guard (§4.5).
 */

beforeEach(function () {
    ActivationHarness::configureIssuer();
});

// ── Fake mode (§6) ──────────────────────────────────────────────────────

it('default fake ignores the switch: writes record and enabled() is true', function () {
    config()->set('verifactu.enabled', false);
    Verifactu::fake();
    $invoice = Invoice::factory()->create();

    expect(Verifactu::enabled())->toBeTrue();

    Verifactu::register($invoice);
    Verifactu::assertRegistered($invoice);
});

it('fake(honourSwitch: true) reports the switch and refuses every write when off, including an empty batch', function (Closure $write) {
    config()->set('verifactu.enabled', false);
    Verifactu::fake(honourSwitch: true);

    expect(Verifactu::enabled())->toBeFalse()
        ->and($write)->toThrow(VerifactuDisabledException::class);
})->with([
    'register' => [fn () => Verifactu::register(Invoice::factory()->create())],
    'cancel' => [fn () => Verifactu::cancel(Invoice::factory()->create())],
    'amendRejected' => [fn () => Verifactu::amendRejected(new Registry, Invoice::factory()->create())],
    'sendBatch([])' => [fn () => Verifactu::sendBatch([])],
]);

it('fake(honourSwitch: true) records as usual when on', function () {
    config()->set('verifactu.enabled', true);
    Verifactu::fake(honourSwitch: true);
    $invoice = Invoice::factory()->create();

    expect(Verifactu::enabled())->toBeTrue();

    Verifactu::register($invoice);
    Verifactu::assertRegistered($invoice);
});

it('intercepts amendRejected in fake mode as a registration of the corrected invoice', function () {
    Verifactu::fake();
    $corrected = Invoice::factory()->create();

    $registry = Verifactu::amendRejected(new Registry, $corrected);

    expect(Registry::withTrashed()->count())->toBe(0)
        ->and($registry->registry_number)->toBe('FAKE-' . $corrected->getInvoiceNumber());
    Verifactu::assertRegistered($corrected);
});

it('answers enabled() from the switch outside fake mode', function (bool $value) {
    config()->set('verifactu.enabled', $value);

    expect(Verifactu::enabled())->toBe($value);
})->with([true, false]);

// The §7 invalid-value matrix, driven through every public surface (gate
// round 1, finding 6). The parser itself is covered in ActivationSwitchTest.
dataset('invalid activation values', [
    'null' => [null],
    'empty string' => [''],
    'typo' => ['ture'],
    'int 2' => [2],
    'array' => [[true]],
]);

it('throws ConfigurationException from enabled() on an invalid value, never reading it as off or on', function (mixed $value) {
    config()->set('verifactu.enabled', $value);

    expect(fn () => Verifactu::enabled())->toThrow(ConfigurationException::class);
})->with('invalid activation values');

it('refuses a facade write with ConfigurationException on an invalid value, creating nothing', function (mixed $value) {
    $invoice = Invoice::factory()->create();
    config()->set('verifactu.enabled', $value);

    expect(fn () => Verifactu::register($invoice))->toThrow(ConfigurationException::class)
        ->and(Registry::withTrashed()->count())->toBe(0);
})->with('invalid activation values');

it('refuses a disabled sendBatch before evaluating the iterable', function () {
    config()->set('verifactu.enabled', false);
    $evaluated = false;

    $invoices = (function () use (&$evaluated): Generator {
        $evaluated = true;

        yield Invoice::factory()->create();
    })();

    expect(fn () => Verifactu::sendBatch($invoices))->toThrow(VerifactuDisabledException::class)
        ->and($evaluated)->toBeFalse();
});

// ── Visible warnings (§8) ───────────────────────────────────────────────

/**
 * The value printed in the Enabled row of the verifactu:status settings table.
 */
function statusEnabledRow(): string
{
    Artisan::call('verifactu:status');
    preg_match('/\|\s*Enabled\s*\|\s*(\S+)\s*\|/', Artisan::output(), $match);

    return $match[1] ?? '';
}

it('verifactu:status warns when disabled and reports an invalid value instead of throwing', function (mixed $value, string $expected) {
    config()->set('verifactu.enabled', $value);

    $this->artisan('verifactu:status')
        ->expectsOutputToContain($expected)
        ->assertSuccessful();
})->with([
    'off' => [false, 'VeriFACTU is disabled'],
    'invalid' => ['ture', 'Invalid value'],
]);

it('verifactu:status prints the effective switch in the Enabled row', function (mixed $value, string $row) {
    config()->set('verifactu.enabled', $value);

    expect(statusEnabledRow())->toBe($row);
})->with([
    'on' => [true, 'Yes'],
    'off' => [false, 'No'],
    'invalid' => ['ture', 'Invalid'],
]);

it('verifactu:status and verifactu:install report every invalid value instead of throwing', function (mixed $value) {
    config()->set('verifactu.enabled', $value);

    $this->artisan('verifactu:status')->expectsOutputToContain('Invalid value')->assertSuccessful();
    $this->artisan('verifactu:install', ['--no-migrate' => true])->expectsOutputToContain('Invalid value')->assertSuccessful();
})->with('invalid activation values');

it('verifactu:install --no-migrate warns when disabled and reads aeat.environment', function () {
    config()->set('verifactu.enabled', false);
    config()->set('verifactu.aeat.environment', 'sandbox');

    $this->artisan('verifactu:install', ['--no-migrate' => true])
        ->expectsOutputToContain('VeriFACTU is disabled')
        ->expectsOutputToContain('Environment: sandbox')
        ->assertSuccessful();
});

it('verifactu:install reports an invalid switch instead of throwing', function () {
    config()->set('verifactu.enabled', '');

    $this->artisan('verifactu:install', ['--no-migrate' => true])
        ->expectsOutputToContain('Invalid value')
        ->assertSuccessful();
});

// ── Series order, unchanged in 1.x (§4.5; G7 moved to AID-1929) ─────────

it('regression pin: an invoice left unregistered while disabled blocks the native queued path, and the facade path registers', function () {
    $transport = new FakeSoapTransport;
    ActivationHarness::bindRegistrar(
        ActivationHarness::registryManager(),
        LazyAeatClient::eager(fn () => FakeSoapTransport::clientWith($transport)),
    );

    $offPeriod = Invoice::factory()
        ->withSerieAndNumber('FA', 'FAC-2026-000001')
        ->issuedAt(Carbon::create(2026, 3, 1, 12))
        ->create();
    $next = Invoice::factory()
        ->withSerieAndNumber('FA', 'FAC-2026-000002')
        ->issuedAt(Carbon::create(2026, 3, 2, 12))
        ->create();

    config()->set('verifactu.enabled', false);
    expect(fn () => Verifactu::register($offPeriod))->toThrow(VerifactuDisabledException::class);

    config()->set('verifactu.enabled', true);

    expect(fn () => ProcessInvoiceRegistrationJob::dispatchSync($next->id))
        ->toThrow(RuntimeException::class, 'Sequential order must be maintained');
    expect(Registry::query()->where('invoice_id', $next->id)->exists())->toBeFalse();

    $registry = Verifactu::register($next);
    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::SENT);
});
