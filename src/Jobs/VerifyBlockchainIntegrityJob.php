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
 * Verify Blockchain Integrity Job
 *
 * Queue job to verify blockchain integrity.
 * Can be scheduled to run periodically for monitoring.
 */
class VerifyBlockchainIntegrityJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600; // 10 minutes for large chains

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue(config('verifactu.queue.name', 'default'));
    }

    /**
     * Execute the job.
     */
    public function handle(InvoiceRegistrar $registrar): void
    {
        Log::channel(config('verifactu.logging.channel', 'single'))
            ->info('Starting blockchain integrity verification');

        try {
            $result = $registrar->verifyBlockchain();

            if ($result['valid']) {
                Log::channel(config('verifactu.logging.channel', 'single'))
                    ->info('Blockchain integrity verified successfully');
            } else {
                Log::channel(config('verifactu.logging.channel', 'single'))
                    ->error('Blockchain integrity verification failed', [
                        'errors' => $result['errors'],
                    ]);
            }
        } catch (\Throwable $e) {
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->error('Failed to verify blockchain integrity', [
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
            ->error('Blockchain integrity verification job failed', [
                'error' => $exception->getMessage(),
            ]);
    }
}
