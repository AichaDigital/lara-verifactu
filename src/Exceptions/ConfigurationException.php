<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Exceptions;

final class ConfigurationException extends VerifactuException
{
    public static function missingCertificate(): self
    {
        return self::make('Certificate path is not configured. Please set VERIFACTU_CERT_PATH in your .env file.');
    }

    public static function missingCertificatePassword(): self
    {
        return self::make('Certificate password is not configured. Please set VERIFACTU_CERT_PASSWORD in your .env file.');
    }

    public static function invalidMode(string $mode): self
    {
        return self::make("Invalid mode '{$mode}'. Mode must be 'native' or 'custom'.");
    }

    public static function invalidEnvironment(string $environment): self
    {
        return self::make("Invalid environment '{$environment}'. Environment must be 'production' or 'sandbox'.");
    }

    public static function modelDoesNotImplementContract(string $model, string $contract): self
    {
        return self::make("Model '{$model}' does not implement '{$contract}'.");
    }

    public static function unknownEndpoint(string $environment, string $certificateType): self
    {
        return self::make(
            "No AEAT endpoint configured for environment '{$environment}' and certificate type '{$certificateType}'. "
            . 'Valid environments: production, sandbox. Valid certificate types: ciudadano, representante, sello.'
        );
    }
}
