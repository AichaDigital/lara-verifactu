<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu;

use AichaDigital\LaraVerifactu\Contracts\ActivationContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;
use AichaDigital\LaraVerifactu\Exceptions\ConfigurationException;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuDisabledException;
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

    /** Whether the fake follows the activation switch (AID-1907). */
    private bool $fakeHonoursSwitch = false;

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
            $this->assertFakeEnabled('register');

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
            $this->assertFakeEnabled('cancel');

            $this->fakeCancelled[$invoice->getInvoiceNumber()] = $invoice;

            return $this->fakeRegistry($invoice, RegistryTypeEnum::CANCELLATION);
        }

        return $this->registrar->cancel($invoice, $submitToAeat);
    }

    /**
     * Amend a rejected initial registration («ALTA POR RECHAZO», AID-137).
     */
    public function amendRejected(
        RegistryContract $rejectedRegistry,
        InvoiceContract $correctedInvoice,
        bool $submitToAeat = true
    ): RegistryContract {
        if ($this->fakeMode) {
            // Recorded as a registration of the corrected invoice (AID-1907 G3),
            // through the same map and builder as register().
            $this->assertFakeEnabled('amendRejected');

            $this->fakeRegistered[$correctedInvoice->getInvoiceNumber()] = $correctedInvoice;

            return $this->fakeRegistry($correctedInvoice, RegistryTypeEnum::REGISTRATION);
        }

        return $this->registrar->amendRejected($rejectedRegistry, $correctedInvoice, $submitToAeat);
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
            // Checked before iterating, so an empty batch is refused too.
            $this->assertFakeEnabled('sendBatch');

            $registries = [];

            foreach ($invoices as $invoice) {
                $registries[] = $this->register($invoice);
            }

            return ['success' => count($registries), 'failed' => 0, 'registries' => $registries];
        }

        // Checked before materialising the iterable: a generator must not be
        // evaluated (lookups, side effects) while the package is disabled.
        if (! app(ActivationContract::class)->isEnabled()) {
            throw VerifactuDisabledException::forOperation('sendBatch');
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
     * Whether the package will register (AID-1907): "ask the package whether
     * it will register". The answer reflects the activation switch only; it
     * does not promise that the certificate or the environment is configured.
     *
     * A default fake answers true, so a consumer that gates on this keeps
     * reaching the fake; fake(honourSwitch: true) reports the switch.
     *
     * @throws ConfigurationException When the switch holds an unparseable value.
     */
    public function enabled(): bool
    {
        if ($this->fakeMode && ! $this->fakeHonoursSwitch) {
            return true;
        }

        return app(ActivationContract::class)->isEnabled();
    }

    /**
     * Enable fake mode: calls are intercepted in-memory for assertions.
     *
     * By default the fake ignores the activation switch. With
     * `honourSwitch: true` it follows it: enabled() reports the configured
     * value and the write methods throw VerifactuDisabledException when off.
     */
    public function fake(bool $honourSwitch = false): void
    {
        $this->fakeMode = true;
        $this->fakeHonoursSwitch = $honourSwitch;
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
        // forceFill: hash and hash_generated_at are out of $fillable (AID-730),
        // and this stub is never persisted.
        return (new Registry)->forceFill([
            'registry_number' => 'FAKE-' . $invoice->getInvoiceNumber(),
            'registry_date' => Carbon::now(),
            'registry_type' => $type->value,
            'hash' => strtoupper(hash('sha256', 'fake-' . $invoice->getInvoiceNumber())),
            'hash_generated_at' => Carbon::now()->format('c'),
            'status' => RegistryStatusEnum::PENDING->value,
            'submission_attempts' => 0,
        ]);
    }

    /**
     * @throws VerifactuDisabledException
     */
    private function assertFakeEnabled(string $operation): void
    {
        if ($this->fakeHonoursSwitch && ! app(ActivationContract::class)->isEnabled()) {
            throw VerifactuDisabledException::forOperation($operation);
        }
    }

    private function ensureFakeMode(): void
    {
        if (! $this->fakeMode) {
            throw new \RuntimeException('Cannot assert when not in fake mode. Call Verifactu::fake() first.');
        }
    }
}
