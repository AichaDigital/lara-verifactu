<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu;

use AichaDigital\LaraVerifactu\Commands\RegisterInvoiceCommand;
use AichaDigital\LaraVerifactu\Commands\RetryFailedCommand;
use AichaDigital\LaraVerifactu\Commands\StatusCommand;
use AichaDigital\LaraVerifactu\Commands\TestAeatConnectionCommand;
use AichaDigital\LaraVerifactu\Commands\VerifyBlockchainCommand;
use AichaDigital\LaraVerifactu\Console\VerifactuInstallCommand;
use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract;
use AichaDigital\LaraVerifactu\Events\BlockchainVerifiedEvent;
use AichaDigital\LaraVerifactu\Events\InvoiceRegisteredEvent;
use AichaDigital\LaraVerifactu\Events\RegistryCreatedEvent;
use AichaDigital\LaraVerifactu\Events\RegistryFailedEvent;
use AichaDigital\LaraVerifactu\Events\RegistrySubmittedEvent;
use AichaDigital\LaraVerifactu\Listeners\LogBlockchainVerification;
use AichaDigital\LaraVerifactu\Listeners\LogInvoiceRegistration;
use AichaDigital\LaraVerifactu\Listeners\LogRegistryCreation;
use AichaDigital\LaraVerifactu\Listeners\LogRegistryFailure;
use AichaDigital\LaraVerifactu\Listeners\LogRegistrySubmission;
use AichaDigital\LaraVerifactu\Services\AeatClient;
use AichaDigital\LaraVerifactu\Services\CertificateManager;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\QrGenerator;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use Illuminate\Events\Dispatcher;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaraVerifactuServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('lara-verifactu')
            ->hasConfigFile('verifactu')
            ->hasTranslations()
            ->hasMigrations([
                '2025_01_01_000001_create_verifactu_invoices_table',
                '2025_01_01_000002_create_verifactu_registries_table',
                '2025_01_01_000003_create_verifactu_invoice_breakdowns_table',
                '2026_01_25_000001_consolidate_issue_datetime_in_verifactu_invoices',
            ])
            ->hasCommand(RegisterInvoiceCommand::class)
            ->hasCommand(RetryFailedCommand::class)
            ->hasCommand(VerifyBlockchainCommand::class)
            ->hasCommand(StatusCommand::class)
            ->hasCommand(TestAeatConnectionCommand::class)
            ->hasInstallCommand(function (InstallCommand $command): void {
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

        // Register install command manually
        if ($this->app->runningInConsole()) {
            $this->commands([
                VerifactuInstallCommand::class,
            ]);
        }
    }

    protected function registerContracts(): void
    {
        $this->app->bind(
            HashGeneratorContract::class,
            HashGenerator::class
        );

        $this->app->bind(
            QrGeneratorContract::class,
            QrGenerator::class
        );

        $this->app->bind(
            XmlBuilderContract::class,
            XmlBuilder::class
        );

        $this->app->bind(
            AeatClientContract::class,
            AeatClient::class
        );

        $this->app->bind(
            CertificateManagerContract::class,
            CertificateManager::class
        );

        $this->app->singleton('verifactu', function ($app) {
            return $app->make(Verifactu::class);
        });
    }

    protected function bootEvents(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make('events');

        // Register event listeners
        $events->listen(
            InvoiceRegisteredEvent::class,
            LogInvoiceRegistration::class
        );

        $events->listen(
            RegistryCreatedEvent::class,
            LogRegistryCreation::class
        );

        $events->listen(
            RegistrySubmittedEvent::class,
            LogRegistrySubmission::class
        );

        $events->listen(
            RegistryFailedEvent::class,
            LogRegistryFailure::class
        );

        $events->listen(
            BlockchainVerifiedEvent::class,
            LogBlockchainVerification::class
        );
    }
}
