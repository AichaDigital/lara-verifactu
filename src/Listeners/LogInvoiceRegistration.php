<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Listeners;

use AichaDigital\LaraVerifactu\Events\InvoiceRegisteredEvent;
use Illuminate\Support\Facades\Log;

/**
 * Log Invoice Registration Listener
 *
 * Logs invoice registration events.
 */
class LogInvoiceRegistration
{
    /**
     * Handle the event.
     */
    public function handle(InvoiceRegisteredEvent $event): void
    {
        Log::channel(config('verifactu.logging.channel', 'single'))
            ->info('Invoice registered in Verifactu system', [
                'invoice_id' => $event->invoice->id ?? null,
                'invoice_number' => $event->invoice->getNumber(),
                'invoice_serie' => $event->invoice->getSerie(),
                'registry_number' => $event->registry->getRegistryNumber(),
                'registry_hash' => substr($event->registry->getHash(), 0, 16) . '...',
                'submitted_to_aeat' => $event->submittedToAeat,
            ]);
    }
}
