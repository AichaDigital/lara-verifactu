# Changelog

All notable changes to `lara-verifactu` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/aichadigital/lara-verifactu/compare/v0.11.0...HEAD
[0.11.0]: https://github.com/aichadigital/lara-verifactu/compare/v0.10.0...v0.11.0
[0.1.0]: https://github.com/aichadigital/lara-verifactu/releases/tag/v0.1.0

