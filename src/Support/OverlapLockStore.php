<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use AichaDigital\LaraVerifactu\Exceptions\ConfigurationException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Support\Facades\Cache;

/**
 * Guard for the overlap locks (AID-220 follow-up).
 *
 * Two guarantees of this package ride on a cache lock:
 *
 *  - `verifactu:retry-failed` takes one so two overlapping runs cannot hand the
 *    same record to both and race to write its outcome (AID-731).
 *  - `ProcessInvoiceRegistrationJob` takes one to keep submissions sequential,
 *    which is what preserves chain ordering.
 *
 * Both are conditional on the consumer's cache store being visible to every
 * process that could overlap — a condition the package used to neither state
 * nor check. On a per-process store the locks are decorative, and the failure
 * is silent: the command reports success, the job proceeds, and the invariant
 * they exist to protect is simply unguarded.
 *
 * The two refused stores were measured, not assumed:
 *
 *  - `null` returns `NoLock`, whose `acquire()` returns TRUE unconditionally
 *    (Illuminate\Cache\NoLock). Every overlap check passes.
 *  - `array` returns a real `ArrayLock`, but it lives in the memory of a single
 *    process, so two queue workers never see each other's.
 *
 * `file` and anything above it are accepted. Note that `file` is shared between
 * processes of ONE host: a consumer running workers on several machines needs a
 * store they all reach (`database`, `redis`, `memcached`). That is documented
 * rather than enforced — the package cannot tell how many hosts it runs on.
 */
final class OverlapLockStore
{
    /**
     * Fail loudly when the resolved cache store cannot carry an overlap lock.
     *
     * Checked against the resolved store INSTANCE, not the configured driver
     * name: a consumer may alias a driver, and what matters is the object that
     * will actually hand out the lock.
     *
     * @throws ConfigurationException
     */
    public static function assertUsable(string $lockName): void
    {
        $store = Cache::store()->getStore();

        if (! $store instanceof NullStore && ! $store instanceof ArrayStore) {
            return;
        }

        throw ConfigurationException::overlapLockStoreNotShared(
            $lockName,
            (string) config('cache.default'),
            $store instanceof NullStore,
        );
    }
}
