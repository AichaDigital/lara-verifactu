<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\LaraVerifactuServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

/**
 * Every migration the package ships must be publishable (AID-1626).
 *
 * The suite loads the whole migrations folder, so a migration missing from
 * hasMigrations() passes CI and is never copied to a consumer — it happened
 * with AID-186. This compares the folder against the publish group.
 */
it('publishes every migration it ships', function () {
    $published = array_map(
        fn (string $path): string => basename($path),
        array_keys(ServiceProvider::pathsToPublish(LaraVerifactuServiceProvider::class, 'lara-verifactu-migrations')),
    );

    $shipped = array_map(
        fn (string $path): string => basename($path),
        File::glob(dirname(__DIR__, 2) . '/database/migrations/*.php'),
    );

    expect($published)->toEqualCanonicalizing($shipped);
});
