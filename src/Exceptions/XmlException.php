<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Exceptions;

final class XmlException extends VerifactuException
{
    public static function cannotBuildXml(string $reason): self
    {
        return self::make("Cannot build XML: {$reason}");
    }

    public static function cannotParseXml(string $reason): self
    {
        return self::make("Cannot parse XML: {$reason}");
    }

    public static function xsdSchemaNotFound(string $path): self
    {
        return self::make("XSD schema not found at path: {$path}");
    }

    public static function invalidXsdSchema(string $reason): self
    {
        return self::make("Invalid XSD schema: {$reason}");
    }
}
