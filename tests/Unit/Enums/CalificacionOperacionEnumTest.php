<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\CalificacionOperacionEnum;

it('has the four L9 values', function () {
    expect(CalificacionOperacionEnum::S1->value)->toBe('S1')
        ->and(CalificacionOperacionEnum::S2->value)->toBe('S2')
        ->and(CalificacionOperacionEnum::N1->value)->toBe('N1')
        ->and(CalificacionOperacionEnum::N2->value)->toBe('N2')
        ->and(CalificacionOperacionEnum::cases())->toHaveCount(4);
});

it('describes each calificación', function () {
    expect(CalificacionOperacionEnum::S1->getDescription())->toContain('sujeta y no exenta')
        ->and(CalificacionOperacionEnum::S2->getDescription())->toContain('inversión del sujeto pasivo')
        ->and(CalificacionOperacionEnum::N1->getDescription())->toContain('no sujeta')
        ->and(CalificacionOperacionEnum::N2->getDescription())->toContain('reglas de localización');
});

it('classifies subject / reverse-charge / not-subject', function () {
    expect(CalificacionOperacionEnum::S1->isSubject())->toBeTrue()
        ->and(CalificacionOperacionEnum::S2->isSubject())->toBeTrue()
        ->and(CalificacionOperacionEnum::N1->isSubject())->toBeFalse()
        ->and(CalificacionOperacionEnum::S2->isReverseCharge())->toBeTrue()
        ->and(CalificacionOperacionEnum::S1->isReverseCharge())->toBeFalse()
        ->and(CalificacionOperacionEnum::N1->isNotSubject())->toBeTrue()
        ->and(CalificacionOperacionEnum::N2->isNotSubject())->toBeTrue()
        ->and(CalificacionOperacionEnum::S1->isNotSubject())->toBeFalse();
});
