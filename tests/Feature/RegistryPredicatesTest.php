<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Models\Registry;

/**
 * AID-1626 — the same AEAT outcome keeps the same answer as in 1.3: a
 * ParcialmenteCorrecto record was `sent` (isSubmitted true) and an over-budget
 * failure was `error` (hasErrors true), whatever their new statuses are.
 */
it('answers isSubmitted() as in 1.3', function (RegistryStatusEnum $status, bool $expected) {
    expect(Registry::factory()->make(['status' => $status])->isSubmitted())->toBe($expected);
})->with([
    'sent' => [RegistryStatusEnum::SENT, true],
    'accepted with errors' => [RegistryStatusEnum::ACCEPTED_WITH_ERRORS, true],
    'accepted' => [RegistryStatusEnum::ACCEPTED, false],
    'pending' => [RegistryStatusEnum::PENDING, false],
]);

it('answers hasErrors() as in 1.3', function (RegistryStatusEnum $status, bool $expected) {
    expect(Registry::factory()->make(['status' => $status])->hasErrors())->toBe($expected);
})->with([
    'error' => [RegistryStatusEnum::ERROR, true],
    'exhausted' => [RegistryStatusEnum::EXHAUSTED, true],
    'sent' => [RegistryStatusEnum::SENT, false],
    'rejected' => [RegistryStatusEnum::REJECTED, false],
]);
