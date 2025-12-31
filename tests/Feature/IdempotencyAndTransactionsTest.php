<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Jobs\SubmitRegistryToAeatJob;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
});

describe('RegistryManager idempotency', function () {
    it('markAsSubmitted does not overwrite already sent registry', function () {
        $registry = Registry::factory()->submitted()->create([
            'aeat_csv' => 'ORIGINAL-CSV-123',
            'aeat_response' => 'Original response',
            'submission_attempts' => 1,
        ]);

        // Get fresh instance of RegistryManager with mocked dependencies
        $hashGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract::class);
        $qrGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract::class);
        $xmlBuilder = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract::class);

        $registryManager = new RegistryManager($hashGenerator, $qrGenerator, $xmlBuilder);

        // Try to mark as submitted again with different data
        $registryManager->markAsSubmitted($registry, 'NEW-CSV-456', 'New response');

        $registry->refresh();

        // Should keep original values (idempotency)
        expect($registry->aeat_csv)->toBe('ORIGINAL-CSV-123')
            ->and($registry->aeat_response)->toBe('Original response')
            ->and($registry->submission_attempts)->toBe(1);
    });

    it('markAsFailed does not overwrite already sent registry', function () {
        $registry = Registry::factory()->submitted()->create([
            'aeat_csv' => 'SUCCESS-CSV-123',
            'status' => RegistryStatusEnum::SENT->value,
        ]);

        $hashGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract::class);
        $qrGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract::class);
        $xmlBuilder = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract::class);

        $registryManager = new RegistryManager($hashGenerator, $qrGenerator, $xmlBuilder);

        // Try to mark as failed after successful submission
        $registryManager->markAsFailed($registry, 'This error should be ignored');

        $registry->refresh();

        // Should remain SENT (never overwrite success with error)
        expect($registry->status)->toBe(RegistryStatusEnum::SENT)
            ->and($registry->aeat_csv)->toBe('SUCCESS-CSV-123')
            ->and($registry->aeat_error)->toBeNull();
    });

    it('markAsFailed can update pending registry', function () {
        $registry = Registry::factory()->create([
            'status' => RegistryStatusEnum::PENDING->value,
        ]);

        $hashGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract::class);
        $qrGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract::class);
        $xmlBuilder = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract::class);

        $registryManager = new RegistryManager($hashGenerator, $qrGenerator, $xmlBuilder);
        $registryManager->markAsFailed($registry, 'Connection timeout');

        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::ERROR)
            ->and($registry->aeat_error)->toBe('Connection timeout');
    });

    it('markAsSubmitted can update pending registry', function () {
        $registry = Registry::factory()->create([
            'status' => RegistryStatusEnum::PENDING->value,
        ]);

        $hashGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract::class);
        $qrGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract::class);
        $xmlBuilder = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract::class);

        $registryManager = new RegistryManager($hashGenerator, $qrGenerator, $xmlBuilder);
        $registryManager->markAsSubmitted($registry, 'CSV-SUCCESS-001', 'Accepted');

        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::SENT)
            ->and($registry->aeat_csv)->toBe('CSV-SUCCESS-001');
    });

    it('markAsSubmitted can update error registry on retry', function () {
        $registry = Registry::factory()->failed()->create([
            'status' => RegistryStatusEnum::ERROR->value,
            'aeat_error' => 'Previous error',
        ]);

        $hashGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract::class);
        $qrGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract::class);
        $xmlBuilder = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract::class);

        $registryManager = new RegistryManager($hashGenerator, $qrGenerator, $xmlBuilder);
        $registryManager->markAsSubmitted($registry, 'CSV-RETRY-001', 'Accepted on retry');

        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::SENT)
            ->and($registry->aeat_csv)->toBe('CSV-RETRY-001');
    });
});

describe('SubmitRegistryToAeatJob idempotency', function () {
    it('can be dispatched to queue', function () {
        Queue::fake();

        SubmitRegistryToAeatJob::dispatch(123);

        Queue::assertPushed(SubmitRegistryToAeatJob::class, function ($job) {
            return $job->registryId === 123;
        });
    });

    it('has correct configuration', function () {
        $job = new SubmitRegistryToAeatJob(1);

        expect($job->tries)->toBeGreaterThan(0)
            ->and($job->timeout)->toBeGreaterThan(0)
            ->and($job->registryId)->toBe(1);
    });
});

