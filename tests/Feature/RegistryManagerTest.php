<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract;
use AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Carbon\Carbon;

beforeEach(function () {
    $this->hashGenerator = Mockery::mock(HashGeneratorContract::class);
    $this->qrGenerator = Mockery::mock(QrGeneratorContract::class);
    $this->xmlBuilder = Mockery::mock(XmlBuilderContract::class);

    $this->registryManager = new RegistryManager(
        $this->hashGenerator,
        $this->qrGenerator,
        $this->xmlBuilder
    );
});

// ========================================
// getPreviousHash Tests
// ========================================

describe('getPreviousHash', function () {
    it('returns null when no registries exist', function () {
        $previousHash = $this->registryManager->getPreviousHash();

        expect($previousHash)->toBeNull();
    });

    it('returns last registry hash when registries exist', function () {
        $invoice = Invoice::factory()->create();
        Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'hash' => 'first_hash',
            'registry_date' => Carbon::now()->subDay(),
        ]);
        Registry::factory()->create([
            'invoice_id' => Invoice::factory()->create()->id,
            'hash' => 'second_hash',
            'registry_date' => Carbon::now(),
        ]);

        $previousHash = $this->registryManager->getPreviousHash();

        expect($previousHash)->toBe('second_hash');
    });
});

// ========================================
// verifyBlockchain Tests
// ========================================

describe('verifyBlockchain', function () {
    it('returns valid when no registries exist', function () {
        $result = $this->registryManager->verifyBlockchain();

        expect($result['valid'])->toBeTrue()
            ->and($result['errors'])->toBeEmpty();
    });

    it('returns valid when chain is correct', function () {
        $this->hashGenerator
            ->shouldReceive('verify')
            ->andReturn(true);

        $invoice1 = Invoice::factory()->create();
        Registry::factory()->create([
            'invoice_id' => $invoice1->id,
            'hash' => 'hash1',
            'previous_hash' => null,
            'registry_date' => Carbon::now()->subDays(2),
        ]);

        $invoice2 = Invoice::factory()->create();
        Registry::factory()->create([
            'invoice_id' => $invoice2->id,
            'hash' => 'hash2',
            'previous_hash' => 'hash1',
            'registry_date' => Carbon::now()->subDay(),
        ]);

        $result = $this->registryManager->verifyBlockchain();

        expect($result['valid'])->toBeTrue();
    });

    it('returns invalid when chain is broken', function () {
        $this->hashGenerator
            ->shouldReceive('verify')
            ->andReturn(true);

        $invoice1 = Invoice::factory()->create();
        Registry::factory()->create([
            'invoice_id' => $invoice1->id,
            'hash' => 'hash1',
            'previous_hash' => null,
            'registry_date' => Carbon::now()->subDays(2),
        ]);

        $invoice2 = Invoice::factory()->create();
        Registry::factory()->create([
            'invoice_id' => $invoice2->id,
            'hash' => 'hash2',
            'previous_hash' => 'wrong_hash', // Should be 'hash1'
            'registry_date' => Carbon::now()->subDay(),
        ]);

        $result = $this->registryManager->verifyBlockchain();

        expect($result['valid'])->toBeFalse()
            ->and($result['errors'])->not->toBeEmpty();
    });
});

// ========================================
// markAsSubmitted Tests
// ========================================

describe('markAsSubmitted', function () {
    it('updates registry status to sent', function () {
        $invoice = Invoice::factory()->create();
        $registry = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'status' => RegistryStatusEnum::PENDING,
            'submission_attempts' => 0,
        ]);

        $this->registryManager->markAsSubmitted($registry, 'CSV123', 'Response data');

        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::SENT)
            ->and($registry->aeat_csv)->toBe('CSV123')
            ->and($registry->submission_attempts)->toBe(1);
    });

    it('does not overwrite already sent registry', function () {
        $invoice = Invoice::factory()->create();
        $registry = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'status' => RegistryStatusEnum::SENT,
            'aeat_csv' => 'ORIGINAL_CSV',
            'submission_attempts' => 1,
        ]);

        $this->registryManager->markAsSubmitted($registry, 'NEW_CSV', 'New response');

        $registry->refresh();

        expect($registry->aeat_csv)->toBe('ORIGINAL_CSV')
            ->and($registry->submission_attempts)->toBe(1);
    });
});

