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

    public function canRetry(): bool
    {
        return in_array($this, [self::PENDING, self::ERROR, self::REJECTED]);
    }
}
