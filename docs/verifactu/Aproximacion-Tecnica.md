# Consideraciones Técnicas para Paquete Laravel Verifactu

## Resumen ejecutivo

El paquete debe proporcionar una implementación completa del sistema Verifactu de la AEAT para aplicaciones Laravel, con enfoque en arquitectura agnóstica que permita su integración tanto en proyectos nuevos como en sistemas de facturación existentes.

Fecha de entrada en vigor obligatoria: 29 de julio de 2025 para software, 1 de enero de 2026 para empresas, 1 de julio de 2026 para autónomos.

## Principio arquitectónico fundamental: Agnosticismo de datos

### Concepto

El paquete debe funcionar en dos modos:

**Modo completo (out-of-the-box)**
El paquete proporciona modelos Eloquent, migraciones y estructura de base de datos lista para usar. Ideal para proyectos nuevos o sistemas sin facturación previa.

**Modo agnóstico (adaptable)**
El paquete se integra con modelos y tablas existentes mediante contratos. El desarrollador implementa interfaces en sus propios modelos, mapeando sus campos a los requerimientos de Verifactu. Ideal para sistemas de facturación existentes.

### Ventajas del enfoque agnóstico

**Flexibilidad de integración**
Los sistemas existentes no necesitan reestructurar sus bases de datos. Solo implementan contratos y mapean campos.

**Cumplimiento del principio de inversión de dependencias**
El paquete depende de abstracciones, no de implementaciones concretas. Los modelos pueden ser intercambiados sin modificar el core del paquete.

**Testabilidad mejorada**
Al trabajar con interfaces, se pueden crear mocks fácilmente para testing sin depender de la base de datos.

**Menor fricción de adopción**
Empresas con sistemas legacy pueden adoptar Verifactu sin migraciones masivas de datos ni refactorizaciones estructurales.

## Arquitectura propuesta mediante contratos

### Contratos principales

```php
namespace VendorName\Verifactu\Contracts;

interface InvoiceContract
{
    public function getIssuerTaxId(): string;
    public function getInvoiceNumber(): string;
    public function getIssueDate(): Carbon;
    public function getInvoiceType(): InvoiceTypeEnum;
    public function getDescription(): ?string;
    public function getTotalAmount(): string;
    public function getTotalTaxAmount(): string;
    public function getBreakdowns(): Collection;
    public function getRecipient(): ?RecipientContract;
    public function getPreviousInvoiceId(): ?string;
    public function getPreviousHash(): ?string;
}

interface InvoiceBreakdownContract
{
    public function getTaxType(): TaxTypeEnum;
    public function getRegimeType(): RegimeTypeEnum;
    public function getOperationType(): OperationTypeEnum;
    public function getTaxRate(): string;
    public function getBaseAmount(): string;
    public function getTaxAmount(): string;
}

interface RecipientContract
{
    public function getTaxId(): string;
    public function getName(): string;
    public function getCountryCode(): string;
    public function getIdType(): IdTypeEnum;
}

interface RegistryContract
{
    public function getRegistryId(): string;
    public function getInvoice(): InvoiceContract;
    public function getHash(): string;
    public function getSignature(): ?string;
    public function getQrCode(): string;
    public function getXmlContent(): string;
    public function getStatus(): RegistryStatusEnum;
    public function getAeatResponse(): ?array;
    public function markAsSent(): void;
    public function markAsAccepted(): void;
    public function markAsRejected(array $errors): void;
}
```

### Configuración del modo de operación

```php
// config/verifactu.php
return [
    // Modo de operación
    'mode' => env('VERIFACTU_MODE', 'native'), // 'native' o 'custom'
    
    // En modo 'native', usa los modelos del paquete
    'models' => [
        'invoice' => \VendorName\Verifactu\Models\Invoice::class,
        'breakdown' => \VendorName\Verifactu\Models\InvoiceBreakdown::class,
        'recipient' => \VendorName\Verifactu\Models\Recipient::class,
        'registry' => \VendorName\Verifactu\Models\InvoiceRegistry::class,
    ],
    
    // En modo 'custom', apunta a modelos del usuario
    // 'models' => [
    //     'invoice' => \App\Models\Invoice::class,
    //     'breakdown' => \App\Models\InvoiceLine::class,
    //     'recipient' => \App\Models\Customer::class,
    //     'registry' => \VendorName\Verifactu\Models\InvoiceRegistry::class,
    // ],
    
    // Mapeo de campos personalizado (solo en modo 'custom')
    'field_mapping' => [
        'invoice' => [
            'issuer_tax_id' => 'nif_emisor',
            'invoice_number' => 'numero_factura',
            'issue_date' => 'fecha_emision',
            'total_amount' => 'importe_total',
            // ...
        ],
    ],
];
```

