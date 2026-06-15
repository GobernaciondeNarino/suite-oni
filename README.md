# Monitor Ambiental y Fenómeno El Niño — Nariño

Plugin de WordPress que comunica a la ciudadanía el estado del fenómeno El Niño / La Niña (ENSO) y las condiciones ambientales de los 64 municipios de Nariño. Front **minimalista** (sin chrome propio, transparente) y configurable, con globo 3D cinematográfico, mapas por municipio, pronóstico en vivo y **datos abiertos** consumibles por API.

> **Gobernación de Nariño · Secretaría TIC, Innovación y Gobierno Abierto**
> Apoyo al Plan de Contingencia ante El Niño (Ley 1523 de 2012).

---

## Instalación (Plesk / WordPress)

1. Copia la carpeta `monitor-ambiental-narino/` a `wp-content/plugins/`.
2. Actívalo desde **Plugins → Monitor Ambiental**.
3. Al activar se crean las tablas `wp_man_cache` y `wp_man_audit`, se siembra la
   configuración por defecto y se agenda el cron de sincronización (cada 12 h).
4. Ve a **Monitor Ambiental → Fuentes** para revisar/ajustar las APIs y pulsa
   **Sincronizar ahora** en NOAA ONI para traer el índice oficial.

Sin proceso de build: D3, D3plus, Leaflet, Three.js y Anime.js se cargan por CDN.

> **¿Dónde copio los shortcodes?** En **Monitor Ambiental → Elementos** tienes el
> catálogo completo de componentes con su descripción, atributos y un botón
> **Copiar** para pegarlos en cualquier página, entrada o widget (incluido el
> bloque *Shortcode* o el widget HTML de Elementor).

---

## Shortcodes

| Shortcode | Qué muestra | Atributos |
|-----------|-------------|-----------|
| `[man_estado]` | Semáforo ENSO + condiciones (gauge D3 + texto) | `municipio`, `compacto` |
| `[man_pronostico]` | Pronóstico 7–16 días (Open-Meteo en vivo) | `municipio`, `dias` |
| `[man_mapa]` | Coroplético de los 64 municipios + panel al clic | `variable`, `mes` |
| `[man_grafico]` | **Tarjeta de gráfico D3plus con barra de herramientas** (Detalle, Compartir, Datos, Imagen PNG, Descarga JSON y *Cambiar tipo en vivo*) + modales y temas | `view`, `type`, `theme`, `actions`, `legend`, `toolbar`, `alto` |
| `[man_estadisticas]` | **Gráficos estadísticos D3plus** (ONI, probabilidad de fase, riesgo por subregión/municipios) — ahora sobre el **motor interactivo** (tooltip, ejes, leyenda, barra de herramientas) | `tipo`, `hasta`, `mes`, `alto` |
| `[man_animacion]` | **Animación explicativa (Anime.js)** del mecanismo ENSO: alisios, piscina cálida, termoclina y lluvias; compara Neutral/El Niño/La Niña | `estado`, `autoplay` |
| `[man_globo]` | Globo 3D cinematográfico (Three.js) | `calidad`, `autorotar` |
| `[man_timeline]` | Slider de meses ONI que controla el globo | `inicio`, `fin` |
| `[man_prediccion]` | **Predicción del ONI hasta feb-2027** (línea + banda de incertidumbre + umbrales de fase + probabilidad por trimestre + texto predictivo). Modelo propio del plugin contrastado con el ensamble NOAA/IRI | `hasta`, `modelo`, `probabilidad` |
| `[man_datos]` | Botón de datos abiertos (JSON/CSV/Ver API) | `recurso`, `municipio`, `mes`, `texto` |
| `[man_historico]` | Episodios ENSO 2015–2024 (barras ONI pico, **interactivo vía el motor D3plus**: tooltip, leyenda, cambiar tipo) | `alto`, `theme` |
| `[man_mar]` | Oleaje Pacífico (Open-Meteo Marine) + nivel del mar (IOC) | `estacion` |
| `[man_salud]` | Dengue sensible al clima (SIVIGILA) | `evento`, `anio` |
| `[man_hidrico]` | Caudal de ríos (GloFAS) + humedad de suelo | `municipio` |
| `[man_estado_api]` | Panel público de salud de las APIs | — |

`municipio` admite código DIVIPOLA (`52001`) o nombre, o `departamento` para el agregado.

**Ejemplos**
```
[man_estado municipio="52835"]
[man_pronostico municipio="52001" dias="14"]
[man_mapa variable="riesgo" mes="2026-10"]
[man_globo calidad="alta"] [man_timeline]
[man_animacion estado="el_nino"]
[man_estadisticas tipo="oni" hasta="2027-02"]
[man_estadisticas tipo="probabilidad"] [man_estadisticas tipo="riesgo"]
[man_prediccion hasta="2027-02"]
[man_prediccion hasta="2027-02" modelo="no" probabilidad="si"]
[man_datos recurso="municipios" texto="Descarga el riesgo por municipio"]
[man_datos recurso="prediccion" texto="Descarga la predicción del ONI"]
```

### Motor de gráficos D3plus (`[man_grafico]`)
Motor genérico de **3 capas** (ver el archivo [`skill`](skill)): el shortcode emite solo un `<figure>` con `data-*` (cacheable, sin datos en el HTML) → `grafico.js` pide `/wp-json/man/v1/render?view=…&type=…` → `renderer.js` elige la clase D3plus y dibuja. Trae barra de herramientas (Detalle · Compartir · Datos · Imagen PNG · Descarga JSON · **Cambiar tipo en vivo**), modales, temas claro/oscuro y tokens `--man-g-*`.

