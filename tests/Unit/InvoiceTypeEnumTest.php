<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;

it('exposes F3 as the substitution invoice type and not as rectificative/simplified', function () {
    expect(InvoiceTypeEnum::SUBSTITUTE->value)->toBe('F3')
        ->and(InvoiceTypeEnum::SUBSTITUTE->getDescription())->toBe('Factura emitida en sustitución de facturas simplificadas')
        ->and(InvoiceTypeEnum::SUBSTITUTE->isRectificative())->toBeFalse()
        ->and(InvoiceTypeEnum::SUBSTITUTE->isSimplified())->toBeFalse();
});
