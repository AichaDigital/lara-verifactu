<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Exceptions;

use AichaDigital\LaraVerifactu\Support\Activation;

/**
 * The package-wide activation switch is off (AID-1907).
 *
 * Provenance is carried explicitly, never inferred from the class: only the
 * pre-send re-check of InvoiceRegistrar::submitToAeat() builds a submission
 * refusal. Every other refusal (a guard, a nested call, an outcome phase) is
 * unflagged.
 */
final class VerifactuDisabledException extends VerifactuException
{
    private bool $submissionRefusal = false;

    private ?string $registryId = null;

    /**
     * A guarded operation (entry, creation or client guard) was refused.
     */
    public static function forOperation(string $operation, ?\Throwable $previous = null): self
    {
        return self::make(self::message("{$operation} refused"), 0, $previous);
    }

    /**
     * The send of this registry was refused before it left the process.
     *
     * Built ONLY by InvoiceRegistrar::submitToAeat(), in its pre-send phase.
     */
    public static function submissionRefused(string $registryId, ?\Throwable $previous = null): self
    {
        $exception = self::make(
            self::message("submission of registry {$registryId} refused before sending; the registry stays pending"),
            0,
            $previous,
        );

        $exception->submissionRefusal = true;
        $exception->registryId = $registryId;

        return $exception;
    }

    /**
     * Whether this is the pre-send refusal of submitToAeat() itself.
     */
    public function isSubmissionRefusal(): bool
    {
        return $this->submissionRefusal;
    }

    /**
     * The registry whose send was refused; null unless isSubmissionRefusal().
     */
    public function registryId(): ?string
    {
        return $this->registryId;
    }

    private static function message(string $what): string
    {
        return sprintf(
            'VeriFACTU is disabled (%s is false): %s. Set %s=true to enable registration and submission.',
            Activation::CONFIG_KEY,
            $what,
            Activation::ENV_VARIABLE,
        );
    }
}
