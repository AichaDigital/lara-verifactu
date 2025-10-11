<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use AichaDigital\LaraVerifactu\Enums\OperationTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;

interface InvoiceBreakdownContract
{
    public function getTaxType(): TaxTypeEnum;

    public function getRegimeType(): RegimeTypeEnum;

    public function getOperationType(): OperationTypeEnum;

    public function getTaxRate(): string;

    public function getBaseAmount(): string;

    public function getTaxAmount(): string;
}
