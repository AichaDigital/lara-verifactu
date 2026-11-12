<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use AichaDigital\LaraVerifactu\Contracts\ActivationContract;
use Closure;
use LogicException;
use Throwable;

/**
 * Proof that one send was admitted by an activation check (AID-1907, gate
 * round 1, finding 1).
 *
 * The admitted path (AeatClient::sendCore()/sendBatchCore(),
 * LazyAeatClient::sendAdmitted()) is public, so it requires one of these:
 * a direct caller cannot reach AEAT while the package is disabled, because
 * every admission comes from the private constructor, and the constructor
 * reads the switch. Running it from a rebound private-scope closure
 * (Closure::bind) is therefore a grant too, and is refused while disabled
 * (gate round 3, finding B1).
 *
 * It is a value passed by argument, never ambient state: nothing on the
 * container, the client or the registry holds it, so a re-entrant callback
 * (beforeResolving included) has nothing to consume, and the proxy passes it
 * through without spending it. It is spent once, at the core entry, so one
 * admission covers one send or one whole batch and the admitted path reads
 * activation no further (spec §4.1 rule (b), §4.2).
 *
 * Validity is set only by the private constructor, never by a default: an
 * object PHP builds without running it (the legacy `C:` unserialize format,
 * newInstanceWithoutConstructor()) is born spent (gate round 2, finding 1).
 *
 * Declared limit: code with deliberate access to this class's private scope
 * (reflection, or a closure rebound with Closure::bind()/call()) can still
 * rewrite $live. This is a guard against misuse of an @internal API inside
 * one process, not isolation from such code.
 *
 * @internal
 */
final class SendAdmission
{
    /** True only between a constructor run that read the switch on and spend(); false for any constructorless object. */
    private bool $live = false;

    /**
     * The only place an admission becomes valid, so it is also the only place
     * the switch is read: running the constructor is never enough by itself.
     *
     * @param  Closure(): Throwable  $refusal
     *
     * @throws Throwable The refusal, when the package is disabled.
     */
    private function __construct(Closure $refusal)
    {
        if (! app(ActivationContract::class)->isEnabled()) {
            throw $refusal();
        }

        $this->live = true;
    }

    /**
     * Read the switch once. Off: throw what $refusal builds. On: admit.
     *
     * @param  Closure(): Throwable  $refusal
     *
     * @throws Throwable The refusal, when the package is disabled.
     */
    public static function grant(Closure $refusal): self
    {
        return new self($refusal);
    }

    /**
     * Spend the admission; a second spend, or one not issued by grant(), is
     * refused.
     *
     * @throws LogicException When the admission was already spent or never granted.
     */
    public function spend(): void
    {
        if (! $this->live) {
            throw new LogicException('A send admission covers one send and was already spent, or was never granted.');
        }

        $this->live = false;
    }

    public function __clone()
    {
        throw new LogicException('A send admission cannot be copied.');
    }

    /**
     * @return array<never>
     */
    public function __serialize(): array
    {
        throw new LogicException('A send admission cannot be serialized.');
    }

    /**
     * @param  array<mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A send admission cannot be unserialized.');
    }
}
