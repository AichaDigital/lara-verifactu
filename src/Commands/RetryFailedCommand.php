<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Commands;

use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Support\OverlapLockStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Retry Failed Registries Command
 *
 * Artisan command to retry failed registry submissions to AEAT.
 */
class RetryFailedCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'verifactu:retry-failed
                            {--max-attempts=3 : Maximum number of attempts before giving up}
                            {--limit=50 : Maximum number of registries to retry}';

    /**
     * The console command description.
     */
    protected $description = 'Retry failed registry submissions to AEAT';

    /**
     * Execute the console command.
     */
    public function handle(InvoiceRegistrar $registrar): int
    {
        // Never run two of these at once (AID-731). Candidate selection neither
        // claims nor locks, so two overlapping runs would hand the same record
        // to both and race to write its outcome. Since AID-717 there are
        // routinely records in ERROR to retry and this is the main recovery
        // path, so a short schedule interval makes the overlap real rather than
        // theoretical.
        //
        // A cache lock rather than the scheduler's withoutOverlapping(): the
        // consumer decides how this command is scheduled, and the package must
        // protect itself either way.
        // The lock is only worth what the store behind it is worth: on a
        // per-process store it is decorative, and silently so. Checked before
        // acquiring rather than after, so the operator is told instead of the
        // command reporting a success it did not serialise.
        OverlapLockStore::assertUsable('verifactu:retry-failed');

        $lock = Cache::lock('verifactu:retry-failed', (int) config('verifactu.lock.timeout', 300));

        if (! $lock->get()) {
            $this->warn('Another verifactu:retry-failed run is still in progress — skipping.');

            return self::SUCCESS;
        }

        try {
            return $this->retry($registrar);
        } finally {
            $lock->release();
        }
    }

    /**
     * Run the retry pass. Always called while holding the overlap lock.
     */
    private function retry(InvoiceRegistrar $registrar): int
    {
        $maxAttempts = (int) $this->option('max-attempts');
        $limit = (int) $this->option('limit');

        $this->info('Retrying failed registries...');
        $this->newLine();

        try {
            $results = $registrar->retryFailed($maxAttempts, $limit);

            if ($results['skipped'] === 0 && $results['success'] === 0 && $results['failed'] === 0) {
                $this->info('No failed registries to retry');

                return self::SUCCESS;
            }

            $this->info('✓ Retry process completed');
            $this->table(
                ['Result', 'Count'],
                [
                    ['Success', $results['success']],
                    ['Failed', $results['failed']],
                    ['Skipped (max attempts)', $results['skipped']],
                    ['Total', array_sum($results)],
                ]
            );

            return $results['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("✗ Failed to retry registries: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