// ========================================
// markAsFailed Tests
// ========================================

describe('markAsFailed', function () {
    it('updates registry status to error', function () {
        $invoice = Invoice::factory()->create();
        $registry = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'status' => RegistryStatusEnum::PENDING,
            'submission_attempts' => 0,
        ]);

        $this->registryManager->markAsFailed($registry, 'Connection timeout');

        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::ERROR)
            ->and($registry->aeat_error)->toBe('Connection timeout')
            ->and($registry->submission_attempts)->toBe(1);
    });

    it('does not overwrite sent registry with error', function () {
        $invoice = Invoice::factory()->create();
        $registry = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'status' => RegistryStatusEnum::SENT,
            'aeat_csv' => 'CSV123',
            'submission_attempts' => 1,
        ]);

        $this->registryManager->markAsFailed($registry, 'Late error');

        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::SENT)
            ->and($registry->aeat_error)->toBeNull();
    });
});

// ========================================
// getPendingRegistries Tests
// ========================================

describe('getPendingRegistries', function () {
    it('returns only pending registries', function () {
        $invoice1 = Invoice::factory()->create();
        Registry::factory()->create([
            'invoice_id' => $invoice1->id,
            'status' => RegistryStatusEnum::PENDING,
        ]);

        $invoice2 = Invoice::factory()->create();
        Registry::factory()->create([
            'invoice_id' => $invoice2->id,
            'status' => RegistryStatusEnum::SENT,
        ]);

        $invoice3 = Invoice::factory()->create();
        Registry::factory()->create([
            'invoice_id' => $invoice3->id,
            'status' => RegistryStatusEnum::PENDING,
        ]);

        $pending = $this->registryManager->getPendingRegistries();

        expect($pending)->toHaveCount(2);
    });

    it('respects limit parameter', function () {
        for ($i = 0; $i < 5; $i++) {
            $invoice = Invoice::factory()->create();
            Registry::factory()->create([
                'invoice_id' => $invoice->id,
                'status' => RegistryStatusEnum::PENDING,
            ]);
        }

        $pending = $this->registryManager->getPendingRegistries(3);

        expect($pending)->toHaveCount(3);
    });
});

// ========================================
// getRetryableRegistries Tests
// ========================================

describe('getRetryableRegistries', function () {
    it('returns error registries under max attempts', function () {
        $invoice1 = Invoice::factory()->create();
        Registry::factory()->create([
            'invoice_id' => $invoice1->id,
            'status' => RegistryStatusEnum::ERROR,
            'submission_attempts' => 1,
        ]);

        $invoice2 = Invoice::factory()->create();
        Registry::factory()->create([
            'invoice_id' => $invoice2->id,
            'status' => RegistryStatusEnum::ERROR,
            'submission_attempts' => 5, // Over max
        ]);

        $retryable = $this->registryManager->getRetryableRegistries(3);

        expect($retryable)->toHaveCount(1);
    });

    it('does not select a REJECTED registry for retry', function () {
        $invoice = Invoice::factory()->create();
        Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'status' => RegistryStatusEnum::REJECTED->value,
            'submission_attempts' => 0,
        ]);

        expect($this->registryManager->getRetryableRegistries())->toHaveCount(0);
    });
});

// ========================================
// markAsRejected Tests
// ========================================

describe('markAsRejected', function () {
    it('marks a registry REJECTED and persists the AEAT response metadata', function () {
        $invoice = Invoice::factory()->create();
        $registry = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'status' => RegistryStatusEnum::PENDING->value,
            'submission_attempts' => 0,
        ]);

        $this->registryManager->markAsRejected(
            $registry,
            '3002: NIF del IDFactura no identificado',
            ['estado_envio' => 'Incorrecto', 'lineas' => [['codigo' => '3002']]],
        );

        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::REJECTED)
            ->and($registry->aeat_error)->toContain('3002')
            ->and($registry->aeat_response['estado_envio'])->toBe('Incorrecto')
            ->and($registry->submission_attempts)->toBe(1);
    });

    it('never overwrites a SENT registry with REJECTED', function () {
        $invoice = Invoice::factory()->create();
        $registry = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'status' => RegistryStatusEnum::SENT->value,
        ]);

        $this->registryManager->markAsRejected($registry, 'late rejection', null);

        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::SENT);
    });
});

