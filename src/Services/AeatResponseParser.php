<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Support\AeatResponse;

/**
 * Parses the decoded SOAP response of RegFactuSistemaFacturacion
 * (RespuestaSuministro structure).
 *
 * EstadoEnvio: Correcto | ParcialmenteCorrecto | Incorrecto.
 * Per-line EstadoRegistro: Correcto | AceptadoConErrores | Incorrecto.
 *
 * AceptadoConErrores means AEAT REGISTERED the record (do not resubmit),
 * so it maps to a successful response carrying the error details.
 */
final class AeatResponseParser
{
    private const STATUS_CORRECT = 'Correcto';

    private const STATUS_PARTIALLY_CORRECT = 'ParcialmenteCorrecto';

    public function parse(mixed $response): AeatResponse
    {
        if (! is_object($response)) {
            return AeatResponse::failure(
                errors: ['Invalid response from AEAT server'],
                message: 'Unexpected response type',
            );
        }

        $submissionStatus = $this->stringProperty($response, 'EstadoEnvio');
        $csv = $this->stringProperty($response, 'CSV');
        $lineErrors = $this->collectLineErrors($response);

        $accepted = in_array($submissionStatus, [self::STATUS_CORRECT, self::STATUS_PARTIALLY_CORRECT], true)
            || ($submissionStatus === null && $csv !== null);

        if ($accepted) {
            return new AeatResponse(
                success: true,
                code: $csv,
                message: $submissionStatus ?? self::STATUS_CORRECT,
                data: [
                    'csv' => $csv,
                    'estado_envio' => $submissionStatus,
                    'timestamp' => $this->presentationTimestamp($response),
                ],
                errors: $lineErrors === [] ? null : $lineErrors,
            );
        }

        return AeatResponse::failure(
            errors: $lineErrors === [] ? ['Submission rejected by AEAT'] : $lineErrors,
            message: $submissionStatus ?? 'Unknown AEAT response',
        );
    }

    /**
     * Collect "code: description" entries from non-correct response lines
     *
     * @return array<int, string>
     */
    private function collectLineErrors(object $response): array
    {
        if (! property_exists($response, 'RespuestaLinea') || $response->RespuestaLinea === null) {
            return [];
        }

        $lines = is_array($response->RespuestaLinea)
            ? $response->RespuestaLinea
            : [$response->RespuestaLinea];

        $errors = [];

        foreach ($lines as $line) {
            if (! is_object($line)) {
                continue;
            }

            $code = $this->stringProperty($line, 'CodigoErrorRegistro');
            $description = $this->stringProperty($line, 'DescripcionErrorRegistro');

            if ($code === null && $description === null) {
                continue;
            }

            $errors[] = trim(sprintf('%s: %s', $code ?? '?', $description ?? 'Unknown error'));
        }

        return $errors;
    }

    private function presentationTimestamp(object $response): ?string
    {
        if (! property_exists($response, 'DatosPresentacion') || ! is_object($response->DatosPresentacion)) {
            return null;
        }

        return $this->stringProperty($response->DatosPresentacion, 'TimestampPresentacion');
    }

    private function stringProperty(object $object, string $property): ?string
    {
        if (! property_exists($object, $property)) {
            return null;
        }

        $value = $object->{$property};

        return is_scalar($value) ? (string) $value : null;
    }
}
