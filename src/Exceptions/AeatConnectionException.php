<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Exceptions;

final class AeatConnectionException extends AeatException
{
    public static function cannotConnect(string $endpoint): self
    {
        return self::make("Cannot connect to AEAT endpoint: {$endpoint}");
    }

    public static function sslError(string $message): self
    {
        return self::make("SSL error: {$message}");
    }
}
