<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Models;

use AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RecipientContract;
use AichaDigital\LaraVerifactu\Database\Factories\InvoiceFactory;
use AichaDigital\LaraVerifactu\Enums\IdTypeEnum;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string|null $description
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Registry|null $registry
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Registry> $registries
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
        'description',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issue_datetime' => 'datetime',
            'base_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'metadata' => 'array',
            'type' => InvoiceTypeEnum::class,
            'recipient_id_type' => IdTypeEnum::class,
            'regime_type' => RegimeTypeEnum::class,
        ];
    }

    /**
     * The invoice's CURRENT registry — the most recent link of its chain.
     *
     * Singular by contract, not by domain. An invoice holds 1→N registries: a
     * subsanación (AID-137) adds a SECOND `registration` row, and a cancellation
     * adds another. With no order, `hasOne()` handed back whichever row the
     * engine felt like returning first, so after an amendment a consumer panel
     * could show the REJECTED record — no CSV, superseded status — as the live
     * one (AID-734).
     *
     * `orderByDesc('id')`, deliberately NOT `latestOfMany()`. `ofMany()` sets
     * isOneOfMany, which registers a beforeQuery callback that joins an
     * aggregate subquery onto the relation's OWN builder. Every write issued
     * through the relation would then carry that join and touch a single row —
     * silently turning the cascade in boot() into a partial one, because
     * SoftDeletingScope qualifies the column when joins are present and Laravel
     * compiles it without complaint. Measured: with latestOfMany() two of three
     * registries survive alive under a deleted invoice. An ORDER BY lives only
     * in the read path (getResults() calls first(); eager loading takes the
     * first row per parent), leaves writes without a LIMIT, and is invisible to
     * whereHas()/doesntHave(), which build their EXISTS subquery from a fresh
     * builder.
     *
     * Ordered by `id` to match Verifactu::status(), which the README documents
     * as "latest registry of an invoice" — two APIs of one package must not
     * contradict each other about the same invoice. NOT by `registry_date`:
     * that is RegistryManager::getPreviousRegistry()'s key, and it answers a
     * different question (the head of the GLOBAL chain, ordered by fiscal
     * timestamp).
     *
     * Soft-deleted rows stay excluded, on purpose. This relation is
     * PRESENTATION: a trashed row must never surface as live state. Chain
     * questions — "does a registration of record exist?" — are answered by
     * scopePendingRegistration() and RegistryManager::assertNoRootRegistration(),
     * which count trashed rows because the chain links over what EXISTED
     * (AID-728).
     *
     * @return HasOne<Registry, static>
     */
    public function registry(): HasOne
    {
        /** @var HasOne<Registry, static> */
        return $this->hasOne(Registry::class)->orderByDesc('id');
    }

    /**
     * Every registry of this invoice — the honest shape of the domain.
     *
     * `registry()` is the singular accessor kept for the published contract;
     * this is the real cardinality. Anything reasoning over the whole chain of
     * an invoice belongs here.
     *
     * Deliberately UNORDERED: this relation carries the cascade in boot(), and
     * an ORDER BY is harmless on an UPDATE only while no LIMIT is set — a
     * property no future edit should have to know about.
     *
     * Soft-deleted rows are excluded (SoftDeletingScope); for chain questions
     * use ->withTrashed().
     *
     * @return HasMany<Registry, static>
     */
    public function registries(): HasMany
    {
        /** @var HasMany<Registry, static> */
        return $this->hasMany(Registry::class);
    }

    /**
     * Scope: invoices with no registration OF RECORD (AID-741).
     *
     * NOT `doesntHave('registry')`, for two independent reasons:
     *
     *  1. Registry uses SoftDeletes, so the relation's EXISTS subquery carries
     *     `deleted_at is null`. An invoice whose registration was soft-deleted
     *     came back as "pending", and the work item that produced could only
     *     fail: assertNoRootRegistration() counts trashed rows, so register()
     *     throws — and the job's failed() handler logs that as "Fiscal
     *     verification system BLOCKED". The chain links over what EXISTED
     *     (AID-728); a trashed root still holds its slot.
     *  2. The relation does not discriminate registry_type. An invoice holding
     *     only a `cancellation` has no alta and IS pending; `doesntHave` said
     *     otherwise and hid the pathology.
     *
     * The predicate is deliberately IDENTICAL to assertNoRootRegistration().
     * Any drift between the two re-opens the "queue a job that can only throw"
     * failure mode.
     *
     * @param  Builder<static>  $query
     */
    public function scopePendingRegistration(Builder $query): void
    {
        $query->whereNotExists(
            Registry::withTrashed()
                ->whereColumn(
                    (new Registry)->qualifyColumn('invoice_id'),
                    $this->qualifyColumn($this->getKeyName()),
                )
                ->where('registry_type', RegistryTypeEnum::REGISTRATION->value)
        );
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
        return $this->getType()->isSimplified();
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
     * Get the invoices substituted by this one (AEAT FacturasSustituidas).
     *
     * Native mode reads them from metadata['substituted_invoices'], an array of
     * ['number' => string, 'issue_date' => string|Carbon] entries. Applies to
     * invoice type F3. Returns an empty array when none are identified.
     *
     * @return array<int, array{number: string, issue_date: Carbon}>
     */
    public function getSubstitutedInvoices(): array
    {
        $entries = $this->metadata['substituted_invoices'] ?? [];

        $substituted = [];

        foreach ($entries as $entry) {
            if (empty($entry['number']) || empty($entry['issue_date'])) {
                continue;
            }

            $substituted[] = [
                'number' => (string) $entry['number'],
                'issue_date' => Carbon::parse($entry['issue_date']),
            ];
        }

        return $substituted;
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

            // Soft delete every registry the agency has NOT ruled on (AID-734,
            // AID-220).
            //
            // 1→N, and never through the singular relation: any determinism
            // trick on registry() — latestOfMany(), ofMany(), a LIMIT — would
            // silently narrow this to one row and leave the rest alive under a
            // deleted invoice. Measured, not feared: with latestOfMany() two of
            // three registries survived.
            //
            // Filed records are skipped, not deleted. The invoice may go; the
            // record the agency holds may not, for as long as the retention
            // obligation lasts (RD 1007/2023 arts. 8 & 16). A soft-deleted
            // invoice with its sealed registry alive is the correct outcome —
            // see docs/notes/lara-privacy-immutability-vs-erasure.md. The filter
            // lives here because this is a query-builder write: the model's
            // seal hook fires no event for it.
            $invoice->registries()
                ->whereNotIn('status', RegistryStatusEnum::agencyVerdictValues())
                ->delete();

            // Soft delete breakdowns
            $invoice->breakdowns()->delete();
        });
    }
}
