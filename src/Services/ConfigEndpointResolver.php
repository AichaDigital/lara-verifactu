<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\EndpointResolverContract;
use AichaDigital\LaraVerifactu\Exceptions\ConfigurationException;

/**
 * Resolves the AEAT SOAP endpoint from config('verifactu.aeat.endpoints').
 *
 * The endpoint host depends on the certificate type (official WSDL port
 * bindings): sello uses www10/prewww10, ciudadano and representante use
 * www1/prewww1.
 */
final class ConfigEndpointResolver implements EndpointResolverContract
{
    public function resolve(string $environment, string $certificateType): string
    {
        $endpoint = config("verifactu.aeat.endpoints.{$environment}.{$certificateType}");

        if (! is_string($endpoint) || $endpoint === '') {
            throw ConfigurationException::unknownEndpoint($environment, $certificateType);
        }

        return $endpoint;
    }
}
