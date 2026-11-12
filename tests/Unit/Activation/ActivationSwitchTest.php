<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\ActivationContract;
use AichaDigital\LaraVerifactu\Exceptions\ConfigurationException;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuDisabledException;
use AichaDigital\LaraVerifactu\LaraVerifactuServiceProvider;
use AichaDigital\LaraVerifactu\Support\Activation;

/*
 * AID-1907 §3: the switch is declared, off by default, read at one point,
 * and parsed strictly.
 */

function activationEnvSet(?string $value): void
{
    if ($value === null) {
        putenv('VERIFACTU_ENABLED');
        unset($_ENV['VERIFACTU_ENABLED'], $_SERVER['VERIFACTU_ENABLED']);

        return;
    }

    putenv("VERIFACTU_ENABLED={$value}");
    $_ENV['VERIFACTU_ENABLED'] = $value;
    $_SERVER['VERIFACTU_ENABLED'] = $value;
}

afterEach(function () {
    activationEnvSet(null);
});

it('ships the key as the first entry of the config, resolved through env() with a false default', function () {
    $path = __DIR__ . '/../../../config/verifactu.php';
    $source = (string) file_get_contents($path);

    // Killed mutations: deleting the declaration, or hard-coding it.
    expect($source)->toMatch("/'enabled'\\s*=>\\s*env\\(\\s*'VERIFACTU_ENABLED'\\s*,\\s*false\\s*\\)\\s*,/");

    activationEnvSet(null);
    $config = require $path;

    expect(array_key_first($config))->toBe('enabled')
        ->and($config['enabled'])->toBeFalse();
});

it('gives a legacy published config without the key off, and on with VERIFACTU_ENABLED=true, through the real provider merge', function (?string $env, bool $expected) {
    activationEnvSet($env);

    // A consumer config published before 1.7: no `enabled` key at all.
    $legacy = config('verifactu');
    unset($legacy['enabled']);
    config()->set('verifactu', $legacy);
    expect(array_key_exists('enabled', config('verifactu')))->toBeFalse();

    // The provider's own register() merges the shipped defaults under the
    // consumer's array, exactly as at boot.
    (new LaraVerifactuServiceProvider($this->app))->register();

    expect(app(ActivationContract::class)->isEnabled())->toBe($expected);
})->with([
    'no env' => [null, false],
    'VERIFACTU_ENABLED=true' => ['true', true],
]);

it('is off when the key is absent from the repository: the default lives at the read point', function () {
    $verifactu = config('verifactu');
    unset($verifactu['enabled']);
    config()->set('verifactu', $verifactu);

    expect(app(ActivationContract::class)->isEnabled())->toBeFalse();
});

it('accepts every documented spelling', function (mixed $raw, bool $expected) {
    config()->set('verifactu.enabled', $raw);

    expect(app(ActivationContract::class)->isEnabled())->toBe($expected);
})->with([
    'bool true' => [true, true],
    'bool false' => [false, false],
    'int 1' => [1, true],
    'int 0' => [0, false],
    "'1'" => ['1', true],
    "'0'" => ['0', false],
    "'true'" => ['true', true],
    "'TRUE '" => ['TRUE ', true],
    "'false'" => ['false', false],
    "'on'" => ['on', true],
    "'Off'" => ['Off', false],
    "'yes'" => ['yes', true],
    "' no'" => [' no', false],
]);

it('refuses any other value instead of reading it as off or on', function (mixed $raw) {
    config()->set('verifactu.enabled', $raw);

    // Killed mutation: a bare filter_var(..., FILTER_NULL_ON_FAILURE), which
    // maps null and '' to false.
    expect(fn () => app(ActivationContract::class)->isEnabled())
        ->toThrow(ConfigurationException::class, 'verifactu.enabled');
})->with([
    'null' => [null],
    'empty string' => [''],
    'typo' => ['ture'],
    'int 2' => [2],
    'array' => [[true]],
    'float' => [1.0],
]);

it('reads the in-process config on every call, never a cached answer', function () {
    $activation = app(ActivationContract::class);

    config()->set('verifactu.enabled', true);
    expect($activation->isEnabled())->toBeTrue();

    config()->set('verifactu.enabled', false);
    expect($activation->isEnabled())->toBeFalse();
});

it('binds the default read point as a singleton that depends on nothing but config', function () {
    expect(app(ActivationContract::class))->toBeInstanceOf(Activation::class)
        ->and(app(ActivationContract::class))->toBe(app(ActivationContract::class));

    $constructor = (new ReflectionClass(Activation::class))->getConstructor();
    expect($constructor)->toBeNull();
});

it('carries provenance only on a submission refusal', function () {
    $previous = new RuntimeException('inner');

    $refusal = VerifactuDisabledException::submissionRefused('42', $previous);
    expect($refusal->isSubmissionRefusal())->toBeTrue()
        ->and($refusal->registryId())->toBe('42')
        ->and($refusal->getPrevious())->toBe($previous)
        ->and($refusal->getMessage())->toContain('verifactu.enabled')->toContain('VERIFACTU_ENABLED');

    $guard = VerifactuDisabledException::forOperation('register', $previous);
    expect($guard->isSubmissionRefusal())->toBeFalse()
        ->and($guard->registryId())->toBeNull()
        ->and($guard->getPrevious())->toBe($previous)
        ->and($guard->getMessage())->toContain('verifactu.enabled')->toContain('VERIFACTU_ENABLED');
});
