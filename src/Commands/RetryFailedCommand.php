<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Commands;

use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Support\OverlapLockStore;
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
     */
    protected $signature = 'verifactu:retry-failed
                            {--max-attempts= : Total submission budget per registry for this run (default: verifactu.retry.max_submission_attempts)}
                            {--limit=50 : Maximum number of registries to resend}
                            {--include-exhausted : Also give each exhausted registry one more attempt}';

    /**
     * The console command description.
     */
    protected $description = 'Resend pending and failed registry submissions to AEAT';

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
        // protect itself either way. RetryFailedRegistriesJob takes the same
        // lock (AID-1626); see OverlapLockStore::exclusively().
        return OverlapLockStore::exclusively(
            'verifactu:retry-failed',
            fn (): int => $this->retry($registrar),
            function (): int {
                $this->warn('Another verifactu:retry-failed run is still in progress — skipping.');

                return self::SUCCESS;
            },
        );
    }

    /**
     * Run the retry pass. Always called while holding the overlap lock.
     */
    private function retry(InvoiceRegistrar $registrar): int
    {
        $rawMaxAttempts = $this->option('max-attempts');
        $maxAttempts = $rawMaxAttempts === null ? null : $this->positiveInt($rawMaxAttempts);
        $limit = $this->positiveInt($this->option('limit'));

        if ($rawMaxAttempts !== null && $maxAttempts === null) {
            $this->error("--max-attempts must be a whole number of at least 1, got '" . $this->raw($rawMaxAttempts) . "'.");

            return self::FAILURE;
        }

        if ($limit === null) {
            $this->error("--limit must be a whole number of at least 1, got '" . $this->raw($this->option('limit')) . "'.");

            return self::FAILURE;
        }

        $this->info('Retrying failed registries...');
        $this->newLine();

        try {
            $results = $registrar->retryFailed(
                $maxAttempts,
                $limit,
                (bool) $this->option('include-exhausted'),
            );

            if ($results['success'] === 0 && $results['failed'] === 0 && $results['exhausted'] === 0 && $results['skipped'] === 0) {
                $this->info('No failed registries to retry');

                return self::SUCCESS;
            }

            $this->info('✓ Retry process completed');
            $this->table(
                ['Result', 'Count'],
                [
                    ['Success', $results['success']],
                    ['Failed', $results['failed']],
                    ['Skipped', $results['skipped']],
                    ['Swept to exhausted', $results['exhausted']],
                    ['Total resent', $results['success'] + $results['failed']],
                ]
            );

            return $results['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("✗ Failed to retry registries: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * A strict positive integer, or null: `(int)` would keep the leading digits
     * of '1O' or '18x' and run with a budget nobody asked for.
     */
    private function positiveInt(mixed $value): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $parsed === false ? null : $parsed;
    }

    private function raw(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : gettype($value);
    }
}
