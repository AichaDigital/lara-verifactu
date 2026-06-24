# Design — AID-137: amend-by-rejection flow («ALTA POR RECHAZO»)

- **Issue:** AID-137 (post-1.0; observed in AID-129 real sandbox runs)
- **Date:** 2026-06-24
- **Status:** design v2 — revised after Codex adversarial review (premise correction)

## Problem

`verifactu:retry-failed` resends the persisted XML as-is. Correct for **transport**
failures (the hashed, chained XML is immutable and must not be regenerated) but
useless for AEAT **validation rejections** (`EstadoEnvio/EstadoRegistro=Incorrecto`):
the record was never registered and its XML is uncorrectable by resending.

**Premise correction (Codex review, verified in code):** today a validation
rejection does **not** become `REJECTED`. `AeatResponseParser` flattens
`Incorrecto` into a generic `AeatResponse::failure()`; `InvoiceRegistrar:164` sends
every failure to `markAsFailed()`; `RegistryManager:335` persists
`RegistryStatusEnum::ERROR`; and `getRetryableRegistries:363` selects
`status = ERROR`. So a real AEAT rejection is stored as a **retryable `ERROR`**,
indistinguishable from transport failure, and `retry-failed` blindly resends it.
`REJECTED` exists in the enum but is never assigned. Any amend-by-rejection flow
must first make rejections classifiable.

## Scope

**v1 implements only the «ALTA POR RECHAZO» variant** (`sheet08-a` row 8): a
rejected **initial registration** whose unique key does **not** exist in AEAT yet,
re-sent corrected as a new chain link with `Subsanacion=S` + `RechazoPrevio=X`.

**Out of scope → AID-209**: the other subsanación variants («ALTA DE SUBSANACIÓN»
`RechazoPrevio=N`, «ALTA POR RECHAZO DE SUBSANACIÓN» `RechazoPrevio=S` with key
present, and both «SIN REGISTRO PREVIO» variants `RechazoPrevio=X`).

## Step 0 (NEW prerequisite) — AEAT outcome classification

Without this, `amendRejected` is unreachable and the retry frontier is wrong.

- **Classify the AEAT outcome** at parse time: a **well-formed AEAT response**
  carrying `EstadoEnvio=Incorrecto` (or per-line `EstadoRegistro=Incorrecto`) is a
  **validation rejection** → `REJECTED`. A transport/SOAP/parse failure (no valid
  response, fault, timeout, malformed body — the `AeatResponse::failure()` path at
  parser line 29) stays `ERROR`.
- **Propagate the discriminator.** `AeatResponse` must carry enough to tell the two
  apart: `EstadoEnvio`, per-line `EstadoRegistro`, and the line codes
  (`CodigoErrorRegistro` / `DescripcionErrorRegistro`). Today `failure()` drops it.
- **Branch the persistence.** `InvoiceRegistrar` routes a classified validation
  rejection to a new `markAsRejected()` (→ `REJECTED`, stores the structured
  `aeat_response` codes) and keeps `markAsError()` (→ `ERROR`) for transport.
- **Retry frontier.** The real selector is `getRetryableRegistries()` = `status =
  ERROR`; once rejections are `REJECTED` they fall out of it automatically.
  Dropping `REJECTED` from `canRetry()` is kept for enum consistency, but the
  effective frontier is the `ERROR` selector — note both so neither drifts.

## The `RechazoPrevio` value — verified against the source

L17 (`sheet06:143-145`) and `SuministroInformacion.xsd:754` (`RechazoPrevioType`)
define `{N,S,X}`, distinguished by **whether the record exists in AEAT**:

- **`N`** — no prior AEAT rejection.
- **`S`** — prior rejection **and the record exists in AEAT** (a later subsanación
  was rejected). → «ALTA POR RECHAZO DE SUBSANACIÓN», post-1.0.
- **`X`** — **the record does not exist in AEAT** (initial alta rejected). → AID-137.

`RechazoPrevioType` is an XSD enum `{N,S,X}` — `validate()` accepts any value, so
it will **not** catch a wrong one. The happy-path test must assert
`<RechazoPrevio>X</RechazoPrevio>` explicitly.

