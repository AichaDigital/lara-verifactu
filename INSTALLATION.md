# 📦 Installation Guide - Lara Verifactu

> **⚠️ IMPORTANTE**: Este paquete está en desarrollo activo y **NO está disponible en Packagist**. Solo se puede instalar desde el repositorio local.

---

## 📋 Requisitos

- PHP 8.2 o superior
- Laravel 11.0 o 12.0
- Composer
- OpenSSL extension
- SOAP extension
- Certificado digital válido de la FNMT/AEAT (.p12 o .pfx)

## 🚀 Instalación Local para Desarrollo

### Opción 1: Path Repository (Recomendada)

Esta es la forma recomendada de instalar el paquete durante el desarrollo. Composer creará un symlink automáticamente.

#### Paso 1: Clonar el Repositorio

```bash
# Crea un directorio para tus paquetes si no existe
mkdir -p ~/development/packages
cd ~/development/packages

# Clona el repositorio
git clone https://github.com/AichaDigital/lara-verifactu.git
cd lara-verifactu

# Instala las dependencias del paquete
composer install
```

#### Paso 2: Configurar tu Proyecto Laravel

En el `composer.json` de tu proyecto Laravel, añade el repositorio local:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../packages/lara-verifactu",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "php": "^8.2",
        "laravel/framework": "^11.0|^12.0",
        "aichadigital/lara-verifactu": "@dev"
    }
}
```

**Nota**: Ajusta la ruta `url` según donde hayas clonado el paquete:
- Si tu proyecto está en `~/projects/mi-app` y el paquete en `~/development/packages/lara-verifactu`, usa: `"../development/packages/lara-verifactu"`
- Si están al mismo nivel: `"../lara-verifactu"`

#### Paso 3: Instalar el Paquete

```bash
cd ~/projects/mi-app
composer update aichadigital/lara-verifactu
```

Composer creará un symlink en `vendor/aichadigital/lara-verifactu` apuntando a tu repositorio local.

#### Paso 4: Publicar Configuración

```bash
php artisan verifactu:install
```

Este comando:
- ✅ Publica el archivo de configuración `config/verifactu.php`
- ✅ Publica las migraciones de base de datos
- ✅ Pregunta si deseas ejecutar las migraciones

---

### Opción 2: Symlink Manual

Si prefieres crear el symlink manualmente:

```bash
# En tu proyecto Laravel
cd vendor
mkdir -p aichadigital

# Crea el symlink (ajusta la ruta según tu estructura)
ln -s ~/development/packages/lara-verifactu aichadigital/lara-verifactu

# Vuelve al root y regenera autoload
cd ..
composer dump-autoload
```

Luego añade el paquete manualmente en `composer.json`:

```json
{
    "require": {
        "aichadigital/lara-verifactu": "@dev"
    }
}
```

---

## ⚙️ Configuración

### 1. Configurar Variables de Entorno

Añade las siguientes variables en tu `.env`:

```env
# Environment: 'sandbox' para pruebas, 'production' para real
VERIFACTU_ENVIRONMENT=sandbox

# Ruta al certificado digital (.p12 o .pfx)
VERIFACTU_CERT_PATH=./certificates/tu_certificado.p12

# Contraseña del certificado
VERIFACTU_CERT_PASSWORD=tu_password_secreto

# Datos de tu empresa
VERIFACTU_COMPANY_NAME="Tu Empresa SL"
VERIFACTU_COMPANY_TAX_ID="B12345678"

# Timeout para conexiones AEAT (segundos)
VERIFACTU_TIMEOUT=30

# Verificar SSL (true en producción, false solo para debug)
VERIFACTU_VERIFY_SSL=true
```

### 2. Ejecutar Migraciones

Si no las ejecutaste durante la instalación:

```bash
php artisan migrate
```

Esto creará las tablas:
- `verifactu_invoices`
- `verifactu_registries`
- `verifactu_invoice_breakdowns`

### 3. Obtener Certificado Digital

Si no tienes un certificado digital:

1. **Para pruebas en Sandbox**: Puedes usar tu certificado personal de la FNMT
2. **Para producción**: Necesitas un certificado de empresa

**Exportar certificado del sistema:**

```bash
# macOS: Desde Acceso a Llaveros
# 1. Abre "Acceso a Llaveros"
# 2. Busca tu certificado FNMT
# 3. Click derecho > "Exportar..."
# 4. Formato: "Intercambio de información personal (.p12)"
# 5. Guárdalo en ./certificates/

# Linux: Si tienes el certificado en el navegador
# Firefox: Preferencias > Privacidad y Seguridad > Certificados > Ver certificados > Hacer copia de seguridad
```

**Guardar el certificado:**

```bash
# Crear directorio para certificados (ya está en .gitignore)
mkdir -p certificates

# Copiar tu certificado
cp ~/Downloads/tu_certificado.p12 certificates/

