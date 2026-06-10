<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Contracts;

use AichaDigital\LaraVerifactu\Exceptions\ConfigurationException;

interface EndpointResolverContract
{
    /**
     * Resolve the AEAT SOAP endpoint for an environment and certificate type.
     *
     * @param  string  $environment  production | sandbox
     * @param  string  $certificateType  ciudadano | representante | sello
     *
     * @throws ConfigurationException When the combination is not configured
     */
    public function resolve(string $environment, string $certificateType): string;
}
