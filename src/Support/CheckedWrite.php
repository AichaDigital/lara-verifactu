<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;

/**
 * Eloquent's `save()` returns false — without throwing — when a `saving` or
 * `creating` listener vetoes the write. A writer whose contract is «this row
 * is persisted or the surrounding transaction rolls back» must check that bool;
 * this is the single place that turns a vetoed write into an exception
 * (AID-1855). Same shape as larabill's `CheckedWrite::orFail()`, raised as the
 * package's own exception so `@throws VerifactuException` stays true.
 *
 * @internal
 */
final class CheckedWrite
{
    /**
     * @param  bool|null  $saved  the return of save()
     * @param  string  $what  what was being written, for the message
     *
     * @throws VerifactuException when the write was vetoed
     */
    public static function orFail(?bool $saved, string $what): void
    {
        if ($saved !== true) {
            throw VerifactuException::make(
                "The write of {$what} was vetoed by a model listener and nothing was persisted; "
                . 'the surrounding transaction must roll back.'
            );
        }
    }
}
