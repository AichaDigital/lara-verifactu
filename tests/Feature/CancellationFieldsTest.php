<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Exceptions\ValidationException;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\CancellationRecord;
use AichaDigital\LaraVerifactu\Support\RegistryChain;
use Carbon\Carbon;

/**
 * AID-182 — RegistroAnulacion circumstance fields (sheet09-b operativa):
 *   SinRegistroPrevio=S, RechazoPrevio=S, and the GeneradoPor (E/D/T) + Generador
 *   (NombreRazon + NIF) group. Coupling and identification rules: 1224
 *   (GeneradoPor <-> Generador), 1227/1228 (Generador NIF; IDOtro post-1.0), 1259
 *   (Generador NIF != ObligadoEmision). Subsanacion / RechazoPrevio=X are alta-only
 *   and intentionally absent.
 */
beforeEach(function () {
    config()->set('verifactu.company.tax_id', '89890001K');
    config()->set('verifactu.company.name', 'Empresa Ejemplo SL');
    config()->set('verifactu.system.vendor_name', 'AichaDigital SL');
    config()->set('verifactu.system.vendor_nif', 'B70123456');
    config()->set('verifactu.system.name', 'LaraVerifactu');
    config()->set('verifactu.system.id', 'LV');
    config()->set('verifactu.system.version', '0.4.0');
    config()->set('verifactu.system.installation_number', '1');

    $this->builder = new XmlBuilder;
});

function cancelChain(): RegistryChain
{
    return new RegistryChain(
        hash: str_repeat('A', 64),
        generatedAt: Carbon::parse('2026-06-02T09:00:00+02:00'),
        previous: null,
    );
}

it('builds a plain anulación without the circumstance fields (no regression)', function () {
    $record = new CancellationRecord('89890001K', 'FAC-2026-001', Carbon::parse('2026-06-01'));

    $xml = $this->builder->buildCancellationXml($record, cancelChain());

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->not->toContain('SinRegistroPrevio')
        ->and($xml)->not->toContain('RechazoPrevio')
        ->and($xml)->not->toContain('GeneradoPor')
        ->and($xml)->not->toContain('Generador');
});

it('emits SinRegistroPrevio=S when set', function () {
    $record = new CancellationRecord('89890001K', 'FAC-2026-001', Carbon::parse('2026-06-01'), withoutPriorRegistry: true);

    $xml = $this->builder->buildCancellationXml($record, cancelChain());

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain('<sf:SinRegistroPrevio>S</sf:SinRegistroPrevio>');
});

it('emits RechazoPrevio=S when set', function () {
    $record = new CancellationRecord('89890001K', 'FAC-2026-001', Carbon::parse('2026-06-01'), rejectedPreviously: true);

    $xml = $this->builder->buildCancellationXml($record, cancelChain());

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain('<sf:RechazoPrevio>S</sf:RechazoPrevio>');
});

it('emits both circumstance flags (anulación por rechazo sin registro previo)', function () {
    $record = new CancellationRecord('89890001K', 'FAC-2026-001', Carbon::parse('2026-06-01'), withoutPriorRegistry: true, rejectedPreviously: true);

    $xml = $this->builder->buildCancellationXml($record, cancelChain());

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain('<sf:SinRegistroPrevio>S</sf:SinRegistroPrevio>')
        ->and($xml)->toContain('<sf:RechazoPrevio>S</sf:RechazoPrevio>')
        // XSD order: SinRegistroPrevio < RechazoPrevio.
        ->and(strpos($xml, 'SinRegistroPrevio'))->toBeLessThan(strpos($xml, 'RechazoPrevio'));
});

it('emits GeneradoPor + Generador and validates against the XSD', function (string $generatedBy) {
    $record = new CancellationRecord(
        '89890001K', 'FAC-2026-001', Carbon::parse('2026-06-01'),
        generatedBy: $generatedBy, generatorName: 'Tercero Generador SL', generatorNif: '12345678Z',
    );

    $xml = $this->builder->buildCancellationXml($record, cancelChain());

    expect($this->builder->validate($xml))->toBeTrue()
        ->and($xml)->toContain("<sf:GeneradoPor>{$generatedBy}</sf:GeneradoPor>")
        ->and($xml)->toContain('<sf:Generador>')
        ->and($xml)->toContain('<sf:NombreRazon>Tercero Generador SL</sf:NombreRazon>')
        ->and($xml)->toContain('<sf:NIF>12345678Z</sf:NIF>')
        // XSD order: GeneradoPor < Generador < Encadenamiento.
        ->and(strpos($xml, 'GeneradoPor'))->toBeLessThan(strpos($xml, '<sf:Generador>'))
        ->and(strpos($xml, '<sf:Generador>'))->toBeLessThan(strpos($xml, 'Encadenamiento'));
})->with(['E', 'D', 'T']);

it('rejects GeneradoPor without a Generador NIF (rule 1227/1228)', function () {
    $record = new CancellationRecord(
        '89890001K', 'FAC-2026-001', Carbon::parse('2026-06-01'),
        generatedBy: 'T', generatorName: 'Tercero Generador SL', generatorNif: null,
    );

    expect(fn () => $this->builder->buildCancellationXml($record, cancelChain()))
        ->toThrow(ValidationException::class, "field 'generator_nif'");
});

it('rejects GeneradoPor without a Generador name', function () {
    $record = new CancellationRecord(
        '89890001K', 'FAC-2026-001', Carbon::parse('2026-06-01'),
        generatedBy: 'T', generatorName: null, generatorNif: '12345678Z',
    );

    expect(fn () => $this->builder->buildCancellationXml($record, cancelChain()))
        ->toThrow(ValidationException::class, "field 'generator_name'");
});

it('rejects an invalid GeneradoPor value', function () {
    $record = new CancellationRecord(
        '89890001K', 'FAC-2026-001', Carbon::parse('2026-06-01'),
        generatedBy: 'X', generatorName: 'Tercero Generador SL', generatorNif: '12345678Z',
    );

    expect(fn () => $this->builder->buildCancellationXml($record, cancelChain()))
        ->toThrow(ValidationException::class, "field 'generated_by'");
});

it('rejects Generador data without GeneradoPor (rule 1224 coupling)', function (array $extra) {
    $record = new CancellationRecord('89890001K', 'FAC-2026-001', Carbon::parse('2026-06-01'), ...$extra);

    expect(fn () => $this->builder->buildCancellationXml($record, cancelChain()))
        ->toThrow(ValidationException::class, "field 'generated_by'");
})->with([
    'name only' => [['generatorName' => 'Huérfano SL']],
    'nif only' => [['generatorNif' => '12345678Z']],
]);

it('rejects a Generador NIF equal to the ObligadoEmision NIF (rule 1259)', function () {
    $record = new CancellationRecord(
        '89890001K', 'FAC-2026-001', Carbon::parse('2026-06-01'),
        generatedBy: 'E', generatorName: 'Empresa Ejemplo SL', generatorNif: '89890001K',
    );

    expect(fn () => $this->builder->buildCancellationXml($record, cancelChain()))
        ->toThrow(ValidationException::class, "field 'generator_nif'");
});
