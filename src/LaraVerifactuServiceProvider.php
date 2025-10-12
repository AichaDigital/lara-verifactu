<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaraVerifactuServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('lara-verifactu')
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations()
            ->hasMigrations([
                '2025_01_01_000001_create_verifactu_invoices_table',
                '2025_01_01_000002_create_verifactu_registries_table',
                '2025_01_01_000003_create_verifactu_invoice_breakdowns_table',
            ])
            // ->hasCommands([
            //     InstallCommand::class,
            //     SendPendingCommand::class,
            //     RetryFailedCommand::class,
            //     ValidateChainCommand::class,
            //     SyncCommand::class,
            // ])
            ->hasInstallCommand(function (\Spatie\LaravelPackageTools\Commands\InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('aichadigital/lara-verifactu');
            });
    }

    public function packageRegistered(): void
    {
        $this->registerContracts();
    }

    public function packageBooted(): void
    {
        $this->bootEvents();
    }

    protected function registerContracts(): void
    {
        $this->app->bind(
            \AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract::class,
            \AichaDigital\LaraVerifactu\Services\HashGenerator::class
        );

        $this->app->bind(
            \AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract::class,
            \AichaDigital\LaraVerifactu\Services\QrGenerator::class
        );

        $this->app->bind(
            \AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract::class,
            \AichaDigital\LaraVerifactu\Services\XmlBuilder::class
        );

        $this->app->bind(
            \AichaDigital\LaraVerifactu\Contracts\AeatClientContract::class,
            \AichaDigital\LaraVerifactu\Services\AeatClient::class
        );

        $this->app->bind(
            \AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract::class,
            \AichaDigital\LaraVerifactu\Services\CertificateManager::class
        );

        $this->app->singleton('verifactu', function ($app) {
            return new \AichaDigital\LaraVerifactu\Verifactu;
        });
    }

    protected function bootEvents(): void
    {
        // Register event listeners if needed
    }
}
