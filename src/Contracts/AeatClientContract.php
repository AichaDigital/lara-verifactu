<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Support\Collection;

interface AeatClientContract
{
    /**
     * Send registration to AEAT
     */
    public function sendRegistration(RegistryContract $registry): AeatResponse;

    /**
     * Send cancellation to AEAT
     */
    public function sendCancellation(string $registryId): AeatResponse;

    /**
     * Send batch of registrations to AEAT
     *
     * @param  \Illuminate\Support\Collection<int, RegistryContract>  $registries
     * @return \Illuminate\Support\Collection<int, AeatResponse>
     */
    public function sendBatch(Collection $registries): Collection;

    /**
     * Query registry status from AEAT
     */
    public function queryRegistry(string $registryId): AeatResponse;

    /**
     * Validate QR code with AEAT
     */
    public function validateQr(string $qrCode): AeatResponse;
}
