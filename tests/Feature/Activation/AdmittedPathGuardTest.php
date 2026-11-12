<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Exceptions\VerifactuDisabledException;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Services\AeatClient;
use AichaDigital\LaraVerifactu\Support\LazyAeatClient;
use AichaDigital\LaraVerifactu\Support\SendAdmission;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\ActivationHarness;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\FakeSoapTransport;

/*
 * AID-1907, gate round 1, finding 1: the admitted path is public but not
 * open. sendCore(), sendBatchCore() and sendAdmitted() require a
 * SendAdmission, and the only way to obtain one is a grant that reads the
 * switch. Called directly while disabled, nothing reaches the transport.
 */

beforeEach(function () {
    ActivationHarness::configureIssuer();
    $this->manager = ActivationHarness::registryManager();
});

/**
 * The three admitted entry points, each with a fresh transport.
 *
 * @return array<string, Closure(FakeSoapTransport, mixed, SendAdmission): mixed>
 */
function admittedEntryPoints(): array
{
    return [
        'AeatClient::sendCore' => fn (FakeSoapTransport $t, $registry, SendAdmission $a) => FakeSoapTransport::clientWith($t)->sendCore($registry, $a),
        'AeatClient::sendBatchCore' => fn (FakeSoapTransport $t, $registry, SendAdmission $a) => FakeSoapTransport::clientWith($t)->sendBatchCore(collect([$registry]), $a),
        'LazyAeatClient::sendAdmitted' => fn (FakeSoapTransport $t, $registry, SendAdmission $a) => LazyAeatClient::eager(fn () => FakeSoapTransport::clientWith($t))->sendAdmitted($registry, $a),
    ];
}

it('requires an admission on every admitted entry point', function (string $class, string $method) {
    $parameter = collect((new ReflectionMethod($class, $method))->getParameters())
        ->first(fn (ReflectionParameter $p) => (string) $p->getType() === SendAdmission::class);

    // Killed mutation: dropping the admission parameter from the signature.
    expect($parameter)->not->toBeNull()
        ->and($parameter->isOptional())->toBeFalse()
        ->and($parameter->allowsNull())->toBeFalse();
})->with([
    [AeatClient::class, 'sendCore'],
    [AeatClient::class, 'sendBatchCore'],
    [LazyAeatClient::class, 'sendAdmitted'],
]);

it('refuses to grant an admission while disabled, so a direct caller cannot reach the transport', function (string $entry) {
    $registry = $this->manager->createRegistry(Invoice::factory()->create());
    config()->set('verifactu.enabled', false);
    $transport = new FakeSoapTransport;

    expect(fn () => admittedEntryPoints()[$entry](
        $transport,
        $registry,
        SendAdmission::grant(fn () => VerifactuDisabledException::forOperation('probe')),
    ))->toThrow(VerifactuDisabledException::class)
        ->and($transport->calls)->toBe(0);
})->with(array_keys(admittedEntryPoints()));

it('spends an admission on one send: reusing it reaches nothing', function (string $entry) {
    $registry = $this->manager->createRegistry(Invoice::factory()->create());
    $transport = new FakeSoapTransport;
    $admission = SendAdmission::grant(fn () => VerifactuDisabledException::forOperation('probe'));

    admittedEntryPoints()[$entry]($transport, $registry, $admission);

    // Granted while enabled, held, replayed while disabled: refused.
    // Killed mutation: removing spend() from sendCore() or sendBatchCore().
    config()->set('verifactu.enabled', false);

    expect(fn () => admittedEntryPoints()[$entry]($transport, $registry, $admission))
        ->toThrow(LogicException::class, 'already spent')
        ->and($transport->calls)->toBe(1);
})->with(array_keys(admittedEntryPoints()));

