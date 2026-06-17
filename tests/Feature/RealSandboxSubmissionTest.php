<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\IdTypeEnum;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\InvoiceBreakdown;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;

/**
 * REAL submission against the AEAT external testing environment (sandbox).
 *
 * Requires a real representative/seal certificate exported locally and the
 * VERIFACTU_* environment variables set — automatically skipped otherwise
 * (CI never runs this). Submissions go exclusively to prewww1/prewww10
 * (Pruebas Externas): they have no fiscal effect.
 */
$certificateAvailable = is_string(getenv('VERIFACTU_CERT_PATH'))
    && getenv('VERIFACTU_CERT_PATH') !== ''
    && file_exists((string) getenv('VERIFACTU_CERT_PATH'));

beforeEach(function () {
    // Environment is FORCED to sandbox: this test must never hit production
    config()->set('verifactu.aeat.environment', 'sandbox');
    config()->set('verifactu.certificate.path', getenv('VERIFACTU_CERT_PATH'));
    config()->set('verifactu.certificate.password', getenv('VERIFACTU_CERT_PASSWORD'));
    config()->set('verifactu.certificate.type', getenv('VERIFACTU_CERT_TYPE') ?: 'representante');
    config()->set('verifactu.company.tax_id', getenv('VERIFACTU_COMPANY_TAX_ID'));
    config()->set('verifactu.company.name', getenv('VERIFACTU_COMPANY_NAME'));
    config()->set('verifactu.system.vendor_name', getenv('VERIFACTU_COMPANY_NAME'));
    config()->set('verifactu.system.vendor_nif', getenv('VERIFACTU_COMPANY_TAX_ID'));

    // QR generation is not part of the SOAP submission — keep the test focused
    $qrGenerator = Mockery::mock(QrGeneratorContract::class);
    $qrGenerator->shouldReceive('generateUrl')->andReturn('https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?stub');
    $qrGenerator->shouldReceive('generateSvg')->andReturn('<svg/>');
    $qrGenerator->shouldReceive('generatePng')->andReturn('png');
    $this->app->instance(QrGeneratorContract::class, $qrGenerator);
});

function createSandboxInvoice(): Invoice
{
    $invoice = Invoice::factory()->create([
        'serie' => null,
        'number' => 'TEST-' . now()->format('YmdHis') . '-' . strtoupper(substr(uniqid(), -5)),
        'type' => InvoiceTypeEnum::SIMPLIFIED, // F2: no recipient validation
        'simplified' => true,
        'recipient_nif' => null,
        'recipient_id_type' => null,
        'recipient_id' => null,
        'recipient_name' => null,
        'recipient_country' => null,
        'base_amount' => 10.00,
        'tax_amount' => 2.10,
        'total_amount' => 12.10,
        'description' => 'Prueba de integracion lara-verifactu',
    ]);

    InvoiceBreakdown::factory()->create([
        'invoice_id' => $invoice->id,
        'tax_rate' => 21.00,
        'base_amount' => 10.00,
        'tax_amount' => 2.10,
        'exempt' => false,
    ]);

    return $invoice->refresh();
}

it('submits a real registration to the AEAT sandbox and receives a CSV', function () {
    $registry = app(InvoiceRegistrar::class)->register(createSandboxInvoice(), submitToAeat: true);

    $registry->refresh();

    dump([
        'status' => $registry->status->value,
        'csv' => $registry->aeat_csv,
        'error' => $registry->aeat_error,
    ]);

    expect($registry->status)->toBe(RegistryStatusEnum::SENT)
        ->and($registry->aeat_csv)->not->toBeNull();
})->skip(! $certificateAvailable, 'Real AEAT sandbox certificate not available');

it('submits a real cancellation to the AEAT sandbox', function () {
    $invoice = createSandboxInvoice();
    $registrar = app(InvoiceRegistrar::class);

    $registration = $registrar->register($invoice, submitToAeat: true);
    expect($registration->refresh()->status)->toBe(RegistryStatusEnum::SENT);

    $cancellation = $registrar->cancel($invoice, submitToAeat: true);
    $cancellation->refresh();

    dump([
        'status' => $cancellation->status->value,
        'csv' => $cancellation->aeat_csv,
        'error' => $cancellation->aeat_error,
    ]);

    expect($cancellation->status)->toBe(RegistryStatusEnum::SENT)
        ->and($cancellation->aeat_csv)->not->toBeNull();
})->skip(! $certificateAvailable, 'Real AEAT sandbox certificate not available');

/*
 * AID-142 — substitution (S) rectification, end-to-end against AEAT Pruebas Externas.
 *
 * Scenario: register a simplified invoice (F2), then rectify it with a SUBSTITUTION
 * rectification (rectification_type = 'S') carrying ImporteRectificacion
 * (BaseRectificada + CuotaRectificada, XSD DesgloseRectificacionType).
 *
 * The rectifying invoice MUST be R5 (rectificativa en facturas simplificadas):
 * AEAT rule 1189 requires the Destinatarios block for F1/F3/R1/R2/R3/R4, so an R1
 * without a recipient is rejected ("Incorrecto - 1189"). R5 is NOT in that list.
 *
 * Validated live 2026-06-15: original CSV A-LY29RU9P3GSBA7, rectification CSV
 * A-945EPP9FM8YB66 (EstadoEnvio Correcto — ImporteRectificacion accepted).
 *
 * Regression triage if this starts failing:
 *  - rule 1189 / Destinatarios  -> rectifying type or recipient handling regressed
 *  - ImporteRectificacion / XSD -> XmlBuilder::buildImporteRectificacion regressed
 */
