<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Seal lock (AID-220) — a record the agency has ruled on stays intact.
 *
 * RD 1007/2023 arts. 8 & 16: integridad, inalterabilidad, trazabilidad.
 * Corrections happen through a SUBSEQUENT record (RegistroAnulacion,
 * subsanación), never by mutating the original.
 *
 * The seal is scoped to the fiscal ARTEFACT — the bytes that were presented and
 * the identity they were presented under. The columns that record what the
 * agency answered (status, aeat_*, submission_attempts) stay writable: they are
 * the conversation, not the artefact, and the package's own transitions need
 * them. Those already have their own guards (AID-729).
 */
beforeEach(function () {
    config()->set('verifactu.company.tax_id', '89890001K');
    config()->set('verifactu.company.name', 'Empresa Ejemplo SL');
    config()->set('verifactu.system.vendor_name', 'AichaDigital SL');
    config()->set('verifactu.system.vendor_nif', 'B70123456');
    config()->set('verifactu.system.name', 'LaraVerifactu');
    config()->set('verifactu.system.id', 'LV');
    config()->set('verifactu.system.version', '1.0');
    config()->set('verifactu.system.installation_number', '1');

    $qrGenerator = Mockery::mock(QrGeneratorContract::class);
    $qrGenerator->shouldReceive('generateUrl')->andReturn('https://example.test/qr');
    $qrGenerator->shouldReceive('generateSvg')->andReturn('<svg/>');
    $qrGenerator->shouldReceive('generatePng')->andReturn('png-binary');

    $this->registryManager = new RegistryManager(new HashGenerator, $qrGenerator, new XmlBuilder);
});

/** A registry the agency has ruled on: SENT, with its CSV. */
function sealedRegistry(RegistryManager $manager, ?Invoice $invoice = null): Registry
{
    $registry = $manager->createRegistry($invoice ?? Invoice::factory()->create());

    // Straight to the table: going through the model would be blocked by the
    // very lock under test once it exists, and this mirrors how the row really
    // reaches SENT (markAsSubmitted, before any seal applies).
    DB::table('verifactu_registries')->where('id', $registry->getId())->update([
        'status' => RegistryStatusEnum::SENT->value,
        'submitted_at' => now(),
        'aeat_csv' => 'A-SEALED' . $registry->getId(),
    ]);

    return $registry->fresh();
}

// ---------------------------------------------------------------------------
// Deletion
// ---------------------------------------------------------------------------

it('refuses to soft-delete a registry the agency has ruled on', function () {
    $registry = sealedRegistry($this->registryManager);

    expect(fn () => $registry->delete())->toThrow(VerifactuException::class);

    expect(Registry::withTrashed()->whereKey($registry->getId())->first()->deleted_at)->toBeNull();
});

it('refuses to force-delete a registry the agency has ruled on', function () {
    $registry = sealedRegistry($this->registryManager);

    expect(fn () => $registry->forceDelete())->toThrow(VerifactuException::class);

    expect(Registry::withTrashed()->whereKey($registry->getId())->exists())->toBeTrue();
});

it('still allows deleting a registry the agency has not ruled on', function () {
    // PENDING is not sealed: nothing has been presented, so nothing is at risk.
    $registry = $this->registryManager->createRegistry(Invoice::factory()->create());

    $registry->delete();

    expect(Registry::withTrashed()->whereKey($registry->getId())->first()->deleted_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Mutation
// ---------------------------------------------------------------------------

it('refuses to rewrite the fiscal artefact of a ruled-on registry', function () {
    $registry = sealedRegistry($this->registryManager);

    // forceFill, not update(): AID-730 already took these out of $fillable, so
    // mass assignment cannot reach them. This is the path a data backfill or an
    // observer would take.
    expect(fn () => $registry->forceFill(['xml' => '<tampered/>'])->save())
        ->toThrow(VerifactuException::class);

    expect($registry->fresh()->xml)->not->toBe('<tampered/>');
});

it('refuses to rewrite the identity a ruled-on registry was filed under', function () {
    $registry = sealedRegistry($this->registryManager);

    expect(fn () => $registry->forceFill(['registry_number' => 'REG-TAMPERED'])->save())
        ->toThrow(VerifactuException::class);
});

it('still records what the agency answered on a ruled-on registry', function () {
    // The seal covers the artefact, not the conversation. Blocking these would
    // break the package's own markAs* transitions.
    $registry = sealedRegistry($this->registryManager);

    $registry->update([
        'aeat_response' => ['estado_envio' => 'Correcto'],
        'submission_attempts' => 2,
    ]);

    expect($registry->fresh()->submission_attempts)->toBe(2);
});

// ---------------------------------------------------------------------------
// The Invoice cascade
// ---------------------------------------------------------------------------

it('leaves a sealed registry intact when its invoice is deleted', function () {
    // The erasure/immutability split (docs/notes/lara-privacy-immutability-vs-erasure):
    // the invoice may go, the filed record may not. A soft-deleted invoice with
    // its sealed registry alive is the correct outcome, not a leak.
    $invoice = Invoice::factory()->create();
    $sealed = sealedRegistry($this->registryManager, $invoice);

    $invoice->delete();

    expect($invoice->fresh()->deleted_at)->not->toBeNull()
        ->and(Registry::withTrashed()->whereKey($sealed->getId())->first()->deleted_at)->toBeNull();
});

it('still cascades to registries the agency has not ruled on', function () {
    $invoice = Invoice::factory()->create();
    $pending = $this->registryManager->createRegistry($invoice);

    $invoice->delete();

    expect(Registry::withTrashed()->whereKey($pending->getId())->first()->deleted_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// The declared limit — pinned so the documentation cannot drift away from it
// ---------------------------------------------------------------------------

it('does NOT stop a query-builder delete, which is the declared limit of the seal', function () {
    // Eloquent model events do not fire for query-builder writes, so this path
    // is beyond reach by construction. It is pinned rather than hidden: the
    // README and CHANGELOG state the limit, and this test is what keeps that
    // statement true. Promising a guarantee the code does not give is the
    // mistake AID-725's comment made.
    $registry = sealedRegistry($this->registryManager);

    Registry::query()->whereKey($registry->getId())->delete();

    expect(Registry::withTrashed()->whereKey($registry->getId())->first()->deleted_at)->not->toBeNull();
});
