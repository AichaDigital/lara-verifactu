# Lara Verifactu - Usage Examples

This document provides longer-form usage examples for the Lara Verifactu
package, complementing the [README](../../README.md).

## Table of Contents

- [Installation & Setup](#installation--setup)
- [Basic Usage](#basic-usage)
- [Native Mode](#native-mode)
- [Agnostic Mode](#agnostic-mode)
- [Working with Commands](#working-with-commands)
- [Working with Jobs](#working-with-jobs)
- [Working with Events](#working-with-events)
- [Advanced Scenarios](#advanced-scenarios)

## Installation & Setup

### Step 1: Install the Package

```bash
composer require aichadigital/lara-verifactu
```

### Step 2: Install Package Assets

```bash
php artisan verifactu:install
```

This command publishes the configuration file and the database migrations,
and asks whether to run migrations immediately.

### Step 3: Configure Environment Variables

```env
# Operating mode: native (bundled models) or custom — see the "Agnostic Mode"
# caveat below before relying on custom mode end to end
VERIFACTU_MODE=native

# AEAT environment
VERIFACTU_ENVIRONMENT=sandbox   # sandbox (AEAT Pruebas Externas) | production

# Issuer (obligado a expedir factura) — must match the AEAT census
VERIFACTU_COMPANY_TAX_ID=B12345678
VERIFACTU_COMPANY_NAME="My Company SL"

# Certificate settings (PKCS#12, keep OUTSIDE the project tree)
VERIFACTU_CERT_PATH=/secure/path/certificate.p12
VERIFACTU_CERT_PASSWORD=your-secret-password
VERIFACTU_CERT_TYPE=representante   # ciudadano | representante | sello

# Queue
VERIFACTU_QUEUE_CONNECTION=redis
VERIFACTU_QUEUE=fiscal_verification

# Retry settings
VERIFACTU_RETRY_MAX_ATTEMPTS=3
VERIFACTU_RETRY_DELAY=60
```

See `config/verifactu.php` for the full set of options (logging, caching,
batching, chain-lock timeouts).

### Step 4: Run Migrations

```bash
php artisan migrate
```

## Basic Usage

### Native Mode - Create and Register Invoice

```php
use AichaDigital\LaraVerifactu\Facades\Verifactu;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;

// Create an invoice
$invoice = Invoice::create([
    'serie' => 'A',
    'number' => '2026-001',
    'issue_datetime' => now(),
    'type' => InvoiceTypeEnum::COMPLETE, // F1
    'base_amount' => 100.00,
    'tax_amount' => 21.00,
    'total_amount' => 121.00,
    'description' => 'Web development services',
    'recipient_nif' => '12345678A',
    'recipient_name' => 'John Doe',
    'recipient_country' => 'ES',
]);

// Add tax breakdown
$invoice->breakdowns()->create([
    'tax_type' => TaxTypeEnum::IVA,
    'tax_rate' => 21.00,
    'base_amount' => 100.00,
    'tax_amount' => 21.00,
]);

// Register with Verifactu (with AEAT submission)
$registry = Verifactu::register($invoice, submitToAeat: true);

echo "Invoice registered successfully!\n";
echo "Registry Number: {$registry->getRegistryNumber()}\n";
echo "Hash: {$registry->getHash()}\n";
echo "QR URL: {$registry->getQrUrl()}\n";
```

### Register Without Immediate AEAT Submission

```php
// Register locally only (submit to AEAT later via queue)
$registry = Verifactu::register($invoice, submitToAeat: false);

// Submit later — dispatches the same job the queue-based flow uses
// (see "Working with Jobs" below)
\AichaDigital\LaraVerifactu\Jobs\SubmitRegistryToAeatJob::dispatch($registry->getId());
```

## Native Mode

### Complete Invoice with Multiple Tax Breakdowns

```php
use AichaDigital\LaraVerifactu\Facades\Verifactu;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;

$invoice = Invoice::create([
    'serie' => 'F',
    'number' => '2026-042',
    'issue_datetime' => now(),
    'type' => InvoiceTypeEnum::COMPLETE,
    'description' => 'Mixed products and services',
    'recipient_nif' => '12345678A',
    'recipient_name' => 'Acme Corp',
]);

// Add IVA 21% (products)
$invoice->breakdowns()->create([
    'tax_type' => TaxTypeEnum::IVA,
    'tax_rate' => 21.00,
    'base_amount' => 500.00,
    'tax_amount' => 105.00,
]);

// Add IVA 10% (reduced rate services)
$invoice->breakdowns()->create([
    'tax_type' => TaxTypeEnum::IVA,
    'tax_rate' => 10.00,
    'base_amount' => 200.00,
    'tax_amount' => 20.00,
]);

// Calculate totals
$invoice->base_amount = $invoice->breakdowns->sum('base_amount');
$invoice->tax_amount = $invoice->breakdowns->sum('tax_amount');
$invoice->total_amount = $invoice->base_amount + $invoice->tax_amount;
$invoice->save();

// Register
Verifactu::register($invoice);
```

### Rectification Invoice

```php
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;

// Original invoice
$originalInvoice = Invoice::find(1);

// Create rectification
$rectification = Invoice::create([
    'serie' => 'R',
    'number' => '2026-001',
    'issue_datetime' => now(),
    'type' => InvoiceTypeEnum::RECTIFICATIVE, // R1
    'rectification_type' => 'S', // Substitution
    'base_amount' => 100.00,
    'tax_amount' => 21.00,
    'total_amount' => 121.00,
    'description' => 'Rectification of invoice A-2026-042',
    'metadata' => [
        // The invoices this one rectifies (AEAT FacturasRectificadas).
        'rectified_invoices' => [
            ['number' => $originalInvoice->number, 'issue_date' => $originalInvoice->issue_datetime],
        ],
        // Substitution (S) rectifications MUST carry the substituted amounts
        // (AEAT ImporteRectificacion / DesgloseRectificacionType). Omitting them
        // throws ValidationException before the XML is built.
        'rectification_amounts' => [
            'base' => 100.00,
            'tax' => 21.00,
            // 'surcharge' => 5.20, // optional (recargo de equivalencia)
        ],
    ],
]);

$rectification->breakdowns()->create([
    'tax_type' => TaxTypeEnum::IVA,
    'tax_rate' => 21.00,
    'base_amount' => 100.00,
    'tax_amount' => 21.00,
]);

Verifactu::register($rectification);
```

> **Substitution (`S`) vs incremental (`I`):** set `rectification_type` to `'S'`
> or `'I'`. A substitution rectification MUST include
> `metadata['rectification_amounts']` (`base`, `tax`, optional `surcharge`) —
> otherwise registration throws `ValidationException` before building the XML,
> because AEAT rejects an `S` without `ImporteRectificacion`.
>
> **F3 (substitution of simplified invoices)** is supported via
> `InvoiceTypeEnum::SUBSTITUTE`: set `type` to F3, provide a recipient (AEAT rule
> 1189 requires `Destinatarios` for F3) and the substituted invoices in
> `metadata['substituted_invoices']` (`[['number' => ..., 'issue_date' => ...]]`).
> It emits `FacturasSustituidas`; without a recipient or substituted invoices it
> throws `ValidationException`.

## Agnostic Mode

### Integrate with an Existing Invoice Model

> **Current limitation:** the FK from `verifactu_registries.invoice_id` to
> `verifactu_invoices` and the `Registry::invoice()` Eloquent relation are
> hardcoded against the native `Invoice` model — they don't yet honor
> `config('verifactu.models.invoice')`. A genuinely external model (not
> backed by the `verifactu_invoices` table) will fail that FK constraint when
> you call `Verifactu::register()`. This is tracked in AID-344; until it
> lands, integrate a pre-existing invoice system by mapping into the native
> `Invoice` model behind your own service layer, rather than relying on
> `custom` mode end-to-end.
>
> The sketch below shows the *shape* of an `InvoiceContract` implementation —
> it deliberately implements only a handful of representative methods for
> readability. The interface has ~18 methods; see
> `src/Contracts/InvoiceContract.php`, `src/Contracts/RecipientContract.php`
> and `src/Contracts/InvoiceBreakdownContract.php` for the full, authoritative
> list before writing a real implementation.

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class Invoice extends Model implements InvoiceContract
{
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIssuerTaxId(): string
    {
        return config('verifactu.company.tax_id');
    }

    public function getInvoiceNumber(): string
    {
        return trim(($this->serie ? "{$this->serie}-" : '').$this->invoice_number);
    }

    public function getIssueDatetime(): Carbon
    {
        return $this->issue_date;
    }

    public function getType(): InvoiceTypeEnum
    {
        return InvoiceTypeEnum::from($this->invoice_type);
    }

    public function getTotalAmount(): float
    {
        return (float) $this->total;
    }

    public function getTaxAmount(): float
    {
        return (float) $this->tax;
    }

    public function getRegimeType(): RegimeTypeEnum
    {
        return RegimeTypeEnum::GENERAL;
    }

    /**
     * @return Collection<int, \AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract>
     */
    public function getBreakdowns(): Collection
    {
        return $this->items->map(function ($item) {
            return new class($item) implements \AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract {
                public function __construct(private $item) {}

                public function getTaxType(): \AichaDigital\LaraVerifactu\Enums\TaxTypeEnum
                {
                    return \AichaDigital\LaraVerifactu\Enums\TaxTypeEnum::IVA;
                }

                public function getTaxRate(): float
                {
                    return (float) $this->item->tax_rate;
                }

                public function getBaseAmount(): float
                {
                    return (float) $this->item->base;
                }

                public function getTaxAmount(): float
                {
                    return (float) $this->item->tax;
                }

                // getSurchargeRate(), getSurchargeAmount(), isExempt(),
                // getExemptionReason(), getCalificacion() also required —
                // see InvoiceBreakdownContract.
            };
        });
    }

    public function getRecipient(): ?\AichaDigital\LaraVerifactu\Contracts\RecipientContract
    {
        if (! $this->customer) {
            return null;
        }

        return new class($this->customer) implements \AichaDigital\LaraVerifactu\Contracts\RecipientContract {
            public function __construct(private $customer) {}

            public function getNif(): ?string
            {
                return $this->customer->tax_id;
            }

            public function getName(): ?string
            {
                return $this->customer->name;
            }

            public function getCountry(): ?string
            {
                return $this->customer->country ?? 'ES';
            }

            public function getIdType(): ?\AichaDigital\LaraVerifactu\Enums\IdTypeEnum
            {
                return \AichaDigital\LaraVerifactu\Enums\IdTypeEnum::NIF;
            }

            public function getId(): ?string
            {
                return $this->customer->tax_id;
            }
        };
    }

    // ... implement the remaining InvoiceContract methods
    // (getSerie, getNumber, getRectificationType, getRectifiedInvoices,
    // getRectificationAmounts, getSubstitutedInvoices, getPreviousInvoiceId,
    // getPreviousHash, getCurrency, hasRecipient, getDescription, getMetadata,
    // isSimplified, getInvoiceType, the deprecated getIssueDate/getIssueTime)
}
```

### Register an Existing Invoice

```php
use App\Models\Invoice;
use AichaDigital\LaraVerifactu\Facades\Verifactu;

$invoice = Invoice::find(42);

$registry = Verifactu::register($invoice);

echo "Registered! Registry: {$registry->getRegistryNumber()}\n";
```

## Working with Commands

### Register Single Invoice

```bash
php artisan verifactu:register 42
```

### Register All Pending Invoices

```bash
php artisan verifactu:register --all
```

### Register Without Submitting to AEAT

```bash
php artisan verifactu:register --all --no-submit
```

### Retry Failed Submissions

```bash
php artisan verifactu:retry-failed --max-attempts=3 --limit=50
```

### Verify Blockchain Integrity

```bash
php artisan verifactu:verify-blockchain
```

### Check System Status

```bash
php artisan verifactu:status --recent=20
```

## Working with Jobs

### Dispatch Invoice Registration Job

```php
use AichaDigital\LaraVerifactu\Jobs\ProcessInvoiceRegistrationJob;

ProcessInvoiceRegistrationJob::dispatch($invoice->id);

ProcessInvoiceRegistrationJob::dispatch($invoice->id, submitToAeat: false);

ProcessInvoiceRegistrationJob::dispatch($invoice->id)
    ->delay(now()->addMinutes(5));
```

### Submit Registry to AEAT (Queue)

```php
use AichaDigital\LaraVerifactu\Jobs\SubmitRegistryToAeatJob;

SubmitRegistryToAeatJob::dispatch($registry->getId());
```

### Schedule Batch Retry

```php
use AichaDigital\LaraVerifactu\Jobs\RetryFailedRegistriesJob;

// In your scheduler
$schedule->job(new RetryFailedRegistriesJob(maxAttempts: 3, limit: 100))
    ->dailyAt('02:00');
```

### Schedule Blockchain Verification

```php
use AichaDigital\LaraVerifactu\Jobs\VerifyBlockchainIntegrityJob;

$schedule->job(new VerifyBlockchainIntegrityJob)
    ->dailyAt('03:00');
```

## Working with Events

### Listen to Invoice Registration

```php
use AichaDigital\LaraVerifactu\Events\InvoiceRegisteredEvent;
use Illuminate\Support\Facades\Event;

Event::listen(InvoiceRegisteredEvent::class, function (InvoiceRegisteredEvent $event) {
    // $event->invoice, $event->registry, $event->submittedToAeat
    $event->invoice->getMetadata();
});
```

### Listen to AEAT Submission Success

```php
use AichaDigital\LaraVerifactu\Events\RegistrySubmittedEvent;

Event::listen(RegistrySubmittedEvent::class, function ($event) {
    Log::info('Registry submitted to AEAT', [
        'registry_number' => $event->registry->getRegistryNumber(),
        'csv' => $event->registry->getAeatCsv(),
    ]);
});
```

### Listen to Submission Failures

```php
use AichaDigital\LaraVerifactu\Events\RegistryFailedEvent;

Event::listen(RegistryFailedEvent::class, function (RegistryFailedEvent $event) {
    Log::error('Registry submission failed', [
        'registry_id' => $event->registry->getId(),
        'error' => $event->error,
        'attempt' => $event->attempt,
    ]);
});
```

### Listen to Blockchain Verification

```php
use AichaDigital\LaraVerifactu\Events\BlockchainVerifiedEvent;

Event::listen(BlockchainVerifiedEvent::class, function ($event) {
    if (! $event->result['valid']) {
        // Critical: blockchain integrity compromised!
        Log::channel('security')->critical('Blockchain integrity check failed', [
            'errors' => $event->result['errors'],
        ]);
    }
});
```

> Verify each event's exact constructor/public properties against
> `src/Events/*.php` before wiring a listener — the snippets above show the
> intended shape, not a guaranteed-stable payload contract.

## Advanced Scenarios

### Batch Registration with Progress Tracking

```php
use AichaDigital\LaraVerifactu\Facades\Verifactu;
use AichaDigital\LaraVerifactu\Models\Invoice;

$invoices = Invoice::whereDoesntHave('registry')
    ->whereDate('issue_datetime', today())
    ->get();

$results = ['success' => [], 'failed' => []];

foreach ($invoices as $invoice) {
    try {
        Verifactu::register($invoice);
        $results['success'][] = $invoice->id;
    } catch (\Throwable $e) {
        $results['failed'][] = [
            'invoice_id' => $invoice->id,
            'error' => $e->getMessage(),
        ];
    }
}

Log::info('Batch registration completed', $results);
```

Or use the built-in batch helper, which returns aggregate counts:

```php
['success' => $success, 'failed' => $failed, 'registries' => $registries]
    = Verifactu::sendBatch($invoices);
```

### Verify Chain Integrity

```php
use AichaDigital\LaraVerifactu\Facades\Verifactu;

['valid' => $valid, 'errors' => $errors] = Verifactu::validateChain();

if (! $valid) {
    Log::channel('security')->critical('Fingerprint chain broken', ['errors' => $errors]);
}
```

---

## Need Help?

- Check the [README](../../README.md) for status, the support matrix, and security notes
- Review [CHANGELOG.md](../../CHANGELOG.md) for version history
- Open an issue on [GitHub](https://github.com/AichaDigital/lara-verifactu/issues)
- Contact support: support@aichadigital.es
