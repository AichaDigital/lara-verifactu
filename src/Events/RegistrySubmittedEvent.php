<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Events;

use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Registry Submitted Event
 *
 * Fired when a registry is successfully submitted to AEAT.
 */
class RegistrySubmittedEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly RegistryContract $registry,
        public readonly AeatResponse $response
    ) {}
}
