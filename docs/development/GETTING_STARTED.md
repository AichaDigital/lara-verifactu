# Getting Started - Lara Verifactu

## 🎉 ¡Bienvenido!

Has configurado exitosamente la estructura base del paquete **Lara Verifactu**. Este documento te guiará en los primeros pasos.

## ✅ Lo que ya está configurado

### 1. Estructura del Paquete
- ✅ Arquitectura completa con contratos
- ✅ Sistema de excepciones robusto
- ✅ Enums para todos los tipos de datos AEAT
- ✅ Configuración completa
- ✅ Service Provider
- ✅ Facade

### 2. Herramientas de Desarrollo
- ✅ PHPStan nivel 8
- ✅ Laravel Pint
- ✅ Pest Testing Framework
- ✅ Tests arquitectónicos

### 3. CI/CD
- ✅ GitHub Actions workflows
- ✅ Dependabot
- ✅ Templates de Issues y PRs

### 4. Documentación
- ✅ README completo
- ✅ Guía de contribución
- ✅ Changelog
- ✅ Reglas de Cursor

## 🚀 Primeros Pasos

### 1. Instalar Dependencias

```bash
cd /Users/abkrim/development/packages/aichadigital/lara-verifactu
composer install
```

### 2. Verificar Configuración

```bash
# Ejecutar análisis estático
composer analyse

# Formatear código
composer format
```

### 3. Configurar Git (Opcional)

Si deseas inicializar Git:

```bash
git init
git add .
git commit -m "chore: initial package structure"
```

### 4. Crear Repositorio en GitHub (Opcional)

```bash
gh repo create aichadigital/lara-verifactu --public --source=. --remote=origin
git push -u origin main
```

## 📝 Próximas Tareas de Desarrollo

### Fase 2: Servicios Core

#### 1. HashGenerator Service

```php
// src/Services/HashGenerator.php
final class HashGenerator implements HashGeneratorContract
{
    public function generate(InvoiceContract $invoice): string
    {
        // Implementar según especificaciones AEAT
        // Ver: documentacion_verifactu/Veri-Factu_especificaciones_huella_hash_registros.pdf
    }
}
```

#### 2. XmlBuilder Service

```php
// src/Services/XmlBuilder.php
final class XmlBuilder implements XmlBuilderContract
{
    public function buildRegistrationXml(InvoiceContract $invoice): string
    {
        // Construir XML según XSD oficial
        // Ver: documentacion_verifactu/SuministroLR.xsd.xml
    }
}
```

#### 3. QrGenerator Service

```php
// src/Services/QrGenerator.php
final class QrGenerator implements QrGeneratorContract
{
    public function generate(InvoiceContract $invoice, string $hash): string
    {
        // Generar QR según especificaciones
        // Ver: documentacion_verifactu/DetalleEspecificacTecnCodigoQRfactura.pdf
    }
}
```

#### 4. AeatClient Service

```php
// src/Services/AeatClient.php
final class AeatClient implements AeatClientContract
{
    public function sendRegistration(RegistryContract $registry): AeatResponse
    {
        // Implementar cliente SOAP
        // Ver: documentacion_verifactu/WSDL_servicios_web.xml
    }
}
```

#### 5. CertificateManager Service

```php
// src/Services/CertificateManager.php
final class CertificateManager implements CertificateManagerContract
{
    public function load(string $path, string $password): void
    {
        // Cargar y validar certificado
        // Ver: documentacion_verifactu/EspecTecGenerFirmaElectRfact.pdf
    }
}
```

### Orden Recomendado de Implementación

1. **HashGenerator** (más simple, sin dependencias externas)
2. **XmlBuilder** (depende solo de datos)
3. **CertificateManager** (necesario para firma)
4. **QrGenerator** (necesita hash)
5. **AeatClient** (integra todo)

### Tests para Cada Servicio

Por cada servicio, crear:

```bash
tests/Unit/{Servicio}Test.php        # Tests unitarios
tests/Feature/{Servicio}IntegrationTest.php  # Tests de integración
```

## 🧪 Desarrollo Guiado por Tests (TDD)

### Ejemplo: HashGenerator

```php
// tests/Unit/HashGeneratorTest.php
it('generates a valid SHA-256 hash', function () {
    $invoice = createTestInvoice();
    $generator = new HashGenerator();
    
    $hash = $generator->generate($invoice);
    
    expect($hash)
        ->toBeString()
        ->toHaveLength(64)
        ->toMatch('/^[a-f0-9]{64}$/');
});

it('generates consistent hashes for same invoice', function () {
    $invoice = createTestInvoice();
    $generator = new HashGenerator();
    
    $hash1 = $generator->generate($invoice);
    $hash2 = $generator->generate($invoice);
    
    expect($hash1)->toBe($hash2);
});

it('generates different hashes for different invoices', function () {
    $invoice1 = createTestInvoice(['number' => 'F-001']);
    $invoice2 = createTestInvoice(['number' => 'F-002']);
    $generator = new HashGenerator();
    
    $hash1 = $generator->generate($invoice1);
    $hash2 = $generator->generate($invoice2);
    
    expect($hash1)->not->toBe($hash2);
});
```

