<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \AichaDigital\LaraVerifactu\Contracts\RegistryContract register(\AichaDigital\LaraVerifactu\Contracts\InvoiceContract $invoice, bool $submitToAeat = true)
 * @method static \AichaDigital\LaraVerifactu\Contracts\RegistryContract cancel(\AichaDigital\LaraVerifactu\Contracts\InvoiceContract $invoice, bool $submitToAeat = true)
 * @method static array{success: int, failed: int, registries: array<\AichaDigital\LaraVerifactu\Contracts\RegistryContract>} sendBatch(iterable<\AichaDigital\LaraVerifactu\Contracts\InvoiceContract> $invoices, bool $submitToAeat = true)
 * @method static \AichaDigital\LaraVerifactu\Contracts\RegistryContract|null status(\AichaDigital\LaraVerifactu\Contracts\InvoiceContract $invoice)
 * @method static string qr(\AichaDigital\LaraVerifactu\Contracts\InvoiceContract $invoice)
 * @method static array{valid: bool, errors: array<string>} validateChain()
 * @method static void fake()
 * @method static void assertRegistered(\AichaDigital\LaraVerifactu\Contracts\InvoiceContract $invoice)
 * @method static void assertCancelled(\AichaDigital\LaraVerifactu\Contracts\InvoiceContract $invoice)
 * @method static void assertNotSent(\AichaDigital\LaraVerifactu\Contracts\InvoiceContract $invoice)
 *
 * @see \AichaDigital\LaraVerifactu\Verifactu
 */
class Verifactu extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'verifactu';
    }
}
