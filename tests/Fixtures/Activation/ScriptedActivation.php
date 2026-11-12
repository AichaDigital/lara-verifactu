<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Tests\Fixtures\Activation;

use AichaDigital\LaraVerifactu\Contracts\ActivationContract;

/**
 * An activation double that answers a script, then a fixed value (AID-1907).
 *
 * It is the seam that makes the in-flight intervals deterministic: the
 * re-check can be answered `true` and every later read `false`.
 */
final class ScriptedActivation implements ActivationContract
{
    public int $calls = 0;

    /**
     * @param  list<bool>  $script  answers for the first calls, in order
     * @param  bool  $then  answer once the script is exhausted
     */
    public function __construct(
        private array $script,
        private bool $then,
    ) {}

    public function isEnabled(): bool
    {
        $this->calls++;

        return $this->script === [] ? $this->then : array_shift($this->script);
    }

    /**
     * From now on, answer $value to every call.
     */
    public function switchTo(bool $value): void
    {
        $this->script = [];
        $this->then = $value;
    }
}
