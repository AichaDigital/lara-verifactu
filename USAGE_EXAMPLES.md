# Lara Verifactu - Usage Examples

This document provides comprehensive usage examples for the Lara Verifactu package.

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

This command will:
- Publish the configuration file
- Publish database migrations
- Ask if you want to run migrations immediately

### Step 3: Configure Environment Variables

Add the following to your `.env` file:

```env
# Operating mode: native or custom
VERIFACTU_MODE=native

# AEAT Environment: production or sandbox
VERIFACTU_ENVIRONMENT=sandbox

# Company details
VERIFACTU_COMPANY_TAX_ID=B12345678
VERIFACTU_COMPANY_NAME="My Company SL"

# Certificate settings
VERIFACTU_CERT_PATH=/path/to/certificate.pfx
VERIFACTU_CERT_PASSWORD=your-secret-password

# Queue settings
VERIFACTU_QUEUE_CONNECTION=redis
VERIFACTU_QUEUE_NAME=verifactu

# Retry settings
VERIFACTU_RETRY_MAX_ATTEMPTS=3
VERIFACTU_RETRY_DELAY=60
```

### Step 4: Run Migrations

```bash
php artisan migrate
```

## Basic Usage

### Native Mode - Create and Register Invoice

```php
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\InvoiceBreakdown;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;

// Create an invoice
$invoice = Invoice::create([
    'serie' => 'A',
    'number' => '2025-001',
    'issue_date' => now(),
    'issue_time' => now(),
    'type' => InvoiceTypeEnum::COMPLETE,
    'simplified' => false,
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
$registrar = app(InvoiceRegistrar::class);
$registry = $registrar->register($invoice, submitToAeat: true);

echo "Invoice registered successfully!\n";
echo "Registry Number: {$registry->getRegistryNumber()}\n";
echo "Hash: {$registry->getHash()}\n";
echo "QR URL: {$registry->getQrUrl()}\n";
```

### Register Without Immediate AEAT Submission

```php
// Register locally only (submit to AEAT later via queue)
$registry = $registrar->register($invoice, submitToAeat: false);

// Submit later
$registrar->submitToAeat($registry);
```

## Native Mode

### Complete Invoice with Multiple Tax Breakdowns

```php
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;

$invoice = Invoice::create([
    'serie' => 'F',
    'number' => '2025-042',
    'issue_date' => now(),
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
app(InvoiceRegistrar::class)->register($invoice);
```

### Rectification Invoice

```php
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;

// Original invoice
$originalInvoice = Invoice::find(1);

// Create rectification
$rectification = Invoice::create([
    'serie' => 'R',
    'number' => '2025-001',
    'issue_date' => now(),
    'type' => InvoiceTypeEnum::RECTIFICATIVE,
    'rectification_type' => 'S', // Substitution
    'base_amount' => 100.00,
    'tax_amount' => 21.00,
    'total_amount' => 121.00,
    'description' => 'Rectification of invoice A-2025-042',
    'metadata' => [
        'original_invoice_id' => $originalInvoice->id,
        'original_invoice_number' => $originalInvoice->number,
        'reason' => 'Error in amount',
    ],
]);

// Add breakdown
$rectification->breakdowns()->create([
    'tax_type' => TaxTypeEnum::IVA,
    'tax_rate' => 21.00,
    'base_amount' => 100.00,
    'tax_amount' => 21.00,
]);

// Register
app(InvoiceRegistrar::class)->register($rectification);
```

## Agnostic Mode

### Integrate with Existing Invoice Model

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;

class Invoice extends Model implements InvoiceContract
{
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSerie(): ?string
    {
        return $this->serie;
    }

    public function getNumber(): string
    {
        return $this->invoice_number;
    }

    public function getIssueDate(): \DateTimeInterface
    {
        return $this->issue_date;
    }

    public function getType(): InvoiceTypeEnum
    {
        return InvoiceTypeEnum::from($this->invoice_type);
    }

    public function getTotalAmount(): string
    {
        return (string) $this->total;
    }

    public function getTaxAmount(): string
    {
        return (string) $this->tax;
    }

