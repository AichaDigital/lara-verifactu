<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Jobs;

use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Support\OverlapLockStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Retry Failed Registries Job
 *
 * Queue job to retry failed registry submissions.
 * Can be scheduled to run periodically.
 */
class RetryFailedRegistriesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1; // Only try once, the registries themselves have their own retry logic

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300; // 5 minutes for batch processing

    /**
     * Total submission budget for the pass, resolved when the job is created.
     */
    public readonly int $maxAttempts;

    /**
     * Create a new job instance.
     *
     * @param  int|null  $maxAttempts  Null reads verifactu.retry.max_submission_attempts (AID-1626).
     */
    public function __construct(
        ?int $maxAttempts = null,
        public readonly int $limit = 50
    ) {
        $this->maxAttempts = $maxAttempts ?? (int) config('verifactu.retry.max_submission_attempts', 3);
        $this->onQueue(config('verifactu.queue.name', 'default'));
    }

    /**
     * Execute the job.
     *
     * Takes the same overlap lock as `verifactu:retry-failed`: this job's
     * budget (the config one unless given) can be lower than the one the
     * scheduled command runs with, and a pass with the lower budget would
     * sweep rows the other is still retrying (AID-1626). When the lock is
     * held, nothing is touched.
     */
    public function handle(InvoiceRegistrar $registrar): void
    {
        OverlapLockStore::exclusively(
            'verifactu:retry-failed',
            function () use ($registrar): void {
                $this->retry($registrar);
            },
            function (): void {
                Log::channel(config('verifactu.logging.channel', 'single'))
                    ->warning('Another verifactu:retry-failed run is still in progress, skipping the retry job');
            },
        );
    }

    private function retry(InvoiceRegistrar $registrar): void
    {
        Log::channel(config('verifactu.logging.channel', 'single'))
            ->info('Starting retry of failed registries', [
                'max_attempts' => $this->maxAttempts,
                'limit' => $this->limit,
            ]);

        try {
            $results = $registrar->retryFailed($this->maxAttempts, $this->limit);

            Log::channel(config('verifactu.logging.channel', 'single'))
                ->info('Retry of failed registries completed', [
                    'success' => $results['success'],
                    'failed' => $results['failed'],
                    'skipped' => $results['skipped'],
                    'exhausted' => $results['exhausted'],
                ]);
        } catch (\Throwable $e) {
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->error('Failed to retry registries', [
                    'error' => $e->getMessage(),
                ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel(config('verifactu.logging.channel', 'single'))
            ->error('Retry failed registries job failed', [
                'error' => $exception->getMessage(),
            ]);
    }
}
