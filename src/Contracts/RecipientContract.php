<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use AichaDigital\LaraVerifactu\Enums\IdTypeEnum;

interface RecipientContract
{
    public function getTaxId(): string;

    public function getName(): string;

    public function getCountryCode(): string;

    public function getIdType(): IdTypeEnum;
}
