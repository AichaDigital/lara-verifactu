<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Database\Factories;

use AichaDigital\LaraVerifactu\Enums\IdTypeEnum;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use AichaDigital\LaraVerifactu\Models\Invoice;
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
            // Default to a non-rectificative, non-substitute type: rectificative
            // types require a rectification_type (S/I) and SUBSTITUTE (F3) requires
            // substituted-invoice metadata. Use the ->rectification() / ->substitute()
            // states for those. Emitting a rectificative here with a null
            // rectification_type produces XML the honest core rejects (AID-179).
            'type' => $this->faker->randomElement(array_values(array_filter(
                InvoiceTypeEnum::cases(),
                fn (InvoiceTypeEnum $t): bool => $t !== InvoiceTypeEnum::SUBSTITUTE && ! $t->isRectificative(),
            ))),
            'simplified' => false,
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
     * Indicate that the invoice is simplified.
     */
    public function simplified(): static
    {
        return $this->state(fn (array $attributes) => [
            'simplified' => true,
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
}
