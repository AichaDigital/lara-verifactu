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

        $lineDetails = $this->collectLineDetails($response);

        // A well-formed AEAT rejection: AEAT evaluated the submission and said
        // Incorrecto (EstadoEnvio present, or per-line EstadoRegistro/errors). A
        // degenerate object with neither is treated as a transport failure.
        $isValidationRejection = $submissionStatus !== null || $lineDetails !== [];

        if ($isValidationRejection) {
            return AeatResponse::rejection(
                errors: $lineErrors === [] ? ['Submission rejected by AEAT'] : $lineErrors,
                message: $submissionStatus ?? 'Rejected by AEAT',
                data: [
                    'estado_envio' => $submissionStatus,
                    'lineas' => $lineDetails,
                ],
            );
        }

        // Reached only when $submissionStatus === null and $lineDetails === []
        // (the $isValidationRejection guard above is false), so the message is
        // always the literal — a degenerate, AEAT-unevaluated transport failure.
        return AeatResponse::failure(
            errors: $lineErrors === [] ? ['Invalid response from AEAT server'] : $lineErrors,
            message: 'Unknown AEAT response',
        );
    }

    /**
     * Collect structured per-line rejection metadata (preserved for AID-137 to
     * tell a duplicate-key rejection from a genuine not-in-AEAT rejection).
     *
     * @return array<int, array{estado_registro: ?string, codigo: ?string, descripcion: ?string, registro_duplicado: bool}>
     */
    private function collectLineDetails(object $response): array
    {
        $details = [];

        foreach ($this->lineObjects($response) as $line) {
            $details[] = [
                'estado_registro' => $this->stringProperty($line, 'EstadoRegistro'),
                'codigo' => $this->stringProperty($line, 'CodigoErrorRegistro'),
                'descripcion' => $this->stringProperty($line, 'DescripcionErrorRegistro'),
                'registro_duplicado' => property_exists($line, 'RegistroDuplicado')
                    && $line->RegistroDuplicado !== null,
            ];
        }

        return $details;
    }

    /**
     * Collect "code: description" entries from non-correct response lines
     *
     * @return array<int, string>
     */
    private function collectLineErrors(object $response): array
    {
        $errors = [];

        foreach ($this->lineObjects($response) as $line) {
            $code = $this->stringProperty($line, 'CodigoErrorRegistro');
            $description = $this->stringProperty($line, 'DescripcionErrorRegistro');

            if ($code === null && $description === null) {
                continue;
            }

            $errors[] = trim(sprintf('%s: %s', $code ?? '?', $description ?? 'Unknown error'));
        }

        return $errors;
    }

    /**
     * Normalize RespuestaLinea (single object or array) to a list of line objects.
     *
     * @return array<int, object>
     */
    private function lineObjects(object $response): array
    {
        if (! property_exists($response, 'RespuestaLinea') || $response->RespuestaLinea === null) {
            return [];
        }

        $raw = $response->RespuestaLinea;

        return array_values(array_filter(
            is_array($raw) ? $raw : [$raw],
            'is_object',
        ));
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
