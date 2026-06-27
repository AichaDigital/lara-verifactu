<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\RegistryManager;

beforeEach(function () {
    // markAsSubmitted/markAsFailed never call the hash/qr/xml collaborators,
    // so the container-resolved manager is enough — no per-test mocks needed.
    $this->manager = app(RegistryManager::class);
});

describe('RegistryManager idempotency', function () {
    it('markAsSubmitted does not overwrite an already sent registry', function () {
        $registry = Registry::factory()->submitted()->create([
            'aeat_csv' => 'ORIGINAL-CSV-123',
            'aeat_response' => 'Original response',
            'submission_attempts' => 1,
        ]);

        $this->manager->markAsSubmitted($registry, 'NEW-CSV-456', 'New response');
        $registry->refresh();

        expect($registry->aeat_csv)->toBe('ORIGINAL-CSV-123')
            ->and($registry->aeat_response)->toBe('Original response')
            ->and($registry->submission_attempts)->toBe(1);
    });

    it('markAsFailed does not overwrite an already sent registry', function () {
        $registry = Registry::factory()->submitted()->create([
            'aeat_csv' => 'SUCCESS-CSV-123',
            'status' => RegistryStatusEnum::SENT->value,
        ]);

        $this->manager->markAsFailed($registry, 'This error should be ignored');
        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::SENT)
            ->and($registry->aeat_csv)->toBe('SUCCESS-CSV-123')
            ->and($registry->aeat_error)->toBeNull();
    });

    it('markAsFailed updates a pending registry', function () {
        $registry = Registry::factory()->create([
            'status' => RegistryStatusEnum::PENDING->value,
        ]);

        $this->manager->markAsFailed($registry, 'Connection timeout');
        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::ERROR)
            ->and($registry->aeat_error)->toBe('Connection timeout');
    });

    it('markAsSubmitted updates a pending registry', function () {
        $registry = Registry::factory()->create([
            'status' => RegistryStatusEnum::PENDING->value,
        ]);

        $this->manager->markAsSubmitted($registry, 'CSV-SUCCESS-001', 'Accepted');
        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::SENT)
            ->and($registry->aeat_csv)->toBe('CSV-SUCCESS-001');
    });

    it('markAsSubmitted updates an error registry on retry', function () {
        $registry = Registry::factory()->failed()->create([
            'status' => RegistryStatusEnum::ERROR->value,
            'aeat_error' => 'Previous error',
        ]);

        $this->manager->markAsSubmitted($registry, 'CSV-RETRY-001', 'Accepted on retry');
        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::SENT)
            ->and($registry->aeat_csv)->toBe('CSV-RETRY-001');
    });
});

describe('Transaction atomicity', function () {
    it('markAsSubmitted increments submission_attempts atomically', function () {
        $registry = Registry::factory()->create([
            'status' => RegistryStatusEnum::PENDING->value,
            'submission_attempts' => 5,
        ]);

        $this->manager->markAsSubmitted($registry, 'CSV-001', 'Success');
        $registry->refresh();

        expect($registry->submission_attempts)->toBe(6);
    });

    it('markAsFailed increments submission_attempts atomically', function () {
        $registry = Registry::factory()->create([
            'status' => RegistryStatusEnum::PENDING->value,
            'submission_attempts' => 2,
        ]);

        $this->manager->markAsFailed($registry, 'Connection error');
        $registry->refresh();

        expect($registry->submission_attempts)->toBe(3);
    });
});

describe('Registry number uniqueness', function () {
    it('enforces unique registry numbers at database level', function () {
        Registry::factory()->create(['registry_number' => 'REG-20251231-000001']);

        expect(fn () => Registry::factory()->create([
            'registry_number' => 'REG-20251231-000001',
        ]))->toThrow(Exception::class);
    });

    it('enforces unique hash at database level', function () {
        $hash = hash('sha256', 'unique-content');

        Registry::factory()->create(['hash' => $hash]);

        expect(fn () => Registry::factory()->create(['hash' => $hash]))->toThrow(Exception::class);
    });
});

describe('Status transitions protection', function () {
    it('cannot transition from SENT to ERROR', function () {
        $registry = Registry::factory()->submitted()->create([
            'status' => RegistryStatusEnum::SENT->value,
            'aeat_csv' => 'PROTECTED-CSV',
        ]);

        $this->manager->markAsFailed($registry, 'Error 1');
        $this->manager->markAsFailed($registry, 'Error 2');
        $this->manager->markAsFailed($registry, 'Error 3');
        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::SENT)
            ->and($registry->aeat_csv)->toBe('PROTECTED-CSV')
            ->and($registry->aeat_error)->toBeNull();
    });

    it('cannot overwrite SENT with another SENT', function () {
        $registry = Registry::factory()->submitted()->create([
            'status' => RegistryStatusEnum::SENT->value,
            'aeat_csv' => 'FIRST-CSV',
            'submission_attempts' => 1,
        ]);

        $this->manager->markAsSubmitted($registry, 'SECOND-CSV', 'Another response');
        $registry->refresh();

        expect($registry->aeat_csv)->toBe('FIRST-CSV')
            ->and($registry->submission_attempts)->toBe(1);
    });

    it('can transition from PENDING to SENT', function () {
        $registry = Registry::factory()->create([
            'status' => RegistryStatusEnum::PENDING->value,
        ]);

        $this->manager->markAsSubmitted($registry, 'SUCCESS-CSV', 'Accepted');
        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::SENT);
    });

    it('can transition from PENDING to ERROR', function () {
        $registry = Registry::factory()->create([
            'status' => RegistryStatusEnum::PENDING->value,
        ]);

        $this->manager->markAsFailed($registry, 'Connection timeout');
        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::ERROR);
    });

    it('can transition from ERROR to SENT on retry', function () {
        $registry = Registry::factory()->failed()->create([
            'status' => RegistryStatusEnum::ERROR->value,
            'aeat_error' => 'Previous failure',
        ]);

        $this->manager->markAsSubmitted($registry, 'RETRY-SUCCESS-CSV', 'Accepted on retry');
        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::SENT)
            ->and($registry->aeat_csv)->toBe('RETRY-SUCCESS-CSV');
    });
});
