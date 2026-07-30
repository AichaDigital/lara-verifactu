<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\RegistryManager;

/**
 * AID-715 — how the internal registry number is derived.
 *
 * registry_number is NOT an AEAT field: the number declared to the tax agency
 * is NumSerieFactura, which comes from the issuer's own invoice number
 * (XmlBuilder::buildRegistrationXml). This is a package-internal identifier,
 * UNIQUE at database level.
 *
 * It used to be COUNT(*) + 1 over the day, which broke in two independent ways.
 * The concurrency half (a gap lock over the counted range) can only be shown
 * with real processes and lives in tests/Concurrency. These are the
 * deterministic guards for the other half.
 */
beforeEach(function () {
    $this->manager = app(RegistryManager::class);
});

it('issues a correlative number per day', function () {
    $first = $this->manager->createRegistry(Invoice::factory()->create());
    $second = $this->manager->createRegistry(Invoice::factory()->create());

    $today = now()->format('Ymd');

    expect($first->registry_number)->toBe("REG-{$today}-000001")
        ->and($second->registry_number)->toBe("REG-{$today}-000002");
});

it('never reuses the number of a soft-deleted registry', function () {
    // Registry uses SoftDeletes, but the UNIQUE index on registry_number does
    // not: a soft-deleted row keeps holding its number. Deriving the next one
    // from COUNT(*) — which the SoftDeletingScope filters — handed out a number
    // that was still taken, and the insert died on the unique constraint. No
    // concurrency needed: one soft delete is enough.
    $this->manager->createRegistry(Invoice::factory()->create());
    $second = $this->manager->createRegistry(Invoice::factory()->create());

    $second->delete();

    expect($second->trashed())->toBeTrue();

    $third = $this->manager->createRegistry(Invoice::factory()->create());

    $today = now()->format('Ymd');

    expect($third->registry_number)->toBe("REG-{$today}-000003")
        ->and(Registry::withTrashed()->pluck('registry_number')->unique())
        ->toHaveCount(3);
});
