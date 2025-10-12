<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Exceptions;

use Exception;

class VerifactuException extends Exception
{
    /**
     * Factory method for creating exceptions
     */
    public static function make(string $message, int $code = 0, ?\Throwable $previous = null): static
    {
        return new static($message, $code, $previous);
    }
}
