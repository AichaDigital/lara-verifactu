<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use AichaDigital\LaraVerifactu\Enums\IdTypeEnum;

/**
 * Recipient Contract
 *
 * Defines the interface for invoice recipients.
 */
interface RecipientContract
{
    /**
     * Get the recipient's NIF (Spanish tax ID).
     */
    public function getNif(): ?string;

    /**
     * Get the recipient's ID type (for non-Spanish recipients).
     */
    public function getIdType(): ?IdTypeEnum;

    /**
     * Get the recipient's ID (for non-Spanish recipients).
     */
    public function getId(): ?string;

    /**
     * Get the recipient's name.
     */
    public function getName(): ?string;

    /**
     * Get the recipient's country code (ISO 3166-1 alpha-2).
     */
    public function getCountry(): ?string;
}
