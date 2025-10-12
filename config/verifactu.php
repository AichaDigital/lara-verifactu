<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Operation Mode
    |--------------------------------------------------------------------------
    |
    | Choose between 'native' or 'custom' mode:
    | - native: use package built-in models (ideal for new projects)
    | - custom: integrate with existing models (ideal for legacy systems)
    |
    */

    'mode' => env('VERIFACTU_MODE', 'native'),

    /*
    |--------------------------------------------------------------------------
    | Models Configuration
    |--------------------------------------------------------------------------
    |
    | Define which models to use for each entity. In native mode, these point
    | to package models. In custom mode, point to your models that implement
    | the required contracts.
    |
    */

    'models' => [
        'invoice' => \AichaDigital\LaraVerifactu\Models\Invoice::class,
        'breakdown' => \AichaDigital\LaraVerifactu\Models\InvoiceBreakdown::class,
        'registry' => \AichaDigital\LaraVerifactu\Models\Registry::class,
        // Note: Recipient is a contract, not a model. Implement in Invoice::getRecipient()
    ],

    /*
    |--------------------------------------------------------------------------
    | AEAT Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for AEAT web services connection.
    |
    */

    'aeat' => [
        'environment' => env('VERIFACTU_ENVIRONMENT', 'production'), // production|sandbox

        'endpoints' => [
            'production' => env('VERIFACTU_PRODUCTION_ENDPOINT', 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion'),
            'sandbox' => env('VERIFACTU_SANDBOX_ENDPOINT', 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion'),
        ],

        'wsdl' => [
            'production' => env('VERIFACTU_PRODUCTION_WSDL', 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion?wsdl'),
            'sandbox' => env('VERIFACTU_SANDBOX_WSDL', 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion?wsdl'),
        ],

        'timeout' => env('VERIFACTU_TIMEOUT', 30),

        'verify_ssl' => env('VERIFACTU_VERIFY_SSL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificate Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for digital certificates used to authenticate with AEAT.
    | NEVER commit certificate files to version control.
    |
    */

    'certificate' => [
        'path' => env('VERIFACTU_CERT_PATH'),
        'password' => env('VERIFACTU_CERT_PASSWORD'),
        'type' => env('VERIFACTU_CERT_TYPE', 'certificate'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificate Storage
    |--------------------------------------------------------------------------
    |
    | Define where certificates are stored securely. This should be a
    | non-public disk.
    |
    */

    'certificate_storage' => [
        'disk' => env('VERIFACTU_CERT_DISK', 'local'),
        'path' => env('VERIFACTU_CERT_STORAGE_PATH', 'certificates/verifactu'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the queue for asynchronous processing of invoices.
    |
    */

    'queue' => [
        'connection' => env('VERIFACTU_QUEUE_CONNECTION', 'redis'),
        'name' => env('VERIFACTU_QUEUE', 'verifactu'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configure automatic retry behavior for failed submissions.
    |
    */

    'retry' => [
        'enabled' => env('VERIFACTU_RETRY_ENABLED', true),
        'max_attempts' => env('VERIFACTU_RETRY_MAX_ATTEMPTS', 3),
        'backoff' => [60, 300, 600],
    ],

    /*
    |--------------------------------------------------------------------------
    | QR Code Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for QR code generation.
    |
    */

    'qr' => [
        'format' => env('VERIFACTU_QR_FORMAT', 'png'),
        'size' => env('VERIFACTU_QR_SIZE', 300),
        'validation_url' => env('VERIFACTU_QR_VALIDATION_URL', 'https://www.aeat.es/verifactu/qr'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure logging behavior for the package.
    |
    */

    'logging' => [
        'channel' => env('VERIFACTU_LOG_CHANNEL', 'verifactu'),
        'level' => env('VERIFACTU_LOG_LEVEL', 'debug'),
        'days' => env('VERIFACTU_LOG_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configure caching for AEAT queries.
    |
    */

    'cache' => [
        'enabled' => env('VERIFACTU_CACHE_ENABLED', true),
        'ttl' => env('VERIFACTU_CACHE_TTL', 3600),
        'prefix' => 'verifactu',
    ],

    /*
    |--------------------------------------------------------------------------
    | Field Mapping (Custom Mode Only)
    |--------------------------------------------------------------------------
    |
    | When using 'custom' mode, define how your existing fields map to
    | Verifactu requirements.
    |
    */

    'field_mapping' => [
        'invoice' => [
            // 'issuer_tax_id' => 'nif_emisor',
            // 'invoice_number' => 'numero_factura',
            // 'issue_date' => 'fecha_emision',
            // 'total_amount' => 'importe_total',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Processing
    |--------------------------------------------------------------------------
    |
    | Configuration for batch processing of invoices.
    |
    */

    'batch' => [
        'size' => env('VERIFACTU_BATCH_SIZE', 100),
        'enabled' => env('VERIFACTU_BATCH_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    |
    | Configure validation behavior.
    |
    */

    'validation' => [
        'strict' => env('VERIFACTU_VALIDATION_STRICT', true),
        'xsd_validation' => env('VERIFACTU_XSD_VALIDATION', true),
    ],

];
