<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use AichaDigital\LaraVerifactu\Contracts\ActivationContract;
use Illuminate\Support\Facades\Log;

/**
 * Queued work while the package is disabled ends in a logged no-op (AID-1907,
 * spec §4.3).
 *
 * Called as the first statement of a job's handle(), before any lock, hold,
 * release, attempt or outcome write. The job then returns normally and the
 * queue deletes it: the work item is CONSUMED, not paused, and the registry
 * it pointed to is recovered through the pending/retry path after
 * re-enabling.
 *
 * @internal
 */
trait SkipsWhenDisabled
{
    /**
     * @param  array<string, int|string|null>  $subject  registry_id or invoice_id
     */
    private function skippedBecauseDisabled(array $subject = []): bool
    {
        if (app(ActivationContract::class)->isEnabled()) {
            return false;
        }

        Log::channel(config('verifactu.logging.channel', 'single'))
            ->warning('verifactu.disabled_noop', [
                'job' => static::class,
                ...$subject,
                'reason' => 'disabled',
                'outcome' => 'skipped_disabled',
            ]);

        return true;
    }
}
