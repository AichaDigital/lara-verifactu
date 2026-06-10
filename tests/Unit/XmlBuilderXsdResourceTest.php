<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Services\XmlBuilder;

it('bundles the official SuministroLR XSD as a package resource', function () {
    $xsdPath = dirname(__DIR__, 2) . '/resources/xsd/SuministroLR.xsd';

    expect(file_exists($xsdPath))->toBeTrue();
});

it('returns false when validating XML that does not conform to the official schema', function () {
    $builder = new XmlBuilder;

    $result = $builder->validate('<?xml version="1.0"?><NotVerifactu/>');

    expect($result)->toBeFalse();
});
