# AID-1907 — Package-wide activation switch `verifactu.enabled`

- **Status:** draft, revision 22 (2026-11-12). Revision history:
  - rev 2 closed §8 with the owner's MINOR ruling (C-03);
  - rev 3 recorded the G2-G6 decisions (C-04) and the reviewer brief;
  - rev 4 applies spec gate round 1 (§13);
  - rev 5 applies round 2 and records the umbrella's G7 proposal (C-06a);
  - rev 6 applies round 3;
  - rev 7 fixes the round-4 P3 notation;
  - rev 8 applies round 5 (unbounded): the lazy AEAT client replaces late resolution and constructor widening;
  - rev 9 applies round 6: invocation-phase provenance; the client is lazy only when disabled at resolution;
  - rev 10 fixes the round-7 P3 factory signatures;
  - rev 11 applies round 8 (unbounded closing);
  - rev 12 applies round 9: single-use send admission replaces the class-based passthroughs;
  - rev 13 applies round 10: an internal admitted send path replaces the admission token;
  - rev 14 applies round 11: re-entry test made satisfiable, stale wording removed;
  - rev 15 applies the union of the closing pair (rounds 12-13): one P3 wording qualification. No mechanism or requirement changed;
  - rev 16 replaces the retired consumer-data notice with the owner's signed exception of 2026-11-08 as the basis of the MINOR classification (umbrella C-22, C-25), and requires the CHANGELOG and UPGRADE to cite it. No mechanism changed;
  - rev 17 (`f6038cb`) applied the owner's G7 ruling (C-27): a series-sequence check inside `createRegistry()` under the chain lock;
  - rev 18 (`f6038cb`) applied the owner's G8 ruling (C-29): the registry keeps its own series, number and issue date in new columns, with a backfill under the full §13;
  - rev 19 applies the owner's G9 ruling (C-31). G7 and G8 move whole to the lara-verifactu major, **AID-1929**, because they touch data. Their design stays at `f6038cb` (§4.6, §4.7, §7 cases (a)-(o)) as that ticket's seed. 1.7.0 is the switch only. `ensureSequentialOrder()` stays as today, and its block is documented, not changed. This text is rev 16's mechanism plus the G7 statement; the C-06a gap report is withdrawn from 1.7.0.
  - rev 20 records spec gate round 14 (bounded, PASS, no findings) in §13. Text only.
  - rev 21 applies the owner's ruling on implementation gate round 1, finding 1 (2026-11-12): the public `@internal` admitted-path methods are guarded, not declared a limit. Rule (b) now passes a `SendAdmission` by argument (§4.1). Implementation gate round 2, finding 1: validity set only by the constructor.
  - rev 22 applies implementation gate round 3, finding B1 (owner instruction 2026-11-12): the switch is read in the private constructor, so running it from a rebound private-scope closure is refused while disabled; the declared limit widens from reflection writes to any private-scope state write.

  Spec only; implementation not authorized.
- **Ticket:** AID-1907 (lara-verifactu). Coordination trace AID-1908. Dead-key follow-up: AID-1909 (see §9).
- **Base:** `origin/main` at `e420d5c` (v1.5.1). Implementation branches from `main` **after** AID-1886 (MR `!104`) merges and 1.6.0 is tagged (umbrella ruling C-02).
- **Rulings applied:**
  - umbrella C-02 (O1, O3, O4, O5, O6, placement);
  - owner ruling on classification, relayed by umbrella C-03 (§8: MINOR), now held under the owner's signed exception of 2026-11-08 after the notice was retired (C-22, C-25);
  - G2-G6 decided in C-04 (§10).

  - G7, G8 and G9 ruled by the owner (C-27, C-29, C-31): the order check and the registry columns leave 1.7.0 for the major AID-1929 (§4.5, §10).

  **Open:** none.

## 1. Owner decision

`verifactu.enabled` is a package-wide key, always declared, value `false` until the consumer sets it. Activation is the consumer's responsibility. There is no deprecation window in which an unset key keeps today's behaviour.

## 2. Current state (measured at `e420d5c` / `2010c83`)

- **The switch is missing.** `src/Console/VerifactuInstallCommand.php:45-46` reads `config('verifactu.enabled')` and advertises `VERIFACTU_ENABLED`. `config/verifactu.php` does not declare the key, and nothing else reads it. The installer therefore always prints "DISABLED", and that has no effect.
- **A second undeclared key.** `src/Console/VerifactuInstallCommand.php:49` reads `config('verifactu.environment')`; the real key is `aeat.environment`. The AID-1886 candidate keeps both reads (`git show 2010c83:src/Console/VerifactuInstallCommand.php`, lines 45-50).
- **No activation state today.** Fiscal effects happen whenever a consumer reaches one of the surfaces in §4.1. Submission is controlled per call by `submitToAeat` (default `true`), and the target is `aeat.environment` (default `production`).
- **The batch path hides errors.** `InvoiceRegistrar::batchRegister()` (`src/Services/InvoiceRegistrar.php:303-315`) catches `\Throwable` per invoice and only counts it in `failed`. A refusal raised inside `register()` would surface there as N silent failures.
- **Building the facade builds the AEAT client.** The provider binds `AeatClientContract` with a closure that resolves the endpoint at construction time (`src/LaraVerifactuServiceProvider.php:139-150`, `src/Services/ConfigEndpointResolver.php:19-27`). `Verifactu` takes `InvoiceRegistrar` in its constructor, and the registrar takes the client (`src/Verifactu.php:38-41`, `src/Services/InvoiceRegistrar.php:43-47`). The jobs and the commands `verifactu:register` and `verifactu:retry-failed` receive the registrar by method injection in `handle()` (`RegisterInvoiceCommand.php:36`, `RetryFailedCommand.php:34`). Laravel resolves those parameters before the method body runs. With an unusable AEAT configuration, resolving the facade, a job or one of those commands throws before any guard in their body can run (round 1, VERIFIED 2; round 2, VERIFIED 2).
- **The submission catch classifies everything as transport failure.** `InvoiceRegistrar::submitToAeat()` wraps the client call in `catch (\Throwable)`, which calls `markAsFailed()` (it increments attempts and writes `ERROR`/`EXHAUSTED`), fires `RegistryFailedEvent` and rethrows `AeatException` (`InvoiceRegistrar.php:196-215`, `RegistryManager.php:884-919`).
- **Fake mode has gaps.** `Verifactu::fake()` intercepts `register`, `cancel`, `sendBatch` and `status`, but not `amendRejected` (`src/Verifactu.php:74-79`).
- **Job `failed()` handlers only log.** `ProcessInvoiceRegistrationJob::failed()` (`:239-253`) and `SubmitRegistryToAeatJob::failed()` (`:250-258`) write log lines and no fiscal state.
- **Native queued registration enforces series order.** `ProcessInvoiceRegistrationJob::ensureSequentialOrder()` (`:192-208`) refuses an invoice when an earlier invoice of the same series and fiscal year has no registration (`Invoice::pendingRegistration()`). The facade path does not enforce this.

## 3. Configuration

- **Declaration** in `config/verifactu.php`, first key of the file, with a comment stating the owner decision:

  ```php
  'enabled' => env('VERIFACTU_ENABLED', false),
  ```

- **Read point:** one method only, `isEnabled()` on a container-bound `AichaDigital\LaraVerifactu\Contracts\ActivationContract`. The default implementation is `Support\Activation` (names provisional), registered as a singleton. It reads `config('verifactu.enabled', false)` on every call, so the default lives at the read point (CONSTITUTION §13.5). It depends on nothing but the config repository, so it never constructs the AEAT client or the registrar. No other code reads the key directly; an arch test enforces this (§7). The contract is a test seam: a scripted double can answer differently on successive calls, which is what makes the in-flight intervals of §7 deterministic (round 3, VERIFIED 1). It is not a supported extension point for consumers. The contract has `isEnabled()` only; it holds no per-send state (round 10).
- **Accepted values (G2, strict).** The type and value are checked **before** any boolean conversion; bare `filter_var()` is not enough, because it maps `null` and `''` to `false` (round 1, VERIFIED 3).

  | Raw config value | Result |
  |---|---|
  | `true` / `false` (PHP bool; `env()` returns these for `true`, `(true)`, `false`, `(false)`) | as is |
  | int `1` / `0` | `true` / `false` |
  | string, compared case-insensitively after `trim()`: `'1'`, `'true'`, `'on'`, `'yes'` / `'0'`, `'false'`, `'off'`, `'no'` | `true` / `false` |
  | anything else, **including `null`** (key present, `VERIFACTU_ENABLED=null` or `(null)`) and **`''`** (`VERIFACTU_ENABLED=` or `(empty)`) | `ConfigurationException::invalidActivation($raw)` |

  An invalid value is never read as "off" (a typo must not silently stop fiscal registration) and never as "on". `verifactu:status` and `verifactu:install` report it instead of throwing.
- **Effective value.** It is the **in-process** config value, read on every guarded call and never cached in a singleton, so `config()->set()` in tests takes effect immediately. It is not a live re-read of `.env`. With a cached configuration (`config:cache`), neither `.env` nor the package merge is consulted at boot, so a changed `.env` has no effect until the cache is rebuilt. Long-lived workers keep the configuration they booted with until they restart (round 1, VERIFIED 7). UPGRADE says both (§8).

## 4. Behaviour when disabled

### 4.1 Admission at the effect boundaries — `VerifactuDisabledException`

