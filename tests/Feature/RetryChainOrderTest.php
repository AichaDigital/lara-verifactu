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

/**
 * AID-1673 — the retry sweeper must obey the same chain discipline as
 * SubmitRegistryToAeatJob, and a job that exhausts its hold budget must leave
 * a recoverable state.
 *
 * Defect 1: requeueAfterHold() gave up leaving the registry PENDING, and the
 * default sweeper only picks PENDING rows when resend_pending is on — a
 * successor whose predecessor sat in ERROR for ~10 minutes was never sent.
 * Defect 2: retryFailed() sent out of chain order — no predecessor check, kept
 * sending after an earlier candidate failed, and took only its own
 * verifactu:retry-failed lock, so it could overlap a running submission job.
 *
 * resend_pending keeps its AID-1645 meaning: rows created PENDING while
 * submission was off are NOT picked up by the default pass.
 */
beforeEach(function () {
    // A failed earlier test can leak the file-store lock across tests; clear
    // it so a failure cannot cascade into the next test's lock assertions.
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
    $this->registrar = new InvoiceRegistrar(
        $this->registryManager,
        Mockery::mock(CertificateManagerContract::class),
        $this->aeatClient,
    );
});

it('marks a registry ERROR when the submission job exhausts its hold budget', function () {
    $first = $this->registryManager->createRegistry(Invoice::factory()->create());
    $second = $this->registryManager->createRegistry(Invoice::factory()->create());

    // The give-up path (holds exhausted): today the registry is left PENDING,
    // and the default sweeper never picks it up (resend_pending off) — a
    // successor whose predecessor sat in ERROR for ~10 minutes was never sent.
    $job = new SubmitRegistryToAeatJob($second->id, 60);
    $job->handle($this->registrar);

    // Abandonment IS a scheduling error: marking it ERROR is what makes the
    // default sweeper recover it. PENDING stays reserved for rows created
    // with submission off (AID-1645).
    expect($second->fresh()->status)->toBe(RegistryStatusEnum::ERROR)
        ->and($second->fresh()->aeat_error)->not->toBeNull();
});

it('does not send a successor whose chain predecessor is not filed, even when the predecessor is out of the retry budget', function () {
    $first = $this->registryManager->createRegistry(Invoice::factory()->create());
    $second = $this->registryManager->createRegistry(Invoice::factory()->create());

    // The predecessor spent its submission budget: it is ERROR but no longer
    // selectable, so the pass would only see the successor — and v1.4.1 sent
    // it, out of chain order. The guard must hold it back instead.
    $this->registryManager->markAsFailed($first, 'boom');
    $this->registryManager->markAsFailed($first, 'boom');
    $this->registryManager->markAsFailed($first, 'boom');
    $this->registryManager->markAsFailed($second, 'boom');

    // The predecessor burned its whole budget: EXHAUSTED, not even selectable
    // by the pass — the sharpest version of the out-of-order window.
    expect($first->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED)
        ->and($second->fresh()->status)->toBe(RegistryStatusEnum::ERROR);

    $this->aeatClient->shouldNotReceive('sendRegistration');

    $results = $this->registrar->retryFailed();

    expect($results['success'])->toBe(0)
        ->and($results['skipped'])->toBeGreaterThanOrEqual(1)
        ->and($second->fresh()->status)->toBe(RegistryStatusEnum::ERROR);
});

it('stops the retry pass at the first submission failure instead of advancing the chain', function () {
    $a = $this->registryManager->createRegistry(Invoice::factory()->create());
    $b = $this->registryManager->createRegistry(Invoice::factory()->create());
    $c = $this->registryManager->createRegistry(Invoice::factory()->create());

    $this->registryManager->markAsFailed($a, 'boom');
    $this->registryManager->markAsSubmitted($b, 'CSV-B', json_encode(['estado_envio' => 'Correcto']));
    $this->registryManager->markAsFailed($c, 'boom');

    // Candidates ordered oldest first: [a, c] — b is already filed. The
    // predecessor guard alone cannot be trusted to keep the order here: c's
    // predecessor (b) IS filed, so only stopping the pass at the first
    // failure prevents c from being sent after a just failed.
    $this->aeatClient->shouldReceive('sendRegistration')
        ->once()
        ->andReturn(AeatResponse::failure(errors: ['transport down']));

    $results = $this->registrar->retryFailed();

    expect($results['failed'])->toBe(1)
        ->and($results['skipped'])->toBeGreaterThanOrEqual(1)
        ->and($c->fresh()->status)->toBe(RegistryStatusEnum::ERROR);

    // A later pass, once the failed predecessor is filed, sends c (its own
    // predecessor b is already filed, so the guard lets it through).
    $this->registryManager->markAsSubmitted($a, 'CSV-A', json_encode(['estado_envio' => 'Correcto']));
    $this->aeatClient->shouldReceive('sendRegistration')
        ->once()
        ->andReturn(new AeatResponse(success: true, code: 'CSV-C', message: 'Correcto'));

    $this->registrar->retryFailed();

    expect($c->fresh()->status)->toBe(RegistryStatusEnum::SENT);
});

