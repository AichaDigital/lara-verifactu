<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\OperacionExentaEnum;

it('models the six documented L10 causes (E1-E6)', function () {
    expect(OperacionExentaEnum::E1->value)->toBe('E1')
        ->and(OperacionExentaEnum::E6->value)->toBe('E6')
        ->and(OperacionExentaEnum::cases())->toHaveCount(6);
});

it('describes each exemption cause per L10', function () {
    expect(OperacionExentaEnum::E1->getDescription())->toBe('Exenta por el artículo 20')
        ->and(OperacionExentaEnum::E2->getDescription())->toBe('Exenta por el artículo 21')
        ->and(OperacionExentaEnum::E3->getDescription())->toBe('Exenta por el artículo 22')
        ->and(OperacionExentaEnum::E4->getDescription())->toBe('Exenta por los artículos 23 y 24')
        ->and(OperacionExentaEnum::E5->getDescription())->toBe('Exenta por el artículo 25')
        ->and(OperacionExentaEnum::E6->getDescription())->toBe('Exenta por otros');
});