- **New exception:** `final class VerifactuDisabledException extends VerifactuException`, built with `self::make()` (the base constructor is final).
  - **Named constructors:** `forOperation(string $operation, ?\Throwable $previous = null)`, for every guard (entry, creation, client), and `submissionRefused(string $registryId, ?\Throwable $previous = null)`. Both forward `$previous` through `make()` (`src/Exceptions/VerifactuException.php:17-27`). The second is built **only** by `submitToAeat()` itself, in its pre-send phase (§4.2). The provenance values are set on defaulted properties after `make()` returns the child instance, because the base constructor is final (round 6, Method).
  - **Provenance:** the exception exposes `isSubmissionRefusal(): bool` and `registryId(): ?string`, so that a catch can tell "this send was refused before leaving" from "something else inside the call was refused" (round 5, VERIFIED 2). The flag marks the invocation's pre-send decision, not merely the registry (round 6, VERIFIED 1). **Origin is never inferred from an exception's class** (round 9, VERIFIED 1-2).

    Every `submitToAeat()` call follows three rules:
    - **(a)** Exactly **one** code path raises `submissionRefused()`: the call's own re-check, which runs **outside** the transport `try`. No catch converts anything into it.
    - **(b)** When the re-check passes, the send is admitted, and **the registrar's send path crosses no further activation guard** (round 10, VERIFIED 1-2; this replaces the single-use admission token of revision 12). The decision is taken once, at the re-check, for the whole send (§4.2). The path, all of it internal:
      - The re-check is `SendAdmission::grant()`: it reads the switch once and, when off, throws the refusal `submitToAeat()` hands it (`submissionRefused()`). When on, it returns a `SendAdmission` (rev 21).
      - `submitToAeat()` calls `LazyAeatClient::sendAdmitted($registry, $admission)` (`@internal`) when the bound client is the package's proxy, and `AeatClient::sendCore($registry, $admission)` when a consumer binds the package's concrete client directly. For any other `AeatClientContract` it calls the contract's `sendRegistration()`, as today; a consumer's own client has no guard.
      - `sendAdmitted()` builds the concrete client if needed and passes the admission through, **unspent**, to `sendCore()`. `sendCore()` spends it and sends; `sendBatchCore()` spends one admission for the whole batch.
      - The public `sendRegistration()`/`sendBatch()` of the proxy and of the concrete client are guarded by their own `grant()`, for direct users.

      **The admitted path requires an admission** (implementation gate round 1, finding 1, owner ruling 2026-11-12). The methods are public, so without it a direct caller reached AEAT while disabled. A valid `SendAdmission` exists only after the switch was read and answered on: the read happens in its private constructor, which `grant()` calls, so running the constructor by any route, a closure rebound into the class scope included, is refused while disabled (implementation gate round 3, finding B1); it is final, its constructor is private, and it refuses to be cloned, serialized or unserialized. Its validity is set only by the constructor, never by a property default, so an object PHP builds without running the constructor (the legacy `C:` unserialize format, `newInstanceWithoutConstructor()`) is born spent (implementation gate round 2, finding 1). It is spent once, so a second send with the same admission is a `LogicException` and reaches nothing.

      This is not the token of revision 12, and it avoids both of its defects. The admission is a **value passed by argument**, never ambient state: nothing on the container, the client or the registry holds it, so a re-entrant callback (`beforeResolving` included) has nothing to consume, and it cannot leak across jobs (round 10, V2). The proxy passes it through without spending it and crosses no guarded method, so the proxy → concrete chain spends it exactly once (round 10, V1). After the grant, the admitted path reads no activation (§4.2 unchanged). A nested send made from a listener or callback goes through a public, guarded method. If disabled, it is refused there and reaches the outer `try` as an ordinary exception, which gets today's handling under rule (c).
    - **(c)** Inside the transport `try` there is **no** disabled-specific catch. Any `VerifactuDisabledException` that escapes the client call comes from a nested package call. It may come from a listener on a log line inside the client's `SoapFault` handler (`AeatClient.php:98-99,243-247`), or from a nested send made through a public, guarded client method by a listener or callback. Either way it reaches today's generic `catch (\Throwable)` unflagged, and gets today's transport-failure handling. The outcome phases rewrap any `VerifactuDisabledException` as `forOperation('outcome')`, unflagged, with the original as `previous` (round 8, VERIFIED 1). That covers processing after the client returned (`InvoiceRegistrar.php:218-286`) and the body of the transport-failure catch (`:200-215`).

    A refusal raised anywhere other than rule (a) therefore never carries the flag: nested writes, nested sends of the same or another registry, listeners inside the client, or the outcome phases. The pre-send phase (`assertSubmissionPayloadIntact()`, the state refresh) performs no writes.
  - **Message:** names the key and `VERIFACTU_ENABLED`.
- **Public signatures unchanged (O3; round 5, VERIFIED 3-4).** These keep their declarations:
  - the return types `RegistryContract` and `array{success, failed, registries}`;
  - the `Verifactu` constructor;
  - every job's and command's `handle(InvoiceRegistrar $registrar)`.

  The only declaration that changes is `fake()`, which gains an optional parameter (§6). The non-final jobs and commands can therefore be subclassed exactly as today.
- **The AEAT client is built lazily only when disabled, behind the existing contract** (round 5, VERIFIED 3-4; round 6, VERIFIED 2; replaces the late-resolution and constructor-widening mechanism of revisions 5-7). The provider binds `AeatClientContract` to a new `@internal final class Support\LazyAeatClient implements AeatClientContract`. The binding's closure reads activation at the moment the binding is resolved:
  - **Enabled at resolution:** the closure does exactly what the current binding does (`LaraVerifactuServiceProvider.php:139-150`). It resolves the endpoint and certificate type, builds the concrete `AeatClient` and wraps it in the proxy, which only delegates and guards. An invalid environment or certificate type therefore still throws `ConfigurationException` at resolution, before any registration starts, exactly as today. The endpoint, timeout and `verify_ssl` are captured at the same moment as today. **The enabled branch has no timing or exception change.**
  - **Disabled at resolution:** the proxy holds the same factory unresolved. Building the registrar, the facade root, or a job's or command's injected registrar does not build the client, so an unusable AEAT configuration cannot fail before an activation guard. The jobs and commands keep their method injection, and the guard is still the first statement of `handle()`.
  - **Switched on later in the same process** (tests, or a long-lived process whose config changed, outside the supported case of §3): the proxy resolves at its first send. If that resolution throws `ConfigurationException`, the exception reaches `submitToAeat()`'s generic transport catch. It gets **today's** transport-failure handling, as any exception raised inside the client call does today: `markAsFailed()`, an attempt consumed, `RegistryFailedEvent`, `AeatException`. There is no class-based passthrough, because inside that `try` a class does not identify where an exception came from (round 9, VERIFIED 2). This window is a declared limit outside the supported case (§3), and it is the only case in which the endpoint is captured at the first send.
  - **Instance selection is unchanged.** The facade root still receives its registrar eagerly in its own construction context, so contextual bindings keep working as today.
  - **`TestAeatConnectionCommand` is unaffected.** It resolves the endpoint and builds its own transport (`TestAeatConnectionCommand.php:118-174`).
  - **Consumer bindings are untouched.** A consumer that rebinds `AeatClientContract` to its own client is unaffected; that client is a declared limit (below).
- **The authority is the effect boundary, not the entry list** (round 1, VERIFIED 1; method (a)). Two kinds of guard:
  - **Effect guards (authoritative).** These guard the effects themselves, so no caller can reach the effect while disabled, whatever its entry point:

    | Effect boundary | Effect it guards |
    |---|---|
    | `RegistryManager::createRegistry()` (`:63-136`) | lock, hash, chain link, insert, `RegistryCreatedEvent` (alta) |
    | `RegistryManager::createCancellationRegistry()` (`:148-208`) | the same for anulación |
    | `AeatClient::sendRegistration()` (`:67`) and `AeatClient::sendBatch()` (`:111`) | the network send to the AEAT |

    The client guard sits in the **public** `LazyAeatClient::sendRegistration()`/`sendBatch()`, before the concrete client is built, and in the **public** concrete `AeatClient` send methods. These guards are for direct users of those classes. The proxy delegates to the concrete client through `sendCore()`, never through a guarded public method, so one call crosses at most one client guard. The registrar's admitted path crosses none (rule (b)).

    **A batch is one admitted effect** (round 8, VERIFIED 2). `sendBatch()` checks activation once, at entry, and refuses the whole batch with `VerifactuDisabledException` before any item is sent. Its loop (`AeatClient.php:115-125`) calls the `@internal`, unguarded `sendCore()` instead of the public guarded `sendRegistration()`. An activation change during an admitted batch therefore neither stops it nor turns its remaining items into generic "Failed to send registry" responses. Every item gets its real transport answer, and the returned collection is complete. No package path calls the client's `sendBatch()` today; only `tests/Unit/AeatClientTest.php:92` does, so this is a contract for direct users.

    All client guards read `app(ActivationContract::class)`. A consumer that binds its own `AeatClientContract` is outside the client guard; such a consumer is still covered by the creation guards and the entry guards.

    **A disabled refusal is never a transport failure** (round 2, VERIFIED 1; round 9). `submitToAeat()` re-checks activation immediately before the `try` that wraps the client call, after the payload integrity check, and refuses with `VerifactuDisabledException::submissionRefused($registryId)`. That refusal is raised outside the `try`, so it never reaches `markAsFailed()`, never fires `RegistryFailedEvent` and is never re-wrapped as `AeatException`. The registry stays `pending` with its attempts untouched.

    When the re-check passes, the registrar's admitted path (rule (b) above) crosses no client guard, so no client guard can refuse an admitted registrar send. A client guard refuses only:
    - direct users of the client's public methods;
    - nested sends made through those public methods from listeners or callbacks. Such a refusal is a nested failure and gets today's handling.

    The transport `try` keeps exactly today's catches.
  - **Entry guards (early, clear refusal).** These refuse before any lock, transaction or row, and before the AID-725 transaction guard, so the caller gets the activation reason and not a misleading one:

    | Entry point | Behaviour when disabled |
    |---|---|
    | `InvoiceRegistrar::register()` / `cancel()` / `amendRejected()` | throws; no registry, no chaining, no event; activation checked before `assertCanSubmitFromHere()` (`:62`, `:111`, `:508`) |
    | `InvoiceRegistrar::batchRegister()` | throws **before the loop**; never reported as `failed: N` |
    | `InvoiceRegistrar::submitToAeat()` | throws; registry untouched, no attempt consumed |
    | `Verifactu::register/cancel/amendRejected/sendBatch` | delegate, so they throw (fake mode excepted, §6) |
    | `verifactu:register` | checks before any candidate lookup, prints the disabled message and exits `FAILURE`, including `--all` with an empty candidate set (`RegisterInvoiceCommand.php:123-139` returns `SUCCESS` before the registrar today) |

    In both `verifactu:register` and `verifactu:retry-failed`, the activation check is the first statement of `handle()`. When the switch is disabled at resolution, their injected registrar does not build the AEAT client, because the client is deferred. Nothing before that statement can then fail on AEAT configuration. When it is enabled at resolution, the client is built eagerly, as today, and an invalid AEAT configuration fails at resolution, as today (§4.1).

