<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\RegistryFailedEvent;
use AichaDigital\LaraVerifactu\Events\RegistrySubmittedEvent;
use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\AeatResponseParser;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Support\Facades\Event;

/**
 * AID-729 — a consumer's listener blowing up is not a transport failure.
 *
 * The catch in submitToAeat() covered the whole body, including the event()
 * calls dispatched AFTER the definitive outcome had been persisted. So a
 * listener that threw was handled as though the round trip to the agency had
 * failed.
 *
 * Two distinct consequences, both covered here:
 *
 *  - After a SUCCESSFUL submission the state held (markAsFailed() refuses to
 *    overwrite a filed record) but the contract lied: the caller received
 *    AeatException::connectionFailed for a submission that went through, and
 *    BOTH events were dispatched for one operation. That false failure is what
 *    pushes an operator into re-registering — the dangerous path of AID-726.
 *
 *  - After a REJECTION the state was corrupted outright: markAsFailed()'s guard
 *    only protected SENT, so a terminal REJECTED became a retryable ERROR with
 *    submission_attempts incremented twice. The package would then retry
 *    something the agency had refused on validation grounds.
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

/** A registry sitting at PENDING, ready to be submitted on its own. */
function isolationPendingRegistry(RegistryManager $manager): Registry
{
    /** @var Registry $registry */
    $registry = $manager->createRegistry(Invoice::factory()->create());

    return $registry;
}

it('does not report a connection failure when a listener throws after success', function () {
    $registry = isolationPendingRegistry($this->registryManager);

    $this->aeatClient->shouldReceive('sendRegistration')
        ->once()
        ->andReturn(new AeatResponse(
            success: true,
            code: 'CSV-OK',
            message: 'Correcto',
        ));

    $failedEvents = 0;
    Event::listen(RegistryFailedEvent::class, function () use (&$failedEvents): void {
        $failedEvents++;
    });

    Event::listen(RegistrySubmittedEvent::class, function (): void {
        throw new RuntimeException('consumer listener blew up');
    });

    // The agency answered correctly. The outcome of the operation is fixed by
    // that answer, not by what a consumer listener does afterwards.
    $response = $this->registrar->submitToAeat($registry);

    expect($response->isSuccess())->toBeTrue()
        ->and($registry->fresh()->status)->toBe(RegistryStatusEnum::SENT)
        ->and($failedEvents)->toBe(0);
});

it('keeps a rejection terminal when a listener throws after it', function () {
    $registry = isolationPendingRegistry($this->registryManager);

    // 3002 = NIF not identified: a genuine validation rejection.
    $this->aeatClient->shouldReceive('sendRegistration')
        ->once()
        ->andReturn((new AeatResponseParser)->parse((object) [
            'EstadoEnvio' => 'Incorrecto',
            'RespuestaLinea' => (object) [
                'EstadoRegistro' => 'Incorrecto',
                'CodigoErrorRegistro' => '3002',
                'DescripcionErrorRegistro' => 'NIF del IDFactura no identificado',
            ],
        ]));

    Event::listen(RegistryFailedEvent::class, function (): void {
        throw new RuntimeException('consumer listener blew up');
    });

    $this->registrar->submitToAeat($registry);

    $fresh = $registry->fresh();

    // REJECTED is terminal: getRetryableRegistries() selects only ERROR. Letting
    // a listener turn it into ERROR would make the package retry something the
    // agency refused on validation grounds.
    expect($fresh->status)->toBe(RegistryStatusEnum::REJECTED)
        ->and($fresh->submission_attempts)->toBe(1);
});

it('does not resurrect a rejected record through markAsFailed', function () {
    $registry = isolationPendingRegistry($this->registryManager);

    $this->registryManager->markAsRejected($registry, 'refused on validation grounds');
    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::REJECTED);

    // Direct call: the guard used to protect only SENT, so any later failure
    // path could downgrade a terminal verdict into a retryable one.
    $this->registryManager->markAsFailed($registry, 'some later failure');

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::REJECTED);
});

it('still reports a genuine transport failure as one', function () {
    $registry = isolationPendingRegistry($this->registryManager);

    $this->aeatClient->shouldReceive('sendRegistration')
        ->once()
        ->andThrow(new RuntimeException('connection reset by peer'));

    // The narrowing must not go too far: a real transport failure still fails
    // loud, still marks the record retryable, and still dispatches the failure
    // event. That is the AID-717 contract.
    expect(fn () => $this->registrar->submitToAeat($registry))
        ->toThrow(AeatException::class);

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::ERROR);
});
