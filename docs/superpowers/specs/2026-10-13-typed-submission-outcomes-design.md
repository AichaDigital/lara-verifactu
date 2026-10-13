# Typed submission outcomes — design (AID-1626)

- **Ticket:** AID-1626
- **Origin:** openmizan integration contract (AID-1609), order of work lara-verifactu → larabill → clientes
- **Unblocks:** larabill AID-1630 (per-invoice fiscal submission state fed by typed outcomes)
- **Release class:** minor, compatible within `1.x` (CONSTITUTION §8)
- **Date:** 2026-10-13

## 1. Problem

A consumer cannot follow the fiscal fate of a registry without parsing messages. Three gaps were measured against `main` (v1.3.1):

1. **PENDING is abandoned.** A registry created with `submitToAeat=false`, or left behind by a crash between the creation commit and the submission, stays `pending` forever. `RegistryManager::getPendingRegistries()` has no caller in `src/`; `verifactu:retry-failed` selects only `error`.
2. **Accepted-with-errors loses its detail.** `ParcialmenteCorrecto` / `AceptadoConErrores` is persisted as `sent` with `aeat_response = "ParcialmenteCorrecto"`. The per-line codes and descriptions are discarded on the accepted path. A duplicate answer `AceptadaConErrores` becomes `accepted` and loses the same detail.
3. **Exhausted retries vanish.** An `error` row whose `submission_attempts` reaches the budget drops out of the retry query with no transition and no signal. `SubmitRegistryToAeatJob` swallows the exception on its last attempt, so `failed()` never runs.

Two further facts shape the design:

- A plain `Correcto` answer is stored as `sent` (`InvoiceRegistrar::submitToAeat`), and only a duplicate reaches `accepted`. `RegistryStatusEnum::isPending()` includes `sent` and `isSuccessful()` recognises only `accepted`, so through the enum helpers a registered invoice reads as pending.
- `InvoiceRegisteredEvent` fires after `submitToAeat()`; a transport failure throws before it, so a consumer listening only to it never learns of the failure. Rejection and failure share `RegistryFailedEvent`.

## 2. Goals and non-goals

Goals:

- Every registry carries, at every moment, one outcome from a closed set, persisted in `status`.
- Every real change of that outcome is announced by one typed event carrying the reason and the agency lines, so a consumer needs one listener and never parses text.
- `verifactu:retry-failed` can resend stale `pending` rows — opt-in via `retry.resend_pending`, default `false` (owner decision, 2026-10-13: a `pending` row may be deliberate, `submitToAeat: false`, and a transmission to AEAT is irreversible, so activation must be explicit, CONSTITUTION §8) — and exhaustion becomes a persisted state with a one-time signal.
- Existing persisted rows are migrated in the same change.

Non-goals:

- «Not required» (no fiscal obligation) is a larabill decision, not a package outcome. The package only reports on an existing `Registry`.
- Correcting an accepted-with-errors record (subsanación) is out of scope.
- The package does not schedule itself; the consumer schedules `verifactu:retry-failed`.
- No change in larabill or clientes here (AID-1630 and conformity follow-ups).

## 3. Outcome taxonomy

| Package outcome | `status` value | New |
|---|---|---|
| Pending | `pending` | no |
| Registered | `sent` (`Correcto`) or `accepted` (duplicate `Correcta`) | no |
| Registered with errors | `accepted_with_errors` | yes |
| Rejected by AEAT | `rejected` | no |
| Retryable failure | `error` | no |
| Retries exhausted | `exhausted` | yes |

`sent` keeps its name and is documented as «registered, `Correcto`». Renaming it would migrate every registered row for no gain.

The consumer maps these six to its own presentation; for example larabill may group `error` and `exhausted` under its own «pending» / «action required» policy. That mapping lives in the consumer.

## 4. `RegistryStatusEnum`

New cases:

- `ACCEPTED_WITH_ERRORS = 'accepted_with_errors'`, description «Aceptado con errores por AEAT».
- `EXHAUSTED = 'exhausted'`, description «Reintentos agotados».

Helper changes, all additive (existing cases keep their answers):

