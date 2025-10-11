<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Enums;

enum RegimeTypeEnum: string
{
    case GENERAL = '01'; // General regime
    case EXPORT = '02'; // Export
    case SPECIAL_USED_GOODS = '03'; // Special regime for used goods
    case SPECIAL_GOLD_INVESTMENT = '04'; // Special regime for investment gold
    case SPECIAL_TRAVEL_AGENCIES = '05'; // Special regime for travel agencies
    case SPECIAL_CRITERION_CASH = '07'; // Special cash accounting regime
    case SPECIAL_AGRICULTURE = '08'; // Special regime for agriculture, livestock and fishing
    case SUBJECT_IPSI = '09'; // IPSI taxpayer
    case BILLING_THIRD_PARTY = '10'; // Billing by recipient
    case SPECIAL_RECARGO_EQUIVALENCE = '11'; // Special equivalence surcharge regime
    case SPECIAL_SIMPLIFIED = '12'; // Simplified special regime
    case SPECIAL_OBJECTIVE_ESTIMATION = '13'; // Special regime for agriculture with objective estimation
    case TAX_FREE_ZONE = '14'; // Free zone special regime
    case NOT_SUBJECT = '15'; // Not subject

    public function getDescription(): string
    {
        return match ($this) {
            self::GENERAL => 'Régimen general',
            self::EXPORT => 'Exportación',
            self::SPECIAL_USED_GOODS => 'Régimen especial de bienes usados',
            self::SPECIAL_GOLD_INVESTMENT => 'Régimen especial del oro de inversión',
            self::SPECIAL_TRAVEL_AGENCIES => 'Régimen especial de las agencias de viajes',
            self::SPECIAL_CRITERION_CASH => 'Régimen especial del criterio de caja',
            self::SPECIAL_AGRICULTURE => 'Régimen especial de la agricultura, ganadería y pesca',
            self::SUBJECT_IPSI => 'Sujeto pasivo del IPSI',
            self::BILLING_THIRD_PARTY => 'Facturación por el destinatario',
            self::SPECIAL_RECARGO_EQUIVALENCE => 'Régimen especial del recargo de equivalencia',
            self::SPECIAL_SIMPLIFIED => 'Régimen especial simplificado',
            self::SPECIAL_OBJECTIVE_ESTIMATION => 'Régimen especial con estimación objetiva',
            self::TAX_FREE_ZONE => 'Régimen especial de zona franca',
            self::NOT_SUBJECT => 'No sujeto',
        };
    }

    public function isSpecialRegime(): bool
    {
        return $this !== self::GENERAL;
    }
}
