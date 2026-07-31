<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Models;

use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Database\Factories\RegistryFactory;
use AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Registry Model
 *
 * Native implementation of RegistryContract for Verifactu system.
 * Represents a registry entry with blockchain hash and AEAT submission details.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $registry_number
 * @property Carbon $registry_date
 * @property RegistryTypeEnum $registry_type
 * @property bool $subsanacion
 * @property RechazoPrevioEnum|null $rechazo_previo
 * @property int|null $amends_registry_id
 * @property string $hash
 * @property string|null $previous_hash
 * @property string|null $hash_generated_at
 * @property string|null $qr_url
 * @property string|null $qr_svg
 * @property string|null $qr_png
 * @property string|null $xml
 * @property string|null $signed_xml
 * @property RegistryStatusEnum $status
 * @property Carbon|null $submitted_at
 * @property string|null $aeat_csv
 * @property array<string, mixed>|null $aeat_response
 * @property string|null $aeat_error
 * @property int $submission_attempts
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Invoice $invoice
 */
class Registry extends Model implements RegistryContract
{
    /** @phpstan-use HasFactory<RegistryFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'verifactu_registries';

    /**
     * The attributes that are mass assignable.
     *
     * The chain's integrity attributes are deliberately ABSENT (AID-730):
     * `hash`, `previous_hash`, `hash_generated_at`, `xml` and `signed_xml`.
     * They are written only by the code that generates them, via forceFill().
     *
     * They used to be here, so any consumer `update()`, observer or data
     * backfill could rewrite them without resistance — and since AID-717 opened
     * a window between submission attempts, a rewritten XML meant a retry
     * presenting the agency different bytes under the same registry number.
     *
     * @var list<string>
     */
    protected $fillable = [
        'invoice_id',
        'registry_number',
        'registry_date',
        'registry_type',
        'subsanacion',
        'rechazo_previo',
        'amends_registry_id',
        'qr_url',
        'qr_svg',
        'qr_png',
        'status',
        'submitted_at',
        'aeat_csv',
        'aeat_response',
        'aeat_error',
        'submission_attempts',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registry_date' => 'datetime',
            'registry_type' => RegistryTypeEnum::class,
            'subsanacion' => 'boolean',
            'rechazo_previo' => RechazoPrevioEnum::class,
            'amends_registry_id' => 'integer',
            'submitted_at' => 'datetime',
            'submission_attempts' => 'integer',
            'status' => RegistryStatusEnum::class,
            'aeat_response' => 'array',
        ];
    }

    /**
     * The fiscal artefact — the bytes presented to the agency and the identity
     * they were presented under (AID-220).
     *
     * Once the agency has ruled, these may never change: RD 1007/2023 arts. 8
     * and 16 require integridad and inalterabilidad, and a correction is a
     * SUBSEQUENT record (RegistroAnulacion, subsanación), never a mutation of
     * the original. `amendRejected()` also reads the rejected record's persisted
     * XML to build its guards, so a rejected artefact is load-bearing too.
     *
     * Deliberately NOT sealed: status, submitted_at, aeat_csv, aeat_response,
     * aeat_error and submission_attempts. Those record what the agency ANSWERED
     * — the conversation, not the artefact — and the package's own markAs*
     * transitions write them. They have their own guards against downgrade
     * (AID-729).
     *
     * @var list<string>
     */
    private const SEALED_ATTRIBUTES = [
        'invoice_id',
        'registry_number',
        'registry_date',
        'registry_type',
        'subsanacion',
        'rechazo_previo',
        'amends_registry_id',
        'hash',
        'previous_hash',
        'hash_generated_at',
        'xml',
        'signed_xml',
    ];

    /**
     * Seal a record the agency has ruled on (AID-220).
     *
     * `hasAgencyVerdict()`, not `isFiledAtAeat()`: a REJECTED record was
     * presented too, its XML is what the agency saw, and `amendRejected()`
     * reads it back to prove the subsanación carries the same IDFactura.
     *
     * KNOWN AND DECLARED LIMIT: Eloquent fires no model event for a
     * query-builder write, so `Registry::query()->...->delete()` and
     * `DB::table(...)->update(...)` are beyond reach by construction. This is
     * stated in the README and pinned by a test rather than papered over —
     * claiming a protection the code does not give is the mistake AID-725's
     * comment made. What is closed here is the consumer's path: `$registry->
     * delete()`, `$registry->forceDelete()` and `$registry->save()`.
     */
    protected static function booted(): void
    {
        static::deleting(function (Registry $registry): void {
            if ($registry->status->hasAgencyVerdict()) {
                throw VerifactuException::make(sprintf(
                    'Registry %s was filed with the agency (status %s) and cannot be deleted. '
                    . 'A filed record must stay intact while the retention obligation lasts '
                    . '(RD 1007/2023 arts. 8 & 16); corrections are made by a subsequent record — '
                    . 'cancel() for a RegistroAnulacion, amendRejected() for a subsanación.',
                    $registry->registry_number,
                    $registry->status->value,
                ));
            }
        });

        static::updating(function (Registry $registry): void {
            if (! $registry->status->hasAgencyVerdict()) {
                return;
            }

            // getOriginal() is the state as loaded, so a transition INTO a
            // verdict is not caught here — only edits to a record that already
            // carried one when it was read.
            $tampered = array_keys(array_intersect_key(
                $registry->getDirty(),
                array_flip(self::SEALED_ATTRIBUTES)
            ));

            if ($tampered === []) {
                return;
            }

            throw VerifactuException::make(sprintf(
                'Registry %s was filed with the agency (status %s); its fiscal artefact is sealed. '
                . 'Refused change to: %s. The bytes presented to the agency and the identity they '
                . 'were presented under are immutable (RD 1007/2023 arts. 8 & 16).',
                $registry->registry_number,
                $registry->status->value,
                implode(', ', $tampered),
            ));
        });
    }

    /**
     * Get the invoice associated with this registry.
     *
     * Resolves the related model from `config('verifactu.models.invoice')`
     * so a custom mode consumer's own Eloquent model is honored instead of
     * always assuming the native `Invoice` (AID-344). Defaults to `Invoice`
     * when unset, matching native mode.
     *
     * @return BelongsTo<Invoice, static>
     */
    public function invoice(): BelongsTo
    {
        /** @var class-string<Model> $invoiceModel */
        $invoiceModel = config('verifactu.models.invoice', Invoice::class);

        /** @var BelongsTo<Invoice, static> */
        return $this->belongsTo($invoiceModel, 'invoice_id');
    }

    // ========================================
    // RegistryContract Implementation
    // ========================================

    /**
     * Get the registry unique number.
     */
    public function getRegistryNumber(): string
    {
        return $this->registry_number;
    }

    /**
     * Get the registry date.
     */
    public function getRegistryDate(): Carbon
    {
        return $this->registry_date;
    }

    /**
     * Get the registry primary key (mirrors InvoiceContract::getId()).
     */
    public function getId(): int|string|null
    {
        return $this->id;
    }

    /**
     * Get the registry type (RegistroAlta vs RegistroAnulacion).
     */
    public function getRegistryType(): RegistryTypeEnum
    {
        return $this->registry_type;
    }

    /**
     * Get the id of the rejected registry this one amends, or null.
     */
    public function getAmendsRegistryId(): ?int
    {
        return $this->amends_registry_id;
    }

    /**
     * Get the associated invoice.
     */
    public function getInvoice(): InvoiceContract
    {
        return $this->invoice;
    }

    /**
     * Get the registry hash (SHA-256).
     */
    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * Get the previous registry hash (blockchain).
     */
    public function getPreviousHash(): ?string
    {
        return $this->previous_hash;
    }

    /**
     * Get the QR code URL.
     */
    public function getQrUrl(): ?string
    {
        return $this->qr_url;
    }

    /**
     * Get the QR code as SVG.
     */
    public function getQrSvg(): ?string
    {
        return $this->qr_svg;
    }

    /**
     * Get the QR code as PNG (binary).
     */
    public function getQrPng(): ?string
    {
        return $this->qr_png;
    }

    /**
     * Get the XML representation.
     */
    public function getXml(): ?string
    {
        return $this->xml;
    }

    /**
     * Get the signed XML (with electronic signature).
     */
    public function getSignedXml(): ?string
    {
        return $this->signed_xml;
    }

    /**
     * Get the registry status.
     */
    public function getStatus(): RegistryStatusEnum
    {
        return $this->status;
    }

    /**
     * Get the submission date to AEAT.
     */
    public function getSubmittedAt(): ?Carbon
    {
        return $this->submitted_at;
    }

    /**
     * Get the AEAT CSV (confirmation code).
     */
    public function getAeatCsv(): ?string
    {
        return $this->aeat_csv;
    }

    /**
     * Get the AEAT response.
     *
     * @return array<string, mixed>|null
     */
    public function getAeatResponse(): ?array
    {
        return $this->aeat_response;
    }

    /**
     * Get the AEAT error message (if any).
     */
    public function getAeatError(): ?string
    {
        return $this->aeat_error;
    }

    /**
     * Get the number of submission attempts.
     */
    public function getSubmissionAttempts(): int
    {
        return $this->submission_attempts;
    }

    /**
     * Check if the registry was successfully submitted.
     */
    public function isSubmitted(): bool
    {
        return $this->status === RegistryStatusEnum::SENT;
    }

    /**
     * Check if the registry is pending submission.
     */
    public function isPending(): bool
    {
        return $this->status === RegistryStatusEnum::PENDING;
    }

    /**
     * Check if the registry has errors.
     */
    public function hasErrors(): bool
    {
        return $this->status === RegistryStatusEnum::ERROR;
    }
}
