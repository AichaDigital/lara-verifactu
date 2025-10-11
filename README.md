# Lara Verifactu

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aichadigital/lara-verifactu.svg?style=flat-square)](https://packagist.org/packages/aichadigital/lara-verifactu)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/aichadigital/lara-verifactu/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/aichadigital/lara-verifactu/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/aichadigital/lara-verifactu/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/aichadigital/lara-verifactu/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/aichadigital/lara-verifactu.svg?style=flat-square)](https://packagist.org/packages/aichadigital/lara-verifactu)

Paquete Laravel para cumplimiento normativo de **Verifactu (AEAT)** con arquitectura agnóstica que permite integración tanto en proyectos nuevos como en sistemas de facturación existentes.

## 🎯 Características

- ✅ **Arquitectura Agnóstica**: Funciona con tus modelos existentes o usa los nativos del paquete
- ✅ **Cumplimiento Total**: Implementación completa de especificaciones AEAT Verifactu
- ✅ **Procesamiento Asíncrono**: Sistema de colas para envíos no bloqueantes
- ✅ **Reintentos Automáticos**: Manejo inteligente de errores con reintentos configurables
- ✅ **Cadena de Bloques**: Generación y validación de hashes SHA-256 según normativa
- ✅ **Códigos QR**: Generación automática de QR para validación ciudadana
- ✅ **Eventos Laravel**: Sistema completo de eventos para extensibilidad
- ✅ **Testing Exhaustivo**: Suite de tests con >90% de cobertura
- ✅ **Documentación Completa**: Guías y ejemplos para todos los casos de uso
- ✅ **PHPStan Nivel 8**: Análisis estático estricto
- ✅ **Laravel 11 & 12**: Compatible con versiones LTS

## 📅 Fechas Importantes

- **29 de julio de 2025**: Obligatorio para software de facturación
- **1 de enero de 2026**: Obligatorio para empresas
- **1 de julio de 2026**: Obligatorio para autónomos

## 📦 Instalación

Puedes instalar el paquete vía Composer:

```bash
composer require aichadigital/lara-verifactu
```

Publicar configuración y migraciones:

```bash
php artisan verifactu:install
```

Este comando:
- Publica el archivo de configuración
- Publica las migraciones
- Te pregunta si deseas ejecutar las migraciones
- Te invita a dar ⭐ al repositorio

Configura tus variables de entorno:

```env
VERIFACTU_MODE=native
VERIFACTU_ENVIRONMENT=production
VERIFACTU_CERT_PATH=/path/to/certificate.pfx
VERIFACTU_CERT_PASSWORD=your-certificate-password
VERIFACTU_QUEUE_CONNECTION=redis
```

## 🚀 Uso Rápido

### Modo Nativo (Proyectos Nuevos)

```php
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Facades\Verifactu;

// Crear factura
$invoice = Invoice::create([
    'issuer_tax_id' => 'B12345678',
    'invoice_number' => 'F-2025-001',
    'issue_date' => now(),
    'total_amount' => '121.00',
    'total_tax_amount' => '21.00',
]);

// Registrar en Verifactu
$result = Verifactu::register($invoice);

if ($result->isSuccess()) {
    echo "Factura registrada correctamente";
    echo "QR: " . $invoice->verifactuRegistry->qr_code;
}
```

### Modo Agnóstico (Sistemas Existentes)

```php
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;
use AichaDigital\LaraVerifactu\Traits\VerifactuInvoice;

// En tu modelo existente
class Invoice extends Model implements InvoiceContract
{
    use VerifactuInvoice;
    
    public function getIssuerTaxId(): string
    {
        return $this->company->tax_id;
    }
    
    public function getInvoiceNumber(): string
    {
        return $this->invoice_number;
    }
    
    // Implementa otros métodos del contrato...
}

// Usar igual que en modo nativo
$invoice = Invoice::find(1);
Verifactu::register($invoice);
```

## 📚 Documentación Completa

### Configuración Avanzada

El archivo `config/verifactu.php` permite configurar:

- Modo de operación (nativo/personalizado)
- Endpoints AEAT (producción/sandbox)
- Certificados digitales
- Configuración de colas
- Estrategia de reintentos
- Generación de QR
- Logging y caché
- Procesamiento por lotes

### Comandos Artisan

```bash
# Enviar facturas pendientes
php artisan verifactu:send-pending

# Reintentar facturas rechazadas
php artisan verifactu:retry-failed

# Validar cadena de bloques
php artisan verifactu:validate-chain

# Sincronizar con AEAT
php artisan verifactu:sync
```

### Eventos Disponibles

```php
// Escuchar eventos
use AichaDigital\LaraVerifactu\Events\InvoiceRegistered;

Event::listen(InvoiceRegistered::class, function ($event) {
    Log::info('Factura registrada', [
        'invoice_id' => $event->invoice->id,
        'registry_id' => $event->registry->registry_id,
    ]);
});
```

Eventos disponibles:
- `InvoiceRegistering`, `InvoiceRegistered`, `InvoiceRegistrationFailed`
- `RegistrySending`, `RegistrySent`, `RegistryAccepted`, `RegistryRejected`
- `ChainBroken`

### Envío en Lote

```php
use AichaDigital\LaraVerifactu\Facades\Verifactu;

$invoices = Invoice::whereDate('created_at', today())->get();
$results = Verifactu::sendBatch($invoices);

foreach ($results as $result) {
    if ($result->isFailure()) {
        Log::error('Error en factura', $result->getErrors());
    }
}
```

### Testing Helpers

```php
use AichaDigital\LaraVerifactu\Facades\Verifactu;

// En tus tests
Verifactu::fake();

// Tu código que registra facturas...

Verifactu::assertRegistered($invoice);
Verifactu::assertNotSent($invoice);
```

## 🏗️ Arquitectura

El paquete sigue principios SOLID con arquitectura basada en contratos:

```
src/
├── Contracts/          # Interfaces y contratos
├── Models/             # Modelos Eloquent (modo nativo)
├── Services/           # Lógica de negocio
├── Facades/            # Facades Laravel
├── Commands/           # Comandos Artisan
├── Jobs/               # Trabajos de cola
├── Events/             # Eventos
├── Listeners/          # Listeners
├── Exceptions/         # Excepciones personalizadas
├── Enums/              # Enumeraciones
└── Traits/             # Traits reutilizables
```

### Servicios Core

- **HashGenerator**: Genera hashes SHA-256 según AEAT
- **QrGenerator**: Genera códigos QR de validación
- **XmlBuilder**: Construye XML conforme a XSD oficial
- **AeatClient**: Cliente SOAP para comunicación con AEAT
- **CertificateManager**: Gestiona certificados electrónicos

## 🧪 Testing

```bash
# Ejecutar todos los tests
composer test

# Tests con cobertura
composer test-coverage

# Análisis estático
composer analyse

# Formatear código
composer format
```

## 📖 Changelog

Consulta [CHANGELOG.md](CHANGELOG.md) para ver los cambios en cada versión.

## 🤝 Contribuir

Por favor revisa [CONTRIBUTING.md](CONTRIBUTING.md) para detalles sobre nuestro código de conducta y el proceso para enviarnos pull requests.

## 🔒 Seguridad

Si descubres algún problema de seguridad, por favor envía un email a security@aichadigital.com en lugar de usar el issue tracker.

## 🙏 Créditos

- [Aicha Digital](https://github.com/aichadigital)
- [Todos los Contribuidores](../../contributors)

Este paquete está inspirado en las mejores prácticas de [Spatie](https://spatie.be) y utiliza [Laravel Package Tools](https://github.com/spatie/laravel-package-tools).

## 📝 Licencia

The MIT License (MIT). Por favor consulta [License File](LICENSE.md) para más información.

## 🔗 Enlaces Útiles

- [Documentación Oficial AEAT Verifactu](https://www.agenciatributaria.es/AEAT.desarrolladores/)
- [Portal de Pruebas AEAT](https://preportal.aeat.es/)
- [Especificaciones Técnicas](https://preportal.aeat.es/PRE-Exteriores/Inicio/_menu_/VERI_FACTU___Sistemas_Informaticos_de_Facturacion/)
- [FAQ Desarrolladores](https://www.agenciatributaria.es/AEAT.internet/verifactu/faqs.html)

---

**Desarrollado con ❤️ por [Aicha Digital](https://aichadigital.com)**

