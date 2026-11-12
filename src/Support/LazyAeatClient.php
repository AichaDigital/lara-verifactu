<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuDisabledException;
use AichaDigital\LaraVerifactu\Services\AeatClient;
use Closure;
use Illuminate\Support\Collection;

/**
 * The package's binding of AeatClientContract (AID-1907, spec §4.1).
 *
 * Enabled when the binding resolves: the concrete client is built at once,
 * exactly as before 1.7, so an invalid AEAT configuration still fails at
 * resolution. Disabled when the binding resolves: the factory is held
 * unresolved, so building the registrar, the facade root or a job's injected
 * registrar never builds the client, and an unusable AEAT configuration
 * cannot fail before an activation guard.
 *
 * The proxy only delegates and guards. It delegates through the concrete
 * client's unguarded core, never through a guarded public method, so one
 * call crosses at most one client guard.
 *
 * @internal
 */
final class LazyAeatClient implements AeatClientContract
{
    /**
     * @param  Closure(): AeatClient  $factory
     */
    private function __construct(
        private readonly Closure $factory,
        private ?AeatClient $client = null,
    ) {}

    /**
     * @param  Closure(): AeatClient  $factory
     */
    public static function eager(Closure $factory): self
    {
        return new self($factory, $factory());
    }

    /**
     * @param  Closure(): AeatClient  $factory
     */
    public static function deferred(Closure $factory): self
    {
        return new self($factory);
    }

    /**
     * Guarded, for direct users of the contract.
     *
     * @throws VerifactuDisabledException When the package is disabled; the client is not built.
     */
    public function sendRegistration(RegistryContract $registry): AeatResponse
    {
        $admission = SendAdmission::grant(
            fn () => VerifactuDisabledException::forOperation('AeatClient::sendRegistration'),
        );

        return $this->concrete()->sendCore($registry, $admission);
    }

    /**
     * Guarded once at entry: a batch is one admitted effect.
     *
     * @param  Collection<int, RegistryContract>  $registries
     * @return Collection<int, AeatResponse>
     *
     * @throws VerifactuDisabledException When the package is disabled; nothing is sent.
     */
    public function sendBatch(Collection $registries): Collection
    {
        $admission = SendAdmission::grant(
            fn () => VerifactuDisabledException::forOperation('AeatClient::sendBatch'),
        );

        return $this->concrete()->sendBatchCore($registries, $admission);
    }

    /**
     * The registrar's admitted send (spec §4.1 rule (b)): the activation
     * decision was taken by InvoiceRegistrar::submitToAeat()'s re-check, once
     * for the whole send, so nothing here reads activation again. The
     * admission that re-check granted is required, passed through unspent and
     * spent by the concrete client's core (gate round 1, finding 1).
     *
     * A deferred client whose factory throws here (switched on later in the
     * same process with an unusable AEAT configuration) raises inside the
     * registrar's transport try and gets today's transport-failure handling.
     *
     * @internal
     */
    public function sendAdmitted(RegistryContract $registry, SendAdmission $admission): AeatResponse
    {
        return $this->concrete()->sendCore($registry, $admission);
    }

    private function concrete(): AeatClient
    {
        return $this->client ??= ($this->factory)();
    }
}
