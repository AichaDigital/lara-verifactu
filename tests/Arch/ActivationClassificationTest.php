<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Services\AeatClient;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Support\LazyAeatClient;
use AichaDigital\LaraVerifactu\Verifactu;

/*
 * AID-1907 §7 arch test, deny by default: every public method of the
 * fiscal-surface classes and every job and command class is classified. A new
 * public method or class with no row fails here, so nobody adds a surface
 * without deciding what the activation switch does to it.
 *
 * Classes: effect-guarded, entry-guarded, outcome-writer, read-only (no
 * fiscal-state mutation — not "no side effect at all"), admitted-path
 * (@internal, reached only after an activation decision), lifecycle.
 */

const ACTIVATION_METHOD_CLASSIFICATION = [
    Verifactu::class => [
        'register' => 'entry-guarded',
        'cancel' => 'entry-guarded',
        'amendRejected' => 'entry-guarded',
        'sendBatch' => 'entry-guarded',
        'status' => 'read-only',
        'qr' => 'read-only',
        'validateChain' => 'read-only',
        'enabled' => 'read-only',
        'fake' => 'read-only',
        'assertRegistered' => 'read-only',
        'assertCancelled' => 'read-only',
        'assertNotSent' => 'read-only',
    ],
    InvoiceRegistrar::class => [
        'register' => 'entry-guarded',
        'cancel' => 'entry-guarded',
        'amendRejected' => 'entry-guarded',
        'batchRegister' => 'entry-guarded',
        'submitToAeat' => 'entry-guarded',
        'retryFailed' => 'entry-guarded',
        'markSchedulingAbandoned' => 'outcome-writer',
        'hasUnfiledChainPredecessor' => 'read-only',
        'verifyBlockchain' => 'read-only',
    ],
    RegistryManager::class => [
        'createRegistry' => 'effect-guarded',
        'createCancellationRegistry' => 'effect-guarded',
        'markAsSubmitted' => 'outcome-writer',
        'markAsAccepted' => 'outcome-writer',
        'markAsAcceptedWithErrors' => 'outcome-writer',
        'markAsFailed' => 'outcome-writer',
        'markAsRejected' => 'outcome-writer',
        'markSchedulingAbandoned' => 'outcome-writer',
        'exhaustOverBudget' => 'outcome-writer',
        'getPreviousRegistry' => 'read-only',
        'getPreviousHash' => 'read-only',
        'hasUnfiledChainPredecessor' => 'read-only',
        'verifyBlockchain' => 'read-only',
        'assertSubmissionPayloadIntact' => 'read-only',
        'submissionBudget' => 'read-only',
        'getPendingRegistries' => 'read-only',
        'getRetryableRegistries' => 'read-only',
        'getResubmittableRegistries' => 'read-only',
    ],
    AeatClient::class => [
        'sendRegistration' => 'effect-guarded',
        'sendBatch' => 'effect-guarded',
        'sendCore' => 'admitted-path',
        'sendBatchCore' => 'admitted-path',
    ],
    LazyAeatClient::class => [
        'eager' => 'lifecycle',
        'deferred' => 'lifecycle',
        'sendRegistration' => 'effect-guarded',
        'sendBatch' => 'effect-guarded',
        'sendAdmitted' => 'admitted-path',
    ],
];

const ACTIVATION_CLASS_CLASSIFICATION = [
    'Jobs/ProcessInvoiceRegistrationJob' => 'entry-guarded',
    'Jobs/SubmitRegistryToAeatJob' => 'entry-guarded',
    'Jobs/RetryFailedRegistriesJob' => 'entry-guarded',
    'Jobs/VerifyBlockchainIntegrityJob' => 'read-only',
    'Commands/RegisterInvoiceCommand' => 'entry-guarded',
    'Commands/RetryFailedCommand' => 'entry-guarded',
    'Commands/StatusCommand' => 'read-only',
    'Commands/TestAeatConnectionCommand' => 'read-only',
    'Commands/VerifyBlockchainCommand' => 'read-only',
    'Console/VerifactuInstallCommand' => 'read-only',
];

