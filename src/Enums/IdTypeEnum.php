<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Enums;

enum IdTypeEnum: string
{
    case NIF = '02'; // NIF-IVA
    case PASSPORT = '03'; // Pasaporte
    case OFFICIAL_DOC = '04'; // Documento oficial expedido por el país o territorio de residencia
    case RESIDENCE_CERTIFICATE = '05'; // Certificado de residencia
    case OTHER = '06'; // Otro documento probatorio
    case NOT_REGISTERED = '07'; // No censado

    public function getDescription(): string
    {
        return match ($this) {
            self::NIF => 'NIF-IVA',
            self::PASSPORT => 'Pasaporte',
            self::OFFICIAL_DOC => 'Documento oficial expedido por el país o territorio de residencia',
            self::RESIDENCE_CERTIFICATE => 'Certificado de residencia',
            self::OTHER => 'Otro documento probatorio',
            self::NOT_REGISTERED => 'No censado',
        };
    }

    public function isSpanishId(): bool
    {
        return $this === self::NIF;
    }

    public function isForeignId(): bool
    {
        return ! $this->isSpanishId() && $this !== self::NOT_REGISTERED;
    }
}
