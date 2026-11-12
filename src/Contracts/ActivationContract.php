<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use AichaDigital\LaraVerifactu\Exceptions\ConfigurationException;

/**
 * The single read point of the package-wide activation switch (AID-1907).
 *
 * This is a test seam, not a supported consumer extension point: a scripted
 * double can answer differently on successive calls, which is what makes
 * the in-flight intervals deterministic in tests. It holds no per-send
 * state.
 *
 * @internal
 */
interface ActivationContract
{
    /**
     * Whether the package will register, chain and submit.
     *
     * Reads the in-process configuration on every call; never cached.
     *
     * @throws ConfigurationException When the configured value is not a recognised boolean.
     */
    public function isEnabled(): bool;
}
