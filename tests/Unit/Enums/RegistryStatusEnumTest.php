<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;

/**
 * AID-1626 — the full helper matrix, one row per case.
 *
 * Pinned as a whole so a new case cannot slip in without its answer to every
 * helper being decided, and so the deprecated isPending()/isSuccessful() are
 * proven to keep their 1.x semantics (CONSTITUTION §8).
 */
it('answers every helper for every case', function (RegistryStatusEnum $status, array $expected) {
    expect([
        'isFinal' => $status->isFinal(),
        'isPending' => $status->isPending(),
        'isSuccessful' => $status->isSuccessful(),
        'isFiledAtAeat' => $status->isFiledAtAeat(),
        'hasAgencyVerdict' => $status->hasAgencyVerdict(),
        'canRetry' => $status->canRetry(),
        'isRegistered' => $status->isRegistered(),
    ])->toBe($expected);
})->with([
    'pending' => [RegistryStatusEnum::PENDING, ['isFinal' => false, 'isPending' => true, 'isSuccessful' => false, 'isFiledAtAeat' => false, 'hasAgencyVerdict' => false, 'canRetry' => true, 'isRegistered' => false]],
    'sent' => [RegistryStatusEnum::SENT, ['isFinal' => false, 'isPending' => true, 'isSuccessful' => false, 'isFiledAtAeat' => true, 'hasAgencyVerdict' => true, 'canRetry' => false, 'isRegistered' => true]],
    'accepted' => [RegistryStatusEnum::ACCEPTED, ['isFinal' => true, 'isPending' => false, 'isSuccessful' => true, 'isFiledAtAeat' => true, 'hasAgencyVerdict' => true, 'canRetry' => false, 'isRegistered' => true]],
    'accepted_with_errors' => [RegistryStatusEnum::ACCEPTED_WITH_ERRORS, ['isFinal' => true, 'isPending' => false, 'isSuccessful' => false, 'isFiledAtAeat' => true, 'hasAgencyVerdict' => true, 'canRetry' => false, 'isRegistered' => true]],
    'rejected' => [RegistryStatusEnum::REJECTED, ['isFinal' => true, 'isPending' => false, 'isSuccessful' => false, 'isFiledAtAeat' => false, 'hasAgencyVerdict' => true, 'canRetry' => false, 'isRegistered' => false]],
    'error' => [RegistryStatusEnum::ERROR, ['isFinal' => false, 'isPending' => false, 'isSuccessful' => false, 'isFiledAtAeat' => false, 'hasAgencyVerdict' => false, 'canRetry' => true, 'isRegistered' => false]],
    'exhausted' => [RegistryStatusEnum::EXHAUSTED, ['isFinal' => false, 'isPending' => false, 'isSuccessful' => false, 'isFiledAtAeat' => false, 'hasAgencyVerdict' => false, 'canRetry' => false, 'isRegistered' => false]],
]);

it('has a matrix row for every case', function () {
    expect(RegistryStatusEnum::cases())->toHaveCount(7);
});

it('stores the new outcomes under stable column values', function () {
    expect(RegistryStatusEnum::ACCEPTED_WITH_ERRORS->value)->toBe('accepted_with_errors')
        ->and(RegistryStatusEnum::EXHAUSTED->value)->toBe('exhausted')
        ->and(RegistryStatusEnum::ACCEPTED_WITH_ERRORS->getDescription())->toBe('Aceptado con errores por AEAT')
        ->and(RegistryStatusEnum::EXHAUSTED->getDescription())->toBe('Reintentos agotados');
});

it('lists accepted_with_errors, and not exhausted, among the agency verdict values', function () {
    expect(RegistryStatusEnum::agencyVerdictValues())
        ->toContain('accepted_with_errors')
        ->not->toContain('exhausted');
});
