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

    public function getDescription(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente de envío',
            self::SENT => 'Enviado a AEAT',
            self::ACCEPTED => 'Aceptado por AEAT',
            self::REJECTED => 'Rechazado por AEAT',
            self::ERROR => 'Error en procesamiento',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::ACCEPTED, self::REJECTED]);
    }

    public function isPending(): bool
    {
        return in_array($this, [self::PENDING, self::SENT]);
    }

    public function isSuccessful(): bool
    {
        return $this === self::ACCEPTED;
    }

    /**
     * The agency holds this record as filed — do not submit it again (AID-727).
     *
     * SENT is our own acknowledgement of a successful submission; ACCEPTED is
     * reached when AEAT answers «registro duplicado», i.e. it already had it.
     * Every idempotency guard must treat both alike: recognising only SENT would
     * re-send a record the agency already holds.
     */
    public function isFiledAtAeat(): bool
    {
        return in_array($this, [self::SENT, self::ACCEPTED], true);
    }

    /**
     * The agency has already ruled on this record — never overwrite it with a
     * local failure (AID-729).
     *
     * Wider than isFiledAtAeat(): a REJECTED record is a verdict too. It is
     * terminal, since getRetryableRegistries() selects only ERROR, so
     * downgrading it to ERROR would make the package retry something the agency
     * refused on validation grounds.
     */
    public function hasAgencyVerdict(): bool
    {
        return in_array($this, [self::SENT, self::ACCEPTED, self::REJECTED], true);
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
