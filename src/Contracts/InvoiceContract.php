<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use Carbon\Carbon;
use Illuminate\Support\Collection;

interface InvoiceContract
{
    public function getIssuerTaxId(): string;

    public function getInvoiceNumber(): string;

    public function getIssueDate(): Carbon;

    public function getInvoiceType(): InvoiceTypeEnum;

    public function getDescription(): ?string;

    public function getTotalAmount(): string;

    public function getTotalTaxAmount(): string;

    /**
     * @return Collection<InvoiceBreakdownContract>
     */
    public function getBreakdowns(): Collection;

    public function getRecipient(): ?RecipientContract;

    public function getPreviousInvoiceId(): ?string;

    public function getPreviousHash(): ?string;
}
