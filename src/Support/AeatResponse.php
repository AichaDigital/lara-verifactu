<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

class AeatResponse
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<int, string>|null  $errors
     * @param  bool  $rejection  True when AEAT semantically rejected the submission (EstadoEnvio/EstadoRegistro=Incorrecto), as opposed to a transport failure.
     */
    public function __construct(
        protected bool $success,
        protected ?string $code = null,
        protected ?string $message = null,
        protected ?array $data = null,
        protected ?array $errors = null,
        protected bool $rejection = false,
    ) {}

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return ! $this->success;
    }

    public function isValidationRejection(): bool
    {
        return $this->rejection;
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
        $message = $this->message ?? 'Unknown error';

        if ($this->errors !== null && $this->errors !== []) {
            $message .= ' — ' . implode(' | ', $this->errors);
        }

        return $message;
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
            'rejection' => $this->rejection,
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

    /**
     * A well-formed AEAT response that AEAT evaluated and rejected
     * (EstadoEnvio/EstadoRegistro=Incorrecto), as opposed to a transport failure.
     *
     * @param  array<int, string>|null  $errors
     * @param  array<string, mixed>|null  $data
     */
    public static function rejection(?array $errors = null, ?string $message = null, ?array $data = null): self
    {
        return new self(
            success: false,
            message: $message,
            data: $data,
            errors: $errors,
            rejection: true,
        );
    }
}
