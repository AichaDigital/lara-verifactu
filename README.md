# Lara Verifactu

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aichadigital/lara-verifactu.svg?style=flat-square)](https://packagist.org/packages/aichadigital/lara-verifactu)
[![Total Downloads](https://img.shields.io/packagist/dt/aichadigital/lara-verifactu.svg?style=flat-square)](https://packagist.org/packages/aichadigital/lara-verifactu)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/AichaDigital/lara-verifactu/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/AichaDigital/lara-verifactu/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/AichaDigital/lara-verifactu/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/AichaDigital/lara-verifactu/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg?style=flat-square)](https://phpstan.org/)
[![PHP Version](https://img.shields.io/packagist/php-v/aichadigital/lara-verifactu.svg?style=flat-square)](https://packagist.org/packages/aichadigital/lara-verifactu)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x-red.svg?style=flat-square)](https://laravel.com)
[![License](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](LICENSE.md)

Laravel package for **VERI*FACTU (AEAT) compliance** — Spain's invoicing
records regulation (Real Decreto 1007/2023). It generates AEAT-conformant
chained fingerprints (huella), validation QR codes and registration XML,
and submits registration and cancellation records to the AEAT web service.

> **Status: 0.9.x — sandbox-validated beta.**
>
> Every artifact this package produces has been validated against the
> official AEAT specifications, and the full submission flow has been
> **accepted live by the AEAT external testing environment** (Pruebas
> Externas): real registration and cancellation records submitted with a
> representative certificate, answered `Correcto` with CSV, including
> AEAT-side validation of the chained fingerprint. Pending before 1.0:
> production hardening and high-volume testing.

## Conformance

- **Fingerprint (huella)** per the official hash spec v0.1.2 — the three
  official AEAT test vectors are part of the test suite.
- **QR** per the official QR spec v0.4.7: cotejo URL with exactly
  `nif`, `numserie`, `fecha`, `importe`; error correction level M.
- **XML** validated against the official `SuministroLR.xsd` in CI; the
  official schemas and WSDL are bundled with the package (offline SOAP
  client, endpoint forced per environment and certificate type).
- **Endpoints** from the official WSDL port bindings: `sello` certificates
  use the `www10`/`prewww10` hosts; `ciudadano`/`representante` use
  `www1`/`prewww1`.

### Regulatory deadlines

- **July 29, 2025**: mandatory for invoicing software vendors
- **January 1, 2026**: mandatory for companies
- **July 1, 2026**: mandatory for freelancers

## Requirements

- PHP 8.3+
- Laravel 12.x or 13.x
- Extensions: `soap`, `openssl`, `dom`, `libxml`
- A digital certificate (PKCS#12) for AEAT submission: `representante`,
  `sello` or `ciudadano`

## Installation

```bash
composer require aichadigital/lara-verifactu

php artisan verifactu:install
```

Configure your environment:

```bash
# Environment: production | sandbox (AEAT Pruebas Externas)
VERIFACTU_ENVIRONMENT=sandbox

# PKCS#12 certificate (keep it OUTSIDE the project tree)
VERIFACTU_CERT_PATH=/secure/path/company-representative.p12
VERIFACTU_CERT_PASSWORD=secret
VERIFACTU_CERT_TYPE=representante   # ciudadano | representante | sello

# Issuer (obligado a expedir factura) — must match the AEAT census
VERIFACTU_COMPANY_TAX_ID=B00000000
VERIFACTU_COMPANY_NAME="YOUR COMPANY SL"
```

> **Note:** macOS Keychain exports `.p12` files with legacy RC2-40
> encryption that OpenSSL 3.x rejects (misleadingly reported as a wrong
> password). Re-package with modern encryption:
>
> ```bash
> /usr/bin/openssl pkcs12 -in legacy.p12 -nodes -out tmp.pem \
>   && openssl pkcs12 -export -in tmp.pem -out modern.p12 && rm tmp.pem
> ```

Verify your setup against AEAT:

```bash
php artisan verifactu:test-connection             # full check incl. mutual-TLS probe
php artisan verifactu:test-connection --cert-info # certificate details only
```

## Usage

### Facade

```php
use AichaDigital\LaraVerifactu\Facades\Verifactu;

// Register an invoice (creates the chained registry record, the QR and
// the XML; submits to AEAT unless told otherwise)
$registry = Verifactu::register($invoice);
$registry = Verifactu::register($invoice, submitToAeat: false);

// Cancel an invoice (RegistroAnulacion, chained like any other record)
$registry = Verifactu::cancel($invoice);

// Latest registry of an invoice (null if never registered)
$registry = Verifactu::status($invoice);

// AEAT cotejo QR for an invoice (SVG or PNG per config)
$qr = Verifactu::qr($invoice);

// Verify the integrity of the whole fingerprint chain
['valid' => $valid, 'errors' => $errors] = Verifactu::validateChain();
```

### Testing your integration

```php
use AichaDigital\LaraVerifactu\Facades\Verifactu;

Verifactu::fake();

// ... code under test ...

Verifactu::assertRegistered($invoice);
Verifactu::assertCancelled($invoice);
Verifactu::assertNotSent($otherInvoice);
```

### Artisan commands

```bash
php artisan verifactu:test-connection   # certificate + mutual-TLS check
php artisan verifactu:register {id}     # register an invoice
php artisan verifactu:retry-failed      # retry failed submissions
php artisan verifactu:verify-blockchain # verify the fingerprint chain
php artisan verifactu:status            # system status
```

### Custom invoice models

Any model implementing
`AichaDigital\LaraVerifactu\Contracts\InvoiceContract` can be registered —
the bundled `Invoice` model (native mode) is optional. See
`config/verifactu.php` for the model bindings.

## Architecture notes

- **VERI*FACTU mode does not sign records**: the chained fingerprint
  replaces the signature. XAdES signing is available behind
  `verifactu.signing.enabled` (default `false`) for the non-Verifactu
  modality.
- Cancellations are **links of the fingerprint chain** in their own right
  (`registry_type`), keeping the chain verifiable end to end.
- Submissions are sequential by design (dedicated `fiscal_verification`
  queue with a unique lock) to preserve chain ordering.
- `AceptadoConErrores` responses map to success: AEAT registered the
  record, so it must not be resubmitted; the error details are persisted.
- **Rectifications**: `TipoRectificativa` derives from
  `getRectificationType()` (`S` substitution / `I` incremental). A substitution
  (`S`) requires the substituted amounts via `getRectificationAmounts()` (native
  mode reads `metadata['rectification_amounts']`), emitted as
  `ImporteRectificacion`; a missing block raises `ValidationException`.
  `FacturasSustituidas` (invoice type `F3`, substitution of simplified invoices)
  is a distinct XSD concept and is **not supported yet**.

## Sandbox validation

The suite includes `tests/Feature/RealSandboxSubmissionTest.php`, which
performs a **real registration and cancellation against AEAT Pruebas
Externas**. It is skipped automatically unless a real certificate is
configured through the `VERIFACTU_*` environment variables — CI never
contacts AEAT.

```bash
set -a; source .env; set +a
vendor/bin/pest tests/Feature/RealSandboxSubmissionTest.php
```

## Testing & quality

```bash
composer test          # Pest
composer phpstan       # PHPStan level 8 (no baseline debt on new code)
composer format        # Laravel Pint
composer quality       # all of the above + coverage
```

## Security

If you discover a security vulnerability, please email
**security@aichadigital.com** instead of using the issue tracker. Never
commit certificates or credentials; keep your `.p12` outside the project
tree with restrictive permissions.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).

## Credits

- [Aicha Digital](https://github.com/AichaDigital)
- Built against the official [AEAT VERI*FACTU specifications](https://sede.agenciatributaria.gob.es/)
- Package skeleton by [Spatie](https://spatie.be/open-source)

---

<p align="center">
  Made with ❤️ by <a href="https://github.com/AichaDigital">Aicha Digital</a>
</p>
