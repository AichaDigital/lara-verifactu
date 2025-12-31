<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\IdTypeEnum;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\OperationTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;

// ========================================
// InvoiceTypeEnum Tests
// ========================================

describe('InvoiceTypeEnum', function () {
    it('has correct values for all cases', function () {
        expect(InvoiceTypeEnum::COMPLETE->value)->toBe('F1');
        expect(InvoiceTypeEnum::SIMPLIFIED->value)->toBe('F2');
        expect(InvoiceTypeEnum::RECTIFICATIVE->value)->toBe('R1');
        expect(InvoiceTypeEnum::RECTIFICATIVE_SIMPLIFIED->value)->toBe('R2');
        expect(InvoiceTypeEnum::RECTIFICATIVE_BY_SUBSTITUTION->value)->toBe('R3');
        expect(InvoiceTypeEnum::RECTIFICATIVE_SUMMARY->value)->toBe('R4');
        expect(InvoiceTypeEnum::RECTIFICATIVE_SUMMARY_SIMPLIFIED->value)->toBe('R5');
    });

    it('returns correct description for each type', function () {
        expect(InvoiceTypeEnum::COMPLETE->getDescription())->toBe('Factura completa');
        expect(InvoiceTypeEnum::SIMPLIFIED->getDescription())->toBe('Factura simplificada');
        expect(InvoiceTypeEnum::RECTIFICATIVE->getDescription())->toBe('Factura rectificativa');
    });

    it('identifies rectificative invoices correctly', function () {
        expect(InvoiceTypeEnum::COMPLETE->isRectificative())->toBeFalse();
        expect(InvoiceTypeEnum::SIMPLIFIED->isRectificative())->toBeFalse();
        expect(InvoiceTypeEnum::RECTIFICATIVE->isRectificative())->toBeTrue();
        expect(InvoiceTypeEnum::RECTIFICATIVE_SIMPLIFIED->isRectificative())->toBeTrue();
        expect(InvoiceTypeEnum::RECTIFICATIVE_BY_SUBSTITUTION->isRectificative())->toBeTrue();
        expect(InvoiceTypeEnum::RECTIFICATIVE_SUMMARY->isRectificative())->toBeTrue();
        expect(InvoiceTypeEnum::RECTIFICATIVE_SUMMARY_SIMPLIFIED->isRectificative())->toBeTrue();
    });

    it('identifies simplified invoices correctly', function () {
        expect(InvoiceTypeEnum::COMPLETE->isSimplified())->toBeFalse();
        expect(InvoiceTypeEnum::SIMPLIFIED->isSimplified())->toBeTrue();
        expect(InvoiceTypeEnum::RECTIFICATIVE->isSimplified())->toBeFalse();
        expect(InvoiceTypeEnum::RECTIFICATIVE_SIMPLIFIED->isSimplified())->toBeTrue();
        expect(InvoiceTypeEnum::RECTIFICATIVE_SUMMARY_SIMPLIFIED->isSimplified())->toBeTrue();
    });
});

// ========================================
// TaxTypeEnum Tests
// ========================================

describe('TaxTypeEnum', function () {
    it('has correct values for all cases', function () {
        expect(TaxTypeEnum::IVA->value)->toBe('01');
        expect(TaxTypeEnum::IPSI->value)->toBe('02');
        expect(TaxTypeEnum::IGIC->value)->toBe('03');
        expect(TaxTypeEnum::IRPF->value)->toBe('04');
        expect(TaxTypeEnum::OTHER->value)->toBe('05');
    });

    it('returns correct description for each type', function () {
        expect(TaxTypeEnum::IVA->getDescription())->toContain('IVA');
        expect(TaxTypeEnum::IPSI->getDescription())->toContain('IPSI');
        expect(TaxTypeEnum::IGIC->getDescription())->toContain('IGIC');
        expect(TaxTypeEnum::IRPF->getDescription())->toContain('IRPF');
    });

    it('identifies indirect taxes correctly', function () {
        expect(TaxTypeEnum::IVA->isIndirectTax())->toBeTrue();
        expect(TaxTypeEnum::IPSI->isIndirectTax())->toBeTrue();
        expect(TaxTypeEnum::IGIC->isIndirectTax())->toBeTrue();
        expect(TaxTypeEnum::IRPF->isIndirectTax())->toBeFalse();
        expect(TaxTypeEnum::OTHER->isIndirectTax())->toBeFalse();
    });

    it('identifies direct taxes correctly', function () {
        expect(TaxTypeEnum::IRPF->isDirectTax())->toBeTrue();
        expect(TaxTypeEnum::IVA->isDirectTax())->toBeFalse();
        expect(TaxTypeEnum::IPSI->isDirectTax())->toBeFalse();
    });
});

