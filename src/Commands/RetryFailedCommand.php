<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Commands;

use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use Illuminate\Console\Command;

/**
 * Retry Failed Registries Command
 *
 * Artisan command to retry failed registry submissions to AEAT.
 */
class RetryFailedCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'verifactu:retry-failed
                            {--max-attempts=3 : Maximum number of attempts before giving up}
                            {--limit=50 : Maximum number of registries to retry}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry failed registry submissions to AEAT';

    /**
     * Execute the console command.
     */
    public function handle(InvoiceRegistrar $registrar): int
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
