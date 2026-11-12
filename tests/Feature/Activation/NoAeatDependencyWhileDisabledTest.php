<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Commands\RegisterInvoiceCommand;
use AichaDigital\LaraVerifactu\Commands\RetryFailedCommand;
use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\RegistryFailedEvent;
use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Exceptions\ConfigurationException;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuDisabledException;
use AichaDigital\LaraVerifactu\Facades\Verifactu;
use AichaDigital\LaraVerifactu\Jobs\ProcessInvoiceRegistrationJob;
use AichaDigital\LaraVerifactu\Jobs\RetryFailedRegistriesJob;
use AichaDigital\LaraVerifactu\Jobs\SubmitRegistryToAeatJob;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\ActivationHarness;
use AichaDigital\LaraVerifactu\Verifactu as VerifactuRoot;
use Illuminate\Support\Facades\Event;

/*
 * AID-1907 §4.1/§4.3/§5: while disabled, nothing that merely RESOLVES the
 * registrar may build the AEAT client, so an unusable AEAT configuration can
 * never fail before an activation guard. No client double here: the real
 * container, with an environment the endpoint resolver rejects.
 */

beforeEach(function () {
    ActivationHarness::configureIssuer();
    config()->set('verifactu.aeat.environment', 'invalid-probe');
});

it('answers enabled() = false without building the AEAT client', function () {
    config()->set('verifactu.enabled', false);

    expect(Verifactu::enabled())->toBeFalse();
});

it('refuses each facade write with VerifactuDisabledException, never ConfigurationException', function (Closure $write) {
    config()->set('verifactu.enabled', false);

    expect($write)->toThrow(VerifactuDisabledException::class);
})->with([
    'register' => [fn () => Verifactu::register(Invoice::factory()->create())],
    'cancel' => [fn () => Verifactu::cancel(Invoice::factory()->create())],
    'amendRejected' => [fn () => Verifactu::amendRejected(new Registry, Invoice::factory()->create())],
    'sendBatch' => [fn () => Verifactu::sendBatch([Invoice::factory()->create()])],
]);

// Each dispatchSync() resolves handle(InvoiceRegistrar) from the container:
// with an eager client these would throw ConfigurationException.

it('lets ProcessInvoiceRegistrationJob complete its disabled no-op', function () {
    config()->set('verifactu.enabled', false);

    ProcessInvoiceRegistrationJob::dispatchSync(Invoice::factory()->create()->id);
})->throwsNoExceptions();

it('lets SubmitRegistryToAeatJob complete its disabled no-op', function () {
    config()->set('verifactu.enabled', false);

    SubmitRegistryToAeatJob::dispatchSync(1);
})->throwsNoExceptions();

it('lets RetryFailedRegistriesJob complete its disabled no-op', function () {
    config()->set('verifactu.enabled', false);

    RetryFailedRegistriesJob::dispatchSync();
})->throwsNoExceptions();

it('makes verifactu:register exit FAILURE and verifactu:retry-failed exit SUCCESS, each with its message', function () {
    config()->set('verifactu.enabled', false);

    $this->artisan('verifactu:register', ['--all' => true])
        ->expectsOutputToContain('VeriFACTU is disabled')
        ->assertFailed();

    $this->artisan('verifactu:retry-failed')
        ->expectsOutputToContain('nothing sent, registries kept')
        ->assertSuccessful();
});

// ── Enabled branch: no timing or exception change (§4.1) ────────────────

it('enabled with an invalid environment: resolving the registrar throws ConfigurationException, as before 1.7', function () {
    config()->set('verifactu.enabled', true);
    $before = Registry::withTrashed()->count();

    // Killed mutation: deferring endpoint resolution when enabled.
    expect(fn () => app(InvoiceRegistrar::class))->toThrow(ConfigurationException::class);
    expect(Registry::withTrashed()->count())->toBe($before);
});

it('keeps contextual bindings selecting the facade root registrar', function () {
    config()->set('verifactu.aeat.environment', 'sandbox');
    $bound = new InvoiceRegistrar(
        ActivationHarness::registryManager(),
        Mockery::mock(CertificateManagerContract::class),
        Mockery::mock(AeatClientContract::class),
    );

    app()->when(VerifactuRoot::class)->needs(InvoiceRegistrar::class)->give(fn () => $bound);

    $root = app(VerifactuRoot::class);

    expect((new ReflectionProperty(VerifactuRoot::class, 'registrar'))->getValue($root))->toBe($bound);
});

it('pins the five handle(InvoiceRegistrar) signatures and lets subclasses override them', function (string $class) {
    $method = new ReflectionMethod($class, 'handle');
    $parameters = $method->getParameters();

    expect($parameters)->toHaveCount(1)
        ->and((string) $parameters[0]->getType())->toBe(InvoiceRegistrar::class)
        ->and($parameters[0]->isPassedByReference())->toBeFalse()
        ->and($parameters[0]->isOptional())->toBeFalse()
        ->and((new ReflectionClass($class))->isFinal())->toBeFalse();

    // A consumer subclass written against the 1.6 signature must still load
    // (gate round 1, finding 6): declare one for real instead of inferring it
    // from reflection. A signature change makes this a fatal error.
    $returnType = (string) $method->getReturnType();
    $subclass = 'ActivationSignatureProbe' . md5($class);
    $body = $returnType === 'void' ? '' : 'return parent::handle($registrar);';
    eval("final class {$subclass} extends \\{$class} { public function handle(\\" . InvoiceRegistrar::class . " \$registrar): {$returnType} { {$body} } }");

    expect(class_exists($subclass, false))->toBeTrue();
})->with([
    ProcessInvoiceRegistrationJob::class,
    SubmitRegistryToAeatJob::class,
    RetryFailedRegistriesJob::class,
    RegisterInvoiceCommand::class,
    RetryFailedCommand::class,
]);

it('declared limit: disabled at resolution, enabled later, invalid environment — the first send gets today\'s transport-failure handling', function () {
    config()->set('verifactu.enabled', false);
    $registrar = app(InvoiceRegistrar::class); // deferred client

    config()->set('verifactu.enabled', true);
    Event::fake([RegistryFailedEvent::class]);

    expect(fn () => $registrar->register(Invoice::factory()->create()))
        ->toThrow(AeatException::class);

    $registry = Registry::query()->latest('id')->first();
    expect($registry->status)->toBe(RegistryStatusEnum::ERROR)
        ->and($registry->submission_attempts)->toBe(1);
    Event::assertDispatched(RegistryFailedEvent::class);
});
