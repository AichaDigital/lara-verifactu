<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Jobs;

use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $registryId
    ) {
        $this->tries = config('verifactu.retry.max_attempts', 3);
        $this->timeout = config('verifactu.retry.timeout', 60);
        $this->onQueue(config('verifactu.queue.name', 'default'));
    }

    /**
     * Execute the job.
     */
    public function handle(InvoiceRegistrar $registrar): void
    {
        $registry = Registry::find($this->registryId);

        if (! $registry) {
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->warning('Registry not found for submission', [
                    'registry_id' => $this->registryId,
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
