<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Jobs;

use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Support\OverlapLockStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Submit Registry To AEAT Job
 *
 * Queue job to submit a registry to AEAT asynchronously.
 */
class SubmitRegistryToAeatJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout;

    /**
     * How many sequential holds this copy has already served.
     *
     * A hold re-dispatches a FRESH copy (see requeueAfterHold) instead of
     * release(): a released job re-queues with attempts()+1 and the worker
     * kills it before handle() once attempts() > maxTries — with the default
     * `retry.max_attempts` of 1, one hold would be a terminal queue failure
     * with the registry left PENDING (AID-1671 gate, VERIFIED #1).
     */
    public int $holds = 0;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $registryId,
        int $holds = 0
    ) {
        $this->holds = $holds;
        $this->tries = config('verifactu.retry.max_attempts', 3);
        $this->timeout = config('verifactu.retry.timeout', 60);
        $this->onQueue(config('verifactu.queue.name', 'default'));
    }

    /**
     * Execute the job.
     *
     * Includes idempotency check to prevent duplicate submissions.
     *
     * Submissions keep chain order regardless of the worker count (AID-1671):
     * since AID-1664 consumers create the registry synchronously at issuance
     * and dispatch this job afterwards, this job is the only submission path of
     * the new flow — and it inherits the discipline ProcessInvoiceRegistrationJob
     * always had. Two mechanisms, for two different windows:
     *
     *  - the `fiscal_verification_queue` lock serialises jobs that overlap in
     *    time (same name and same assertUsable() gate as the old job, so both
     *    paths exclude each other);
     *  - the chain-order guard holds a record whose predecessor — the registry
     *    its `previous_hash` points at — has not been filed at the agency yet,
     *    the case of two workers picking records created one after another,
     *    where the lock alone never fires.
     */
    public function handle(InvoiceRegistrar $registrar): void
    {
        // The sequential-processing lock is only worth what the store behind it
        // is worth: on a per-process store it is decorative, and silently so.
        // Same gate and failure mode as ProcessInvoiceRegistrationJob.
        OverlapLockStore::assertUsable('fiscal_verification_queue');

        $lockTimeout = config('verifactu.lock.timeout', 300); // 5 minutes default
        $lock = Cache::lock('fiscal_verification_queue', $lockTimeout);

        if (! $lock->get()) {
            // Another submission is processing; retry shortly.
            $this->requeueAfterHold($registrar, 'fiscal verification lock is held', [
                'registry_id' => $this->registryId,
            ]);

            return;
        }

        try {
            $this->submitWhileHoldingLock($registrar);
        } finally {
            // A leaked lock would wedge every later submission until it expired.
            $lock->release();
        }
    }

    /**
     * Idempotency, chain-order guard and the actual submission. Runs while the
     * caller holds the `fiscal_verification_queue` lock.
     */
    protected function submitWhileHoldingLock(InvoiceRegistrar $registrar): void
    {
        $registry = Registry::find($this->registryId);

        if (! $registry) {
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->warning('Registry not found for submission', [
                    'registry_id' => $this->registryId,
                ]);

            return;
        }

        // Idempotency check: skip if the agency already holds it — SENT, or
        // ACCEPTED via a duplicate answer (AID-727).
        if ($registry->status->isFiledAtAeat()) {
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->debug('Registry already filed at AEAT, skipping job', [
                    'registry_id' => $this->registryId,
                    'registry_number' => $registry->registry_number,
                    'csv' => $registry->aeat_csv,
                ]);

            return;
        }

        // Chain order (AID-1671/1673): wait behind the predecessor this record
        // chains from until the agency has ruled on it. One shared
        // implementation in RegistryManager — the sweeper applies the same
        // guarantee, not a second copy of it.
        if ($this->hasUnfiledPredecessor($registry, $registrar)) {
            $this->requeueAfterHold($registrar, 'chain predecessor not filed at AEAT yet', [
                'registry_id' => $this->registryId,
                'registry_number' => $registry->registry_number,
                'previous_hash' => $registry->previous_hash,
            ]);

            return;
        }

        try {
            $response = $registrar->submitToAeat($registry);

            if ($response->isSuccess()) {
                Log::channel(config('verifactu.logging.channel', 'single'))
                    ->info('Registry submitted successfully via queue', [
                        'registry_id' => $this->registryId,
                        'registry_number' => $registry->registry_number,
                        'csv' => $response->getCsv(),
                    ]);
            }
        } catch (AeatException $e) {
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->error('Failed to submit registry via queue', [
                    'registry_id' => $this->registryId,
                    'error' => $e->getMessage(),
                    'attempt' => $this->attempts(),
                ]);

            // Re-throw to let Laravel handle retry logic
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }

    /**
     * Serve a sequential hold by re-dispatching a FRESH copy 10 seconds out.
     *
     * Assumes a real async queue connection, like the whole sequential
     * `fiscal_verification` design does: on a `sync` connection Laravel
     * executes the delayed dispatch inline, so the loop resolves immediately
     * within the bounded hold budget instead of scheduling a future job.
     *
     * NOT release(): a released job re-queues with attempts()+1 and the worker
     * fails it before handle() runs again — with the default
     * `retry.max_attempts` of 1, a routine hold (the guard path is the COMMON
     * case with several workers) would be a terminal queue failure. A fresh
     * copy starts its own attempt budget; the hold counter bounds the loop
     * when a predecessor never files — the give-up then marks the registry
     * ERROR so the default sweeper recovers it (AID-1673).
     *
     * @param  array<string, mixed>  $context
     */
    protected function requeueAfterHold(InvoiceRegistrar $registrar, string $reason, array $context): void
    {
        $channel = Log::channel(config('verifactu.logging.channel', 'single'));
        $maxHolds = (int) config('verifactu.retry.max_queue_holds', 60);

        if ($this->holds >= $maxHolds) {
            $channel->error('Submission hold budget exhausted, giving up; the registry is marked ERROR for the default sweeper', [
                'reason' => $reason,
                'holds' => $this->holds,
                ...$context,
            ]);

            // Abandonment IS a scheduling error: marking the registry ERROR is
            // what makes the default sweeper recover it (AID-1673). Leaving it
            // PENDING stranded the record forever with `resend_pending` off.
            // PENDING stays reserved for rows created with submission off
            // (AID-1645). markAsFailed is idempotent against filed outcomes.
            $registry = Registry::find($this->registryId);

            if ($registry && ! $registry->status->isFiledAtAeat()) {
                $registrar->markSchedulingAbandoned($registry);
            }

            return;
        }

        $channel->debug('Submission held, re-dispatching a fresh copy in 10 seconds', [
            'reason' => $reason,
            'holds' => $this->holds,
            ...$context,
        ]);

        self::dispatch($this->registryId, $this->holds + 1)->delay(10);
    }

    /**
     * Whether the registry this record chains from (its `previous_hash`, a
     * unique `hash`) is still awaiting its agency verdict.
     *
     * A thin delegate: the implementation lives in
     * RegistryManager::hasUnfiledChainPredecessor() so the job and the retry
     * sweeper share ONE chain-order guard (AID-1673).
     */
    protected function hasUnfiledPredecessor(Registry $registry, InvoiceRegistrar $registrar): bool
    {
        return $registrar->hasUnfiledChainPredecessor($registry);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel(config('verifactu.logging.channel', 'single'))
            ->error('Registry submission job failed permanently', [
                'registry_id' => $this->registryId,
                'error' => $exception->getMessage(),
                'attempts' => $this->attempts(),
            ]);
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $delay = config('verifactu.retry.delay', 60);

        // Exponential backoff: 1min, 2min, 4min
        return [
            $delay,
            $delay * 2,
            $delay * 4,
        ];
    }
}
