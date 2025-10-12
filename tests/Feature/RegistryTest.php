<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Database\Factories\InvoiceFactory;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Models\Registry;
use Carbon\Carbon;

beforeEach(function () {
    // Run migrations
    $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
});

it('can create a registry', function () {
    $registry = Registry::factory()->create([
        'registry_number' => 'REG-0001',
        'hash' => hash('sha256', 'test'),
    ]);

    expect($registry)->toBeInstanceOf(Registry::class)
        ->and($registry->registry_number)->toBe('REG-0001')
        ->and($registry->hash)->toHaveLength(64)
        ->and($registry->exists)->toBeTrue();
});

it('implements RegistryContract methods', function () {
    $registry = Registry::factory()->create([
        'registry_number' => 'REG-0002',
        'hash' => hash('sha256', 'test2'),
    ]);

    expect($registry->getRegistryNumber())->toBe('REG-0002')
        ->and($registry->getHash())->toBeString()
        ->and($registry->getHash())->toHaveLength(64)
        ->and($registry->getRegistryDate())->toBeInstanceOf(Carbon::class)
        ->and($registry->getStatus())->toBeInstanceOf(RegistryStatusEnum::class)
        ->and($registry->getXml())->toBeString();
});

it('belongs to an invoice', function () {
    $invoice = InvoiceFactory::new()->create();
    $registry = Registry::factory()->forInvoice($invoice)->create();

    expect($registry->invoice)->not->toBeNull()
        ->and($registry->invoice->id)->toBe($invoice->id)
        ->and($registry->getInvoice()->id)->toBe($invoice->id);
});

it('can track blockchain with previous hash', function () {
    $registry1 = Registry::factory()->create([
        'hash' => $hash1 = hash('sha256', 'first'),
        'previous_hash' => null,
    ]);

    $registry2 = Registry::factory()
        ->withPreviousHash($hash1)
        ->create();

    expect($registry1->getPreviousHash())->toBeNull()
        ->and($registry2->getPreviousHash())->toBe($hash1)
        ->and($registry2->previous_hash)->toBe($hash1);
});

it('can be marked as submitted', function () {
    $registry = Registry::factory()->submitted()->create();

    expect($registry->isSubmitted())->toBeTrue()
        ->and($registry->isPending())->toBeFalse()
        ->and($registry->hasErrors())->toBeFalse()
        ->and($registry->getStatus())->toBe(RegistryStatusEnum::SENT)
        ->and($registry->getSubmittedAt())->toBeInstanceOf(Carbon::class)
        ->and($registry->getAeatCsv())->not->toBeNull()
        ->and($registry->getSubmissionAttempts())->toBeGreaterThan(0);
});

it('can be marked as failed', function () {
    $registry = Registry::factory()->failed()->create();

    expect($registry->hasErrors())->toBeTrue()
        ->and($registry->isSubmitted())->toBeFalse()
        ->and($registry->isPending())->toBeFalse()
        ->and($registry->getStatus())->toBe(RegistryStatusEnum::ERROR)
        ->and($registry->getAeatError())->not->toBeNull()
        ->and($registry->getSubmissionAttempts())->toBeGreaterThan(0);
});

it('starts as pending by default', function () {
    $registry = Registry::factory()->create();

    expect($registry->isPending())->toBeTrue()
        ->and($registry->isSubmitted())->toBeFalse()
        ->and($registry->hasErrors())->toBeFalse()
        ->and($registry->getStatus())->toBe(RegistryStatusEnum::PENDING)
        ->and($registry->getSubmittedAt())->toBeNull()
        ->and($registry->getSubmissionAttempts())->toBe(0);
});

it('can store QR codes', function () {
    $registry = Registry::factory()->withQr()->create();

    expect($registry->getQrUrl())->not->toBeNull()
        ->and($registry->getQrUrl())->toStartWith('https://')
        ->and($registry->getQrSvg())->not->toBeNull()
        ->and($registry->getQrSvg())->toContain('<svg>')
        ->and($registry->getQrPng())->not->toBeNull();
});

it('can store signed XML', function () {
    $registry = Registry::factory()->signed()->create([
        'xml' => '<xml><data>test</data></xml>',
    ]);

    expect($registry->getXml())->toContain('<xml>')
        ->and($registry->getSignedXml())->not->toBeNull()
        ->and($registry->getSignedXml())->toContain('<Signature>');
});

it('enforces unique registry number', function () {
    Registry::factory()->create([
        'registry_number' => 'UNIQUE-001',
    ]);

    // This should throw a database exception
    Registry::factory()->create([
        'registry_number' => 'UNIQUE-001',
    ]);
})->throws(Exception::class);

it('enforces unique hash', function () {
    $hash = hash('sha256', 'duplicate');

    Registry::factory()->create(['hash' => $hash]);

    // This should throw a database exception
    Registry::factory()->create(['hash' => $hash]);
})->throws(Exception::class);

it('cascades delete when invoice is deleted', function () {
    $invoice = InvoiceFactory::new()->create();
    $registry = Registry::factory()->forInvoice($invoice)->create();

    $registryId = $registry->id;
    $invoice->delete();

    expect(Registry::find($registryId))->toBeNull();
});

it('can soft delete registries', function () {
    $registry = Registry::factory()->create();
    $registryId = $registry->id;

    $registry->delete();

    expect(Registry::find($registryId))->toBeNull()
        ->and(Registry::withTrashed()->find($registryId))->not->toBeNull();
});

it('tracks AEAT submission attempts', function () {
    $registry = Registry::factory()->create(['submission_attempts' => 0]);

    expect($registry->getSubmissionAttempts())->toBe(0);

    $registry->update(['submission_attempts' => 1]);
    expect($registry->fresh()->getSubmissionAttempts())->toBe(1);

    $registry->update(['submission_attempts' => 3]);
    expect($registry->fresh()->getSubmissionAttempts())->toBe(3);
});

it('stores AEAT responses', function () {
    $registry = Registry::factory()->submitted()->create([
        'aeat_response' => ['status' => 'ACEPTADO', 'message' => 'Success'],
        'aeat_csv' => 'CSV-ABC123',
    ]);

    expect($registry->getAeatResponse())->toBeArray()
        ->and($registry->getAeatResponse()['status'])->toBe('ACEPTADO')
        ->and($registry->getAeatCsv())->toBe('CSV-ABC123');
});