### Implementación en modelos de usuario

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use VendorName\Verifactu\Contracts\InvoiceContract;
use VendorName\Verifactu\Traits\VerifactuInvoice;

class Invoice extends Model implements InvoiceContract
{
    use VerifactuInvoice;
    
    // El trait proporciona implementaciones por defecto
    // El usuario solo override lo necesario
    
    public function getIssuerTaxId(): string
    {
        // Mapea desde el campo existente
        return $this->company->tax_id;
    }
    
    public function getInvoiceNumber(): string
    {
        return $this->invoice_number;
    }
    
    public function getBreakdowns(): Collection
    {
        // Transforma relaciones existentes
        return $this->lines->map(function ($line) {
            return new InvoiceBreakdownAdapter($line);
        });
    }
}
```

## Gestión de registros: Tabla propia del paquete

Independientemente del modo de operación, el paquete debe gestionar su propia tabla de registros que actúa como log y control de envíos a la AEAT.

### Justificación técnica

**Separación de responsabilidades**
La tabla de facturas del usuario contiene lógica de negocio. Los registros Verifactu son aspectos técnicos de cumplimiento fiscal.

**No contaminar el modelo de negocio**
Los campos específicos de Verifactu (hash, QR, XML, estado AEAT) no pertenecen conceptualmente al modelo de factura del negocio.

**Trazabilidad completa**
Permite mantener histórico completo de envíos, respuestas, reintentos y estados sin modificar la estructura existente.

**Facilita reintentos y auditoría**
Sistema independiente para gestionar cola de envíos, errores y reenvíos sin afectar el flujo de facturación normal.

### Estructura de tabla de registros

```php
Schema::create('verifactu_registries', function (Blueprint $table) {
    $table->id();
    $table->uuid('registry_id')->unique();
    
    // Polimórfica para soportar diferentes modelos de factura
    $table->morphs('invoiceable');
    
    // Datos de encadenamiento
    $table->string('hash', 64);
    $table->string('previous_hash', 64)->nullable();
    $table->string('previous_invoice_id')->nullable();
    
    // Seguridad
    $table->text('signature')->nullable();
    $table->string('signature_algorithm')->nullable();
    
    // QR y XML
    $table->text('qr_code');
    $table->longText('xml_content');
    
    // Control de envío
    $table->enum('status', ['pending', 'sent', 'accepted', 'rejected', 'error'])
          ->default('pending');
    $table->timestamp('sent_at')->nullable();
    $table->integer('retry_count')->default(0);
    $table->timestamp('last_retry_at')->nullable();
    
    // Respuesta AEAT
    $table->json('aeat_response')->nullable();
    $table->json('aeat_errors')->nullable();
    
    // Metadatos
    $table->string('environment')->default('production'); // production|sandbox
    $table->string('operation_type')->default('alta'); // alta|anulacion|subsanacion
    
    $table->timestamps();
    $table->softDeletes();
    
    // Índices
    $table->index(['invoiceable_type', 'invoiceable_id']);
    $table->index('status');
    $table->index('sent_at');
    $table->index('environment');
});
```

### Relación con modelos de usuario

```php
// En el modelo de factura del usuario
public function verifactuRegistry()
{
    return $this->morphOne(InvoiceRegistry::class, 'invoiceable');
}

// Uso
$invoice = Invoice::find(1);
$registry = $invoice->verifactuRegistry;

