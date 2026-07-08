<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Tests\Fixtures;

use AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RecipientContract;
use AichaDigital\LaraVerifactu\Enums\IdTypeEnum;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * AID-344 test fixture: a genuinely external "custom mode" invoice model,
 * backed by its own table (`custom_invoices`), deliberately NOT the native
 * `verifactu_invoices` table. Proves `Registry::invoice()` and the
 * registries FK no longer assume the native model/table.
 *
 * Fixed to a valid F1 (complete) invoice with a domestic recipient and a
 * single 21% IVA breakdown — enough to pass XmlBuilder's fail-loud
 * validation end to end without exercising every AEAT edge case (that's
 * already covered by the native-model test suite).
 */
final class CustomInvoice extends Model implements InvoiceContract
{
    protected $table = 'custom_invoices';

    protected $guarded = [];

    protected $casts = [
        'issue_datetime' => 'datetime',
        'base_amount' => 'float',
        'tax_amount' => 'float',
        'total_amount' => 'float',
    ];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIssuerTaxId(): string
    {
        return (string) (config('verifactu.company.tax_id') ?? '');
    }

    public function getSerie(): ?string
    {
        return $this->serie;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getInvoiceNumber(): string
    {
        return $this->serie ? $this->serie . $this->number : $this->number;
    }

    public function getIssueDatetime(): Carbon
    {
        return $this->issue_datetime;
    }

    public function getIssueDate(): Carbon
    {
        return $this->issue_datetime->clone()->startOfDay();
    }

    public function getIssueTime(): Carbon
    {
        return $this->issue_datetime;
    }

    public function getType(): InvoiceTypeEnum
    {
        return InvoiceTypeEnum::COMPLETE;
    }

    public function getInvoiceType(): InvoiceTypeEnum
    {
        return $this->getType();
    }

    public function isSimplified(): bool
    {
        return false;
    }

    public function getRectificationType(): ?string
    {
        return null;
    }

    public function getRectifiedInvoices(): array
    {
        return [];
    }

    public function getRectificationAmounts(): ?array
    {
        return null;
    }

    public function getSubstitutedInvoices(): array
    {
        return [];
    }

    public function getPreviousInvoiceId(): ?string
    {
        return null;
    }

    public function getPreviousHash(): ?string
    {
        return null;
    }

    public function getBaseAmount(): float
    {
        return (float) $this->base_amount;
    }

    public function getTaxAmount(): float
    {
        return (float) $this->tax_amount;
    }

    public function getTotalAmount(): float
    {
        return (float) $this->total_amount;
    }

    public function getCurrency(): string
    {
        return 'EUR';
    }

    public function getRecipient(): ?RecipientContract
    {
        if (! $this->hasRecipient()) {
            return null;
        }

        return new class($this->recipient_nif, $this->recipient_name, $this->recipient_country) implements RecipientContract
        {
            public function __construct(
                private readonly ?string $nif,
                private readonly ?string $name,
                private readonly ?string $country,
            ) {}

            public function getNif(): ?string
            {
                return $this->nif;
            }

            public function getIdType(): ?IdTypeEnum
            {
                return IdTypeEnum::NIF;
            }

            public function getId(): ?string
            {
                return $this->nif;
            }

            public function getName(): ?string
            {
                return $this->name;
            }

            public function getCountry(): ?string
            {
                return $this->country;
            }
        };
    }

    public function hasRecipient(): bool
    {
        return $this->recipient_nif !== null && $this->recipient_nif !== '';
    }

    /**
     * @return Collection<int, InvoiceBreakdownContract>
     */
    public function getBreakdowns(): Collection
    {
        return collect([
            new CustomInvoiceBreakdown(
                baseAmount: $this->getBaseAmount(),
                taxAmount: $this->getTaxAmount(),
                taxRate: 21.00,
            ),
        ]);
    }

    public function getRegimeType(): RegimeTypeEnum
    {
        return RegimeTypeEnum::GENERAL;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return [];
    }
}