- **Outcome writers are NOT guarded, and their persistence is as today.** Not guarding them guarantees that the switch never blocks an answer. It does not guarantee that the answer persists. The outcome updates and the `amendRejected()` link ignore the boolean `update()` returns, so an `updating` listener that vetoes the save is silently ignored (`RegistryManager.php:751-1135`, `InvoiceRegistrar.php:558`; round 5, VERIFIED 1 and SUSPECTED 1). That defect predates this spec. It is tracked as **AID-1917** (the continuation of AID-1855) and is a declared limit here, not something this spec fixes. The outcome writers are these:
  - `markAsSubmitted`, `markAsAccepted`, `markAsAcceptedWithErrors`, `markAsFailed`, `markAsRejected`, `markSchedulingAbandoned`, `exhaustOverBudget` (`RegistryManager.php:730-1147`), and the registrar's `markSchedulingAbandoned()` delegate (`:457`).

  Refusing them would drop an acknowledgement. When the package is disabled they are reached only by direct calls, because every package path that calls them is behind an entry or effect guard. A direct consumer call to them while disabled is a declared limit, below.
- **Lifecycle methods are exempt** from the classification (round 2, VERIFIED 6): constructors, and the client's `__destruct()`, which deletes its own temporary certificate files (`AeatClient.php:51-58`). Here "read-only" means **no fiscal-state mutation**, not "no side effect at all".
- **Read-only and pure functions are NOT guarded.** These include hash reconstruction used by verification (`RegistryManager.php:559-568`), `getPreviousRegistry()`, `getPreviousHash()`, `hasUnfiledChainPredecessor()`, `verifyBlockchain()` and `assertSubmissionPayloadIntact()`.
- **Declared limits.** The switch does not, and is not claimed to, intercept the following:
  - direct Eloquent writes to `Registry` (status and attempts are fillable; the seal of `src/Models/Registry.php:158-206` has the same event-based limit);
  - query-builder or raw SQL writes;
  - direct consumer calls to the outcome writers above;
  - the deletion cascade of `Invoice::deleting()` (`src/Models/Invoice.php:571-594`), which belongs to the consumer's invoice lifecycle, not to registration, chaining or submission;
  - a consumer's own `AeatClientContract` implementation;
  - a vetoed outcome update or amendment link (AID-1917);
  - a `SendAdmission` obtained while enabled and spent later while disabled (one send), or one whose private state is rewritten by code with deliberate access to the class's private scope (reflection such as `ReflectionProperty::setValue()`, or a closure rebound with `Closure::bind()`/`call()`): both are deliberate circumvention of an `@internal` API, not a supported use. The admission is a guard against misuse inside one process, not isolation from code that can reach private members (implementation gate round 3, finding B1); a characterisation test pins the private-scope write so a change to this limit is deliberate.
- **Events:** none of the registry or submission events fire for a refused operation. The issuer keeps invoicing normally: the package never touches the consumer's invoice.

### 4.2 Admission and in-flight operations (round 1, SUSPECTED 2)

- **The decision is per effect, at the moment the effect starts.** An operation whose registry was created while enabled, and whose send then finds the package disabled at the re-check in `submitToAeat()`, ends like this. Once the re-check passes, the send is admitted as a whole (§4.1, rule (b)), and a switch change after that point does not stop it.
  - the registry stays persisted as `pending`, attempts untouched;
  - `VerifactuDisabledException` propagates to the caller of `register()`/`cancel()`/`amendRejected()`, never as `AeatException`;
  - neither `RegistryFailedEvent` nor `InvoiceRegisteredEvent` fires for that call (`InvoiceRegistrar.php:68-96,196-215`).
- **Recovery is the existing pending path.** After re-enabling, the consumer resends with `retry.resend_pending=true` and `verifactu:retry-failed`. This is the documented persisted-but-not-submitted result (UPGRADE, §8).
- **A send already on the wire is not interrupted.** The client guard runs before the SOAP call. The answer to an admitted send is always **passed** to the outcome writers, because they are not guarded. Whether it then persists is as today (AID-1917).
- **When this happens in practice.** The configuration is per process (§3), so the window exists across processes: a worker that booted enabled versus a request that booted disabled. A worker restart (UPGRADE) removes it.

### 4.3 Queued and scheduled work ends in a logged no-op (O4)

- **When disabled, activation is checked before anything that can fail on AEAT configuration.** `SubmitRegistryToAeatJob`, `ProcessInvoiceRegistrationJob` and `RetryFailedRegistriesJob` keep `handle(InvoiceRegistrar $registrar)`. When the switch is disabled at resolution, the injected registrar holds the deferred client of §4.1, so resolving it builds no AEAT client. When enabled at resolution, the client is built eagerly, exactly as today (round 1, VERIFIED 2; round 5, VERIFIED 4; rounds 12-13, VERIFIED 1).
  - **Where the check sits:** `app(ActivationContract::class)->isEnabled()` is the first statement of `handle()`. It runs before the lock acquisition at `ProcessInvoiceRegistrationJob.php:76`, `SubmitRegistryToAeatJob.php:89` and `RetryFailedRegistriesJob.php:68`.
  - **Serialized jobs:** a job queued under 1.6 and handled under 1.7 keeps working, because queued jobs serialize properties, not `handle()` dependencies (round 5, Part B).
- **When disabled, a job that enters `handle()`:**
  - does not send;
  - does not consume `max_submission_attempts` (does not touch `submission_attempts`);
  - does not call any outcome writer;
  - does not acquire the sequential or overlap lock;
  - does not `release()`, re-dispatch or use the AID-1671 hold;
  - does not call `fail()`;
  - returns normally.
- **The queue then deletes the job** (`CallQueuedHandler.php:95-101`). This is a **consumed** work item, not a paused one. The registry it pointed to stays as it was, and is recovered through the pending/retry path after re-enabling (§4.5). UPGRADE says so.
- **Framework-level failures before `handle()` are outside this guarantee** (round 1, VERIFIED 6). Example: the worker's max-attempts check on a job that the existing lock-contention `release(10)` already pushed back (`ProcessInvoiceRegistrationJob.php:82-91`, default `$tries` 1). That failure exists with or without the switch; it reaches `failed()`, which only logs (§2) and writes no fiscal state.
- **Named outcome:** one warning log line per job execution on the `verifactu.logging.channel`, message `verifactu.disabled_noop`, context `{job, registry_id|invoice_id, reason: 'disabled', outcome: 'skipped_disabled'}` (G5).
- **Registry state:** untouched. A `pending` registry stays `pending`; an `error` stays `error` with its attempt count.
- **`InvoiceRegistrar::retryFailed()`, disabled at start:** returns before selection with the existing array shape and every counter at `0`, plus an additive key `disabled => true`.
- **`retryFailed()`, disabled mid-pass** (round 3, VERIFIED 3). Today the loop wraps `submitToAeat()` in `catch (\Throwable)`, counts `failed++`, adds the rest to `skipped` and breaks (`InvoiceRegistrar.php:421-441`). A catch placed before it ends the pass, but **only** for an exception where `isSubmissionRefusal()` is true **and** `registryId()` equals the candidate's id (round 5, VERIFIED 2).

Any other `VerifactuDisabledException` keeps today's handling (`failed++`, rest `skipped`, break). That includes one raised by a nested package call from a listener during outcome persistence, which means the send was admitted and its outcome did not persist. The result of a submission refusal:
  - the counters already completed are kept;
  - the refused candidate and every later one are added to `skipped`, **not** `failed`;
  - `disabled => true` is set;
  - no outcome writer runs.

  Where the existing exhaustion sweep runs relative to the loop is unchanged by this spec.
- **`verifactu:retry-failed`:** checks activation **first**, before the overlap-lock machinery (`RetryFailedCommand.php:47-55`, `OverlapLockStore.php:81-91`). It prints a warning ("VeriFACTU is disabled (verifactu.enabled=false): nothing sent, registries kept") and exits `SUCCESS` (G4) — also when the lock is busy or the cache store is refused.

  When `retryFailed()` returns `disabled => true` after a mid-pass stop, the command:
  - prints the same warning plus the completed counters;
  - keeps "Total resent" as `success + failed` (`RetryFailedCommand.php:99-107`), which is now correct because a refused candidate is counted in `skipped`, never in `failed`;
  - exits `SUCCESS`, unless an attempted send failed before the stop, in which case it exits `FAILURE` as today.

