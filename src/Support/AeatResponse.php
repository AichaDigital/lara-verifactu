<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

class AeatResponse
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<int, string>|null  $errors
     */
    public function __construct(
        protected bool $success,
        protected ?string $code = null,
        protected ?string $message = null,
        protected ?array $data = null,
        protected ?array $errors = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return ! $this->success;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getCsv(): ?string
    {
        return $this->code;
    }

    public function getErrorMessage(): string
    {
        return $this->message ?? 'Unknown error';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    /**
     * @return array<int, string>|null
     */
    public function getErrors(): ?array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== null && $this->errors !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'code' => $this->code,
            'message' => $this->message,
            'data' => $this->data,
            'errors' => $this->errors,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function success(?array $data = null, ?string $message = null): self
    {
        return new self(
            success: true,
            data: $data,
            message: $message
        );
    }

    /**
     * @param  array<int, string>|null  $errors
     */
    public static function failure(?array $errors = null, ?string $message = null, ?string $code = null): self
    {
        return new self(
            success: false,
            code: $code,
            message: $message,
            errors: $errors
        );
    }
}
