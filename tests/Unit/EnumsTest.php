<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\IdTypeEnum;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum;
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
        expect(InvoiceTypeEnum::SUBSTITUTE->value)->toBe('F3');
        expect(InvoiceTypeEnum::RECTIFICATIVE->value)->toBe('R1');
        expect(InvoiceTypeEnum::RECTIFICATIVE_ART_80_3->value)->toBe('R2');
        expect(InvoiceTypeEnum::RECTIFICATIVE_ART_80_4->value)->toBe('R3');
        expect(InvoiceTypeEnum::RECTIFICATIVE_OTHER->value)->toBe('R4');
        expect(InvoiceTypeEnum::RECTIFICATIVE_SIMPLIFIED_INVOICES->value)->toBe('R5');
    });

    it('returns correct description for each type', function () {
        expect(InvoiceTypeEnum::COMPLETE->getDescription())->toContain('art. 6');
        expect(InvoiceTypeEnum::SIMPLIFIED->getDescription())->toContain('simplificada');
        expect(InvoiceTypeEnum::RECTIFICATIVE_ART_80_3->getDescription())->toBe('Factura rectificativa (art. 80.3)');
        expect(InvoiceTypeEnum::RECTIFICATIVE_OTHER->getDescription())->toBe('Factura rectificativa (Resto)');
        expect(InvoiceTypeEnum::RECTIFICATIVE_SIMPLIFIED_INVOICES->getDescription())->toBe('Factura rectificativa en facturas simplificadas');
    });

    it('identifies rectificative invoices correctly', function () {
        expect(InvoiceTypeEnum::COMPLETE->isRectificative())->toBeFalse();
        expect(InvoiceTypeEnum::SIMPLIFIED->isRectificative())->toBeFalse();
        expect(InvoiceTypeEnum::RECTIFICATIVE->isRectificative())->toBeTrue();
        expect(InvoiceTypeEnum::RECTIFICATIVE_ART_80_3->isRectificative())->toBeTrue();
        expect(InvoiceTypeEnum::RECTIFICATIVE_ART_80_4->isRectificative())->toBeTrue();
        expect(InvoiceTypeEnum::RECTIFICATIVE_OTHER->isRectificative())->toBeTrue();
        expect(InvoiceTypeEnum::RECTIFICATIVE_SIMPLIFIED_INVOICES->isRectificative())->toBeTrue();
    });

    it('identifies simplified invoices correctly (F2 and R5 only, per rules 1185/1190)', function () {
        expect(InvoiceTypeEnum::COMPLETE->isSimplified())->toBeFalse();
        expect(InvoiceTypeEnum::SIMPLIFIED->isSimplified())->toBeTrue();
        expect(InvoiceTypeEnum::RECTIFICATIVE->isSimplified())->toBeFalse();
        // R2 (Art. 80.3) is NOT simplified — the prior code wrongly included it.
        expect(InvoiceTypeEnum::RECTIFICATIVE_ART_80_3->isSimplified())->toBeFalse();
        expect(InvoiceTypeEnum::RECTIFICATIVE_SIMPLIFIED_INVOICES->isSimplified())->toBeTrue();
    });
});

// ========================================
// TaxTypeEnum Tests
// ========================================

