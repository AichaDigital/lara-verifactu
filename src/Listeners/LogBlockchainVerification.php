<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Listeners;

use AichaDigital\LaraVerifactu\Events\BlockchainVerifiedEvent;
use Illuminate\Support\Facades\Log;

/**
 * Log Blockchain Verification Listener
 *
 * Logs blockchain verification results.
 */
class LogBlockchainVerification
{
    /**
     * Handle the event.
     */
    public function handle(BlockchainVerifiedEvent $event): void
    {
        $level = $event->result['valid'] ? 'info' : 'error';
        $message = $event->result['valid']
            ? 'Blockchain integrity verification passed'
            : 'Blockchain integrity verification failed';

        Log::channel(config('verifactu.logging.channel', 'single'))
            ->log($level, $message, [
                'valid' => $event->result['valid'],
                'errors' => $event->result['errors'],
                'error_count' => count($event->result['errors']),
            ]);
    }
}
