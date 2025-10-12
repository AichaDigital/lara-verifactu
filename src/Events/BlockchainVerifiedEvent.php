<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Blockchain Verified Event
 *
 * Fired when blockchain integrity verification is completed.
 */
class BlockchainVerifiedEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  array{valid: bool, errors: array<string>}  $result
     */
    public function __construct(
        public readonly array $result
    ) {}
}
