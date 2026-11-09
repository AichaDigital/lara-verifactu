<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Exceptions\PublishedMigrationsException;
use AichaDigital\LaraVerifactu\LaraVerifactuServiceProvider;
use AichaDigital\LaraVerifactu\Support\PublishedMigrationsGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/*
 * The package owns its schema (`package-managed`, AID-1886): it runs its
 * migrations from vendor/, publishes none, and refuses to migrate while a
 * consumer keeps the copies older releases published.
 */

/**
 * @return list<string>
 */
function packageManagedShippedMigrations(): array
{
    return array_map(
        fn (string $file): string => basename($file, '.php'),
        File::glob(PublishedMigrationsGuard::packageMigrationsPath() . '/*.php'),
    );
}

/**
 * Writes a file into the app's migrations folder and returns a cleanup that
 * removes it (and the folder, when the test created it).
 */
function packageManagedPlantAppMigration(string $fileName, string $contents): Closure
{
    $directory = database_path('migrations');
    $createdDirectory = ! File::isDirectory($directory);

    File::ensureDirectoryExists($directory);
    File::put($directory . '/' . $fileName, $contents);

    return function () use ($directory, $fileName, $createdDirectory): void {
        File::delete($directory . '/' . $fileName);

        if ($createdDirectory) {
            File::deleteDirectory($directory);
        }
    };
}

it('runs every shipped migration from the package folder, under the package file names', function () {
    $ran = DB::table('migrations')->pluck('migration')->all();

    expect(packageManagedShippedMigrations())
        ->not->toBeEmpty()
        ->each(fn ($migration) => $migration->toBeIn($ran));

    expect(Schema::hasTable('verifactu_registries'))->toBeTrue()
        ->and(Schema::hasTable('verifactu_chain_locks'))->toBeTrue();
});

it('publishes no migrations', function () {
    expect(ServiceProvider::pathsToPublish(LaraVerifactuServiceProvider::class, 'lara-verifactu-migrations'))
        ->toBe([]);
});

it('is idempotent: a second migrate runs nothing', function () {
    $before = DB::table('migrations')->count();

    expect(Artisan::call('migrate'))->toBe(0);

    expect(DB::table('migrations')->count())->toBe($before);
});

it('refuses to migrate while a published copy remains, before running anything', function () {
    $shipped = PublishedMigrationsGuard::packageMigrationsPath() . '/2026_06_26_000001_create_verifactu_chain_locks_table.php';
    $copy = '2026_02_14_185846_create_verifactu_chain_locks_table.php';
    $cleanup = packageManagedPlantAppMigration($copy, File::get($shipped));

    $before = DB::table('migrations')->count();

    try {
        expect(fn () => Artisan::call('migrate'))->toThrow(
            PublishedMigrationsException::class,
            database_path('migrations/' . $copy),
        );

        expect(DB::table('migrations')->count())->toBe($before);
    } finally {
        $cleanup();
    }
});

it('ignores app migrations that are not copies of the package ones', function () {
    $cleanup = packageManagedPlantAppMigration(
        '2026_11_07_000000_create_app_owned_table.php',
        "<?php\n\nreturn new class extends Illuminate\\Database\\Migrations\\Migration {};\n",
    );

    try {
        expect(app(PublishedMigrationsGuard::class)->publishedCopies())->toBe([]);
    } finally {
        $cleanup();
    }
});

it('verifactu:install no longer publishes migrations', function () {
    $migrations = database_path('migrations');
    $config = config_path('verifactu.php');
    $lang = lang_path('vendor/lara-verifactu');
    $hadMigrations = File::isDirectory($migrations);
    $hadConfig = File::exists($config);
    $hadLang = File::isDirectory($lang);
    $before = $hadMigrations ? count(File::files($migrations)) : 0;

    try {
        Artisan::call('verifactu:install', ['--no-migrate' => true]);

        $after = File::isDirectory($migrations) ? count(File::files($migrations)) : 0;

        expect($after)->toBe($before)
            ->and(app(PublishedMigrationsGuard::class)->publishedCopies())->toBe([]);
    } finally {
        // The command publishes config and translations into the testbench
        // skeleton; leave it as it was.
        if (! $hadConfig) {
            File::delete($config);
        }
        if (! $hadLang) {
            File::deleteDirectory($lang);
        }
        if (! $hadMigrations && File::isDirectory($migrations)) {
            File::deleteDirectory($migrations);
        }
    }
});