// ========================================
// submitToAeat outcome routing Tests
// ========================================

describe('submitToAeat outcome routing', function () {
    it('routes a validation rejection to REJECTED, not ERROR', function () {
        $invoice = Invoice::factory()->create();
        $registry = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'status' => RegistryStatusEnum::PENDING->value,
        ]);

        $aeatClient = Mockery::mock(AeatClientContract::class);
        $aeatClient->shouldReceive('sendRegistration')->andReturn(
            AeatResponse::rejection(
                errors: ['3002: NIF del IDFactura no identificado'],
                message: 'Incorrecto',
                data: ['estado_envio' => 'Incorrecto'],
            )
        );

        $registrar = new InvoiceRegistrar(
            $this->registryManager,
            Mockery::mock(CertificateManagerContract::class),
            $aeatClient,
        );

        $registrar->submitToAeat($registry);
        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::REJECTED)
            ->and($registry->aeat_response['estado_envio'])->toBe('Incorrecto');
    });

    it('routes a transport failure to ERROR', function () {
        $invoice = Invoice::factory()->create();
        $registry = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'status' => RegistryStatusEnum::PENDING->value,
        ]);

        $aeatClient = Mockery::mock(AeatClientContract::class);
        $aeatClient->shouldReceive('sendRegistration')->andReturn(
            AeatResponse::failure(errors: ['Invalid response from AEAT server'])
        );

        $registrar = new InvoiceRegistrar(
            $this->registryManager,
            Mockery::mock(CertificateManagerContract::class),
            $aeatClient,
        );

        $registrar->submitToAeat($registry);
        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::ERROR);
    });
});

// ========================================
// subsanación columns Tests
// ========================================

describe('subsanación columns', function () {
    it('persists subsanacion, rechazo_previo and amends_registry_id with the enum cast', function () {
        $invoice = Invoice::factory()->create();
        $rejected = Registry::factory()->create(['invoice_id' => $invoice->id]);

        $amendment = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'subsanacion' => true,
            'rechazo_previo' => 'X',
            'amends_registry_id' => $rejected->id,
        ]);

        $amendment->refresh();

        expect($amendment->subsanacion)->toBeTrue()
            ->and($amendment->rechazo_previo)->toBe(RechazoPrevioEnum::X)
            ->and($amendment->amends_registry_id)->toBe($rejected->id);
    });

    it('rejects a second amendment of the same rejected registry at the DB level', function () {
        $invoice = Invoice::factory()->create();
        $rejected = Registry::factory()->create(['invoice_id' => $invoice->id]);

        Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'amends_registry_id' => $rejected->id,
        ]);

        expect(fn () => Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'amends_registry_id' => $rejected->id,
        ]))->toThrow(Illuminate\Database\QueryException::class);
    });

    it('allows multiple registries with a null amends_registry_id', function () {
        $invoice = Invoice::factory()->create();

        Registry::factory()->create(['invoice_id' => $invoice->id, 'amends_registry_id' => null]);
        Registry::factory()->create(['invoice_id' => $invoice->id, 'amends_registry_id' => null]);

        expect(Registry::whereNull('amends_registry_id')->count())->toBe(2);
    });
});

// ========================================
// registry contract accessors Tests
// ========================================

describe('registry contract accessors', function () {
    it('exposes the registry type, the amended registry id, and the registry id', function () {
        $invoice = Invoice::factory()->create();
        $rejected = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'registry_type' => RegistryTypeEnum::REGISTRATION->value,
        ]);
        $amendment = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'registry_type' => RegistryTypeEnum::REGISTRATION->value,
            'amends_registry_id' => $rejected->id,
        ]);

        expect($amendment->getRegistryType())->toBe(RegistryTypeEnum::REGISTRATION)
            ->and($amendment->getAmendsRegistryId())->toBe($rejected->id)
            ->and($rejected->getAmendsRegistryId())->toBeNull()
            ->and($rejected->getId())->toBe($rejected->id)
            ->and($amendment->getId())->toBe($amendment->id);
    });
});