### 4.4 Read-only operations stay available (O5)

These keep working when disabled, because existing registries are kept and the retention duty does not depend on the switch:

- `Verifactu::status()`, `qr()`, `validateChain()` and `enabled()`;
- `InvoiceRegistrar::verifyBlockchain()` and `hasUnfiledChainPredecessor()`;
- the commands `verifactu:verify-blockchain`, `verifactu:status` and `verifactu:test-connection`;
- `VerifyBlockchainIntegrityJob`, which is explicitly **not** subject to the job guard of §4.3.

### 4.5 No backfill when the switch turns on

- **Only later calls are affected.** Turning the switch on changes nothing retroactively:
  - invoices issued while it was off never get a registry automatically;
  - pending registries and consumed jobs are **not** auto-resent;
  - the consumer's lever is `retry.resend_pending=true` together with `verifactu:retry-failed`;
  - whether earlier invoices must be registered is the consumer's legal call.
- **Series order after re-enabling — G7, moved to the major AID-1929 (owner, C-31).** In 1.x the order guard stays exactly as today. In native mode, an invoice issued while disabled stays `pendingRegistration()`. After re-enabling, `ProcessInvoiceRegistrationJob::ensureSequentialOrder()` refuses every later invoice of the same series and fiscal year with a `RuntimeException`, when that invoice's number carries an extractable numeric sequence (`:178-208`). The queued native path stays blocked for that series until the gap is registered. The facade path is not affected. The same block bites a consumer that adopts Verifactu mid-year with earlier unregistered invoices in the series.
  - **1.7.0 documents it and does not change it.** UPGRADE states the block and the consumer's options: register the earlier invoices explicitly (consumer-initiated, never automatic), open a new series, or use the facade path.
  - **The C-06a gap report is withdrawn from 1.7.0.** 1.7.0 is the switch only, and the report was proposed for a ruling that did not happen.
  - **The fix is the major.** The order check under the chain lock and the registry custody columns are designed at `f6038cb` (§4.6, §4.7) and carried by AID-1929. Its calendar condition: it must be published before Castris or ArabeConAicha activate Verifactu.

## 5. Public query (O3)

- **New method:** `Verifactu::enabled(): bool`, with the facade `@method` docblock. It returns `app(ActivationContract::class)->isEnabled()` and throws `ConfigurationException` on an invalid value. When the switch is disabled at the moment the facade root is resolved, `enabled()` answers without building the AEAT client. When it is enabled, the client is built exactly as today (round 8, VERIFIED 3).
- **Facade root unchanged.** The facade resolves its root before calling any method (`Facade.php:232-243,356-364`). The root still receives `InvoiceRegistrar` eagerly, with its constructor and its `'verifactu'` singleton binding unchanged (`LaraVerifactuServiceProvider.php:158-160`). When disabled at resolution, building it does not build the AEAT client, because the client is the deferred proxy of §4.1. When enabled, it builds it as today, and an invalid AEAT configuration fails as today (round 2, VERIFIED 3; round 5, VERIFIED 3).
  - **Read methods** (`status`, `qr`, `validateChain`) work while disabled and with an unusable AEAT configuration. `validateChain` → `verifyBlockchain()` never sends (`src/Verifactu.php:109-148`).
  - **Fake mode** never touches the registrar.
- **Consumers.** Larabill included (AID-1889 boundary), they call `enabled()` instead of reading `config('verifactu.enabled')`.
- **Contract wording for the docs:** "ask the package whether it will register". The answer reflects the switch only. It does not promise that the certificate or the environment is configured.

## 6. Fake mode (O5)

- **Default:** `Verifactu::fake()` ignores the switch. `register`, `cancel`, `amendRejected` and `sendBatch` record as today, and `enabled()` returns `true`, so a consumer that gates on `enabled()` keeps reaching the fake. Existing consumer tests keep passing with the new `false` default. The trade-off is that a default fake never exercises a consumer's "package disabled" branch.
- **Opt-in:** `Verifactu::fake(honourSwitch: true)` reports the config value from `enabled()`, and makes the write methods throw `VerifactuDisabledException` when it is off. That includes `sendBatch([])` with an empty input, which checks activation before iterating. This changes the declaration `fake(): void` (`src/Verifactu.php:154-159`, facade `@method` at `src/Facades/Verifactu.php:16`) by adding an optional parameter; existing calls stay compatible.
- **`amendRejected` fake interception (G3):** recorded through the existing `fakeRegistered` map and `fakeRegistry()` builder, as a registration of the corrected invoice (`src/Verifactu.php:46-51,164-172,213-225`, confirmed in round 1, Part A 5).

## 7. Tests required in the implementation MR

Every test names the observable that kills its mutation. The network double replaces `AeatClientContract` in the container (precedent `tests/Feature/SubmitRegistryOrderingTest.php:61-68`); the client is SOAP, not Laravel HTTP.

- **Declaration and wiring** (round 1, VERIFIED 4):
  - an arch-style test reads the shipped `config/verifactu.php` and asserts that a top-level `enabled` key exists and resolves through `env('VERIFACTU_ENABLED', false)`;
  - bootstrapping against a **legacy consumer config** (published before the key existed, without `enabled`) through the real provider merge — not by replacing the repository array after boot — gives `false` with no env and `true` with `VERIFACTU_ENABLED=true`.

  Killed mutations: deleting the declaration; replacing it with `'enabled' => false`.
- **Parsing:** every accepted spelling of §3; `null`, `''`, `'ture'`, `2` and an array each throw `ConfigurationException` on a write and on `enabled()`, and are reported, not thrown, by `status` and `install`. Killed mutation: implementing the parser as bare `filter_var(..., FILTER_NULL_ON_FAILURE)`.
- **No AEAT dependency while disabled** (round 1 VERIFIED 2, round 2 VERIFIED 2-3). Setup: a real container with an unusable AEAT configuration (`aeat.environment='invalid-probe'`) and no client double. Expected:
  - `Verifactu::enabled()` returns `false`;
  - each facade write throws `VerifactuDisabledException` (not `ConfigurationException`);
  - each of the three jobs completes its disabled no-op;
  - `verifactu:register` exits `FAILURE` with the disabled message;
  - `verifactu:retry-failed` exits `SUCCESS` with its warning.

  Killed mutations: binding `AeatClientContract` back to the eager factory; moving the activation check after anything in `handle()` that builds the concrete client.
- **Effect guards, two separate cases** (round 2, VERIFIED 4). Common assertions: **zero additional** registry rows (counted against a seeded baseline), no `RegistryCreatedEvent`, and the client double never called. Transactions are observed with a `TransactionBeginning` listener, not with a post-call `DB::transactionLevel()`.
  - **(a) Entry guard kept:** `register()` throws and **no** transaction begins.
  - **(b) Entry guard removed, effect guard kept:** `register()` still throws. The registrar's outer transaction (`InvoiceRegistrar.php:68`) is expected. The manager must refuse **before** its own transaction or lock, so exactly one `TransactionBeginning` at level 1 is allowed. Killed mutation: moving the manager guard inside its transaction callback.

  Direct calls to `RegistryManager::createRegistry()`, `createCancellationRegistry()` and `AeatClient::sendRegistration()`/`sendBatch()` throw while disabled.