- `isFiledAtAeat()` gains `ACCEPTED_WITH_ERRORS`: never resent.
- `hasAgencyVerdict()` gains `ACCEPTED_WITH_ERRORS`: sealed by `Registry::booted()` and protected by the Invoice cascade guard through `agencyVerdictValues()` (AID-220). `EXHAUSTED` is not a verdict; it behaves like `ERROR` for sealing and deletion.
- `isFinal()` gains `ACCEPTED_WITH_ERRORS`. `EXHAUSTED` stays out, since it can be reactivated manually (§7).
- `canRetry()` stays `PENDING` + `ERROR`.
- New `isRegistered()`: `SENT`, `ACCEPTED`, `ACCEPTED_WITH_ERRORS`. This is the helper consumers should use.

Unchanged and deprecated (CONSTITUTION §8: a compatible alternative exists, so their semantics must not change inside `1.x`):

- `isPending()` keeps `PENDING` + `SENT`. Docblock `@deprecated`: it reports a registered `Correcto` record as pending; use `$status === PENDING` or `isRegistered()`.
- `isSuccessful()` keeps `ACCEPTED` only. Docblock `@deprecated`: it misses a plain `Correcto`; use `isRegistered()`.

`StatusCommand` counts the two new statuses.

## 5. Parser and `AeatResponse`

`AeatResponseParser::parse()`:

- On the accepted path, also collect `collectLineDetails()` and return it in `data['lineas']`, the same shape the rejection and duplicate paths already use (`estado_registro`, `codigo`, `descripcion`, `registro_duplicado`, `registro_duplicado_estado`).
- The parser is the only place that decides «with errors»:
  - accepted path: `EstadoEnvio === 'ParcialmenteCorrecto'`, or any line with `EstadoRegistro === 'AceptadoConErrores'`;
  - duplicate path: any line with `registro_duplicado_estado === 'AceptadaConErrores'` (note the gender, L19 vs L21).

`AeatResponse`:

- New constructor parameter `bool $acceptedWithErrors = false`, last, named, defaulted: no caller breaks.
- New factory `acceptedWithErrors(...)` and accessor `isAcceptedWithErrors()`. An accepted-with-errors response is also `isSuccess()`; a duplicate one is also `isDuplicate()`.

`InvoiceRegistrar::submitToAeat()` asks `isAcceptedWithErrors()` before `isDuplicate()`; it never compares strings.

## 6. `RegistryManager` writers

New `markAsAcceptedWithErrors(RegistryContract $registry, ?string $aeatCsv, array $aeatResponse): void`:

- Same transaction shape, `lockAndRefresh()` (AID-731) and `isFiledAtAeat()` idempotency guard as `markAsSubmitted()` / `markAsAccepted()`.
- Writes `status = accepted_with_errors`, `submitted_at` (kept if already set, as `markAsAccepted()` does), `aeat_csv` (null, never `''`, for the UNIQUE index), `aeat_response` = the structured array (`estado_envio`, `csv`, `lineas`), `submission_attempts + 1`.
- `aeat_error` stays null: this is a valid record with warnings, not an error.

`markAsSubmitted()` and `markAsAccepted()` keep their signatures and behaviour.

`markAsFailed(RegistryContract $registry, string $error, ?int $maxSubmissionAttempts = null)` — new trailing optional parameter:

- Inside its transaction and under the lock, after incrementing the counter: if the registry was `ERROR` or `PENDING` and `submission_attempts >= budget`, it writes `EXHAUSTED` instead of `ERROR`. The budget is the parameter, or `config('verifactu.retry.max_submission_attempts', 3)` when null.
- If the registry was already `EXHAUSTED` (manual reactivation, §7), a new failure keeps `EXHAUSTED` and is not a new crossing.
- The existing `hasAgencyVerdict()` guard is unchanged.

`markAsRejected()` is unchanged.

Every writer reports whether it actually transitioned and from which state, so events (§8) fire only on real changes.

## 7. Resend, budget and exhaustion

Configuration:

- New key `verifactu.retry.max_submission_attempts`, default 3, read with the default at the read point (`config(key, 3)`), so consumers who published the config file before this release, and therefore lack the key, get 3 (the `mergeConfigFrom` shallow-merge lesson of 2026-07-31).
- New key `verifactu.retry.resend_pending`, default `false`, same read-point default.
- New key `verifactu.retry.pending_grace`, seconds, default 300, same read-point default. Validated (integer >= 0) only when `resend_pending` is on.
- `verifactu.retry.max_attempts` keeps its current meaning: the queue-level `$tries` of `SubmitRegistryToAeatJob` (also read by `ProcessInvoiceRegistrationJob`). Its config comment is rewritten to say so and to point at `max_submission_attempts` for the total budget.