**Precondition for `X` (Codex `[P1#2]`):** `X` is correct only if the key does not
exist in AEAT. `amendRejected` must **prove** that from the rejection metadata
(Step 0): the stored `EstadoRegistro=Incorrecto` plus error codes that are not a
duplicate-key / already-registered code. If the rejection reason cannot be shown to
mean "not in AEAT" (e.g. a `RegistroDuplicado` / existing-key code), `amendRejected`
**fails loud** — emitting `X` for an existing key would be rejected again.

## Design

### 1. Data model — new migration (NO `.php.stub`)

lara-verifactu publishes the real `.php` migrations (`hasMigrations([...])` +
`publishMigrations()`); there is **no** `.php`/`.stub` pair here. The migration
**must be registered in `LaraVerifactuServiceProvider::hasMigrations([...])`** or it
is never published to consumers (CI does not catch this — it loads the whole
folder). Columns on `verifactu_registries`:

- `subsanacion` boolean, default `false` → `<Subsanacion>S</Subsanacion>`.
- `rechazo_previo` char(1) nullable, cast to `RechazoPrevioEnum {N,S,X}` (null = not
  emitted) → `<RechazoPrevio>`.
- `amends_registry_id` nullable self-FK → `registries.id`.
- **Unique partial index on `amends_registry_id` where not null** (Codex `[P1#4]`):
  the app-level `withTrashed()` guard gives a good message; the DB constraint
  prevents a concurrent double amendment.

### 2. `RegistryContract` accessors

Add `getRegistryType(): RegistryTypeEnum` (guard 1) and `getAmendsRegistryId(): ?int`.

### 3. `amendRejected` — public API + fail-loud guards

```php
Verifactu::amendRejected(
    RegistryContract $rejectedRegistry,
    InvoiceContract $correctedInvoice,
    bool $submitToAeat = true
): RegistryContract
```

Guards (fail-loud, in order):

1. `getRegistryType() === REGISTRATION` (not a cancellation).
2. `getStatus() === REJECTED` (now reachable thanks to Step 0). `ACCEPTED` → error
   pointing to AID-209.
3. **Rejection proves "not in AEAT"** (precondition for `X`): inspect the persisted
   `aeat_response` codes; if the reason is duplicate-key / already-registered,
   fail loud.
4. **`IDFactura` matches the rejected record's persisted historical XML** (Codex
   `[P2#7]`): parse `IDEmisorFactura` / `NumSerieFactura` / `FechaExpedicionFactura`
   from `getXml()` via **namespaced XPath** (`sf:`), comparing `NumSerieFactura`
   built with the **builder's own convention** `getSerie().getNumber()` (not
   `getInvoiceNumber()`), date as `d-m-Y`. Fail loud if `getXml()` is null/empty or
   a node is missing. Compared against the immutable XML, not the mutable
   `getInvoice()`.
5. **No double amendment**: no registry (`withTrashed()`) has `amends_registry_id =
   rejected.id`. Backed by the DB unique index (guard 1 of §1).

### 4. New registry generation — explicit amendment context

`register()` cannot be reused as-is (Codex `[P1#5]`): `buildRegistrationXml(invoice,
chain)` and `createRegistry(invoice)` take no amendment context, so post-hoc field
updates would emit XML **without** `Subsanacion/RechazoPrevio`. Introduce a small
value object **`RegistrationCircumstances`** (`subsanacion: bool`,
`rechazoPrevio: ?RechazoPrevioEnum`) threaded through `buildRegistrationXml(invoice,
chain, circumstances)` and `createRegistry(invoice, circumstances)`. Normal alta
passes a null/empty circumstances; amendment passes `(S, X)`. **Not** fields on
`InvoiceContract` — these are registry circumstances, not invoice data.

The new registry: new `registry_number`, new chained hash, same `IDFactura` +
corrected data + `<Subsanacion>S</Subsanacion>` + `<RechazoPrevio>X</RechazoPrevio>`,
`amends_registry_id` set, `registry_type=REGISTRATION`. Submits if `$submitToAeat`.

### 5. XSD position (verified correct)

`Subsanacion` / `RechazoPrevio` are direct `RegistroAlta` children **after
`NombreRazonEmisor`, before `TipoFactura`** (`SuministroInformacion.xsd:105`,
`sheet02:9`). Emit only when circumstances flag them.

### 6. Hash chaining (verified correct) + concurrency

