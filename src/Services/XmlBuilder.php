<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Contracts\XmlBuilderContract;
use AichaDigital\LaraVerifactu\Exceptions\XmlException;
use DOMDocument;
use Illuminate\Support\Collection;

final class XmlBuilder implements XmlBuilderContract
{
    private const NAMESPACE_URI = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd';

    private const NAMESPACE_PREFIX = 'sflr';

    /**
     * Build XML for invoice registration according to AEAT XSD schema
     *
     * @throws XmlException
     */
    public function buildRegistrationXml(InvoiceContract $invoice): string
    {
        try {
            $dom = $this->createDomDocument();

            // Root element
            $root = $dom->createElementNS(self::NAMESPACE_URI, self::NAMESPACE_PREFIX . ':RegFactuSistemaFacturacion');
            $dom->appendChild($root);

            // Cabecera (Header)
            $this->addHeader($dom, $root, $invoice);

            // RegistroFactura (Invoice Registry)
            $this->addRegistroFactura($dom, $root, $invoice);

            return $this->formatXml($dom);
        } catch (\Throwable $e) {
            throw XmlException::cannotBuildXml($e->getMessage());
        }
    }

    /**
     * Build XML for invoice cancellation
     *
     * @throws XmlException
     */
    public function buildCancellationXml(string $registryId): string
    {
        try {
            $dom = $this->createDomDocument();

            $root = $dom->createElementNS(self::NAMESPACE_URI, self::NAMESPACE_PREFIX . ':RegFactuSistemaFacturacion');
            $dom->appendChild($root);

            // Build cancellation structure
            $registro = $dom->createElement(self::NAMESPACE_PREFIX . ':RegistroFactura');
            $root->appendChild($registro);

            $anulacion = $dom->createElement('RegistroAnulacion');
            $registro->appendChild($anulacion);

            $idRegistro = $dom->createElement('IDRegistro', $registryId);
            $anulacion->appendChild($idRegistro);

            return $this->formatXml($dom);
        } catch (\Throwable $e) {
            throw XmlException::cannotBuildXml($e->getMessage());
        }
    }

    /**
     * Build XML for batch submission
     *
     * @throws XmlException
     */
    public function buildBatchXml(Collection $invoices): string
    {
        try {
            $dom = $this->createDomDocument();

            $root = $dom->createElementNS(self::NAMESPACE_URI, self::NAMESPACE_PREFIX . ':RegFactuSistemaFacturacion');
            $dom->appendChild($root);

            // Add header for batch
            $this->addHeader($dom, $root, $invoices->first());

            // Add all invoices
            foreach ($invoices as $invoice) {
                $this->addRegistroFactura($dom, $root, $invoice);
            }

            return $this->formatXml($dom);
        } catch (\Throwable $e) {
            throw XmlException::cannotBuildXml($e->getMessage());
        }
    }

