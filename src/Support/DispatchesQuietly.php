<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use Illuminate\Support\Facades\Log;

/**
 * Shared by InvoiceRegistrar and RegistryManager so the AID-729 guarantee —
 * a throwing listener never reclassifies an outcome — has one implementation
 * (AID-1626). It carries the agency outcomes and also the creation
 * announcement (RegistryStatusChangedEvent with `from` null), so a listener
 * that throws on creation cannot abort the registry being created.
 */
trait DispatchesQuietly
{
    /**
     * Dispatch an outcome event without letting a consumer listener rewrite the
     * outcome (AID-729).
     *
     * These events are dispatched AFTER the agency's answer has been persisted.
     * A listener that throws is a defect in the consumer, not a failure of the
     * round trip, and must not turn a successful submission into
     * AeatException::connectionFailed nor a terminal REJECTED into a retryable
     * ERROR — both of which the previous single catch did.
     *
     * The failure is logged rather than propagated: the operation's result is
     * already durable and already returned. Swallowing it here keeps the return
     * value and the persisted state truthful about what the agency said.
     */
    private function dispatchQuietly(object $event, RegistryContract $registry): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->error('A listener threw while handling a Verifactu outcome event', [
                    'registry_number' => $registry->getRegistryNumber(),
                    'event' => $event::class,
                    'error' => AeatLogSanitizer::redactText($e->getMessage()),
                    ...AeatLogSanitizer::traceContext($e),
                ]);
        }
    }
}
