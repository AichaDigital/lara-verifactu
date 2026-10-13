# Upgrading to lara-verifactu 1.4

1.4 is a compatible minor release of the 1.x line. It adds two registry outcomes, a typed event for every outcome change, and lets `verifactu:retry-failed` sweep exhausted retries and, if you opt in, resend abandoned `pending` registries (AID-1626).

## Steps

1. **Before deploying**, set the total submission budget to the `--max-attempts` you schedule for `verifactu:retry-failed`. If you schedule `--max-attempts=18`:

   ```dotenv
   VERIFACTU_RETRY_MAX_SUBMISSION_ATTEMPTS=18
   ```

   **Until it is set, any run without `--max-attempts` — a manual run, `RetryFailedRegistriesJob`, a direct `InvoiceRegistrar::retryFailed()` call — moves `error` registries with attempts at or above 3 to `exhausted`, and your scheduled window stops retrying them.** The same budget applies on the `register()` and queued-job paths. With `--include-exhausted`, a registry swept to `exhausted` gets its extra attempt on the next run, not in the run that swept it.

2. Update the package: `composer update aichadigital/lara-verifactu`.
3. Publish the new migration: `php artisan vendor:publish --tag=lara-verifactu-migrations`. Migrations you already published keep their file names and are not overwritten. `verifactu:install` is not enough: it publishes only the three `create_*` migrations.
4. Run it: `php artisan migrate`. It converts `sent` registries whose stored answer is `ParcialmenteCorrecto` into `accepted_with_errors`.
5. Decide on `VERIFACTU_RETRY_RESEND_PENDING` (default `false`: nothing `pending` is resent on upgrade). **Enabling it makes `verifactu:retry-failed` transmit to AEAT every `pending` registry older than the grace period, including rows you created on purpose with `submitToAeat: false`; a submission cannot be undone.** Audit what would go out first:

   ```sql
   SELECT id, invoice_id, registry_number, created_at
   FROM verifactu_registries
   WHERE status = 'pending' AND deleted_at IS NULL
   ORDER BY created_at;
   ```

   `VERIFACTU_RETRY_PENDING_GRACE` (seconds, default 300, an integer >= 0) is how old a `pending` registry must be before it is resent, once the flag is on.

## What changes in behaviour

- **New statuses.** `accepted_with_errors`: AEAT registered the record with warnings (`ParcialmenteCorrecto`, `AceptadoConErrores`, or a duplicate `AceptadaConErrores`); it is filed, sealed and never resent, and `aeat_response.lineas` carries the agency's codes. `exhausted`: the submission budget is spent; the package no longer resends it on its own.
- **`markAsFailed()` may write `exhausted`** when a failure reaches the budget, and fires `RegistryRetriesExhaustedEvent` once.
- **`verifactu:retry-failed` moves `error` registries already at or past the budget to `exhausted`**, firing the events, and — only when `retry.resend_pending` is enabled — resends `pending` registries older than the grace period. `InvoiceRegistrar::retryFailed()` and `RetryFailedRegistriesJob` do the same sweep, and the job now takes the `verifactu:retry-failed` overlap lock (it does nothing while the command runs). **Like the command, the job now throws `ConfigurationException` when `cache.default` is a per-process store (`array`, `null`)**: point it at a shared store (`redis`, `database`, `file`) before deploying. `--max-attempts` still governs the run. `--include-exhausted` gives each exhausted registry one more attempt without resetting its counter.
- **`verifactu:retry-failed` rejects malformed `--max-attempts` and `--limit` values** (`1O`, `18x`, `1.9`, `0`): both must be whole numbers of at least 1. Before, they were truncated, so `--max-attempts=1O` ran with a budget of 1.
- **A duplicate answer whose original was `AceptadaConErrores` is now `accepted_with_errors`**, not `accepted`. For that duplicate, `aeat_response.lineas` and `estado_envio` come from the duplicate answer (code 3000, `Incorrecto`), not from the original record's warnings.
- **`Registry::isSubmitted()` and `hasErrors()` keep their 1.3 answers**: `isSubmitted()` is true for `sent` and `accepted_with_errors`; `hasErrors()` is true for `error` and `exhausted`. The same AEAT outcome gives the same answer as in 1.3.
- **New event `RegistryStatusChangedEvent`** (`registry`, `from`, `to`, `error`, `lines`) fires after commit for every persisted change of outcome, including creation (`null → pending`). Listen to it alone to follow a registry. Existing events are unchanged.
- **Deprecated:** `RegistryStatusEnum::isPending()` (it reports a registered `sent` record as pending) and `isSuccessful()` (it misses `sent`). Use `isRegistered()`, or compare against `PENDING`. Their answers do not change in 1.x.

## Reconciling state you track yourself

Rows converted by the migration fire no event. If your application mirrors registry outcomes, re-read them once:

```sql
SELECT id, invoice_id, registry_number
FROM verifactu_registries
WHERE status = 'accepted_with_errors'
  AND aeat_response = '{"estado_envio":"ParcialmenteCorrecto","lineas":null}';
```

`lineas: null` on those rows means «unknown», not «no errors»: the lines were never stored before 1.4.

Rows moved to `exhausted` by the retry sweep do fire `RegistryStatusChangedEvent` and `RegistryRetriesExhaustedEvent`.

## Rolling back

The migration's `down()` leaves nothing a 1.3.x install cannot load: `accepted_with_errors` rows become `sent` — including those 1.4 wrote for a duplicate answered `AceptadaConErrores`, which were `accepted` in 1.3 — and `exhausted` rows become `error`, keeping `aeat_error` and `submission_attempts`. Lines persisted by 1.4 are dropped.
