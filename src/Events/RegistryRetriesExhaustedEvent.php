<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Events;

use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Registry Retries Exhausted Event (AID-1626)
 *
 * Fired once, when a registry crosses into EXHAUSTED: its submission budget is
 * spent and the package will not resend it on its own. Not fired again when an
 * already exhausted registry fails a manual extra attempt.
 */
class RegistryRetriesExhaustedEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly RegistryContract $registry,
        public readonly string $lastError,
        public readonly int $attempts,
    ) {}
}