`previous_hash` chains after the **last generated link** (`RegistroAnterior` = the
previous generated SIF record, not the rejected business record); `amends_registry_id`
is the separate business link. AEAT accepts chaining onto hashes of rejected links
it never received (AID-129). **Concurrency note** (Codex `[P2]`): previous-link
selection is not locked, so concurrent registry creation can fork the local chain —
generation must select+insert under a lock (row lock / serialized) to keep the chain
linear. Applies to `register()` too; in scope here because amendment adds a link.

### 7. Rejected record stays immutable

No mutation, no inverse column: "already amended" derives from guard 5 + the DB
index.

### 8. `verifyBlockchain` — DECISION NEEDED (Codex `[P1#3]`)

`verifyRegistryHash()` (`RegistryManager:250`) rebuilds the hash from
`$registry->invoice` (mutable). The amendment flow feeds corrected data, so if the
consumer mutates the original native `Invoice`, `verifyBlockchain()` fails on the
rejected historical record. Two parts:

- **In AID-137 regardless:** `amendRejected` takes `$correctedInvoice` as a
  **separate** instance; it must never mutate the rejected record's `Invoice`.
- **The underlying fix (scope question):** verify from the **persisted historical
  XML** rather than the mutable `Invoice`, so a corrected invoice can never break
  an old link's verification. **Recommended: include it in AID-137** — the
  amendment flow is exactly what surfaces the bug, and leaving it makes
  `verifyBlockchain` lie after the first subsanación. Alternative: split to its own
  issue and have AID-137 assume `Invoice` immutability explicitly. Not left
  ambiguous — needs your call.

## Testing

- Step 0 classification: well-formed `Incorrecto` → `REJECTED`; transport/SOAP/parse
  failure → `ERROR`; `getRetryableRegistries` excludes `REJECTED`.
- Each `amendRejected` guard fails loud: cancellation / non-`REJECTED` /
  duplicate-key rejection / `IDFactura` mismatch (from historical XML) / double
  amendment (incl. soft-deleted + DB-constraint race).
- Happy path: new `Registry` with `subsanacion=true` + `rechazo_previo=X` +
  `amends_registry_id`; XML asserts `<Subsanacion>S</Subsanacion>` and explicit
  `<RechazoPrevio>X</RechazoPrevio>` before `TipoFactura`; chained after the last
  link; rejected record + its XML untouched; `validate()` passes XSD.
- Concurrency: two amendments of one rejected record → one succeeds, one fails on the
  unique index.

## AID-137 closure criteria

- A validation rejection is classified `REJECTED` (not retryable `ERROR`).
- A `REJECTED` is not auto-retried.
- An explicit path creates a new amend-by-rejection registration with
  `Subsanacion=S` + `RechazoPrevio=X`, only when the key is provably not in AEAT.
- The rejected record + its XML stay as immutable history; the new one is chained
  after the last link.

## Files touched (anticipated)

- `src/Services/AeatResponseParser.php`, `src/Support/AeatResponse.php` — outcome
  classification + discriminator (Step 0).
- `src/Services/InvoiceRegistrar.php` — `markAsRejected()` branch; `amendRejected()`.
- `src/Services/RegistryManager.php` — `markAsRejected()`; `createRegistry()` +
  circumstances; locked previous-link selection; `verifyRegistryHash` (if §8 in).
- `src/Enums/RegistryStatusEnum.php` — `canRetry()` (consistency).
- `src/Enums/RechazoPrevioEnum.php` — new `{N,S,X}`.
- `src/Support/RegistrationCircumstances.php` — new value object.
- `database/migrations/*_add_subsanacion_to_verifactu_registries_table.php` +
  **register in `hasMigrations()`** (no stub).
- `src/Models/Registry.php`, `src/Contracts/RegistryContract.php` — fields +
  accessors.
- `src/Contracts/XmlBuilderContract.php`, `src/Services/XmlBuilder.php` —
  `buildRegistrationXml(invoice, chain, circumstances)`; emit elements.
- `src/Verifactu.php` — facade method.
- Tests under `tests/Feature` + `tests/Unit`.

## Open decision

§8 `verifyBlockchain`: include the "verify from persisted XML" fix in AID-137
(recommended) or split it out. Everything else above is settled.