    public function getBreakdowns(): iterable
    {
        return $this->items->map(function ($item) {
            return new class($item) implements \AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract {
                public function __construct(private $item) {}

                public function getTaxType(): \AichaDigital\LaraVerifactu\Enums\TaxTypeEnum
                {
                    return \AichaDigital\LaraVerifactu\Enums\TaxTypeEnum::IVA;
                }

                public function getTaxRate(): string
                {
                    return (string) $this->item->tax_rate;
                }

                public function getBaseAmount(): string
                {
                    return (string) $this->item->base;
                }

                public function getTaxAmount(): string
                {
                    return (string) $this->item->tax;
                }

                // ... implement other methods
            };
        });
    }

    public function getRecipient(): ?\AichaDigital\LaraVerifactu\Contracts\RecipientContract
    {
        if (!$this->customer) {
            return null;
        }

        return new class($this->customer) implements \AichaDigital\LaraVerifactu\Contracts\RecipientContract {
            public function __construct(private $customer) {}

            public function getTaxId(): string
            {
                return $this->customer->tax_id;
            }

            public function getName(): string
            {
                return $this->customer->name;
            }

            public function getCountryCode(): string
            {
                return $this->customer->country ?? 'ES';
            }

            public function getIdType(): \AichaDigital\LaraVerifactu\Enums\IdTypeEnum
            {
                return \AichaDigital\LaraVerifactu\Enums\IdTypeEnum::NIF;
            }
        };
    }

    // ... implement remaining methods from InvoiceContract
}
```

### Register Existing Invoice

```php
use App\Models\Invoice;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;

// Your existing invoice
$invoice = Invoice::find(42);

// Register with Verifactu
$registrar = app(InvoiceRegistrar::class);
$registry = $registrar->register($invoice);

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
# Retry up to 50 failed registries (max 3 attempts each)
php artisan verifactu:retry-failed --limit=50 --max-attempts=3
```

### Verify Blockchain Integrity

```bash
php artisan verifactu:verify-blockchain
```

### Check System Status

```bash
# Show status with last 20 registries
php artisan verifactu:status --limit=20
```

## Working with Jobs

### Dispatch Invoice Registration Job

```php
use AichaDigital\LaraVerifactu\Jobs\ProcessInvoiceRegistrationJob;

// Dispatch to queue
ProcessInvoiceRegistrationJob::dispatch($invoice->id);

// Dispatch with delay
ProcessInvoiceRegistrationJob::dispatch($invoice->id)
    ->delay(now()->addMinutes(5));

// Dispatch to specific queue
ProcessInvoiceRegistrationJob::dispatch($invoice->id)
    ->onQueue('high-priority');
```

### Submit Registry to AEAT (Queue)

```php
use AichaDigital\LaraVerifactu\Jobs\SubmitRegistryToAeatJob;

$registry = $invoice->registry;

SubmitRegistryToAeatJob::dispatch($registry->id);
```

### Schedule Batch Retry

```php
use AichaDigital\LaraVerifactu\Jobs\RetryFailedRegistriesJob;

// In your scheduler (app/Console/Kernel.php)
$schedule->job(new RetryFailedRegistriesJob(maxAttempts: 3, limit: 100))
    ->dailyAt('02:00');
```

### Schedule Blockchain Verification

```php
use AichaDigital\LaraVerifactu\Jobs\VerifyBlockchainIntegrityJob;

// Verify blockchain every night
$schedule->job(new VerifyBlockchainIntegrityJob)
    ->dailyAt('03:00');
```

## Working with Events

### Listen to Invoice Registration

```php
use AichaDigital\LaraVerifactu\Events\InvoiceRegisteredEvent;
use Illuminate\Support\Facades\Event;

Event::listen(InvoiceRegisteredEvent::class, function ($event) {
    // Send notification
    Mail::to($event->invoice->recipient_email)
        ->send(new InvoiceRegisteredMail($event->invoice, $event->registry));

    // Update your system
    $event->invoice->update(['verifactu_status' => 'registered']);
});
```

### Listen to AEAT Submission Success

```php
use AichaDigital\LaraVerifactu\Events\RegistrySubmittedEvent;

