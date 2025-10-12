<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Exceptions;

use Exception;

/**
 * Base exception for Verifactu package
 */
class VerifactuException extends Exception
{
    /**
     * Final constructor to ensure consistent signature in child classes
     */
    final public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Factory method for creating exceptions
     */
    public static function make(string $message, int $code = 0, ?\Throwable $previous = null): static
    {
        return new static($message, $code, $previous);
    }
}
