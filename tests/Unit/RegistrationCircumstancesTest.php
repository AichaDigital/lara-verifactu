<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum;
use AichaDigital\LaraVerifactu\Support\RegistrationCircumstances;

it('defaults to an empty (normal alta) circumstance', function () {
    $circumstances = new RegistrationCircumstances;

    expect($circumstances->subsanacion)->toBeFalse()
        ->and($circumstances->rechazoPrevio)->toBeNull();
});

it('carries the amend-by-rejection circumstance (S + X)', function () {
    $circumstances = new RegistrationCircumstances(
        subsanacion: true,
        rechazoPrevio: RechazoPrevioEnum::X,
    );

    expect($circumstances->subsanacion)->toBeTrue()
        ->and($circumstances->rechazoPrevio)->toBe(RechazoPrevioEnum::X);
});
