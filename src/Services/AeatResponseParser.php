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

        // AID-727: «registro duplicado» is not a refusal, it is the agency
        // telling us it already holds this record — the expected answer when a
        // submission was accepted and its response lost to a timeout. It maps to
        // a duplicate outcome, not to a rejection.
        if ($this->isDuplicateOfFiledRecord($lineDetails)) {
            return AeatResponse::duplicate(
                csv: $csv,
                message: $submissionStatus ?? 'Registro duplicado',
                data: [
                    'csv' => $csv,
                    'estado_envio' => $submissionStatus,
                    'lineas' => $lineDetails,
                ],
                errors: $lineErrors === [] ? null : $lineErrors,
            );
        }

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
     * The RegistroDuplicado states that confirm the agency holds the record.
     *
     * List L21 of docs/verifactu/Veri-Factu_Descripcion_SWeb.pdf (AEAT, v1.0.3,
     * p. 43) defines exactly three: Correcta, AceptadaConErrores and Anulada.
     * The first two are filed — L19 says of an accepted-with-errors record that
     * it «tiene errores que no provocan su rechazo. Se registra en el sistema».
     * Anulada is deliberately absent: what the agency holds there is an annulled
     * record, not ours, and reusing that number is refused for good (FAQ §6), so
     * it must stay a rejection and keep feeding Guard 3 of amendRejected().
     *
     * @var array<int, string>
     */
    private const DUPLICATE_STATES_FILED = ['Correcta', 'AceptadaConErrores'];

    /**
     * Does every line say «the agency already holds this record»? (AID-727)
     *
     * Requires the EXPLICIT positive signal, not merely the presence of the
     * RegistroDuplicado block, and reasons over the values AEAT actually
     * returns — verified against the sandbox (prewww1), not against fixtures:
     * a record accepted seconds earlier with a CSV comes back as
     * `AceptadaConErrores`, because the submission was ParcialmenteCorrecto.
     * Which of the two filed states appears depends on the quality of the
     * ORIGINAL submission, not on whether it is on file. Accepting only
     * `Correcta` left this reconciliation unreachable in practice and kept the
     * retry loop it exists to close.
     *
     * Note the gender: L19 spells the submission state `AceptadoConErrores`,
     * L21 spells the duplicate state `AceptadaConErrores`. They are different
     * strings; one constant for both would silently never match.
     *
     * All lines must agree: a mixed response, where only some lines are
     * duplicates, is still a rejection and keeps feeding Guard 3 of
     * amendRejected().
     *
     * @param  array<int, array{estado_registro: ?string, codigo: ?string, descripcion: ?string, registro_duplicado: bool, registro_duplicado_estado: ?string}>  $lineDetails
     */
    private function isDuplicateOfFiledRecord(array $lineDetails): bool
    {
        if ($lineDetails === []) {
            return false;
        }

        foreach ($lineDetails as $line) {
            if ($line['registro_duplicado'] !== true
                || ! in_array($line['registro_duplicado_estado'], self::DUPLICATE_STATES_FILED, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Collect structured per-line rejection metadata (preserved for AID-137 to
     * tell a duplicate-key rejection from a genuine not-in-AEAT rejection).
     *
     * `registro_duplicado` keeps its original meaning — the block is present —
     * because Guard 3 of amendRejected() reasons over it. AID-727 adds the
     * state alongside it rather than narrowing that flag underneath a caller.
     *
     * @return array<int, array{estado_registro: ?string, codigo: ?string, descripcion: ?string, registro_duplicado: bool, registro_duplicado_estado: ?string}>
     */
    private function collectLineDetails(object $response): array
    {
        $details = [];

        foreach ($this->lineObjects($response) as $line) {
            $duplicate = property_exists($line, 'RegistroDuplicado')
                ? $line->RegistroDuplicado
                : null;

            $details[] = [
                'estado_registro' => $this->stringProperty($line, 'EstadoRegistro'),
                'codigo' => $this->stringProperty($line, 'CodigoErrorRegistro'),
                'descripcion' => $this->stringProperty($line, 'DescripcionErrorRegistro'),
                'registro_duplicado' => $duplicate !== null,
                'registro_duplicado_estado' => is_object($duplicate)
                    ? $this->stringProperty($duplicate, 'EstadoRegistroDuplicado')
                    : null,
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
