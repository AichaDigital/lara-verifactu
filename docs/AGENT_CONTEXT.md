# Lara Verifactu - Package Context for AI Agents

> **Read this file first** to understand the package's purpose, architecture, and conventions.

## 🎯 Package Identity

**Lara Verifactu** is a Laravel package for **Spanish AEAT Verifactu compliance**. It handles the digital signing, blockchain chaining, and submission of invoices to Spain's tax authority.

### Critical Information

| Item | Value |
|------|-------|
| **Version** | dev-main (targeting v1.0 for Dec 15, 2025) |
| **PHP** | ^8.3 |
| **Laravel** | ^11.0 \| ^12.0 |
| **License** | AGPL-3.0-or-later |
| **Progress** | ~92% (7 of 9 phases completed) |

### Regulatory Deadlines

- **July 29, 2025**: Mandatory for invoicing software
- **January 1, 2026**: Mandatory for companies
- **July 1, 2026**: Mandatory for freelancers

### Ecosystem Context

Lara Verifactu is part of the **AichaDigital billing ecosystem**:

```
aichadigital/
├── larabill/        # Core billing
├── lara100/         # Base-100 monetary calculations
├── lararoi/         # EU VAT/ROI verification
├── lara-verifactu/  # Spain AEAT VeriFACTU (THIS PACKAGE)
└── laratickets/     # Support tickets
```

**Primary staging environment**: [Larafactu](https://github.com/AichaDigital/larafactu)

## 🏗️ Architecture

### Core Components

1. **Invoice Registration**: Convert invoices to AEAT XML format
2. **Digital Signature**: XAdES-EPES signing with .p12/.pfx certificates
3. **Blockchain Chain**: SHA-256 hash chain for invoice integrity
4. **SOAP Client**: Real AEAT web service integration
5. **QR Code Generation**: Citizen validation codes
6. **Queue Processing**: Async submission via Laravel queues

### Key Models

```
VerifactuInvoice     → Invoice registration record
VerifactuSubmission  → AEAT submission attempt
VerifactuChain       → Blockchain hash chain
```

### Agnostic Design

The package works with **any invoice model**. Configure your model in `config/verifactu.php`:

```php
'models' => [
    'invoice' => \App\Models\Invoice::class,
    // Or use the package's native model
    'invoice' => \AichaDigital\LaraVerifactu\Models\VerifactuInvoice::class,
],
```

## 📁 Package Structure

```
lara-verifactu/
├── config/verifactu.php        # Package configuration
├── database/migrations/        # Database migrations
├── docs/
│   ├── verifactu/              # AEAT specification docs
│   ├── development/            # Development guides
│   └── internal/               # Internal architecture
├── resources/
│   ├── lang/                   # Translations (es)
│   └── views/                  # QR templates
├── src/
│   ├── Contracts/              # Interfaces
│   ├── DTOs/                   # Data Transfer Objects
│   ├── Enums/                  # Status enums
│   ├── Events/                 # Domain events
│   ├── Exceptions/             # Custom exceptions
│   ├── Jobs/                   # Queue jobs
│   ├── Models/                 # Eloquent models
│   ├── Services/               # Business logic
│   │   ├── Signature/          # XAdES signing
│   │   ├── Soap/               # AEAT client
│   │   └── Chain/              # Blockchain
│   └── Support/                # Helpers
└── tests/                      # Pest tests
```

## 🔧 Key Services

### VerifactuService

Main entry point for invoice registration:

```php
use AichaDigital\LaraVerifactu\Services\VerifactuService;

$service = app(VerifactuService::class);
$result = $service->registerInvoice($invoice);
```

### SignatureService

Handles XAdES-EPES digital signatures:

```php
use AichaDigital\LaraVerifactu\Services\Signature\SignatureService;

$service = app(SignatureService::class);
$signedXml = $service->sign($xml, $certificatePath, $password);
```

### ChainService

Manages the blockchain hash chain:

```php
use AichaDigital\LaraVerifactu\Services\Chain\ChainService;

$service = app(ChainService::class);
$hash = $service->calculateHash($invoice);
```

## ⚙️ Configuration

### Environment Variables

```env
# AEAT Certificate
VERIFACTU_CERTIFICATE_PATH=/path/to/certificate.p12
VERIFACTU_CERTIFICATE_PASSWORD=your_password

# AEAT Environment
VERIFACTU_ENVIRONMENT=sandbox  # sandbox or production

# Company Information
VERIFACTU_COMPANY_NIF=B12345678
VERIFACTU_COMPANY_NAME="Your Company S.L."

# Software Registration (AEAT assigned)
VERIFACTU_SOFTWARE_ID=your_software_id
VERIFACTU_SOFTWARE_NAME="Your Software"
VERIFACTU_SOFTWARE_VERSION="1.0.0"
```

## 🧪 Testing

```bash
# Run all tests
composer test

# Run specific tests
composer test -- --filter=Signature

# Static analysis (level 8)
vendor/bin/phpstan analyse
```

## ⚠️ Important Conventions

### Invoice Immutability

Once an invoice is registered with AEAT, it **cannot be modified**. Any changes require a rectification invoice.

### Certificate Security

**NEVER commit certificates to Git**. The `.gitignore` excludes `*.p12` and `*.pfx` files.

### Sequential Processing

Invoice submissions must maintain order for the blockchain chain. The package handles this automatically via queues.

### Error Handling

AEAT responses can include various error codes. Always check:

```php
if ($result->isSuccessful()) {
    // Invoice registered
} else {
    // Handle $result->getErrors()
}
```

## 🚫 Anti-Patterns

**DON'T**:
- ❌ Commit certificates to Git
- ❌ Modify registered invoices
- ❌ Skip the blockchain chain
- ❌ Process invoices out of order
- ❌ Use production certificates in development

**DO**:
- ✅ Use sandbox environment for testing
- ✅ Handle AEAT errors gracefully
- ✅ Use queue processing for submissions
- ✅ Validate XML before signing
- ✅ Keep certificates secure

## 📚 Key Documentation

| File | Purpose |
|------|---------|
| `docs/verifactu/` | AEAT specification documents |
| `docs/development/` | Development guides |
| `INSTALLATION.md` | Installation instructions |
| `CHANGELOG.md` | Version history |

## 🎯 Integration with Larabill

When used with Larabill, the adapter handles conversion:

```php
use AichaDigital\Larabill\Services\Adapters\VerifactuAdapter;

$adapter = app(VerifactuAdapter::class);
$verifactuData = $adapter->convert($larabillInvoice);
```

---

**Remember**: This package handles legal compliance. Test thoroughly in sandbox before production. Target: v1.0 stable by December 15, 2025.

