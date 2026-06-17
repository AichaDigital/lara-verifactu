<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RecipientContract;
use AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract;
use AichaDigital\LaraVerifactu\Enums\CalificacionOperacionEnum;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\OperacionExentaEnum;
use AichaDigital\LaraVerifactu\Enums\RectificativeTypeEnum;
use AichaDigital\LaraVerifactu\Enums\TaxTypeEnum;
use AichaDigital\LaraVerifactu\Exceptions\ValidationException;
use AichaDigital\LaraVerifactu\Exceptions\XmlException;
use AichaDigital\LaraVerifactu\Support\CancellationRecord;
use AichaDigital\LaraVerifactu\Support\PreviousRegistry;
use AichaDigital\LaraVerifactu\Support\RegistryChain;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;

/**
 * Builds RegFactuSistemaFacturacion XML conformant with the official AEAT
 * schemas bundled in resources/xsd/ (SuministroLR.xsd + SuministroInformacion.xsd).
 *
 * Element order inside RegistroAlta/RegistroAnulacion follows the XSD
 * sequences strictly — AEAT validation rejects out-of-order elements.
 */
final class XmlBuilder implements XmlBuilderContract
{
    private const LR_NS = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd';

    private const SF_NS = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';

    private const ID_VERSION = '1.0';

    private const HASH_TYPE_SHA256 = '01';

    /**
     * Stable TipoRecargoEquivalencia values allowed per TipoImpositivo, keyed by
     * the formatted rate (AEAT rules 1162/1163/1164). These carry no date window:
     * 21% admits the general 5,2 and the tobacco 1,75; 10% admits 1,4; 4% admits
     * 0,5. The rule does not key by Impuesto, so it applies across the core
     * indirect taxes. The 5% pair {0,5 ; 0,62} is intentionally absent — it is
     * date-windowed (rules 1167/1168/1194) and out of the v1.0 core.
     *
     * @var array<string, list<string>>
     */
    private const SURCHARGE_BY_TAX_RATE = [
        '21.00' => ['5.20', '1.75'],
        '10.00' => ['1.40'],
        '4.00' => ['0.50'],
    ];

    /**
     * TipoImpositivo values whose surcharge magnitude is fixed by date-windowed
     * AEAT rules (1165-1170/1277, plus 1167/1168 for 5%). They require
     * effective-date logic that is post-1.0, so a surcharge on these rates is
     * rejected with a distinct, explicit message rather than a generic mismatch.
     *
     * @var list<string>
     */
    private const DATE_WINDOWED_SURCHARGE_RATES = ['0.00', '2.00', '5.00', '7.50'];

    /**
     * AEAT importe-coherence margin (Validaciones doc §16/§17): ±10,00 EUR,
     * expressed in cents to compare integer amounts derived from the same 2-decimal
     * formatting the XML emits. Beyond it AEAT does not reject — it accepts the
     * record "con errores" (subsanable).
     */
    private const TOTAL_TOLERANCE_CENTS = 1000;

    /**
     * @throws XmlException
     */
    public function buildRegistrationXml(InvoiceContract $invoice, RegistryChain $chain): string
    {
        try {
            $dom = $this->createDomDocument();
            $root = $this->createEnvelope($dom);

            $registroFactura = $dom->createElementNS(self::LR_NS, 'sfLR:RegistroFactura');
            $root->appendChild($registroFactura);

            $registroFactura->appendChild($this->buildRegistroAlta($dom, $invoice, $chain));

            return $this->formatXml($dom);
        } catch (ValidationException $e) {
            // Preserve business-rule validation failures (e.g. a substitution
            // rectification missing ImporteRectificacion). The generic catch
            // below would otherwise wrap them in XmlException.
            throw $e;
        } catch (\Throwable $e) {
            throw XmlException::cannotBuildXml($e->getMessage());
        }
    }

    /**
     * @throws XmlException
     */
    public function buildCancellationXml(CancellationRecord $record, RegistryChain $chain): string
    {
        try {
            $dom = $this->createDomDocument();
            $root = $this->createEnvelope($dom);

            $registroFactura = $dom->createElementNS(self::LR_NS, 'sfLR:RegistroFactura');
            $root->appendChild($registroFactura);

            $registroFactura->appendChild($this->buildRegistroAnulacion($dom, $record, $chain));

            return $this->formatXml($dom);
        } catch (\Throwable $e) {
            throw XmlException::cannotBuildXml($e->getMessage());
        }
    }

    /**
     * Validate XML against the bundled official AEAT XSD schema
     */
    public function validate(string $xml): bool
    {
        $previousUseErrors = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($xml)) {
                return false;
            }

