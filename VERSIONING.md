# 📦 Versioning Strategy - Lara Verifactu

## 🎯 Estrategia de Versionado

Este documento describe la estrategia de versionado y compatibilidad backward del paquete Lara Verifactu.

---

## 📋 Principio General

**El paquete se desarrolla para la última versión LTS de Laravel disponible.**

- ✅ **Rama `main`**: Siempre soporta la última versión LTS de Laravel
- ✅ **Branches de compatibilidad**: Se crean solo cuando es necesario mantener soporte para versiones anteriores

---

## 🔄 Estrategia de Compatibilidad Backward

### Escenario Normal: Compatibilidad Mantenida

Cuando Laravel lanza una nueva versión LTS (ej: Laravel 13), si el paquete es compatible sin cambios significativos:

1. **Actualizar `main`** para soportar la nueva versión
2. **Mantener compatibilidad** con la versión anterior mediante tags de versión
3. **No crear branches** adicionales si no es necesario

**Ejemplo:**
```
main (Laravel 13)
  ├── v2.0.0 (Laravel 13)
  ├── v1.5.0 (Laravel 12) ← Tag para Laravel 12
  └── v1.0.0 (Laravel 12)
```

### Escenario de Incompatibilidad: Branch de Compatibilidad

Si surge una incompatibilidad que requiere cambios significativos o no se puede mantener compatibilidad:

1. **Marcar la última versión compatible** con un tag (ej: `v1.5.0`)
2. **Crear una branch** específica para la versión antigua (ej: `laravel-12`)
3. **Continuar desarrollo** en `main` para la nueva versión de Laravel

**Ejemplo:**
```
main (Laravel 13)
  ├── v2.0.0 (Laravel 13)
  └── ...

laravel-12 (Laravel 12)
  ├── v1.5.1 (bugfixes solo para Laravel 12)
  ├── v1.5.0 ← Última versión antes de la incompatibilidad
  └── ...
```

---

## 🗄️ Política de versionado de esquema

El esquema de base de datos publicado también es contrato público:

- A partir de `v1.0.0` estable, una migración ya publicada se considera inmutable.
  Cualquier cambio estructural se realiza mediante una migración nueva y append-only.
- Cambios aditivos (nuevas tablas/columnas/índices, nuevos valores de enumeración no
  retroincompatibles): **MINOR**.
- Cambios de ruptura de esquema (eliminación o cambio de tipo, restricciones que invaliden
  datos persistentes existentes, renombre de estructura con pérdida de compatibilidad): **MAJOR**.
- Esta política aplica tanto a la semántica fiscal materializada en la base como a su
  persistencia física.
- En este paquete no hay migraciones `.stub`; por ello no aplica aún una
  consistencia `*.php`/`*.stub`.
- En paralelo a la política de esquema, en `1.0.0` el contrato público (incluidos
  los contratos de `src/Contracts/*`) pasa a tratarse como API estable. Cambios
  incompatibles en esas firmas se publican como MAJOR.

---

## 📌 Convenciones de Naming

### Branches

- `main`: Rama principal, siempre para la última versión LTS de Laravel
- `laravel-{version}`: Branch de compatibilidad para una versión específica (ej: `laravel-12`)

### Tags

- `v{major}.{minor}.{patch}`: Versiones semánticas estándar
- Los tags indican la versión de Laravel soportada en el CHANGELOG

---

## 🔧 Proceso de Actualización

### Cuando Laravel Lanza Nueva Versión LTS

1. **Evaluar compatibilidad**
   ```bash
   # Actualizar dependencias temporalmente
   composer require "laravel/framework:^13.0" --dev --no-update
   composer update --prefer-stable
   
   # Ejecutar tests
   composer test
   ```

2. **Si es compatible:**
   - Actualizar `composer.json` y workflow
   - Actualizar documentación
   - Crear tag de versión para la versión anterior
   - Continuar en `main`

3. **Si NO es compatible:**
   - Crear tag de la última versión compatible
   - Crear branch `laravel-{version-anterior}`
   - Actualizar `main` para nueva versión
   - Documentar breaking changes

---

## 📝 Ejemplo Práctico: Laravel 12 → Laravel 13

### Situación Actual (2025)

- **Rama `main`**: Laravel 12
- **Versión actual**: v0.1.0-alpha

### Cuando Laravel 13 Sea Lanzado

#### Opción A: Compatible sin Cambios

```bash
# 1. Actualizar dependencias
composer require "laravel/framework:^13.0" --dev --no-update
composer update --prefer-stable

# 2. Ejecutar tests
composer test

# 3. Si pasan, actualizar composer.json
# 4. Actualizar workflow de GitHub Actions
# 5. Actualizar documentación
# 6. Crear tag para Laravel 12
git tag v0.1.0-laravel12
git push origin v0.1.0-laravel12
```

#### Opción B: Incompatible, Requiere Cambios

```bash
# 1. Crear tag de la última versión compatible con Laravel 12
git tag v0.1.0-laravel12
git push origin v0.1.0-laravel12

# 2. Crear branch de compatibilidad
git checkout -b laravel-12
git push origin laravel-12

# 3. Volver a main y actualizar para Laravel 13
git checkout main
# ... hacer cambios necesarios ...
# ... actualizar composer.json, workflows, docs ...

# 4. Crear nueva versión para Laravel 13
git tag v0.2.0-laravel13
git push origin v0.2.0-laravel13
```

---

## 🏷️ Tags y Releases

### Estructura de Tags

- `v{major}.{minor}.{patch}`: Versión principal
- `v{major}.{minor}.{patch}-laravel{version}`: Versión específica para una versión de Laravel (opcional, solo si hay branches separadas)

### Releases en GitHub

Cada release debe indicar claramente:
- ✅ Versión de Laravel soportada
- ✅ Versión mínima de PHP requerida
- ✅ Breaking changes (si los hay)
- ✅ Changelog completo

---

## 📚 Documentación

### Actualizar en Cada Cambio

1. **README.md**: Requisitos técnicos
2. **CHANGELOG.md**: Notas de versión
3. **composer.json**: Constraints de dependencias
4. **.github/workflows/run-tests.yml**: Matriz de tests

---

## ✅ Checklist de Actualización

Cuando se actualiza la versión de Laravel soportada:

- [ ] Actualizar `composer.json` (`illuminate/contracts`)
- [ ] Actualizar `.github/workflows/run-tests.yml`
- [ ] Actualizar `README.md` (requisitos técnicos)
- [ ] Actualizar `CHANGELOG.md`
- [ ] Ejecutar tests localmente
- [ ] Verificar que CI pasa
- [ ] Crear tag si corresponde
- [ ] Crear branch de compatibilidad si es necesario
- [ ] Actualizar este documento si cambia la estrategia

---

## 🔮 Futuro: Laravel 13

Cuando Laravel 13 sea lanzado:

1. **Evaluar compatibilidad** con el código actual
2. **Decidir**: ¿Mantener compatibilidad o crear branch?
3. **Seguir el proceso** documentado arriba
4. **Actualizar este documento** con la decisión tomada

---

## 📖 Referencias

- [Semantic Versioning](https://semver.org/)
- [Laravel Release Cycle](https://laravel.com/docs/releases)
- [Git Flow](https://nvie.com/posts/a-successful-git-branching-model/)

---

**Última actualización**: 27 de junio de 2026  
**Versión de Laravel actual**: 12.x  
**Estrategia**: Solo Laravel 12+ hasta que Laravel 13 requiera cambios incompatibles
