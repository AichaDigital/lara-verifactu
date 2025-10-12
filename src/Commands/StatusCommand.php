<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Commands;

use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use Illuminate\Console\Command;

/**
 * Status Command
 *
 * Artisan command to show Verifactu system status.
 */
class StatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'verifactu:status
                            {--recent=10 : Number of recent registries to show}';

    /**
     * The console command description.
     */
    protected $description = 'Show Verifactu system status and statistics';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->displaySystemInfo();
        $this->newLine();
        $this->displayStatistics();
        $this->newLine();
        $this->displayRecentRegistries();

        return self::SUCCESS;
    }

    /**
     * Display system information.
     */
    private function displaySystemInfo(): void
    {
        $this->info('=== Verifactu System Status ===');
        $this->newLine();

        $config = [
            ['Mode', config('verifactu.mode', 'native')],
            ['Environment', config('verifactu.aeat_client.environment', 'test')],
            ['Queue Enabled', config('verifactu.queue.enabled', false) ? 'Yes' : 'No'],
            ['Queue Name', config('verifactu.queue.name', 'default')],
            ['Certificate', file_exists(config('verifactu.certificate.path', '')) ? 'Found' : 'Not Found'],
        ];

        $this->table(['Setting', 'Value'], $config);
    }

    /**
     * Display statistics.
     */
    private function displayStatistics(): void
    {
        $this->info('=== Statistics ===');
        $this->newLine();

        $totalInvoices = Invoice::count();
        $totalRegistries = Registry::count();
        $pendingRegistries = Registry::where('status', RegistryStatusEnum::PENDING->value)->count();
        $sentRegistries = Registry::where('status', RegistryStatusEnum::SENT->value)->count();
        $errorRegistries = Registry::where('status', RegistryStatusEnum::ERROR->value)->count();
        $unregisteredInvoices = Invoice::doesntHave('registry')->count();

        $stats = [
            ['Total Invoices', $totalInvoices],
            ['Unregistered Invoices', $unregisteredInvoices],
            ['Total Registries', $totalRegistries],
            ['Pending Registries', $pendingRegistries],
            ['Sent Registries', $sentRegistries],
            ['Error Registries', $errorRegistries],
        ];

        $this->table(['Metric', 'Count'], $stats);
    }

    /**
     * Display recent registries.
     */
    private function displayRecentRegistries(): void
    {
        $limit = (int) $this->option('recent');

        $this->info("=== Recent Registries (Last {$limit}) ===");
        $this->newLine();

        $registries = Registry::with('invoice')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        if ($registries->isEmpty()) {
            $this->info('No registries found');

            return;
        }

        $data = $registries->map(function (Registry $registry) {
            return [
                $registry->registry_number,
                $registry->invoice->number ?? 'N/A',
                $registry->status->value,
                substr($registry->hash, 0, 12) . '...',
                $registry->created_at->format('Y-m-d H:i'),
            ];
        })->toArray();

        $this->table(
            ['Registry Number', 'Invoice', 'Status', 'Hash', 'Created At'],
            $data
        );
    }
}
