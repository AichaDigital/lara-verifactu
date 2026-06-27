<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Jobs\ProcessInvoiceRegistrationJob;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * AID-265 — pin the sequential fiscal-order guard.
 *
 * `ensureSequentialOrder()` and `extractSequentialNumber()` are both protected,
 * and the AC requires exercising `extractSequentialNumber()` in isolation. Driving
 * the full `handle()` would couple this to the cache lock, the InvoiceRegistrar and
 * AEAT submission, none of which this guard depends on — so we invoke the two
 * methods directly via reflection. These tests pin the CURRENT behaviour; the
 * `id <` ordering (vs the parsed sequential number) is a known limitation tracked
 * elsewhere and intentionally NOT changed here.
 */
function ensureSequentialOrderOn(Invoice $invoice): void
{
    $job = new ProcessInvoiceRegistrationJob($invoice->id);
    (new ReflectionMethod($job, 'ensureSequentialOrder'))->invoke($job, $invoice);
}

function extractSequentialNumberFrom(string $invoiceNumber): ?int
{
    $job = new ProcessInvoiceRegistrationJob(0);

    return (new ReflectionMethod($job, 'extractSequentialNumber'))->invoke($job, $invoiceNumber);
}

it('throws when a previous invoice in the same serie and fiscal year has no registry', function () {
    // Earlier invoice (lower id), same serie + year, left unregistered.
    Invoice::factory()->withoutBreakdowns()
        ->withSerieAndNumber('FA', 'FAC-2025-000001')
        ->issuedAt(Carbon::create(2025, 6, 1, 12))
        ->create();

    $current = Invoice::factory()->withoutBreakdowns()
        ->withSerieAndNumber('FA', 'FAC-2025-000002')
        ->issuedAt(Carbon::create(2025, 6, 2, 12))
        ->create();

    expect(fn () => ensureSequentialOrderOn($current))
        ->toThrow(RuntimeException::class);
});

it('passes when every previous invoice in the same serie and fiscal year is registered', function () {
    $previous = Invoice::factory()->withoutBreakdowns()
        ->withSerieAndNumber('FA', 'FAC-2025-000001')
        ->issuedAt(Carbon::create(2025, 6, 1, 12))
        ->create();

    Registry::factory()->forInvoice($previous)->create();

    $current = Invoice::factory()->withoutBreakdowns()
        ->withSerieAndNumber('FA', 'FAC-2025-000002')
        ->issuedAt(Carbon::create(2025, 6, 2, 12))
        ->create();

    expect(fn () => ensureSequentialOrderOn($current))
        ->not->toThrow(RuntimeException::class);
});

it('skips validation and logs a warning when the number has no extractable trailing digits', function () {
    // A blocking unregistered predecessor exists: if validation ran, it would throw.
    Invoice::factory()->withoutBreakdowns()
        ->withSerieAndNumber('FA', 'FAC-2025-000001')
        ->issuedAt(Carbon::create(2025, 6, 1, 12))
        ->create();

    // Trailing segment "FINAL" is non-numeric, so no sequential number is extracted.
    $current = Invoice::factory()->withoutBreakdowns()
        ->withSerieAndNumber('FA', 'FAC-2025-FINAL')
        ->issuedAt(Carbon::create(2025, 6, 2, 12))
        ->create();

    // Pin the warning path: channel()->warning() is hit exactly once and no throw.
    $channel = Mockery::mock(LoggerInterface::class);
    Log::shouldReceive('channel')->andReturn($channel);
    $channel->shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'Could not extract sequential number'));

    ensureSequentialOrderOn($current);
});

it('does not block when an unregistered invoice belongs to a different serie', function () {
    // Earlier (lower id) unregistered invoice, but in serie 'FB'.
    Invoice::factory()->withoutBreakdowns()
        ->withSerieAndNumber('FB', 'FAC-2025-000001')
        ->issuedAt(Carbon::create(2025, 6, 1, 12))
        ->create();

    $current = Invoice::factory()->withoutBreakdowns()
        ->withSerieAndNumber('FA', 'FAC-2025-000002')
        ->issuedAt(Carbon::create(2025, 6, 2, 12))
        ->create();

    expect(fn () => ensureSequentialOrderOn($current))
        ->not->toThrow(RuntimeException::class);
});

it('does not block when an unregistered invoice belongs to a different fiscal year', function () {
    // Earlier (lower id) unregistered invoice, same serie, but issued in 2024.
    Invoice::factory()->withoutBreakdowns()
        ->withSerieAndNumber('FA', 'FAC-2024-000001')
        ->issuedAt(Carbon::create(2024, 6, 1, 12))
        ->create();

    $current = Invoice::factory()->withoutBreakdowns()
        ->withSerieAndNumber('FA', 'FAC-2025-000002')
        ->issuedAt(Carbon::create(2025, 6, 2, 12))
        ->create();

    expect(fn () => ensureSequentialOrderOn($current))
        ->not->toThrow(RuntimeException::class);
});

it('extracts the trailing sequential number, or null when the tail is non-numeric', function (string $number, ?int $expected) {
    expect(extractSequentialNumberFrom($number))->toBe($expected);
})->with([
    'zero-padded tail' => ['FAC-2025-000047', 47],
    'non-numeric tail' => ['FAC-2025-FINAL', null],
]);
