<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Exceptions;

final class PublishedMigrationsException extends VerifactuException
{
    /**
     * @param  list<string>  $files
     */
    public static function found(array $files): self
    {
        $list = implode(PHP_EOL, array_map(fn (string $file): string => '  - ' . $file, $files));

        return self::make(
            'lara-verifactu runs its migrations from vendor/ since 1.6.0, but this application still has '
            . count($files) . ' published copies of them. Nothing was migrated. Remove these files:' . PHP_EOL
            . $list . PHP_EOL
            . 'Then follow UPGRADE-1.6.md: the package schema must be recreated from vendor/, it cannot be '
            . 'adopted from tables built by the copies.'
        );
    }
}
