<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class VerifactuInstallCommand extends Command
{
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

        // 3. Publicar migraciones
        $this->info('📄 Publishing migrations...');
        $published = $this->publishMigrations();

        if ($published === 0) {
            $this->comment('⚠ No new migrations to publish (use --force to overwrite)');
        }

        // 4. Validar configuración
        $this->newLine();
        $this->info('🔍 Validating configuration...');

        if (! config('verifactu.enabled')) {
            $this->warn('⚠ Verifactu is DISABLED in config. Set VERIFACTU_ENABLED=true to enable.');
        }

        $environment = config('verifactu.environment');
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

    protected function publishMigrations(): int
    {
        $packagePath = dirname(__DIR__, 3) . '/database/migrations';
        $targetPath = database_path('migrations');
        $timestamp = now();

        $migrations = [
            'create_verifactu_invoices_table',
            'create_verifactu_registries_table',
            'create_verifactu_invoice_breakdowns_table',
        ];

        $published = 0;

        foreach ($migrations as $index => $migrationName) {
            // Buscar archivo (con o sin prefijo de fecha)
            $sourceFile = null;

            // Buscar con wildcard
            $matches = File::glob("{$packagePath}/*_{$migrationName}.php");
            if (count($matches) > 0) {
                $sourceFile = $matches[0];
            }

            if (! $sourceFile || ! File::exists($sourceFile)) {
                $this->warn("⚠ Migration not found: {$migrationName}");

                continue;
            }

            // Generar timestamp incremental
            $migrationTimestamp = $timestamp->copy()->addSeconds($index + 1);
            $targetFile = $targetPath . '/' . $migrationTimestamp->format('Y_m_d_His') . '_' . $migrationName . '.php';

            // No sobrescribir si existe y no se pasó --force
            if (File::exists($targetFile) && ! $this->option('force')) {
                continue;
            }

            // Copiar archivo
            File::copy($sourceFile, $targetFile);
            $published++;
        }

        $this->info("✓ Published {$published} migrations");

        return $published;
    }
}
