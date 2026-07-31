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

    /**
     * The resolved cache store cannot carry an overlap lock across processes.
     */
    public static function overlapLockStoreNotShared(string $lockName, string $driver, bool $isNullStore): self
    {
        $why = $isNullStore
            ? 'the null store hands out a NoLock, whose acquire() returns true unconditionally, so every '
              . 'overlap check passes and nothing is ever serialised'
            : 'the array store keeps its locks in the memory of a single process, so two workers never see '
              . "each other's";

        return self::make(
            "Cache store '{$driver}' cannot carry the overlap lock '{$lockName}': {$why}. "
            . 'This lock is what stops two verifactu:retry-failed passes from racing over one record, and what '
            . 'keeps registration submissions sequential — guarantees that would be silently void here. '
            . 'Set CACHE_STORE to a store the overlapping processes share: database, redis or memcached across '
            . 'hosts, file for several processes on one host.'
        );
    }
}
