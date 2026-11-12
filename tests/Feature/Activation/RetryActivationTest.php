<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\RegistryFailedEvent;
use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Exceptions\ConfigurationException;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuDisabledException;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Support\LazyAeatClient;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\ActivationHarness;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\FakeSoapTransport;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\ScriptedActivation;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/*
 * AID-1907 §4.3: the retry pass while disabled, and the provenance rule — a
 * pass ends as "disabled" only for the candidate's OWN pre-send refusal.
 */

beforeEach(function () {
    ActivationHarness::configureIssuer();
    $this->manager = ActivationHarness::registryManager();
});

function retryActivationErrorRegistry(RegistryManager $manager): Registry
{
    /** @var Registry $registry */
    $registry = $manager->createRegistry(Invoice::factory()->create());

    tamperRegistryColumns($registry->id, [
        'status' => RegistryStatusEnum::ERROR->value,
        'submission_attempts' => 1,
        'aeat_error' => 'connection reset',
        'created_at' => now()->subHour(),
    ]);

    return $registry->fresh();
}

function retryActivationRegistrar(RegistryManager $manager, FakeSoapTransport $transport): InvoiceRegistrar
{
    return ActivationHarness::bindRegistrar(
        $manager,
        LazyAeatClient::eager(fn () => FakeSoapTransport::clientWith($transport)),
    );
}

it('disabled at start: retryFailed() selects, locks and sends nothing, and says disabled', function () {
    $registry = retryActivationErrorRegistry($this->manager);
    $transport = new FakeSoapTransport;
    $registrar = retryActivationRegistrar($this->manager, $transport);
    config()->set('verifactu.enabled', false);
    $before = ActivationHarness::registrySnapshot();

    Cache::spy();
    $results = $registrar->retryFailed();

    Cache::shouldNotHaveReceived('lock');
    expect($results)->toBe(['success' => 0, 'failed' => 0, 'skipped' => 0, 'exhausted' => 0, 'disabled' => true])
        ->and($transport->calls)->toBe(0)
        ->and(ActivationHarness::registrySnapshot())->toBe($before);
});

it('verifactu:retry-failed while disabled exits SUCCESS with the warning, whatever the lock store does', function (string $store) {
    retryActivationErrorRegistry($this->manager);
    $transport = new FakeSoapTransport;
    retryActivationRegistrar($this->manager, $transport);
    config()->set('verifactu.enabled', false);

    $busy = Cache::lock('verifactu:retry-failed', 300);

    match ($store) {
        'free' => null,
        'busy' => expect($busy->get())->toBeTrue(),
        'refused' => config()->set('cache.default', 'array'),
    };

    try {
        $this->artisan('verifactu:retry-failed')
            ->expectsOutputToContain('VeriFACTU is disabled (verifactu.enabled=false): nothing sent, registries kept')
            ->assertSuccessful();
    } finally {
        // The file store outlives the test: never leave the lock held.
        $busy->forceRelease();
    }

    expect($transport->calls)->toBe(0);
})->with(['free', 'busy', 'refused']);

it('disabled mid-pass: the refused candidate and the rest are skipped, not failed', function () {
    $first = retryActivationErrorRegistry($this->manager);
    $second = retryActivationErrorRegistry($this->manager);
    $activation = ActivationHarness::script(new ScriptedActivation([], true));
    $transport = new FakeSoapTransport(function () use ($activation): void {
        $activation->switchTo(false); // after the first send, before the second re-check
    });
    retryActivationRegistrar($this->manager, $transport);

    // Killed mutation: removing the disabled catch from the loop (failed = 1).
    $this->artisan('verifactu:retry-failed')
        ->expectsOutputToContain('nothing sent, registries kept')
        ->expectsTable(['Result', 'Count'], [
            ['Success', 1],
            ['Failed', 0],
            ['Skipped', 1],
            ['Swept to exhausted', 0],
            ['Total resent', 1],
        ])
        ->assertSuccessful();

    expect($transport->calls)->toBe(1)
        ->and($first->fresh()->status)->toBe(RegistryStatusEnum::SENT)
        ->and($second->fresh()->status)->toBe(RegistryStatusEnum::ERROR)
        ->and($second->fresh()->submission_attempts)->toBe(1);
});

it('returns the mid-pass counters with disabled => true from retryFailed()', function () {
    retryActivationErrorRegistry($this->manager);
    retryActivationErrorRegistry($this->manager);
    $activation = ActivationHarness::script(new ScriptedActivation([], true));
    $registrar = retryActivationRegistrar($this->manager, new FakeSoapTransport(function () use ($activation): void {
        $activation->switchTo(false);
    }));

    expect($registrar->retryFailed())
        ->toBe(['success' => 1, 'failed' => 0, 'skipped' => 1, 'exhausted' => 0, 'disabled' => true]);
});

/*
 * Provenance. The scripted activation turns off after the outer send was
 * admitted (inside the transport); an observer on Registry then makes a
 * nested package call that is refused.
 */

