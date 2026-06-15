# Fase 1 — Datos / APIs: pronóstico ENSO oficial en vivo + impactos derivados de fuentes reales

**Fecha:** 2026-06-15
**Estado:** Aprobado (diseño)
**Rama:** `work`
**Proyecto:** suite-oni (Monitor Ambiental y Fenómeno El Niño — Nariño)

## Objetivo

Hacer que el estado actual **y el pronóstico** de ENSO se apoyen en **APIs internacionales reales**, y derivar de fuentes reales los impactos que hoy son semilla, etiquetando con honestidad la procedencia de cada dato.

Esta es la base sobre la que se construyen las fases 2 (gráficos d3plus) y 3 (globo).

## Contexto del estado actual (verificado)

Ya es real (sync por cron, con caché y fallback):
- **ONI observado** y **Niño-3.4 semanal** desde NOAA/CPC (`class-man-sync-oni.php`, archivo ASCII `cpc.ncep.noaa.gov/data/indices/oni.ascii.txt`).
- Alertas **IDEAM** (datos.gov.co), **IOC** nivel del mar (Tumaco), **SIVIGILA** dengue.
- **Open-Meteo** (navegador, CORS): pronóstico 7 d, marino, caudal/GloFAS, aire.

Aún NO es real:
- **Pronóstico oficial de ENSO** (ensamble NOAA/IRI): hoy es **semilla** en caché (`class-man-rest.php` ~770-810). El modelo propio Holt (`class-man-forecast.php`) sí se calcula en vivo, pero no es oficial.
- **Impactos por municipio** (déficit hídrico, % cultivos, focos de calor): **modelados/semilla** en `datos_globo_elnino_narino_2026.json`.

## Alcance de la Fase 1

### 1.1 Pronóstico ENSO oficial en vivo
- **Nueva clase** `includes/sync/class-man-sync-iri.php` que sincroniza la proyección oficial:
  - **Probabilidades ENSO CPC/IRI** por trimestre (El Niño / Neutral / La Niña).
  - **Curva de ensamble de Niño-3.4 / ONI proyectado** (media del plume oficial).
- **Fuente a confirmar en implementación** (riesgo conocido — no son JSON limpios). Candidatos, en orden:
  1. IRI/CPC "ENSO probabilistic forecast" (tabla CPC) — parseo de tabla/CSV.
  2. IRI Data Library (Niño-3.4 forecast plume) si expone CSV.
  3. Fallback: semilla actual (se conserva como respaldo, nunca se rompe).
- **Caché:** transient/opción `man_enso_pronostico_oficial`, TTL 12 h; cron c/12 h (reutiliza el scheduler existente).
- **Resiliencia:** fetch con timeout → si falla, cae a la última caché → si no hay, a la semilla → marca `estado=mantenimiento` en `/estado-apis`.
- **Modelo propio Holt:** se conserva como complemento, NO se elimina.

### 1.2 Impactos derivados de APIs reales
- **Déficit hídrico por municipio (REAL, derivado):** a partir de Open-Meteo (precipitación reciente vs. climatología por coordenadas DIVIPOLA). Índice 0–100 calculado server-side y cacheado. Reemplaza el valor semilla donde haya cobertura.
- **Focos de calor (REAL):** nueva clase `includes/sync/class-man-sync-firms.php` → NASA FIRMS (VIIRS/MODIS active fire) por bounding-box de Nariño; conteo por municipio (point-in-polygon con el GeoJSON existente). Requiere MAP_KEY de FIRMS (gratuito) — se guarda cifrado como las demás claves (`sodium_crypto_secretbox`, patrón existente en `class-man-api-config.php`).
- **% cultivos en riesgo:** sin fuente directa → se mantiene **modelado**, pero recalculado en función del déficit hídrico real (no número fijo).

### 1.3 Estado actual fiel
- El divisor observado/proyectado de la serie ONI queda anclado al **último mes real** sincronizado de NOAA (no fijo en el JSON).
- `[man_estado]` refleja la fase vigente desde el último dato observado real.

### 1.4 Provenance honesto (transversal)
- Convención de procedencia por dato: `real` | `oficial` | `modelado`.
- Se expone en: payload REST (`fuente` por serie/indicador), tooltips de gráficos/mapa, y panel `[man_estado_api]` (última sync + tipo de cada fuente).

## Cambios por archivo (resumen)

| Archivo | Cambio |
|---|---|
| `includes/sync/class-man-sync-iri.php` | **Nuevo** — sync pronóstico oficial ENSO |
| `includes/sync/class-man-sync-firms.php` | **Nuevo** — sync focos de calor NASA FIRMS |
| `includes/sync/class-man-sync.php` | Registrar las dos nuevas fuentes en el scheduler/orquestador |
| `includes/analysis/class-man-forecast.php` | Aceptar `fuente=oficial\|modelo\|ambos`; integrar serie oficial |
| `includes/analysis/class-man-risk.php` | Déficit hídrico derivado real → riesgo municipal |
| `includes/data/class-man-municipios.php` | Helper point-in-polygon (focos por municipio) si no existe |
| `includes/class-man-rest.php` | `/pronostico/oni` y `/prediccion` aceptan `fuente`; `/estado-apis` reporta procedencia; `fuente` en payloads |
| `includes/admin/class-man-api-config.php` | Campo de clave FIRMS (cifrada) + toggles de las nuevas fuentes |
| `includes/class-man-activator.php` | Programar crons de las nuevas fuentes; sembrar config por defecto |

## Contrato / interfaces

- **REST sin cambios incompatibles:** los endpoints existentes mantienen su forma; `fuente` es un parámetro **opcional** (default = comportamiento actual). Aditivo, no rompe consumidores.
- **`man-core.js`** (cliente JS) gana acceso a `fuente` en las respuestas para etiquetar en UI (se consume en Fase 2).

## Errores y resiliencia

- Toda fuente nueva sigue el patrón existente: caché → semilla → mensaje de mantenimiento. Ninguna falla de red rompe el render.
- Timeouts cortos y `sslverify` solo flexibilizado para portales estatales CO (patrón de seguridad existente). FIRMS/IRI usan verificación normal.

## Pruebas / verificación

- **PHP lint** (`php -l`) de cada archivo nuevo/modificado.
- **Smoke REST:** `/pronostico/oni?fuente=oficial`, `/prediccion?fuente=ambos`, `/estado-apis`, `/abierto/oni?dominio=pronostico` devuelven JSON válido con `fuente`.
- **Fallback:** simular fallo de fetch (URL inválida temporal) y confirmar que cae a semilla sin error fatal.
- Como no hay WordPress de pruebas local, la verificación de integración real se documenta como checklist para ejecutar en el entorno Plesk (post-deploy).

## Fuera de alcance (Fase 1)

- Migración de gráficos a d3plus y nuevos shortcodes sectoriales → **Fase 2**.
- Cinemática del globo → **Fase 3**.
- Pulido visual/identidad → **Fase 4**.

## Riesgos

1. **Fuente oficial IRI/CPC sin JSON limpio** → mitigado con parser robusto + fallback a semilla; se confirma el endpoint exacto antes de cablear.
2. **Clave FIRMS** requerida → si no se configura, la capa de focos cae a modelado etiquetado (no bloquea).
3. **Cobertura Open-Meteo histórica** para climatología del déficit → usar normales/medias disponibles; documentar el método.
