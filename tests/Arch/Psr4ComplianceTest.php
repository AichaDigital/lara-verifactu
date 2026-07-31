<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Guards against classes the Composer autoloader silently skips (AID-743).
 *
 * `composer dump-autoload -o` prints a "does not comply with psr-4" warning
 * once and moves on: the file stays on disk, `class_exists()` returns false,
 * and nothing fails. RetryFailedVerificationCommand survived that way since
 * before v1.0 — declared under Console\Commands while living in src/Console/.
 * This test turns the warning into a red build.
 *
 * Scope is the production `autoload` map only, on purpose: it is the contract
 * with consumers. autoload-dev is excluded because test files may define
 * inline doubles (e.g. RecordingAeatClient inside the gated fork test) that
 * load with their file, never through the autoloader — and a genuinely
 * misplaced test class self-detects: the suite errors on first reference.
 */
it('declares every autoloadable class where its PSR-4 mapping expects it', function () {
    $root = dirname(__DIR__, 2);

    /** @var array{autoload: array{'psr-4': array<string, string>}} $composer */
    $composer = json_decode(File::get($root . '/composer.json'), true);

    $violations = [];

    foreach ($composer['autoload']['psr-4'] as $prefix => $dir) {
        $base = $root . '/' . rtrim($dir, '/');

        if (! File::isDirectory($base)) {
            continue;
        }

        foreach (File::allFiles($base) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = $file->getContents();

            preg_match('/^namespace\s+([^;\s]+)\s*;/m', $source, $namespace);
            preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $type);

            if ($namespace === [] || $type === []) {
                continue;
            }

            $declared = $namespace[1] . '\\' . $type[1];
            $expected = $prefix . str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));

            if ($declared !== $expected) {
                $violations[$dir . $file->getRelativePathname()] = "declares {$declared}, PSR-4 resolves {$expected}";
            }
        }
    }

    expect($violations)->toBe([]);
});
