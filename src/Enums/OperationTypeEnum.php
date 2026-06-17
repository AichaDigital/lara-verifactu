<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Enums;

/**
 * LEGACY — not an official AEAT code list. Its values (01-07) map to no Verifactu
 * list and XmlBuilder ignores `Invoice::getOperationKey()` entirely. The real
 * operation classification (L9: S1/S2/N1/N2) is CalificacionOperacionEnum, which
 * lives on the breakdown. This enum and the `operation_key` column are slated for
 * removal in AID-179. Do not build new behavior on it.
 */
enum OperationTypeEnum: string
{
    case NORMAL = '01'; // Normal operation
    case INTRA_COMMUNITY_ACQUISITION = '02'; // Intra-community acquisition
    case IMPORT = '03'; // Import
    case REVERSE_CHARGE = '04'; // Reverse charge
    case NOT_SUBJECT_ARTICLE_7_14 = '05'; // Not subject by location rules
    case NOT_SUBJECT_ARTICLE_7_14_OTHER = '06'; // Not subject for other reasons
    case EXEMPT = '07'; // Exempt

    public function getDescription(): string
    {
        return match ($this) {
            self::NORMAL => 'Operación normal',
            self::INTRA_COMMUNITY_ACQUISITION => 'Adquisición intracomunitaria',
            self::IMPORT => 'Importación',
            self::REVERSE_CHARGE => 'Inversión del sujeto pasivo',
            self::NOT_SUBJECT_ARTICLE_7_14 => 'No sujeto por reglas de localización (Art. 7, 14)',
            self::NOT_SUBJECT_ARTICLE_7_14_OTHER => 'No sujeto por otras razones',
            self::EXEMPT => 'Exenta',
        };
    }

    public function isSubjectToTax(): bool
    {
        return ! in_array($this, [
            self::NOT_SUBJECT_ARTICLE_7_14,
            self::NOT_SUBJECT_ARTICLE_7_14_OTHER,
            self::EXEMPT,
        ]);
    }
}
