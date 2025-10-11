<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;

interface RegistryContract
{
    public function getRegistryId(): string;

    public function getInvoice(): InvoiceContract;

    public function getHash(): string;

    public function getSignature(): ?string;

    public function getQrCode(): string;

    public function getXmlContent(): string;

    public function getStatus(): RegistryStatusEnum;

    public function getAeatResponse(): ?array;

    public function markAsSent(): void;

    public function markAsAccepted(): void;

    public function markAsRejected(array $errors): void;
}
