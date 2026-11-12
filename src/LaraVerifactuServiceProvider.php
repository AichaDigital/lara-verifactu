<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu;

use AichaDigital\LaraVerifactu\Commands\RegisterInvoiceCommand;
use AichaDigital\LaraVerifactu\Commands\RetryFailedCommand;
use AichaDigital\LaraVerifactu\Commands\StatusCommand;
use AichaDigital\LaraVerifactu\Commands\TestAeatConnectionCommand;
use AichaDigital\LaraVerifactu\Commands\VerifyBlockchainCommand;
use AichaDigital\LaraVerifactu\Console\VerifactuInstallCommand;
use AichaDigital\LaraVerifactu\Contracts\ActivationContract;
use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\EndpointResolverContract;
use AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract;
use AichaDigital\LaraVerifactu\Events\BlockchainVerifiedEvent;
use AichaDigital\LaraVerifactu\Events\InvoiceRegisteredEvent;
use AichaDigital\LaraVerifactu\Events\RegistryCreatedEvent;
use AichaDigital\LaraVerifactu\Events\RegistryFailedEvent;
use AichaDigital\LaraVerifactu\Events\RegistrySubmittedEvent;
use AichaDigital\LaraVerifactu\Exceptions\ConfigurationException;
use AichaDigital\LaraVerifactu\Listeners\LogBlockchainVerification;
use AichaDigital\LaraVerifactu\Listeners\LogInvoiceRegistration;
use AichaDigital\LaraVerifactu\Listeners\LogRegistryCreation;
use AichaDigital\LaraVerifactu\Listeners\LogRegistryFailure;
use AichaDigital\LaraVerifactu\Listeners\LogRegistrySubmission;
use AichaDigital\LaraVerifactu\Services\AeatClient;
use AichaDigital\LaraVerifactu\Services\CertificateManager;
use AichaDigital\LaraVerifactu\Services\ConfigEndpointResolver;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\QrGenerator;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\Activation;
use AichaDigital\LaraVerifactu\Support\LazyAeatClient;
use AichaDigital\LaraVerifactu\Support\PublishedMigrationsGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\MigrationsStarted;
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
            ->hasCommand(RegisterInvoiceCommand::class)
            ->hasCommand(RetryFailedCommand::class)
            ->hasCommand(VerifyBlockchainCommand::class)
            ->hasCommand(StatusCommand::class)
            ->hasCommand(TestAeatConnectionCommand::class)
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
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
        $this->bootMigrations();
        $this->bootEvents();

        // Register install command manually
        if ($this->app->runningInConsole()) {
            $this->commands([
                VerifactuInstallCommand::class,
            ]);
        }
    }

    /**
     * The package owns its schema (`package-managed`, AID-1886).
     *
     * Migrations run straight from this package's folder in vendor/, so
     * `composer update` + `php artisan migrate` is the whole upgrade. Loading
     * the folder, not a list, means a new migration cannot be forgotten in the
     * provider (AID-186). Nothing is published to the consumer.
     */
    protected function bootMigrations(): void
    {
        $this->loadMigrationsFrom(PublishedMigrationsGuard::packageMigrationsPath());

        /** @var Dispatcher $events */
        $events = $this->app->make('events');

        $events->listen(MigrationsStarted::class, function (MigrationsStarted $event): void {
            if ($event->method === 'up') {
                $this->app->make(PublishedMigrationsGuard::class)->assertNoPublishedCopies();
            }
        });
    }

    protected function registerContracts(): void
    {
        $this->app->bind(
            HashGeneratorContract::class,
            HashGenerator::class
        );

        $this->app->bind(QrGeneratorContract::class, function (): QrGenerator {
            $environment = (string) config('verifactu.aeat.environment', 'production');

            /** @var string $validationUrl */
            $validationUrl = config('verifactu.qr.validation_url')
                ?? config(
                    "verifactu.qr.validation_urls.{$environment}",
                    'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR',
                );

            return new QrGenerator(
                validationUrl: $validationUrl,
                format: (string) config('verifactu.qr.format', 'png'),
                size: (int) config('verifactu.qr.size', 300),
            );
        });

        $this->app->bind(
            XmlBuilderContract::class,
            XmlBuilder::class
        );

        // The single read point of verifactu.enabled (AID-1907). It reads the
        // config on every call, so the singleton caches nothing but itself.
        $this->app->singleton(ActivationContract::class, Activation::class);

        $this->app->singleton(EndpointResolverContract::class, ConfigEndpointResolver::class);

        $this->app->bind(AeatClientContract::class, function ($app): LazyAeatClient {
            $factory = static function () use ($app): AeatClient {
                $environment = (string) config('verifactu.aeat.environment', 'production');
                $certificateType = (string) config('verifactu.certificate.type', 'representante');

                /** @var EndpointResolverContract $endpointResolver */
                $endpointResolver = $app->make(EndpointResolverContract::class);

                return new AeatClient(
                    endpoint: $endpointResolver->resolve($environment, $certificateType),
                    timeout: (int) config('verifactu.aeat.timeout', 30),
                    verifySSL: (bool) config('verifactu.aeat.verify_ssl', true),
                );
            };

            // Enabled at resolution: built now, exactly as before 1.7, so an
            // invalid AEAT configuration still fails here. Disabled (or an
            // unparseable switch, which every guard will refuse anyway): held
            // unresolved, so nothing that merely resolves the registrar can
            // fail on AEAT configuration before an activation guard (AID-1907).
            return $this->activeAtResolution($app)
                ? LazyAeatClient::eager($factory)
                : LazyAeatClient::deferred($factory);
        });

        $this->app->bind(
            CertificateManagerContract::class,
            CertificateManager::class
        );

        $this->app->singleton('verifactu', function ($app) {
            return $app->make(Verifactu::class);
        });
    }

    /**
     * Whether the switch is on at the moment the AEAT client binding resolves.
     * An unparseable value defers the client instead of throwing here: read
     * methods must keep working, and every write guard reports the value.
     */
    private function activeAtResolution(Application $app): bool
    {
        try {
            return $app->make(ActivationContract::class)->isEnabled();
        } catch (ConfigurationException) {
            return false;
        }
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
