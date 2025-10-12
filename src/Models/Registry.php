<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Models;

use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
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
 * @property \Carbon\Carbon $registry_date
 * @property string $hash
 * @property string|null $previous_hash
 * @property string|null $qr_url
 * @property string|null $qr_svg
 * @property string|null $qr_png
 * @property string|null $xml
 * @property string|null $signed_xml
 * @property RegistryStatusEnum $status
 * @property \Carbon\Carbon|null $submitted_at
 * @property string|null $aeat_csv
 * @property array<string, mixed>|null $aeat_response
 * @property string|null $aeat_error
 * @property int $submission_attempts
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * @property-read Invoice $invoice
 */
class Registry extends Model implements RegistryContract
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'verifactu_registries';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'invoice_id',
        'registry_number',
        'registry_date',
        'hash',
        'previous_hash',
        'qr_url',
        'qr_svg',
        'qr_png',
        'xml',
        'signed_xml',
        'status',
        'submitted_at',
        'aeat_csv',
        'aeat_response',
        'aeat_error',
        'submission_attempts',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'registry_date' => 'datetime',
        'submitted_at' => 'datetime',
        'submission_attempts' => 'integer',
        'status' => RegistryStatusEnum::class,
    ];

    /**
     * Get the invoice associated with this registry.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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
    public function getXml(): string
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
     */
    public function getAeatResponse(): ?string
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
