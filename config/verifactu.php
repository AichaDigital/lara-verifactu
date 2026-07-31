<?php

declare(strict_types=1);
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\InvoiceBreakdown;
use AichaDigital\LaraVerifactu\Models\Registry;

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
    | Company (Obligado a expedir factura)
    |--------------------------------------------------------------------------
    |
    | Issuer identification used in the registry hash (IDEmisorFactura),
    | the registration XML and the QR cotejo URL. The tax_id is REQUIRED
    | for AEAT-conformant output.
    |
    */

    'company' => [
        'tax_id' => env('VERIFACTU_COMPANY_TAX_ID'),
        'name' => env('VERIFACTU_COMPANY_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | System Identification (SistemaInformatico)
    |--------------------------------------------------------------------------
    |
    | Invoicing software identification reported to AEAT inside every
    | registry record (SistemaInformaticoType). vendor_* identify the
    | software producer; id is limited to 2 characters by the XSD.
    |
    */

    'system' => [
        'vendor_name' => env('VERIFACTU_SYSTEM_VENDOR_NAME'),
        'vendor_nif' => env('VERIFACTU_SYSTEM_VENDOR_NIF'),
        'name' => env('VERIFACTU_SYSTEM_NAME', 'LaraVerifactu'),
        'id' => env('VERIFACTU_SYSTEM_ID', 'LV'),
        'version' => env('VERIFACTU_SYSTEM_VERSION', '1.0'),
        'installation_number' => env('VERIFACTU_INSTALLATION_NUMBER', '1'),
        'only_verifactu' => env('VERIFACTU_SYSTEM_ONLY_VERIFACTU', 'S'),
        'multi_ot' => env('VERIFACTU_SYSTEM_MULTI_OT', 'N'),
        'multiple_ot' => env('VERIFACTU_SYSTEM_MULTIPLE_OT', 'N'),
    ],

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
        'invoice' => Invoice::class,
        'breakdown' => InvoiceBreakdown::class,
        'registry' => Registry::class,
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

        /*
        | SOAP endpoints from the official AEAT WSDL port bindings
        | (docs/verifactu/WSDL_servicios_web.xml, verified live against
        | prewww2.aeat.es/static_files/.../SistemaFacturacion.wsdl).
        | The host depends on the certificate type: seal certificates
        | (sello) use www10/prewww10, citizen and representative
        | certificates use www1/prewww1.
        */
        'endpoints' => [
            'production' => [
                'ciudadano' => 'https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP',
                'representante' => 'https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP',
                'sello' => 'https://www10.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP',
            ],
            'sandbox' => [
                'ciudadano' => 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP',
                'representante' => 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP',
                'sello' => 'https://prewww10.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP',
            ],
        ],

        // WSDL override per environment. When null (default) the bundled
        // official WSDL (resources/wsdl/SistemaFacturacion.wsdl) is used —
        // offline, with the endpoint forced from the resolved location.
        'wsdl' => [
            'production' => env('VERIFACTU_PRODUCTION_WSDL'),
            'sandbox' => env('VERIFACTU_SANDBOX_WSDL'),
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
        // ciudadano | representante | sello — determines the SOAP endpoint host
        'type' => env('VERIFACTU_CERT_TYPE', 'representante'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registry XML Signing
    |--------------------------------------------------------------------------
    |
    | In VERI*FACTU mode registry records are NOT signed: the chained
    | fingerprint (huella) replaces the signature. XAdES signing only
    | applies to the non-Verifactu modality — keep disabled unless you
    | know you need it.
    |
    */

    'signing' => [
        'enabled' => env('VERIFACTU_SIGNING_ENABLED', false),
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
    | Queue Configuration (v2.0)
    |--------------------------------------------------------------------------
    |
    | Configure the queue for asynchronous processing of invoices.
    |
    | v2.0 Changes:
    | - Default queue changed from 'verifactu' to 'fiscal_verification'
    | - Dedicated queue ensures sequential processing
    | - Recommended: Single worker for this queue
    |
    */

    'queue' => [
        'connection' => env('VERIFACTU_QUEUE_CONNECTION', 'redis'),
        'name' => env('VERIFACTU_QUEUE', 'fiscal_verification'), // v2.0: Changed default
    ],

    /*
    |--------------------------------------------------------------------------
    | Lock Configuration (v2.0 NEW)
    |--------------------------------------------------------------------------
    |
    | Configure lock behavior for sequential processing.
    |
    | v2.0: New configuration section for unique lock management.
    | The lock ensures only ONE invoice is processed at a time for fiscal
    | compliance.
    |
    */

    'lock' => [
        'enabled' => env('VERIFACTU_LOCK_ENABLED', true),
        'timeout' => env('VERIFACTU_LOCK_TIMEOUT', 300), // 5 minutes
        'retry_delay' => env('VERIFACTU_LOCK_RETRY_DELAY', 10), // 10 seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Guard (AID-725)
    |--------------------------------------------------------------------------
    |
    | Submitting to the AEAT from inside a transaction the CALLER opened is
    | unsafe: a nested DB::transaction() is a SAVEPOINT, so the record is not
    | durable when the submission leaves, and an outer rollback erases a record
    | the agency already accepted. register()/cancel()/amendRejected() refuse
    | that combination.
    |
    | This value is the nesting level the environment considers "not inside a
    | caller's transaction".
    |
    | In production it is ALWAYS 0. Raise it to 1 ONLY in a test harness that
    | wraps every test in a transaction of its own (Laravel's RefreshDatabase
    | does): there, level 1 belongs to the harness, not to a caller, and leaving
    | this at 0 would make every test that submits fail for the wrong reason.
    | It is not a way to opt out of the guard — at any baseline, a transaction
    | the caller opens is still one level above it, and still refused.
    |
    */

    'transaction_guard' => [
        'baseline_level' => env('VERIFACTU_TRANSACTION_BASELINE_LEVEL', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration (v2.0)
    |--------------------------------------------------------------------------
    |
    | Configure automatic retry behavior for failed submissions.
    |
    | v2.0 Changes:
    | - Default max_attempts changed from 3 to 1
    | - Manual intervention required after failure
    | - Use verifactu:retry-failed command to retry
    |
    */

    'retry' => [
        'enabled' => env('VERIFACTU_RETRY_ENABLED', true),
        'max_attempts' => env('VERIFACTU_RETRY_MAX_ATTEMPTS', 1), // v2.0: Changed default
        'delay' => env('VERIFACTU_RETRY_DELAY', 60), // Delay between retries in seconds
        'backoff' => [60, 120, 240], // Exponential backoff (if manual retry with >1 attempts)
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

        // Official AEAT cotejo service URLs. The active one is picked from
        // aeat.environment; set validation_url to override explicitly.
        'validation_url' => env('VERIFACTU_QR_VALIDATION_URL'),
        'validation_urls' => [
            'production' => 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR',
            'sandbox' => 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR',
        ],
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
        'level' => env('VERIFACTU_LOG_LEVEL', 'info'),
        'days' => env('VERIFACTU_LOG_DAYS', 90),

        // Opt-in: log the SOAP request/response exchange. Even when enabled the
        // payload is ALWAYS passed through AeatLogSanitizer — fiscal personal
        // data (NIF, names, amounts, dates, signature) is masked. There is no
        // configuration to log the raw payload. Keep disabled in production.
        'log_soap_payload' => env('VERIFACTU_LOG_SOAP_PAYLOAD', false),

        // Opt-in: include full stack traces in error logs. Traces can reveal
        // internal filesystem paths; keep disabled outside active debugging.
        'debug' => env('VERIFACTU_LOG_DEBUG', false),
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
