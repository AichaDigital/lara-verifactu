<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Listeners;

use AichaDigital\LaraVerifactu\Events\RegistryFailedEvent;
use Illuminate\Support\Facades\Log;

/**
 * Log Registry Failure Listener
 *
 * Logs failed registry submissions.
 */
class LogRegistryFailure
{
    /**
     * Handle the event.
     */
    public function handle(RegistryFailedEvent $event): void
    {
        Log::channel(config('verifactu.logging.channel', 'single'))
            ->error('Registry submission failed', [
                'registry_number' => $event->registry->getRegistryNumber(),
                'registry_hash' => substr($event->registry->getHash(), 0, 16) . '...',
                'error' => $event->error,
                'attempt' => $event->attempt,
            ]);
    }
}
