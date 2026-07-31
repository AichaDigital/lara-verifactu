<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;
use AichaDigital\LaraVerifactu\Jobs\ProcessInvoiceRegistrationJob;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;

/**
 * The registry surface of Invoice (AID-734 + AID-741).
 *
 * Two orthogonal defects live here and they pull in opposite directions:
 *
 *  - AID-734 is PRESENTATION. `registry()` answers "what is this invoice's
 *    current record?" — the row the consumer panel reads huella, CSV and AEAT
 *    status off. A soft-deleted row must never surface there.
 *  - AID-741 is CHAIN. "Does a registration of record exist?" must count
 *    trashed rows, because the chain links over what EXISTED (AID-728) and
 *    RegistryManager::assertNoRootRegistration() already refuses on them.
 *
 * Which is why one cannot be fixed inside the other.
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

    $this->registrar = new InvoiceRegistrar(
        $this->registryManager,
        Mockery::mock(CertificateManagerContract::class),
        Mockery::mock(AeatClientContract::class),
    );
});

/**
 * An invoice whose registration was REJECTED and then amended (AID-137), so it
 * legitimately holds TWO rows with registry_type=registration.
 *
 * Returns [$invoice, $rejected, $amendment].
 */
function invoiceWithAmendment(RegistryManager $manager, InvoiceRegistrar $registrar): array
{
    $invoice = Invoice::factory()->create();
    $rejected = $manager->createRegistry($invoice);
    $rejected->update([
        'status' => RegistryStatusEnum::REJECTED->value,
        'aeat_response' => [
            'estado_envio' => 'Incorrecto',
            'lineas' => [[
                'estado_registro' => 'Incorrecto',
                'codigo' => '3002',
                'descripcion' => 'NIF del IDFactura no identificado',
                'registro_duplicado' => false,
            ]],
        ],
    ]);

    $amendment = $registrar->amendRejected($rejected->fresh(), $invoice, submitToAeat: false);

    return [$invoice->fresh(), $rejected->fresh(), $amendment];
}

// ---------------------------------------------------------------------------
// AID-734 — the relation must be deterministic
// ---------------------------------------------------------------------------

it('returns the amendment, not the rejected record, as the current registry', function () {
    [$invoice, $rejected, $amendment] = invoiceWithAmendment($this->registryManager, $this->registrar);

    // Asserted by MEANING, not by a literal id: the point is "the row that is
    // fiscally current", and the rejected one carries no CSV and a REJECTED
    // status. A panel reading the wrong one shows a superseded state.
    expect($invoice->registry)->not->toBeNull()
        ->and($invoice->registry->subsanacion)->toBeTrue()
        ->and($invoice->registry->amends_registry_id)->toBe($rejected->getId())
        ->and($invoice->registry->id)->toBe($amendment->getId())
        ->and($invoice->registry->id)->toBe(
            Registry::query()->where('invoice_id', $invoice->id)->max('id')
        );
});

it('returns the same current registry when eager loaded', function () {
    [$invoice, , $amendment] = invoiceWithAmendment($this->registryManager, $this->registrar);

    // Distinct code path from the lazy accessor: eager loading matches rows per
    // parent and takes the first of the ordered set (HasOneOrMany::getRelationValue
    // -> reset()), so it has to be pinned separately.
    $loaded = Invoice::query()->with('registry')->findOrFail($invoice->id);

    expect($loaded->relationLoaded('registry'))->toBeTrue()
        ->and($loaded->registry->id)->toBe($amendment->getId());
});

it('exposes every registry of the invoice through registries()', function () {
    [$invoice] = invoiceWithAmendment($this->registryManager, $this->registrar);

    // registry_type is cast to the enum, so pluck() yields cases, not strings.
    expect($invoice->registries()->count())->toBe(2)
        ->and($invoice->registries()->pluck('registry_type')->unique()->values()->all())
        ->toBe([RegistryTypeEnum::REGISTRATION]);
});

// ---------------------------------------------------------------------------
// AID-734 — the cascade must stay whole (pin: GREEN today, on purpose)
// ---------------------------------------------------------------------------

