<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Listeners;

use AichaDigital\LaraVerifactu\Events\RegistrySubmittedEvent;
use Illuminate\Support\Facades\Log;

/**
 * Log Registry Submission Listener
 *
 * Logs successful registry submissions to AEAT.
 */
class LogRegistrySubmission
{
    /**
     * Handle the event.
     */
    public function handle(RegistrySubmittedEvent $event): void
    {
        Log::channel(config('verifactu.logging.channel', 'single'))
            ->info('Registry successfully submitted to AEAT', [
                'registry_number' => $event->registry->getRegistryNumber(),
                'registry_hash' => substr($event->registry->getHash(), 0, 16) . '...',
                'aeat_csv' => $event->response->getCsv(),
                'aeat_message' => $event->response->getMessage(),
            ]);
    }
}