`verifactu:retry-failed`:

- `--max-attempts` stays and keeps meaning the budget for this run. Its default becomes «unset», which resolves to `max_submission_attempts`. clientes schedules `--max-attempts=18` (`routes/console.php:125`, AID-721) as its retry window, so the option must keep governing.
- The run budget is passed through `InvoiceRegistrar::retryFailed()` and `submitToAeat()` down to `markAsFailed()`, so the crossing uses the same number the selection used.
- Selection, through a new `RegistryManager` query (the existing `getRetryableRegistries()` and `getPendingRegistries()` keep their result sets):
  - `pending` rows whose `created_at` is older than `pending_grace` seconds, **only when `retry.resend_pending` is true (default `false`; owner decision 2026-10-13: the reference consumer creates deliberate `pending` rows with `submitToAeat: false` while its submit switch is off, and upgrading must not transmit that backlog)**. The grace avoids racing an in-flight `register()` or a still-queued `SubmitRegistryToAeatJob`. If a double submission still happens, AEAT answers «duplicate» and AID-727 reconciles it.
  - `error` rows with `submission_attempts < budget`;
  - with `--include-exhausted`, also `exhausted` rows: one more attempt each, counter not reset (history kept). Success moves them to a registered state; failure keeps them `exhausted` with no new exhaustion event.
  - Ordered by `created_at` ascending, as today.
- **Over-budget sweep.** In the same run, after the candidates are selected (selection validates the retry configuration, so a misconfiguration fails before anything is touched), `error` rows with `submission_attempts >= budget` are moved to `exhausted` under the row lock, emitting the events of §8. This converts legacy rows using the consumer's real budget rather than a guess made at migration time.
- The dead `skipped` branch of `retryFailed()` is removed; the `skipped` key stays in the returned array at 0 so readers do not break.

`InvoiceRegistrar::retryFailed(?int $maxAttempts = null, int $limit = 50, bool $includeExhausted = false)` and `RetryFailedRegistriesJob(?int $maxAttempts = null, int $limit = 50)`: types widened, defaults resolve to the config budget (still 3). Compatible.

Consumers should set `max_submission_attempts` equal to the `--max-attempts` they schedule; otherwise a failure in the register or queued-job path could exhaust at the config budget while the command allows more. The upgrade notes say so.

## 8. Events

New `RegistryStatusChangedEvent`:

```php
public function __construct(
    public readonly RegistryContract $registry,
    public readonly ?RegistryStatusEnum $from,
    public readonly RegistryStatusEnum $to,
    public readonly ?string $error,
    public readonly ?array $lines,
) {}
```

- `error`: the readable reason for `error`, `exhausted` and `rejected`; null otherwise.
- `lines`: the agency lines for `rejected` and `accepted_with_errors`, in the parser shape documented in §5; null otherwise.
- Emitted by `RegistryManager` for every persisted transition, after commit (`DB::afterCommit`), and only when the row actually changed. A write cut short by an idempotency guard emits nothing.
- Also emitted on creation (`null → pending`), so consumers see rows created with `submitToAeat=false`.
- Dispatched quietly: a listener exception is logged and never reclassifies the outcome (AID-729). The quiet dispatch is shared with `InvoiceRegistrar` instead of duplicated.

New `RegistryRetriesExhaustedEvent(RegistryContract $registry, string $lastError, int $attempts)`, emitted once, on the `error|pending → exhausted` crossing (failure path or over-budget sweep). Not emitted when an already `exhausted` row fails again.

Unchanged: `RegistrySubmittedEvent` still fires on acceptance, including with errors (its `AeatResponse` exposes `isAcceptedWithErrors()`); `RegistryFailedEvent` still fires on every failure, including the crossing one; `InvoiceRegisteredEvent` and `RegistryCreatedEvent` are untouched.

## 9. Data migration and upgrade

`status` is already a plain string column, so the schema does not change. One data migration, `2026_10_13_000001_backfill_accepted_with_errors_in_verifactu_registries`, registered in `LaraVerifactuServiceProvider::hasMigrations()` (otherwise it is never published, as happened with AID-186):

