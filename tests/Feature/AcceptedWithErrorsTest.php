<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\RegistryStatusChangedEvent;
use AichaDigital\LaraVerifactu\Events\RegistrySubmittedEvent;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\AeatResponseParser;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use Illuminate\Support\Facades\Event;

/**
 * AID-1626 — ParcialmenteCorrecto / AceptadoConErrores used to be stored as
 * `sent` with aeat_response = "ParcialmenteCorrecto", discarding the per-line
 * codes. It is now its own outcome, persisted with the agency's lines.
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
    $this->aeatClient = Mockery::mock(AeatClientContract::class);

    $this->registrar = new InvoiceRegistrar(
        $this->registryManager,
        Mockery::mock(CertificateManagerContract::class),
        $this->aeatClient,
    );
});

it('persists a ParcialmenteCorrecto answer as accepted_with_errors, with its lines', function () {
    $invoice = Invoice::factory()->create();

    $this->aeatClient->shouldReceive('sendRegistration')->once()->andReturn((new AeatResponseParser)->parse((object) [
        'CSV' => 'CSV-AWE-0001',
        'EstadoEnvio' => 'ParcialmenteCorrecto',
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'AceptadoConErrores',
            'CodigoErrorRegistro' => '2003',
            'DescripcionErrorRegistro' => 'Huella incorrecta',
        ],
    ]));

    $this->registrar->register($invoice);

    $registry = Registry::query()->where('invoice_id', $invoice->id)->firstOrFail();

    expect($registry->status)->toBe(RegistryStatusEnum::ACCEPTED_WITH_ERRORS)
        ->and($registry->aeat_csv)->toBe('CSV-AWE-0001')
        ->and($registry->aeat_error)->toBeNull()
        ->and($registry->submitted_at)->not->toBeNull()
        ->and($registry->submission_attempts)->toBe(1)
        ->and($registry->aeat_response)->toBe([
            'estado_envio' => 'ParcialmenteCorrecto',
            'csv' => 'CSV-AWE-0001',
            'lineas' => [[
                'estado_registro' => 'AceptadoConErrores',
                'codigo' => '2003',
                'descripcion' => 'Huella incorrecta',
                'registro_duplicado' => false,
                'registro_duplicado_estado' => null,
            ]],
        ]);
});

it('persists a single AceptadoConErrores line under a Correcto envelope as accepted_with_errors', function () {
    $invoice = Invoice::factory()->create();

    $this->aeatClient->shouldReceive('sendRegistration')->once()->andReturn((new AeatResponseParser)->parse((object) [
        'CSV' => 'CSV-AWE-0002',
        'EstadoEnvio' => 'Correcto',
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'AceptadoConErrores',
            'CodigoErrorRegistro' => '1142',
            'DescripcionErrorRegistro' => 'CuotaRepercutida fuera de tolerancia',
        ],
    ]));

    $this->registrar->register($invoice);

    expect(Registry::query()->where('invoice_id', $invoice->id)->firstOrFail()->status)
        ->toBe(RegistryStatusEnum::ACCEPTED_WITH_ERRORS);
});

it('tells the RegistrySubmittedEvent listener the record was accepted with errors', function () {
    Event::fake([RegistrySubmittedEvent::class, RegistryStatusChangedEvent::class]);
    $invoice = Invoice::factory()->create();

    $this->aeatClient->shouldReceive('sendRegistration')->once()->andReturn((new AeatResponseParser)->parse((object) [
        'CSV' => 'CSV-AWE-0003',
        'EstadoEnvio' => 'ParcialmenteCorrecto',
        'RespuestaLinea' => (object) ['EstadoRegistro' => 'AceptadoConErrores', 'CodigoErrorRegistro' => '2003', 'DescripcionErrorRegistro' => 'Huella incorrecta'],
    ]));

    $this->registrar->register($invoice);

    Event::assertDispatched(RegistrySubmittedEvent::class, fn (RegistrySubmittedEvent $event) => $event->response->isAcceptedWithErrors());
    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->from === RegistryStatusEnum::PENDING
        && $event->to === RegistryStatusEnum::ACCEPTED_WITH_ERRORS
        && $event->error === null
        && $event->lines[0]['codigo'] === '2003');
});

it('stores a missing CSV as null, never an empty string', function () {
    $registry = Registry::factory()->create();

    $this->registryManager->markAsAcceptedWithErrors($registry, '', ['estado_envio' => 'ParcialmenteCorrecto', 'csv' => null, 'lineas' => []]);

    expect($registry->fresh()->aeat_csv)->toBeNull();
});

it('does not overwrite a record the agency already holds', function () {
    $registry = Registry::factory()->submitted()->create(['aeat_csv' => 'CSV-ORIGINAL']);

    $this->registryManager->markAsAcceptedWithErrors($registry, 'CSV-OTHER', ['estado_envio' => 'ParcialmenteCorrecto', 'csv' => 'CSV-OTHER', 'lineas' => []]);

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::SENT)
        ->aeat_csv->toBe('CSV-ORIGINAL');
});

it('never re-sends a record accepted with errors', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::ACCEPTED_WITH_ERRORS, 'aeat_csv' => 'CSV-HELD']);

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $response = $this->registrar->submitToAeat($registry);

    expect($response->isSuccess())->toBeTrue()
        ->and($response->getMessage())->toBe('Already submitted');
});

it('seals a record accepted with errors once it is persisted', function () {
    $registry = Registry::factory()->create();

    $this->registryManager->markAsAcceptedWithErrors($registry, 'CSV-AWE-0004', ['estado_envio' => 'ParcialmenteCorrecto', 'csv' => 'CSV-AWE-0004', 'lineas' => []]);

    expect(fn () => $registry->fresh()->delete())->toThrow(VerifactuException::class, 'cannot be deleted');
});

it('keeps submitted_at and announces from = error when a failed registry is accepted with errors', function () {
    Event::fake([RegistryStatusChangedEvent::class]);
    $at = now()->subHour()->startOfSecond();
    $registry = Registry::factory()->create([
        'status' => RegistryStatusEnum::ERROR,
        'submitted_at' => $at,
        'submission_attempts' => 1,
    ]);

    $this->registryManager->markAsAcceptedWithErrors($registry, 'CSV-AWE-0005', ['estado_envio' => 'ParcialmenteCorrecto', 'csv' => 'CSV-AWE-0005', 'lineas' => []]);

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::ACCEPTED_WITH_ERRORS)
        ->submitted_at->equalTo($at)->toBeTrue()
        ->submission_attempts->toBe(2);

    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->from === RegistryStatusEnum::ERROR
        && $event->to === RegistryStatusEnum::ACCEPTED_WITH_ERRORS);
});
