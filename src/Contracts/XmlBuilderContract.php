<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use AichaDigital\LaraVerifactu\Support\CancellationRecord;
use AichaDigital\LaraVerifactu\Support\RegistryChain;

interface XmlBuilderContract
{
    /**
     * Build the RegFactuSistemaFacturacion XML for a registration record
     * (RegistroAlta) conformant with the official AEAT SuministroLR schema.
     */
    public function buildRegistrationXml(InvoiceContract $invoice, RegistryChain $chain): string;

    /**
     * Build the RegFactuSistemaFacturacion XML for a cancellation record
     * (RegistroAnulacion) conformant with the official AEAT SuministroLR schema.
     */
    public function buildCancellationXml(CancellationRecord $record, RegistryChain $chain): string;

    /**
     * Validate XML against the bundled official AEAT XSD schema
     */
    public function validate(string $xml): bool;
}
