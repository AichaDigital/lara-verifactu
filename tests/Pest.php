<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(TestCase::class)->in(__DIR__);
uses(RefreshDatabase::class)->in('Feature');

/**
 * Rewrite registry columns straight in the table, bypassing the model.
 *
 * Since AID-730 the chain's integrity attributes (`hash`, `previous_hash`,
 * `hash_generated_at`, `xml`, `signed_xml`) are out of `$fillable`, so
 * `update()` no longer reaches them — which is the protection working. Tests
 * that need to SIMULATE tampering must therefore do it the way it would really
 * arrive: a data backfill, a migration, a query run by hand.
 *
 * Named with the `tamper` prefix on purpose. Pest's file-level functions live in
 * the suite's global namespace, so a generic name here would collide with a
 * same-named helper in another test file.
 *
 * @param  array<string, mixed>  $columns
 */
function tamperRegistryColumns(int|string $registryId, array $columns): void
{
    DB::table('verifactu_registries')
        ->where('id', $registryId)
        ->update($columns);
}