describe('Transaction atomicity', function () {
    it('submission_attempts uses atomic increment', function () {
        $registry = Registry::factory()->create([
            'status' => RegistryStatusEnum::PENDING->value,
            'submission_attempts' => 5,
        ]);

        $hashGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract::class);
        $qrGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract::class);
        $xmlBuilder = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract::class);

        $registryManager = new RegistryManager($hashGenerator, $qrGenerator, $xmlBuilder);
        $registryManager->markAsSubmitted($registry, 'CSV-001', 'Success');

        $registry->refresh();

        // Should be 6 (5 + 1)
        expect($registry->submission_attempts)->toBe(6);
    });

    it('markAsFailed increments attempts atomically', function () {
        $registry = Registry::factory()->create([
            'status' => RegistryStatusEnum::PENDING->value,
            'submission_attempts' => 2,
        ]);

        $hashGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract::class);
        $qrGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract::class);
        $xmlBuilder = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract::class);

        $registryManager = new RegistryManager($hashGenerator, $qrGenerator, $xmlBuilder);
        $registryManager->markAsFailed($registry, 'Connection error');

        $registry->refresh();

        expect($registry->submission_attempts)->toBe(3);
    });
});

describe('Registry number uniqueness', function () {
    it('enforces unique registry numbers at database level', function () {
        Registry::factory()->create([
            'registry_number' => 'REG-20251231-000001',
        ]);

        // Attempting to create duplicate should throw
        expect(fn () => Registry::factory()->create([
            'registry_number' => 'REG-20251231-000001',
        ]))->toThrow(Exception::class);
    });

    it('enforces unique hash at database level', function () {
        $hash = hash('sha256', 'unique-content');

        Registry::factory()->create(['hash' => $hash]);

        // Attempting to create duplicate should throw
        expect(fn () => Registry::factory()->create(['hash' => $hash]))->toThrow(Exception::class);
    });
});

describe('Status transitions protection', function () {
    it('cannot transition from SENT to ERROR', function () {
        $registry = Registry::factory()->submitted()->create([
            'status' => RegistryStatusEnum::SENT->value,
            'aeat_csv' => 'PROTECTED-CSV',
        ]);

        $hashGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract::class);
        $qrGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract::class);
        $xmlBuilder = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract::class);

        $registryManager = new RegistryManager($hashGenerator, $qrGenerator, $xmlBuilder);

        // Attempt multiple failures
        $registryManager->markAsFailed($registry, 'Error 1');
        $registryManager->markAsFailed($registry, 'Error 2');
        $registryManager->markAsFailed($registry, 'Error 3');

        $registry->refresh();

        // Should still be SENT
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

        $hashGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract::class);
        $qrGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract::class);
        $xmlBuilder = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract::class);

        $registryManager = new RegistryManager($hashGenerator, $qrGenerator, $xmlBuilder);

        // Attempt to overwrite
        $registryManager->markAsSubmitted($registry, 'SECOND-CSV', 'Another response');

        $registry->refresh();

        // Should keep first values
        expect($registry->aeat_csv)->toBe('FIRST-CSV')
            ->and($registry->submission_attempts)->toBe(1);
    });

    it('can transition from PENDING to SENT', function () {
        $registry = Registry::factory()->create([
            'status' => RegistryStatusEnum::PENDING->value,
        ]);

        $hashGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract::class);
        $qrGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract::class);
        $xmlBuilder = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract::class);

        $registryManager = new RegistryManager($hashGenerator, $qrGenerator, $xmlBuilder);
        $registryManager->markAsSubmitted($registry, 'SUCCESS-CSV', 'Accepted');

        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::SENT);
    });

    it('can transition from PENDING to ERROR', function () {
        $registry = Registry::factory()->create([
            'status' => RegistryStatusEnum::PENDING->value,
        ]);

        $hashGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract::class);
        $qrGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract::class);
        $xmlBuilder = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract::class);

        $registryManager = new RegistryManager($hashGenerator, $qrGenerator, $xmlBuilder);
        $registryManager->markAsFailed($registry, 'Connection timeout');

        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::ERROR);
    });

    it('can transition from ERROR to SENT on retry', function () {
        $registry = Registry::factory()->failed()->create([
            'status' => RegistryStatusEnum::ERROR->value,
            'aeat_error' => 'Previous failure',
        ]);

        $hashGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract::class);
        $qrGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract::class);
        $xmlBuilder = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract::class);

        $registryManager = new RegistryManager($hashGenerator, $qrGenerator, $xmlBuilder);
        $registryManager->markAsSubmitted($registry, 'RETRY-SUCCESS-CSV', 'Accepted on retry');

        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::SENT)
            ->and($registry->aeat_csv)->toBe('RETRY-SUCCESS-CSV');
    });
});
