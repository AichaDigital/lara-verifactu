<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\InvoiceRegisteredEvent;
use AichaDigital\LaraVerifactu\Events\RegistryCreatedEvent;
use AichaDigital\LaraVerifactu\Events\RegistryFailedEvent;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuDisabledException;
use AichaDigital\LaraVerifactu\Facades\Verifactu;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\AeatClient;
use AichaDigital\LaraVerifactu\Support\LazyAeatClient;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\ActivationHarness;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\FakeSoapTransport;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\ScriptedActivation;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * AID-1907 §4.1: while disabled, no caller reaches a fiscal effect. Entry
 * guards refuse early and clearly; effect guards are the authority.
 */

beforeEach(function () {
    ActivationHarness::configureIssuer();

    $this->manager = ActivationHarness::registryManager();
    $this->client = Mockery::mock(AeatClientContract::class);
    $this->registrar = ActivationHarness::bindRegistrar($this->manager, $this->client);

    // A seeded baseline row, so "zero additional rows" is not "zero rows".
    $this->seeded = $this->manager->createRegistry(Invoice::factory()->create());
    $this->baselineCount = Registry::withTrashed()->count();
});

function disabledGuardsTransactionCounter(): object
{
    $counter = new class
    {
        public int $begun = 0;
    };

    Event::listen(TransactionBeginning::class, function () use ($counter): void {
        $counter->begun++;
    });

    return $counter;
}

it('refuses every facade write with VerifactuDisabledException, creating nothing and sending nothing', function (Closure $write) {
    config()->set('verifactu.enabled', false);
    Event::fake([RegistryCreatedEvent::class, InvoiceRegisteredEvent::class, RegistryFailedEvent::class]);
    $this->client->shouldNotReceive('sendRegistration', 'sendBatch');

    expect(fn () => $write($this))->toThrow(VerifactuDisabledException::class);

    expect(Registry::withTrashed()->count())->toBe($this->baselineCount);
    Event::assertNotDispatched(RegistryCreatedEvent::class);
    Event::assertNotDispatched(InvoiceRegisteredEvent::class);
    Event::assertNotDispatched(RegistryFailedEvent::class);
})->with([
    'register' => [fn ($t) => Verifactu::register(Invoice::factory()->create())],
    'cancel' => [fn ($t) => Verifactu::cancel(Invoice::find($t->seeded->invoice_id))],
    'amendRejected' => [fn ($t) => Verifactu::amendRejected($t->seeded, Invoice::factory()->create())],
    'sendBatch' => [fn ($t) => Verifactu::sendBatch([Invoice::factory()->create(), Invoice::factory()->create()])],
    'sendBatch (empty)' => [fn ($t) => Verifactu::sendBatch([])],
]);

it('(a) entry guard: register() refuses before any transaction begins', function () {
    config()->set('verifactu.enabled', false);
    $counter = disabledGuardsTransactionCounter();

    expect(fn () => $this->registrar->register(Invoice::factory()->create()))
        ->toThrow(VerifactuDisabledException::class);

    expect($counter->begun)->toBe(0)
        ->and(Registry::withTrashed()->count())->toBe($this->baselineCount);
});

it('(b) effect guard: with the entry guard passed, the manager still refuses before its own transaction and lock', function () {
    // The entry guard answers on, every later read off: this is the entry
    // guard "removed", leaving the manager's effect guard to do the work.
    ActivationHarness::script(new ScriptedActivation([true], false));
    Event::fake([RegistryCreatedEvent::class]);
    $counter = disabledGuardsTransactionCounter();
    $this->client->shouldNotReceive('sendRegistration');

    expect(fn () => $this->registrar->register(Invoice::factory()->create()))
        ->toThrow(VerifactuDisabledException::class);

    // Exactly the registrar's own outer transaction. Killed mutation: moving
    // the manager's guard inside its transaction callback (a second one).
    expect($counter->begun)->toBe(1)
        ->and(Registry::withTrashed()->count())->toBe($this->baselineCount);
    Event::assertNotDispatched(RegistryCreatedEvent::class);
});

