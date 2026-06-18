<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sanitizes AEAT SOAP traffic before it reaches the logs.
 *
 * Verifactu payloads carry fiscal personal data (GDPR): NIF, names, amounts,
 * dates, the chained fingerprint and — in the non-Verifactu XAdES modality —
 * the signer certificate. Logging them verbatim is a data-protection leak.
 *
 * Policy: logs may reveal STRUCTURE and CODES/ENUMS (useful to debug AEAT
 * conformance), never real fiscal data. The redactor walks the document
 * leaf-by-leaf and blanks the content of every sensitive element while
 * leaving codes/enums and the element tree intact. It fails closed: any input
 * it cannot safely parse is replaced by a placeholder, never echoed raw.
 */
final class AeatLogSanitizer
{
    private const REDACTED = '[REDACTED]';

    private const REDACTED_ID = '[REDACTED-ID]';

    private const PLACEHOLDER = '[verifactu] unparseable SOAP payload redacted';

    /**
     * Lower-cased localNames whose textual content must be blanked.
     *
     * Derived from the official AEAT XSD (resources/xsd/*.xsd): party
     * identification, invoice numbers, dates, free-text descriptions,
     * amounts/bases/quotas, the chained fingerprint, request/agreement/
     * installation identifiers, response receipt (CSV) and error text, plus
     * the XAdES signature material. Codes and enums are deliberately absent.
     *
     * @var list<string>
     */
    private const SENSITIVE_ELEMENTS = [
        // Party identification
        'nombrerazon', 'nif', 'idotro', 'codigopais', 'id', 'nombrerazonemisor',
        'idemisorfactura', 'idemisorfacturaanulada', 'nifrepresentante', 'nifpresentador',
        'idtype',
        // Invoice identification
        'numseriefactura', 'numseriefacturaanulada',
        // Dates
        'fechaexpedicionfactura', 'fechaexpedicionfacturaanulada', 'fechaoperacion',
        'fechahorahusogenregistro', 'timestamppresentacion', 'timestampultimamodificacion',
        'fechafinverifactu', 'desde', 'hasta',
        // Free-text descriptions
        'descripcionoperacion', 'descripcionerrorregistro',
        // Amounts / bases / quotas
        'importetotal', 'cuotatotal', 'baseimponibleoimportenosujeto', 'baseimponibleacoste',
        'cuotarepercutida', 'cuotarecargoequivalencia', 'baserectificada', 'cuotarectificada',
        'cuotarecargorectificado',
        // Hash chain
        'huella',
        // Request / agreement / installation identifiers
        'refexterna', 'refrequerimiento', 'idpeticion', 'idpeticionregistroduplicado',
        'numregistroacuerdofacturacion', 'idacuerdosistemainformatico', 'numeroinstalacion',
        'nombresistemainformatico', 'idsistemainformatico',
        // Response receipt
        'csv',
        // XAdES signature material / key fingerprint
        'signaturevalue', 'digestvalue', 'x509certificate', 'x509issuername',
        'x509serialnumber', 'x509ski', 'x509subjectname', 'x509crl', 'pgpkeyid',
        'pgpkeypacket', 'spkisexp', 'keyname', 'mgmtdata', 'modulus',
        'p', 'q', 'g', 'y', 'j', 'seed', 'pgencounter',
    ];

    /**
     * Spanish tax-id shapes (DNI/NIF, NIE, CIF), with optional ES VAT prefix
     * and optional separator. Case-insensitive. Used as a defense-in-depth
     * pass over free text and attributes that escape the element denylist.
     *
     * @var list<string>
     */
    private const ID_PATTERNS = [
        '/\b(ES)?[XYZ]?\d{7,8}[- ]?[A-Z]\b/i', // DNI / NIE / NIF
        '/\b(ES)?[A-Z]\d{7}[- ]?[0-9A-Z]\b/i', // CIF
    ];

    /**
     * Redact a SOAP request/response payload, preserving structure and codes.
     *
     * Fails closed: empty, DOCTYPE-bearing or unparseable input yields a
     * placeholder, never the raw payload.
     */
    public static function redactPayload(?string $xml): string
    {
        $xml = (string) $xml;

        if (trim($xml) === '') {
            return self::PLACEHOLDER;
        }

        // Reject DOCTYPE outright: no entity expansion, no XXE surface.
        if (stripos($xml, '<!DOCTYPE') !== false) {
            return self::PLACEHOLDER;
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $dom->documentElement === null) {
            return self::PLACEHOLDER;
        }

        $elements = $dom->getElementsByTagName('*');
        // Snapshot first: blanking subtrees mutates the live NodeList.
        /** @var list<DOMElement> $nodes */
        $nodes = iterator_to_array($elements);

        foreach ($nodes as $node) {
            $localName = $node->localName;

            if ($localName === null || ! in_array(strtolower($localName), self::SENSITIVE_ELEMENTS, true)) {
                continue;
            }

            while ($node->firstChild !== null) {
                $node->removeChild($node->firstChild);
            }

            $node->appendChild($dom->createTextNode(self::REDACTED));
        }

        $serialized = $dom->saveXML($dom->documentElement);

        if ($serialized === false) {
            return self::PLACEHOLDER;
        }

        return self::redactText($serialized);
    }

    /**
     * Mask Spanish tax-id shapes anywhere in a free-text string.
     */
    public static function redactText(string $text): string
    {
        return (string) preg_replace(self::ID_PATTERNS, self::REDACTED_ID, $text);
    }

    /**
     * Stack-trace log context, gated behind the explicit debug flag.
     *
     * @return array{trace?: string}
     */
    public static function traceContext(Throwable $e): array
    {
        if (! config('verifactu.logging.debug', false)) {
            return [];
        }

        return ['trace' => $e->getTraceAsString()];
    }

    /**
     * Log a redacted SOAP exchange — opt-in, on the Verifactu channel only.
     *
     * No-op unless verifactu.logging.log_soap_payload is enabled. There is
     * deliberately no path to log the raw payload.
     */
    public static function logExchange(?string $request, ?string $response): void
    {
        if (! config('verifactu.logging.log_soap_payload', false)) {
            return;
        }

        $channel = config('verifactu.logging.channel', 'verifactu');

        Log::channel($channel)->debug('AEAT SOAP Request', [
            'request' => self::redactPayload($request),
        ]);

        Log::channel($channel)->debug('AEAT SOAP Response', [
            'response' => self::redactPayload($response),
        ]);
    }
}
