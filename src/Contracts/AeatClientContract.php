<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Support\Collection;

interface AeatClientContract
{
    /**
     * Send a registry record (registration or cancellation) to AEAT via the
     * RegFactuSistemaFacturacion operation — the only one exposed by the
     * official WSDL. The registry XML must be a complete, already-signed
     * RegFactuSistemaFacturacion document; this client does not sign.
     */
    public function sendRegistration(RegistryContract $registry): AeatResponse;

    /**
     * Send a batch of registry records to AEAT, one submission each.
     *
     * @param  Collection<int, RegistryContract>  $registries
     * @return Collection<int, AeatResponse>
     */
    public function sendBatch(Collection $registries): Collection;
}
