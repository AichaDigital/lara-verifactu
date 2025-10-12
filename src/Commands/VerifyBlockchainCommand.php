<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Commands;

use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use Illuminate\Console\Command;

/**
 * Verify Blockchain Command
 *
 * Artisan command to verify blockchain integrity.
 */
class VerifyBlockchainCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'verifactu:verify-blockchain';

    /**
     * The console command description.
     */
    protected $description = 'Verify the integrity of the Verifactu blockchain';

    /**
     * Execute the console command.
     */
    public function handle(InvoiceRegistrar $registrar): int
    {
        $this->info('Verifying blockchain integrity...');
        $this->newLine();

        try {
            $result = $registrar->verifyBlockchain();

            if ($result['valid']) {
                $this->info('✓ Blockchain is valid');

                return self::SUCCESS;
            }

            $this->error('✗ Blockchain validation failed');
            $this->newLine();
            $this->error('Errors found:');

            foreach ($result['errors'] as $error) {
                $this->line("  • {$error}");
            }

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("✗ Failed to verify blockchain: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
