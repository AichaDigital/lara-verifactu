<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Jobs;

use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
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
     *
     * @var int
     */
    public $tries = 1; // Only try once, the registries themselves have their own retry logic

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 300; // 5 minutes for batch processing

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly int $limit = 50
    ) {
        $this->onQueue(config('verifactu.queue.name', 'default'));
    }

    /**
     * Execute the job.
     */
    public function handle(InvoiceRegistrar $registrar): void
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
