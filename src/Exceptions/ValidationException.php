<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Exceptions;

final class ValidationException extends VerifactuException
{
    protected array $errors = [];

    public static function withErrors(array $errors): self
    {
        $exception = new self('Validation failed: ' . implode(', ', $errors));
        $exception->errors = $errors;

        return $exception;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public static function invalidXml(string $reason): self
    {
        return self::make("Invalid XML: {$reason}");
    }

    public static function xsdValidationFailed(array $errors): self
    {
        return self::withErrors($errors);
    }

    public static function invalidInvoiceData(string $field, string $reason): self
    {
        return self::make("Invalid invoice data for field '{$field}': {$reason}");
    }
}
