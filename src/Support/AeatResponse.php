<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

class AeatResponse
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<int, string>|null  $errors
     * @param  bool  $rejection  True when AEAT semantically rejected the submission (EstadoEnvio/EstadoRegistro=Incorrecto), as opposed to a transport failure.
     * @param  bool  $duplicate  True when AEAT answered «registro duplicado»: it already holds this record as filed (AID-727).
     * @param  bool  $acceptedWithErrors  True when AEAT registered the record with warnings (ParcialmenteCorrecto / AceptadoConErrores / duplicate AceptadaConErrores, AID-1626).
     */
    public function __construct(
        protected bool $success,
        protected ?string $code = null,
        protected ?string $message = null,
        protected ?array $data = null,
        protected ?array $errors = null,
        protected bool $rejection = false,
        protected bool $duplicate = false,
        protected bool $acceptedWithErrors = false,
    ) {}

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * AEAT registered the record with warnings (AID-1626). Also a success —
     * the record must never be re-sent — and, for a duplicate answer, also
     * isDuplicate().
     */
    public function isAcceptedWithErrors(): bool
    {
        return $this->acceptedWithErrors;
    }

    public function isFailure(): bool
    {
        return ! $this->success;
    }

    public function isValidationRejection(): bool
    {
        return $this->rejection;
    }

    /**
     * AEAT answered «registro duplicado»: it already holds this record as filed
     * (AID-727). Not a rejection — the expected answer when a submission was
     * accepted and its response lost to a timeout.
     */
    public function isDuplicate(): bool
    {
        return $this->duplicate;
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
            'duplicate' => $this->duplicate,
            'accepted_with_errors' => $this->acceptedWithErrors,
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

    /**
     * AEAT already holds this record as filed («registro duplicado», AID-727).
     *
     * Reported as a SUCCESS: the submission's purpose — the record being filed
     * at the agency — is met. The CSV stays null when the answer carries none;
     * never '', which would collide on the UNIQUE index of `aeat_csv` for the
     * second record without one.
     *
     * @param  array<int, string>|null  $errors
     * @param  array<string, mixed>|null  $data
     */
    public static function duplicate(
        ?string $csv = null,
        ?string $message = null,
        ?array $data = null,
        ?array $errors = null,
        bool $acceptedWithErrors = false,
    ): self {
        return new self(
            success: true,
            code: $csv,
            message: $message,
            data: $data,
            errors: $errors,
            duplicate: true,
            acceptedWithErrors: $acceptedWithErrors,
        );
    }

    /**
     * AEAT registered the record but reported errors that do not cause its
     * rejection (AID-1626; L19 «se registra en el sistema»). The CSV stays null
     * when the answer carries none, never '' (UNIQUE index on `aeat_csv`).
     *
     * @param  array<string, mixed>|null  $data
     * @param  array<int, string>|null  $errors
     */
    public static function acceptedWithErrors(
        ?string $csv = null,
        ?string $message = null,
        ?array $data = null,
        ?array $errors = null,
    ): self {
        return new self(
            success: true,
            code: $csv,
            message: $message,
            data: $data,
            errors: $errors,
            acceptedWithErrors: true,
        );
    }
}
