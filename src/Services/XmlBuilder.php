<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\InvoiceBreakdownContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\RecipientContract;
use AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
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
        $alta->appendChild($this->sfElement($dom, 'TipoFactura', $invoice->getType()->value));

        if ($invoice->getType()->isRectificative()) {
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
        // AEAT rejects an F3 without Destinatarios (rule 1189) or without the
        // substituted invoices, so fail fast instead of producing rejectable XML.
        if ($invoice->getType() === InvoiceTypeEnum::SUBSTITUTE) {
            if (! $invoice->hasRecipient()) {
                throw ValidationException::invalidInvoiceData(
                    'recipient',
                    'F3 (sustitución de simplificadas) requires Destinatarios (AEAT rule 1189)'
                );
            }

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

        $alta->appendChild($this->sfElement($dom, 'CuotaTotal', $this->formatAmount($invoice->getTaxAmount())));
        $alta->appendChild($this->sfElement($dom, 'ImporteTotal', $this->formatAmount($invoice->getTotalAmount())));

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

        $detalle->appendChild($this->sfElement($dom, 'Impuesto', $breakdown->getTaxType()->value));
        $detalle->appendChild($this->sfElement($dom, 'ClaveRegimen', $invoice->getRegimeType()->value));

        if ($breakdown->isExempt()) {
            $detalle->appendChild($this->sfElement($dom, 'OperacionExenta', $this->exemptionCode($breakdown)));
            $detalle->appendChild($this->sfElement($dom, 'BaseImponibleOimporteNoSujeto', $this->formatAmount($breakdown->getBaseAmount())));

            return $detalle;
        }

        // S1: subject and not exempt, without reverse charge
        $detalle->appendChild($this->sfElement($dom, 'CalificacionOperacion', 'S1'));
        $detalle->appendChild($this->sfElement($dom, 'TipoImpositivo', $this->formatAmount($breakdown->getTaxRate())));
        $detalle->appendChild($this->sfElement($dom, 'BaseImponibleOimporteNoSujeto', $this->formatAmount($breakdown->getBaseAmount())));
        $detalle->appendChild($this->sfElement($dom, 'CuotaRepercutida', $this->formatAmount($breakdown->getTaxAmount())));

        return $detalle;
    }

    /**
     * Build Destinatarios with a single IDDestinatario (PersonaFisicaJuridicaType)
     */
    /**
     * Normalize the rectification type to the AEAT ClaveTipoRectificativaType.
     *
     * 'S' (sustitución) passes through; anything else — including legacy
     * adapter codes like 'R1' or null — maps to 'I' (incremental, por
     * diferencias), the common rectification modality.
     */
    private function rectificationTypeCode(InvoiceContract $invoice): string
    {
        return $invoice->getRectificationType() === 'S' ? 'S' : 'I';
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

        $importe->appendChild($this->sfElement($dom, 'BaseRectificada', $this->formatAmount($amounts['base'])));
        $importe->appendChild($this->sfElement($dom, 'CuotaRectificada', $this->formatAmount($amounts['tax'])));

        if ($amounts['surcharge'] !== null) {
            $importe->appendChild($this->sfElement($dom, 'CuotaRecargoRectificado', $this->formatAmount($amounts['surcharge'])));
        }

        return $importe;
    }

    private function buildDestinatarios(DOMDocument $dom, RecipientContract $recipient): DOMElement
    {
        $destinatarios = $dom->createElementNS(self::SF_NS, 'sf:Destinatarios');

        $idDestinatario = $dom->createElementNS(self::SF_NS, 'sf:IDDestinatario');
        $destinatarios->appendChild($idDestinatario);

        $idDestinatario->appendChild($this->sfElement($dom, 'NombreRazon', $recipient->getName() ?? ''));

        $nif = $recipient->getNif();

        if ($nif !== null && $nif !== '') {
            $idDestinatario->appendChild($this->sfElement($dom, 'NIF', $nif));

            return $destinatarios;
        }

        $idOtro = $dom->createElementNS(self::SF_NS, 'sf:IDOtro');
        $idDestinatario->appendChild($idOtro);

        $country = $recipient->getCountry();

        if ($country !== null && $country !== '') {
            $idOtro->appendChild($this->sfElement($dom, 'CodigoPais', $country));
        }

        $idType = $recipient->getIdType();
        $idOtro->appendChild($this->sfElement($dom, 'IDType', $idType !== null ? $idType->value : '02'));
        $idOtro->appendChild($this->sfElement($dom, 'ID', $recipient->getId() ?? ''));

        return $destinatarios;
    }

    /**
     * Map breakdown exemption to an OperacionExentaType code (E1-E6)
     */
    private function exemptionCode(InvoiceBreakdownContract $breakdown): string
    {
        $reason = $breakdown->getExemptionReason();

        if ($reason !== null && preg_match('/^E[1-6]$/', $reason) === 1) {
            return $reason;
        }

        return 'E1';
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

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
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
