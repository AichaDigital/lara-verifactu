<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\ActivationContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\InvoiceRegisteredEvent;
use AichaDigital\LaraVerifactu\Events\RegistryFailedEvent;
use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuDisabledException;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Support\LazyAeatClient;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\ActivationHarness;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\FakeSoapTransport;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\ScriptedActivation;
use Illuminate\Support\Facades\Event;

/*
 * AID-1907 §4.2: the decision is per effect, taken once at the re-check of
 * submitToAeat(). Before it, a switch-off leaves the registry pending; after
 * it, the send is admitted as a whole and its answer reaches the outcome
 * writers.
 */

beforeEach(function () {
    ActivationHarness::configureIssuer();
    $this->manager = ActivationHarness::registryManager();
    $this->transport = null;
});

/**
 * @param  'eager'|'deferred'  $variant
 */
function inFlightProxy(string $variant, FakeSoapTransport $transport): LazyAeatClient
{
    $factory = fn () => FakeSoapTransport::clientWith($transport);

    return $variant === 'eager' ? LazyAeatClient::eager($factory) : LazyAeatClient::deferred($factory);
}

it('interval 1: off between the commit and the send — refused, pending, attempts untouched, recoverable', function () {
    $transport = new FakeSoapTransport;
    $registrar = ActivationHarness::bindRegistrar($this->manager, inFlightProxy('eager', $transport));

    // register() entry guard, createRegistry() effect guard: on. Re-check: off.
    $activation = ActivationHarness::script(new ScriptedActivation([true, true], false));
    Event::fake([RegistryFailedEvent::class, InvoiceRegisteredEvent::class]);

    try {
        $registrar->register(Invoice::factory()->create());
        $this->fail('register() did not refuse the send');
    } catch (VerifactuDisabledException $e) {
        expect($e)->not->toBeInstanceOf(AeatException::class)
            ->and($e->isSubmissionRefusal())->toBeTrue();
    }

    $registry = Registry::query()->latest('id')->first();
    expect($registry->status)->toBe(RegistryStatusEnum::PENDING)
        ->and($registry->submission_attempts)->toBe(0)
        ->and($transport->calls)->toBe(0)
        ->and($e->registryId())->toBe((string) $registry->id);
    Event::assertNotDispatched(RegistryFailedEvent::class);
    Event::assertNotDispatched(InvoiceRegisteredEvent::class);

    // Recovery: re-enable, opt into resending pending rows, run the sweeper.
    $activation->switchTo(true);
    config()->set('verifactu.retry.resend_pending', true);
    tamperRegistryColumns($registry->id, ['created_at' => now()->subHour()]);

    $this->artisan('verifactu:retry-failed')->assertSuccessful();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::SENT)
        ->and($transport->calls)->toBe(1);
});

it('interval 2: off after the re-check, before the client guard — the send happens', function (string $variant) {
    $registry = $this->manager->createRegistry(Invoice::factory()->create());
    $transport = new FakeSoapTransport;
    // 'concrete': the package's own AeatClient bound directly by a consumer.
    $client = $variant === 'concrete'
        ? FakeSoapTransport::clientWith($transport)
        : inFlightProxy($variant, $transport);
    $registrar = ActivationHarness::bindRegistrar($this->manager, $client);

    // The re-check answers on; every later read answers off. Killed mutations:
    // submitToAeat() calling the proxy's guarded sendRegistration(), the proxy
    // delegating to the concrete client's guarded public method, and
    // submitToAeat() calling a directly bound concrete client's guarded
    // sendRegistration() instead of sendCore() (gate round 1, finding 2).
    ActivationHarness::script(new ScriptedActivation([true], false));

    $response = $registrar->submitToAeat($registry);

    expect($response->isSuccess())->toBeTrue()
        ->and($transport->calls)->toBe(1)
        ->and($registry->fresh()->status)->toBe(RegistryStatusEnum::SENT);
})->with(['eager', 'deferred', 'concrete']);

it('interval 3: off inside the transport, after the send left — the answer reaches the outcome writers', function () {
    $registry = $this->manager->createRegistry(Invoice::factory()->create());
    $activation = ActivationHarness::script(new ScriptedActivation([], true));
    $transport = new FakeSoapTransport(function () use ($activation): void {
        $activation->switchTo(false);
    });
    $registrar = ActivationHarness::bindRegistrar($this->manager, inFlightProxy('eager', $transport));

    $registrar->submitToAeat($registry);

    expect($transport->calls)->toBe(1)
        ->and($registry->fresh()->status)->toBe(RegistryStatusEnum::SENT);
});

it('reads activation nowhere on the admitted path after the re-check', function (string $variant) {
    $registry = $this->manager->createRegistry(Invoice::factory()->create());
    $transport = new FakeSoapTransport;
    $registrar = ActivationHarness::bindRegistrar($this->manager, inFlightProxy($variant, $transport));

    $counter = new class
    {
        public int $resolutions = 0;
    };

    // Answers on once — the re-check — and, in that same call, starts
    // counting every later resolution of the contract.
    app()->instance(ActivationContract::class, new class($counter) implements ActivationContract
    {
        private bool $answered = false;

        public function __construct(private object $counter) {}

        public function isEnabled(): bool
        {
            if ($this->answered) {
                return false;
            }

            $this->answered = true;
            app()->beforeResolving(ActivationContract::class, function (): void {
                $this->counter->resolutions++;
            });

            return true;
        }
    });

    $registrar->submitToAeat($registry);

    // Killed mutation: any app(ActivationContract::class) or guard inserted in
    // sendAdmitted() or sendCore().
    expect($counter->resolutions)->toBe(0)
        ->and($transport->calls)->toBe(1);
})->with(['eager', 'deferred']);

it('admits a client batch as one effect: a switch-off mid-batch leaves every item its real answer', function (string $client) {
    $registries = collect([
        $this->manager->createRegistry(Invoice::factory()->create()),
        $this->manager->createRegistry(Invoice::factory()->create()),
        $this->manager->createRegistry(Invoice::factory()->create()),
    ]);
    $activation = ActivationHarness::script(new ScriptedActivation([], true));
    $transport = new FakeSoapTransport(function (int $call) use ($activation): void {
        if ($call === 1) {
            $activation->switchTo(false);
        }
    });
    $target = $client === 'concrete' ? FakeSoapTransport::clientWith($transport) : inFlightProxy('eager', $transport);

    // Killed mutation: the batch loop calling the guarded sendRegistration().
    $responses = $target->sendBatch($registries);

    expect($responses)->toHaveCount(3)
        ->and($responses->every(fn ($r) => $r->isSuccess()))->toBeTrue()
        ->and($responses->contains(fn ($r) => $r->getMessage() === 'Failed to send registry'))->toBeFalse()
        ->and($transport->calls)->toBe(3);
})->with(['concrete', 'proxy']);

it('refuses a client batch whole when off at entry, sending nothing', function (string $client) {
    $registry = $this->manager->createRegistry(Invoice::factory()->create());
    config()->set('verifactu.enabled', false);
    $transport = new FakeSoapTransport;
    $target = $client === 'concrete' ? FakeSoapTransport::clientWith($transport) : inFlightProxy('eager', $transport);

    expect(fn () => $target->sendBatch(collect([$registry])))->toThrow(VerifactuDisabledException::class)
        ->and($transport->calls)->toBe(0);
})->with(['concrete', 'proxy']);
