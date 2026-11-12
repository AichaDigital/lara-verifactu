<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Console;

use AichaDigital\LaraVerifactu\Support\ReportsActivation;
use Illuminate\Console\Command;

class VerifactuInstallCommand extends Command
{
    use ReportsActivation;

    protected $signature = 'verifactu:install
                            {--force : Force overwrite of existing files}
                            {--no-migrate : Skip running migrations}';

    protected $description = 'Install Lara-Verifactu package';

    public function handle(): int
    {
        $this->info('🚀 Installing Lara-Verifactu...');
        $this->newLine();

        // 1. Publicar configuración
        $this->info('📝 Publishing configuration...');
        $this->call('vendor:publish', [
            '--provider' => 'AichaDigital\\LaraVerifactu\\LaraVerifactuServiceProvider',
            '--tag' => 'verifactu-config',
            '--force' => $this->option('force'),
        ]);

        // 2. Publicar traducciones
        $this->info('🌐 Publishing translations...');
        $this->call('vendor:publish', [
            '--provider' => 'AichaDigital\\LaraVerifactu\\LaraVerifactuServiceProvider',
            '--tag' => 'verifactu-translations',
            '--force' => $this->option('force'),
        ]);

        // 3. Migrations are NOT published: the package runs them from vendor/
        //    (package-managed schema, AID-1886).

        // 4. Validar configuración
        $this->newLine();
        $this->info('🔍 Validating configuration...');

        $this->reportActivation();

        $environment = config('verifactu.aeat.environment', 'production');
        $this->info("✓ Environment: {$environment}");

        // 5. Ejecutar migraciones si no se especificó --no-migrate
        if (! $this->option('no-migrate')) {
            $this->newLine();
            if ($this->confirm('Run migrations now?', true)) {
                $this->info('🔄 Running migrations...');
                $exitCode = $this->call('migrate');

                if ($exitCode !== 0) {
                    $this->error('❌ Migration failed. Please check the errors above.');

                    return self::FAILURE;
                }
            }
        }

        $this->newLine();
        $this->info('✅ Lara-Verifactu installed successfully!');
        $this->newLine();
        $this->comment('Next steps:');
        $this->line('  - Configure your .env with VERIFACTU_* variables');
        $this->line('  - Add your AEAT certificate: storage/certs/verifactu.p12');
        $this->line('  - Test connection: php artisan verifactu:test-connection');
        $this->line('  - Check documentation: https://github.com/aichadigital/lara-verifactu');

        return self::SUCCESS;
    }
}
