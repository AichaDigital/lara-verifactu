<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Exceptions;

final class CertificateException extends VerifactuException
{
    public static function fileNotFound(string $path): self
    {
        return self::make("Certificate file not found at path: {$path}");
    }

    public static function invalidPassword(): self
    {
        return self::make('Invalid certificate password.');
    }

    public static function invalidFormat(): self
    {
        return self::make('Invalid certificate format.');
    }

    public static function expired(): self
    {
        return self::make('Certificate has expired.');
    }

    public static function notYetValid(): self
    {
        return self::make('Certificate is not yet valid.');
    }

    public static function invalidPrivateKey(): self
    {
        return self::make('Invalid or missing private key in certificate');
    }
}
