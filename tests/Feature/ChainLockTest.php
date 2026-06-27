<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * AID-258 — the chain-lock serializes writers across connections.
 *
 * Deterministic guard for the lock itself: the FOR UPDATE on the sentinel row
 * blocks a second connection, which then hits the 5s innodb_lock_wait_timeout.
 * The sentinel row is committed by the migration, so a second real connection
 * sees it — what we assert here is the lock, not row visibility, so the
 * transactional RefreshDatabase is fine (the end-to-end no-fork behaviour lives
 * in the on-demand pcntl test under tests/Concurrency).
 */
it('blocks a second writer on the chain-lock sentinel row until timeout', function () {
    // Hold the sentinel row exclusively inside this test's transaction.
    DB::table('verifactu_chain_locks')->where('scope', 'global')->lockForUpdate()->first();

    // A second real connection to the same database must block on that row and
    // fail with a lock-wait timeout (error 1205).
    config(['database.connections.testing2' => config('database.connections.testing')]);
    $other = DB::connection('testing2');
    $other->beginTransaction();

    expect(fn () => $other->table('verifactu_chain_locks')
        ->where('scope', 'global')
        ->lockForUpdate()
        ->first())
        ->toThrow(QueryException::class);

    $other->rollBack();
    DB::purge('testing2');
});
