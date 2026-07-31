<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\AeatResponse;

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

    $this->aeatClient = Mockery::mock(AeatClientContract::class);

    $this->registrar = new InvoiceRegistrar(
        $this->registryManager,
        Mockery::mock(CertificateManagerContract::class),
        $this->aeatClient,
    );
});

/**
 * Create a REJECTED initial registration whose persisted XML is real, plus the
 * Invoice it was built from. Returns [$rejected, $invoice].
 */
function rejectedRegistration(RegistryManager $manager): array
{
    $invoice = Invoice::factory()->create();
    $registry = $manager->createRegistry($invoice);
    $registry->update([
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

    return [$registry->fresh(), $invoice];
}

it('amends a rejected registration with Subsanacion=S + RechazoPrevio=X', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);

    $this->aeatClient->shouldReceive('sendRegistration')->andReturn(
        new AeatResponse(success: true, code: 'CSV-OK', message: 'Correcto')
    );

    $amendment = $this->registrar->amendRejected($rejected, $invoice);
    $amendment->refresh();

    expect($amendment->getRegistryType())->toBe(RegistryTypeEnum::REGISTRATION)
        ->and($amendment->subsanacion)->toBeTrue()
        ->and($amendment->rechazo_previo)->toBe(RechazoPrevioEnum::X)
        ->and($amendment->amends_registry_id)->toBe($rejected->id)
        ->and($amendment->xml)->toContain('<sf:Subsanacion>S</sf:Subsanacion>')
        ->and($amendment->xml)->toContain('<sf:RechazoPrevio>X</sf:RechazoPrevio>');

    // Rejected record + its XML untouched.
    $rejected->refresh();
    expect($rejected->status)->toBe(RegistryStatusEnum::REJECTED)
        ->and($rejected->subsanacion)->toBeFalse();
});

it('chains the amendment after the last generated link, not the rejected record', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);
    $this->aeatClient->shouldReceive('sendRegistration')->andReturn(new AeatResponse(success: true, code: 'CSV', message: 'Correcto'));

    // Create an INTERVENING normal registry for a different invoice so the chain
    // advances past the rejected record.
    $interveningInvoice = Invoice::factory()->create();
    $intervening = $this->registryManager->createRegistry($interveningInvoice);

    $amendment = $this->registrar->amendRejected($rejected, $invoice);
    $amendment->refresh();

    // The amendment must chain after the last generated record (the intervening
    // one), NOT after the rejected business record.
    expect($amendment->previous_hash)->toBe($intervening->hash)
        ->and($amendment->previous_hash)->not->toBe($rejected->hash);
});

it('guard 1: rejects amending a cancellation registry', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);

    // Straight to the table: the seal lock (AID-220) refuses this through the
    // model, and rightly so — registry_type is part of what was presented, and
    // an alta and an anulación are different documents. The fixture needs the
    // state, not the write path.
    tamperRegistryColumns($rejected->getId(), [
        'registry_type' => RegistryTypeEnum::CANCELLATION->value,
    ]);

    expect(fn () => $this->registrar->amendRejected($rejected->fresh(), $invoice))
        ->toThrow(VerifactuException::class);
});

it('guard 2: rejects amending a non-REJECTED registry', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);
    $rejected->update(['status' => RegistryStatusEnum::ACCEPTED->value]);

    expect(fn () => $this->registrar->amendRejected($rejected->fresh(), $invoice))
        ->toThrow(VerifactuException::class);
});

it('guard 3: rejects when the rejection is a duplicate-key (key exists in AEAT)', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);
    $rejected->update([
        'aeat_response' => [
            'estado_envio' => 'Incorrecto',
            'lineas' => [['codigo' => '3000', 'registro_duplicado' => true]],
        ],
    ]);

    expect(fn () => $this->registrar->amendRejected($rejected->fresh(), $invoice))
        ->toThrow(VerifactuException::class);
});

it('guard 3: rejects when lineas is empty (cannot prove key is absent from AEAT)', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);
    $rejected->update([
        'aeat_response' => [
            'estado_envio' => 'Incorrecto',
            'lineas' => [],
        ],
    ]);

    expect(fn () => $this->registrar->amendRejected($rejected->fresh(), $invoice))
        ->toThrow(VerifactuException::class);
});

it('guard 3: rejects when a line is missing the registro_duplicado key (unknown shape)', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);
    $rejected->update([
        'aeat_response' => [
            'estado_envio' => 'Incorrecto',
            'lineas' => [['codigo' => '3002', 'descripcion' => 'NIF no identificado']],
        ],
    ]);

    expect(fn () => $this->registrar->amendRejected($rejected->fresh(), $invoice))
        ->toThrow(VerifactuException::class);
});

it('guard 4: rejects when the corrected invoice IDFactura does not match the persisted XML', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);

    // A different invoice (different number) => IDFactura mismatch vs rejected XML.
    $other = Invoice::factory()->create(['number' => 'DIFFERENT-001']);

    expect(fn () => $this->registrar->amendRejected($rejected, $other))
        ->toThrow(VerifactuException::class);
});

it('guard 5: rejects a second amendment of the same rejected registry', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);
    $this->aeatClient->shouldReceive('sendRegistration')->andReturn(new AeatResponse(success: true, code: 'CSV', message: 'Correcto'));

    $this->registrar->amendRejected($rejected, $invoice);

    expect(fn () => $this->registrar->amendRejected($rejected->fresh(), $invoice))
        ->toThrow(VerifactuException::class);
});

it('guard 5: rejects a second amendment even when the first amendment has been soft-deleted', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);
    $this->aeatClient->shouldReceive('sendRegistration')->andReturn(new AeatResponse(success: true, code: 'CSV', message: 'Correcto'));

    $firstAmendment = $this->registrar->amendRejected($rejected, $invoice);

    // Soft-delete the first amendment — the withTrashed() guard must still see it.
    //
    // Through the table, not the model: the amendment was submitted, so the
    // seal lock (AID-220) refuses $firstAmendment->delete(). The scenario this
    // test protects is still reachable — a query-builder write is the declared
    // limit of the seal — so the fixture now takes the path that can actually
    // produce it.
    tamperRegistryColumns($firstAmendment->getId(), ['deleted_at' => now()]);

    expect(fn () => $this->registrar->amendRejected($rejected->fresh(), $invoice))
        ->toThrow(VerifactuException::class);
});