it('does not run the pass while another holder owns the fiscal verification lock', function () {
    $registry = $this->registryManager->createRegistry(Invoice::factory()->create());
    $this->registryManager->markAsFailed($registry, 'boom');

    // A submission job holds the sequential lock: the sweep must not send
    // underneath it.
    $held = Cache::lock('fiscal_verification_queue', 300);
    expect($held->get())->toBeTrue();

    $this->aeatClient->shouldNotReceive('sendRegistration');

    $results = $this->registrar->retryFailed();

    expect($results['success'])->toBe(0)
        ->and($results['failed'])->toBe(0)
        ->and($registry->fresh()->status)->toBe(RegistryStatusEnum::ERROR);

    $held->release();
});

it('still leaves a PENDING row created with submission off out of the default pass', function () {
    $registry = $this->registryManager->createRegistry(Invoice::factory()->create());

    expect($registry->status)->toBe(RegistryStatusEnum::PENDING);

    // resend_pending is off by default: this row was created on purpose with
    // submitToAeat=false (AID-1645) — the abandonment marking must not leak
    // into the selection and sweep it.
    $this->aeatClient->shouldNotReceive('sendRegistration');

    $results = $this->registrar->retryFailed();

    expect($results['success'])->toBe(0)
        ->and($registry->fresh()->status)->toBe(RegistryStatusEnum::PENDING);
});

it('marks a hold-budget abandonment ERROR without spending submission budget, so the default sweeper still recovers it', function () {
    $first = $this->registryManager->createRegistry(Invoice::factory()->create());
    $second = $this->registryManager->createRegistry(Invoice::factory()->create());

    // The successor is ONE attempt away from its submission budget: a marking
    // that consumed an attempt would cross it to EXHAUSTED, which the default
    // sweeper does not select - the abandonment would strand it anyway
    // (AID-1673 gate, VERIFIED #1). The abandonment never reached the agency:
    // it must not spend the budget.
    tamperRegistryColumns($second->id, ['submission_attempts' => 2]);

    $job = new SubmitRegistryToAeatJob($second->id, 60);
    $job->handle($this->registrar);

    expect($second->fresh()->status)->toBe(RegistryStatusEnum::ERROR)
        ->and($second->fresh()->submission_attempts)->toBe(2);

    // The default sweeper selects it again.
    expect($this->registryManager->getRetryableRegistries()->pluck('id'))->toContain($second->id);
});

it('stops the pass when the sequential-lock lease is about to expire', function () {
    config()->set('verifactu.lock.timeout', 2);

    $a = $this->registryManager->createRegistry(Invoice::factory()->create());
    $b = $this->registryManager->createRegistry(Invoice::factory()->create());
    $this->registryManager->markAsFailed($a, 'boom');
    $this->registryManager->markAsFailed($b, 'boom');

    // A slow agency call eats the lease: Laravel cache locks expire silently,
    // so a pass that outruns verifactu.lock.timeout would keep "holding" a
    // lock another worker can already take (AID-1673 gate, SUSPECTED).
    $this->aeatClient->shouldReceive('sendRegistration')
        ->once()
        ->andReturnUsing(function () {
            usleep(2_500_000);

            return new AeatResponse(success: true, code: 'CSV-SLOW', message: 'Correcto');
        });

    $results = $this->registrar->retryFailed();

    expect($results['success'])->toBe(1)
        ->and($results['skipped'])->toBeGreaterThanOrEqual(1)
        ->and($b->fresh()->status)->toBe(RegistryStatusEnum::ERROR);
});

it('leaves an EXHAUSTED registry untouched when its job gives up on holds', function () {
    $first = $this->registryManager->createRegistry(Invoice::factory()->create());
    $second = $this->registryManager->createRegistry(Invoice::factory()->create());

    // The successor already spent its whole budget on REAL submissions:
    // EXHAUSTED is a deliberate crossing (recovered via --include-exhausted),
    // not a scheduling state. The abandonment marking must not rewrite it.
    $this->registryManager->markAsFailed($second, 'boom');
    $this->registryManager->markAsFailed($second, 'boom');
    $this->registryManager->markAsFailed($second, 'boom');

    $job = new SubmitRegistryToAeatJob($second->id, 60);
    $job->handle($this->registrar);

    expect($second->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED)
        ->and($second->fresh()->submission_attempts)->toBe(3);
});
