<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Events\RegistryCreatedEvent;
use AichaDigital\LaraVerifactu\Events\RegistryStatusChangedEvent;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * AID-1855 (AID-962 gate round 1, F1) — a vetoed registry write is a failure.
 *
 * Eloquent's save() returns false, without inserting and without throwing,
 * when a `saving` listener returns false. createRegistry() and
 * createCancellationRegistry() ignored that bool, so they announced and
 * returned a Registry with no row behind it, and an issuer wrapping the call
 * in its own transaction committed an invoice with no fingerprint.
 *
 * Nothing in the package or its known consumers vetoes the write today; these
 * tests pin the contract for the day something does.
 */
beforeEach(function () {
    config()->set('verifactu.company.tax_id', '89890001K');
    config()->set('verifactu.company.name', 'Empresa Ejemplo SL');
    config()->set('verifactu.system.vendor_name', 'AichaDigital SL');
    config()->set('verifactu.system.vendor_nif', 'B70123456');

    $qrGenerator = Mockery::mock(QrGeneratorContract::class);
    $qrGenerator->shouldReceive('generateUrl')->andReturn('https://example.test/qr');
    $qrGenerator->shouldReceive('generateSvg')->andReturn('<svg/>');
    $qrGenerator->shouldReceive('generatePng')->andReturn('png-binary');

    $this->registryManager = new RegistryManager(new HashGenerator, $qrGenerator, new XmlBuilder);
});

function vetoRegistrySaves(): void
{
    Registry::saving(fn (): bool => false);
}

it('throws instead of returning an unpersisted registration registry', function () {
    Event::fake([RegistryCreatedEvent::class, RegistryStatusChangedEvent::class]);
    vetoRegistrySaves();

    expect(fn () => $this->registryManager->createRegistry(Invoice::factory()->create()))
        ->toThrow(VerifactuException::class, 'vetoed by a model listener');

    expect(Registry::withTrashed()->count())->toBe(0);
    Event::assertNotDispatched(RegistryCreatedEvent::class);
    Event::assertNotDispatched(RegistryStatusChangedEvent::class);
});

it('throws instead of returning an unpersisted cancellation registry', function () {
    $invoice = Invoice::factory()->create();
    $this->registryManager->createRegistry($invoice);

    Event::fake([RegistryCreatedEvent::class, RegistryStatusChangedEvent::class]);
    vetoRegistrySaves();

    expect(fn () => $this->registryManager->createCancellationRegistry($invoice))
        ->toThrow(VerifactuException::class, 'vetoed by a model listener');

    expect(Registry::withTrashed()->count())->toBe(1);
    Event::assertNotDispatched(RegistryCreatedEvent::class);
});

it('fails register() so the issuer transaction rolls back with it', function () {
    vetoRegistrySaves();

    $registrar = new InvoiceRegistrar(
        $this->registryManager,
        Mockery::mock(CertificateManagerContract::class),
        Mockery::mock(AeatClientContract::class),
    );

    // The consumer's issuance: number the invoice and register it in one
    // transaction. The vetoed registry write must abort the whole unit.
    $issue = function () use ($registrar): void {
        DB::transaction(function () use ($registrar): void {
            $invoice = Invoice::factory()->create(['number' => 'F1-VETO-0001']);
            $registrar->register($invoice, submitToAeat: false);
        });
    };

    expect($issue)->toThrow(VerifactuException::class, 'vetoed by a model listener');

    expect(Invoice::query()->where('number', 'F1-VETO-0001')->exists())->toBeFalse()
        ->and(Registry::withTrashed()->count())->toBe(0);
});
