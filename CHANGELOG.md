# Changelog

All notable changes to `lara-verifactu` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- The fork-based proof that the fingerprint chain cannot be forked under
  concurrent writes (AID-264, guarding the AID-258 chain lock) **now runs in
  CI**, against both engines of the test matrix (MySQL 8.4 and MariaDB 12.3).
  It had never run in any pipeline: the file gates on `RUN_CONCURRENCY_IT=1`
  and nothing set it, so the most expensive invariant in the package — a fork
  means two records declaring the same predecessor, an invalid chain before the
  Spanish tax agency — was covered by no automated gate at all. The job fails
  if the test reports itself skipped, so an absent gate cannot be replaced by a
  gate that lies.
- The same test was hardened so its green means something (AID-710): the forked
  writers are released on an absolute-time barrier instead of one-by-one as
  they are created, the process count is now measured and documented instead of
  assumed, and a child that fails reports its real exception instead of a mute
  `exit(1)`. Verified by disabling the chain lock and confirming the test turns
  red on both engines.

No runtime behaviour changed: `src/` is untouched by this work.

## [1.1.0] - 2026-07-15

### Added

- Custom invoice models are now genuinely decoupled from the native
  `Invoice` table (AID-344): `Registry::invoice()` resolves the model class
  from `config('verifactu.models.invoice')` at runtime, and the hardcoded
  foreign key from `verifactu_registries.invoice_id` to `verifactu_invoices`
  has been dropped. Any Eloquent model (any table, integer primary key)
  implementing `InvoiceContract` can now be registered, cancelled and
  queried through the `Verifactu` facade. The `verifactu:register` command,
  `ProcessInvoiceRegistrationJob`, `verifactu:retry-failed` and
  `verifactu:status` remain native-`Invoice`-only (tracked separately) —
  see the README's "Custom invoice models" section.

### Fixed

- `RegistryManager` now persists `invoice_id` via `InvoiceContract::getId()`
  instead of the Eloquent `id` magic property (AID-344) — no behavior
  change for native mode, but the previous code silently bypassed the
  contract.

### Internal

- Modernize the Eloquent cast declarations: the `Invoice`, `InvoiceBreakdown`
  and `Registry` models now declare their casts via the `casts()` method
  (Laravel 11+ idiom) instead of the classic `$casts` property. Behavior-neutral
  — identical cast map and semantics, only the declaration form changed. Model
  configuration stays at the PHP level; no Laravel 13-only `#[Table]` native
  attributes are introduced, since those would break L12 consumers.
- Add the on-demand fork-based concurrency integration test for the AID-258
  chain-fork lock (AID-264): N real processes create registries concurrently and
  assert the fingerprint chain does not fork. Gated behind `RUN_CONCURRENCY_IT=1`
  and kept out of the CI suite (lives outside the PHPUnit testsuites). Verified
  sensitive — it detects the fork in 6/6 runs when the lock is disabled.

## [1.0.0] - 2026-06-27

First stable release. The public API (including the `src/Contracts/*` interfaces)
and the published database schema are now frozen under SemVer — breaking changes
require a MAJOR bump (see `VERSIONING.md`). "Stable" means the API and schema are
locked for the declared scope, not full Spanish-law coverage: the package keeps
its **honest-core, fail-loud** posture, rejecting unsupported AEAT cases with a
`ValidationException` instead of emitting fiscal XML the AEAT would reject. The
supported-vs-rejected matrix is documented in the README; the post-1.0 coverage
roadmap is tracked in AID-209.

### Added

- Amend-by-rejection «ALTA POR RECHAZO» (AID-137): a `REJECTED` initial
  registration whose unique key is provably **not** registered at AEAT can be
  re-sent, corrected, as a new chain link carrying `Subsanacion=S` +
  `RechazoPrevio=X`, with `amends_registry_id` pointing at the rejected record.
  The rejected record and its XML stay immutable. Blockchain verification now
  reads the hash inputs from each registry's persisted historical XML, not the
  mutable `Invoice`.

### Changed

- AEAT validation rejections (`EstadoRegistro = Incorrecto`) are now classified
  as `REJECTED` instead of a generic error state (AID-257) — the precondition
  for the amend-by-rejection flow: a record AEAT never accepted can be corrected
  and re-sent rather than staying stuck.

