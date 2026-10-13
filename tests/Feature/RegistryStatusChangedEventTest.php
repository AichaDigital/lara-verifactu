<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\RegistryRetriesExhaustedEvent;
use AichaDigital\LaraVerifactu\Events\RegistryStatusChangedEvent;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * AID-1626 — one typed event per real change of a registry's outcome, after
 * commit, so a consumer needs one listener and never parses text.
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

    $this->manager = new RegistryManager(new HashGenerator, $qrGenerator, new XmlBuilder);

    Event::fake([RegistryStatusChangedEvent::class, RegistryRetriesExhaustedEvent::class]);
});

it('announces the creation of a registry as null to pending', function () {
    $registry = $this->manager->createRegistry(Invoice::factory()->create());

    Event::assertDispatchedTimes(RegistryStatusChangedEvent::class, 1);
    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->registry->getId() === $registry->getId()
        && $event->from === null
        && $event->to === RegistryStatusEnum::PENDING
        && $event->error === null
        && $event->lines === null);
});

it('announces the creation of a cancellation registry as null to pending', function () {
    $invoice = Invoice::factory()->create();
    $this->manager->createRegistry($invoice);
    Event::fake([RegistryStatusChangedEvent::class, RegistryRetriesExhaustedEvent::class]);

    $cancellation = $this->manager->createCancellationRegistry($invoice);

    Event::assertDispatchedTimes(RegistryStatusChangedEvent::class, 1);
    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->registry->getId() === $cancellation->getId()
        && $event->from === null
        && $event->to === RegistryStatusEnum::PENDING);
});

it('announces a first failure as pending to error, with the reason', function () {
    $registry = Registry::factory()->create();

    $this->manager->markAsFailed($registry, 'connection reset');

    Event::assertDispatchedTimes(RegistryStatusChangedEvent::class, 1);
    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->from === RegistryStatusEnum::PENDING
        && $event->to === RegistryStatusEnum::ERROR
        && $event->error === 'connection reset'
        && $event->lines === null);
});

it('stays silent when a further failure leaves the outcome unchanged', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::ERROR, 'submission_attempts' => 1]);

    $this->manager->markAsFailed($registry, 'connection reset again');

    Event::assertNotDispatched(RegistryStatusChangedEvent::class);
});

it('stays silent when an idempotency guard cuts the write', function () {
    $registry = Registry::factory()->submitted()->create();

    $this->manager->markAsSubmitted($registry, 'OTHER-CSV', 'Correcto');
    $this->manager->markAsFailed($registry, 'late failure');

    Event::assertNotDispatched(RegistryStatusChangedEvent::class);
});

it('announces a registration with no error and no lines', function () {
    $registry = Registry::factory()->create();

    $this->manager->markAsSubmitted($registry, 'CSV-SENT', 'Correcto');

    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->from === RegistryStatusEnum::PENDING
        && $event->to === RegistryStatusEnum::SENT
        && $event->error === null
        && $event->lines === null);
});

it('announces a duplicate reconciliation as error to accepted', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::ERROR, 'submission_attempts' => 1]);

    $this->manager->markAsAccepted($registry, null, 'Registro duplicado');

    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->from === RegistryStatusEnum::ERROR
        && $event->to === RegistryStatusEnum::ACCEPTED);
});

it('carries the agency lines on a rejection', function () {
    $registry = Registry::factory()->create();
    $lines = [[
        'estado_registro' => 'Incorrecto',
        'codigo' => '1100',
        'descripcion' => 'Valor no permitido',
        'registro_duplicado' => false,
        'registro_duplicado_estado' => null,
    ]];

    $this->manager->markAsRejected($registry, '1100: Valor no permitido', ['estado_envio' => 'Incorrecto', 'lineas' => $lines]);

    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->to === RegistryStatusEnum::REJECTED
        && $event->error === '1100: Valor no permitido'
        && $event->lines === $lines);
});

it('waits for the surrounding transaction to commit', function () {
    $registry = Registry::factory()->create();

    DB::transaction(function () use ($registry): void {
        $this->manager->markAsFailed($registry, 'connection reset');

        Event::assertNotDispatched(RegistryStatusChangedEvent::class);
    });

    Event::assertDispatchedTimes(RegistryStatusChangedEvent::class, 1);
});

it('announces nothing when the surrounding transaction rolls back', function () {
    $registry = Registry::factory()->create();

    try {
        DB::transaction(function () use ($registry): void {
            $this->manager->markAsFailed($registry, 'connection reset');

            throw new RuntimeException('the caller rolls back');
        });
    } catch (RuntimeException) {
        // expected
    }

    Event::assertNotDispatched(RegistryStatusChangedEvent::class);
    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::PENDING);
});

it('announces no creation for a registry made in a transaction the caller rolls back', function () {
    $invoice = Invoice::factory()->create();

    try {
        DB::transaction(function () use ($invoice): void {
            $this->manager->createRegistry($invoice);

            throw new RuntimeException('the caller rolls back');
        });
    } catch (RuntimeException) {
        // expected
    }

    Event::assertNotDispatched(RegistryStatusChangedEvent::class);
    expect(Registry::query()->where('invoice_id', $invoice->id)->exists())->toBeFalse();
});
