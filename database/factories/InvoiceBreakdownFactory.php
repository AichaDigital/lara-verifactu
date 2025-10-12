<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Database\Factories;

use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\InvoiceBreakdown;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Invoice Breakdown Factory
 *
 * @extends Factory<InvoiceBreakdown>
 */
class InvoiceBreakdownFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<InvoiceBreakdown>
     */
    protected $model = InvoiceBreakdown::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $baseAmount = $this->faker->randomFloat(2, 100, 5000);
        $taxRate = 21.00;
        $taxAmount = round($baseAmount * ($taxRate / 100), 2);

        return [
            'invoice_id' => Invoice::factory(),
            'tax_type' => TaxTypeEnum::IVA,
            'tax_rate' => $taxRate,
            'base_amount' => $baseAmount,
            'tax_amount' => $taxAmount,
            'surcharge_rate' => null,
            'surcharge_amount' => null,
            'exempt' => false,
            'exemption_reason' => null,
            'metadata' => null,
        ];
    }

    /**
     * Set IVA at 21%.
     */
    public function iva21(): static
    {
        return $this->state(fn (array $attributes) => [
            'tax_type' => TaxTypeEnum::IVA,
            'tax_rate' => 21.00,
            'tax_amount' => round($attributes['base_amount'] * 0.21, 2),
        ]);
    }

    /**
     * Set IVA at 10%.
     */
    public function iva10(): static
    {
        return $this->state(fn (array $attributes) => [
            'tax_type' => TaxTypeEnum::IVA,
            'tax_rate' => 10.00,
            'tax_amount' => round($attributes['base_amount'] * 0.10, 2),
        ]);
    }

    /**
     * Set IVA at 4%.
     */
    public function iva4(): static
    {
        return $this->state(fn (array $attributes) => [
            'tax_type' => TaxTypeEnum::IVA,
            'tax_rate' => 4.00,
            'tax_amount' => round($attributes['base_amount'] * 0.04, 2),
        ]);
    }

    /**
     * Set as exempt.
     */
    public function exempt(?string $reason = 'E1'): static
    {
        return $this->state(fn (array $attributes) => [
            'exempt' => true,
            'exemption_reason' => $reason,
            'tax_rate' => 0.00,
            'tax_amount' => 0.00,
        ]);
    }

    /**
     * Include surcharge (recargo de equivalencia).
     */
    public function withSurcharge(float $rate): static
    {
        return $this->state(fn (array $attributes) => [
            'surcharge_rate' => $rate,
            'surcharge_amount' => round($attributes['base_amount'] * ($rate / 100), 2),
        ]);
    }

    /**
     * Set for a specific invoice.
     */
    public function forInvoice(Invoice $invoice): static
    {
        return $this->state(fn (array $attributes) => [
            'invoice_id' => $invoice->id,
        ]);
    }
}