it('covers a whole batch with one admission', function () {
    $registries = collect([
        $this->manager->createRegistry(Invoice::factory()->create()),
        $this->manager->createRegistry(Invoice::factory()->create()),
    ]);
    $transport = new FakeSoapTransport;

    $responses = FakeSoapTransport::clientWith($transport)->sendBatchCore(
        $registries,
        SendAdmission::grant(fn () => VerifactuDisabledException::forOperation('probe')),
    );

    // Killed mutation: the batch loop spending per item.
    expect($responses->every(fn ($r) => $r->isSuccess()))->toBeTrue()
        ->and($transport->calls)->toBe(2);
});

it('cannot be built, copied or revived outside a grant', function () {
    $class = new ReflectionClass(SendAdmission::class);
    $admission = SendAdmission::grant(fn () => VerifactuDisabledException::forOperation('probe'));

    expect($class->isFinal())->toBeTrue()
        ->and($class->getConstructor()?->isPrivate())->toBeTrue()
        ->and(fn () => clone $admission)->toThrow(LogicException::class)
        ->and(fn () => serialize($admission))->toThrow(LogicException::class)
        ->and(fn () => unserialize('O:' . strlen(SendAdmission::class) . ':"' . SendAdmission::class . '":0:{}'))->toThrow(LogicException::class);
});

/**
 * Admissions PHP can build without running the private constructor.
 *
 * @return array<string, Closure(): mixed>
 */
function constructorlessAdmissions(): array
{
    $class = SendAdmission::class;

    return [
        // Legacy Serializable format: built without __unserialize() (gate round 2, finding 1).
        'C: unserialize' => fn () => @unserialize('C:' . strlen($class) . ':"' . $class . '":0:{}'),
        'O: unserialize with a payload' => function () use ($class) {
            try {
                return unserialize('O:' . strlen($class) . ':"' . $class . '":1:{s:4:"live";b:1;}');
            } catch (LogicException) {
                return null;
            }
        },
        'newInstanceWithoutConstructor' => fn () => (new ReflectionClass($class))->newInstanceWithoutConstructor(),
    ];
}

it('refuses to send with an admission PHP built without a grant, even while enabled', function (string $route) {
    $registry = $this->manager->createRegistry(Invoice::factory()->create());
    $transport = new FakeSoapTransport;
    $forged = constructorlessAdmissions()[$route]();

    // A payload whose __unserialize() refused never yields an object.
    if (! $forged instanceof SendAdmission) {
        expect($forged)->toBeNull();

        return;
    }

    // Killed mutation: validity as a property default instead of set by the constructor.
    expect(fn () => FakeSoapTransport::clientWith($transport)->sendCore($registry, $forged))
        ->toThrow(LogicException::class, 'never granted')
        ->and($transport->calls)->toBe(0);
})->with(array_keys(constructorlessAdmissions()));

it('refuses a constructor run from a rebound private-scope closure while disabled', function () {
    $registry = $this->manager->createRegistry(Invoice::factory()->create());
    $transport = new FakeSoapTransport;
    $class = SendAdmission::class;
    $construct = Closure::bind(
        static fn () => new $class(fn () => VerifactuDisabledException::forOperation('probe')),
        null,
        $class,
    );
    config()->set('verifactu.enabled', false);

    // Gate round 3, finding B1. Killed mutation: the switch read moved back
    // from the constructor into grant(), so running the constructor admits.
    expect(fn () => FakeSoapTransport::clientWith($transport)->sendCore($registry, $construct()))
        ->toThrow(VerifactuDisabledException::class)
        ->and($transport->calls)->toBe(0);
});

it('declares its limit: private-scope code can still rewrite the admission state', function () {
    $registry = $this->manager->createRegistry(Invoice::factory()->create());
    $transport = new FakeSoapTransport;
    config()->set('verifactu.enabled', false);

    // Characterisation, not protection: an object built without the
    // constructor and given live=true from the class scope sends while
    // disabled. This pins the declared limit (spec §4.1) so a change to it is
    // deliberate.
    $forged = (new ReflectionClass(SendAdmission::class))->newInstanceWithoutConstructor();
    Closure::bind(fn () => $this->live = true, $forged, SendAdmission::class)();

    FakeSoapTransport::clientWith($transport)->sendCore($registry, $forged);

    expect($transport->calls)->toBe(1);
});
