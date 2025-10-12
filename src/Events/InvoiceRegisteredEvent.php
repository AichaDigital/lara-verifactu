<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Events;

use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Invoice Registered Event
 *
 * Fired when an invoice is successfully registered in the Verifactu system.
 */
class InvoiceRegisteredEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly InvoiceContract $invoice,
        public readonly RegistryContract $registry,
        public readonly bool $submittedToAeat = false
    ) {}
}
