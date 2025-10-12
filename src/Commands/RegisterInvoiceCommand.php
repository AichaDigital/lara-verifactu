<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Commands;

use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use Illuminate\Console\Command;

/**
 * Register Invoice Command
 *
 * Artisan command to register invoices in the Verifactu system.
 */
class RegisterInvoiceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'verifactu:register
                            {invoice? : Invoice ID to register}
                            {--all : Register all pending invoices}
                            {--no-submit : Create registry without submitting to AEAT}
                            {--batch=100 : Number of invoices to process in batch mode}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register invoices in the Verifactu system';

    /**
     * Execute the console command.
     */
    public function handle(InvoiceRegistrar $registrar): int
    {
        $invoiceId = $this->argument('invoice');
        $registerAll = $this->option('all');
        $submitToAeat = ! $this->option('no-submit');
        $batchSize = (int) $this->option('batch');

        if ($invoiceId) {
            return $this->registerSingleInvoice($registrar, (int) $invoiceId, $submitToAeat);
        }

        if ($registerAll) {
            return $this->registerAllPendingInvoices($registrar, $batchSize, $submitToAeat);
        }

        $this->error('Please specify an invoice ID or use --all flag');
        $this->info('Usage:');
        $this->line('  php artisan verifactu:register 123');
        $this->line('  php artisan verifactu:register --all');
        $this->line('  php artisan verifactu:register --all --no-submit');

        return self::FAILURE;
    }

    /**
     * Register a single invoice.
     */
    private function registerSingleInvoice(
        InvoiceRegistrar $registrar,
        int $invoiceId,
        bool $submitToAeat
    ): int {
        $invoice = Invoice::find($invoiceId);

        if (! $invoice) {
            $this->error("Invoice {$invoiceId} not found");

            return self::FAILURE;
        }

        $this->info("Registering invoice {$invoice->number}...");

        try {
            $registry = $registrar->register($invoice, $submitToAeat);

            $this->info('✓ Invoice registered successfully');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Registry Number', $registry->getRegistryNumber()],
                    ['Hash', substr($registry->getHash(), 0, 16) . '...'],
                    ['Status', $registry->getStatus()->value],
                    ['AEAT CSV', $registry->getAeatCsv() ?? 'N/A'],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("✗ Failed to register invoice: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Register all pending invoices.
     */
    private function registerAllPendingInvoices(
        InvoiceRegistrar $registrar,
        int $batchSize,
        bool $submitToAeat
    ): int {
        // Get invoices without registry
        $invoices = Invoice::doesntHave('registry')
            ->limit($batchSize)
            ->get();

        if ($invoices->isEmpty()) {
            $this->info('No pending invoices found');

            return self::SUCCESS;
        }

        $this->info("Found {$invoices->count()} pending invoices");
        $this->newLine();

        $progressBar = $this->output->createProgressBar($invoices->count());
        $progressBar->start();

        $results = $registrar->batchRegister($invoices->all(), $submitToAeat);

        $progressBar->finish();
        $this->newLine(2);

        $this->info("✓ Processed {$invoices->count()} invoices");
        $this->table(
            ['Result', 'Count'],
            [
                ['Success', $results['success']],
                ['Failed', $results['failed']],
            ]
        );

        return $results['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
