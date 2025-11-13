<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \AichaDigital\LaraVerifactu\Support\AeatResponse register(\AichaDigital\LaraVerifactu\Contracts\InvoiceContract $invoice)
 * @method static \AichaDigital\LaraVerifactu\Support\AeatResponse cancel(string $registryId)
 * @method static \Illuminate\Support\Collection<int, mixed> sendBatch(\Illuminate\Support\Collection<int, \AichaDigital\LaraVerifactu\Contracts\InvoiceContract> $invoices)
 * @method static \AichaDigital\LaraVerifactu\Support\AeatResponse status(\AichaDigital\LaraVerifactu\Contracts\InvoiceContract $invoice)
 * @method static string qr(\AichaDigital\LaraVerifactu\Contracts\InvoiceContract $invoice)
 * @method static bool validateChain(\AichaDigital\LaraVerifactu\Contracts\InvoiceContract $invoice)
 * @method static void fake()
 * @method static void assertRegistered(\AichaDigital\LaraVerifactu\Contracts\InvoiceContract $invoice)
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