if ($registry) {
    echo "Estado: " . $registry->status;
    echo "Hash: " . $registry->hash;
    echo "QR: " . $registry->qr_code;
}
```

## Servicios core del paquete

### HashGenerator

Calcula el hash SHA-256 del registro según especificaciones oficiales de la AEAT.

```php
interface HashGeneratorContract
{
    public function generate(InvoiceContract $invoice): string;
    public function verify(string $hash, InvoiceContract $invoice): bool;
}
```

### QrGenerator

Genera el código QR con la URL de validación y parámetros requeridos.

```php
interface QrGeneratorContract
{
    public function generate(InvoiceContract $invoice, string $hash): string;
    public function getValidationUrl(InvoiceContract $invoice, string $hash): string;
}
```

### XmlBuilder

Construye el XML conforme a los esquemas XSD oficiales de la AEAT.

```php
interface XmlBuilderContract
{
    public function buildRegistrationXml(InvoiceContract $invoice): string;
    public function buildCancellationXml(string $registryId): string;
    public function buildBatchXml(Collection $invoices): string;
    public function validate(string $xml): bool;
}
```

### AeatClient

Cliente SOAP para comunicación con servicios web de la AEAT.

```php
interface AeatClientContract
{
    public function sendRegistration(RegistryContract $registry): AeatResponse;
    public function sendCancellation(string $registryId): AeatResponse;
    public function sendBatch(Collection $registries): Collection;
    public function queryRegistry(string $registryId): AeatResponse;
    public function validateQr(string $qrCode): AeatResponse;
}
```

### CertificateManager

Gestiona certificados electrónicos para autenticación con la AEAT.

```php
interface CertificateManagerContract
{
    public function load(string $path, string $password): void;
    public function sign(string $content): string;
    public function verify(string $content, string $signature): bool;
    public function getCertificateInfo(): array;
}
```

## Facade principal del paquete

Proporciona una API fluida y expresiva siguiendo el estilo Laravel.

```php
use VendorName\Verifactu\Facades\Verifactu;

// Registrar factura
$result = Verifactu::register($invoice);

// Anular registro
Verifactu::cancel($registryId);

// Enviar lote
Verifactu::sendBatch($invoices);

// Consultar estado
$status = Verifactu::status($invoice);

// Obtener QR
$qr = Verifactu::qr($invoice);

// Validar cadena
$isValid = Verifactu::validateChain($invoice);
```

## Sistema de eventos

Permite extensibilidad y reacción a cambios de estado.

```php
// Eventos disponibles
InvoiceRegistering::class
InvoiceRegistered::class
InvoiceRegistrationFailed::class

RegistrySending::class
RegistrySent::class
RegistryAccepted::class
RegistryRejected::class

ChainBroken::class
```

### Uso de eventos

```php
// En EventServiceProvider
protected $listen = [
    InvoiceRegistered::class => [
        SendInvoiceNotification::class,
        LogRegistryActivity::class,
    ],
    
    RegistryRejected::class => [
        NotifyAccountant::class,
        ScheduleRetry::class,
    ],
];
```

## Sistema de colas y trabajos

Procesamiento asíncrono de envíos a la AEAT para no bloquear la aplicación.

```php
// Job principal
SendInvoiceToAeat::class

// Configuración
'queue' => env('VERIFACTU_QUEUE', 'verifactu'),
'connection' => env('VERIFACTU_QUEUE_CONNECTION', 'redis'),

// Uso
SendInvoiceToAeat::dispatch($invoice)->onQueue('verifactu');

// Con reintentos automáticos
SendInvoiceToAeat::dispatch($invoice)
    ->onQueue('verifactu')
    ->retry(3)
    ->backoff([60, 300, 600]);
```

## Comandos Artisan

### Instalación inicial

```bash
php artisan verifactu:install
```

Publica configuración, migraciones y assets. Pregunta modo de operación.

### Envío de facturas pendientes

```bash
php artisan verifactu:send-pending
```

Envía todas las facturas con registro en estado pendiente.

### Reintentar rechazadas

```bash
php artisan verifactu:retry-failed
```

Reintenta envío de facturas rechazadas con errores subsanables.

### Validar cadena de bloques

```bash
php artisan verifactu:validate-chain
```

Verifica integridad de la cadena de hashes.

### Sincronizar con AEAT

```bash
php artisan verifactu:sync
```

Consulta estado actual en AEAT de registros enviados.

## Testing

### Estrategia de pruebas

**Unit tests**
Cada servicio core debe tener tests unitarios completos. Uso de mocks para dependencias externas.

**Feature tests**
Tests de integración que verifican flujo completo desde factura hasta registro exitoso.

**Sandbox tests**
Tests contra entorno de pruebas de la AEAT. Requieren certificado de prueba y configuración específica.

### Facilitadores de testing

```php
// Trait para tests
use VerifactuTestHelpers;

// Factories
InvoiceFactory::new()->create();
RegistryFactory::new()->pending()->create();

