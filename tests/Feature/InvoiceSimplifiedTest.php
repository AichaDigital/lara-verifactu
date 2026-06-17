<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Models\Invoice;
use Illuminate\Support\Facades\Schema;

/**
 * AID-187: `type` is the single source of truth for simplified-ness.
 *
 * `Invoice::isSimplified()` derives from `InvoiceTypeEnum` (F2 / R5) and the
 * stored `simplified` column is dropped, so the two can no longer disagree.
 */
it('derives isSimplified() from the invoice type', function (InvoiceTypeEnum $type, bool $expected) {
    $invoice = Invoice::factory()->create(['type' => $type]);

    expect($invoice->isSimplified())->toBe($expected)
        ->and($invoice->getType()->isSimplified())->toBe($expected);
})->with([
    'F1 complete' => [InvoiceTypeEnum::COMPLETE, false],
    'F2 simplified' => [InvoiceTypeEnum::SIMPLIFIED, true],
    'R1 rectificative' => [InvoiceTypeEnum::RECTIFICATIVE, false],
    'R5 simplified rectificative' => [InvoiceTypeEnum::RECTIFICATIVE_SIMPLIFIED_INVOICES, true],
]);

it('drops the stored simplified column (single source of truth)', function () {
    expect(Schema::hasColumn('verifactu_invoices', 'simplified'))->toBeFalse();
});

it('factory default is a deterministic complete (F1) non-simplified invoice', function () {
    $invoice = Invoice::factory()->create();

    expect($invoice->getType())->toBe(InvoiceTypeEnum::COMPLETE)
        ->and($invoice->isSimplified())->toBeFalse();
});

it('factory simplified() state sets type=F2 and derives simplified', function () {
    $invoice = Invoice::factory()->simplified()->create();

    expect($invoice->getType())->toBe(InvoiceTypeEnum::SIMPLIFIED)
        ->and($invoice->isSimplified())->toBeTrue()
        ->and($invoice->hasRecipient())->toBeFalse();
});