function activationSrc(): string
{
    return dirname(__DIR__, 2) . '/src';
}

/**
 * @return list<string> relative paths of the src/ files that contain $needle
 */
function activationFilesContaining(string $needle): array
{
    $hits = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(activationSrc(), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), $needle)) {
            $hits[] = substr($file->getPathname(), strlen(activationSrc()) + 1);
        }
    }

    sort($hits);

    return $hits;
}

it('classifies every public method of the fiscal-surface classes, and nothing else', function (string $class) {
    $declared = collect((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $m) => $m->getDeclaringClass()->getName() === $class)
        ->map(fn (ReflectionMethod $m) => $m->getName())
        ->reject(fn (string $name) => in_array($name, ['__construct', '__destruct'], true))
        ->sort()->values()->all();

    $classified = collect(ACTIVATION_METHOD_CLASSIFICATION[$class])->keys()->sort()->values()->all();

    expect($declared)->toBe($classified);
})->with(array_keys(ACTIVATION_METHOD_CLASSIFICATION));

it('classifies every job and command class', function () {
    $found = [];

    foreach (['Jobs', 'Commands', 'Console'] as $dir) {
        foreach (glob(activationSrc() . "/{$dir}/*.php") as $path) {
            $found[] = $dir . '/' . basename($path, '.php');
        }
    }

    sort($found);
    $classified = array_keys(ACTIVATION_CLASS_CLASSIFICATION);
    sort($classified);

    expect($found)->toBe($classified);
});

it('puts the activation check first in the handle() of every guarded job and command', function (string $relative) {
    $source = (string) file_get_contents(activationSrc() . "/{$relative}.php");
    preg_match('/public function handle\(InvoiceRegistrar \$registrar\): \w+\s*\{\s*(?:\/\/[^\n]*\n\s*)*([^\n]+)/', $source, $match);

    expect($match[1] ?? '')->toMatch('/skippedBecauseDisabled\(|ActivationContract::class\)->isEnabled\(\)/');
})->with(array_keys(array_filter(ACTIVATION_CLASS_CLASSIFICATION, fn (string $c) => $c === 'entry-guarded')));

it('spells the configuration key only at the read point', function () {
    $single = activationFilesContaining("'verifactu.enabled'");
    $double = activationFilesContaining('"verifactu.enabled"');

    expect(array_values(array_unique([...$single, ...$double])))->toBe(['Support/Activation.php']);
});

it('reaches the admitted path only from its declared callers', function (string $call, array $allowed) {
    expect(activationFilesContaining($call))->toBe($allowed);
})->with([
    'sendCore' => ['->sendCore(', ['Services/AeatClient.php', 'Services/InvoiceRegistrar.php', 'Support/LazyAeatClient.php']],
    'sendBatchCore' => ['->sendBatchCore(', ['Services/AeatClient.php', 'Support/LazyAeatClient.php']],
    'sendAdmitted' => ['->sendAdmitted(', ['Services/InvoiceRegistrar.php']],
]);

it('builds a submission refusal only in submitToAeat()', function () {
    expect(activationFilesContaining('::submissionRefused('))->toBe(['Services/InvoiceRegistrar.php']);

    $source = (string) file_get_contents(activationSrc() . '/Services/InvoiceRegistrar.php');
    expect(substr_count($source, '::submissionRefused('))->toBe(1);
});

it('grants a send admission only at the guards and the re-check (gate round 1, finding 1)', function () {
    expect(activationFilesContaining('SendAdmission::grant('))
        ->toBe(['Services/AeatClient.php', 'Services/InvoiceRegistrar.php', 'Support/LazyAeatClient.php']);

    $counts = collect(['Services/AeatClient.php' => 2, 'Services/InvoiceRegistrar.php' => 1, 'Support/LazyAeatClient.php' => 2])
        ->map(fn (int $expected, string $file) => substr_count((string) file_get_contents(activationSrc() . "/{$file}"), 'SendAdmission::grant('));

    expect($counts->all())->toBe(['Services/AeatClient.php' => 2, 'Services/InvoiceRegistrar.php' => 1, 'Support/LazyAeatClient.php' => 2]);
});
