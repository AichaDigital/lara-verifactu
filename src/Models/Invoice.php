<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Models;

use AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RecipientContract;
use AichaDigital\LaraVerifactu\Database\Factories\InvoiceFactory;
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
 *
 * @property int $id
 * @property string|null $serie
 * @property string $number
 * @property Carbon $issue_datetime
 * @property InvoiceTypeEnum $type
 * @property bool $simplified
 * @property string|null $rectification_type
 * @property float $base_amount
 * @property float $tax_amount
 * @property float $total_amount
 * @property string $currency
 * @property string|null $recipient_nif
 * @property IdTypeEnum|null $recipient_id_type
 * @property string|null $recipient_id
 * @property string|null $recipient_name
 * @property string|null $recipient_country
 * @property RegimeTypeEnum $regime_type
 * @property OperationTypeEnum $operation_key
 * @property string|null $description
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Registry|null $registry
 * @property-read \Illuminate\Database\Eloquent\Collection<int, InvoiceBreakdown> $breakdowns
 */
class Invoice extends Model implements InvoiceContract
{
    /** @phpstan-use HasFactory<InvoiceFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'verifactu_invoices';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'serie',
        'number',
        'issue_datetime',
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
        'issue_datetime' => 'datetime',
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
     *
     * @return HasOne<Registry, static>
     */
    public function registry(): HasOne
    {
        /** @var HasOne<Registry, static> */
        return $this->hasOne(Registry::class);
    }

    /**
     * Get the invoice breakdowns.
     *
     * @return HasMany<InvoiceBreakdown, static>
     */
    public function breakdowns(): HasMany
    {
        /** @var HasMany<InvoiceBreakdown, static> */
        return $this->hasMany(InvoiceBreakdown::class);
    }

    // ========================================
    // InvoiceContract Implementation
    // ========================================

    /**
     * Get unique invoice ID
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Get issuer tax ID (NIF/CIF)
     */
    public function getIssuerTaxId(): string
    {
        return (string) (config('verifactu.company.tax_id') ?? '');
    }

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
     * Get complete invoice number (serie + number)
     */
    public function getInvoiceNumber(): string
    {
        return $this->serie ? $this->serie . $this->number : $this->number;
    }

    /**
     * Get the invoice issue datetime (combined date and time).
     *
     * This is the primary method for temporal ordering.
     */
    public function getIssueDatetime(): Carbon
    {
        return $this->issue_datetime;
    }

    /**
     * Get the invoice issue date.
     *
     * @deprecated Use getIssueDatetime() instead. Returns date portion only.
     */
    public function getIssueDate(): Carbon
    {
        return $this->issue_datetime->startOfDay();
    }

    /**
     * Get the invoice issue time.
     *
     * @deprecated Use getIssueDatetime() instead. Returns time portion only.
     */
    public function getIssueTime(): Carbon
    {
        return $this->issue_datetime;
    }

    /**
     * Get the invoice type.
     */
    public function getType(): InvoiceTypeEnum
    {
        return $this->type;
    }

    /**
     * Alias for getType() for backwards compatibility
     */
    public function getInvoiceType(): InvoiceTypeEnum
    {
        return $this->getType();
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
     * Get the invoices rectified by this one (AEAT FacturasRectificadas).
     *
     * Native mode reads them from metadata['rectified_invoices'], an array
     * of ['number' => string, 'issue_date' => string|Carbon] entries.
     *
     * @return array<int, array{number: string, issue_date: Carbon}>
     */
    public function getRectifiedInvoices(): array
    {
        $entries = $this->metadata['rectified_invoices'] ?? [];

        $rectified = [];

        foreach ($entries as $entry) {
            if (empty($entry['number']) || empty($entry['issue_date'])) {
                continue;
            }

            $rectified[] = [
                'number' => (string) $entry['number'],
                'issue_date' => Carbon::parse($entry['issue_date']),
            ];
        }

        return $rectified;
    }

    /**
     * Get the rectified amounts for substitution rectifications (TipoRectificativa = S).
     *
     * Native mode reads them from metadata['rectification_amounts'] as
     * ['base' => numeric, 'tax' => numeric, 'surcharge' => numeric|null].
     * Returns null when base or tax are missing or non-numeric. 0.00 is a valid
     * amount and is preserved (hence array_key_exists + is_numeric, not empty()).
     * The surcharge is included only when present and numeric.
     *
     * @return array{base: float, tax: float, surcharge: float|null}|null
     */
    public function getRectificationAmounts(): ?array
    {
        $amounts = $this->metadata['rectification_amounts'] ?? null;

        if (! is_array($amounts)
            || ! array_key_exists('base', $amounts) || ! is_numeric($amounts['base'])
            || ! array_key_exists('tax', $amounts) || ! is_numeric($amounts['tax'])) {
            return null;
        }

        $surcharge = (array_key_exists('surcharge', $amounts) && is_numeric($amounts['surcharge']))
            ? (float) $amounts['surcharge']
            : null;

        return [
            'base' => (float) $amounts['base'],
            'tax' => (float) $amounts['tax'],
            'surcharge' => $surcharge,
        ];
    }

    /**
     * Get previous invoice ID for rectifications
     */
    public function getPreviousInvoiceId(): ?string
    {
        return $this->metadata['previous_invoice_id'] ?? null;
    }

    /**
     * Get previous invoice hash for rectifications
     */
    public function getPreviousHash(): ?string
    {
        return $this->metadata['previous_hash'] ?? null;
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
        return ($this->recipient_nif !== null && $this->recipient_nif !== '')
            || ($this->recipient_id !== null && $this->recipient_id !== '');
    }

    /**
     * Get the tax breakdowns.
     *
     * @return Collection<int, InvoiceBreakdownContract>
     */
    public function getBreakdowns(): Collection
    {
        /** @var Collection<int, InvoiceBreakdownContract> $collection */
        $collection = collect($this->breakdowns->all())
            ->map(static fn (InvoiceBreakdownContract $breakdown): InvoiceBreakdownContract => $breakdown)
            ->values();

        return $collection;
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

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Handle cascade deletes when soft deleting
        static::deleting(function (Invoice $invoice): void {
            if ($invoice->isForceDeleting()) {
                return; // Let database cascade handle it
            }

            // Soft delete registry
            $invoice->registry()->delete();

            // Soft delete breakdowns
            $invoice->breakdowns()->delete();
        });
    }
}
