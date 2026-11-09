<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use AichaDigital\LaraVerifactu\Exceptions\PublishedMigrationsException;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Refuses to migrate while the consumer keeps published copies of this
 * package's migrations (AID-1886).
 *
 * Until 1.5.x the package copied its migrations into the consumer under fresh
 * timestamps. Since 1.6.0 it runs them from vendor/. The two sets have
 * different file names, so Laravel would treat the package's files as pending
 * and try to create tables that already exist — failing half-way through a
 * deploy. This guard stops the run before the first migration, and names the
 * files to remove.
 */
final class PublishedMigrationsGuard
{
    public function __construct(private readonly Migrator $migrator) {}

    public static function packageMigrationsPath(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }

    /**
     * @throws PublishedMigrationsException
     */
    public function assertNoPublishedCopies(): void
    {
        $copies = $this->publishedCopies();

        if ($copies !== []) {
            throw PublishedMigrationsException::found($copies);
        }
    }

    /**
     * Consumer files whose name, without the timestamp, matches a migration
     * this package ships.
     *
     * @return list<string>
     */
    public function publishedCopies(): array
    {
        $packagePath = (string) realpath(self::packageMigrationsPath());

        $shipped = array_map(
            fn (string $file): string => self::withoutTimestamp(basename($file)),
            File::glob($packagePath . '/*.php') ?: [],
        );

        $copies = [];

        foreach ($this->consumerPaths($packagePath) as $path) {
            foreach (File::glob($path . '/*.php') ?: [] as $file) {
                if (in_array(self::withoutTimestamp(basename($file)), $shipped, true)) {
                    $copies[] = $file;
                }
            }
        }

        sort($copies);

        return array_values(array_unique($copies));
    }

    /**
     * Every migration directory the migrator knows, plus the app's default,
     * except this package's own folder.
     *
     * @return list<string>
     */
    private function consumerPaths(string $packagePath): array
    {
        $paths = array_merge([database_path('migrations')], $this->migrator->paths());

        $directories = [];

        foreach ($paths as $path) {
            $real = realpath($path);

            if ($real === false || ! is_dir($real) || $real === $packagePath) {
                continue;
            }

            $directories[] = $real;
        }

        return array_values(array_unique($directories));
    }

    private static function withoutTimestamp(string $fileName): string
    {
        return Str::of($fileName)->replaceMatches('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '')->toString();
    }
}