- `up()`: `sent` rows whose `aeat_response` is `ParcialmenteCorrecto` become `accepted_with_errors`, with `aeat_response = {"estado_envio": "ParcialmenteCorrecto", "lineas": null}`. Both stored forms are matched: the raw string and the JSON-encoded string the `array` cast writes. Query builder, so no model event and no `RegistryStatusChangedEvent`.
- `down()`: `accepted_with_errors` → `sent` with `aeat_response = "ParcialmenteCorrecto"` (lines are dropped; duplicate-origin rows, which were `accepted` in 1.3, also become `sent`; declared), and `exhausted` → `error`, because the 1.3 enum cast cannot load `exhausted`.
- `error` rows are not touched by the migration; the over-budget sweep (§7) converts them with the consumer's budget and emits the events.

Declared limits:

- Legacy `accepted_with_errors` rows have `lineas: null`, meaning «unknown», not «no errors»: the lines were never stored.
- Legacy duplicates answered `AceptadaConErrores` cannot be told apart from `Correcta`, because the stored text does not say. They stay `accepted`.

Delivery: `UPGRADE-1.4.md` at the package root (in the dist; `CHANGELOG.md` and `docs/` are export-ignored): publish the new migration with `php artisan vendor:publish --tag=lara-verifactu-migrations` (`verifactu:install` publishes only the three create migrations), `php artisan migrate`, set `VERIFACTU_RETRY_MAX_SUBMISSION_ATTEMPTS` to match the scheduled `--max-attempts`, and a one-off reconciliation query for consumers who track state (migrated rows emit no event).

`CHANGELOG.md` under `[Unreleased]`, with the behaviour changes in bold: new statuses; `markAsFailed()` may now write `exhausted`; `retry-failed` can resend stale `pending` rows (opt-in) and sweeps over-budget `error` rows; `isPending()` / `isSuccessful()` deprecated.

## 10. Compatibility analysis (CONSTITUTION §8)

- New enum cases and helpers: additive. A consumer with an exhaustive `match` over `RegistryStatusEnum` would see unmatched cases. Measured on 2026-10-13: neither larabill nor clientes matches exhaustively (larabill uses `PENDING` in one test; clientes asserts `SENT` in one integration test).
- `isPending()` / `isSuccessful()`: semantics preserved, deprecated with a substitute.
- `--max-attempts`, `retryFailed()`, `RetryFailedRegistriesJob`, `markAsFailed()`: same meaning, widened types or trailing optional parameters.
- Persisted semantics: `sent` rows that were `ParcialmenteCorrecto` change status through a compatible data migration with an upgrade path; `error` rows past budget change status through the sweep. Both are the behaviour the ticket requires.
- The product contract requires conformity of the generic consumer and of Castris for any change to the outcome taxonomy: the larabill and clientes gates are part of the definition of done.

## 11. Testing

- Enum: full matrix of every helper × every case, including the deprecated ones staying put.
- Parser: `ParcialmenteCorrecto` envelope; a single `AceptadoConErrores` line under a `Correcto` envelope; duplicate `AceptadaConErrores`; duplicate `Correcta` still `accepted`; `lineas` present on the accepted path.
- `markAsAcceptedWithErrors()`: persists the structure, CSV null not `''`, idempotent against an already filed row, sealed afterwards.
- `markAsFailed()`: crossing writes `exhausted` and emits exactly one `RegistryRetriesExhaustedEvent`; an explicit budget overrides config; an `exhausted` row failing again stays `exhausted` with no second event; the verdict guard still wins.
- `retry-failed`: stale `pending` selected, fresh `pending` skipped; `error` under budget selected; over-budget `error` swept to `exhausted` with events; `exhausted` only with `--include-exhausted`; `--max-attempts=18` honoured end to end; overlap lock unchanged.
- `RegistryStatusChangedEvent`: one per real transition, none when a guard cuts, emitted after commit (not inside a caller's transaction that rolls back), `error`/`lines` filled per §8, listener exception isolated.
- Upgrade path: seed `sent` rows with both stored forms of `ParcialmenteCorrecto`, a `sent` `Correcto` row and an `accepted` row → run the migration → assert conversion, untouched rows, and `down()`.
- Re-run the gated concurrency suite (`tests/Concurrency`) and the consumer conformity gates (larabill, clientes).

## 12. Follow-ups

- larabill AID-1630: consume `RegistryStatusChangedEvent` and map the six outcomes.
- clientes: set `VERIFACTU_RETRY_MAX_SUBMISSION_ATTEMPTS` to its scheduled window (18) when adopting the release.
