# Design — AID-137: amend-by-rejection flow («ALTA POR RECHAZO»)

- **Issue:** AID-137 (post-1.0; observed in AID-129 real sandbox runs)
- **Date:** 2026-06-24
- **Status:** approved design, pre-implementation

## Problem

`verifactu:retry-failed` resends the persisted XML as-is. That is correct for
**transport** failures (the hashed, chained XML is immutable and must not be
regenerated) but useless for AEAT **validation rejections** (`REJECTED`): the
record was never registered and its XML is uncorrectable by resending.
`RegistryStatusEnum::canRetry()` today includes `REJECTED`, so `retry-failed`
blindly resends rejected records. There is no path to register the corrected
invoice.

## Scope

**v1 implements only the «ALTA POR RECHAZO» variant** (`sheet08-a` row 8): a
rejected **initial registration** whose unique key does **not** exist in AEAT yet,
re-sent corrected as a new chain link with `Subsanacion=S` + **`RechazoPrevio=X`**.

**Out of scope → AID-209** (the other subsanación variants, each with different
preconditions): «ALTA DE SUBSANACIÓN» (correcting an already-`ACCEPTED` record,
`RechazoPrevio=N`), «ALTA POR RECHAZO DE SUBSANACIÓN» (key exists, `RechazoPrevio=S`),
«ALTA DE SUBSANACIÓN SIN REGISTRO PREVIO» and «ALTA POR RECHAZO DE SUBSANACIÓN SIN
REGISTRO PREVIO» (NO-VERIFACTU→VERIFACTU, `RechazoPrevio=X`). The data model below
leaves room for all of them without rework.

## The `RechazoPrevio` value — verified against the source

L17 (`sheet06:143-145`) and `SuministroInformacion.xsd:754` (`RechazoPrevioType`)
define **three** values, and the distinction is *whether the record exists in
AEAT*, not merely "was there a rejection":

- **`N`** — no prior AEAT rejection.
- **`S`** — prior AEAT rejection **and the record exists in AEAT** (a later
  subsanación was rejected). → «ALTA POR RECHAZO DE SUBSANACIÓN».
- **`X`** — **the record does not exist in AEAT** (regardless of prior rejection):
  the initial registration was rejected, so it was never registered. → **our case,
  «ALTA POR RECHAZO»**.

So AID-137 emits **`RechazoPrevio=X`**, not `S`. Critically, `RechazoPrevioType`
is an XSD enum `{N,S,X}` — `validate()` accepts any of them, so it will **not**
catch a wrong value. The happy-path test must assert `<RechazoPrevio>X</RechazoPrevio>`
explicitly.

## Frontier decisions (agreed)

- A `REJECTED` is a business/validation outcome, **not** a resendable technical
  error. The package never auto-corrects invoice data (it lives in the consumer).
- Amendment is an **explicit consumer-initiated** operation, after the consumer
  corrects the data.
- The operation **creates a new `Registry`**; it never mutates the rejected one.

## Design

### 1. Retry frontier fix

`RegistryStatusEnum::canRetry()` drops `REJECTED` → `[PENDING, ERROR]`.
- `PENDING` stays: it never reached AEAT, so resending is a first send (transport,
  idempotent by unique key).
- `SENT` stays out (as today — the acknowledgement/idempotency problem, unrelated).
- Adjust any test asserting `REJECTED` is retryable.

### 2. Data model — new migration + `.php.stub` pair

Three columns on `verifactu_registries` (the `.php` and `.php.stub` must stay
byte-identical — this repo carries known `.php`/`.stub` drift, so author both):

- `subsanacion` boolean, default `false` → drives `<Subsanacion>S</Subsanacion>`
  (L4 is binary N/S; a boolean suffices, `true` ⇒ `S`).
- `rechazo_previo` char(1) nullable, cast to `RechazoPrevioEnum` `{N,S,X}` (null =
  do not emit the element) → drives `<RechazoPrevio>`. **Not a boolean**: L17 has
  three values and the XSD will not protect a wrong one.
- `amends_registry_id` nullable self-FK → `registries.id` (the rejected record
  this one amends).

Naming rationale: the two flag columns keep the **AEAT Spanish** names (literal
XSD/list concepts); `amends_registry_id` is English (an internal model relation).
The two independent fields — not a single `isCorrection` flag — let
`subsanacion(S) × rechazo_previo(N/S/X)` express every `sheet08-a` variant; v1 only
emits `(S, X)`.

### 3. `RegistryContract` accessors

`registry_type` (enum `REGISTRATION`/`CANCELLATION`, migration `2026_06_10_000002`)
exists on the model but is **not** exposed on `RegistryContract`. Add
`getRegistryType(): RegistryTypeEnum` (guard 1 needs it) and
`getAmendsRegistryId(): ?int` to the contract + model accessors.

### 4. `amendRejected` — public API + fail-loud guards

