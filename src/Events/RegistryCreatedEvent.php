<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Events;

use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Registry Created Event
 *
 * Fired when a new registry is created (before AEAT submission).
 */
class RegistryCreatedEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly RegistryContract $registry,
        public readonly InvoiceContract $invoice
    ) {}
}