Event::listen(RegistrySubmittedEvent::class, function ($event) {
    Log::info('Registry submitted to AEAT', [
        'registry_number' => $event->registry->getRegistryNumber(),
        'csv' => $event->response->getCsv(),
    ]);

    // Notify accounting department
    Notification::send(
        User::role('accounting')->get(),
        new RegistrySubmittedNotification($event->registry)
    );
});
```

### Listen to Submission Failures

```php
use AichaDigital\LaraVerifactu\Events\RegistryFailedEvent;

Event::listen(RegistryFailedEvent::class, function ($event) {
    if ($event->attempt >= 3) {
        // Max attempts reached - alert admin
        Mail::to('admin@company.com')
            ->send(new RegistryFailedAlert($event->registry, $event->error));
    }
});
```

### Listen to Blockchain Verification

```php
use AichaDigital\LaraVerifactu\Events\BlockchainVerifiedEvent;

Event::listen(BlockchainVerifiedEvent::class, function ($event) {
    if (!$event->result['valid']) {
        // Critical: blockchain integrity compromised!
        Mail::to('security@company.com')
            ->send(new BlockchainIntegrityAlert($event->result['errors']));

        // Log to security channel
        Log::channel('security')->critical('Blockchain integrity check failed', [
            'errors' => $event->result['errors'],
        ]);
    }
});
```

## Advanced Scenarios

### Batch Registration with Progress Tracking

```php
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;

$invoices = Invoice::whereNull('registry_id')
    ->whereDate('issue_date', today())
    ->get();

$registrar = app(InvoiceRegistrar::class);
$results = [];

foreach ($invoices as $invoice) {
    try {
        $registry = $registrar->register($invoice);
        $results['success'][] = $invoice->id;
    } catch (\Exception $e) {
        $results['failed'][] = [
            'invoice_id' => $invoice->id,
            'error' => $e->getMessage(),
        ];
    }
}

Log::info('Batch registration completed', $results);
```

### Custom Retry Logic

```php
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;

$registryManager = app(RegistryManager::class);

// Get registries that failed but haven't exceeded max attempts
$retryable = $registryManager->getRetryableRegistries(maxAttempts: 5, limit: 100);

foreach ($retryable as $registry) {
    try {
        app(InvoiceRegistrar::class)->submitToAeat($registry);
    } catch (\Exception $e) {
        Log::error("Retry failed for registry {$registry->id}", [
            'error' => $e->getMessage(),
        ]);
    }
}
```

### Verify Specific Invoice Chain

```php
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\HashGenerator;

$registryManager = app(RegistryManager::class);
$hashGenerator = app(HashGenerator::class);

$invoice = Invoice::with('registry')->find(42);

// Verify this specific registry
$previousHash = $registryManager->getPreviousHash();
$expectedHash = $hashGenerator->generate($invoice, $previousHash);

if ($invoice->registry->hash === $expectedHash) {
    echo "✅ Hash is valid\n";
} else {
    echo "❌ Hash mismatch - blockchain integrity issue!\n";
}
```

### Generate QR Codes for Existing Invoices

```php
use AichaDigital\LaraVerifactu\Services\QrGenerator;
use AichaDigital\LaraVerifactu\Models\Registry;

$qrGenerator = app(QrGenerator::class);

Registry::whereNull('qr_svg')->chunk(100, function ($registries) use ($qrGenerator) {
    foreach ($registries as $registry) {
        $invoice = $registry->invoice;

        $registry->update([
            'qr_url' => $qrGenerator->generateUrl($invoice, $registry->hash),
            'qr_svg' => $qrGenerator->generateSvg($invoice, $registry->hash),
            'qr_png' => $qrGenerator->generatePng($invoice, $registry->hash),
        ]);
    }
});
```

---

## Need Help?

- Check the [README](README.md) for general information
- Review the [API documentation](docs/api.md)
- Open an issue on [GitHub](https://github.com/AichaDigital/lara-verifactu/issues)
- Contact support: support@aichadigital.com

