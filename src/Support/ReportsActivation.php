<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use AichaDigital\LaraVerifactu\Contracts\ActivationContract;
use AichaDigital\LaraVerifactu\Exceptions\ConfigurationException;

/**
 * Visible activation report for the read-only console commands (AID-1907).
 *
 * Reports an unparseable switch instead of throwing: verifactu:status and
 * verifactu:install are where a consumer checks the effective state.
 *
 * @internal
 */
trait ReportsActivation
{
    /**
     * Print a warning when the package is disabled or misconfigured, and
     * return the value to show in a settings table.
     */
    private function reportActivation(): string
    {
        try {
            if (app(ActivationContract::class)->isEnabled()) {
                return 'Yes';
            }
        } catch (ConfigurationException $e) {
            $this->error('✗ ' . $e->getMessage());

            return 'Invalid';
        }

        $this->warn('⚠ ' . Activation::disabledNotice() . ': the package registers and sends nothing to the AEAT. '
            . 'Set ' . Activation::ENV_VARIABLE . '=true to enable it.');

        return 'No';
    }
}