- **Entry guards and order:** `register`, `cancel`, `amendRejected`, `batchRegister` (throws, not `failed: N`), `submitToAeat` and `verifactu:register`, including `--all` with no candidates, which exits `FAILURE`. In a caller-opened transaction one level above the harness baseline (`tests/TestCase.php:65-76`), disabled throws `VerifactuDisabledException`, not the AID-725 refusal.
- **Jobs when disabled** (VERIFIED 5): each of the three jobs runs with `withFakeQueueInteractions()` and asserts:
  - `assertNotReleased()`, not failed and not deleted-with-failure;
  - no lock acquired (an `OverlapLockStore`/cache spy);
  - no concrete `AeatClient` built (a construction counter on the lazy proxy's factory);
  - a complete raw-row snapshot of every registry unchanged;
  - exactly one `skipped_disabled` warning.

  Separately, one test pushes a job through a real worker (`queue:work --once` on the **database** driver; the `sync` driver executes on `push()` and its `pop()` returns nothing, `SyncQueue.php:195-240,365-368`). It asserts that the worker consumed that job (its row is gone from `jobs`, the log line carries its id) and that the job is not in `failed_jobs`. Killed mutations: `$this->release(10)` before the disabled return; moving the check after acquiring and freeing a free lock.
- **In-flight admission, three intervals** (round 1 SUSPECTED 2, round 2 VERIFIED 1):
  1. The switch turns off between the registry commit and `submitToAeat()`, so the re-check refuses.
  2. The switch turns off after the re-check but before the client guard. The existing pre-send debug log (`InvoiceRegistrar.php:162-165`) is **not** a usable hook, because it runs before the re-check (round 3, VERIFIED 1). Instead, a scripted `ActivationContract` double answers `true` to the re-check and `false` to every later `isEnabled()`. The real `AeatClient` is used with its SOAP transport doubled below the guard.
  3. The switch turns off inside the transport double, after the send left.

  For 1, assert:
  - the `pending` row persisted, with attempts unchanged;
  - `VerifactuDisabledException::submissionRefused()` raised to the caller, not `AeatException`;
  - no `RegistryFailedEvent` and no `InvoiceRegisteredEvent`;
  - recovery through `resend_pending` after re-enabling.

  For 2, assert that the send **happens**: the transport double is called once and the answer reaches the outcome writers. Run interval 2 twice, once with the client resolved **enabled** (eager concrete inside the proxy) and once **disabled-then-enabled** (deferred). For 3, assert that the answer reaches the outcome writers.

  Killed mutations:
  - `submitToAeat()` calling the proxy's public guarded `sendRegistration()` instead of `sendAdmitted()`, so interval 2 refuses;
  - the proxy delegating to the concrete client's public guarded method instead of `sendCore()`, so interval 2 refuses in the eager variant.
- **No activation read after the re-check** (round 10, VERIFIED 2; round 11, VERIFIED 1). A `beforeResolving(ActivationContract::class, …)` counter is registered **after** the re-check has resolved activation; a scripted activation that answers `true` once triggers it. During the admitted send, assert:
  - the counter stays at **0**;
  - exactly one SOAP call is made.

  Run it in both the eager and the deferred binding variants. Killed mutation: inserting `app(ActivationContract::class)`, or any guard, anywhere in `sendAdmitted()` or `sendCore()`.
- **Admitted path is guarded** (rev 21): every admitted entry point (`sendCore()`, `sendBatchCore()`, `sendAdmitted()`) requires a non-null `SendAdmission`; `grant()` refuses while disabled and nothing reaches the transport; an admission spent once cannot be replayed (one transport call); one admission covers a whole batch; the class cannot be built, cloned, serialized or unserialized, and an admission built without the constructor (`C:` format, `O:` with a payload, `newInstanceWithoutConstructor()`) sends nothing; the constructor run from a rebound private-scope closure while disabled is refused and sends nothing; a characterisation test pins that a private-scope write of the validity flag still sends (the declared limit); `SendAdmission::grant(` appears only at the five guard sites. Killed mutations: removing `spend()` from `sendCore()` or from `sendBatchCore()`; the batch loop spending per item; validity as a property default instead of set by the constructor; the switch read moved from the constructor back into `grant()`.

  The case of a nested send refused through a public guarded method is covered separately and independently by the "Origin is not inferred from class" test (a listener on the `SoapFault` log). It is not coupled to this one.
- **Origin is not inferred from class** (round 9, VERIFIED 1-2). Activation stays **on** throughout. A `MessageLogged` listener, fired by the client's `SoapFault` log (`AeatClient.php:243-247`), throws:
  - **(i)** a `VerifactuDisabledException`;
  - **(ii)** a `ConfigurationException`.

  In both cases, assert today's transport-failure handling: `markAsFailed()`, an attempt consumed, `RegistryFailedEvent`, `AeatException` to the caller. In retries, assert the candidate is counted as `failed`, not `skipped`. Killed mutation: any class-based catch reinstated in the transport `try`.
- **Commands:** `verifactu:retry-failed` when disabled exits `SUCCESS` with the warning, with a free, a busy and a refused lock store. `verifactu:status` and `verifactu:install --no-migrate` print the warning. The installer reads `aeat.environment`, not `verifactu.environment`.
- **Upgrade path, on a real engine (MariaDB 11.4 and MySQL 8.4):** seed a chain with registries in `accepted`, `pending` and `error`, then leave the switch unset. Assert:
  - every write and effect surface throws, with zero additional rows;
  - complete raw-row snapshots are unchanged;
  - `retry-failed` sends nothing and says so;
  - `status`, `qr`, `validateChain` and `verify-blockchain` work.

  Then turn the switch on and assert the chain continues from the last hash. The case also includes an off-period native invoice gap and pins the unchanged 1.x block.
- **Fake:** the default fake ignores the switch and `enabled()` is `true`. `fake(honourSwitch: true)` throws when off, including `sendBatch([])`. `amendRejected` is intercepted.
- **Series order unchanged (G7 moved to AID-1929):** a regression pin. With an invoice issued while disabled, the next native queued invoice of the series is refused by `ensureSequentialOrder()` exactly as at `e420d5c`, and the facade path registers it. Kills an accidental change to the 1.x guard in this MR.
- **Retry mid-pass refusal:** two candidates, the first sent successfully and the scripted activation turning off before the second. Assert:
  - `success = 1`, `failed = 0`, `skipped = 1`, `disabled = true`;
  - no outcome write for the second candidate;
  - the command exits `SUCCESS` and reports "Total resent" as 1.

  Killed mutation: removing the disabled catch from the loop, which returns `failed = 1`.
- **Retry provenance** (round 5, VERIFIED 2; round 6, VERIFIED 1; round 8, VERIFIED 1). Four cases, each driven by a scripted activation that turns off after the outer send was admitted:
  - **(a)** an `updating` observer on `Registry` calls an ordinary guarded package write;
  - **(b)** the observer calls `submitToAeat()` for a **different** registry;
  - **(c)** the observer calls the client's or the registrar's send for the **same** registry;
  - **(d)** the transport throws after admission, and an `updating` observer fired by `markAsFailed()`, inside the transport-failure catch, calls `submitToAeat()` for the same registry.

  In all four cases, assert:
  - `retryFailed()` reports the candidate as `failed`, not `skipped`;
  - the result does not carry `disabled => true`;
  - the escaping exception is unflagged (`forOperation('outcome')` with the nested exception as `previous`);
  - the command exits `FAILURE`.

  A fifth case, the ordinary mid-pass refusal, still yields `skipped` and `disabled => true`. Killed mutations:
  - matching the catch on the class alone, which kills (a);
  - matching on `isSubmissionRefusal()` alone, or on the flag plus the registry id, without the post-response unflagging, which kills (b) and (c);
  - unflagging only the post-response phase and not the transport-failure catch body, which kills (d).
- **Client batch admission** (round 8, VERIFIED 2): with three registries, the scripted activation turns off after the first item is sent. Assert three real transport answers, no generic "Failed to send registry" response and no exception. The client's `sendBatch()` with the switch off at entry throws `VerifactuDisabledException` and sends nothing. Killed mutation: the loop calling the public guarded `sendRegistration()`.
- **Enabled-branch equivalence** (round 5, VERIFIED 3-4; round 6, VERIFIED 2):
  - a contextual binding `when(Verifactu::class)->needs(InvoiceRegistrar::class)` still selects the bound registrar;
  - a subclass of each job and command that overrides `handle(InvoiceRegistrar $registrar)` still loads, and the five `handle()` signatures are pinned by reflection;
  - **enabled with an invalid `aeat.environment`**: resolving the registrar throws `ConfigurationException` exactly as today, and no registry row is created. Killed mutation: deferring endpoint resolution when enabled;
  - **disabled at resolution, enabled later, invalid environment** (a declared limit, outside the supported case): the first send's deferred factory throws `ConfigurationException`, which gets today's transport-failure handling (§4.1). The test pins that declared outcome, so any change to it is deliberate.
- **Arch test, deny by default** (round 1 method (a), round 2 VERIFIED 6):
  - every public method of `Verifactu`, `InvoiceRegistrar`, `RegistryManager` and `AeatClient`, and every job and command class, appears in a classification table in the test, as one of: `effect-guarded`, `entry-guarded`, `outcome-writer`, `read-only` (no fiscal-state mutation) or `admitted-path` (`LazyAeatClient::sendAdmitted()`, `AeatClient::sendCore()`/`sendBatchCore()`, marked `@internal` and requiring a `SendAdmission`; their only callers are `submitToAeat()`, the proxy and the batch loop, which the test asserts by scanning `src/`);
  - lifecycle methods (constructors, `__destruct`) are exempt by name;
  - a new public method or class with no row fails the test. `VerifyBlockchainIntegrityJob` is classified `read-only`. The literal `'verifactu.enabled'` appears in `src/` only inside the read point.
- **Sensitivity, executed:** for each guard, the MR records the mutation (guard removed), the test that went red and the assertion that killed it. This is run on both engines.

## 8. Version classification — MINOR (owner ruling, 2026-11-08, relayed by umbrella C-03)

- **Ruling:** delivered as a **minor** (the next one after 1.6.0, expected 1.7.0). The consumer-data notice this rested on was retired on 2026-11-08; the minor is now lawful only under the owner's signed exception of the same day (central record `governance/changes/2026-11-08-excepcion-firmada-lara-verifactu-aid-1907.md`, umbrella C-25). That exception lifts the major classification §8 and §9 would impose, for this change only. It must be reviewed no later than **2026-11-23** (AID-1926); if it is not reviewed in time and the change is not yet published, it lapses and the change is classified major again. Measured impact:
  - Castris gates issuance with its own `CASTRIS_VERIFACTU_ENABLED` (default `false`, `config/larafactu.php:113`) and has no production deployment;
  - ArabeConAicha does not integrate Larabill yet;
  - there is no other consumer.
- **Why it would otherwise be major (recorded, not reopened):** a call that succeeds today for a consumer that set no key starts to refuse, which narrows a public flow (CONSTITUTION §9). The API surface itself is additive (new key, method and exception, optional `fake()` parameter).
- **Conditions attached by the umbrella, all required in the implementation MR:**
  - **CHANGELOG**, behaviour change in bold: **a consumer that never set `verifactu.enabled` now gets `VerifactuDisabledException` from `register()`, `cancel()`, `amendRejected()` and `sendBatch()`**; set `VERIFACTU_ENABLED=true` to keep today's behaviour.
  - **`UPGRADE-1.7.md` in the dist**, at the repository root. Note that `README.md`/`CHANGELOG.md` are export-ignored (`.gitattributes:18-19`), so UPGRADE is the only in-dist notice. It contains:
    1. set `VERIFACTU_ENABLED=true` in the same upgrade to keep today's behaviour;
    2. rebuild the cached configuration (`php artisan config:cache`, or `config:clear` if it is not cached) and restart every long-lived worker. `php artisan queue:restart` only signals the workers to exit; a process monitor (Supervisor or equivalent) must start the new process, so check that the workers really were replaced;
    3. verify the effective state with `php artisan verifactu:status`;
    4. what each surface and job does while disabled (§4), including that disabled jobs are consumed, not paused;
    5. pending registries are not resent automatically — `retry.resend_pending` is the lever;
    6. no backfill for invoices issued while disabled;
    7. the series-order block of §4.5: after re-enabling, or on a mid-year adoption, the native queued path refuses later invoices of a series with an earlier unregistered one, and the consumer's three options. The definitive fix is the major AID-1929;
    8. the in-flight persisted-but-not-submitted result of §4.2.
  - **Visible warning** from `verifactu:status` and `verifactu:install` when disabled (§7).
  - **Exception cited in both notices:** the CHANGELOG entry and `UPGRADE-1.7.md` state in bold that without the key the package refuses to send, how to enable it (`VERIFACTU_ENABLED=true`), and that the owner's signed exception of 2026-11-08 covers the minor classification (condition 4 of that record).
  - **Governance record** (central, written by the umbrella): the signed exception above. Without it this default is a major (§9). Not a package-side deliverable.

## 9. Dead configuration keys (O6)

- **In this spec:** `verifactu.enabled` (declared, §3) and `verifactu.environment` in the installer, replaced by `aeat.environment`.
- **Linked ticket, AID-746 precedent:** the other five keys go to a separate ticket, AID-1909 (Backlog, no due date).
  - `queue.enabled` and `aeat_client.environment` are read by `StatusCommand` without being declared.
  - `retry.enabled`, `batch.enabled` and `cache.enabled` are declared and never read.

## 10. Decisions

The gate may still challenge any of these; a challenge is measured against the tree like any other finding.

- **G1 — classification:** closed, MINOR (§8).
- **G2 — invalid value:** an unparseable `verifactu.enabled` throws `ConfigurationException` at the single read point and is never read as `false`. Rev 4 makes the accepted set explicit and strict (§3).
- **G3 — `amendRejected` in fake mode:** in scope; it reuses the existing mechanism (§6). If the implementation needs any machinery beyond that, it moves to a linked ticket instead.
- **G4 — `verifactu:retry-failed` when disabled:** exits `SUCCESS` with the visible warning, checked before the lock machinery (§4.3).
- **G5 — job no-op log:** `warning` level, exactly once per job execution, with the context of §4.3. Batch paths return or throw before iterating.
- **G6 — coexistence with `CASTRIS_VERIFACTU_ENABLED`:** recommendation only, carried by the Clientes mirror ticket. The package proposes retiring the app switch in favour of `Verifactu::enabled()`.
- **G7 — closed for 1.x (owner, C-27 then C-31):** the 1.x guard stays as today, and the block is documented (§4.5). The ruled design (option B under the chain lock, §4.6 at `f6038cb`) moves to the major AID-1929.
- **G8 — closed for 1.x (owner, C-29 then C-31):** the registry custody columns and their backfill (§4.7 at `f6038cb`) move to AID-1929. 1.7.0 adds no table and no column, which keeps the signed exception's "Tablas: ninguna" true.
- **G9 — closed (owner, C-31):** the signed exception is not stretched to the order check: "Aceptaba la excepción por un periodo de tiempo ya fijado con el fin de desatascar, pero está claro que hay que afrontarlo en una major ... Y ya esa major respetar lo pactado sobre migraciones contenidas con sus datos". On G7 and G8 moving whole to the major: "sí, toca datos".

## 11. Brief for the adversarial reviewer

- **Inputs:** this spec; the code at `e420d5c` plus the AID-1886 candidate `2010c83`; `CONSTITUTION.md` §8, §9 and §13; `products/lara-verifactu.md`; Linear AID-1907; umbrella rulings C-01a, C-02, C-03, C-04, C-22, C-25 and C-31.
- **Settled premises (do not reopen):**
  - `clientes` does not submit to the AEAT in production: its only deployment is staging, and it gates issuance with its own `CASTRIS_VERIFACTU_ENABLED`, default `false` (C-01a);
  - the consumer-data notice was retired on 2026-11-08; the classification is MINOR under the owner's signed exception of that day (`2026-11-08-excepcion-firmada-lara-verifactu-aid-1907`, umbrella C-25), review due 2026-11-23.
- **Look for:**
  - a fiscal effect reachable while disabled outside the declared limits;
  - any path where "disabled" looks like success;
  - state changes (attempts, status, holds, locks) while disabled;
  - guard order against AID-725;
  - tests that would stay green with a guard removed;
  - anything in UPGRADE or CHANGELOG that leaves a consumer silently disabled.

## 12. Out of scope

- AID-1886 (schema ownership, MR `!104`).
- AID-1889 (orchestration boundary; Larabill decides how it uses `enabled()`).
- Any change to `submitToAeat` defaults or to `aeat.environment`.
- The five dead keys of §9.

## 13. Adjudication

### Round 1

- **Session:** `01a1bae2-4390-73c1-8ece-05031734b82f` (Codex, read-only), 2026-11-08 12:19-12:26.
- **Scope:** full spec at `a51a3f5` (rev 3) over code `e420d5c`; unbounded first round.
- **Verdict:** `GATE: FAIL` — 8 verified, 3 suspected.
- **Method rulings:** (a) cosmetic as a completeness argument; (b)-(e) real.
- **Probes:** the reviewer ran inline `php -r` probes only and left no probe files; its measurements are quoted in `round-1/out.md` of the durable gate directory.
- **Baseline suite at `a51a3f5`:** MariaDB 11.4, 757 passed, 9 skipped.

| # | Finding | Decision | Landed in |
|---|---|---|---|
| M(a) | Entry-list census cannot prove a package-wide boundary | accepted — authority moved to effect boundaries, deny-by-default classification | §4.1, §7 arch test |
| A6 | `fake()` declaration does change | accepted (wording) | §4.1, §6 |
| V1 P1 | Manager creation methods and client sends unguarded; outcome writers and model writes unclassified | accepted in part — creation and send guarded; outcome writers deliberately unguarded (they persist answers to admitted sends); model/raw writes, invoice deletion cascade and custom clients declared as limits | §4.1 |
| V2 P1 | Facade and job resolution build the AEAT client before any guard | accepted — measured in `src/LaraVerifactuServiceProvider.php:139-150` | §2, §4.3, §5, §7 |
| V3 P1 | `filter_var` maps `null`/`''` to false | accepted — strict pre-conversion table | §3, §7 |
| V4 P1 | Default pin cannot detect a missing declaration | accepted — declaration test plus a real-merge legacy bootstrap | §7 |
| V5 P1 | `assertNothingPushed` misses `release()` | accepted — `assertNotReleased`, lock spy, worker run | §7 |
| V6 P2 | Pre-`handle()` failure cannot be excluded | accepted (limit) — verified that `failed()` only logs (`ProcessInvoiceRegistrationJob.php:239-253`, `SubmitRegistryToAeatJob.php:250-258`) | §2, §4.3 |
| V7 P2 | Cached config and long-lived workers | accepted | §3, §8 UPGRADE 2-3 |
| V8 P2 | Command checks must precede lock and empty-candidate branches | accepted | §4.1, §4.3, §7 |
| S1 P1 | Off-period gap blocks the native queued path after re-enabling | confirmed by reading `ProcessInvoiceRegistrationJob.php:192-208`; decision escalated as G7 | §4.5, §10 |
| S2 P2 | In-flight switching undefined | accepted — per-effect admission, persisted-pending result, answers always persisted | §4.2, §7 |
| S3 P2 | Test plan lacks executable sensitivity | accepted — observables and killed mutations per test | §7 |

### Round 2

- **Session:** `01a1baec-8b4d-70d0-851e-030a5738ca60` (Codex, read-only), 2026-11-08 12:30-12:37.
- **Scope:** bounded to `a51a3f5..d0abf7f` (rev 4).
- **Verdict:** `GATE: FAIL` — 6 verified, 0 suspected.
- **Method:** rev 4 changed the method. Rulings: (i), (ii), (iv) and (v) real; (iii) real but incomplete.
- **Probes:** inline only, quoted in `round-2/out.md`.

| # | Finding | Decision | Landed in |
|---|---|---|---|
| V1 P1 | Client-guard refusal enters `catch (\Throwable)` → `markAsFailed`, `RegistryFailedEvent`, `AeatException` | accepted — measured `InvoiceRegistrar.php:196-215`; re-check before the `try` and a disabled passthrough before the generic catch | §2, §4.1, §4.2, §7 in-flight interval 2 |
| V2 P1 | Command `handle()` injection builds the client before a first-statement guard | accepted — measured `RegisterInvoiceCommand.php:36`, `RetryFailedCommand.php:34`; same deferred resolution as the jobs | §2, §4.1, §7 |
| V3 P2 | Lazy facade wiring unspecified; "only `fake()` changes" inaccurate | accepted — constructor widening `InvoiceRegistrar\|\Closure`, explicit provider factory, refusal before resolution; `validateChain` declared as a limit | §4.1, §5 |
| V4 P2 | Effect-guard test mixes incompatible transaction expectations | accepted — measured the outer transaction at `InvoiceRegistrar.php:68`; split into (a) and (b) with a `TransactionBeginning` listener | §7 |
| V5 P3 | `sync` is not a real worker | accepted — database driver only, consumption evidence | §7 |
| V6 P3 | Lifecycle methods unclassified; "read-only" imprecise | accepted — lifecycle exempt by name; read-only = no fiscal-state mutation | §4.1, §7 |
| C-06a | Umbrella proposal for G7: keep the block, document it, report gaps in `verifactu:status` | recorded as a proposal; G7 stays open pending the owner | §4.5, §7, §8, §10 |

### Round 3

- **Session:** `01a1baf5-b2a7-7c20-9b58-4f42bc9c055e` (Codex, read-only), 2026-11-08 12:40-12:44.
- **Scope:** bounded to `d0abf7f..ef87151` (rev 5).
- **Verdict:** `GATE: FAIL` — 3 verified (2 P1, 1 P2), 0 suspected.
- **Method:** rev 5 completes the method rather than replacing it; rulings (vi) to (ix) are all real.
- **Part A:** V2-V6 land; V1 partially; C-06a lands as a proposal.

| # | Finding | Decision | Landed in |
|---|---|---|---|
| V1 P1 | The interval-2 hook (pre-send log) runs before the re-check, so the passthrough mutation survives | accepted — measured: the log is at `InvoiceRegistrar.php:162-165`, before the `try` at `:196`; activation becomes a container-bound contract, and a scripted double drives interval 2 | §3, §7 |
| V2 P1 | The gap report, bounded by "newest registered invoice", misses the case 1-registered / 2-pending / 3-new, and misses a new series | accepted — the report now uses the order guard's own predicate, runs whether the switch is on or off, and is native-only (custom mode declared not applicable) | §4.5, §7 |
| V3 P2 | A mid-pass refusal in `retryFailed()` is swallowed as `failed` and counted as resent | accepted — measured `InvoiceRegistrar.php:421-441` and `RetryFailedCommand.php:99-107`; a disabled catch ends the pass, the refused candidate goes to `skipped`, `disabled => true` is set, and the command exits `SUCCESS` | §4.3, §7 |

### Round 4

- **Session:** `01a1bafb-eef2-7330-9f3d-c68dda26656e` (Codex, read-only), 2026-11-08 12:47-12:50.
- **Scope:** bounded to `ef87151..326a8ad` (rev 6).
- **Verdict:** `GATE: PASS`, with 1 verified finding (P3) and 0 suspected. This is a PASS of that diff, not of the spec.
- **Method:** rulings (x)-(xii) are all real.
- **Part A:** all three round-3 corrections land.

| # | Finding | Decision | Landed in |
|---|---|---|---|
| V1 P3 | §4.3 and §5 still used the static notation `Activation::isEnabled()` | accepted. Both now read `app(ActivationContract::class)->isEnabled()`, and §4.1 states how the concrete client reaches the contract without changing its constructor or factory | §4.1, §4.3, §5 |

### Round 5 (unbounded, path after the decision)

- **Session:** `01a1baff-9ae4-7422-b521-96d96e7053d1` (Codex, read-only), 2026-11-08 12:51-12:59.
- **Scope:** full spec at `881dfdc` (rev 7). Part B was not bounded.
- **Verdict:** `GATE: FAIL` — 4 verified (2 P1, 2 P2), 1 suspected (P1).
- **Part A:** the rev-7 P3 lands.
- **Part B checks the reviewer reported as clean:**
  - ordinary serialized base jobs survive the `handle()` change;
  - the passthrough catch does not change the enabled branch.

| # | Finding | Decision | Landed in |
|---|---|---|---|
| V1 P1 | Unguarded outcome writers do not guarantee persistence: `update()` result ignored, a vetoed save goes unnoticed | accepted as a **pre-existing** defect, not a switch regression. Measured: AID-1855 (`CheckedWrite`) covers only the creation saves (`RegistryManager.php:123-129,199-202`); the updates at `:751-1135` and `InvoiceRegistrar.php:558` do not. Filed **AID-1917**; the spec now promises "passed to", not "persisted" | §4.1, §4.2 |
| V2 P1 | The retry disabled catch can report an admitted send whose outcome failed as `skipped` | accepted — the catch now requires provenance (`isSubmissionRefusal()` and the matching `registryId()`); any other disabled exception keeps today's `failed` handling | §4.1, §4.3, §7 |
| V3 P2 | Lazy registrar resolution changes contextual binding and endpoint capture | accepted. Mechanism changed: the facade root and the jobs/commands resolve as today; only the AEAT client is lazy, behind `AeatClientContract`. Contextual bindings are preserved. Endpoint capture moves from registrar construction to first send — declared and pinned | §4.1, §4.3, §5, §7 |
| V4 P2 | Removing `handle()` parameters breaks subclasses of the non-final jobs and commands | accepted — the signatures are kept, made unnecessary by the lazy client; pinned by a reflection test | §4.1, §4.3, §7 |
| S1 P1 | A vetoed amendment link may allow a second amendment | same mechanism as V1; included in AID-1917; declared limit | §4.1 |

Because rev 8 changes a mechanism, the closing pair restarts: a bounded round on `881dfdc..rev 8`, then a new unbounded round and its identical repeat.

### Round 6

- **Session:** `01a1bb0a-767e-7bf0-803e-039f1c9485dc` (Codex, read-only), 2026-11-08 13:03-13:08.
- **Scope:** bounded to `881dfdc..64df1c3` (rev 8).
- **Verdict:** `GATE: FAIL`, with 2 verified findings (1 P1, 1 P2) and 0 suspected.
- **Method:**
  - (xiii), the lazy proxy, is real;
  - (xiv), provenance, is real but incomplete.
- **Part A:** V1, V4 and S1 land; V2 and V3 land partially. No normative text from the old mechanism survives.

| # | Finding | Decision | Landed in |
|---|---|---|---|
| V1 P1 | Registry id is not invocation provenance: a nested same-registry send from an `updating` observer would still match | accepted. Provenance now marks the invocation's pre-send phase: client guards raise unflagged `forOperation('send')`; only `submitToAeat()` flags (re-check, plus conversion in its own passthrough); everything after the client returned is rethrown unflagged as `forOperation('outcome')` | §4.1, §7 retry provenance (a)(b)(c) |
| V2 P2 | Lazy construction turns an invalid endpoint into a transport failure (`markAsFailed`, attempts consumed) | accepted. The binding resolves eagerly when enabled at resolution (exactly as today) and defers only when disabled. The deferred-factory `ConfigurationException` passes the transport catch. Verified that the concrete client throws no `ConfigurationException` (`AeatClient.php:75-256`) | §4.1, §7 enabled-branch equivalence |

### Round 7

- **Session:** `01a1bb11-f265-7533-9265-ac4ff9651534` (Codex, read-only), 2026-11-08 13:11-13:14.
- **Scope:** bounded to `64df1c3..6fd10d5` (rev 9).
- **Verdict:** `GATE: PASS` for that diff, with 1 verified finding (P3) and 0 suspected.
- **Method:** (xv) and (xvi) are real. The deferred window is confined to in-process activation changes.
- **Part A:** both round-6 corrections land.

| # | Finding | Decision | Landed in |
|---|---|---|---|
| V1 P3 | The factory signatures do not express their call forms: `$previous` has no default, and `forOperation` has no causal argument | accepted. Both now take `?\Throwable $previous = null`, forwarded through `make()` | §4.1 |

### Round 8 (unbounded closing round)

- **Session:** `01a1bb15-c3fe-7060-bf7d-209e9b63c698` (Codex, read-only), 2026-11-08 13:15-13:22.
- **Scope:** full spec at `b06f711` (rev 10). Part B was not bounded.
- **Verdict:** `GATE: FAIL`, with 4 verified findings (1 P1, 1 P2, 2 P3) and 0 suspected.
- **Part A:** the rev-10 P3 lands.

| # | Finding | Decision | Landed in |
|---|---|---|---|
| V1 P1 | Provenance unflagging did not cover the transport-failure catch body (`markAsFailed`, event), so a nested refusal there could be read as `skipped` | accepted — verified at `InvoiceRegistrar.php:196-215`. Rule (c) now covers every outcome phase, so only the two pre-send paths can flag | §4.1, §7 retry provenance (d) |
| V2 P2 | A partial client batch turns a refusal into a generic failure response (`AeatClient.php:115-125`) | accepted — verified, and the method has no package caller. Decision: the batch is admitted once at entry, and items use an unguarded private core | §4.1, §7 client batch admission |
| V3 P3 | `enabled()` and the facade-root wording promised client-free construction unconditionally | accepted — the claim is qualified to "disabled at resolution" | §5 |
| V4 P3 | The gap report overstated blocking; `ensureSequentialOrder()` skips invoice numbers without an extractable sequence (`ProcessInvoiceRegistrationJob.php:178-189`) | accepted — verified. The report now lists "potential blockers" | §4.5 |

Rev 11 changes no mechanism. It extends one rule and specifies one contract. The closing pair runs again on rev 11, as an unbounded round plus an identical repeat, with the union taken.

### Round 9 (first unbounded round on rev 11)

- **Session:** `01a1bb1e-2a89-7030-8257-721d1c08243e` (Codex, read-only), 2026-11-08 13:24-13:31.
- **Scope:** full spec at `a4f51ce` (rev 11), with Part B not bounded. It was launched as the identical repeat of round 8, but because rev 11 changed text it counts as the first unbounded round on rev 11 (umbrella C-06b). It is not paired with round 8.
- **Verdict:** `GATE: FAIL` — 2 verified (both P1), 0 suspected.
- **Part A:** all four round-8 corrections land.

| # | Finding | Decision | Landed in |
|---|---|---|---|
| V1 P1 | An exception from a listener on the client's `SoapFault` log escapes the concrete client after the SOAP attempt; the class-based passthrough would flag it as a pre-send refusal | accepted — verified at `AeatClient.php:88,98-99,243-247`. **Mechanism change:** a single-use per-registry admission, decided at the re-check and consumed by the client guard, replaces the disabled passthrough. The re-check is the only source of the flag | §3, §4.1, §4.2, §7 |
| V2 P1 | The `ConfigurationException` passthrough changes the continuously-enabled branch, because the same listener path can raise that class | accepted — the passthrough is removed. A deferred-factory failure in the unsupported switch-on-later window gets today's transport handling, declared as a limit | §4.1, §7 |

Rev 12 changes a mechanism, so the next round is a bounded round on `a4f51ce..rev 12`, followed by the closing pair (unbounded plus an identical repeat over the same revision).

### Round 10

- **Session:** `01a1bb26-ec89-7722-8421-bfc4d7e29d4b` (Codex, read-only), 2026-11-08 13:34-13:37.
- **Scope:** bounded to `a4f51ce..cb42610` (rev 12).
- **Verdict:** `GATE: FAIL` — 2 verified (both P1), 0 suspected.
- **Method:** (xvii), the single-use admission, is real but incomplete.
- **Part A:** round-9 V2 lands. Round-9 V1 lands partially. No class-based passthrough survives in normative text.

| # | Finding | Decision | Landed in |
|---|---|---|---|
| V1 P1 | One admission cannot cover the proxy → concrete guard chain, because the proxy consumes it and the concrete guard then refuses | accepted. **Mechanism change:** the admission token is removed. The registrar calls an internal unguarded `sendAdmitted()`, and the proxy delegates through an internal unguarded `sendCore()`. Public client methods keep their guards for direct users only | §3, §4.1, §7 |
| V2 P1 | A re-entrant `beforeResolving` callback inside `app()` can consume the outer admission | accepted — verified against `Container.php:911-926`. With no per-send state there is nothing to steal, and the admitted path resolves no activation after the re-check | §4.1, §7 re-entry test |

**Trajectory note.** Rounds 5, 6, 8, 9 and 10 all found new forms of one gap at the registrar/client boundary. Revisions 12 and 13 are the redesigns. If the next unbounded round finds another P1 there, the loop stops and the method question goes to the owner (§13 note to the umbrella, AID-1908).

### Round 11

- **Session:** `01a1bb2b-5e96-7890-ad8c-6a8533f5c6c8` (Codex, read-only), 2026-11-08 13:39-13:41.
- **Scope:** bounded to `cb42610..b7a93bf` (rev 13).
- **Verdict:** `GATE: FAIL` — 2 verified (P2, P3), 0 suspected. **No P1.**
- **Method:** (xviii), the internal admitted path, is real.
  - `instanceof` covers both binding branches.
  - The `sendCore()` extraction preserves today's body.
  - Consumer clients and decorators take the contract fallback, a declared limit.
- **Part A:** V1 lands; V2 partially (the test, not the mechanism).

| # | Finding | Decision | Landed in |
|---|---|---|---|
| V1 P2 | The re-entry test was unsatisfiable: it required a `beforeResolving` callback to run on a path the spec says resolves no activation | accepted. The test now asserts that the callback never runs after the re-check (counter = 0). The nested-refusal case stays covered, independently, by the class-origin test | §7 |
| V2 P3 | Normative §4.1 rule (c) still mentioned "after the admission was consumed" | accepted — reworded to a nested send through a public guarded method | §4.1 |

### Rounds 12 and 13 (the closing pair, both over `6b46910`)

- **Round 12:**
  - session `01a1bb2e-f76b-7723-9cb9-cf00926fe2ce`, 2026-11-08 13:42-13:47, unbounded;
  - `GATE: PASS` with 1 verified (P3), 0 suspected.
- **Round 13:**
  - session `01a1bb33-316d-7690-82cd-b2a2309d5817`, 13:47-13:51;
  - the identical repeat: the prompt differs only in its first line;
  - `GATE: PASS` with 1 verified (P3), 0 suspected.
- **Union:**
  - one finding, reported by both rounds: §4.1 and §4.3 still described client-free resolution unconditionally;
  - no P1, no P2 and no suspected finding in either half;
  - **union verdict over `6b46910`: PASS**.

| # | Finding | Decision | Landed in |
|---|---|---|---|
| U1 P3 | §4.1 (commands) and §4.3 (jobs) claimed that resolving the registrar never builds the AEAT client, but §4.1 keeps eager construction when enabled | accepted; both are qualified with "when disabled at resolution". Wording only | §4.1, §4.3 |

Rev 15 differs from `6b46910` only by U1 and by this adjudication text. It changes no mechanism, requirement or test.

### Owner rulings after the closing pair (revisions 16 to 19)

| # | Ruling | Decision | Landed in |
|---|---|---|---|
| C-22 | Consumer-data notice retired | the MINOR basis falls | §8 (rev 16) |
| C-25 | Owner's signed exception for AID-1907 | MINOR rests on the exception; CHANGELOG and UPGRADE cite it | §8, §11 (rev 16) |
| C-27 | G7 = option B under the chain lock | applied in rev 17 (`f6038cb`), then moved to AID-1929 by C-31 | §4.5, §10 (rev 19) |
| C-29 | G8 = registry custody columns, full §13 | applied in rev 18 (`f6038cb`), then moved to AID-1929 by C-31 | §10 (rev 19) |
| C-31 | G9: G7 and G8 go to a major; 1.7.0 = the switch only | applied; the gap report is withdrawn; the 1.x guard is pinned unchanged | §4.5, §7, §8, §10 (rev 19) |

**Rev 19 against the closing pair.** The mechanism is rev 15's, which passed rounds 12-13 over `6b46910`. The diff `22376bc..` this revision is text only:
- the classification basis (rev 16);
- the G7 statement;
- the gap report withdrawn, a conditional mechanism that is no longer prescribed;
- one regression-pin test.

A bounded round on that diff is enough. No closing pair is needed unless the bounded round finds that the withdrawal changes a path the pair covered.

### Round 14 (bounded, over `22376bc..cd63b6c`)

- **Session:** `01a1bc47-bb67-79d0-a442-bb374ccaa6ca`, read-only, 2026-11-08 18:49-18:52. Launched by umbrella GO C-34 with the owner's authorization in session.
- **Verdict:** **GATE: PASS**, with 0 VERIFIED and 0 SUSPECTED.
- **Method ruling:** withdrawing the gap report removes a conditional status output, not an admission or execution path. Read-only surfaces, the disabled warning and the upgrade-path test are retained.
- **§4.5 description of the 1.x block:** accurate (`ProcessInvoiceRegistrationJob.php:170-234`). Its predicate does not tell a mid-year adoption from re-enabling.
- **Regression pin (§7):** kills deleting `$this->ensureSequentialOrder($invoice)` at `:131`, which is reachable. The reviewer established this by reading the code; no mutation was executed.
- **Normative sweep:** clean. The remaining positive mentions of the gap report are historical adjudications that have been superseded.

| # | Finding | Decision | Landed in |
|---|---|---|---|
| — | none | — | — |

**Coverage of 1.7.0 after round 14:**
- **Mechanism:** rev 15's, covered by the closing pair, rounds 12-13 over `6b46910`.
- **Text:** rev 19's diff, covered by round 14.
- **Statement it supports:** **PASS of the specification for the switch-only 1.7.0 in rounds 12-14.** It is not "the implementation is correct", and not release approval. What no round executed is listed below and is unchanged.

### Coverage — what these verdicts cover and what they do not

- **Rounds by role:**
  - Rounds 1-4 and 6-7, 10-11 were bounded rounds on how the switch decides and on each correction: parsing, guards, observability, test sensitivity.
  - Rounds 5, 8, 9 and 12-13 were unbounded rounds on the path after the decision, along both branches, across 14 to 15 units.
  - Rounds 12-13 are the closing pair over one revision, with the union taken.
- **Redesigns forced by the rounds:**
  - rev 4 moved authority to effect boundaries;
  - rev 8 introduced the lazy client (in place of late resolution and constructor widening);
  - rev 12 introduced the admission token (in place of class-based catches);
  - rev 13 introduced the internal admitted path (in place of the token).

  Five rounds (5, 6, 8, 9, 10) found new forms of one gap at the registrar/client boundary. The closing pair found none after rev 13.
- **The statement this gate supports:** **PASS of the specification in rounds 12-13 over `6b46910`**, applied as rev 15. It is not "the implementation is correct", and not release approval.
- **What NO round executed:**
  - The database suite was never run by a reviewer, and every reviewer measurement was an inline, database-free PHP probe. The author's only suite run was the baseline at `a51a3f5` (MariaDB 11.4, 757 passed, 9 skipped).
  - Nothing ran on MySQL 8.4.
  - No real worker, no real AEAT sandbox, and no concurrent processes.
  - No consumer conformance against `clientes` or Larabill.

  Every behavioural claim in §4 and §7 is a prescription to be proven by the implementation MR's tests on both engines, with recorded sensitivity.
- **Out of scope by design:**
  - G7 and G8 as mechanisms: moved to AID-1929 (C-31); 1.7.0 keeps the 1.x order guard;
  - AID-1917 (vetoed outcome updates);
  - AID-1909 (the other dead config keys);
  - AID-1886 (MR `!104`);
  - AID-1889.
