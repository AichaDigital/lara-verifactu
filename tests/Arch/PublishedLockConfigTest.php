<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Guards the published lock config against keys the package never reads
 * (AID-746).
 *
 * A published key is a promise: a consumer who sets VERIFACTU_LOCK_ENABLED=false
 * reasonably believes the sequential-processing lock is off. Until v1.3.0 the
 * package shipped `lock.enabled` and `lock.retry_delay` without a single read
 * of either — the switch did not exist. This test requires every key published
 * under `lock` to have a matching config() read somewhere in src/.
 *
 * It reads config/verifactu.php from disk on purpose: the merged config()
 * repository is already mixed with the test environment and would not catch a
 * reintroduced dead key in the published file.
 */
it('reads every key it publishes under verifactu.lock', function () {
    $root = dirname(__DIR__, 2);

    /** @var array{lock: array<string, mixed>} $published */
    $published = require $root . '/config/verifactu.php';

    $sources = '';

    foreach (File::allFiles($root . '/src') as $file) {
        if ($file->getExtension() === 'php') {
            $sources .= $file->getContents();
        }
    }

    $deadKeys = array_values(array_filter(
        array_keys($published['lock']),
        fn (string $key): bool => ! str_contains($sources, "verifactu.lock.{$key}"),
    ));

    expect($deadKeys)->toBe([]);
});
