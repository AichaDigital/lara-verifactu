<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\AeatResponseParser;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;

/**
 * AID-727 — a duplicate is not a rejection.
 *
 * The agency accepts a submission and the response is lost to a network
 * timeout. AID-717 makes that recoverable: the record stays in ERROR and
 * `verifactu:retry-failed` re-sends it. The agency then answers «duplicado»,
 * because it does already have it.
 *
 * That answer used to be classified as a validation rejection, so the record
 * landed in REJECTED — terminal, no CSV, and not retryable. A record the agency
 * holds as filed ended up locally as refused, with no automatic way out.
 *
 * The signal was already being captured: collectLineDetails() has populated
 * `registro_duplicado` since AID-137, and its docblock says it exists precisely
 * to tell a duplicate-key rejection from a genuine not-in-AEAT one. Nothing
 * consulted it when deciding the status.
 *
 * ACCEPTED is used rather than a new enum case: it already exists, already means
 * «Aceptado por AEAT», and is already final and non-retryable.
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

/** The AEAT «registro duplicado» answer (code 3000), carrying the RegistroDuplicado block. */
function duplicateAeatResponse(?string $csv = null): object
{
    $line = [
        'EstadoRegistro' => 'Incorrecto',
        'CodigoErrorRegistro' => '3000',
        'DescripcionErrorRegistro' => 'Registro de facturación duplicado',
        'RegistroDuplicado' => (object) ['EstadoRegistroDuplicado' => 'Correcta'],
    ];

    $response = ['EstadoEnvio' => 'Incorrecto', 'RespuestaLinea' => (object) $line];

    if ($csv !== null) {
        $response['CSV'] = $csv;
    }

    return (object) $response;
}

it('reconciles an accepted-but-timed-out submission instead of rejecting it', function () {
    $invoice = Invoice::factory()->create();

    // 1-2. The agency accepts; the response is lost to a timeout.
    $this->aeatClient->shouldReceive('sendRegistration')
        ->once()
        ->andThrow(new RuntimeException('connection timed out'));

    expect(fn () => $this->registrar->register($invoice))->toThrow(AeatException::class);

    $registry = Registry::query()->where('invoice_id', $invoice->id)->firstOrFail();
    expect($registry->status)->toBe(RegistryStatusEnum::ERROR);

    // 3-4. retry-failed re-sends it; the agency answers «duplicado».
    $this->aeatClient->shouldReceive('sendRegistration')
        ->once()
        ->andReturn((new AeatResponseParser)->parse(duplicateAeatResponse()));

    $this->registrar->retryFailed();

    // 5. It must NOT be REJECTED: the agency holds this record as filed.
    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::ACCEPTED);
});

it('keeps the CSV when the duplicate answer carries one', function () {
    $invoice = Invoice::factory()->create();

    $this->aeatClient->shouldReceive('sendRegistration')
        ->andReturn((new AeatResponseParser)->parse(duplicateAeatResponse('CSV-FROM-DUPLICATE')));

    $this->registrar->register($invoice);

    $registry = Registry::query()->where('invoice_id', $invoice->id)->firstOrFail();

    expect($registry->status)->toBe(RegistryStatusEnum::ACCEPTED)
        ->and($registry->aeat_csv)->toBe('CSV-FROM-DUPLICATE');
});

it('leaves the CSV null, never empty, when the duplicate answer carries none', function () {
    $invoice = Invoice::factory()->create();

    $this->aeatClient->shouldReceive('sendRegistration')
        ->andReturn((new AeatResponseParser)->parse(duplicateAeatResponse()));

    $this->registrar->register($invoice);

    $registry = Registry::query()->where('invoice_id', $invoice->id)->firstOrFail();

    // aeat_csv carries a UNIQUE index. Storing '' would make the SECOND record
    // without a CSV collide on it; NULLs do not collide.
    expect($registry->aeat_csv)->toBeNull();
});

it('does not re-submit a record the agency already holds', function () {
    $invoice = Invoice::factory()->create();

    $this->aeatClient->shouldReceive('sendRegistration')
        ->once()
        ->andReturn((new AeatResponseParser)->parse(duplicateAeatResponse()));

    $this->registrar->register($invoice);

    $registry = Registry::query()->where('invoice_id', $invoice->id)->firstOrFail();

    // Every idempotency guard that only recognised SENT must recognise ACCEPTED
    // too, or a record the agency holds would be sent again. `once()` above is
    // the assertion: a second call fails the mock.
    $response = $this->registrar->submitToAeat($registry);

    expect($response->isSuccess())->toBeTrue()
        ->and($registry->fresh()->status)->toBe(RegistryStatusEnum::ACCEPTED);
});

it('still rejects a genuine validation failure', function () {
    $invoice = Invoice::factory()->create();

    // 3002 = NIF not identified: a real rejection, no RegistroDuplicado block.
    $this->aeatClient->shouldReceive('sendRegistration')
        ->andReturn((new AeatResponseParser)->parse((object) [
            'EstadoEnvio' => 'Incorrecto',
            'RespuestaLinea' => (object) [
                'EstadoRegistro' => 'Incorrecto',
                'CodigoErrorRegistro' => '3002',
                'DescripcionErrorRegistro' => 'NIF del IDFactura no identificado',
            ],
        ]));

    $this->registrar->register($invoice);

    expect(Registry::query()->where('invoice_id', $invoice->id)->firstOrFail()->status)
        ->toBe(RegistryStatusEnum::REJECTED);
});

it('reconciles a duplicate of an accepted-with-errors record end to end', function () {
    $invoice = Invoice::factory()->create();

    // The state the agency actually returns for a record it accepted moments
    // earlier (verified against the prewww1 sandbox). Before AID-727 was
    // widened to list L21, this landed in a terminal REJECTED without CSV —
    // the exact bug the fix claimed to close.
    $this->aeatClient->shouldReceive('sendRegistration')
        ->andReturn((new AeatResponseParser)->parse((object) [
            'EstadoEnvio' => 'Incorrecto',
            'RespuestaLinea' => (object) [
                'EstadoRegistro' => 'Incorrecto',
                'CodigoErrorRegistro' => '3000',
                'DescripcionErrorRegistro' => 'Registro de facturación duplicado.',
                'RegistroDuplicado' => (object) ['EstadoRegistroDuplicado' => 'AceptadaConErrores'],
            ],
        ]));

    $this->registrar->register($invoice);

    expect(Registry::query()->where('invoice_id', $invoice->id)->firstOrFail()->status)
        ->toBe(RegistryStatusEnum::ACCEPTED);
});

it('treats a duplicate of an ANNULLED record as a rejection', function () {
    $invoice = Invoice::factory()->create();

    // `Anulada` is the one L21 state that must not reconcile: the agency holds
    // an annulled record, not ours, and that number is refused for good
    // (FAQ §6). Verified against the sandbox: alta, anulación, alta.
    $this->aeatClient->shouldReceive('sendRegistration')
        ->andReturn((new AeatResponseParser)->parse((object) [
            'EstadoEnvio' => 'Incorrecto',
            'RespuestaLinea' => (object) [
                'EstadoRegistro' => 'Incorrecto',
                'CodigoErrorRegistro' => '3000',
                'DescripcionErrorRegistro' => 'Registro de facturación duplicado',
                'RegistroDuplicado' => (object) ['EstadoRegistroDuplicado' => 'Anulada'],
            ],
        ]));

    $this->registrar->register($invoice);

    expect(Registry::query()->where('invoice_id', $invoice->id)->firstOrFail()->status)
        ->toBe(RegistryStatusEnum::REJECTED);
});