# Asegurar permisos correctos
chmod 600 certificates/tu_certificado.p12
```

---

## ✅ Verificar Instalación

### Probar Certificado y Conexión

```bash
# Ver información del certificado
php artisan verifactu:test-connection --cert-info

# Probar conexión completa con AEAT
php artisan verifactu:test-connection
```

**Salida esperada:**

```
🔐 Testing AEAT Connection & Certificate

📋 Checking configuration...
   ✓ Environment: sandbox
   ✓ Certificate: ./certificates/tu_certificado.p12

🔑 Testing certificate...
   ✓ Certificate loaded successfully
   • Subject: Tu Nombre
   • Issuer:  FNMT-RCM
   • Valid From: 2023-XX-XX
   • Valid To:   2025-XX-XX

🌐 Testing AEAT SOAP connection...
   ✓ WSDL: https://prewww2.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion?wsdl
   ✓ Endpoint: https://prewww2.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion
   ✓ SOAP client created successfully
   ✓ Available SOAP methods:
     • RegFactuSistemaFacturacion
     • ConsultaFactuSistemaFacturacion

✅ All tests passed successfully!
```

### Verificar Comandos Disponibles

```bash
php artisan list verifactu
```

Deberías ver:

```
verifactu
  verifactu:install                Install the Lara Verifactu package
  verifactu:register               Register invoice(s) with AEAT
  verifactu:retry-failed           Retry failed AEAT submissions
  verifactu:status                 Show Verifactu system status
  verifactu:test-connection        Test AEAT connection and certificate
  verifactu:verify-blockchain      Verify Verifactu blockchain integrity
```

---

## 🔧 Desarrollo del Paquete

Si vas a contribuir o modificar el paquete:

### Instalación de Dependencias de Desarrollo

```bash
cd ~/development/packages/lara-verifactu
composer install
```

### Ejecutar Tests

```bash
# Tests completos
composer test

# Tests con cobertura
composer test:coverage

# Solo unit tests
vendor/bin/pest tests/Unit

# Solo feature tests
vendor/bin/pest tests/Feature
```

### Análisis de Código

```bash
# PHPStan (nivel 8)
composer analyse

# Laravel Pint (formateo)
composer format

# PHP Insights
composer insights
```

---

## 🔄 Actualizar el Paquete

Cuando hay cambios en el repositorio:

```bash
# Ir al directorio del paquete
cd ~/development/packages/lara-verifactu

# Pull de los últimos cambios
git pull origin main

# Actualizar dependencias
composer install

# Volver a tu proyecto
cd ~/projects/mi-app

# Limpiar caché de Composer
composer dump-autoload

# Limpiar caché de Laravel
php artisan optimize:clear
```

---

## 🐛 Troubleshooting

### Error: "Class not found"

```bash
# Regenerar autoload
composer dump-autoload

# Limpiar caché de Laravel
php artisan optimize:clear
```

### Error: "Certificate not found"

Verifica que:
1. El archivo existe en la ruta especificada
2. Los permisos son correctos (600)
3. La ruta en `.env` es correcta (relativa al root del proyecto)

```bash
# Verificar certificado
ls -la certificates/
php artisan verifactu:test-connection --cert-info
```

### Error: "SOAP connection failed"

1. Verifica que la extensión SOAP esté instalada:
   ```bash
   php -m | grep soap
   ```

2. Si no está, instálala:
   ```bash
   # Ubuntu/Debian
   sudo apt-get install php8.2-soap
   
   # macOS (Homebrew)
   brew install php@8.2
   ```

3. Reinicia el servidor:
   ```bash
   php artisan serve
   ```

### Symlink no funciona

Si el symlink no se crea automáticamente:

1. Verifica que el path en `composer.json` sea correcto
2. Prueba con ruta absoluta:
   ```json
   {
       "url": "/Users/tu-usuario/development/packages/lara-verifactu"
   }
   ```
3. O usa symlink manual (Opción 2)

---

## 📚 Siguientes Pasos

Una vez instalado:

1. 📖 Lee la [documentación de uso](README.md#uso-rápido)
2. 🧪 Prueba los [comandos básicos](README.md#comandos-artisan)
3. 💡 Revisa los [ejemplos de código](README.md#uso-programático)
4. 🤝 Considera [contribuir](CONTRIBUTING.md) al proyecto

---

## 💬 Soporte

¿Problemas con la instalación?

- 🐛 [Reporta un issue](https://github.com/AichaDigital/lara-verifactu/issues)
- 💬 [Inicia una discusión](https://github.com/AichaDigital/lara-verifactu/discussions)
- 📧 Email: support@aichadigital.com

---

<p align="center">
  <strong>¿Instalación exitosa? 🎉</strong><br>
  <em>Ahora estás listo para integrar Verifactu en tu aplicación</em>
</p>