## 📚 Recursos Disponibles

### Documentación AEAT

Toda la documentación oficial está en `/documentacion_verifactu/`:

- **Aproximacion-Tecnica.md**: Arquitectura y diseño del paquete
- **Veri-Factu_especificaciones_huella_hash_registros.pdf**: Cálculo de hashes
- **DetalleEspecificacTecnCodigoQRfactura.pdf**: Generación de QR
- **SuministroLR.xsd.xml**: Esquema XSD oficial
- **WSDL_servicios_web.xml**: Definición de servicios SOAP
- **EspecTecGenerFirmaElectRfact.pdf**: Firma electrónica
- **FAQs-Desarrolladores.pdf**: Preguntas frecuentes

### Ejemplos de XML

Ver `/documentacion_verifactu/AnexosEjemplosFirmaRegFact/`:

- `ejemploRegistro.xml`: Ejemplo de XML sin firmar
- `ejemploRegistro-firmado-epes-xades4j.xml`: Ejemplo con firma

## 🔍 Comandos Útiles

```bash
# Desarrollo
composer test              # Ejecutar tests
composer test-coverage     # Tests con cobertura
composer analyse           # PHPStan
composer format            # Laravel Pint

# Ver estructura
tree -L 3 -I 'vendor|node_modules'

# Actualizar dependencias
composer update

# Validar composer.json
composer validate
```

## 🐛 Debugging

### PHPStan

Si encuentras errores de PHPStan:

```bash
# Ver errores
composer analyse

# Crear baseline (usar solo si es necesario)
./vendor/bin/phpstan analyse --generate-baseline
```

### Tests

```bash
# Ejecutar test específico
./vendor/bin/pest tests/Unit/HashGeneratorTest.php

# Ejecutar con debug
./vendor/bin/pest --debug

# Ver cobertura
./vendor/bin/pest --coverage --min=90
```

## 📖 Lectura Recomendada

1. **Primero**: `documentacion_verifactu/Aproximacion-Tecnica.md`
2. **Segundo**: `documentacion_verifactu/FAQs-Desarrolladores.pdf`
3. **Tercero**: Revisar ejemplos XML en `/AnexosEjemplosFirmaRegFact/`

## 💡 Tips de Desarrollo

### 1. Usa Type Hints Estrictos

```php
<?php

declare(strict_types=1);

// Siempre en cada archivo PHP
```

### 2. Documenta Todo

```php
/**
 * Generate SHA-256 hash for an invoice
 * 
 * @param InvoiceContract $invoice The invoice to hash
 * @return string The 64-character hexadecimal hash
 * @throws HashException If hash cannot be generated
 */
public function generate(InvoiceContract $invoice): string
```

### 3. Escribe Tests Primero (TDD)

1. Escribe el test
2. Ve que falla
3. Implementa el código mínimo
4. Ve que pasa
5. Refactoriza

### 4. Commits Semánticos

```bash
git commit -m "feat: add hash generator service"
git commit -m "test: add hash generator tests"
git commit -m "docs: update README with examples"
git commit -m "fix: resolve hash calculation edge case"
git commit -m "refactor: improve xml builder performance"
```

## 🎯 Milestone 1: Servicios Core Funcionando

**Objetivo**: Tener todos los servicios core implementados y testeados

**Checklist**:
- [ ] HashGenerator implementado y testeado (>90% cobertura)
- [ ] XmlBuilder implementado y testeado (>90% cobertura)
- [ ] QrGenerator implementado y testeado (>90% cobertura)
- [ ] CertificateManager implementado y testeado (>90% cobertura)
- [ ] AeatClient implementado y testeado (>90% cobertura)
- [ ] PHPStan nivel 8 sin errores
- [ ] Tests arquitectónicos pasando
- [ ] Documentación actualizada

**Tiempo Estimado**: 2-3 semanas

## 🆘 Ayuda

Si tienes preguntas:

1. Revisa la documentación en `/documentacion_verifactu/`
2. Consulta `.cursor/verifactu-package.md`
3. Revisa `CONTRIBUTING.md`
4. Abre un issue en GitHub (cuando esté público)

## 📞 Contacto

- Email: info@aichadigital.com
- Documentación interna: Ver archivos `.cursor/`

---

**¡Buena suerte con el desarrollo! 🚀**

**Fecha de creación**: 2025-10-11
**Estado**: Fase 1 Completada - Listo para Fase 2

