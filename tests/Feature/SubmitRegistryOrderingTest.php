<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Jobs\SubmitRegistryToAeatJob;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * AID-1671 — submissions must keep chain order, whatever the worker count.
 *
 * Since AID-1664 consumers create the registry synchronously at issuance and
 * dispatch SubmitRegistryToAeatJob afterwards, that job is the ONLY submission
 * path of the new flow — and it inherited none of the discipline
 * ProcessInvoiceRegistrationJob had: no overlap lock, no order guard. With more
 * than one worker, registry N+1 could reach the agency before registry N, whose
 * huella N+1 references.
 *
 * Two mechanisms, because the two windows they close are different:
 *  - the `fiscal_verification_queue` lock serialises jobs that overlap in time;
 *  - the chain-order guard waits behind a predecessor (the registry whose hash
 *    this record's previous_hash points at, `hash` being unique) that the
 *    agency has not ruled on yet — the case of two workers picking records
 *    created one after another, where the lock alone never fires.
 *
 * Holds re-dispatch a FRESH copy instead of release(): with the default
 * `retry.max_attempts` of 1, a released job is killed by the worker before
 * handle() runs again, and the registry would stay PENDING forever with
 * `resend_pending` off. Each hold is bounded by `retry.max_queue_holds`.
 */
beforeEach(function () {
    // A failed earlier test can leak the file-store lock across tests; clear
    // it so a failure cannot cascade into the next test's probe assertions.
    Cache::store()->flush();

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

    $this->app->instance(InvoiceRegistrar::class, new InvoiceRegistrar(
        $this->registryManager,
        Mockery::mock(CertificateManagerContract::class),
        $this->aeatClient,
    ));

    Queue::fake();
});

/**
 * Run the submission job the way the worker would.
 */
function runSubmissionJob(int $registryId, int $holds = 0): SubmitRegistryToAeatJob
{
    $job = new SubmitRegistryToAeatJob($registryId, $holds);

    $job->handle(app(InvoiceRegistrar::class));

    return $job;
}

it('holds a registry whose chain predecessor has not been filed at the agency yet', function () {
    /** @var Registry $first */
    $first = $this->registryManager->createRegistry(Invoice::factory()->create());
    /** @var Registry $second */
    $second = $this->registryManager->createRegistry(Invoice::factory()->create());

    expect($second->previous_hash)->toBe($first->hash);

    // The predecessor is still PENDING: submitting the successor first would
    // hand the agency a record that references a huella it has never seen.
    $this->aeatClient->shouldReceive('sendRegistration')->never();

    runSubmissionJob($second->id);

    // The hold re-dispatches a fresh copy (own attempt budget), it does not
    // release() this one into the worker's maxTries guillotine.
    Queue::assertPushed(SubmitRegistryToAeatJob::class, function ($job) use ($second) {
        return $job->registryId === $second->id && $job->holds === 1;
    });

    expect($second->fresh()->status)->toBe(RegistryStatusEnum::PENDING)
        ->and($first->fresh()->status)->toBe(RegistryStatusEnum::PENDING);
});

it('holds the submission while another job owns the fiscal verification lock', function () {
    /** @var Registry $registry */
    $registry = $this->registryManager->createRegistry(Invoice::factory()->create());

    // Stand in for a job already processing: hold the sequential lock.
    $held = Cache::lock('fiscal_verification_queue', 300);
    expect($held->get())->toBeTrue();

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    runSubmissionJob($registry->id);

    Queue::assertPushed(SubmitRegistryToAeatJob::class, function ($job) use ($registry) {
        return $job->registryId === $registry->id && $job->holds === 1;
    });

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::PENDING);

    $held->release();
});

it('submits once the predecessor has been filed at the agency', function () {
    /** @var Registry $first */
    $first = $this->registryManager->createRegistry(Invoice::factory()->create());
    /** @var Registry $second */
    $second = $this->registryManager->createRegistry(Invoice::factory()->create());

    // The agency already holds the predecessor (SENT is a filed outcome).
    $this->registryManager->markAsSubmitted($first, 'CSV-1', json_encode(['estado_envio' => 'Correcto']));

    expect($first->fresh()->status)->toBe(RegistryStatusEnum::SENT);

    $this->aeatClient->shouldReceive('sendRegistration')
        ->once()
        ->andReturn(new AeatResponse(success: true, code: 'CSV-2', message: 'Correcto'));

    runSubmissionJob($second->id);

    expect($second->fresh()->status)->toBe(RegistryStatusEnum::SENT);

    // The happy path holds nothing and re-dispatches nothing.
    Queue::assertNothingPushed();

    // The lock was released: a leaked lock would wedge the whole queue.
    $probe = Cache::lock('fiscal_verification_queue', 10);
    expect($probe->get())->toBeTrue();
    $probe->release();
});

it('submits behind a predecessor the agency accepted with errors, not only behind SENT', function () {
    /** @var Registry $first */
    $first = $this->registryManager->createRegistry(Invoice::factory()->create());
    /** @var Registry $second */
    $second = $this->registryManager->createRegistry(Invoice::factory()->create());

    // ACCEPTED_WITH_ERRORS is a filed outcome too (isFiledAtAeat) — pinning
    // the enum here so a guard narrowed to SENT alone cannot slip through.
    $this->registryManager->markAsAcceptedWithErrors($first, 'CSV-1', ['estado_envio' => 'AceptadaConErrores']);

    expect($first->fresh()->status)->toBe(RegistryStatusEnum::ACCEPTED_WITH_ERRORS);

    $this->aeatClient->shouldReceive('sendRegistration')
        ->once()
        ->andReturn(new AeatResponse(success: true, code: 'CSV-2', message: 'Correcto'));

    runSubmissionJob($second->id);

    expect($second->fresh()->status)->toBe(RegistryStatusEnum::SENT);
});

it('marks the registry ERROR for the default sweeper once the hold budget is exhausted', function () {
    /** @var Registry $first */
    $first = $this->registryManager->createRegistry(Invoice::factory()->create());
    /** @var Registry $second */
    $second = $this->registryManager->createRegistry(Invoice::factory()->create());

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    // 60 holds served (the default budget): the give-up marks the record
    // ERROR so the DEFAULT sweeper recovers it (AID-1673) — PENDING would
    // strand it forever with resend_pending off.
    runSubmissionJob($second->id, holds: 60);

    Queue::assertNothingPushed();

    expect($second->fresh()->status)->toBe(RegistryStatusEnum::ERROR)
        ->and($second->fresh()->aeat_error)->toContain('abandoned')
        ->and($first->fresh()->status)->toBe(RegistryStatusEnum::PENDING);
});