            return $dom->schemaValidate(__DIR__ . '/../../resources/xsd/SuministroLR.xsd');
        } catch (\Throwable) {
            return false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }
    }

    private function createDomDocument(): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $dom->preserveWhiteSpace = false;

        return $dom;
    }

    /**
     * Create the RegFactuSistemaFacturacion root with its Cabecera
     */
    private function createEnvelope(DOMDocument $dom): DOMElement
    {
        $root = $dom->createElementNS(self::LR_NS, 'sfLR:RegFactuSistemaFacturacion');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sf', self::SF_NS);
        $dom->appendChild($root);

        $cabecera = $dom->createElementNS(self::LR_NS, 'sfLR:Cabecera');
        $root->appendChild($cabecera);

        $obligado = $dom->createElementNS(self::SF_NS, 'sf:ObligadoEmision');
        $cabecera->appendChild($obligado);

        $obligado->appendChild($this->sfElement($dom, 'NombreRazon', $this->companyName()));
        $obligado->appendChild($this->sfElement($dom, 'NIF', $this->companyTaxId()));

        return $root;
    }

    /**
     * Build RegistroAlta (RegistroFacturacionAltaType) — XSD sequence order
     */
    private function buildRegistroAlta(DOMDocument $dom, InvoiceContract $invoice, RegistryChain $chain): DOMElement
    {
        $alta = $dom->createElementNS(self::SF_NS, 'sf:RegistroAlta');

        $alta->appendChild($this->sfElement($dom, 'IDVersion', self::ID_VERSION));

        $idFactura = $dom->createElementNS(self::SF_NS, 'sf:IDFactura');
        $alta->appendChild($idFactura);
        $idFactura->appendChild($this->sfElement($dom, 'IDEmisorFactura', $invoice->getIssuerTaxId()));
        $idFactura->appendChild($this->sfElement($dom, 'NumSerieFactura', $this->invoiceNumber($invoice)));
        $idFactura->appendChild($this->sfElement($dom, 'FechaExpedicionFactura', $invoice->getIssueDatetime()->format('d-m-Y')));

        $alta->appendChild($this->sfElement($dom, 'NombreRazonEmisor', $this->companyName()));

        // TipoFactura guard (#7): the v1.0 core set is {F1, F2, F3, R1, R5} per
        // the coverage matrix (L2). R2/R3/R4 (Art. 80.3/80.4/Resto) are post-1.0
        // and XSD-valid, so validate() would not catch them — reject fail-loud
        // here, before any TipoFactura is emitted and before the rectificative
        // block, so an R2/R3/R4 fails on TipoFactura rather than TipoRectificativa.
        $type = $invoice->getType();

        if (! $type->isSupportedInV10Core()) {
            throw ValidationException::invalidInvoiceData(
                'tipo_factura',
                "TipoFactura {$type->value} is outside the v1.0 core {F1, F2, F3, R1, R5}; R2/R3/R4 (Art. 80.3/80.4/Resto) are post-1.0"
            );
        }

        $alta->appendChild($this->sfElement($dom, 'TipoFactura', $type->value));

        // Destinatarios × TipoFactura guard (#8): AEAT rules 1189 / 1190.
        // 1189 — F1/F3/R1 require a Destinatarios block. Require an actually
        // emittable recipient: hasRecipient() alone is not enough, since a
        // contract could report a recipient while getRecipient() is null, which
        // would emit no block and still violate 1189.
        // 1190 — F2/R5 (simplified) forbid Destinatarios. Reject on any recipient
        // signal, before guard #5 (NIF), since the type rules regardless of
        // whether the recipient carries a valid NIF.
        $hasEmittableRecipient = $invoice->hasRecipient() && $invoice->getRecipient() instanceof RecipientContract;

        if ($type->requiresRecipientInV10Core() && ! $hasEmittableRecipient) {
            throw ValidationException::invalidInvoiceData(
                'recipient',
                "TipoFactura {$type->value} requires a Destinatarios block (AEAT rule 1189)"
            );
        }

        if ($type->isSimplified() && ($invoice->hasRecipient() || $invoice->getRecipient() !== null)) {
            throw ValidationException::invalidInvoiceData(
                'recipient',
                "TipoFactura {$type->value} (simplified) must not carry a Destinatarios block (AEAT rule 1190)"
            );
        }

        if ($type->isRectificative()) {
            $alta->appendChild($this->sfElement($dom, 'TipoRectificativa', $this->rectificationTypeCode($invoice)));

            $rectified = $invoice->getRectifiedInvoices();

            if ($rectified !== []) {
                $alta->appendChild($this->buildFacturasRectificadas($dom, $invoice, $rectified));
            }

            // ImporteRectificacion (XSD DesgloseRectificacionType) is required by
            // AEAT business rules for substitution rectifications (TipoRectificativa=S).
            // Fail here instead of emitting XSD-valid XML that AEAT would reject.
            if ($this->rectificationTypeCode($invoice) === 'S') {
                $amounts = $invoice->getRectificationAmounts();

                if ($amounts === null) {
                    throw ValidationException::invalidInvoiceData(
                        'rectification_amounts',
                        'ImporteRectificacion is required for substitution rectifications (TipoRectificativa=S)'
                    );
                }

                $alta->appendChild($this->buildImporteRectificacion($dom, $amounts));
            }
        }

        // FacturasSustituidas (XSD: after the rectificative block, before
        // DescripcionOperacion). Only for F3 (factura en sustitución de
        // simplificadas), which is mutually exclusive with rectificative types.
        // The Destinatarios requirement (rule 1189) is enforced by guard #8 above;
        // here we only ensure the substituted invoices are present.
        if ($type === InvoiceTypeEnum::SUBSTITUTE) {
            $substituted = $invoice->getSubstitutedInvoices();

            if ($substituted === []) {
                throw ValidationException::invalidInvoiceData(
                    'substituted_invoices',
                    'F3 requires FacturasSustituidas (the simplified invoices being substituted)'
                );
            }

            $alta->appendChild($this->buildFacturasSustituidas($dom, $invoice, $substituted));
        }

        $alta->appendChild($this->sfElement($dom, 'DescripcionOperacion', $invoice->getDescription() ?? 'Factura'));

        if ($invoice->hasRecipient() && $invoice->getRecipient() instanceof RecipientContract) {
            $alta->appendChild($this->buildDestinatarios($dom, $invoice->getRecipient()));
        }

        $alta->appendChild($this->buildDesglose($dom, $invoice));

        // Validate the totals' form (formatAmount: finite, <= 12 integer digits)
        // before their coherence, so a malformed total fails on its own field
        // rather than as an incoherence. Then the coherence guard (AEAT 1210/2005,
        // 1216/2006) runs after every per-line breakdown guard.
        $cuotaTotal = $this->formatAmount($invoice->getTaxAmount(), 'CuotaTotal');
        $importeTotal = $this->formatAmount($invoice->getTotalAmount(), 'ImporteTotal');

        $this->assertTotalsCoherent($invoice);

        $alta->appendChild($this->sfElement($dom, 'CuotaTotal', $cuotaTotal));
        $alta->appendChild($this->sfElement($dom, 'ImporteTotal', $importeTotal));

        $alta->appendChild($this->buildEncadenamiento($dom, $chain->previous));
        $alta->appendChild($this->buildSistemaInformatico($dom));

        $alta->appendChild($this->sfElement($dom, 'FechaHoraHusoGenRegistro', $chain->generatedAt->format('c')));
        $alta->appendChild($this->sfElement($dom, 'TipoHuella', self::HASH_TYPE_SHA256));
        $alta->appendChild($this->sfElement($dom, 'Huella', $chain->hash));

        return $alta;
    }

    /**
     * Build RegistroAnulacion (RegistroFacturacionAnulacionType) — XSD sequence order
     */
    private function buildRegistroAnulacion(DOMDocument $dom, CancellationRecord $record, RegistryChain $chain): DOMElement
    {
        $anulacion = $dom->createElementNS(self::SF_NS, 'sf:RegistroAnulacion');

        $anulacion->appendChild($this->sfElement($dom, 'IDVersion', self::ID_VERSION));

        $idFactura = $dom->createElementNS(self::SF_NS, 'sf:IDFactura');
        $anulacion->appendChild($idFactura);
        $idFactura->appendChild($this->sfElement($dom, 'IDEmisorFacturaAnulada', $record->issuerTaxId));
        $idFactura->appendChild($this->sfElement($dom, 'NumSerieFacturaAnulada', $record->invoiceNumber));
        $idFactura->appendChild($this->sfElement($dom, 'FechaExpedicionFacturaAnulada', $record->issueDate->format('d-m-Y')));

        $anulacion->appendChild($this->buildEncadenamiento($dom, $chain->previous));
        $anulacion->appendChild($this->buildSistemaInformatico($dom));

        $anulacion->appendChild($this->sfElement($dom, 'FechaHoraHusoGenRegistro', $chain->generatedAt->format('c')));
        $anulacion->appendChild($this->sfElement($dom, 'TipoHuella', self::HASH_TYPE_SHA256));
        $anulacion->appendChild($this->sfElement($dom, 'Huella', $chain->hash));

        return $anulacion;
    }

    /**
     * Build Encadenamiento: PrimerRegistro=S on the first record of the
     * chain, RegistroAnterior with the previous registry data otherwise.
     */
    private function buildEncadenamiento(DOMDocument $dom, ?PreviousRegistry $previous): DOMElement
    {
        $encadenamiento = $dom->createElementNS(self::SF_NS, 'sf:Encadenamiento');

        if ($previous === null) {
            $encadenamiento->appendChild($this->sfElement($dom, 'PrimerRegistro', 'S'));

            return $encadenamiento;
        }

        $anterior = $dom->createElementNS(self::SF_NS, 'sf:RegistroAnterior');
        $encadenamiento->appendChild($anterior);

        $anterior->appendChild($this->sfElement($dom, 'IDEmisorFactura', $previous->issuerTaxId));
        $anterior->appendChild($this->sfElement($dom, 'NumSerieFactura', $previous->invoiceNumber));
        $anterior->appendChild($this->sfElement($dom, 'FechaExpedicionFactura', $previous->issueDate->format('d-m-Y')));
        $anterior->appendChild($this->sfElement($dom, 'Huella', $previous->hash));

        return $encadenamiento;
    }

    /**
     * Build SistemaInformatico (SistemaInformaticoType) from config.
     * Note: IdSistemaInformatico is limited to 2 characters by the XSD.
     */
    private function buildSistemaInformatico(DOMDocument $dom): DOMElement
    {
        $sistema = $dom->createElementNS(self::SF_NS, 'sf:SistemaInformatico');

        $sistema->appendChild($this->sfElement($dom, 'NombreRazon', (string) (config('verifactu.system.vendor_name') ?? '')));
        $sistema->appendChild($this->sfElement($dom, 'NIF', (string) (config('verifactu.system.vendor_nif') ?? '')));
        $sistema->appendChild($this->sfElement($dom, 'NombreSistemaInformatico', (string) (config('verifactu.system.name') ?? 'LaraVerifactu')));
        $sistema->appendChild($this->sfElement($dom, 'IdSistemaInformatico', (string) (config('verifactu.system.id') ?? 'LV')));
        $sistema->appendChild($this->sfElement($dom, 'Version', (string) (config('verifactu.system.version') ?? '1.0')));
        $sistema->appendChild($this->sfElement($dom, 'NumeroInstalacion', (string) (config('verifactu.system.installation_number') ?? '1')));
        $sistema->appendChild($this->sfElement($dom, 'TipoUsoPosibleSoloVerifactu', (string) (config('verifactu.system.only_verifactu') ?? 'S')));
        $sistema->appendChild($this->sfElement($dom, 'TipoUsoPosibleMultiOT', (string) (config('verifactu.system.multi_ot') ?? 'N')));
        $sistema->appendChild($this->sfElement($dom, 'IndicadorMultiplesOT', (string) (config('verifactu.system.multiple_ot') ?? 'N')));

        return $sistema;
    }

    /**
     * Build Desglose with one DetalleDesglose per tax breakdown (max 12 per XSD)
     */
    private function buildDesglose(DOMDocument $dom, InvoiceContract $invoice): DOMElement
    {
        $desglose = $dom->createElementNS(self::SF_NS, 'sf:Desglose');

        foreach ($invoice->getBreakdowns() as $breakdown) {
            $desglose->appendChild($this->buildDetalleDesglose($dom, $invoice, $breakdown));
        }

        return $desglose;
    }

    private function buildDetalleDesglose(DOMDocument $dom, InvoiceContract $invoice, InvoiceBreakdownContract $breakdown): DOMElement
    {
        $detalle = $dom->createElementNS(self::SF_NS, 'sf:DetalleDesglose');

        // Impuesto guard (#3): only the indirect taxes of the v1.0 core
        // {01 IVA, 02 IPSI, 03 IGIC} are supported; 05 (Otros) is post-1.0.
        $taxType = $breakdown->getTaxType();

        if (! $taxType->isIndirectTax()) {
            throw ValidationException::invalidInvoiceData(
                'impuesto',
                "Impuesto {$taxType->value} is outside the v1.0 core {01, 02, 03}; 05 (Otros) is post-1.0"
            );
        }

        // ClaveRegimen guard (#4): positive allow-list (only the general regime
        // 01 is core). Rejects, among others, regimes 04/08/10/20 that force a
        // non-S1 CalificacionOperacion (rules 1147/1252/1205/1293).
        $regime = $invoice->getRegimeType();

        if (! $regime->isSupportedInV10Core()) {
            throw ValidationException::invalidInvoiceData(
                'clave_regimen',
                "ClaveRegimen {$regime->value} is outside the v1.0 core (only the general regime 01 is supported; special regimes are post-1.0)"
            );
        }

        $detalle->appendChild($this->sfElement($dom, 'Impuesto', $taxType->value));
        $detalle->appendChild($this->sfElement($dom, 'ClaveRegimen', $regime->value));

        if ($breakdown->isExempt()) {
            $detalle->appendChild($this->sfElement($dom, 'OperacionExenta', $this->exemptionCode($breakdown, $taxType)));
            $detalle->appendChild($this->sfElement($dom, 'BaseImponibleOimporteNoSujeto', $this->formatAmount($breakdown->getBaseAmount(), 'BaseImponibleOimporteNoSujeto')));

            return $detalle;
        }

        // CalificacionOperacion guard (#1): S1 (subject, not exempt, no reverse
        // charge) is the only value in the v1.0 core. No breakdown input expresses
        // a calificación, so S1 is the only possible value; the regimes that would
        // force S2/N1/N2 are already rejected by the ClaveRegimen guard above.
        $detalle->appendChild($this->sfElement($dom, 'CalificacionOperacion', CalificacionOperacionEnum::S1->value));
        $detalle->appendChild($this->sfElement($dom, 'TipoImpositivo', $this->formatRate($breakdown->getTaxRate(), 'TipoImpositivo')));
        $detalle->appendChild($this->sfElement($dom, 'BaseImponibleOimporteNoSujeto', $this->formatAmount($breakdown->getBaseAmount(), 'BaseImponibleOimporteNoSujeto')));
        $detalle->appendChild($this->sfElement($dom, 'CuotaRepercutida', $this->formatAmount($breakdown->getTaxAmount(), 'CuotaRepercutida')));

        $surchargeRate = $breakdown->getSurchargeRate();
        $surchargeAmount = $breakdown->getSurchargeAmount();

        if ($surchargeRate !== null || $surchargeAmount !== null) {
            // Recargo de equivalencia: TipoRecargoEquivalencia + CuotaRecargoEquivalencia
            // are a semantic pair. Emitting one without the other is XSD-valid but
            // fiscally incoherent and AEAT would reject it, so fail fast.
            if ($surchargeRate === null || $surchargeAmount === null) {
                throw ValidationException::invalidInvoiceData(
                    'surcharge',
                    'TipoRecargoEquivalencia and CuotaRecargoEquivalencia must be provided together'
                );
            }

            // Validate the Tipo2.2Type form first (overflow / sign / non-finite),
            // so a malformed surcharge fails on its own field before the magnitude
            // guard below reasons about the formatted value.
            $surchargeFormatted = $this->formatRate($surchargeRate, 'TipoRecargoEquivalencia');

            // Surcharge magnitude guard (#9): AEAT rules 1162/1163/1164. The
            // TipoRecargoEquivalencia must be one of the stable values allowed for
            // the TipoImpositivo. Date-windowed rates (5/0/2/7,5% — rules
            // 1167/1168 and 1165-1170/1277) need effective-date logic and are
            // post-1.0, so a surcharge on them is rejected fail-loud.
            $rateFormatted = number_format($breakdown->getTaxRate(), 2, '.', '');
            $allowedSurcharges = self::SURCHARGE_BY_TAX_RATE[$rateFormatted] ?? null;

            if ($allowedSurcharges === null) {
                throw ValidationException::invalidInvoiceData(
                    'surcharge',
                    in_array($rateFormatted, self::DATE_WINDOWED_SURCHARGE_RATES, true)
                        ? "TipoRecargoEquivalencia for TipoImpositivo {$rateFormatted} requires date-windowed transitional VAT rules (AEAT 1165-1170/1277) not supported by the v1.0 core"
                        : "TipoImpositivo {$rateFormatted} has no equivalence-surcharge magnitude in the v1.0 core (stable rates: 21, 10, 4; AEAT rules 1162/1163/1164)"
                );
            }

            if (! in_array($surchargeFormatted, $allowedSurcharges, true)) {
                throw ValidationException::invalidInvoiceData(
                    'surcharge',
                    "TipoRecargoEquivalencia {$surchargeFormatted} is not valid for TipoImpositivo {$rateFormatted} (AEAT rules 1162/1163/1164); allowed: " . implode(', ', $allowedSurcharges)
                );
            }

            $detalle->appendChild($this->sfElement($dom, 'TipoRecargoEquivalencia', $surchargeFormatted));
            $detalle->appendChild($this->sfElement($dom, 'CuotaRecargoEquivalencia', $this->formatAmount($surchargeAmount, 'CuotaRecargoEquivalencia')));
        }

        return $detalle;
    }

    /**
     * Build Destinatarios with a single IDDestinatario (PersonaFisicaJuridicaType)
     */
    /**
     * Resolve and validate the AEAT ClaveTipoRectificativaType (L3).
     *
     * TipoRectificativa guard (#6): the value must be S (por sustitución) or I
     * (por diferencias). Anything else — including legacy adapter codes like
     * 'R1' or null — is rejected fail-loud instead of silently collapsing to 'I'.
     * tryFrom() is null-checked first (strict_types=1 would TypeError on null).
     */
    private function rectificationTypeCode(InvoiceContract $invoice): string
    {
        $raw = $invoice->getRectificationType();
        $type = $raw !== null ? RectificativeTypeEnum::tryFrom($raw) : null;

        if (! $type instanceof RectificativeTypeEnum) {
            throw ValidationException::invalidInvoiceData(
                'rectification_type',
                $raw === null
                    ? 'TipoRectificativa is required for a rectificative invoice and must be S (sustitución) or I (diferencias)'
                    : "TipoRectificativa '{$raw}' is invalid; only S (sustitución) or I (diferencias) are allowed"
            );
        }

        return $type->value;
    }

    /**
     * Build FacturasRectificadas with one IDFacturaRectificada per rectified
     * invoice. Per the XSD, IDEmisorFactura is the issuer NIF of the
     * rectifying invoice's IDFactura block.
     *
     * @param  array<int, array{number: string, issue_date: Carbon}>  $rectified
     */
    private function buildFacturasRectificadas(DOMDocument $dom, InvoiceContract $invoice, array $rectified): DOMElement
    {
        $facturas = $dom->createElementNS(self::SF_NS, 'sf:FacturasRectificadas');

        foreach ($rectified as $entry) {
            $id = $dom->createElementNS(self::SF_NS, 'sf:IDFacturaRectificada');
            $facturas->appendChild($id);

            $id->appendChild($this->sfElement($dom, 'IDEmisorFactura', $invoice->getIssuerTaxId()));
            $id->appendChild($this->sfElement($dom, 'NumSerieFactura', $entry['number']));
            $id->appendChild($this->sfElement($dom, 'FechaExpedicionFactura', $entry['issue_date']->format('d-m-Y')));
        }

        return $facturas;
    }

    /**
     * Build FacturasSustituidas with one IDFacturaSustituida per substituted
     * invoice. Mirrors buildFacturasRectificadas (same IDFacturaARType); used
     * for invoice type F3. The issuer NIF is the rectifying/substituting
     * invoice's own NIF, per the XSD.
     *
     * @param  array<int, array{number: string, issue_date: Carbon}>  $substituted
     */
    private function buildFacturasSustituidas(DOMDocument $dom, InvoiceContract $invoice, array $substituted): DOMElement
    {
        $facturas = $dom->createElementNS(self::SF_NS, 'sf:FacturasSustituidas');

        foreach ($substituted as $entry) {
            $id = $dom->createElementNS(self::SF_NS, 'sf:IDFacturaSustituida');
            $facturas->appendChild($id);

            $id->appendChild($this->sfElement($dom, 'IDEmisorFactura', $invoice->getIssuerTaxId()));
            $id->appendChild($this->sfElement($dom, 'NumSerieFactura', $entry['number']));
            $id->appendChild($this->sfElement($dom, 'FechaExpedicionFactura', $entry['issue_date']->format('d-m-Y')));
        }

        return $facturas;
    }

    /**
     * Build ImporteRectificacion (DesgloseRectificacionType) for substitution
     * rectifications. BaseRectificada and CuotaRectificada are mandatory;
     * CuotaRecargoRectificado is emitted only when a surcharge is present.
     *
     * @param  array{base: float, tax: float, surcharge: float|null}  $amounts
     */
    private function buildImporteRectificacion(DOMDocument $dom, array $amounts): DOMElement
    {
        $importe = $dom->createElementNS(self::SF_NS, 'sf:ImporteRectificacion');

        $importe->appendChild($this->sfElement($dom, 'BaseRectificada', $this->formatAmount($amounts['base'], 'BaseRectificada')));
        $importe->appendChild($this->sfElement($dom, 'CuotaRectificada', $this->formatAmount($amounts['tax'], 'CuotaRectificada')));

        if ($amounts['surcharge'] !== null) {
            $importe->appendChild($this->sfElement($dom, 'CuotaRecargoRectificado', $this->formatAmount($amounts['surcharge'], 'CuotaRecargoRectificado')));
        }

        return $importe;
    }

    /**
     * Importe coherence guard (AEAT 1210/2005, 1216/2006): CuotaTotal and
     * ImporteTotal must match the breakdown sums within AEAT's ±10,00 EUR margin.
     * Beyond it AEAT does not reject — it accepts the record "con errores"
     * (subsanable) — so failing loud here keeps the consumer from shipping a
     * record it would have to amend. Exempt breakdowns emit only
     * BaseImponibleOimporteNoSujeto, so they contribute base only. The ImporteTotal
     * regime exclusions (ClaveRegimen 03/05/06/08/09) never apply: the core is
     * always regime 01 (guard #4).
     *
     * Sums are accumulated in cents from the same 2-decimal formatting the XML
     * emits, so the comparison matches what AEAT validates line by line.
     */
    private function assertTotalsCoherent(InvoiceContract $invoice): void
    {
        $baseCents = 0;
        $taxCents = 0;
        $surchargeCents = 0;

        foreach ($invoice->getBreakdowns() as $breakdown) {
            $baseCents += $this->toCents($breakdown->getBaseAmount());

            if ($breakdown->isExempt()) {
                continue;
            }

            $taxCents += $this->toCents($breakdown->getTaxAmount());
            $surchargeCents += $this->toCents($breakdown->getSurchargeAmount() ?? 0.0);
        }

        $sumCuotaCents = $taxCents + $surchargeCents;
        $sumImporteCents = $baseCents + $taxCents + $surchargeCents;

        if (abs($this->toCents($invoice->getTaxAmount()) - $sumCuotaCents) > self::TOTAL_TOLERANCE_CENTS) {
            throw ValidationException::invalidInvoiceData(
                'cuota_total',
                sprintf(
                    'CuotaTotal %s differs from the breakdown sum %s by more than 10,00 EUR (AEAT 1216/2006; AEAT would accept the record AceptadoConErrores, not reject it)',
                    $this->fromCents($this->toCents($invoice->getTaxAmount())),
                    $this->fromCents($sumCuotaCents)
                )
            );
        }

        if (abs($this->toCents($invoice->getTotalAmount()) - $sumImporteCents) > self::TOTAL_TOLERANCE_CENTS) {
            throw ValidationException::invalidInvoiceData(
                'importe_total',
                sprintf(
                    'ImporteTotal %s differs from the breakdown sum %s by more than 10,00 EUR (AEAT 1210/2005; AEAT would accept the record AceptadoConErrores, not reject it)',
                    $this->fromCents($this->toCents($invoice->getTotalAmount())),
                    $this->fromCents($sumImporteCents)
                )
            );
        }
    }

    /**
     * Convert an amount to integer cents using the same 2-decimal rounding the XML
     * emits via number_format(), so the coherence sums match the emitted values.
     */
    private function toCents(float $amount): int
    {
        return (int) str_replace('.', '', number_format($amount, 2, '.', ''));
    }

    private function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function buildDestinatarios(DOMDocument $dom, RecipientContract $recipient): DOMElement
    {
        $destinatarios = $dom->createElementNS(self::SF_NS, 'sf:Destinatarios');

        $idDestinatario = $dom->createElementNS(self::SF_NS, 'sf:IDDestinatario');
        $destinatarios->appendChild($idDestinatario);

        $idDestinatario->appendChild($this->sfElement($dom, 'NombreRazon', $recipient->getName() ?? ''));

        // IDType / IDOtro guard (#5): the v1.0 domestic core only identifies
        // recipients by Spanish NIF. The IDOtro branch (foreign / non-NIF, with
        // its IDType) is post-1.0 — reject it fail-loud instead of defaulting
        // IDType to '02'.
        $nif = $recipient->getNif();

        if ($nif === null || $nif === '') {
            throw ValidationException::invalidInvoiceData(
                'recipient_nif',
                'Recipient identification via IDOtro (foreign / non-NIF) is post-1.0; the v1.0 core requires a Spanish NIF'
            );
        }

        $idDestinatario->appendChild($this->sfElement($dom, 'NIF', $nif));

        return $destinatarios;
    }

    /**
     * Map breakdown exemption to an OperacionExentaType code (E1-E6)
     */
    private function exemptionCode(InvoiceBreakdownContract $breakdown, TaxTypeEnum $taxType): string
    {
        // OperacionExenta guard (#2): require an explicit, core-supported cause.
        // tryFrom() must be null-checked first — under strict_types=1 a null
        // argument raises TypeError (which buildRegistrationXml would wrap as an
        // XmlException, not the ValidationException we want). Never default to E1.
        $reason = $breakdown->getExemptionReason();
        $cause = $reason !== null ? OperacionExentaEnum::tryFrom($reason) : null;

        if (! $cause instanceof OperacionExentaEnum || ! $cause->isSupportedInV10Core()) {
            throw ValidationException::invalidInvoiceData(
                'exemption_reason',
                $reason === null
                    ? 'An exempt breakdown requires an explicit OperacionExenta cause (E1-E4 or E6)'
                    : "OperacionExenta '{$reason}' is not supported in the v1.0 core (valid core causes: E1-E4, E6; E5 requires IDOtro, post-1.0)"
            );
        }

        // OperacionExenta × Impuesto guard (AEAT rule 1199): with Impuesto 01
        // (IVA) or 03 (IGIC) and ClaveRegimen 01 (the core regime, enforced by
        // guard #4), causes E2 (art. 21) and E3 (art. 22) cannot be used. They
        // stay valid for IPSI (02), which rule 1199 does not name. The enum
        // allow-list is tax-agnostic, so this contextual check lives here.
        if (($taxType === TaxTypeEnum::IVA || $taxType === TaxTypeEnum::IGIC)
            && ($cause === OperacionExentaEnum::E2 || $cause === OperacionExentaEnum::E3)) {
            throw ValidationException::invalidInvoiceData(
                'exemption_reason',
                "OperacionExenta {$cause->value} cannot be used with Impuesto {$taxType->value} under the general regime (AEAT rule 1199); E2/E3 (art. 21/22) are valid only for IPSI in the v1.0 core"
            );
        }

        return $cause->value;
    }

    private function sfElement(DOMDocument $dom, string $name, string $value): DOMElement
    {
        $element = $dom->createElementNS(self::SF_NS, 'sf:' . $name);
        $element->appendChild($dom->createTextNode($value));

        return $element;
    }

    private function invoiceNumber(InvoiceContract $invoice): string
    {
        return $invoice->getSerie()
            ? $invoice->getSerie() . $invoice->getNumber()
            : $invoice->getNumber();
    }

    private function formatXml(DOMDocument $dom): string
    {
        $xml = $dom->saveXML();

        if ($xml === false) {
            throw XmlException::cannotBuildXml('Failed to save XML');
        }

        return $xml;
    }

    private function formatAmount(float $amount, string $field): string
    {
        if (! is_finite($amount)) {
            throw ValidationException::invalidInvoiceData(
                $field,
                'value is not a finite number (ImporteSgn12.2Type requires a finite amount)'
            );
        }

        $formatted = number_format($amount, 2, '.', '');

        // ImporteSgn12.2Type allows at most 12 integer digits (the sign and the
        // two decimals do not count). Fail here instead of emitting XSD-invalid
        // XML that AEAT (or schemaValidate) would reject.
        $integerDigits = strlen(ltrim(explode('.', $formatted)[0], '-'));

        if ($integerDigits > 12) {
            throw ValidationException::invalidInvoiceData(
                $field,
                "value {$formatted} exceeds ImporteSgn12.2Type: maximum 12 integer digits"
            );
        }

        return $formatted;
    }

    private function formatRate(float $rate, string $field): string
    {
        // TipoImpositivo / TipoRecargoEquivalencia are Tipo2.2Type: finite,
        // non-negative (no sign), at most 3 integer digits and 2 decimals.
        if (! is_finite($rate)) {
            throw ValidationException::invalidInvoiceData(
                $field,
                'value is not a finite number (Tipo2.2Type requires a finite value)'
            );
        }

        if ($rate < 0) {
            throw ValidationException::invalidInvoiceData(
                $field,
                'value is negative; Tipo2.2Type has no sign'
            );
        }

        $formatted = number_format($rate, 2, '.', '');

        // No sign is possible here ($rate >= 0), so the integer part is digits only.
        if (strlen(explode('.', $formatted)[0]) > 3) {
            throw ValidationException::invalidInvoiceData(
                $field,
                "value {$formatted} exceeds Tipo2.2Type: maximum 3 integer digits"
            );
        }

        return $formatted;
    }

    private function companyName(): string
    {
        return (string) (config('verifactu.company.name') ?? '');
    }

    private function companyTaxId(): string
    {
        return (string) (config('verifactu.company.tax_id') ?? '');
    }
}
