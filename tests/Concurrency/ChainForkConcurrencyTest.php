<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
 * AID-710 hardened this test and put it in CI. Until then it had never run in
 * CI at all: it gates on RUN_CONCURRENCY_IT=1 and nothing set it — not the
 * retired GitHub workflow, not the GitLab pipeline. The invariant that the
 * fingerprint chain does not fork was verified by no automated gate since the
 * test was written. The pipeline now runs it against BOTH engines of the test
 * matrix, and fails the job if it reports itself skipped.
 *
 * Run on demand:
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

/**
 * Number of concurrent writers. MEASURED, not guessed (AID-710).
 *
 * Measured on MySQL 8.4.10 and MariaDB 12.3.2, with acquireChainLock() commented
 * out to check this test can actually fail:
 *
 *   - Floor: WITH the barrier below, 2 writers are already enough — RED in 3 of
 *     3 rounds at 2, 3, 4, 6 and 8 writers, on both engines. The failure is
 *     total and identical every time: N-1 of N writers die, one survives.
 *   - WITHOUT the barrier (how this test shipped until AID-710), 6 unsynchronised
 *     writers also went red 6 of 6 rounds on this host — but that is luck, not
 *     design: the loop releases each child as it is born, so whether the writers
 *     ever overlap depends on how fast the machine happens to be. On a loaded
 *     runner they may not overlap at all, and the gate goes green with no lock.
 *     The barrier is what turns "red if we get lucky" into "red, deterministically".
 *
 * Shipped value: 8 — 4x the measured floor, with room for a runner far slower
 * than the one measured here, and well under the ceiling described next.
 *
 * There IS a ceiling here, unlike larabill's numbering test which runs 24. The
 * lock serializes writers and one registry costs ~170 ms (hash + XML + three QR
 * renders), so writer N waits (N-1) x 170 ms while innodb_lock_wait_timeout is
 * 5 s — set in tests/TestCase.php, and tests/Feature/ChainLockTest.php depends
 * on that exact value, so it must not be raised globally to buy headroom here.
 * Measured green at 8/12/16/20 writers on both engines (whole test 3.00 s to
 * 5.07 s), which puts the ceiling near 30 on this host and lower on a contended
 * one. Past it the LAST writer times out while the lock is working perfectly — a
 * red that means nothing. So raising this number is not "more safety": above the
 * floor it buys no discrimination, and near the ceiling it buys false failures.
 *
 * Overridable for local stress runs: VERIFACTU_CONCURRENCY_FORKS=16 ...
 */
function verifactuForkCount(): int
{
    return (int) (getenv('VERIFACTU_CONCURRENCY_FORKS') ?: 8);
}

/**
 * Fork one child per invoice; each creates a registry on its own connection.
 *
 * @param  list<int|string>  $invoiceIds
 * @return array{0: int, 1: int, 2: list<string>} [converged, died, causes]
 */
function verifactuForkCreateRegistries(array $invoiceIds): array
{
    $resultDir = sys_get_temp_dir() . '/verifactu_aid710_' . Str::random(8);
    mkdir($resultDir, 0755, true);

    // AID-710: absolute-time barrier. Forking in a loop releases every child the
    // instant it is born, so on a fast host child 1 can commit before child N
    // even exists — the writers never overlap and the lock is never disputed.
    // Measured: without this barrier the red/green outcome depends on how fast
    // the host happens to be, which is precisely what a concurrency gate must
    // not depend on. Releasing every child at one wall-clock instant is what
    // makes this a concurrency test.
    $startAt = microtime(true) + 1.0;

    $pids = [];
    foreach ($invoiceIds as $invoiceId) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            // Never share the parent's PDO across the fork.
            DB::purge('testing');
            time_sleep_until($startAt);

            try {
                $invoice = Invoice::findOrFail($invoiceId);
                app(RegistryManager::class)->createRegistry($invoice);
                exit(0);
            } catch (Throwable $e) {
                // Record the REAL cause. A mute exit(1) turns every failure into
                // "N children did not converge", which is what kept the AID-700
                // deadlock undiagnosed for weeks in the consumer. Here it hid
                // something specific too: with acquireChainLock() disabled, the
                // children do NOT fail on the chain assertions below — they die
                // with SQLSTATE[40001] 1213 Deadlock on the INSERT, on both
                // engines. generateRegistryNumber() takes lockForUpdate() over a
                // COUNT(*) filtered by whereDate(created_at), i.e. a gap lock on
                // a range every writer then inserts into. That is the AID-700
                // pattern, dormant here only because the chain lock serializes
                // writers before they reach it. Printing the cause is what tells
                // a deadlock apart from an actual chain fork.
                file_put_contents(
                    $resultDir . '/' . getmypid() . '.err',
                    get_class($e) . ': ' . $e->getMessage()
                );
                exit(1);
            }
        }

        $pids[] = $pid;
    }

    $converged = $died = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
        $code === 0 ? $converged++ : $died++;
    }

    $causes = [];
    foreach (glob($resultDir . '/*.err') ?: [] as $file) {
        $causes[] = mb_substr((string) file_get_contents($file), 0, 300);
        unlink($file);
    }
    rmdir($resultDir);

    return [$converged, $died, $causes];
}

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
    $childCount = verifactuForkCount();

    // Pre-create the invoices, committed, so each child can read its own.
    $invoiceIds = [];
    for ($i = 0; $i < $childCount; $i++) {
        $invoiceIds[] = Invoice::factory()->create()->id;
    }

    // Without the AID-258 lock, two children read the same chain head and emit
    // the same previous_hash (a fork). With it, they serialize.
    [$converged, $died, $causes] = verifactuForkCreateRegistries($invoiceIds);

    if ($died > 0) {
        test()->fail(sprintf(
            "%d of %d concurrent writers died.\nDistinct causes:\n%s",
            $died,
            $childCount,
            implode("\n", array_unique($causes))
        ));
    }

    expect($converged)->toBe($childCount);

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
