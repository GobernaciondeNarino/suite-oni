# Fase 2 — Gráficos d3plus: vistas sectoriales (uno por gráfico)

**Fecha:** 2026-06-15 · **Estado:** Aprobado (diseño) · **Rama:** `work`

## Objetivo
Que haya un gráfico d3plus por cada elemento del mockup (Ambiental / Agrícola /
Salud / Recursos), maquetable suelto, reutilizando el **motor genérico ya probado**
(`[man_grafico view="…"]` → `/render` → `renderer.js` → d3plus). Cada vista nueva
es PHP puro en `MAN_Views` (metadatos + filas), sin JS nuevo: se renderiza por el
mismo camino que las vistas existentes (`oni_serie`, `prob_fase`, …).

## Estrategia (incremento 1)
Añadir vistas al registro. Dos clases de fuente:

**Reales (datos de Fase 1):**
- `deficit_municipios` — barras, top municipios por déficit hídrico REAL (`deficit_municipios` cache; fallback a semilla del mes).
- `focos_municipios` — barras, focos de calor REALES por municipio (`focos_calor` cache).

**Series temporales (semilla `datos_globo…json`, modelado etiquetado):**
- `deficit_serie` — línea, déficit hídrico mensual (0–100).
- `precip_caudal` — multi-línea (long-form), precipitación mm + nivel de caudal %.
- `focos_serie` — barras/área, focos de calor mensuales.
- `cultivos_riesgo` — línea, % área de cultivos en riesgo.
- `acueductos` — barras, nº municipios en racionamiento.
- `hidro_reduccion` — línea, reducción hidroeléctrica %.

Cada una queda disponible como **un shortcode por gráfico**:
`[man_grafico view="deficit_serie" type="line"]`, etc. (granularidad pedida).

## Contrato (sin cambios incompatibles)
- Las vistas nuevas siguen el mismo shape que las existentes: `dimensions`,
  `measures`, filas long-form donde haya varias series (patrón de `oni_serie`:
  `{mes, serie, valor}` con `dimensions=[mes,serie]`, `measures=[valor]`).
- `compatibles()` por categoría ya restringe los tipos válidos. Las temporales
  usan `temporal`; las de municipio, `categorical`.
- Procedencia: el `analisis.descriptivo` indica si la serie es real o modelada.

## Fuera de alcance (incrementos siguientes)
- Migrar `[man_prediccion]` (D3 puro) a d3plus con banda de incertidumbre →
  requiere verificación en navegador (d3plus LinePlot no trae banda nativa).
- Shortcode `[man_prevencion]` (recomendaciones por nivel de alerta).
- Alias amables (`[man_deficit]`…) sobre el motor genérico.
- Estos quedan para Fase 2b, tras verificar el incremento 1 en navegador.

## Pruebas
- No hay PHP/WordPress local → verificación en el entorno: cada
  `[man_grafico view="…"]` renderiza un gráfico d3plus con datos; `/render?view=…`
  devuelve `data` no vacío. Documentado en el checklist de Fase 2.
