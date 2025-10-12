<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Jobs;

use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process Invoice Registration Job
 *
 * Queue job to process complete invoice registration (create registry + submit to AEAT).
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
     * @var int
     */
    public $tries;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $invoiceId,
        public readonly bool $submitToAeat = true
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
                ->info('Invoice already has a registry, skipping', [
                    'invoice_id' => $this->invoiceId,
                    'invoice_number' => $invoice->number,
                ]);

            return;
        }

        try {
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
            ->error('Invoice registration job failed permanently', [
                'invoice_id' => $this->invoiceId,
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