// ========================================
// RegistryStatusEnum Tests
// ========================================

describe('RegistryStatusEnum', function () {
    it('has correct values for all cases', function () {
        expect(RegistryStatusEnum::PENDING->value)->toBe('pending');
        expect(RegistryStatusEnum::SENT->value)->toBe('sent');
        expect(RegistryStatusEnum::ACCEPTED->value)->toBe('accepted');
        expect(RegistryStatusEnum::REJECTED->value)->toBe('rejected');
        expect(RegistryStatusEnum::ERROR->value)->toBe('error');
    });

    it('returns correct description for each status', function () {
        expect(RegistryStatusEnum::PENDING->getDescription())->toBe('Pendiente de envío');
        expect(RegistryStatusEnum::SENT->getDescription())->toBe('Enviado a AEAT');
        expect(RegistryStatusEnum::ACCEPTED->getDescription())->toBe('Aceptado por AEAT');
        expect(RegistryStatusEnum::REJECTED->getDescription())->toBe('Rechazado por AEAT');
        expect(RegistryStatusEnum::ERROR->getDescription())->toBe('Error en procesamiento');
    });

    it('identifies final statuses correctly', function () {
        expect(RegistryStatusEnum::PENDING->isFinal())->toBeFalse();
        expect(RegistryStatusEnum::SENT->isFinal())->toBeFalse();
        expect(RegistryStatusEnum::ACCEPTED->isFinal())->toBeTrue();
        expect(RegistryStatusEnum::REJECTED->isFinal())->toBeTrue();
        expect(RegistryStatusEnum::ERROR->isFinal())->toBeFalse();
    });

    it('identifies pending statuses correctly', function () {
        expect(RegistryStatusEnum::PENDING->isPending())->toBeTrue();
        expect(RegistryStatusEnum::SENT->isPending())->toBeTrue();
        expect(RegistryStatusEnum::ACCEPTED->isPending())->toBeFalse();
        expect(RegistryStatusEnum::REJECTED->isPending())->toBeFalse();
        expect(RegistryStatusEnum::ERROR->isPending())->toBeFalse();
    });

    it('identifies successful status correctly', function () {
        expect(RegistryStatusEnum::ACCEPTED->isSuccessful())->toBeTrue();
        expect(RegistryStatusEnum::PENDING->isSuccessful())->toBeFalse();
        expect(RegistryStatusEnum::REJECTED->isSuccessful())->toBeFalse();
    });

    it('identifies retryable statuses correctly', function () {
        expect(RegistryStatusEnum::PENDING->canRetry())->toBeTrue();
        expect(RegistryStatusEnum::ERROR->canRetry())->toBeTrue();
        expect(RegistryStatusEnum::REJECTED->canRetry())->toBeTrue();
        expect(RegistryStatusEnum::SENT->canRetry())->toBeFalse();
        expect(RegistryStatusEnum::ACCEPTED->canRetry())->toBeFalse();
    });
});

// ========================================
// IdTypeEnum Tests
// ========================================

describe('IdTypeEnum', function () {
    it('has correct values for all cases', function () {
        expect(IdTypeEnum::NIF->value)->toBe('02');
        expect(IdTypeEnum::PASSPORT->value)->toBe('03');
        expect(IdTypeEnum::OFFICIAL_DOC->value)->toBe('04');
        expect(IdTypeEnum::RESIDENCE_CERTIFICATE->value)->toBe('05');
        expect(IdTypeEnum::OTHER->value)->toBe('06');
        expect(IdTypeEnum::NOT_REGISTERED->value)->toBe('07');
    });

    it('returns correct description for each type', function () {
        expect(IdTypeEnum::NIF->getDescription())->toBe('NIF-IVA');
        expect(IdTypeEnum::PASSPORT->getDescription())->toBe('Pasaporte');
        expect(IdTypeEnum::NOT_REGISTERED->getDescription())->toBe('No censado');
    });

    it('identifies Spanish ID correctly', function () {
        expect(IdTypeEnum::NIF->isSpanishId())->toBeTrue();
        expect(IdTypeEnum::PASSPORT->isSpanishId())->toBeFalse();
        expect(IdTypeEnum::NOT_REGISTERED->isSpanishId())->toBeFalse();
    });

    it('identifies foreign ID correctly', function () {
        expect(IdTypeEnum::NIF->isForeignId())->toBeFalse();
        expect(IdTypeEnum::PASSPORT->isForeignId())->toBeTrue();
        expect(IdTypeEnum::OFFICIAL_DOC->isForeignId())->toBeTrue();
        expect(IdTypeEnum::RESIDENCE_CERTIFICATE->isForeignId())->toBeTrue();
        expect(IdTypeEnum::OTHER->isForeignId())->toBeTrue();
        expect(IdTypeEnum::NOT_REGISTERED->isForeignId())->toBeFalse();
    });
});

