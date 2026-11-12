<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use AichaDigital\LaraVerifactu\Contracts\ActivationContract;
use AichaDigital\LaraVerifactu\Exceptions\ConfigurationException;

/**
 * Default activation read point (AID-1907).
 *
 * Depends on nothing but the config repository, so resolving it never builds
 * the AEAT client or the registrar. The default lives here, at the read
 * point, so a consumer whose published config predates the key is off.
 *
 * @internal
 */
final class Activation implements ActivationContract
{
    /** The only place in src/ where the configuration key is spelled out. */
    public const CONFIG_KEY = 'verifactu.enabled';

    public const ENV_VARIABLE = 'VERIFACTU_ENABLED';

    private const TRUE_SPELLINGS = ['1', 'true', 'on', 'yes'];

    private const FALSE_SPELLINGS = ['0', 'false', 'off', 'no'];

    /**
     * The disabled notice shared by the commands, naming the key and the
     * variable that turns it on.
     */
    public static function disabledNotice(): string
    {
        return sprintf('VeriFACTU is disabled (%s=false)', self::CONFIG_KEY);
    }

    public function isEnabled(): bool
    {
        return self::parse(config(self::CONFIG_KEY, false));
    }

    /**
     * Strict parser: the type and value are checked before any boolean
     * conversion. A bare filter_var() would read null and '' as false, which
     * would let a typo silently stop fiscal registration.
     *
     * @throws ConfigurationException
     */
    private static function parse(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if ($raw === 1 || $raw === 0) {
            return $raw === 1;
        }

        if (is_string($raw)) {
            $normalised = strtolower(trim($raw));

            if (in_array($normalised, self::TRUE_SPELLINGS, true)) {
                return true;
            }

            if (in_array($normalised, self::FALSE_SPELLINGS, true)) {
                return false;
            }
        }

        throw ConfigurationException::invalidActivation($raw);
    }
}