```php
Verifactu::amendRejected(
    RegistryContract $rejectedRegistry,   // the REJECTED history record
    InvoiceContract $correctedInvoice,    // data corrected by the consumer
    bool $submitToAeat = true
): RegistryContract                        // the new amendment registry
```

Delegates to `InvoiceRegistrar::amendRejected(...)`. Guards, in order, each
throwing `ValidationException` (fail-loud) before anything is generated:

1. `$rejectedRegistry->getRegistryType() === REGISTRATION` (not a cancellation).
2. `$rejectedRegistry->getStatus() === REJECTED`. If `ACCEPTED` → explicit error
   pointing to AID-209 («ALTA DE SUBSANACIÓN»).
3. `IDFactura` of `$correctedInvoice` (issuer NIF + series/number + issue date)
   **matches** the **persisted historical XML** of the rejected record. The XML
   (`getXml()`) is the immutable fiscal snapshot generated at registration; we
   parse `IDEmisorFactura` / `NumSerieFactura` / `FechaExpedicionFactura` from it
   via namespaced XPath — **not** from `getInvoice()`, whose native model could be
   mutated by the consumer after the fact. If they differ → another invoice,
   fail-loud.
4. **No double amendment**: no registry (counting `withTrashed()`) already has
   `amends_registry_id = $rejectedRegistry->id`. Once an amendment link was
   generated its hash is in the chain; a soft-deleted one still counts.

### 5. New registry generation

Reuses the `register()` pipeline inside a DB transaction: new `registry_number`,
new chained hash, new XML carrying the **same `IDFactura`** + corrected data +
`<Subsanacion>S</Subsanacion>` + **`<RechazoPrevio>X</RechazoPrevio>`**. Sets
`subsanacion=true`, `rechazo_previo=X`, `amends_registry_id`,
`registry_type=REGISTRATION`. Submits to AEAT when `$submitToAeat`.

### 6. Hash chaining vs. amends relation (two distinct links)

- **Hash chain** (`previous_hash`): chains after the **last generated link**
  (existing `RegistryManager` logic), following real ledger chronology — *not*
  forced onto the rejected record (intervening invoices may exist).
- **Amends relation** (`amends_registry_id`): the business link to the rejected
  record, independent of the chain.

The rejected record remains a historical, immutable link; AEAT accepts chaining
onto hashes of rejected links it never received (observed in AID-129).

### 7. Rejected record stays immutable

No mutation, no `amended_by_*` inverse column in v1: "already amended" is
**derived** via guard 4 (`amends_registry_id = rejected.id`), avoiding a second
source of truth.

### 8. XmlBuilder

Emit `<Subsanacion>` / `<RechazoPrevio>` in `buildAlta` only when the registry
context flags them, at the correct XSD position — in the generation-circumstances
detail, **before `TipoFactura`** (`sheet02` field order). They are **registry**
circumstances, not invoice data, so they are passed as generation context into
`buildRegistrationXml`, not read off `InvoiceContract`.

## Testing

- `canRetry()` excludes `REJECTED`; `retry-failed` no longer processes rejected
  records.
- Each `amendRejected` guard fails loud: cancellation record / non-`REJECTED` /
  `IDFactura` mismatch (parsed from historical XML) / double amendment (incl.
  soft-deleted prior).
- Happy path: new `Registry` with `subsanacion=true` + `rechazo_previo=X` +
  `amends_registry_id`; XML contains `<Subsanacion>S</Subsanacion>` and explicitly
  **`<RechazoPrevio>X</RechazoPrevio>`** (the XSD enum `{N,S,X}` would pass a wrong
  value, so assert the literal), before `TipoFactura`; chained after the last link;
  rejected record untouched; `validate()` passes XSD.
- Migration `.php` / `.php.stub` parity (per the repo's drift guardrail).

## AID-137 closure criteria

- A `REJECTED` is not auto-retried.
- An explicit path creates a new amend-by-rejection registration.
- That new registration's XML carries `Subsanacion=S` + **`RechazoPrevio=X`**.
- The rejected record stays as history; the new one is chained afterwards.

## Files touched (anticipated)

- `src/Enums/RegistryStatusEnum.php` — `canRetry()`.
- `src/Enums/RechazoPrevioEnum.php` — **new** enum `{N,S,X}`.
- `database/migrations/*_add_subsanacion_to_verifactu_registries_table.php` (+ `.stub`).
- `src/Models/Registry.php`, `src/Contracts/RegistryContract.php` — fields +
  `getRegistryType()`, `getAmendsRegistryId()`.
- `src/Services/InvoiceRegistrar.php` — `amendRejected()`.
- `src/Verifactu.php` — facade method.
- `src/Services/XmlBuilder.php` — emit `Subsanacion`/`RechazoPrevio`.
- `src/Services/RegistryManager.php` — persist new fields (if it owns creation).
- Tests under `tests/Feature` + `tests/Unit`.