describe('TaxTypeEnum', function () {
    it('has correct values for all cases (L1: 01/02/03/05, no IRPF)', function () {
        expect(TaxTypeEnum::IVA->value)->toBe('01');
        expect(TaxTypeEnum::IPSI->value)->toBe('02');
        expect(TaxTypeEnum::IGIC->value)->toBe('03');
        expect(TaxTypeEnum::OTHER->value)->toBe('05');
        expect(TaxTypeEnum::cases())->toHaveCount(4);
    });

    it('returns correct description for each type', function () {
        expect(TaxTypeEnum::IVA->getDescription())->toContain('IVA');
        expect(TaxTypeEnum::IPSI->getDescription())->toContain('IPSI');
        expect(TaxTypeEnum::IPSI->getDescription())->toContain('Ceuta y Melilla');
        expect(TaxTypeEnum::IGIC->getDescription())->toContain('IGIC');
    });

    it('identifies indirect taxes correctly', function () {
        expect(TaxTypeEnum::IVA->isIndirectTax())->toBeTrue();
        expect(TaxTypeEnum::IPSI->isIndirectTax())->toBeTrue();
        expect(TaxTypeEnum::IGIC->isIndirectTax())->toBeTrue();
        expect(TaxTypeEnum::OTHER->isIndirectTax())->toBeFalse();
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
        expect(RegistryStatusEnum::REJECTED->canRetry())->toBeFalse();
        expect(RegistryStatusEnum::SENT->canRetry())->toBeFalse();
        expect(RegistryStatusEnum::ACCEPTED->canRetry())->toBeFalse();
    });

    it('excludes REJECTED from the retryable statuses', function () {
        expect(RegistryStatusEnum::REJECTED->canRetry())->toBeFalse()
            ->and(RegistryStatusEnum::PENDING->canRetry())->toBeTrue()
            ->and(RegistryStatusEnum::ERROR->canRetry())->toBeTrue();
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
// RegimeTypeEnum Tests
// ========================================

describe('RegimeTypeEnum', function () {
    it('has correct values for key cases (no invented 12/13)', function () {
        expect(RegimeTypeEnum::GENERAL->value)->toBe('01');
        expect(RegimeTypeEnum::EXPORT->value)->toBe('02');
        expect(RegimeTypeEnum::EQUIVALENCE_SURCHARGE->value)->toBe('18');
        expect(RegimeTypeEnum::SIMPLIFIED_REGIME->value)->toBe('20');
    });

    it('returns the L8A (IVA) description by default', function () {
        expect(RegimeTypeEnum::GENERAL->getDescription())->toBe('Operación de régimen general');
        expect(RegimeTypeEnum::EXPORT->getDescription())->toBe('Exportación');
        expect(RegimeTypeEnum::EQUIVALENCE_SURCHARGE->getDescription())->toBe('Recargo de equivalencia');
    });

    it('resolves divergent IGIC (L8B) labels via descriptionFor()', function () {
        // 17/18/19 mean different things for IVA (L8A) vs IGIC (L8B).
        expect(RegimeTypeEnum::OSS_IOSS->descriptionFor(TaxTypeEnum::IVA))->toContain('OSS');
        expect(RegimeTypeEnum::OSS_IOSS->descriptionFor(TaxTypeEnum::IGIC))->toContain('comerciante minorista');
        expect(RegimeTypeEnum::EQUIVALENCE_SURCHARGE->descriptionFor(TaxTypeEnum::IGIC))->toContain('pequeño empresario');
        expect(RegimeTypeEnum::REAGYP->descriptionFor(TaxTypeEnum::IGIC))->toContain('artículo 25');
        // Shared codes keep the same label for both.
        expect(RegimeTypeEnum::GENERAL->descriptionFor(TaxTypeEnum::IGIC))->toBe('Operación de régimen general');
    });

    it('identifies special regimes correctly', function () {
        expect(RegimeTypeEnum::GENERAL->isSpecialRegime())->toBeFalse();
        expect(RegimeTypeEnum::EXPORT->isSpecialRegime())->toBeTrue();
        expect(RegimeTypeEnum::SPECIAL_USED_GOODS->isSpecialRegime())->toBeTrue();
        expect(RegimeTypeEnum::EQUIVALENCE_SURCHARGE->isSpecialRegime())->toBeTrue();
    });
});

// ========================================
// RechazoPrevioEnum Tests
// ========================================

describe('RechazoPrevioEnum', function () {
    it('has the XSD-conformant RechazoPrevioType members', function () {
        expect(RechazoPrevioEnum::N->value)->toBe('N')
            ->and(RechazoPrevioEnum::S->value)->toBe('S')
            ->and(RechazoPrevioEnum::X->value)->toBe('X');
    });

    it('maps the amend-by-rejection case to X (key not in AEAT)', function () {
        expect(RechazoPrevioEnum::tryFrom('X'))->toBe(RechazoPrevioEnum::X)
            ->and(RechazoPrevioEnum::tryFrom('Z'))->toBeNull();
    });
});
