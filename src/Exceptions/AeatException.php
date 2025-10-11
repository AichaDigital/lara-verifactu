<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Exceptions;

class AeatException extends VerifactuException
{
    public static function communicationError(string $message): self
    {
        return self::make("AEAT communication error: {$message}");
    }

    public static function timeout(): self
    {
        return self::make('AEAT request timeout.');
    }

    public static function invalidResponse(string $reason): self
    {
        return self::make("Invalid AEAT response: {$reason}");
    }
}
