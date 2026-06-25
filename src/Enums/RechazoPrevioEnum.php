<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Enums;

/**
 * RechazoPrevioType (SuministroInformacion.xsd:754) — distinguished by whether
 * the record exists in AEAT:
 *  - N: no prior AEAT rejection.
 *  - S: prior rejection AND the record exists in AEAT (post-1.0, AID-209).
 *  - X: the record does NOT exist in AEAT (initial alta rejected) → AID-137.
 */
enum RechazoPrevioEnum: string
{
    case N = 'N';
    case S = 'S';
    case X = 'X';
}
