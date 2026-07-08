# Lara Verifactu - Package Context for AI Agents

> **Read this file first** to understand the package's purpose, architecture, and conventions.

## 🎯 Package Identity

**Lara Verifactu** is a Laravel package for **Spanish AEAT VERI\*FACTU compliance** (Real Decreto 1007/2023). It generates AEAT-conformant chained fingerprints (huella), validation QR codes and registration XML, and submits registration/cancellation records to the AEAT web service.

### Critical Information

| Item | Value |
|------|-------|
| **Version** | v1.0.0 — stable (public API + DB schema frozen under SemVer, see `VERSIONING.md`) |
| **PHP** | ^8.3 |
| **Laravel** | ^12.0 \| ^13.0 |
| **License** | MIT |
| **Scope** | Honest core (F1/F2/F3/R1/R5, régimen 01) — see the README support matrix for what's supported vs. rejected fail-loud; post-1.0 coverage tracked in AID-209 |

### Regulatory Deadlines

Extended to 2027 by [Real Decreto-ley 15/2025](https://www.boe.es/buscar/doc.php?id=BOE-A-2025-24446) (2 December 2025):

- **July 29, 2025**: invoicing software / SIF must already meet the requirements
- **January 1, 2027**: mandatory for Corporate Income Tax payers (was 2026)
- **July 1, 2027**: mandatory for the remaining obligated parties, including freelancers (was 2026)

### Ecosystem Context

Lara Verifactu lives in the AichaDigital umbrella but is a **self-contained island**: no FK into any consumer's tables, no compile-time dependency on another AichaDigital package. It owns its own `verifactu_*` tables and speaks `InvoiceContract` (an interface), not a concrete consumer class.

```
aichadigital/
├── larabill/        # Core billing — a KNOWN CONSUMER of this package
├── lara100/         # Base-100 monetary calculations
├── lararoi/         # EU VAT/ROI verification
├── lara-verifactu/  # Spain AEAT VERI*FACTU (THIS PACKAGE)
└── laratickets/     # Support tickets
```

`larabill` depends on this package (not the other way around) and adapts its
own `Invoice` model into this package's native `Invoice` via its own
`AichaDigital\Larabill\Services\Adapters\VerifactuAdapter` — that adapter's
API belongs to larabill, not here; don't copy its method signatures into this
file, they can change independently.

**Primary staging environment**: [Larafactu](https://github.com/AichaDigital/larafactu)

### Development Setup (Local)

```
/Users/abkrim/
├── development/packages/aichadigital/  # Package SOURCE (edit here)
│   ├── larabill/
│   ├── lararoi/
│   ├── lara-verifactu/                 # THIS PACKAGE
│   └── laratickets/
└── SitesLR12/larafactu/                # Staging APP
    └── packages/aichadigital/          # Symlinks to source
```

**Workflow**: Edit in source → Test in Larafactu → Commit package first

## 🐛 Debugging Strategy (CRITICAL)

### ALWAYS Read Logs First

**RULE**: Before assuming the cause of ANY error, **READ THE ACTUAL LOGS**.

```bash
# In Larafactu staging
cd /Users/abkrim/SitesLR12/larafactu

# Clear logs for clean output
rm storage/logs/laravel.log && touch storage/logs/laravel.log

# Reproduce the error, then read
cat storage/logs/laravel.log | head -50
```

Browser error messages are often **symptoms**, not root causes. Always check logs first.

## 🏗️ Architecture

### Core Components

1. **Invoice Registration**: convert invoices to AEAT `RegistroAlta`/`RegistroAnulacion` XML
2. **Hash Chain**: SHA-256 chained fingerprint per the official huella spec
3. **SOAP Client**: real AEAT web service integration (offline WSDL, mTLS via the certificate)
4. **QR Code Generation**: AEAT cotejo validation codes
5. **Queue Processing**: async submission via a dedicated `fiscal_verification` queue with a unique lock (submissions are sequential by design, to preserve chain ordering)

### Key Models (native mode)

```
Invoice            → the fiscal invoice snapshot (table: verifactu_invoices)
InvoiceBreakdown   → per-tax-rate breakdown (table: verifactu_invoice_breakdowns)
Registry           → a chain link: RegistroAlta or RegistroAnulacion (table: verifactu_registries)
```

### Agnostic design — real scope

The package supports a `native` / `custom` mode switch
(`config('verifactu.mode')`) and a `models` binding array so a consumer can
point `invoice`/`breakdown`/`registry` at their own classes. **In practice
this is only partially wired**: `Registry::invoice()` and the
`registries.invoice_id` foreign key are hardcoded against the native
`Invoice` model and the `verifactu_invoices` table, so a genuinely external
custom model fails the FK constraint on `register()` (tracked in AID-209).
Don't advertise `custom` mode as a finished, decoupled integration path when
working on docs or examples — see the README's "Custom invoice models"
section for the exact caveat.

## 📁 Package Structure

```
lara-verifactu/
├── config/verifactu.php        # Package configuration
├── database/migrations/        # Database migrations
├── docs/
│   ├── verifactu/               # AEAT specification docs (source of truth)
│   ├── development/             # Usage examples for consumers
│   ├── notes/                   # Dated cross-package design notes
│   └── superpowers/             # Dated engineering plans/specs tied to closed tickets
├── resources/
│   ├── lang/                   # Translations (es)
│   └── views/                  # QR templates
├── src/
│   ├── Contracts/               # Interfaces (InvoiceContract, RegistryContract, ...)
│   ├── Enums/                   # AEAT-aligned status/type enums
│   ├── Events/                  # Domain events
│   ├── Exceptions/               # Custom exceptions (fail-loud ValidationException, ...)
│   ├── Jobs/                     # Queue jobs
│   ├── Models/                   # Eloquent models (native mode)
│   ├── Services/                 # Internal implementation (see below)
│   ├── Facades/Verifactu.php     # The public API surface
│   └── Verifactu.php             # Facade-bound service, orchestrates the services below
└── tests/                        # Pest tests
```

## 🔧 Public API vs. internal services

**Consumers use the facade** — this is the only surface the README/CHANGELOG
guarantee under SemVer:

```php
use AichaDigital\LaraVerifactu\Facades\Verifactu;

$registry = Verifactu::register($invoice);
$registry = Verifactu::cancel($invoice);
$registry = Verifactu::status($invoice);
$qr = Verifactu::qr($invoice);
['valid' => $valid, 'errors' => $errors] = Verifactu::validateChain();
```

**Internal services** (`src/Services/`) implement the above and are not part
of the public contract — they can be refactored without a MAJOR bump as long
as the facade's behavior is unchanged:

```
HashGenerator          # chained fingerprint (huella)
XmlBuilder             # RegistroAlta / RegistroAnulacion XML
QrGenerator            # AEAT cotejo QR
AeatClient             # SOAP submission
AeatResponseParser     # AEAT response → outcome classification
CertificateManager     # PKCS#12 loading/validation
ConfigEndpointResolver # WSDL/endpoint selection per environment + cert type
InvoiceRegistrar       # orchestrates registration end to end
RegistryManager        # chain head, hash-chain reads/writes
```

If you're modifying the package itself, work against these services directly
and use the facade methods for anything a consumer would call.

## ⚙️ Configuration

Real environment variables from `config/verifactu.php` (not exhaustive — see
that file for the full set, including logging, caching, batching and
retry/lock tuning):

```env
# Mode: native (bundled models) or custom (see the agnostic-design caveat above)
VERIFACTU_MODE=native

# AEAT environment
VERIFACTU_ENVIRONMENT=sandbox   # sandbox | production

# Issuer (obligado a expedir factura)
VERIFACTU_COMPANY_TAX_ID=B12345678
VERIFACTU_COMPANY_NAME="Your Company S.L."

# Certificate (PKCS#12, keep OUTSIDE the project tree)
VERIFACTU_CERT_PATH=/secure/path/certificate.p12
VERIFACTU_CERT_PASSWORD=your_password
VERIFACTU_CERT_TYPE=representante   # ciudadano | representante | sello

# Queue
VERIFACTU_QUEUE_CONNECTION=redis
VERIFACTU_QUEUE=fiscal_verification
```

## 🧪 Testing

```bash
composer test          # Pest (serial — parallel testing is intentionally disabled, AID-259)
composer phpstan        # PHPStan level 8, memory-limit=1G
composer format          # Laravel Pint
```

The suite runs against MariaDB/MySQL, not SQLite — see the README's "Running
the suite" section for local setup.

## ⚠️ Important Conventions

### Invoice Immutability

Once a `Registry` is created for an invoice, its historical XML is sealed —
any change requires a rectification (`R1`/`R5`) or, for a rejected record, an
amend-by-rechazo re-send (AID-137), never an in-place edit.

### Certificate Security

**NEVER commit certificates to Git**. `.gitignore` excludes `*.p12`/`*.pfx`.

### Sequential Processing

Submissions must stay ordered for the hash chain — handled by the dedicated
`fiscal_verification` queue plus the chain-fork lock (AID-258), not by the
caller.

### Error Handling

```php
['valid' => $valid, 'errors' => $errors] = Verifactu::validateChain();

if (! $valid) {
    // $errors: array<string> describing the broken link(s)
}
```

`AceptadoConErrores` responses map to success (AEAT registered the record);
a genuine `EstadoRegistro = Incorrecto` rejection is classified `REJECTED`
(AID-257), which is the precondition for the amend-by-rechazo flow.

## 🚫 Anti-Patterns

**DON'T**:
- ❌ Commit certificates to Git
- ❌ Modify a sealed `Registry`'s historical XML
- ❌ Bypass the `fiscal_verification` queue for submissions
- ❌ Advertise `custom` mode as a finished integration path (see the caveat above)
- ❌ Use production certificates in development

**DO**:
- ✅ Use the sandbox environment (AEAT Pruebas Externas) for testing
- ✅ Use `Verifactu::fake()` + `assertRegistered`/`assertCancelled`/`assertNotSent` in consumer tests
- ✅ Read the honest-core support matrix in the README before assuming an AEAT case is covered
- ✅ Keep certificates secure, outside the project tree

## 📚 Key Documentation

| File | Purpose |
|------|---------|
| `README.md` | Status, support matrix, install, usage, security |
| `docs/verifactu/` | Official AEAT specification documents (source of truth) |
| `docs/development/USAGE_EXAMPLES.md` | Longer-form usage examples |
| `CHANGELOG.md` | Version history |
| `VERSIONING.md` | SemVer + Laravel-compatibility strategy |

---

**Remember**: this package handles legal compliance. Cross-check regulatory
claims against `docs/verifactu/` (the official AEAT files), never against an
external prompt or a third-party repo (see the AEAT-source-of-truth
principle). Test thoroughly in sandbox before production.
