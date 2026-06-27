<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * AID-264 — end-to-end fork proof for the AID-258 chain-fork lock.
 *
 * `ChainLockTest` (tests/Feature) is the deterministic guard: it proves the
 * FOR UPDATE on the sentinel row serializes writers. This is the empirical
 * confirmation: real OS processes create registries at the same time and we
 * assert the fingerprint chain did not fork (no two links share a predecessor).
 *
 * Why it can't use RefreshDatabase: the forked children must read rows the
 * parent committed, on their own connections — a transactional RefreshDatabase
 * would hide them. So the schema + sentinel are built committed via migrate:fresh.
 * Because this file is outside the \Feature\ namespace, the TestCase's
 * defineDatabaseMigrations() does not register the package migration path, so we
 * pass it explicitly.
 *
 * Gated: runs only with RUN_CONCURRENCY_IT=1 and pcntl available. The file lives
 * outside the phpunit testsuites (Unit/Feature/Architecture), so the CI `pest`
 * run never touches it. Run on demand:
 *   RUN_CONCURRENCY_IT=1 vendor/bin/pest tests/Concurrency
 */
beforeEach(function () {
    if (getenv('RUN_CONCURRENCY_IT') !== '1') {
        test()->markTestSkipped('concurrency IT disabled (set RUN_CONCURRENCY_IT=1)');
    }

    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl extension not available');
    }

    // Build schema + seed the sentinel, committed (no RefreshDatabase wraps this).
    Artisan::call('migrate:fresh', [
        '--database' => 'testing',
        '--path' => realpath(__DIR__ . '/../../database/migrations'),
        '--realpath' => true,
    ]);
});

afterEach(function () {
    if (getenv('RUN_CONCURRENCY_IT') === '1' && function_exists('pcntl_fork')) {
        Artisan::call('db:wipe', ['--database' => 'testing']);
    }
});

it('a forked child sees the committed schema and sentinel row', function () {
    // Sanity for the mechanism the real test relies on: a child, on its own
    // connection, sees data the parent committed.
    $pid = pcntl_fork();

    if ($pid === -1) {
        test()->fail('pcntl_fork failed');
    }

    if ($pid === 0) {
        DB::purge('testing');
        $seen = DB::connection('testing')
            ->table('verifactu_chain_locks')
            ->where('scope', 'global')
            ->exists();
        exit($seen ? 0 : 1);
    }

    pcntl_waitpid($pid, $status);

    expect(pcntl_wifexited($status))->toBeTrue()
        ->and(pcntl_wexitstatus($status))->toBe(0);
})->group('concurrency');

it('concurrent registry creation never forks the fingerprint chain', function () {
    $childCount = 6;

    // Pre-create the invoices, committed, so each child can read its own.
    $invoiceIds = [];
    for ($i = 0; $i < $childCount; $i++) {
        $invoiceIds[] = Invoice::factory()->create()->id;
    }

    // Fork one child per invoice; each creates a registry on its own connection.
    // Without the AID-258 lock, two children would read the same chain head and
    // emit the same previous_hash (a fork). With it, they serialize.
    $pids = [];
    foreach ($invoiceIds as $invoiceId) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            // Never share the parent's PDO across the fork.
            DB::purge('testing');

            try {
                $invoice = Invoice::findOrFail($invoiceId);
                app(RegistryManager::class)->createRegistry($invoice);
                exit(0);
            } catch (Throwable) {
                exit(1);
            }
        }

        $pids[] = $pid;
    }

    // Parent: every child must succeed.
    $failures = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            $failures++;
        }
    }
    expect($failures)->toBe(0);

    // Re-read on a fresh connection to see everything the children committed.
    DB::purge('testing');
    $registries = Registry::query()->orderBy('id')->get(['hash', 'previous_hash']);

    // One registry per invoice.
    expect($registries)->toHaveCount($childCount);

    // Exactly one genesis link (null previous_hash).
    expect($registries->whereNull('previous_hash'))->toHaveCount(1);

    // No two links share a predecessor — the chain is linear, not forked.
    $previousHashes = $registries->whereNotNull('previous_hash')->pluck('previous_hash');
    expect($previousHashes->unique()->count())->toBe($previousHashes->count());

    // Every previous_hash points at a real earlier link's hash.
    $hashes = $registries->pluck('hash')->all();
    $previousHashes->each(fn ($previousHash) => expect($hashes)->toContain($previousHash));
})->group('concurrency');
