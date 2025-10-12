<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Listeners;

use AichaDigital\LaraVerifactu\Events\RegistryCreatedEvent;
use Illuminate\Support\Facades\Log;

/**
 * Log Registry Creation Listener
 *
 * Logs when a new registry is created.
 */
class LogRegistryCreation
{
    /**
     * Handle the event.
     */
    public function handle(RegistryCreatedEvent $event): void
    {
        Log::channel(config('verifactu.logging.channel', 'single'))
            ->info('New registry created', [
                'registry_number' => $event->registry->getRegistryNumber(),
                'registry_hash' => substr($event->registry->getHash(), 0, 16) . '...',
                'invoice_number' => $event->invoice->getNumber(),
                'invoice_serie' => $event->invoice->getSerie(),
                'previous_hash' => $event->registry->getPreviousHash()
                    ? substr($event->registry->getPreviousHash(), 0, 16) . '...'
                    : 'null (first registry)',
            ]);
    }
}
