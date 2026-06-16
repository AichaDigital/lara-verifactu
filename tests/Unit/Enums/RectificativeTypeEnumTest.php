<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\RectificativeTypeEnum;

it('has the two L3 values', function () {
    expect(RectificativeTypeEnum::BY_SUBSTITUTION->value)->toBe('S')
        ->and(RectificativeTypeEnum::BY_DIFFERENCES->value)->toBe('I')
        ->and(RectificativeTypeEnum::cases())->toHaveCount(2);
});

it('describes each rectification mode', function () {
    expect(RectificativeTypeEnum::BY_SUBSTITUTION->getDescription())->toBe('Por sustitución')
        ->and(RectificativeTypeEnum::BY_DIFFERENCES->getDescription())->toBe('Por diferencias');
});
