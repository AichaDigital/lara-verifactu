<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Database\Factories;

use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Registry Factory
 *
 * @extends Factory<Registry>
 */
class RegistryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Registry>
     */
    protected $model = Registry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'registry_number' => 'REG-' . $this->faker->unique()->numerify('######'),
            'registry_date' => Carbon::now(),
            'hash' => hash('sha256', $this->faker->uuid()),
            'previous_hash' => null,
            'qr_url' => null,
            'qr_svg' => null,
            'qr_png' => null,
            'xml' => '<xml></xml>',
            'signed_xml' => null,
            'status' => RegistryStatusEnum::PENDING,
            'submitted_at' => null,
            'aeat_csv' => null,
            'aeat_response' => null,
            'aeat_error' => null,
            'submission_attempts' => 0,
        ];
    }

    /**
     * Indicate that the registry is submitted.
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RegistryStatusEnum::SUBMITTED,
            'submitted_at' => Carbon::now(),
            'aeat_csv' => 'CSV-' . $this->faker->regexify('[A-Z0-9]{16}'),
            'aeat_response' => 'Accepted',
            'submission_attempts' => 1,
        ]);
    }

    /**
     * Indicate that the registry has failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RegistryStatusEnum::FAILED,
            'aeat_error' => 'Submission error',
            'submission_attempts' => 3,
        ]);
    }

    /**
     * Set a specific invoice.
     */
    public function forInvoice(Invoice $invoice): static
    {
        return $this->state(fn (array $attributes) => [
            'invoice_id' => $invoice->id,
        ]);
    }

    /**
     * Set a previous hash (blockchain).
     */
    public function withPreviousHash(string $previousHash): static
    {
        return $this->state(fn (array $attributes) => [
            'previous_hash' => $previousHash,
        ]);
    }

    /**
     * Include QR code.
     */
    public function withQr(): static
    {
        return $this->state(fn (array $attributes) => [
            'qr_url' => 'https://example.com/qr/' . $this->faker->uuid(),
            'qr_svg' => '<svg></svg>',
            'qr_png' => base64_encode('fake_png_data'),
        ]);
    }

    /**
     * Include signed XML.
     */
    public function signed(): static
    {
        return $this->state(fn (array $attributes) => [
            'signed_xml' => '<xml><Signature></Signature></xml>',
        ]);
    }
}