// Mocks
Verifactu::fake();
Verifactu::assertRegistered($invoice);
Verifactu::assertNotSent($invoice);

// Sandbox helpers
Verifactu::sandbox();
Verifactu::useSandboxCertificate($path, $password);
```

## Manejo de errores

### Jerarquía de excepciones

```php
VerifactuException
├── ConfigurationException
├── CertificateException
├── ValidationException
├── AeatException
│   ├── AeatConnectionException
│   ├── AeatAuthenticationException
│   └── AeatRejectionException
├── HashException
└── XmlException
```

### Estrategia de reintentos

**Errores de conexión**
Reintento automático con backoff exponencial.

**Errores de validación**
No reintentar. Notificar para corrección manual.

**Errores de negocio AEAT**
Reintento según tipo de error. Algunos son subsanables, otros requieren anulación y nuevo registro.

## Logging y auditoría

### Canales de log

```php
'channels' => [
    'verifactu' => [
        'driver' => 'daily',
        'path' => storage_path('logs/verifactu.log'),
        'level' => 'debug',
        'days' => 90,
    ],
],
```

### Información registrada

- Todas las comunicaciones SOAP (request/response)
- Cambios de estado de registros
- Errores y excepciones con contexto completo
- Operaciones de hash y firma
- Validaciones de cadena de bloques

## Configuración de seguridad

### Almacenamiento de certificados

Nunca en el repositorio. Utilizar almacenamiento seguro:

```php
'certificate' => [
    'path' => env('VERIFACTU_CERT_PATH'),
    'password' => env('VERIFACTU_CERT_PASSWORD'),
    'type' => env('VERIFACTU_CERT_TYPE', 'certificate'), // certificate|seal
],

// Storage seguro
'certificate_storage' => [
    'disk' => 'local-secure', // Disco no público
    'path' => 'certificates/verifactu',
],
```

### Validación estricta de entrada

Todas las entradas deben validarse mediante Form Requests antes de procesamiento.

### Conexiones HTTPS

Verificación estricta de certificados SSL en todas las comunicaciones con AEAT.

## Análisis de squareetlabs/LaravelVerifactu

### Puntos fuertes identificados

- Estructura básica de modelos bien definida
- Uso de Enums para tipos fiscales
- Form Requests para validación
- API Resources para respuestas

### Áreas de mejora

**Acoplamiento fuerte a modelos específicos**
No permite integración con sistemas existentes. El enfoque agnóstico resolvería esto.

**Ausencia de sistema de colas**
Envíos síncronos pueden bloquear la aplicación. Implementar Jobs asíncronos.

**Testing limitado**
Necesita suite de tests más completa incluyendo integración con sandbox.

**Sin gestión de reintentos**
No hay sistema robusto para manejar errores y reintentar automáticamente.

**Documentación escasa**
Falta documentación técnica detallada y ejemplos de uso avanzado.

### Estrategia de aprovechamiento

**Mantener como referencia**
Usar estructura de Enums, definiciones de campos y mapeos de normativa.

**Extraer lógica de validación**
Las validaciones de campos según especificaciones AEAT son reutilizables.

**Rediseñar arquitectura**
Implementar desde cero con enfoque agnóstico mediante contratos.

**Expandir funcionalidad**
Añadir colas, eventos, reintentos, logging completo y testing exhaustivo.

## Roadmap de desarrollo

### Fase 1: Fundamentos

- Definir contratos e interfaces
- Implementar servicios core (Hash, QR, XML)
- Crear modelos nativos con migraciones
- Configuración básica

### Fase 2: Comunicación AEAT

- Implementar AeatClient con SOAP
- Gestión de certificados
- Manejo de respuestas y errores
- Tests contra sandbox

### Fase 3: Modo agnóstico

- Traits para implementación en modelos de usuario
- Sistema de configuración de mapeo
- Adapters para transformación de datos
- Documentación de integración

### Fase 4: Robustez

- Sistema de colas y Jobs
- Reintentos automáticos
- Eventos y listeners
- Logging y auditoría completa

### Fase 5: Experiencia de usuario

- Comandos Artisan
- Helpers y facades
- Panel de administración opcional
- Generadores de código

### Fase 6: Testing y documentación

- Suite de tests completa
- Documentación técnica exhaustiva
- Guías de integración
- Ejemplos prácticos

### Fase 7: Optimización

- Caché de consultas AEAT
- Optimización de rendimiento
- Métricas y monitoreo
- Preparación para producción

## Consideraciones de Laravel y su ecosistema

### Versionado de Laravel

Soportar versiones LTS prioritariamente: Laravel 11 y 12.

### PSR y estándares

- PSR-4 para autoloading
- PSR-12 para estilo de código
- PSR-3 para logging
- PHPStan nivel 8 mínimo

### Integración con ecosistema

**Laravel Horizon**
Para monitoreo de colas de envío.

**Laravel Telescope**
Para debugging en desarrollo.

**Laravel Octane**
Compatible para alta performance.

**Laravel Sail**
Proporcionar configuración Docker.

### Service Container

Uso extensivo del contenedor de servicios para inversión de dependencias y testabilidad.

### Eloquent vs Query Builder

Favorecer Eloquent para modelos nativos pero permitir Query Builder en modo agnóstico para flexibilidad.

## Documentación y ejemplos

### Documentación técnica

README completo con instalación, configuración y ejemplos básicos.

Documentación extendida con arquitectura, contratos y API reference.

Guía de integración para sistemas existentes.

Guía de migración desde otros paquetes.

### Ejemplos prácticos

```php
// Ejemplo 1: Modo nativo (nuevo proyecto)
$invoice = Invoice::create([...]);
Verifactu::register($invoice);

