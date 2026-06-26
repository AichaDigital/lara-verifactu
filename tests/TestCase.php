<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Tests;

use AichaDigital\LaraVerifactu\LaraVerifactuServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        $this->configureOpenSslRandFile();
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'AichaDigital\\LaraVerifactu\\Database\\Factories\\' . class_basename($modelName) . 'Factory'
        );
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->closeOpenTestDatabaseConnection();
        }
    }

    private function configureOpenSslRandFile(): void
    {
        if (getenv('RANDFILE') !== false) {
            return;
        }

        $randFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/.rnd';

        if (! file_exists($randFile)) {
            if (@touch($randFile) === false) {
                return;
            }
        }

        if (! is_writable($randFile)) {
            return;
        }

        putenv('RANDFILE=' . $randFile);
        $_ENV['RANDFILE'] = $randFile;
        $_SERVER['RANDFILE'] = $randFile;
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaraVerifactuServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => env('DB_DRIVER', 'mariadb'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'verifactu_test'),
            'username' => env('DB_USERNAME', 'verifactu'),
            'password' => env('DB_PASSWORD', 'secret'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'options' => [
                \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION lock_wait_timeout = 5, innodb_lock_wait_timeout = 5',
            ],
        ]);
    }

    private function isFeatureTest(): bool
    {
        return str_contains(static::class, '\\Feature\\');
    }

    /**
     * Define database migrations.
     */
    protected function defineDatabaseMigrations(): void
    {
        if (! $this->isFeatureTest()) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    private function closeOpenTestDatabaseConnection(): void
    {
        if (! isset($this->app) || ! $this->app->bound('db')) {
            return;
        }

        $defaultConnection = (string) config('database.default');
        $connectionNames = array_keys((array) config('database.connections', []));
        if ($defaultConnection !== '') {
            $connectionNames[] = $defaultConnection;
        }
        $connectionNames[] = 'testing';

        foreach ($connectionNames as $connectionName) {
            if ($connectionName === '') {
                continue;
            }

            try {
                DB::disconnect($connectionName);
            } catch (\Throwable) {
                // Ignore cleanup errors to keep teardown safe.
            }
        }
    }
}