**Vistas disponibles** (`view`): `oni_serie`, `prob_fase`, `riesgo_subregion`, `riesgo_municipios`, `episodios`.
**Tipos** (`type`): `bar`, `stacked_bar`, `line`, `area`, `stacked_area`, `pie`, `donut`, `treemap`, `box_whisker` (se restringe a los compatibles con la vista).
```
[man_grafico view="oni_serie" type="line"]
[man_grafico view="riesgo_subregion" type="treemap" theme="oscuro"]
[man_grafico view="prob_fase" type="stacked_bar" actions="datos,imagen,cambiar"]
```

### Componentes composables (enlazados por `grupo`)
Para maquetar a la medida, separa el **gráfico**, los **filtros** y el **panel de
detalles** en shortcodes distintos que se sincronizan por un id de `grupo` (bus
de eventos `window.MANGrupo`). Un filtro cambia la vista/tipo/mes y el gráfico se
re-renderiza; el panel muestra los detalles del gráfico vigente.
```
[man_filtro grupo="enso" control="vista"] [man_filtro grupo="enso" control="tipo"]
[man_grafico grupo="enso" view="oni_serie" toolbar="no"]
[man_panel grupo="enso"]
```
`control`: `vista` (elige el conjunto de datos), `tipo` (tipo de gráfico compatible) o `mes` (deslizador 2026-03 → 2027-03).

### Predicción y métodos predictivos
`[man_prediccion]` no lee una curva fija: el plugin **calcula** la trayectoria del
ONI con un modelo de **tendencia lineal amortiguada (Holt) por mínimos cuadrados**
sobre la cola observada, con **reversión a la media** climatológica y **banda de
incertidumbre creciente** con el horizonte (ampliada en la primavera boreal). La
**probabilidad de fase** se obtiene integrando una gaussiana sobre los umbrales
NOAA ±0,5 °C. Todo se contrasta con el **ensamble oficial NOAA-CPC/IRI** y se
comunica siempre la incertidumbre (no son certezas). Lógica auditable en
`includes/analysis/class-man-forecast.php` y `class-man-texto.php`.

### Apariencia minimalista y overrides por atributo
Por defecto todo es **transparente y sin bordes/sombras/franjas**. Ajusta el aspecto global en **Monitor Ambiental → Apariencia**, o por shortcode:
```
[man_estado fondo="#ffffff" borde="1px" radio="8px" sombra="0 1px 4px rgba(0,0,0,.08)"]
[man_mapa acento="#003087" ancho="720px"]
```
Atributos de apariencia: `fondo`, `texto`, `acento`, `acento2`, `tecnico`, `borde`, `sombra`, `ancho`, `espaciado`, `radio`.

---

## Datos abiertos (API pública)

Pensada para ciudadanía, estudiantes e investigadores. Licencia **CC BY 4.0**.

```
GET /wp-json/man/v1/abierto/municipios?mes=2026-10&formato=json
GET /wp-json/man/v1/abierto/oni?dominio=historico&formato=csv     # observado
GET /wp-json/man/v1/abierto/oni?dominio=pronostico&formato=json   # proyectado
GET /wp-json/man/v1/abierto/prediccion?hasta=2027-02&formato=json
GET /wp-json/man/v1/abierto/52001?formato=json
```

**Dominios separados (histórico vs pronóstico).** El backend divide el ONI en
observado y proyección: `/historico/oni` (medido por NOAA) y `/pronostico/oni`
(proyección). Las vistas del motor `oni_observado` y `oni_pronostico`, y
`[man_estadisticas tipo="observado|pronostico"]`, usan esa separación.

El shortcode `[man_datos]` genera los botones de **Descargar JSON**, **Descargar CSV**, **Ver API** y **Copiar URL** (recursos: `municipios`, `oni`, `prediccion`, `municipio`).

REST interna (para el front): `/wp-json/man/v1/municipio/{divipola}`, `/departamento`, `/oni`, `/historico/oni`, `/pronostico/oni`, `/prediccion`, `/historico`, `/render`, `/estado-apis`.

---

## Arquitectura (3 capas)

1. **Sync servidor (cron):** NOAA ONI/Niño 3.4, IDEAM (datos.gov.co), SIVIGILA, IOC → `wp_man_cache`.
2. **Navegador directo:** Open-Meteo (pronóstico/marino/aire/caudal), CORS, sin clave.
3. **REST interna:** sirve datos procesados (fase, riesgo, texto de análisis) al front.

**Resiliencia:** si una API falla, el componente cae a caché → semilla JSON local → mensaje de mantenimiento.

---

## Seguridad

Sanitización (lista blanca de 64 DIVIPOLA + bounding-box de Nariño), escape de salida, nonces, capacidades, rate-limit por IP, claves de API cifradas con `sodium_crypto_secretbox` y `sslverify=false` solo para portales estatales CO.

---

## Fuentes y atribución (obligatoria)

NOAA/CPC · IRI · IDEAM (datos.gov.co) · **Open-Meteo (CC BY 4.0)** · NASA POWER · IOC/VLIZ Sea Level · OpenAQ · INS/SIVIGILA · DANE (cartografía).

> Los escenarios de planeación (semillas JSON) son ilustrativos, no pronósticos oficiales. Verificar contra boletines vigentes de IDEAM y NOAA-CPC.