// Ejemplo 2: Modo agnóstico (sistema existente)
// app/Models/Invoice.php implementa InvoiceContract
$invoice = Invoice::find(1);
Verifactu::register($invoice);

// Ejemplo 3: Envío en lote
$invoices = Invoice::whereDate('created_at', today())->get();
Verifactu::sendBatch($invoices);

// Ejemplo 4: Manejo de errores
try {
    Verifactu::register($invoice);
} catch (AeatRejectionException $e) {
    Log::error('Factura rechazada', [
        'invoice_id' => $invoice->id,
        'errors' => $e->getAeatErrors(),
    ]);
}

// Ejemplo 5: Consulta de estado
$status = Verifactu::status($invoice);
if ($status->isAccepted()) {
    // Procesamiento post-aceptación
}
```

## Métricas de calidad

### Code coverage

Objetivo: 90% de cobertura de tests.

### Análisis estático

PHPStan nivel 8 sin errores.

### Rendimiento

Generación de XML: menos de 100ms por factura.

Envío a AEAT: timeout configurable, por defecto 30s.

Procesamiento de lote: soporte para 1000 facturas según límite AEAT.

## Licencia y contribución

### Licencia sugerida

MIT para máxima adopción y flexibilidad.

### Guía de contribución

CONTRIBUTING.md con estándares de código, proceso de PR y testing requirements.

### Changelog

Mantener CHANGELOG.md siguiendo Keep a Changelog.

## Enlaces de referencia

### Documentación oficial AEAT

Portal desarrolladores: https://www.agenciatributaria.es/AEAT.desarrolladores/

Portal de pruebas externas: https://preportal.aeat.es/

Verifactu específico: https://preportal.aeat.es/PRE-Exteriores/Inicio/_menu_/VERI_FACTU___Sistemas_Informaticos_de_Facturacion/

### Recursos técnicos

Esquemas XSD y WSDL: https://github.com/hectorsipe/aeat-verifactu

Implementación PHP referencia: https://github.com/josemmo/Verifactu-PHP

Paquete Laravel para análisis: https://github.com/squareetlabs/LaravelVerifactu

### Consultas técnicas

Email AEAT: verifactu@correo.aeat.es

## Conclusión

El desarrollo de este paquete requiere equilibrio entre cumplimiento normativo estricto y flexibilidad de integración. El enfoque agnóstico mediante contratos permite que el paquete sea útil tanto para proyectos nuevos como para sistemas existentes, maximizando su adopción en el ecosistema Laravel.

La clave del éxito radica en mantener la complejidad técnica de Verifactu encapsulada en servicios especializados mientras se proporciona una API simple y expresiva para el usuario final. El testing exhaustivo y la documentación clara son fundamentales para generar confianza en un paquete que maneja aspectos críticos de cumplimiento fiscal.

La implementación debe seguir rigurosamente los principios SOLID, enfatizando la inversión de dependencias mediante contratos y la responsabilidad única de cada componente. Esto no solo mejora la testabilidad y mantenibilidad, sino que también facilita futuras extensiones ante cambios normativos.

