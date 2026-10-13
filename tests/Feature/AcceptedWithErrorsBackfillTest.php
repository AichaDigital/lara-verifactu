<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\RegistryStatusChangedEvent;
use AichaDigital\LaraVerifactu\Models\Registry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * AID-1626 — upgrade path. Before 1.4 a ParcialmenteCorrecto answer was stored
 * as `sent` with aeat_response = the message, through the `array` cast (so
 * JSON-quoted) or, if written by hand, raw. Both forms become
 * accepted_with_errors; nothing else is touched; no event fires.
 */
beforeEach(function () {
    $this->backfill = require dirname(__DIR__, 2)
        . '/database/migrations/2026_10_13_000001_backfill_accepted_with_errors_in_verifactu_registries.php';
});

it('converts both stored forms of ParcialmenteCorrecto and leaves every other row alone', function () {
    Event::fake([RegistryStatusChangedEvent::class]);

    $raw = Registry::factory()->create();
    $quoted = Registry::factory()->create();
    $correcto = Registry::factory()->create();
    $accepted = Registry::factory()->create();
    $error = Registry::factory()->create();

    tamperRegistryColumns($raw->id, ['status' => 'sent', 'aeat_response' => 'ParcialmenteCorrecto']);
    tamperRegistryColumns($quoted->id, ['status' => 'sent', 'aeat_response' => '"ParcialmenteCorrecto"']);
    tamperRegistryColumns($correcto->id, ['status' => 'sent', 'aeat_response' => '"Correcto"']);
    tamperRegistryColumns($accepted->id, ['status' => 'accepted', 'aeat_response' => '"Registro duplicado"']);
    tamperRegistryColumns($error->id, ['status' => 'error', 'aeat_response' => null, 'submission_attempts' => 9]);

    $this->backfill->up();

    $converted = ['estado_envio' => 'ParcialmenteCorrecto', 'lineas' => null];

    expect($raw->fresh())->status->toBe(RegistryStatusEnum::ACCEPTED_WITH_ERRORS)->aeat_response->toBe($converted)
        ->and($quoted->fresh())->status->toBe(RegistryStatusEnum::ACCEPTED_WITH_ERRORS)->aeat_response->toBe($converted)
        ->and($correcto->fresh()->status)->toBe(RegistryStatusEnum::SENT)
        ->and($accepted->fresh()->status)->toBe(RegistryStatusEnum::ACCEPTED)
        // ERROR rows are the retry sweep's job, with the consumer's budget.
        ->and($error->fresh()->status)->toBe(RegistryStatusEnum::ERROR);

    Event::assertNotDispatched(RegistryStatusChangedEvent::class);
});

it('reverts to sent with the message on down()', function () {
    $registry = Registry::factory()->create();
    tamperRegistryColumns($registry->id, ['status' => 'sent', 'aeat_response' => '"ParcialmenteCorrecto"']);

    $this->backfill->up();
    $this->backfill->down();

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::SENT)
        ->aeat_response->toBe('ParcialmenteCorrecto');
    expect(DB::table('verifactu_registries')->where('id', $registry->id)->value('aeat_response'))
        ->toBe('"ParcialmenteCorrecto"');
});

it('reverts exhausted to error on down() so a 1.3 install can load every row', function () {
    $exhausted = Registry::factory()->create();
    tamperRegistryColumns($exhausted->id, [
        'status' => 'exhausted',
        'aeat_error' => 'connection reset',
        'submission_attempts' => 5,
    ]);

    $this->backfill->down();

    expect($exhausted->fresh())
        ->status->toBe(RegistryStatusEnum::ERROR)
        ->aeat_error->toBe('connection reset')
        ->submission_attempts->toBe(5);
});
