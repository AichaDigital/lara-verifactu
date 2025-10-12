<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Models;

use AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RecipientContract;
use AichaDigital\LaraVerifactu\Enums\IdTypeEnum;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\OperationTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Invoice Model
 *
 * Native implementation of InvoiceContract for Verifactu system.
 * Represents an invoice with all required AEAT fields.
 */
class Invoice extends Model implements InvoiceContract
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'verifactu_invoices';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'serie',
        'number',
        'issue_date',
        'issue_time',
        'type',
        'simplified',
        'rectification_type',
        'base_amount',
        'tax_amount',
        'total_amount',
        'currency',
        'recipient_nif',
        'recipient_id_type',
        'recipient_id',
        'recipient_name',
        'recipient_country',
        'regime_type',
        'operation_key',
        'description',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'issue_date' => 'date',
        'issue_time' => 'datetime:H:i:s',
        'simplified' => 'boolean',
        'base_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'metadata' => 'array',
        'type' => InvoiceTypeEnum::class,
        'recipient_id_type' => IdTypeEnum::class,
        'regime_type' => RegimeTypeEnum::class,
        'operation_key' => OperationTypeEnum::class,
    ];

    /**
     * Get the invoice registry.
     */
    public function registry(): HasOne
    {
        return $this->hasOne(Registry::class);
    }

    /**
     * Get the invoice breakdowns.
     */
    public function breakdowns(): HasMany
    {
        return $this->hasMany(InvoiceBreakdown::class);
    }

    // ========================================
    // InvoiceContract Implementation
    // ========================================

    /**
     * Get the invoice serie.
     */
    public function getSerie(): ?string
    {
        return $this->serie;
    }

    /**
     * Get the invoice number.
     */
    public function getNumber(): string
    {
        return $this->number;
    }

    /**
     * Get the invoice issue date.
     */
    public function getIssueDate(): Carbon
    {
        return $this->issue_date;
    }

    /**
     * Get the invoice issue time.
     */
    public function getIssueTime(): Carbon
    {
        return $this->issue_time;
    }

    /**
     * Get the invoice type.
     */
    public function getType(): InvoiceTypeEnum
    {
        return $this->type;
    }

    /**
     * Check if the invoice is simplified.
     */
    public function isSimplified(): bool
    {
        return $this->simplified;
    }

    /**
     * Get the rectification type (if applicable).
     */
    public function getRectificationType(): ?string
    {
        return $this->rectification_type;
    }

    /**
     * Get the invoice base amount.
     */
    public function getBaseAmount(): float
    {
        return (float) $this->base_amount;
    }

    /**
     * Get the invoice tax amount.
     */
    public function getTaxAmount(): float
    {
        return (float) $this->tax_amount;
    }

    /**
     * Get the invoice total amount.
     */
    public function getTotalAmount(): float
    {
        return (float) $this->total_amount;
    }

    /**
     * Get the invoice currency (default: EUR).
     */
    public function getCurrency(): string
    {
        return $this->currency ?? 'EUR';
    }

    /**
     * Get the recipient (returns an internal implementation).
     */
    public function getRecipient(): ?RecipientContract
    {
        if (! $this->hasRecipient()) {
            return null;
        }

        return new class($this->recipient_nif, $this->recipient_id_type, $this->recipient_id, $this->recipient_name, $this->recipient_country) implements RecipientContract
        {
            public function __construct(
                private ?string $nif,
                private ?IdTypeEnum $idType,
                private ?string $id,
                private ?string $name,
                private ?string $country
            ) {}

            public function getNif(): ?string
            {
                return $this->nif;
            }

            public function getIdType(): ?IdTypeEnum
            {
                return $this->idType;
            }

            public function getId(): ?string
            {
                return $this->id;
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

    /**
     * Check if the invoice has recipient information.
     */
    public function hasRecipient(): bool
    {
        return ! empty($this->recipient_nif) || ! empty($this->recipient_id);
    }

    /**
     * Get the tax breakdowns.
     *
     * @return Collection<int, InvoiceBreakdownContract>
     */
    public function getBreakdowns(): Collection
    {
        return $this->breakdowns;
    }

    /**
     * Get the tax regime type.
     */
    public function getRegimeType(): RegimeTypeEnum
    {
        return $this->regime_type;
    }

    /**
     * Get the operation key.
     */
    public function getOperationKey(): OperationTypeEnum
    {
        return $this->operation_key;
    }

    /**
     * Get the invoice description.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Get additional metadata as array.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata ?? [];
    }
}
