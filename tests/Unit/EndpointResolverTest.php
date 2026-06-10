<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Exceptions\ConfigurationException;
use AichaDigital\LaraVerifactu\Services\ConfigEndpointResolver;

/**
 * The AEAT SOAP endpoint depends on BOTH the environment and the certificate
 * type: seal certificates (sello) use the www10/prewww10 hosts while citizen
 * and representative certificates use www1/prewww1 (see the port bindings in
 * docs/verifactu/WSDL_servicios_web.xml).
 */
beforeEach(function () {
    $this->resolver = new ConfigEndpointResolver;
});

it('resolves every environment and certificate type combination from the official WSDL', function (string $environment, string $certificateType, string $expected) {
    expect($this->resolver->resolve($environment, $certificateType))->toBe($expected);
})->with([
    'production ciudadano' => ['production', 'ciudadano', 'https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'],
    'production representante' => ['production', 'representante', 'https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'],
    'production sello' => ['production', 'sello', 'https://www10.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'],
    'sandbox ciudadano' => ['sandbox', 'ciudadano', 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'],
    'sandbox representante' => ['sandbox', 'representante', 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'],
    'sandbox sello' => ['sandbox', 'sello', 'https://prewww10.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'],
]);

it('throws a typed exception for an unknown environment', function () {
    $this->resolver->resolve('staging', 'representante');
})->throws(ConfigurationException::class);

it('throws a typed exception for an unknown certificate type', function () {
    $this->resolver->resolve('production', 'autonomo');
})->throws(ConfigurationException::class);

it('honours endpoint overrides from config', function () {
    config()->set('verifactu.aeat.endpoints.sandbox.representante', 'https://custom.example.test/soap');

    expect($this->resolver->resolve('sandbox', 'representante'))
        ->toBe('https://custom.example.test/soap');
});
