<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Console\Commands;

use AichaDigital\LaraVerifactu\Jobs\ProcessInvoiceRegistrationJob;
use AichaDigital\LaraVerifactu\Models\Invoice;
use Illuminate\Console\Command;

class RetryFailedVerificationCommand extends Command
{
    protected $signature = 'verifactu:retry-failed
                            {invoice_id? : Specific invoice ID to retry}
                            {--all : Retry all failed invoices}
                            {--serie= : Filter by serie}
                            {--from= : Start date (Y-m-d)}
                            {--to= : End date (Y-m-d)}
                            {--dry-run : Show what would be retried without actually retrying}';

    protected $description = 'Manually retry failed fiscal verification for invoices';

    public function handle(): int
    {
        $this->info('🔍 Searching for failed verifications...');

        $query = Invoice::query()
            ->whereDoesntHave('registry')
            ->orderBy('serie')
            ->orderBy('issue_date');

        // Filter by specific invoice
        $invoiceId = $this->argument('invoice_id');
        if ($invoiceId !== null) {
            $query->where('id', $invoiceId);
        }

        // Filter by serie
        $serie = $this->option('serie');
        if ($serie !== null) {
            $query->where('serie', $serie);
        }

        // Filter by date range
        $from = $this->option('from');
        if ($from && is_string($from)) {
            $query->whereDate('issue_date', '>=', $from);
        }

        $to = $this->option('to');
        if ($to && is_string($to)) {
            $query->whereDate('issue_date', '<=', $to);
        }

        $invoices = $query->get();

        if ($invoices->isEmpty()) {
            $this->info('✅ No failed verifications found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Serie', 'Number', 'Issue Date', 'Amount'],
            $invoices->map(fn ($inv) => [
                $inv->id,
                $inv->serie,
                $inv->number,
                $inv->issue_date->format('Y-m-d'),
                number_format($inv->total_amount, 2) . ' ' . $inv->currency,
            ])
        );

        $this->warn("⚠️  Found {$invoices->count()} invoice(s) without verification.");

        if ($this->option('dry-run')) {
            $this->info('🏃 Dry run mode - no jobs dispatched.');

            return self::SUCCESS;
        }

        if (! $this->option('all') && ! $this->argument('invoice_id')) {
            if (! $this->confirm('Do you want to retry these invoices?')) {
                $this->info('❌ Operation cancelled.');

                return self::FAILURE;
            }
        }

        $this->info('📤 Dispatching retry jobs...');

        $progressBar = $this->output->createProgressBar($invoices->count());
        $progressBar->start();

        foreach ($invoices as $invoice) {
            ProcessInvoiceRegistrationJob::dispatch($invoice->id, false);
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("✅ Dispatched {$invoices->count()} retry job(s) to 'fiscal_verification' queue.");
        $this->warn('⚠️  Monitor queue worker logs to ensure sequential processing.');

        return self::SUCCESS;
    }
}