// ========================================
// OperationTypeEnum Tests
// ========================================

describe('OperationTypeEnum', function () {
    it('has correct values for all cases', function () {
        expect(OperationTypeEnum::NORMAL->value)->toBe('01');
        expect(OperationTypeEnum::INTRA_COMMUNITY_ACQUISITION->value)->toBe('02');
        expect(OperationTypeEnum::IMPORT->value)->toBe('03');
        expect(OperationTypeEnum::REVERSE_CHARGE->value)->toBe('04');
        expect(OperationTypeEnum::NOT_SUBJECT_ARTICLE_7_14->value)->toBe('05');
        expect(OperationTypeEnum::NOT_SUBJECT_ARTICLE_7_14_OTHER->value)->toBe('06');
        expect(OperationTypeEnum::EXEMPT->value)->toBe('07');
    });

    it('returns correct description for each type', function () {
        expect(OperationTypeEnum::NORMAL->getDescription())->toBe('Operación normal');
        expect(OperationTypeEnum::IMPORT->getDescription())->toBe('Importación');
        expect(OperationTypeEnum::EXEMPT->getDescription())->toBe('Exenta');
    });

    it('identifies subject to tax operations correctly', function () {
        expect(OperationTypeEnum::NORMAL->isSubjectToTax())->toBeTrue();
        expect(OperationTypeEnum::INTRA_COMMUNITY_ACQUISITION->isSubjectToTax())->toBeTrue();
        expect(OperationTypeEnum::IMPORT->isSubjectToTax())->toBeTrue();
        expect(OperationTypeEnum::REVERSE_CHARGE->isSubjectToTax())->toBeTrue();
        expect(OperationTypeEnum::NOT_SUBJECT_ARTICLE_7_14->isSubjectToTax())->toBeFalse();
        expect(OperationTypeEnum::NOT_SUBJECT_ARTICLE_7_14_OTHER->isSubjectToTax())->toBeFalse();
        expect(OperationTypeEnum::EXEMPT->isSubjectToTax())->toBeFalse();
    });
});

// ========================================
// RegimeTypeEnum Tests
// ========================================

describe('RegimeTypeEnum', function () {
    it('has correct values for key cases', function () {
        expect(RegimeTypeEnum::GENERAL->value)->toBe('01');
        expect(RegimeTypeEnum::EXPORT->value)->toBe('02');
        expect(RegimeTypeEnum::SPECIAL_SIMPLIFIED->value)->toBe('12');
        expect(RegimeTypeEnum::NOT_SUBJECT->value)->toBe('15');
    });

    it('returns correct description for each type', function () {
        expect(RegimeTypeEnum::GENERAL->getDescription())->toBe('Régimen general');
        expect(RegimeTypeEnum::EXPORT->getDescription())->toBe('Exportación');
        expect(RegimeTypeEnum::NOT_SUBJECT->getDescription())->toBe('No sujeto');
    });

    it('identifies special regimes correctly', function () {
        expect(RegimeTypeEnum::GENERAL->isSpecialRegime())->toBeFalse();
        expect(RegimeTypeEnum::EXPORT->isSpecialRegime())->toBeTrue();
        expect(RegimeTypeEnum::SPECIAL_USED_GOODS->isSpecialRegime())->toBeTrue();
        expect(RegimeTypeEnum::SPECIAL_SIMPLIFIED->isSpecialRegime())->toBeTrue();
        expect(RegimeTypeEnum::NOT_SUBJECT->isSpecialRegime())->toBeTrue();
    });
});
