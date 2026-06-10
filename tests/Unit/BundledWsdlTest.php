<?php

declare(strict_types=1);

/**
 * The official AEAT WSDL is bundled (with its XSD imports rewritten to the
 * local resources) so SoapClient can be built without network access. The
 * actual endpoint is forced via the 'location' option — the WSDL declares
 * 8 ports and SoapClient would otherwise pick the first one.
 */
it('bundles the official WSDL as a package resource', function () {
    expect(file_exists(dirname(__DIR__, 2) . '/resources/wsdl/SistemaFacturacion.wsdl'))->toBeTrue();
});

it('builds a SoapClient offline from the bundled WSDL exposing the official operation', function () {
    $client = new SoapClient(dirname(__DIR__, 2) . '/resources/wsdl/SistemaFacturacion.wsdl', [
        'cache_wsdl' => WSDL_CACHE_NONE,
        'exceptions' => true,
    ]);

    $functions = array_map('strval', $client->__getFunctions());

    expect(implode("\n", $functions))->toContain('RegFactuSistemaFacturacion');
});