### Fixed

- Chain-fork lock (AID-258): the hash-chain head is now locked under concurrent
  registry creation. `acquireChainLock()` takes an exclusive lock on a sentinel
  row at the start of the registration transaction (both registration and
  cancellation paths) before selecting the previous link, so two concurrent
  creations can no longer read the same predecessor and fork the fingerprint
  chain. The chain is global per issuer.

### Internal

- Migrated the test suite from SQLite to the real MariaDB/MySQL engine, green
  dual-engine (MariaDB 12.3 + MySQL 8.4) in CI (AID-259); pinned the rule 1156
  IDOtro+02 × TipoFactura invariant (AID-228); standardized the README badge
  block (AID-241).
- Documented the schema versioning policy in `VERSIONING.md`: published
  migrations immutable from 1.0.0 (append-only), additive schema = MINOR,
  breaking schema = MAJOR, `src/Contracts/*` treated as stable API.

## [1.0.0-rc2] - 2026-06-21

### Added

- Intra-EU B2B service invoices (AID-223): `CalificacionOperacion` **N2** (no
  sujeta por reglas de localización) and the **`IDOtro`** recipient block for a
  foreign counterpart. A Spanish issuer's service to an EU VAT-registered company
  now emits N2 + `IDOtro` (NIF-IVA 02, no `CodigoPais`) instead of being rejected
  fail-loud — the case AEAT rejected as error 1100 (a foreign VAT emitted as
  `<NIF>`). S2/N1, E5, OSS, intra-community goods and `IDType` 07 stay rejected
  fail-loud. `Impuesto`/`ClaveRegimen` remain 01 (general regime). Validated
  end-to-end against AEAT Pruebas Externas on 2026-06-21 (EstadoEnvio Correcto,
  CSV `A-UA5R9QVEXRTWYQ`).

### Changed (BREAKING)

- `InvoiceBreakdownContract` gains `getCalificacion(): ?CalificacionOperacionEnum`
  (AID-223). Implementers must add the method; `null` preserves the previous S1
  behavior. Accepted within the 1.0 release candidate, before the public API
  locks at 1.0.0 — a breakdown's fiscal classification belongs on the contract,
  not behind an optional capability.

### Changed

