# Requisitos para Fase 7: API Integration

## 🎯 Objetivo Fase 7

Integrar el cliente SOAP real con la plataforma de pruebas de AEAT para enviar registros reales.

---

## ✅ Lo Que YA Tenemos (Fase 1-6)

- ✅ Arquitectura completa con mocks
- ✅ HashGenerator (SHA-256 según AEAT)
- ✅ XmlBuilder (estructura correcta)
- ✅ QrGenerator (códigos QR)
- ✅ Models, Commands, Jobs, Events
- ✅ 120 tests passing

---

## 📋 Lo Que SE NECESITA para Fase 7

### **1. Certificado Digital de Pruebas** 🔐

**¿Qué es?**
Un certificado X.509 (formato .pfx o .p12) para autenticarte con AEAT.

**¿Dónde conseguirlo?**
- **Portal de Pruebas AEAT**: https://preportal.aeat.es/
- Necesitas crear una cuenta en el portal de pruebas
- Solicitar certificado de prueba para "Sistemas de Facturación"

**Tipos de certificado necesarios:**
1. **Certificado de Sello Electrónico** (recomendado)
   - Para sistemas automáticos
   - No requiere PIN cada vez
   
2. **Certificado de Representante** (alternativa)
   - Para pruebas manuales
   - Requiere PIN

**Formato esperado:**
```bash
/path/to/certificate.pfx
# Con password para descifrarlo
```

---

### **2. Acceso a Plataforma de Pruebas** 🌐

**URLs de Pruebas:**

```php
// WSDL de pruebas
https://prewww2.aeat.es/static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SistemaFacturacion.wsdl

// Endpoint SOAP de pruebas  
https://prewww2.aeat.es/wlpl/TIKE-CONT/SistemaFacturacion

// Portal web de pruebas
https://preportal.aeat.es/
```

**Entornos disponibles:**
- **Pruebas (PRE)**: Para desarrollo y testing
- **Producción**: Solo cuando esté todo validado

---

### **3. Datos de Empresa de Prueba** 🏢

Para las pruebas necesitas:

```env
# NIF/CIF de empresa de prueba
VERIFACTU_COMPANY_TAX_ID=B99999999

# Nombre de empresa
VERIFACTU_COMPANY_NAME="Empresa Pruebas SL"

# Datos adicionales
VERIFACTU_SOFTWARE_NAME="Lara Verifactu"
VERIFACTU_SOFTWARE_VERSION="0.2.0"
VERIFACTU_SOFTWARE_NIF=B12345678
```

**Nota**: AEAT proporciona NIFs ficticios para pruebas en el portal.

---

### **4. Configuración Necesaria** ⚙️

```env
# Environment
VERIFACTU_ENVIRONMENT=sandbox  # sandbox o production

# Certificate
VERIFACTU_CERT_PATH=/path/to/test-certificate.pfx
VERIFACTU_CERT_PASSWORD=your-certificate-password

# AEAT Endpoints (ya están en config)
VERIFACTU_SANDBOX_ENDPOINT=https://prewww2.aeat.es/wlpl/TIKE-CONT/SistemaFacturacion
VERIFACTU_PRODUCTION_ENDPOINT=https://www2.aeat.es/wlpl/TIKE-CONT/SistemaFacturacion

# Timeouts
VERIFACTU_TIMEOUT=30
VERIFACTU_VERIFY_SSL=true
```

---

## 🔧 Pasos para Implementar Fase 7

### **Paso 1: Obtener Certificado de Prueba**

1. Ir a https://preportal.aeat.es/
2. Crear cuenta de pruebas
3. Solicitar certificado para "Sistema de Facturación Verifactu"
4. Descargar certificado .pfx
5. Guardar en ubicación segura (fuera del repo)

### **Paso 2: Actualizar AeatClient**

Cambiar de mock a cliente SOAP real:

