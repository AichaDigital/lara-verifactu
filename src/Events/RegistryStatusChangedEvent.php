<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Events;

use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Registry Status Changed Event (AID-1626)
 *
 * Fired once per persisted change of a registry's outcome, after the write
 * commits, so one listener follows a registry's fiscal fate without parsing
 * messages. `$from` is null on creation. `$error` is the readable reason for
 * error, exhausted and rejected. `$lines` is the agency's per-line detail for
 * rejected and accepted_with_errors, in the AeatResponseParser shape
 * (estado_registro, codigo, descripcion, registro_duplicado,
 * registro_duplicado_estado); null when unknown or not applicable. For a
 * duplicate answered AceptadaConErrores, `$lines` (and the persisted
 * `aeat_response.lineas` / `estado_envio`) come from the duplicate answer
 * (code 3000, `Incorrecto`), not from the original record's warnings.
 */
class RegistryStatusChangedEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>|null  $lines
     */
    public function __construct(
        public readonly RegistryContract $registry,
        public readonly ?RegistryStatusEnum $from,
        public readonly RegistryStatusEnum $to,
        public readonly ?string $error,
        public readonly ?array $lines,
    ) {}
}
