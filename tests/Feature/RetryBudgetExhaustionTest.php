<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\RegistryRetriesExhaustedEvent;
use AichaDigital\LaraVerifactu\Events\RegistryStatusChangedEvent;
use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;

/**
 * AID-1626 — an `error` row whose attempts reach the budget used to drop out
 * of the retry query with no transition and no signal. It now crosses into
 * `exhausted`, under the row lock, with exactly one RegistryRetriesExhaustedEvent.
 */
beforeEach(function () {
    $this->manager = app(RegistryManager::class);

    Event::fake([RegistryStatusChangedEvent::class, RegistryRetriesExhaustedEvent::class]);
});

it('crosses into exhausted when a failure reaches the budget, and says so once', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::ERROR, 'submission_attempts' => 2]);

    $this->manager->markAsFailed($registry, 'connection reset');

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::EXHAUSTED)
        ->submission_attempts->toBe(3)
        ->aeat_error->toBe('connection reset');

    Event::assertDispatchedTimes(RegistryRetriesExhaustedEvent::class, 1);
    Event::assertDispatched(RegistryRetriesExhaustedEvent::class, fn (RegistryRetriesExhaustedEvent $event) => $event->lastError === 'connection reset' && $event->attempts === 3);
    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->from === RegistryStatusEnum::ERROR
        && $event->to === RegistryStatusEnum::EXHAUSTED
        && $event->error === 'connection reset');
});

it('stays in error below the budget', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::ERROR, 'submission_attempts' => 1]);

    $this->manager->markAsFailed($registry, 'connection reset');

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::ERROR);
    Event::assertNotDispatched(RegistryRetriesExhaustedEvent::class);
});

it('lets an explicit budget override the configured one', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::ERROR, 'submission_attempts' => 2]);

    $this->manager->markAsFailed($registry, 'connection reset', 18);

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::ERROR);
});

it('reads the budget from verifactu.retry.max_submission_attempts', function () {
    config()->set('verifactu.retry.max_submission_attempts', 2);
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::ERROR, 'submission_attempts' => 1]);

    $this->manager->markAsFailed($registry, 'connection reset');

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED);
});

it('falls back to 3 for a published config that predates the key', function () {
    // mergeConfigFrom() is shallow: a consumer who published config before
    // 1.4 has a `retry` array without max_submission_attempts.
    config()->set('verifactu.retry', ['enabled' => true, 'max_attempts' => 1, 'delay' => 60]);

    $belowBudget = Registry::factory()->create(['status' => RegistryStatusEnum::ERROR, 'submission_attempts' => 1]);
    $atBudget = Registry::factory()->create(['status' => RegistryStatusEnum::ERROR, 'submission_attempts' => 2]);

    $this->manager->markAsFailed($belowBudget, 'connection reset');
    $this->manager->markAsFailed($atBudget, 'connection reset');

    expect($belowBudget->fresh()->status)->toBe(RegistryStatusEnum::ERROR)
        ->and($atBudget->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED);
});

it('crosses from pending straight to exhausted on a budget of one', function () {
    $registry = Registry::factory()->create();

    $this->manager->markAsFailed($registry, 'connection reset', 1);

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED);
    Event::assertDispatchedTimes(RegistryRetriesExhaustedEvent::class, 1);
});

it('keeps an exhausted registry exhausted, silently, when it fails again', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::EXHAUSTED, 'submission_attempts' => 3]);

    $this->manager->markAsFailed($registry, 'still unreachable', 18);

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::EXHAUSTED)
        ->submission_attempts->toBe(4)
        ->aeat_error->toBe('still unreachable');

    Event::assertNotDispatched(RegistryRetriesExhaustedEvent::class);
    Event::assertNotDispatched(RegistryStatusChangedEvent::class);
});

it('never lets the budget override an agency verdict', function () {
    $registry = Registry::factory()->submitted()->create();

    $this->manager->markAsFailed($registry, 'late failure', 1);

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::SENT);
});

it('refuses a budget below one', function (int $budget) {
    expect(fn () => $this->manager->submissionBudget($budget))
        ->toThrow(VerifactuException::class, 'at least 1');
})->with([0, -3]);

/**
 * @return array{0: InvoiceRegistrar, 1: RegistryManager, 2: MockInterface}
 */
function budgetRegistrar(): array
{
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

    $manager = new RegistryManager(new HashGenerator, $qrGenerator, new XmlBuilder);
    $client = Mockery::mock(AeatClientContract::class);
    $registrar = new InvoiceRegistrar($manager, Mockery::mock(CertificateManagerContract::class), $client);

    return [$registrar, $manager, $client];
}

it('honours the budget passed to submitToAeat on a transport failure', function () {
    [$registrar, $manager, $client] = budgetRegistrar();
    $client->shouldReceive('sendRegistration')->once()->andThrow(new RuntimeException('connection timed out'));

    /** @var Registry $registry */
    $registry = $manager->createRegistry(Invoice::factory()->create());
    tamperRegistryColumns($registry->id, ['status' => RegistryStatusEnum::ERROR->value, 'submission_attempts' => 4]);

    // 5 attempts spent, budget 18: stays in error. The default budget (3) would exhaust it.
    expect(fn () => $registrar->submitToAeat($registry->fresh(), 18))->toThrow(AeatException::class);

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::ERROR)
        ->submission_attempts->toBe(5);
});

it('honours the budget passed to submitToAeat on a failed agency answer', function () {
    [$registrar, $manager, $client] = budgetRegistrar();
    $client->shouldReceive('sendRegistration')->once()
        ->andReturn(AeatResponse::failure(errors: ['Server busy'], message: 'Unknown AEAT response'));

    /** @var Registry $registry */
    $registry = $manager->createRegistry(Invoice::factory()->create());

    // One attempt, budget 1: exhausted. The default budget (3) would leave it in error.
    $registrar->submitToAeat($registry->fresh(), 1);

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED);
});

it('refuses an invalid configured budget before transmitting anything', function () {
    [$registrar, $manager, $client] = budgetRegistrar();
    $client->shouldReceive('sendRegistration')->never();

    /** @var Registry $registry */
    $registry = $manager->createRegistry(Invoice::factory()->create());

    config()->set('verifactu.retry.max_submission_attempts', 0);

    expect(fn () => $registrar->submitToAeat($registry->fresh()))->toThrow(VerifactuException::class, 'at least 1');

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::PENDING)
        ->submission_attempts->toBe(0);
});