/**
 * @return array{0: InvoiceRegistrar, 1: Registry, 2: Registry}
 */
function retryProvenanceSetup(RegistryManager $manager, string $case): array
{
    $candidate = retryActivationErrorRegistry($manager);
    $other = $manager->createRegistry(Invoice::factory()->create());
    tamperRegistryColumns($other->id, ['status' => RegistryStatusEnum::PENDING->value]);

    $activation = ActivationHarness::script(new ScriptedActivation([], true));
    $transport = new FakeSoapTransport(function () use ($activation, $case): void {
        $activation->switchTo(false);

        if ($case === 'd') {
            throw new RuntimeException('connection reset after admission');
        }
    });
    $registrar = retryActivationRegistrar($manager, $transport);

    $fired = false;
    Registry::updating(function (Registry $registry) use (&$fired, $candidate, $other, $registrar, $manager, $case): void {
        if ($fired || $registry->id !== $candidate->id) {
            return;
        }
        $fired = true;

        match ($case) {
            'a' => $manager->createRegistry(Invoice::factory()->create()),
            'b' => $registrar->submitToAeat(Registry::query()->findOrFail($other->id)),
            'c', 'd' => $registrar->submitToAeat(Registry::query()->findOrFail($candidate->id)),
        };
    });

    return [$registrar, $candidate, $other];
}

dataset('provenance cases', [
    '(a) ordinary guarded write' => ['a'],
    '(b) send of a different registry' => ['b'],
    '(c) send of the same registry' => ['c'],
    '(d) same registry, from the transport-failure catch' => ['d'],
]);

it('rewraps a nested refusal unflagged, as outcome, with the nested exception as previous', function (string $case) {
    [$registrar, $candidate] = retryProvenanceSetup($this->manager, $case);

    try {
        $registrar->submitToAeat($candidate);
        $this->fail('the nested refusal did not escape');
    } catch (VerifactuDisabledException $e) {
        expect($e->isSubmissionRefusal())->toBeFalse()
            ->and($e->registryId())->toBeNull()
            ->and($e->getMessage())->toContain('outcome refused')
            ->and($e->getPrevious())->toBeInstanceOf(VerifactuDisabledException::class);
    }
})->with('provenance cases');

it('counts a nested refusal as failed, never as a disabled skip', function (string $case) {
    retryProvenanceSetup($this->manager, $case);

    // Killed mutations: a class-only match kills (a); a flag-only or
    // flag-plus-id match without the outcome rewrap kills (b) and (c);
    // rewrapping only the post-response phase kills (d).
    $this->artisan('verifactu:retry-failed')->assertFailed();
})->with('provenance cases');

it('reports a nested refusal as failed in the retryFailed() result, without disabled', function (string $case) {
    [$registrar] = retryProvenanceSetup($this->manager, $case);

    $results = $registrar->retryFailed();

    expect($results['failed'])->toBe(1)
        ->and($results)->not->toHaveKey('disabled');
})->with('provenance cases');

/*
 * Origin is not inferred from class. Activation stays ON throughout; a
 * listener on the client's SoapFault log throws.
 */

it('gives today\'s transport-failure handling to any exception a listener throws inside the client', function (string $thrown) {
    $registry = retryActivationErrorRegistry($this->manager);
    $transport = new FakeSoapTransport(function (): void {
        throw new SoapFault('Server', 'internal fault');
    });
    $registrar = retryActivationRegistrar($this->manager, $transport);

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($thrown): void {
        if ($event->message !== 'SOAP Fault from AEAT') {
            return;
        }

        throw $thrown === 'disabled'
            ? VerifactuDisabledException::forOperation('listener')
            : ConfigurationException::make('listener misconfiguration');
    });

    Event::fake([RegistryFailedEvent::class]);

    // Killed mutation: any class-based catch reinstated in the transport try.
    expect(fn () => $registrar->submitToAeat($registry))->toThrow(AeatException::class);

    expect($registry->fresh()->submission_attempts)->toBe(2)
        ->and($registry->fresh()->status)->toBe(RegistryStatusEnum::ERROR);
    Event::assertDispatched(RegistryFailedEvent::class);
})->with(['disabled', 'configuration']);

it('counts a listener exception inside the client as failed in a retry pass', function (string $thrown) {
    retryActivationErrorRegistry($this->manager);
    $registrar = retryActivationRegistrar($this->manager, new FakeSoapTransport(function (): void {
        throw new SoapFault('Server', 'internal fault');
    }));

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($thrown): void {
        if ($event->message === 'SOAP Fault from AEAT') {
            throw $thrown === 'disabled'
                ? VerifactuDisabledException::forOperation('listener')
                : ConfigurationException::make('listener misconfiguration');
        }
    });

    $results = $registrar->retryFailed();

    expect($results['failed'])->toBe(1)
        ->and($results['skipped'])->toBe(0)
        ->and($results)->not->toHaveKey('disabled');
})->with(['disabled', 'configuration']);
