<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Enums;

enum RegistryStatusEnum: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case ERROR = 'error';
    case ACCEPTED_WITH_ERRORS = 'accepted_with_errors';
    case EXHAUSTED = 'exhausted';

    public function getDescription(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente de envío',
            self::SENT => 'Enviado a AEAT',
            self::ACCEPTED => 'Aceptado por AEAT',
            self::REJECTED => 'Rechazado por AEAT',
            self::ERROR => 'Error en procesamiento',
            self::ACCEPTED_WITH_ERRORS => 'Aceptado con errores por AEAT',
            self::EXHAUSTED => 'Reintentos agotados',
        };
    }

    /**
     * EXHAUSTED is deliberately not final: `verifactu:retry-failed
     * --include-exhausted` can give it one more attempt (AID-1626).
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::ACCEPTED, self::REJECTED, self::ACCEPTED_WITH_ERRORS], true);
    }

    /**
     * @deprecated Since 1.4: it reports a registered `Correcto` record (SENT) as
     *             pending. Compare against PENDING, or use isRegistered().
     *             Kept unchanged for the 1.x line (CONSTITUTION §8).
     */
    public function isPending(): bool
    {
        return in_array($this, [self::PENDING, self::SENT]);
    }

    /**
     * @deprecated Since 1.4: it misses a plain `Correcto` answer (SENT) and an
     *             accepted-with-errors one. Use isRegistered(). Kept unchanged
     *             for the 1.x line (CONSTITUTION §8).
     */
    public function isSuccessful(): bool
    {
        return $this === self::ACCEPTED;
    }

    /**
     * The agency holds this record as registered (AID-1626): `Correcto` (SENT),
     * a duplicate `Correcta` (ACCEPTED), or accepted with errors. This is the
     * helper a consumer should ask «is this invoice registered?».
     */
    public function isRegistered(): bool
    {
        return in_array($this, [self::SENT, self::ACCEPTED, self::ACCEPTED_WITH_ERRORS], true);
    }

    /**
     * The agency holds this record as filed — do not submit it again (AID-727).
     *
     * SENT is our own acknowledgement of a successful submission; ACCEPTED is
     * reached when AEAT answers «registro duplicado», i.e. it already had it.
     * Every idempotency guard must treat both alike: recognising only SENT would
     * re-send a record the agency already holds. ACCEPTED_WITH_ERRORS is filed
     * too: AEAT registered it with warnings, so re-sending it would duplicate it
     * (AID-1626).
     */
    public function isFiledAtAeat(): bool
    {
        return in_array($this, [self::SENT, self::ACCEPTED, self::ACCEPTED_WITH_ERRORS], true);
    }

    /**
     * The agency has already ruled on this record — never overwrite it with a
     * local failure (AID-729).
     *
     * Wider than isFiledAtAeat(): a REJECTED record is a verdict too. It is
     * terminal, since getRetryableRegistries() selects only ERROR, so
     * downgrading it to ERROR would make the package retry something the agency
     * refused on validation grounds. EXHAUSTED is not a verdict: the agency
     * never ruled, so it behaves like ERROR (AID-1626).
     */
    public function hasAgencyVerdict(): bool
    {
        return in_array($this, [self::SENT, self::ACCEPTED, self::ACCEPTED_WITH_ERRORS, self::REJECTED], true);
    }

    /**
     * The same set as hasAgencyVerdict(), as raw column values.
     *
     * For query-builder writes, which fire no model event and therefore cannot
     * ask an instance — the Invoice cascade being the one that matters
     * (AID-220). Derived from the cases rather than restated, so the two can
     * never drift apart.
     *
     * @return list<string>
     */
    public static function agencyVerdictValues(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->hasAgencyVerdict()),
        ));
    }

    public function canRetry(): bool
    {
        // REJECTED is a validation outcome (AID-257), not a transport retry.
        // The effective retry frontier is getRetryableRegistries() = status ERROR.
        return in_array($this, [self::PENDING, self::ERROR]);
    }
}