it('submits a real substitution (S) rectification with ImporteRectificacion to the AEAT sandbox (AID-142)', function () {
    $registrar = app(InvoiceRegistrar::class);

    // 1) Original invoice, accepted by AEAT first.
    $original = createSandboxInvoice();
    $originalRegistry = $registrar->register($original, submitToAeat: true);
    $originalRegistry->refresh();
    dump(['original_status' => $originalRegistry->status->value, 'original_csv' => $originalRegistry->aeat_csv, 'original_error' => $originalRegistry->aeat_error]);
    expect($originalRegistry->status)->toBe(RegistryStatusEnum::SENT);

    // 2) Substitution (S) rectification referencing the original, carrying
    //    ImporteRectificacion (base + tax of the substituted invoice).
    $rectification = Invoice::factory()->create([
        'serie' => null,
        'number' => 'TESTR-' . now()->format('YmdHis') . '-' . strtoupper(substr(uniqid(), -5)),
        'type' => InvoiceTypeEnum::RECTIFICATIVE_SIMPLIFIED_INVOICES, // R5: rectifies a simplified invoice, no Destinatarios required (AEAT rule 1189)
        'rectification_type' => 'S',
        'simplified' => true,
        'recipient_nif' => null,
        'recipient_id_type' => null,
        'recipient_id' => null,
        'recipient_name' => null,
        'recipient_country' => null,
        'base_amount' => 10.00,
        'tax_amount' => 2.10,
        'total_amount' => 12.10,
        'description' => 'Rectificativa sustitutiva S - prueba lara-verifactu AID-142',
        'metadata' => [
            'rectified_invoices' => [
                ['number' => $original->number, 'issue_date' => $original->getIssueDatetime()->toDateString()],
            ],
            'rectification_amounts' => ['base' => 10.00, 'tax' => 2.10],
        ],
    ]);

    InvoiceBreakdown::factory()->create([
        'invoice_id' => $rectification->id,
        'tax_rate' => 21.00,
        'base_amount' => 10.00,
        'tax_amount' => 2.10,
        'exempt' => false,
    ]);

    $registry = $registrar->register($rectification->refresh(), submitToAeat: true);
    $registry->refresh();

    dump([
        'status' => $registry->status->value,
        'csv' => $registry->aeat_csv,
        'error' => $registry->aeat_error,
    ]);

    expect($registry->status)->toBe(RegistryStatusEnum::SENT)
        ->and($registry->aeat_csv)->not->toBeNull();
})->skip(! $certificateAvailable, 'Real AEAT sandbox certificate not available');

/*
 * AID-166 — F3 (factura en sustitución de simplificadas) end-to-end against AEAT.
 *
 * Register a simplified invoice (F2), then an F3 that substitutes it, emitting
 * FacturasSustituidas. F3 MUST carry a recipient (AEAT rule 1189 requires
 * Destinatarios for F1/F3/R1/R2/R3/R4), unlike the F2.
 *
 * Regression triage if this fails:
 *  - rule 1189 / Destinatarios  -> recipient handling regressed
 *  - FacturasSustituidas / XSD  -> XmlBuilder::buildFacturasSustituidas regressed
 */
it('submits a real F3 substitution-of-simplified invoice with FacturasSustituidas to the AEAT sandbox (AID-166)', function () {
    $registrar = app(InvoiceRegistrar::class);

    // 1) Simplified invoice (F2) accepted first — the one being substituted.
    $simplified = createSandboxInvoice();
    $simplifiedRegistry = $registrar->register($simplified, submitToAeat: true);
    $simplifiedRegistry->refresh();
    dump(['simplified_status' => $simplifiedRegistry->status->value, 'simplified_csv' => $simplifiedRegistry->aeat_csv, 'simplified_error' => $simplifiedRegistry->aeat_error]);
    expect($simplifiedRegistry->status)->toBe(RegistryStatusEnum::SENT);

    // 2) F3 substituting it, WITH recipient (rule 1189) and FacturasSustituidas.
    $f3 = Invoice::factory()->create([
        'serie' => null,
        'number' => 'TESTF3-' . now()->format('YmdHis') . '-' . strtoupper(substr(uniqid(), -5)),
        'type' => InvoiceTypeEnum::SUBSTITUTE,
        'simplified' => false,
        'recipient_nif' => (string) getenv('VERIFACTU_COMPANY_TAX_ID'),
        'recipient_id_type' => IdTypeEnum::NIF,
        'recipient_id' => null,
        'recipient_name' => (string) getenv('VERIFACTU_COMPANY_NAME'),
        'recipient_country' => 'ES',
        'base_amount' => 10.00,
        'tax_amount' => 2.10,
        'total_amount' => 12.10,
        'description' => 'Factura F3 en sustitucion de simplificada - lara-verifactu AID-166',
        'metadata' => [
            'substituted_invoices' => [
                ['number' => $simplified->number, 'issue_date' => $simplified->getIssueDatetime()->toDateString()],
            ],
        ],
    ]);

    InvoiceBreakdown::factory()->create([
        'invoice_id' => $f3->id,
        'tax_rate' => 21.00,
        'base_amount' => 10.00,
        'tax_amount' => 2.10,
        'exempt' => false,
    ]);

    $registry = $registrar->register($f3->refresh(), submitToAeat: true);
    $registry->refresh();

    dump([
        'status' => $registry->status->value,
        'csv' => $registry->aeat_csv,
        'error' => $registry->aeat_error,
    ]);

    expect($registry->status)->toBe(RegistryStatusEnum::SENT)
        ->and($registry->aeat_csv)->not->toBeNull();
})->skip(! $certificateAvailable, 'Real AEAT sandbox certificate not available');
