# Contributing to Lara Verifactu

¡Gracias por considerar contribuir a Lara Verifactu! Apreciamos cualquier tipo de contribución.

## Código de Conducta

Este proyecto y todos los que participan en él se rigen por nuestro Código de Conducta. Al participar, se espera que mantengas este código. Por favor reporta comportamientos inaceptables a info@aichadigital.com.

## ¿Cómo Puedo Contribuir?

### Reportar Bugs

Antes de crear un bug report, por favor verifica que el problema no haya sido reportado previamente. Cuando crees un bug report, incluye tantos detalles como sea posible:

- **Usa un título claro y descriptivo**
- **Describe los pasos exactos para reproducir el problema**
- **Proporciona ejemplos específicos**
- **Describe el comportamiento que observaste y por qué lo consideras un problema**
- **Explica qué comportamiento esperabas ver**
- **Incluye screenshots si es posible**
- **Versión de Laravel, PHP y del paquete**

### Sugerir Mejoras

Las sugerencias de mejora son bienvenidas. Por favor incluye:

- **Usa un título claro y descriptivo**
- **Proporciona una descripción detallada de la mejora sugerida**
- **Explica por qué esta mejora sería útil**
- **Proporciona ejemplos de cómo funcionaría**

### Pull Requests

1. Fork el repositorio
2. Crea una rama desde `main` para tu feature (`git checkout -b feature/AmazingFeature`)
3. Realiza tus cambios siguiendo las guías de estilo
4. Escribe o actualiza tests según sea necesario
5. Asegúrate de que todos los tests pasen
6. Asegúrate de que PHPStan nivel 8 pase sin errores
7. Formatea el código con Laravel Pint
8. Actualiza la documentación según sea necesario
9. Commit tus cambios (`git commit -m 'feat: add some AmazingFeature'`)
10. Push a la rama (`git push origin feature/AmazingFeature`)
11. Abre un Pull Request

## Guías de Estilo

### Mensajes de Commit

Usamos [Conventional Commits](https://www.conventionalcommits.org/):

```
<tipo>(<scope>): <descripción>

[cuerpo opcional]

[footer opcional]
```

Tipos:
- `feat`: Nueva funcionalidad
- `fix`: Corrección de bug
- `docs`: Cambios en documentación
- `style`: Cambios de formato (espacios, puntos y comas, etc.)
- `refactor`: Refactorización de código
- `perf`: Mejoras de rendimiento
- `test`: Añadir o corregir tests
- `chore`: Cambios en proceso de build o herramientas auxiliares

Ejemplos:
```
feat(hash): add support for custom hash algorithms
fix(aeat): resolve certificate validation issue
docs(readme): update installation instructions
```

### Estilo de Código PHP

- Seguir PSR-12
- Usar strict typing en todos los archivos: `declare(strict_types=1);`
- Usar tipos de retorno explícitos
- Documentar todos los métodos públicos con PHPDoc
- Preferir readonly properties cuando sea posible
- Usar named arguments para claridad
- Aplicar principios SOLID

#### Ejemplo de Clase Bien Formateada

```php
<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\LaraVerifactu\Contracts\HashGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;

final class HashGenerator implements HashGeneratorContract
{
    /**
     * Generate SHA-256 hash for an invoice according to AEAT specifications
     */
    public function generate(InvoiceContract $invoice): string
    {
        $data = $this->prepareDataForHash($invoice);
        
        return hash('sha256', $data);
    }
    
    /**
     * Verify if a hash matches an invoice
     */
    public function verify(string $hash, InvoiceContract $invoice): bool
    {
        return hash_equals($hash, $this->generate($invoice));
    }
    
    private function prepareDataForHash(InvoiceContract $invoice): string
    {
        return sprintf(
            '%s|%s|%s|%s',
            $invoice->getIssuerTaxId(),
            $invoice->getInvoiceNumber(),
            $invoice->getIssueDate()->format('d-m-Y'),
            $invoice->getTotalAmount()
        );
    }
}
```

### Testing

- Todos los métodos públicos deben tener tests
- Usar Pest para tests
- Nombrar tests de forma descriptiva
- Usar el enfoque AAA (Arrange, Act, Assert)
- Mockear dependencias externas
- Apuntar a >90% de cobertura

#### Ejemplo de Test

```php
<?php

use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;

it('generates correct hash for invoice', function () {
    // Arrange
    $invoice = Mockery::mock(InvoiceContract::class);
    $invoice->shouldReceive('getIssuerTaxId')->andReturn('B12345678');
    $invoice->shouldReceive('getInvoiceNumber')->andReturn('F-2025-001');
    $invoice->shouldReceive('getIssueDate')->andReturn(now());
    $invoice->shouldReceive('getTotalAmount')->andReturn('121.00');
    
    $generator = new HashGenerator();
    
    // Act
    $hash = $generator->generate($invoice);
    
    // Assert
    expect($hash)
        ->toBeString()
        ->toHaveLength(64);
});
```

### Documentación

- Actualizar README.md si añades nuevas funcionalidades
- Actualizar CHANGELOG.md siguiendo Keep a Changelog
- Añadir PHPDoc a todos los métodos públicos
- Incluir ejemplos de uso cuando sea apropiado
- Documentar excepciones que se pueden lanzar

## Proceso de Revisión

1. Un mantenedor revisará tu PR
2. Pueden solicitar cambios o aclaraciones
3. Una vez aprobado, tu PR será merged
4. Tu contribución aparecerá en el próximo release

## Configuración del Entorno de Desarrollo

```bash
# Clonar el repositorio
git clone https://github.com/aichadigital/lara-verifactu.git
cd lara-verifactu

# Instalar dependencias
composer install

# Ejecutar tests
composer test

# Ejecutar análisis estático
composer analyse

# Formatear código
composer format
```

## Herramientas de Calidad

Este proyecto utiliza:

- **PHPStan** (nivel 8): Análisis estático de código
- **Laravel Pint**: Formateo automático de código
- **Pest**: Framework de testing
- **GitHub Actions**: CI/CD automático

Todas estas herramientas deben pasar antes de que un PR pueda ser merged.

## Licencia

Al contribuir a Lara Verifactu, aceptas que tus contribuciones serán licenciadas bajo la misma licencia MIT del proyecto.

## Preguntas

Si tienes preguntas sobre cómo contribuir, no dudes en:

- Abrir un issue
- Contactarnos en info@aichadigital.com
- Unirte a nuestras discusiones en GitHub

¡Gracias por contribuir! 🎉