```php
// src/Services/AeatClient.php

private function initializeSoapClient(): void
{
    $wsdl = config('verifactu.aeat.wsdl');
    
    $options = [
        'location' => $this->endpoint,
        'soap_version' => SOAP_1_1,
        'exceptions' => true,
        'trace' => true,
        'connection_timeout' => $this->timeout,
        'cache_wsdl' => WSDL_CACHE_NONE, // En pruebas
        
        // Certificate authentication
        'local_cert' => $certificatePath,
        'passphrase' => $certificatePassword,
        
        // SSL verification
        'stream_context' => stream_context_create([
            'ssl' => [
                'verify_peer' => $this->verifySSL,
                'verify_peer_name' => $this->verifySSL,
                'allow_self_signed' => !$this->verifySSL,
            ],
        ]),
    ];
    
    $this->client = new \SoapClient($wsdl, $options);
}
```

### **Paso 3: Implementar Firma XAdES**

La AEAT requiere firma electrónica XAdES-EPES en el XML.

**Opciones:**
1. Usar librería PHP existente (ej: `robrichards/xmlseclibs`)
2. Llamar comando externo (ej: `xmlsec1`)
3. Servicio externo de firmado

**Recomendación**: `robrichards/xmlseclibs`

```bash
composer require robrichards/xmlseclibs
```

### **Paso 4: Validar contra XSD**

```php
// src/Services/XmlBuilder.php

public function validate(string $xml): bool
{
    $xsdPath = resource_path('verifactu/SuministroLR.xsd');
    
    $dom = new \DOMDocument();
    $dom->loadXML($xml);
    
    return $dom->schemaValidate($xsdPath);
}
```

---

## 🧪 Testing en Sandbox

### **Proceso Recomendado:**

1. **Configurar entorno de pruebas**
   ```bash
   cp .env.example .env.testing
   # Configurar con credenciales de prueba
   ```

2. **Crear facturas de prueba**
   - Usar NIFs ficticios de AEAT
   - Importes pequeños
   - Varios tipos de factura (F1, F2, etc.)

3. **Enviar a sandbox**
   ```bash
   php artisan verifactu:register 1 # Primera factura de prueba
   ```

4. **Verificar respuestas AEAT**
   - Revisar logs
   - Comprobar CSV de confirmación
   - Validar QR en portal AEAT

---

## ❓ Preguntas Frecuentes

### **¿Necesito un NIF real de empresa?**
❌ No. En el entorno de pruebas puedes usar NIFs ficticios que proporciona AEAT.

### **¿El certificado de prueba es gratuito?**
✅ Sí. Los certificados de prueba del portal PRE son gratuitos.

### **¿Puedo probar sin certificado?**
❌ No. AEAT requiere autenticación mediante certificado digital incluso en pruebas.

### **¿Los datos de prueba afectan a producción?**
❌ No. El entorno de pruebas (PRE) está completamente separado de producción.

### **¿Cuánto tiempo tarda obtener el certificado?**
⏱️ Inmediato en el portal de pruebas. Solo registro y descarga.

---

## 📚 Documentación AEAT Necesaria

Ya disponible en `/documentacion_verifactu/`:

- ✅ `Veri-Factu_Descripcion_SWeb.pdf` - Especificación servicios web
- ✅ `WSDL_servicios_web.xml` - Definición WSDL
- ✅ `SuministroLR.xsd.xml` - Schema XSD
- ✅ `EspecTecGenerFirmaElectRfact.pdf` - Firma electrónica
- ✅ `FAQs-Desarrolladores.pdf` - Preguntas frecuentes

---

## 🚀 Próximos Pasos

### **Inmediatos (antes de Fase 7):**

1. ✅ Obtener certificado de prueba del portal AEAT
2. ✅ Configurar acceso al sandbox
3. ✅ Preparar empresa de prueba (NIF ficticio)

### **Durante Fase 7:**

1. Actualizar `AeatClient` con SOAP real
2. Implementar firma XAdES
3. Validación XSD
4. Tests contra sandbox
5. Manejo de errores AEAT reales
6. Logging mejorado

---

## 📞 Recursos de Ayuda

- **Portal Pruebas**: https://preportal.aeat.es/
- **Documentación**: https://sede.agenciatributaria.gob.es/
- **GitHub Examples**: 
  - https://github.com/josemmo/Verifactu-PHP
  - https://github.com/squareetlabs/LaravelVerifactu

---

**¿Estás listo para obtener el certificado y empezar Fase 7?** 🎯