- Demoted redundant `->info()` logs to `->debug()` (AID-208): intermediate steps
  ("Creating registry for invoice", "Creating cancellation registry",
  "Submitting registry to AEAT") and idempotency skips ("Registry already sent,
  skipping", "Invoice already has a registry, skipping") no longer emit at the
  default `info` level. Real transitions (registry submitted/sent, invoice
  registered) and all warning/error/critical logs are unchanged.

### Documentation

- Documented the logging privacy posture (AID-208): third-party fiscal PII in
  the SOAP payload is redacted and opt-in (AID-198); the issuer's own
  operational identifiers (invoice number/serie, registry number, ids, AEAT CSV)
  are kept for support traceability and controlled via `VERIFACTU_LOG_LEVEL`
  (recommend `warning` for restricted environments). No field-level redaction of
  these operational identifiers in v1.0.

## [1.0.0-rc1] - 2026-06-19

### Changed (BREAKING)

- Made `XmlBuilder` enforce Destinatarios × TipoFactura (AID-194, AEAT rules
  1189/1190): F1/F3/R1 now require a Destinatarios block (only F3 was enforced
  before) and F2/R5 (simplified) must not carry one — both fail loud with
  `ValidationException` (guard #8). 1189 requires an actually emittable recipient
  (`hasRecipient()` plus a non-null `getRecipient()`), and 1190 fires before the
  NIF guard. Added `InvoiceTypeEnum::requiresRecipientInV10Core()` ({F1, F3, R1}).
- Made `Invoice::isSimplified()` derive from `type` (AID-187): `type` is now the
  single source of truth for simplified-ness, deriving from `InvoiceTypeEnum`
  (F2/R5) instead of a separate stored `simplified` boolean that could disagree
  with it (e.g. `type=F2` with `simplified=false`). The `InvoiceFactory` default
  is now the deterministic `COMPLETE` (F1) — it previously emitted a random F1/F2
  with a recipient, which under the new derivation would be a simplified invoice
  carrying a recipient (contrary to AEAT rule 1190). The `simplified()` state now
  sets `type=F2`.
- Made `XmlBuilder` reject post-1.0 `TipoFactura` codes fail loud (AID-185). It
  now throws `ValidationException` for any `TipoFactura` outside the v1.0 core
  {F1, F2, F3, R1, R5}; R2/R3/R4 (Art. 80.3/80.4/"Resto") are XSD-valid but
  post-1.0, so `validate()` alone would not catch them. The codes remain in
  `InvoiceTypeEnum` for XSD conformance; added
  `InvoiceTypeEnum::isSupportedInV10Core()`.
- Made `XmlBuilder` fail loud for data outside the v1.0 honest core instead of
  silently emitting a default the AEAT would reject (AID-179). It now throws
  `ValidationException` for: `Impuesto` ∉ {01,02,03} (05 Otros is post-1.0);
  any `ClaveRegimen` other than the general regime 01 (special regimes — incl.
  04/08/10/20, which force a non-S1 calificación — are post-1.0); an exempt
  breakdown without an explicit `OperacionExenta` cause E1-E4/E6 (E5 requires
  IDOtro, post-1.0) instead of defaulting to `E1`; a non-NIF (IDOtro / foreign)
  recipient instead of defaulting `IDType` to `02`; and a `TipoRectificativa`
  outside {S,I} instead of collapsing it to `I`. `CalificacionOperacion` is now
  emitted from `CalificacionOperacionEnum::S1`. Added
  `RegimeTypeEnum::isSupportedInV10Core()` and
  `OperacionExentaEnum::isSupportedInV10Core()`.
- Rebuilt the AEAT code-list enums against the official XSD / lists (AID-178),
  the prerequisite for the v1.0 honest core:
  - `RegimeTypeEnum` (L8A/L8B): removed the invalid codes `12`/`13` (not in the
    XSD `IdOperacionesTrascendenciaTributariaType`, AEAT error 1246), fixed the
    mislabeled codes 08-15, added the missing 06/17/18/19/20/21, and added
    `descriptionFor(TaxTypeEnum $tax)` to resolve the IGIC (L8B) labels that
    diverge from IVA (L8A) for codes 17/18/19. Several case names changed.
  - `TaxTypeEnum`: removed `IRPF` (`04`) — not a valid `ImpuestoType` (AEAT
    error 1218); removed `isDirectTax()` (no direct-tax case remains).
  - `InvoiceTypeEnum`: renamed the rectificative cases to match the law —
    R2 `RECTIFICATIVE_SIMPLIFIED` → `RECTIFICATIVE_ART_80_3`, R4
    `RECTIFICATIVE_SUMMARY` → `RECTIFICATIVE_OTHER`, R5
    `RECTIFICATIVE_SUMMARY_SIMPLIFIED` → `RECTIFICATIVE_SIMPLIFIED_INVOICES`;
    fixed `isSimplified()` to F2/R5 only (R2 was wrongly included).
  - `OperationTypeEnum`: marked legacy (not an official AEAT list; ignored by
    the XML builder). Removed in this release together with the `operation_key`
    column (AID-186, see Removed below).

### Added

- End-to-end AEAT Pruebas Externas validation for the full v1.0 core
  (AID-203/AID-204): added F1 and R1 (rectificación por diferencias) scenarios
  to `RealSandboxSubmissionTest` alongside the existing F2/F3/R5-S and
  cancellation, and fixed a doubled-breakdown bug (an explicit breakdown stacked
  on the `InvoiceFactory` default added in AID-193) that had silently broken the
  skipped suite. All six scenarios validated live against AEAT on 2026-06-19
  (EstadoEnvio = Correcto). The sandbox tests stay skipped without a certificate.
- `CalificacionOperacionEnum` (L9), `OperacionExentaEnum` (L10) and
  `RectificativeTypeEnum` (L3) — typed definitions for the lists previously
  carried as hardcode / regex / raw string. Wiring into the builder lands in
  AID-179.
- XSD↔enum conformance guardrail test: asserts every code-list enum stays in
  sync with the official XSD enumerations (would have caught the 12/13 and
  IRPF=04 defects in CI).

### Removed (BREAKING)

- Removed the stored `simplified` boolean column (dropped via a new migration)
  and its `$fillable` / `$casts` / `@property` entries on the `Invoice` model
  (AID-187). `type` is authoritative; any row where `simplified` disagreed with
  `type` now follows `type`. `toArray()` / JSON no longer include a `simplified`
  key — call `isSimplified()` (kept on `InvoiceContract`) instead.
- Removed the dead `OperationTypeEnum` enum, the `operation_key` column (dropped
  via a new migration) and `getOperationKey()` from `InvoiceContract` and the
  `Invoice` model (AID-186). The enum mapped to no official AEAT list and the XML
  builder never read it, so it only let consumers express a non-S1 intent that
  was silently discarded — contrary to the fail-loud honest core.

### Fixed

- Registered the `drop_operation_key` (AID-186) and `drop_simplified` (AID-187)
  migrations in `LaraVerifactuServiceProvider::hasMigrations()`. AID-186 shipped
  the drop but never listed it, so `publishMigrations()` never copied it to
  consumers (the test suite loads the whole migrations folder, which masked the
  gap) — a published install would have kept the obsolete `operation_key` column.

### Upgrade notes

- BREAKING for code writing the `simplified` column directly: the column no
  longer exists and mass-assigning it is silently ignored. Replace
  `'simplified' => true` with `'type' => InvoiceTypeEnum::SIMPLIFIED` (or
  `RECTIFICATIVE_SIMPLIFIED_INVOICES`); read simplified-ness via
  `$invoice->isSimplified()` or `$invoice->getType()->isSimplified()`.
  `Invoice::toArray()` / JSON no longer include a `simplified` key.
- BREAKING for code referencing the removed/renamed enum cases — there are no
  aliases, so stale references fail loudly. Any persisted `regime_type` of
  `12`/`13` or `tax_type` of `04` was already invalid for AEAT and will no
  longer hydrate through the model cast; clean such rows if present.
- BREAKING for custom `InvoiceContract` implementations: `getOperationKey()` was
  removed — delete the method (and any `OperationTypeEnum` import) from your
  model. The `operation_key` column is dropped by a migration; rolling back that
  migration restores the column schema (default `'01'`) but not prior row values.

### Security

- Redacted AEAT SOAP traffic before it reaches the logs (AID-198). `AeatClient`
  no longer dumps the raw SOAP request/response on every send. A new
  `AeatLogSanitizer` masks fiscal personal data (NIF, names, amounts, dates,
  signature material, CSV — a denylist derived from the official XSD) while
  preserving codes/enums; it fails closed (placeholder, never the raw payload)
  and rejects `DOCTYPE` input (XXE). Payload logging is now opt-in
  (`VERIFACTU_LOG_SOAP_PAYLOAD`, default off) and, even when enabled, is always
  redacted on the `verifactu` channel — there is no raw-payload mode. Full stack
  traces are gated behind `VERIFACTU_LOG_DEBUG` (default off); SOAP
  `faultstring` and AEAT error text are passed through the redactor. The default
  log level moved from `debug` to `info`.

## [0.11.0] - 2026-06-16

### Added

- Substitution rectifications (`TipoRectificativa = S`) now emit
  `ImporteRectificacion` (XSD `DesgloseRectificacionType`): `BaseRectificada` +
  `CuotaRectificada` (mandatory) and `CuotaRecargoRectificado` (optional, recargo
  de equivalencia) (AID-142). This closes the 0.10.0 known limitation. Building a
  substitution rectification without the amounts now throws `ValidationException`
  before producing XML, instead of emitting XSD-valid XML that AEAT would reject.
- `InvoiceContract::getRectificationAmounts(): ?array` — returns
  `['base' => float, 'tax' => float, 'surcharge' => float|null]` for the
  substituted original invoice, or `null` when not applicable. The native
  `Invoice` model reads it from `metadata['rectification_amounts']`.
- Invoice type **F3** ("factura emitida en sustitución de facturas simplificadas")
  is now supported (`InvoiceTypeEnum::SUBSTITUTE`) and emits the `FacturasSustituidas`
  block — one `IDFacturaSustituida` per substituted invoice (AID-166). An F3 without
  a recipient (AEAT rule 1189) or without substituted invoices throws
  `ValidationException` before producing XML.
- `InvoiceContract::getSubstitutedInvoices(): array` — the invoices an F3 substitutes
  (`[['number' => string, 'issue_date' => Carbon]]`), `[]` when not applicable. Native
  mode reads `metadata['substituted_invoices']`.
- Amount magnitude is now validated against the XSD `ImporteSgn12.2Type` (max 12
  integer digits) before building XML (AID-168). Every monetary field
  (`CuotaTotal`, `ImporteTotal`, `BaseImponibleOimporteNoSujeto`,
  `CuotaRepercutida`, `BaseRectificada`, `CuotaRectificada`,
  `CuotaRecargoRectificado`) throws `ValidationException` when it exceeds the
  range, instead of emitting XSD-invalid XML that AEAT would reject. The sign and
  the two decimals do not count toward the limit; rounding is accounted for
  (`999999999999.999` rounds up to 13 integer digits and is rejected), and
  non-finite amounts (`INF`/`NaN`) are rejected too. `TipoImpositivo` (XSD
  `Tipo2.2Type`, a percentage, not an amount) is formatted separately and is not
  subject to this check.
- Equivalence surcharge (recargo de equivalencia) in the breakdown (AID-173): when
  a breakdown carries a surcharge, the registration XML now emits
  `TipoRecargoEquivalencia` (XSD `Tipo2.2Type`) and `CuotaRecargoEquivalencia` (XSD
  `ImporteSgn12.2Type`) after `CuotaRepercutida`, in XSD `DetalleType` sequence
  order. Rate and amount are a semantic pair: providing only one throws
  `ValidationException` before producing XML. `CuotaRecargoEquivalencia` inherits
  the AID-168 magnitude check.
- Percentage fields are now validated against the XSD `Tipo2.2Type` (finite,
  non-negative, max 3 integer digits) before building XML (AID-175). `formatRate()`
  — used by `TipoImpositivo` and `TipoRecargoEquivalencia` — throws
  `ValidationException` (naming the field) on a negative, non-finite, or
  out-of-range rate (counted after rounding, so `999.999` → `1000.00` is rejected),
  instead of emitting XSD-invalid XML.

### Changed

- **BREAKING (custom invoice models)**: `InvoiceContract` gained
  `getRectificationAmounts(): ?array` (AID-142) and `getSubstitutedInvoices(): array`
  (AID-166). Custom implementations must add both (return `null` / `[]` when not
  applicable). Native mode is unaffected.
- **BREAKING (enum)**: `InvoiceTypeEnum::RECTIFICATIVE_BY_SUBSTITUTION` (value `R3`)
  renamed to `InvoiceTypeEnum::RECTIFICATIVE_ART_80_4` (AID-167). The old name was
  misleading: per the official XSD `ClaveTipoFacturaType`, `R3` is "FACTURA
  RECTIFICATIVA (Art. 80.4)" (uncollectable debts), not a substitution. Substitution
  in Verifactu is `TipoRectificativa = S`, orthogonal to the invoice type. The
  backing value (`R3`) and the `isRectificative()` behaviour are unchanged; only the
  case name and its description ("Factura rectificativa (Art. 80.4)") changed. Update
  any reference to the old case name — a backed-enum alias is impossible (two cases
  cannot share the value `R3`), so this is a direct rename.

## [0.10.0] - 2026-06-11

### Added

- Rectificative invoices in the registration XML (AID-135): `RegistroAlta`
  now emits `TipoRectificativa` and, when the rectified invoices are
  identified, `FacturasRectificadas` with one `IDFacturaRectificada`
  (issuer NIF + serie/number + issue date) per entry. Fixes AEAT sandbox
  rejection 1114 ("Si la factura es de tipo rectificativa, el campo
  TipoRectificativa debe estar cumplimentado").
- `InvoiceContract::getRectifiedInvoices()` — returns the invoices
  rectified by this one as `[['number' => string, 'issue_date' => Carbon]]`.
  The native `Invoice` model reads them from
  `metadata['rectified_invoices']`.

### Changed

- **BREAKING (custom invoice models)**: `InvoiceContract` gained
  `getRectifiedInvoices(): array`. Custom implementations must add it
  (return `[]` when not applicable). Native mode is unaffected.
- `getRectificationType()` values are normalized when building the XML:
  `'S'` maps to AEAT substitution; anything else (including legacy adapter
  codes like `'R1'` or null) maps to `'I'` (incremental, por diferencias).

### Known limitations

- `ImporteRectificacion` (required by AEAT business rules when
  `TipoRectificativa` is `S`) is not emitted yet — substitution
  rectifications will need it before production use. Incremental (`I`)
  rectifications, the common modality, are fully supported.

## [0.2.0-alpha] - 2025-11-16

### 🚨 BREAKING CHANGES

#### Configuration Changes
- **Default queue name changed**: `verifactu` → `fiscal_verification`
  - **Action required**: Update your queue worker configuration
  - **Action required**: Update supervisor config to use dedicated queue
- **Default max retry attempts changed**: `3` → `1`
  - **Rationale**: Fiscal compliance requires manual verification of errors
  - **Action required**: Use `php artisan verifactu:retry-failed` command for retries

#### Job Behavior Changes
- **Sequential Processing**: Invoices are now processed in strict chronological order within same serie/fiscal year
  - **Critical**: Invoice N+1 cannot be verified before invoice N
  - **Critical**: Failed verification BLOCKS entire queue until resolved manually
- **Unique Lock**: Only ONE fiscal verification job can run at a time
  - **Action required**: Use SINGLE worker for `fiscal_verification` queue
  - **Configuration**: Supervisor should run max 1 process for this queue

### Added

#### New Features
- **Sequential Verification Logic** (ADR-001)
  - `ensureSequentialOrder()` method in `ProcessInvoiceRegistrationJob`
  - Validates no previous invoices are pending within same serie + fiscal year
  - Throws `RuntimeException` on sequential order violation
- **Unique Lock System** (ADR-002)
  - Cache-based lock prevents concurrent fiscal verification
  - Lock timeout: 300 seconds (configurable)
  - Retry delay: 10 seconds (configurable)
- **Manual Retry Command** (ADR-003)
  - New command: `php artisan verifactu:retry-failed`
  - Options: `--serie`, `--from`, `--to`, `--all`, `--dry-run`
  - Progress bar for batch retries
  - Confirmation prompt for safety
- **Configuration Section: Lock** (NEW)
  - `lock.enabled`: Enable/disable lock (default: true)
  - `lock.timeout`: Lock duration in seconds (default: 300)
  - `lock.retry_delay`: Retry delay in seconds (default: 10)
- **Test Suite for Sequential Processing**
  - 6 new tests validating sequential logic
  - Tests for serie/fiscal year isolation
  - Tests for job configuration
  - Tests for queue dispatch

### Changed

#### Job Configuration
- `ProcessInvoiceRegistrationJob::$tries`: 3 → 1
- `ProcessInvoiceRegistrationJob::onQueue()`: 'verifactu' → 'fiscal_verification'
- **No automatic retries**: Job fails permanently on error
- **Critical logging**: Failed jobs log as CRITICAL level

#### Error Handling
- Sequential order violations throw `RuntimeException` with descriptive message
- Lock acquisition failures release job with delay (non-fatal)
- All exceptions are re-thrown to fail job permanently
- `failed()` method logs critical messages for monitoring

#### Configuration Defaults
- `config('verifactu.queue.name')`: 'verifactu' → 'fiscal_verification'
- `config('verifactu.retry.max_attempts')`: 3 → 1
- `config('verifactu.retry.delay')`: Added (60 seconds)
- New config section: `lock` (enabled, timeout, retry_delay)

### Fixed
- Sequential processing prevents race conditions
- Lock system prevents parallel job execution
- No data corruption due to out-of-order verification

### Migration Guide

#### Step 1: Update Configuration
```bash
# Update .env
VERIFACTU_QUEUE=fiscal_verification  # Changed from 'verifactu'
VERIFACTU_RETRY_MAX_ATTEMPTS=1       # Changed from 3
VERIFACTU_LOCK_ENABLED=true          # New setting
VERIFACTU_LOCK_TIMEOUT=300           # New setting (5 minutes)
```

#### Step 2: Update Supervisor Configuration
```ini
; OLD Configuration (REMOVE THIS)
[program:verifactu-worker]
command=php artisan queue:work redis --queue=verifactu --tries=3

; NEW Configuration (USE THIS)
[program:fiscal-verification-worker]
command=php artisan queue:work redis --queue=fiscal_verification --tries=1
process_name=%(program_name)s
numprocs=1                           ; ⚠️ CRITICAL: Only 1 process
autostart=true
autorestart=true
```

#### Step 3: Monitor for Failed Jobs
```bash
# Check for failed verifications
php artisan verifactu:retry-failed --dry-run

# Retry specific invoice
php artisan verifactu:retry-failed 123

# Retry all failed invoices (with confirmation)
php artisan verifactu:retry-failed --all

# Filter by serie
php artisan verifactu:retry-failed --serie=A --from=2025-01-01
```

#### Step 4: Update Monitoring
- Monitor queue: `fiscal_verification`
- Alert on job failures (check logs for CRITICAL level)
- Alert if queue is blocked (no jobs processing for extended period)

### Why This Version?

This release introduces **critical fiscal compliance features** required for production use with Spain's AEAT Verifactu system:

1. **Sequential Processing**: Tax agencies require invoices to be registered in chronological order
2. **Unique Lock**: Prevents race conditions and ensures data integrity
3. **Manual Intervention**: Fiscal errors require human review, not automatic retries
4. **Audit Trail**: Critical logging ensures all issues are traceable

### Upgrade Risk

⚠️ **HIGH** - This is a breaking change that requires:
- Queue worker reconfiguration
- Supervisor restart
- Monitoring updates
- Team awareness of new error handling

✅ **Safe for alpha/beta users** - Existing database schema unchanged
✅ **Backward compatible** - Can override via ENV variables

## [0.1.0] - 2025-10-12

### Added - Phase 1: Base Structure
- Initial package structure following Spatie best practices
- Contract-based agnostic architecture
- Core contracts: InvoiceContract, RegistryContract, HashGeneratorContract, QrGeneratorContract, XmlBuilderContract, AeatClientContract, CertificateManagerContract
- Comprehensive enum system: InvoiceTypeEnum, TaxTypeEnum, RegimeTypeEnum, OperationTypeEnum, IdTypeEnum, RegistryStatusEnum
- Complete exception hierarchy: VerifactuException and 9 specialized exception classes
- LaraVerifactuServiceProvider with automatic service binding
- Verifactu Facade for fluent API
- Configuration file with native and custom mode support
- Support for Laravel 12
- PHPStan level 8 configuration
- Laravel Pint code style configuration
- Pest testing framework setup
- GitHub Actions CI/CD workflows
- Comprehensive documentation (README, CONTRIBUTING, CHANGELOG, GETTING_STARTED)
- Project-specific Cursor rules and guidelines

### Added - Phase 2: Core Services
- **HashGenerator Service**: Generate SHA-256 hashes according to AEAT specifications (14 tests)
- **QrGenerator Service**: Generate QR codes in SVG/PNG formats (10 tests)
- **XmlBuilder Service**: Build XML according to AEAT XSD schema (14 tests)
- **CertificateManager Service**: Manage digital certificates for AEAT authentication (6 tests)
- **AeatClient Service**: SOAP client for AEAT web services communication (8 tests)
- Complete test coverage: 52 active tests passing
- All services fully documented with PHPDoc
- Integration with certificate signing
- Proper error handling and logging

### Added - Phase 3: Models & Persistence
- **Invoice Model**: Eloquent model implementing InvoiceContract (12 tests)
- **Registry Model**: Eloquent model implementing RegistryContract (15 tests)
- **InvoiceBreakdown Model**: Eloquent model implementing InvoiceBreakdownContract (11 tests)
- Database migrations with proper indexes and constraints
- Model factories for testing and seeding
- Comprehensive model relationships (hasOne, hasMany, belongsTo)
- Soft delete support with cascade
- Type casting for enums, decimals, dates, and JSON
- Model feature tests with RefreshDatabase

### Added - Phase 4: Service Integration
- **RegistryManager Service**: Orchestrate registry operations
  - Create registries with hash generation
  - Manage blockchain integrity
  - Track submission status
  - Generate registry numbers
  - Retry failed submissions
- **InvoiceRegistrar Service**: Main orchestrator for invoice registration
  - Complete registration workflow
  - AEAT submission handling
  - Batch processing support
  - Retry logic for failed submissions
  - Blockchain verification
- Updated HashGenerator and XmlBuilder to work with Invoice models
- Service integration tests

### Added - Phase 5: Commands & Jobs
- **Artisan Commands** (4):
  - `verifactu:register` - Register invoices (single or batch)
  - `verifactu:retry-failed` - Retry failed AEAT submissions
  - `verifactu:verify-blockchain` - Verify blockchain integrity
  - `verifactu:status` - Show system status dashboard
- **Queue Jobs** (4):
  - `ProcessInvoiceRegistrationJob` - Full invoice registration process
  - `SubmitRegistryToAeatJob` - Submit registry to AEAT
  - `RetryFailedRegistriesJob` - Batch retry failed registries
  - `VerifyBlockchainIntegrityJob` - Verify blockchain integrity
- All jobs configured with retries, timeouts, and backoff
- Command and job tests (12 tests)

### Added - Phase 6: Events & Listeners
- **Events** (5):
  - `InvoiceRegisteredEvent` - Fired when invoice is registered
  - `RegistryCreatedEvent` - Fired when registry is created
  - `RegistrySubmittedEvent` - Fired on successful AEAT submission
  - `RegistryFailedEvent` - Fired on failed AEAT submission
  - `BlockchainVerifiedEvent` - Fired after blockchain verification
- **Listeners** (5):
  - `LogInvoiceRegistration` - Logs invoice registrations
  - `LogRegistryCreation` - Logs registry creations
  - `LogRegistrySubmission` - Logs successful submissions
  - `LogRegistryFailure` - Logs failed submissions
  - `LogBlockchainVerification` - Logs verification results
- Event-listener registration in ServiceProvider
- Comprehensive event logging with context data
- Event tests (6 tests)

### Added - Phase 7: AEAT API Integration
- **Real SOAP Client**: Production-ready AEAT web service integration
  - Automatic SSL certificate authentication (.p12/.pfx support)
  - Certificate extraction and temporary file management
  - Full WSDL support for both sandbox and production
  - Proper SOAP operation calls (`RegFactuSistemaFacturacion`)
- **XAdES-EPES Digital Signature**: XML signature using xmlseclibs
  - RSA-SHA256 signature algorithm
  - X.509 certificate embedding
  - Enveloped signature support
  - Proper canonicalization (EXC_C14N)
- **Enhanced AeatClient**:
  - Real certificate-based authentication
  - Detailed SOAP request/response logging
  - AEAT response parsing (CSV, EstadoEnvio, CodigoSeguro)
  - Comprehensive error handling
- **Enhanced CertificateManager**:
  - XAdES-EPES signature implementation
  - Full .p12 certificate support
  - Certificate validation and date checking
  - Private key extraction and management
- **TestAeatConnectionCommand**: New command to verify AEAT connectivity
  - Certificate information display
  - SOAP client initialization test
  - Available methods discovery
  - Connection troubleshooting
- **Dependencies**: Added robrichards/xmlseclibs for XML security
- **Configuration**: Updated endpoints and WSDL URLs for real AEAT services

### Status
- ⚠️ **BETA VERSION - NOT FOR PRODUCTION USE**
- ✅ Phase 1: Arquitectura base (100%)
- ✅ Phase 2: Servicios core (100%)
- ✅ Phase 3: Modelos y persistencia (100%)
- ✅ Phase 4: Integración servicios (100%)
- ✅ Phase 5: Commands & Jobs (100%)
- ✅ Phase 6: Events & Listeners (100%)
- ✅ Phase 7: AEAT API Integration (100%)
- 🚧 Phase 8: Testing & Documentation (50%)
- ⏳ Phase 9: Production hardening (planned for v1.0.0)
- **Total Progress: 92%**
- **Tests: 120/120 passing ✅**
- **PHPStan: Level 8 ✅ (12 legitimate framework false positives baselined)**
- **Code Style: PSR-12 ✅**

[Unreleased]: https://github.com/aichadigital/lara-verifactu/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/aichadigital/lara-verifactu/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/aichadigital/lara-verifactu/compare/v1.0.0-rc2...v1.0.0
[1.0.0-rc2]: https://github.com/aichadigital/lara-verifactu/compare/v1.0.0-rc1...v1.0.0-rc2
[1.0.0-rc1]: https://github.com/aichadigital/lara-verifactu/compare/v0.11.0...v1.0.0-rc1
[0.11.0]: https://github.com/aichadigital/lara-verifactu/compare/v0.10.0...v0.11.0
[0.1.0]: https://github.com/aichadigital/lara-verifactu/releases/tag/v0.1.0

