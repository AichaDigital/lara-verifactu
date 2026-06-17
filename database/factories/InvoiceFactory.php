<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Database\Factories;

use AichaDigital\LaraVerifactu\Enums\IdTypeEnum;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\InvoiceBreakdown;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Invoice Factory
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Invoice>
     */
    protected $model = Invoice::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $baseAmount = $this->faker->randomFloat(2, 100, 10000);
        $taxAmount = $baseAmount * 0.21; // 21% IVA
        $totalAmount = $baseAmount + $taxAmount;

        return [
            'serie' => $this->faker->regexify('[A-Z]{2}'),
            'number' => $this->faker->unique()->numerify('INV-####'),
            'issue_datetime' => Carbon::now(),
            // Default to F1 (factura completa): a deterministic, AEAT-coherent
            // base case with a recipient. Use ->simplified() for F2 (which nulls
            // the recipient per rule 1190), ->rectification() for R1, and
            // ->substitute() for F3. A random simplified default would emit a
            // simplified invoice carrying a recipient, contradicting rule 1190.
            'type' => InvoiceTypeEnum::COMPLETE,
            'rectification_type' => null,
            'base_amount' => $baseAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'currency' => 'EUR',
            'recipient_nif' => $this->faker->regexify('[0-9]{8}[A-Z]'),
            'recipient_id_type' => IdTypeEnum::NIF,
            'recipient_id' => null,
            'recipient_name' => $this->faker->company(),
            'recipient_country' => 'ES',
            'regime_type' => RegimeTypeEnum::GENERAL,
            'description' => $this->faker->sentence(),
            'metadata' => null,
        ];
    }

    /**
     * Indicate that the invoice is simplified (F2).
     *
     * Simplified-ness derives from `type` (AID-187), so this sets the TipoFactura
     * to F2 and nulls the recipient (AEAT rule 1190: F2/R5 forbid Destinatarios).
     */
    public function simplified(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => InvoiceTypeEnum::SIMPLIFIED,
            'recipient_nif' => null,
            'recipient_id_type' => null,
            'recipient_id' => null,
            'recipient_name' => null,
            'recipient_country' => null,
        ]);
    }

    /**
     * Indicate that the invoice is a rectification.
     */
    public function rectification(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => InvoiceTypeEnum::RECTIFICATIVE,
            'rectification_type' => 'S',
        ]);
    }

    /**
     * Indicate that the invoice is an F3 (issued to substitute simplified invoices).
     *
     * Keeps the default recipient (F3 requires Destinatarios per AEAT rule 1189)
     * and provides the substituted invoices in metadata.
     */
    public function substitute(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => InvoiceTypeEnum::SUBSTITUTE,
            'metadata' => [
                'substituted_invoices' => [
                    ['number' => 'SIMP-0001', 'issue_date' => Carbon::now()->subDay()->toDateString()],
                ],
            ],
        ]);
    }

    /**
     * Indicate that the invoice has foreign recipient.
     */
    public function foreignRecipient(): static
    {
        return $this->state(fn (array $attributes) => [
            'recipient_nif' => null,
            'recipient_id_type' => IdTypeEnum::PASSPORT,
            'recipient_id' => $this->faker->regexify('[A-Z0-9]{10}'),
            'recipient_name' => $this->faker->company(),
            'recipient_country' => $this->faker->randomElement(['FR', 'DE', 'IT', 'PT', 'UK']),
        ]);
    }

    /**
     * Set a specific serie and number.
     */
    public function withSerieAndNumber(string $serie, string $number): static
    {
        return $this->state(fn (array $attributes) => [
            'serie' => $serie,
            'number' => $number,
        ]);
    }

    /**
     * Set a specific issue datetime.
     */
    public function issuedAt(Carbon $datetime): static
    {
        return $this->state(fn (array $attributes) => [
            'issue_datetime' => $datetime,
        ]);
    }

    /**
     * Attach a coherent default breakdown so the invoice totals back the desglose
     * sums (XmlBuilder's AEAT 1210/1216 coherence guard). The breakdown mirrors the
     * invoice's base/tax at the general 21% rate. Tests that build their own
     * breakdowns keep full control: the hook is a no-op when one already exists.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Invoice $invoice): void {
            if ($invoice->breakdowns()->exists()) {
                return;
            }

            InvoiceBreakdown::factory()->create([
                'invoice_id' => $invoice->id,
                'tax_type' => TaxTypeEnum::IVA,
                'tax_rate' => 21.00,
                'base_amount' => $invoice->base_amount,
                'exempt' => false,
            ]);
        });
    }

    /**
     * Opt out of the default breakdown for tests that manage breakdowns
     * themselves (e.g. relationship/count assertions). Runs after configure()'s
     * hook, removing the auto-created breakdown and clearing the cached relation
     * so a later lazy-load reflects only the test's own breakdowns.
     */
    public function withoutBreakdowns(): static
    {
        return $this->afterCreating(function (Invoice $invoice): void {
            $invoice->breakdowns()->delete();
            $invoice->unsetRelation('breakdowns');
        });
    }
}