it('refuses direct calls to the manager creation methods', function (string $method) {
    config()->set('verifactu.enabled', false);
    $counter = disabledGuardsTransactionCounter();

    expect(fn () => $this->manager->{$method}(Invoice::factory()->create()))
        ->toThrow(VerifactuDisabledException::class);

    expect($counter->begun)->toBe(0)
        ->and(Registry::withTrashed()->count())->toBe($this->baselineCount);
})->with(['createRegistry', 'createCancellationRegistry']);

it('refuses direct sends through the concrete client and the proxy, without touching the wire', function () {
    config()->set('verifactu.enabled', false);
    $transport = new FakeSoapTransport;
    $concrete = FakeSoapTransport::clientWith($transport);
    $built = 0;
    $proxy = LazyAeatClient::deferred(function () use (&$built, $concrete): AeatClient {
        $built++;

        return $concrete;
    });

    expect(fn () => $concrete->sendRegistration($this->seeded))->toThrow(VerifactuDisabledException::class)
        ->and(fn () => $concrete->sendBatch(collect([$this->seeded])))->toThrow(VerifactuDisabledException::class)
        ->and(fn () => $proxy->sendRegistration($this->seeded))->toThrow(VerifactuDisabledException::class)
        ->and(fn () => $proxy->sendBatch(collect([$this->seeded])))->toThrow(VerifactuDisabledException::class);

    expect($transport->calls)->toBe(0)
        ->and($built)->toBe(0);
});

it('refuses cancel() and amendRejected() at entry', function (string $operation) {
    config()->set('verifactu.enabled', false);
    $counter = disabledGuardsTransactionCounter();

    $call = $operation === 'cancel'
        ? fn () => $this->registrar->cancel(Invoice::find($this->seeded->invoice_id))
        : fn () => $this->registrar->amendRejected($this->seeded, Invoice::factory()->create());

    expect($call)->toThrow(VerifactuDisabledException::class, $operation);
    expect($counter->begun)->toBe(0);
})->with(['cancel', 'amendRejected']);

it('refuses batchRegister() before the loop instead of reporting failed: N', function () {
    config()->set('verifactu.enabled', false);

    expect(fn () => $this->registrar->batchRegister([Invoice::factory()->create(), Invoice::factory()->create()]))
        ->toThrow(VerifactuDisabledException::class, 'batchRegister');

    expect(Registry::withTrashed()->count())->toBe($this->baselineCount);
});

it('refuses submitToAeat() with a flagged submission refusal, leaving the registry untouched', function () {
    config()->set('verifactu.enabled', false);
    Event::fake([RegistryFailedEvent::class]);
    $this->client->shouldNotReceive('sendRegistration');
    $before = ActivationHarness::registrySnapshot();

    try {
        $this->registrar->submitToAeat($this->seeded);
        $this->fail('submitToAeat() did not refuse');
    } catch (VerifactuDisabledException $e) {
        expect($e->isSubmissionRefusal())->toBeTrue()
            ->and($e->registryId())->toBe((string) $this->seeded->id);
    }

    expect(ActivationHarness::registrySnapshot())->toBe($before)
        ->and($this->seeded->fresh()->status)->toBe(RegistryStatusEnum::PENDING);
    Event::assertNotDispatched(RegistryFailedEvent::class);
});

it('gives the activation reason, not the AID-725 refusal, inside a caller-opened transaction', function () {
    config()->set('verifactu.enabled', false);

    // One level above the harness baseline: AID-725 would refuse here.
    expect(fn () => DB::transaction(fn () => $this->registrar->register(Invoice::factory()->create())))
        ->toThrow(VerifactuDisabledException::class);
});

it('verifactu:register exits FAILURE with the disabled message, also for --all with nothing to register', function (array $arguments) {
    config()->set('verifactu.enabled', false);
    $this->client->shouldNotReceive('sendRegistration');

    $this->artisan('verifactu:register', $arguments)
        ->expectsOutputToContain('VeriFACTU is disabled')
        ->assertFailed();

    expect(Registry::withTrashed()->count())->toBe($this->baselineCount);
})->with([
    'single invoice' => [['invoice' => 1]],
    '--all' => [['--all' => true]],
]);
