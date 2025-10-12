<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Events;

use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Registry Failed Event
 *
 * Fired when a registry submission to AEAT fails.
 */
class RegistryFailedEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly RegistryContract $registry,
        public readonly string $error,
        public readonly int $attempt
    ) {}
}
