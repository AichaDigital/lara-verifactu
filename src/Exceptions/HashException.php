<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Exceptions;

final class HashException extends VerifactuException
{
    public static function invalidHash(string $hash): self
    {
        return self::make("Invalid hash: {$hash}");
    }

    public static function hashMismatch(): self
    {
        return self::make('Hash verification failed: hash does not match.');
    }

    public static function chainBroken(string $invoiceId): self
    {
        return self::make("Blockchain chain is broken at invoice: {$invoiceId}");
    }

    public static function cannotGenerateHash(string $reason): self
    {
        return self::make("Cannot generate hash: {$reason}");
    }
}
