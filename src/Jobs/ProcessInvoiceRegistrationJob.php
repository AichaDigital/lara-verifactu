<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Jobs;

use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Support\AeatLogSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Process Invoice Registration Job
 *
 * Queue job to process complete invoice registration (create registry + submit to AEAT).
 *
 * v2.0 Changes:
 * - Enforces sequential processing with unique lock
 * - Validates invoice order before processing
 * - No automatic retries (manual intervention required)
 * - Dedicated 'fiscal_verification' queue
 */
class ProcessInvoiceRegistrationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * v2.0: Changed from 3 to 1 - no automatic retries.
     * Manual retry required via command after error resolution.
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
        public readonly int $invoiceId,
        public readonly bool $submitToAeat = true
    ) {
        // v2.0: Default tries changed from 3 to 1
        $this->tries = config('verifactu.retry.max_attempts', 1);
        $this->timeout = config('verifactu.retry.timeout', 60);

        // v2.0: Default queue changed from 'default' to 'fiscal_verification'
        $this->onQueue(config('verifactu.queue.name', 'fiscal_verification'));
    }

    /**
     * Execute the job.
     */
    public function handle(InvoiceRegistrar $registrar): void
    {
        // v2.0: Acquire unique lock to ensure sequential processing
        $lockTimeout = config('verifactu.lock.timeout', 300); // 5 minutes default
        $lock = Cache::lock('fiscal_verification_queue', $lockTimeout);

        if (! $lock->get()) {
            // Another job is already processing, retry in 10 seconds
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->debug('Fiscal verification lock is held, retrying...', [
                    'invoice_id' => $this->invoiceId,
                ]);

            $this->release(10);

            return;
        }

        try {
            $invoice = Invoice::find($this->invoiceId);

            if (! $invoice) {
                Log::channel(config('verifactu.logging.channel', 'single'))
                    ->warning('Invoice not found for registration', [
                        'invoice_id' => $this->invoiceId,
                    ]);

                return;
            }

            // Check if already registered
            if ($invoice->registry()->exists()) {
                Log::channel(config('verifactu.logging.channel', 'single'))
                    ->debug('Invoice already has a registry, skipping', [
                        'invoice_id' => $this->invoiceId,
                        'invoice_number' => $invoice->number,
                    ]);

                return;
            }

            // v2.0: Enforce sequential order - CRITICAL validation
            $this->ensureSequentialOrder($invoice);

            // Register invoice
            $registry = $registrar->register($invoice, $this->submitToAeat);

            Log::channel(config('verifactu.logging.channel', 'single'))
                ->info('Invoice registered successfully via queue', [
                    'invoice_id' => $this->invoiceId,
                    'invoice_number' => $invoice->number,
                    'registry_number' => $registry->getRegistryNumber(),
                ]);
        } catch (\Throwable $e) {
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->error('Failed to register invoice via queue', [
                    'invoice_id' => $this->invoiceId,
                    'error' => AeatLogSanitizer::redactText($e->getMessage()),
                    'attempt' => $this->attempts(),
                    ...AeatLogSanitizer::traceContext($e),
                ]);

            // Release lock before re-throwing
            $lock->release();

            // v2.0: No automatic retry - re-throw to fail job
            throw $e;
        } finally {
            // Always release lock
            $lock->release();
        }
    }

    /**
     * v2.0: Ensure invoices are processed in sequential order.
     *
     * Validates that no previous invoices in the same serie/fiscal year
     * are pending registration. This is CRITICAL for fiscal compliance.
     *
     * @throws \RuntimeException if previous invoices are not registered
     */
    protected function ensureSequentialOrder(Invoice $invoice): void
    {
        // Extract fiscal year from issue_datetime
        $fiscalYear = $invoice->issue_datetime->year;

        // Parse invoice number to extract sequential part
        // Assumes format like "FAC-2025-000047" or similar
        // TODO: Make this configurable or use a dedicated sequential field
        $currentNumber = $this->extractSequentialNumber($invoice->number);

        if ($currentNumber === null) {
            // If we can't extract a sequential number, skip validation
            // This allows for non-sequential invoice numbers
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->warning('Could not extract sequential number from invoice, skipping order validation', [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->number,
                ]);

            return;
        }

        // Find any previous invoices in same serie/year without registry
        $previousUnregistered = Invoice::where('serie', $invoice->serie)
            ->whereYear('issue_datetime', $fiscalYear)
            ->where('id', '<', $invoice->id) // Use ID as fallback for ordering
            ->whereDoesntHave('registry')
            ->exists();

        if ($previousUnregistered) {
            throw new \RuntimeException(
                "Cannot register invoice {$invoice->number}. " .
                "Previous invoices in serie '{$invoice->serie}' (fiscal year {$fiscalYear}) are not registered yet. " .
                'Sequential order must be maintained for fiscal compliance.'
            );
        }

        Log::channel(config('verifactu.logging.channel', 'single'))
            ->debug('Sequential order validated', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'serie' => $invoice->serie,
                'fiscal_year' => $fiscalYear,
            ]);
    }

    /**
     * Extract sequential number from invoice number string.
     *
     * Attempts to extract a numeric sequential part from invoice number.
     * Returns null if no sequential number can be extracted.
     */
    protected function extractSequentialNumber(string $invoiceNumber): ?int
    {
        // Try to extract last numeric part (e.g., "FAC-2025-000047" -> 47)
        if (preg_match('/(\d+)$/', $invoiceNumber, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel(config('verifactu.logging.channel', 'single'))
            ->error('Invoice registration job failed permanently', [
                'invoice_id' => $this->invoiceId,
                'error' => $exception->getMessage(),
                'attempts' => $this->attempts(),
            ]);

        // v2.0: Notify admin - system is now BLOCKED
        Log::channel(config('verifactu.logging.channel', 'single'))
            ->critical('Fiscal verification system BLOCKED - manual intervention required', [
                'invoice_id' => $this->invoiceId,
                'error' => $exception->getMessage(),
            ]);
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * v2.0: Kept for compatibility, but tries=1 means this won't be used.
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
