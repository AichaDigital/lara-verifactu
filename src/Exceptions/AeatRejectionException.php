<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Exceptions;

final class AeatRejectionException extends AeatException
{
    protected array $aeatErrors = [];

    protected bool $retryable = false;

    public static function withErrors(array $errors, bool $retryable = false): self
    {
        $exception = new self('Invoice rejected by AEAT: ' . implode(', ', array_column($errors, 'description')));
        $exception->aeatErrors = $errors;
        $exception->retryable = $retryable;

        return $exception;
    }

    public function getAeatErrors(): array
    {
        return $this->aeatErrors;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
