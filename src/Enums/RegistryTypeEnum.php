<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Enums;

enum RegistryTypeEnum: string
{
    case REGISTRATION = 'registration'; // RegistroAlta
    case CANCELLATION = 'cancellation'; // RegistroAnulacion
}
