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

**v1 implements only the «ALTA POR RECHAZO» variant** (`sheet08-a`): a rejected
**initial registration** whose unique key does **not** exist in AEAT yet, re-sent
corrected as a new chain link with `Subsanacion=S` + `RechazoPrevio=S`.

**Out of scope → AID-209** (the other three subsanación variants, each with
different preconditions): «ALTA DE SUBSANACIÓN» (correcting an already-`ACCEPTED`
record), «ALTA POR RECHAZO DE SUBSANACIÓN», «ALTA DE SUBSANACIÓN SIN REGISTRO
PREVIO». The data model below leaves room for all of them without rework.

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

- `subsanacion` boolean, default `false` → drives `<Subsanacion>S</Subsanacion>`.
- `rechazo_previo` boolean, default `false` → drives `<RechazoPrevio>S</RechazoPrevio>`.
- `amends_registry_id` nullable self-FK → `registries.id` (the rejected record
  this one amends).

Naming rationale: the two flags keep the **AEAT Spanish** names (literal XSD
concepts, lists L4 / L17, values S/N); `amends_registry_id` is English (an
internal model relation). Two independent booleans — not a single `isCorrection`
flag — so the four `(S/N × S/N)` combinations cover all `sheet08-a` variants; v1
only emits `(S,S)`.

### 3. `RegistryContract::getRegistryType()`

`registry_type` (enum `REGISTRATION`/`CANCELLATION`, migration `2026_06_10_000002`)
exists on the model but is **not** exposed on `RegistryContract`. Add
`getRegistryType(): RegistryTypeEnum` to the contract + model accessor (guard 1
needs it).

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
   **matches** `$rejectedRegistry->getInvoice()`. Same fiscal invoice, corrected
   data; if it differs it is another invoice → fail-loud. Identity is read from
   the native `Invoice` relation, **not** by parsing XML.
4. **No double amendment**: no registry (counting `withTrashed()`) already has
   `amends_registry_id = $rejectedRegistry->id`. Once an amendment link was
   generated its hash is in the chain; a soft-deleted one still counts.

### 5. New registry generation

Reuses the `register()` pipeline inside a DB transaction: new `registry_number`,
new chained hash, new XML carrying the **same `IDFactura`** + corrected data +
`<Subsanacion>S</Subsanacion>` + `<RechazoPrevio>S</RechazoPrevio>`. Sets
`subsanacion=true`, `rechazo_previo=true`, `amends_registry_id`,
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
detail, **before `TipoFactura`** (`sheet02` field order). The flags are
**registry** circumstances, not invoice data, so they are passed as generation
context into `buildRegistrationXml`, not read off `InvoiceContract`.

## Testing

- `canRetry()` excludes `REJECTED`; `retry-failed` no longer processes rejected
  records.
- Each `amendRejected` guard fails loud: cancellation record / non-`REJECTED` /
  `IDFactura` mismatch / double amendment (incl. soft-deleted prior).
- Happy path: new `Registry` with both flags + `amends_registry_id`; XML contains
  both elements before `TipoFactura`; chained after the last link; rejected record
  untouched; `validate()` passes XSD.
- Migration `.php` / `.php.stub` parity (per the repo's drift guardrail).

## AID-137 closure criteria

- A `REJECTED` is not auto-retried.
- An explicit path creates a new amend-by-rejection registration.
- That new registration's XML carries `Subsanacion=S` + `RechazoPrevio=S`.
- The rejected record stays as history; the new one is chained afterwards.

## Files touched (anticipated)

- `src/Enums/RegistryStatusEnum.php` — `canRetry()`.
- `database/migrations/*_add_subsanacion_to_verifactu_registries_table.php` (+ `.stub`).
- `src/Models/Registry.php`, `src/Contracts/RegistryContract.php` — fields +
  `getRegistryType()`, `getAmendsRegistryId()`.
- `src/Services/InvoiceRegistrar.php` — `amendRejected()`.
- `src/Verifactu.php` — facade method.
- `src/Services/XmlBuilder.php` — emit `Subsanacion`/`RechazoPrevio`.
- `src/Services/RegistryManager.php` — persist new fields (if it owns creation).
- Tests under `tests/Feature` + `tests/Unit`.