    /**
     * Validate XML against XSD schema
     */
    public function validate(string $xml): bool
    {
        try {
            $dom = new DOMDocument;
            $dom->loadXML($xml);

            // XSD validation would go here
            // For now, just check if XML is well-formed
            return $dom->schemaValidate(__DIR__ . '/../../documentacion_verifactu/SuministroLR.xsd.xml') !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Create DOM document with proper configuration
     */
    private function createDomDocument(): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $dom->preserveWhiteSpace = false;

        return $dom;
    }

    /**
     * Add header (Cabecera) to XML
     */
    private function addHeader(DOMDocument $dom, \DOMElement $root, InvoiceContract $invoice): void
    {
        $cabecera = $dom->createElement('Cabecera');
        $root->appendChild($cabecera);

        // Obligee (Obligado) - Issuer information
        $obligado = $dom->createElement('Obligado');
        $cabecera->appendChild($obligado);

        $nif = $dom->createElement('NIF', config('verifactu.company.tax_id', ''));
        $obligado->appendChild($nif);

        // System information
        $sistema = $dom->createElement('SistemaInformatico');
        $cabecera->appendChild($sistema);

        $nombreSistema = $dom->createElement('NombreSistema', 'LaraVerifactu');
        $sistema->appendChild($nombreSistema);

        $idSistema = $dom->createElement('IdSistema', config('verifactu.system_id', 'LARA-VERIFACTU-001'));
        $sistema->appendChild($idSistema);

        $version = $dom->createElement('Version', '1.0');
        $sistema->appendChild($version);
    }

    /**
     * Add invoice registry (RegistroFactura) to XML
     */
    private function addRegistroFactura(DOMDocument $dom, \DOMElement $root, InvoiceContract $invoice): void
    {
        $registro = $dom->createElement('RegistroFactura');
        $root->appendChild($registro);

        // RegistroAlta (New invoice)
        $alta = $dom->createElement('RegistroAlta');
        $registro->appendChild($alta);

        // Invoice identification
        $idFactura = $dom->createElement('IDFactura');
        $alta->appendChild($idFactura);

        $emisor = $dom->createElement('IDEmisorFactura', config('verifactu.company.tax_id', ''));
        $idFactura->appendChild($emisor);

        $invoiceNumber = $invoice->getSerie()
            ? $invoice->getSerie() . $invoice->getNumber()
            : $invoice->getNumber();
        $numero = $dom->createElement('NumSerieFactura', $invoiceNumber);
        $idFactura->appendChild($numero);

        $fecha = $dom->createElement('FechaExpedicionFactura', $invoice->getIssueDate()->format('d-m-Y'));
        $idFactura->appendChild($fecha);

        // Invoice data
        $datosFactura = $dom->createElement('DatosFactura');
        $alta->appendChild($datosFactura);

        $tipoFactura = $dom->createElement('TipoFactura', $invoice->getType()->value);
        $datosFactura->appendChild($tipoFactura);

        // Import data
        $importeTotal = $dom->createElement('ImporteTotal', $this->formatAmount($invoice->getTotalAmount()));
        $datosFactura->appendChild($importeTotal);

        // Tax breakdowns
        $this->addTaxBreakdowns($dom, $datosFactura, $invoice);

        // Encadenamiento (Blockchain)
        $this->addEncadenamiento($dom, $alta, $invoice);
    }

    /**
     * Add tax breakdowns to invoice data
     */
    private function addTaxBreakdowns(DOMDocument $dom, \DOMElement $datosFactura, InvoiceContract $invoice): void
    {
        if ($invoice->getBreakdowns()->isEmpty()) {
            return;
        }

        $desgloses = $dom->createElement('Desgloses');
        $datosFactura->appendChild($desgloses);

        foreach ($invoice->getBreakdowns() as $breakdown) {
            $desglose = $dom->createElement('Desglose');
            $desgloses->appendChild($desglose);

            $tipoImpuesto = $dom->createElement('TipoImpuesto', $breakdown->getTaxType()->value);
            $desglose->appendChild($tipoImpuesto);

            $baseImponible = $dom->createElement('BaseImponible', $this->formatAmount($breakdown->getBaseAmount()));
            $desglose->appendChild($baseImponible);

            $cuota = $dom->createElement('Cuota', $this->formatAmount($breakdown->getTaxAmount()));
            $desglose->appendChild($cuota);

            // Note: TipoOperacion is from Invoice, not breakdown
            // This should be handled at invoice level, not breakdown level
        }
    }

    /**
     * Add blockchain data (Encadenamiento)
     */
    private function addEncadenamiento(DOMDocument $dom, \DOMElement $alta, InvoiceContract $invoice): void
    {
        if (! $invoice->getPreviousHash()) {
            return;
        }

        $encadenamiento = $dom->createElement('Encadenamiento');
        $alta->appendChild($encadenamiento);

        $registroAnterior = $dom->createElement('RegistroAnterior');
        $encadenamiento->appendChild($registroAnterior);

        $idRegistro = $dom->createElement('IDRegistroAnterior', $invoice->getPreviousInvoiceId());
        $registroAnterior->appendChild($idRegistro);

        $huella = $dom->createElement('HuellaAnterior', $invoice->getPreviousHash());
        $registroAnterior->appendChild($huella);
    }

    /**
     * Format XML output
     */
    private function formatXml(DOMDocument $dom): string
    {
        $xml = $dom->saveXML();

        if ($xml === false) {
            throw XmlException::cannotBuildXml('Failed to save XML');
        }

        return $xml;
    }

    /**
     * Format amount for XML (2 decimals, dot separator)
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