it('cascades the soft-delete to every registry the agency has not ruled on', function () {
    // This pin passes against the current code. It is NOT test-driven: it exists
    // because nothing guards the cascade today, and the obvious fix for the
    // relation would silently break it.
    //
    // latestOfMany()/ofMany() set isOneOfMany, which registers a beforeQuery
    // callback joining an aggregate subquery onto the relation's OWN builder. A
    // delete issued through the relation would then carry that join and touch a
    // single row, leaving the rest alive under a deleted invoice — and it
    // compiles without error, because SoftDeletingScope qualifies the column
    // when joins are present. ChainSoftDeleteTest does not catch it: its invoice
    // holds exactly one registry, where "the latest" and "all" are the same row.
    // Three rows: the REJECTED registration (the agency ruled on it, so the
    // seal lock of AID-220 keeps it), its amendment and a cancellation (both
    // PENDING, so both cascade).
    [$invoice] = invoiceWithAmendment($this->registryManager, $this->registrar);
    $this->registryManager->createCancellationRegistry($invoice);

    expect(Registry::query()->where('invoice_id', $invoice->id)->count())->toBe(3);

    $invoice->delete();

    // What matters for the trap is the COUNT that came through: two unsealed
    // rows, not one. With latestOfMany() the join narrows this to a single row
    // and the other survives alive under a deleted invoice.
    $alive = Registry::query()->where('invoice_id', $invoice->id)->get();

    expect($alive)->toHaveCount(1)
        ->and($alive->first()->status)->toBe(RegistryStatusEnum::REJECTED)
        ->and(Registry::withTrashed()->where('invoice_id', $invoice->id)->count())->toBe(3);
});

// ---------------------------------------------------------------------------
// AID-741 — "has a registration of record?" must count trashed rows
// ---------------------------------------------------------------------------

it('does not report an invoice whose registration was soft-deleted as pending', function () {
    $invoice = Invoice::factory()->create();
    $registry = $this->registryManager->createRegistry($invoice);
    $registry->delete();

    // The decisive argument is not taste: assertNoRootRegistration() counts
    // trashed rows, so register() refuses this invoice. Calling it "pending"
    // mints a work item that can only throw — and the job's failed() handler
    // logs that as "Fiscal verification system BLOCKED".
    expect(Invoice::pendingRegistration()->pluck('id')->all())
        ->not->toContain($invoice->id);
});

it('reports an invoice holding only a cancellation as pending', function () {
    // Deliberate consequence of filtering by registry_type: an invoice with a
    // cancellation but no alta has no registration of record, and register()
    // would accept it. doesntHave('registry') said otherwise and hid the
    // pathology.
    $invoice = Invoice::factory()->create();
    Registry::factory()->forInvoice($invoice)->create([
        'registry_type' => RegistryTypeEnum::CANCELLATION->value,
    ]);

    expect(Invoice::pendingRegistration()->pluck('id')->all())
        ->toContain($invoice->id);
});

it('skips the job guard for an invoice whose registration was soft-deleted', function () {
    $invoice = Invoice::factory()->create();
    $this->registryManager->createRegistry($invoice)->delete();

    // Driven with the REAL registrar on purpose: InvoiceRegistrar is final, so
    // it cannot be doubled, and the honest assertion is the outcome anyway.
    // With the guard broken this reaches register(), assertNoRootRegistration()
    // throws, and the job re-throws — so the failure surfaces as the actual
    // exception rather than as an unmet expectation.
    (new ProcessInvoiceRegistrationJob($invoice->id, submitToAeat: false))
        ->handle($this->registrar);

    // No second chain link was minted, and the trashed one still holds its slot.
    expect(Registry::withTrashed()->where('invoice_id', $invoice->id)->count())->toBe(1);
});

it('does not pick an invoice with a soft-deleted registration in verifactu:register --all', function () {
    $invoice = Invoice::factory()->create();
    $this->registryManager->createRegistry($invoice)->delete();

    $this->artisan('verifactu:register', ['--all' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('No pending invoices found');
});
