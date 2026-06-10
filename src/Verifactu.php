<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu;

use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use Carbon\Carbon;
use PHPUnit\Framework\Assert;

/**
 * Public entry point of the package (facade root).
 *
 * Delegates to the orchestrating services and offers a fake mode that
 * intercepts calls in-memory so consumer tests can assert on them without
 * touching the database or AEAT.
 */
final class Verifactu
{
    private bool $fakeMode = false;

    /**
     * @var array<string, InvoiceContract>
     */
    private array $fakeRegistered = [];

    /**
     * @var array<string, InvoiceContract>
     */
    private array $fakeCancelled = [];

    public function __construct(
        private readonly InvoiceRegistrar $registrar,
        private readonly QrGeneratorContract $qrGenerator,
    ) {}

    /**
     * Register an invoice (RegistroAlta) in the Verifactu chain
     */
    public function register(InvoiceContract $invoice, bool $submitToAeat = true): RegistryContract
    {
        if ($this->fakeMode) {
            $this->fakeRegistered[$invoice->getInvoiceNumber()] = $invoice;

            return $this->fakeRegistry($invoice, RegistryTypeEnum::REGISTRATION);
        }

        return $this->registrar->register($invoice, $submitToAeat);
    }

    /**
     * Cancel an invoice (RegistroAnulacion) in the Verifactu chain
     */
    public function cancel(InvoiceContract $invoice, bool $submitToAeat = true): RegistryContract
    {
        if ($this->fakeMode) {
            $this->fakeCancelled[$invoice->getInvoiceNumber()] = $invoice;

            return $this->fakeRegistry($invoice, RegistryTypeEnum::CANCELLATION);
        }

        return $this->registrar->cancel($invoice, $submitToAeat);
    }

    /**
     * Register a batch of invoices
     *
     * @param  iterable<InvoiceContract>  $invoices
     * @return array{success: int, failed: int, registries: array<RegistryContract>}
     */
    public function sendBatch(iterable $invoices, bool $submitToAeat = true): array
    {
        if ($this->fakeMode) {
            $registries = [];

            foreach ($invoices as $invoice) {
                $registries[] = $this->register($invoice);
            }

            return ['success' => count($registries), 'failed' => 0, 'registries' => $registries];
        }

        return $this->registrar->batchRegister(
            is_array($invoices) ? $invoices : iterator_to_array($invoices),
            $submitToAeat,
        );
    }

    /**
     * Get the latest registry of an invoice, or null if never registered
     */
    public function status(InvoiceContract $invoice): ?RegistryContract
    {
        if ($this->fakeMode) {
            $number = $invoice->getInvoiceNumber();

            return isset($this->fakeRegistered[$number])
                ? $this->fakeRegistry($invoice, RegistryTypeEnum::REGISTRATION)
                : null;
        }

        return Registry::query()
            ->where('invoice_id', $invoice->getId())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Generate the AEAT QR for an invoice in the configured format
     */
    public function qr(InvoiceContract $invoice): string
    {
        if ($this->fakeMode) {
            return 'fake-qr-code';
        }

        return $this->qrGenerator->generate($invoice);
    }

    /**
     * Verify the integrity of the whole registry chain
     *
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateChain(): array
    {
        if ($this->fakeMode) {
            return ['valid' => true, 'errors' => []];
        }

        return $this->registrar->verifyBlockchain();
    }

    /**
     * Enable fake mode: calls are intercepted in-memory for assertions
     */
    public function fake(): void
    {
        $this->fakeMode = true;
        $this->fakeRegistered = [];
        $this->fakeCancelled = [];
    }

    /**
     * Assert an invoice was registered while in fake mode
     */
    public function assertRegistered(InvoiceContract $invoice): void
    {
        $this->ensureFakeMode();

        Assert::assertArrayHasKey(
            $invoice->getInvoiceNumber(),
            $this->fakeRegistered,
            sprintf('Invoice [%s] was not registered.', $invoice->getInvoiceNumber()),
        );
    }

    /**
     * Assert an invoice was cancelled while in fake mode
     */
    public function assertCancelled(InvoiceContract $invoice): void
    {
        $this->ensureFakeMode();

        Assert::assertArrayHasKey(
            $invoice->getInvoiceNumber(),
            $this->fakeCancelled,
            sprintf('Invoice [%s] was not cancelled.', $invoice->getInvoiceNumber()),
        );
    }

    /**
     * Assert an invoice was neither registered nor cancelled while in fake mode
     */
    public function assertNotSent(InvoiceContract $invoice): void
    {
        $this->ensureFakeMode();

        $number = $invoice->getInvoiceNumber();

        Assert::assertArrayNotHasKey(
            $number,
            $this->fakeRegistered,
            sprintf('Invoice [%s] was unexpectedly registered.', $number),
        );
        Assert::assertArrayNotHasKey(
            $number,
            $this->fakeCancelled,
            sprintf('Invoice [%s] was unexpectedly cancelled.', $number),
        );
    }

    /**
     * Build a non-persisted registry stub for fake mode returns
     */
    private function fakeRegistry(InvoiceContract $invoice, RegistryTypeEnum $type): Registry
    {
        return new Registry([
            'registry_number' => 'FAKE-' . $invoice->getInvoiceNumber(),
            'registry_date' => Carbon::now(),
            'registry_type' => $type->value,
            'hash' => strtoupper(hash('sha256', 'fake-' . $invoice->getInvoiceNumber())),
            'hash_generated_at' => Carbon::now()->format('c'),
            'status' => RegistryStatusEnum::PENDING->value,
            'submission_attempts' => 0,
        ]);
    }

    private function ensureFakeMode(): void
    {
        if (! $this->fakeMode) {
            throw new \RuntimeException('Cannot assert when not in fake mode. Call Verifactu::fake() first.');
        }
    }
}
