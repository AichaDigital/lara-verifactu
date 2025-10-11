<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Exceptions;

final class AeatAuthenticationException extends AeatException
{
    public static function invalidCredentials(): self
    {
        return self::make('Invalid AEAT credentials.');
    }

    public static function certificateNotAccepted(): self
    {
        return self::make('Certificate not accepted by AEAT.');
    }

    public static function unauthorized(): self
    {
        return self::make('Unauthorized access to AEAT services.');
    }
}
