<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AID-735 — end-to-end fork proof for the AID-731 retry-overlap lock.
 *
 * `RetryOverlapTest` (tests/Feature) is the deterministic guard: it proves the
 * command skips while another pass holds the lock. This is the empirical
 * confirmation — real OS processes run `verifactu:retry-failed` at the same
 * instant, and we assert the tax agency was called ONCE.
 *
 * The harm being guarded is not an abstract race. Two passes handing the same
 * ERROR record to both means the same registro is transmitted to the AEAT
 * twice, and the second transmission is what AID-727's duplicate reconciliation
 * then has to clean up after. Since AID-717, records sit in ERROR routinely and
 * this command is the ONLY automatic recovery path, so a consumer scheduling it
 * every few minutes — `clientes` runs it every 10 — makes the overlap real
 * rather than theoretical.
 *
 * Why it cannot use RefreshDatabase: the forked children must read rows the
 * parent committed, on their own connections. Schema is built committed via
 * migrate:fresh, and because this file lives outside the \Feature\ namespace the
 * TestCase does not register the package migration path — it is passed here.
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

    config()->set('verifactu.company.tax_id', '89890001K');
    config()->set('verifactu.company.name', 'Empresa Ejemplo SL');
    config()->set('verifactu.system.vendor_name', 'AichaDigital SL');
    config()->set('verifactu.system.vendor_nif', 'B70123456');
    config()->set('verifactu.system.name', 'LaraVerifactu');
    config()->set('verifactu.system.id', 'LV');
    config()->set('verifactu.system.version', '1.0');
    config()->set('verifactu.system.installation_number', '1');

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
 * Number of concurrent retry passes. MEASURED, not guessed.
 *
 * Measured on MySQL 8.4.10 and MariaDB 12.3.2 with the `$lock->get()` check in
 * RetryFailedCommand commented out, to check this test can actually fail.
 *
 *   - Floor: 2. With the barrier below, two passes are already enough — RED in
 *     3 of 3 rounds at 2, 3 and 4 passes, on BOTH engines. The failure is total
 *     and exact every time: N passes produce N transmissions, never fewer. That
 *     is what makes this gate cheap to trust — there is no partial outcome to
 *     interpret, the agency either heard about the registro once or N times.
 *
 * Shipped value: 4 — twice the measured floor, which is ample given a failure
 * mode this total, and small enough to keep the gate under two seconds.
 *
 * On the CEILING, and why this test has none while the chain-fork gate does:
 * that gate's lock SERIALIZES writers, so writer N waits (N-1) x cost and
 * competes against innodb_lock_wait_timeout — raising the count there buys
 * false failures. Here the losers do not queue: a pass that cannot take the
 * cache lock returns immediately and exits. Nobody waits on anybody, so there
 * is no timeout pressure and no ceiling of that kind. The only limit is how
 * many processes the host will fork, which is far above anything useful.
 *
 * Overridable for local stress runs: VERIFACTU_RETRY_FORKS=16 ...
 */
function verifactuRetryForkCount(): int
{
    return (int) (getenv('VERIFACTU_RETRY_FORKS') ?: 4);
}

/**
 * An AEAT client that records every transmission and holds the line.
 *
 * The sleep is the point: a submission that returns instantly would let the
 * winner finish before the losers even reach the lock, and the test would pass
 * whether or not the lock exists. Holding it open is what makes the overlap
 * real. Each call appends a line, so the file IS the count of transmissions —
 * the fiscal harm, observed directly rather than inferred from a counter.
 */
final class RecordingAeatClient implements AeatClientContract
{
    public function __construct(private string $callLog) {}

    public function sendRegistration(RegistryContract $registry): AeatResponse
    {
        file_put_contents(
            $this->callLog,
            getmypid() . ':' . $registry->getRegistryNumber() . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );

        usleep(400_000);

        return new AeatResponse(success: true, code: 'CSV-' . getmypid(), message: 'Correcto');
    }

    public function sendBatch(Collection $registries): Collection
    {
        return $registries->map(fn (RegistryContract $r) => $this->sendRegistration($r));
    }
}

/**
 * Fork N children, each running one `verifactu:retry-failed` pass.
 *
 * @return array{0: int, 1: list<string>} [transmissions, causes]
 */
function verifactuForkRetryPasses(int $children, string $callLog): array
{
    $resultDir = sys_get_temp_dir() . '/verifactu_aid735_' . Str::random(8);
    mkdir($resultDir, 0755, true);

    // Absolute-time barrier. Forking in a loop releases each child the instant
    // it is born, so on a fast host child 1 can finish its whole pass before
    // child N exists — the passes never overlap and the lock is never disputed.
    // Releasing every child at one wall-clock instant is what makes this a
    // concurrency test instead of a sequential one.
    $startAt = microtime(true) + 1.0;

    $pids = [];
    for ($i = 0; $i < $children; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            // Never share the parent's PDO across the fork.
            DB::purge('testing');
            time_sleep_until($startAt);

            try {
                Artisan::call('verifactu:retry-failed');
                exit(0);
            } catch (Throwable $e) {
                // The REAL cause. A mute exit(1) turns every failure into "N
                // children failed", which is indistinguishable between a lock
                // defect, a deadlock and a broken fixture.
                file_put_contents(
                    $resultDir . '/' . getmypid() . '.cause',
                    get_class($e) . ': ' . $e->getMessage(),
                );
                exit(1);
            }
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $causes = [];
    foreach (glob($resultDir . '/*.cause') ?: [] as $file) {
        $causes[] = (string) file_get_contents($file);
        unlink($file);
    }
    rmdir($resultDir);

    $transmissions = is_file($callLog)
        ? count(array_filter(explode(PHP_EOL, (string) file_get_contents($callLog))))
        : 0;

    return [$transmissions, $causes];
}

it('never transmits one record twice when retry passes overlap', function () {
    $callLog = sys_get_temp_dir() . '/verifactu_aid735_calls_' . Str::random(8) . '.log';

    $qrGenerator = Mockery::mock(QrGeneratorContract::class);
    $qrGenerator->shouldReceive('generateUrl')->andReturn('https://example.test/qr');
    $qrGenerator->shouldReceive('generateSvg')->andReturn('<svg/>');
    $qrGenerator->shouldReceive('generatePng')->andReturn('png-binary');
    app()->instance(QrGeneratorContract::class, $qrGenerator);
    app()->instance(CertificateManagerContract::class, Mockery::mock(CertificateManagerContract::class));
    app()->instance(AeatClientContract::class, new RecordingAeatClient($callLog));

    // One record the agency has NOT got yet, left retryable exactly as a failed
    // submission leaves it since AID-717.
    $registry = (new RegistryManager(new HashGenerator, $qrGenerator, new XmlBuilder))
        ->createRegistry(Invoice::factory()->create());

    DB::table('verifactu_registries')->where('id', $registry->getId())->update([
        'status' => RegistryStatusEnum::ERROR->value,
        'aeat_error' => 'connection timed out',
        'submission_attempts' => 0,
    ]);

    [$transmissions, $causes] = verifactuForkRetryPasses(verifactuRetryForkCount(), $callLog);

    @unlink($callLog);

    expect($causes)->toBe([], 'a retry pass died: ' . implode(' | ', $causes));

    // The invariant, stated as the harm: the tax agency heard about this
    // registro exactly once, no matter how many passes raced for it.
    expect($transmissions)->toBe(1);

    expect(Registry::query()->whereKey($registry->getId())->first()->submission_attempts)->toBe(1);
});
