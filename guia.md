# Especificaciones técnicas y guía de construcción
## Plugin WordPress "Monitor Ambiental y Fenómeno El Niño — Nariño"

> Visualización ciudadana de ENSO (El Niño / La Niña) y condiciones ambientales de los 64 municipios.
>
> **Gobernación de Nariño · Secretaría TIC, Innovación y Gobierno Abierto** — Pasto, Nariño · Colombia
> Versión 1.0 — documento de especificación funcional y técnica.
> Stack: D3.js · D3plus.js · Three.js · APIs en tiempo real · arquitectura por etapas.

---

## 1. Propósito y alcance

**Objetivo.** Construir un plugin de WordPress que comunique a la ciudadanía, de forma visual, animada e interactiva, el estado del fenómeno El Niño / La Niña (ENSO) y las condiciones ambientales del departamento de Nariño y de cada uno de sus 64 municipios. El plugin consume APIs en tiempo real, aplica algoritmos de análisis y pronóstico, y expone cada componente como un **shortcode independiente** para incrustarlo en cualquier página del portal `gobiernoabierto.narino.gov.co`.

**Contexto operativo (junio 2026).** El Climate Prediction Center de la NOAA mantiene activa una **Vigilancia de El Niño**: ~82 % de probabilidad de emergencia de El Niño en el trimestre mayo–julio 2026 y ~96 % de persistencia hacia el invierno boreal 2026–27. La herramienta es pertinente para el Plan de Contingencia departamental (Ley 1523 de 2012).

**Marco de identidad.** Paleta institucional verde `#10A13B` + amarillo `#FFD500`, azul profundo `#003087` para acentos técnicos/marinos. Tipografía Hind Madurai. Los colores semánticos del clima (azules/rojos del Pacífico) y el semáforo de alertas se conservan solo donde su uso es universal y técnico.

> **Convenciones heredadas del ecosistema (obligatorias).** Namespace PHP `GobernacionNarino\MonitorAmbiental`; prefijo de tablas `wp_man_`; `sslverify => false` en todas las llamadas a portales estatales colombianos; caché con `transients`; D3plus v3 vía CDN; sin npm/build para los entregables estáticos del front; todo el código y los comentarios en español.

### 1.1 Principios de diseño

1. **Ciudadano primero.** Cada gráfico va acompañado de un texto de análisis en lenguaje claro, generado automáticamente a partir de los datos.
2. **Modularidad por shortcode.** Estado actual, pronóstico, histórico, globo 3D + línea de tiempo, mapas por municipio, nivel del mar, salud y recursos hídricos son piezas independientes y combinables.
3. **Resiliencia.** Si una API falla, el componente cae con elegancia a un dato cacheado o a un mensaje de mantenimiento, nunca a una pantalla rota.
4. **Tiempo real con respeto al backend.** El navegador consume APIs públicas sin clave (Open-Meteo) directamente; las fuentes oficiales (NOAA, IDEAM, SIVIGILA) se sincronizan por cron del lado del servidor y se cachean.
5. **Seguridad por defecto.** Nonces, sanitización, capacidades, rate-limiting y almacenamiento cifrado de claves. Ver Sección 9.

---

## 2. Arquitectura general

Tres capas para equilibrar frescura de datos y carga del servidor.

### Capa 1 — Sincronización servidor (cron)
- **Qué sincroniza:** índice ONI y Niño 3.4 (NOAA/CPC), alertas y pronóstico oficial (IDEAM vía datos.gov.co), casos de salud (SIVIGILA), nivel del mar (IOC).
- **Cómo:** WP-Cron cada 6–12 h descarga, parsea, normaliza a JSON propio y guarda en transients + tabla `wp_man_cache`.
- **Por qué:** estas fuentes cambian lento (mensual/semanal), requieren parseo (texto plano, SoQL) y algunas exigen `sslverify=false`, inadecuado para el navegador.

### Capa 2 — Consumo directo navegador
- **Qué:** pronóstico diario y horario por municipio (Open-Meteo Forecast), oleaje (Open-Meteo Marine), calidad del aire (Open-Meteo Air Quality).
- **Cómo:** fetch directo desde JS al hacer clic en el mapa o cargar un shortcode. Open-Meteo soporta CORS y no exige clave.
- **Por qué:** datos puntuales y bajo demanda; evita que el servidor sea cuello de botella con 64 municipios.

### Capa 3 — Análisis y presentación
- **Qué:** algoritmos de clasificación de fase ENSO, anomalías, índice de riesgo municipal, pronóstico probabilístico; render con D3.js, D3plus.js y Three.js.
- **Dónde:** REST API interna del plugin (`/wp-json/man/v1/...`) sirve los datos ya procesados al front.

### 2.1 Diagrama lógico de flujo de datos

```
FUENTES EXTERNAS            CAPA 1 (servidor)          CAPA 3 (REST interna)      FRONT (navegador)
───────────────            ─────────────────          ─────────────────────      ─────────────────
NOAA CPC (ONI/3.4) ─┐
IDEAM   (alertas)  ─┤  WP-Cron → parser → wp_man_cache → /wp-json/man/v1/* ──┐
SIVIGILA (salud)   ─┤   (6-12h)  normaliza  transients    (procesado +       │
IOC     (mar)      ─┘                                     algoritmos)        │  D3 / D3plus / Three.js
                                                                             ├─→ shortcodes
Open-Meteo Forecast ──────────────────────────────  fetch directo  ─────────┘  (estado, pronóstico,
Open-Meteo Marine   ──────────────────────────────  (CORS, sin key)            histórico, globo,
Open-Meteo AirQ     ──────────────────────────────                             mapas, mar, salud)
NASA POWER (hist.)  ───────────  fetch directo o cron, según volumen  ──────────
```

### 2.2 Estructura de carpetas del plugin

```
monitor-ambiental-narino/
├── monitor-ambiental-narino.php      (bootstrap, headers del plugin)
├── uninstall.php
├── includes/
│   ├── class-man-plugin.php           (singleton, orquestador)
│   ├── class-man-activator.php        (crea tablas, agenda cron)
│   ├── class-man-rest.php             (endpoints /wp-json/man/v1)
│   ├── class-man-cache.php            (transients + tabla)
│   ├── class-man-security.php         (nonces, caps, rate-limit, cifrado)
│   ├── sync/
│   │   ├── class-man-sync-oni.php      (NOAA ONI + Niño 3.4)
│   │   ├── class-man-sync-ideam.php    (datos.gov.co SoQL)
│   │   ├── class-man-sync-sivigila.php (salud)
│   │   └── class-man-sync-sealevel.php (IOC nivel del mar)
│   ├── analysis/
│   │   ├── class-man-enso.php          (clasificación de fase, anomalías)
│   │   ├── class-man-forecast.php      (pronóstico probabilístico)
│   │   └── class-man-risk.php          (índice de riesgo municipal)
│   ├── shortcodes/
│   │   └── class-man-shortcodes.php    (registro de los 9 shortcodes)
│   └── admin/
│       ├── class-man-admin.php         (menú, settings, salud de APIs)
│       └── class-man-api-config.php    (config/actualización por API)
├── assets/
│   ├── js/   (globo.js, mapa.js, graficos.js, timeline.js, estado.js, ...)
│   ├── css/  (estilos.css con paleta institucional)
│   └── img/  (TIC.png, texturas globo)
├── data/    (narino_municipios.geojson, semillas JSON)
└── languages/  (es_CO)
```

---

## 3. Catálogo de APIs (investigación y verificación)

Todas verificadas como vigentes y de actualización permanente. **«Sin clave»** = consumible directo desde el navegador; las demás se sincronizan por cron.

### 3.1 Open-Meteo — núcleo del pronóstico (sin clave, CORS, CC BY 4.0)

| Endpoint | Uso en el plugin | Host |
|----------|------------------|------|
| Forecast | Pronóstico diario/horario por municipio (T°, precipitación, viento, humedad) hasta 16 días | `api.open-meteo.com/v1/forecast` |
| Historical (ERA5) | Series desde 1940 para tendencias y anomalías | `archive-api.open-meteo.com/v1/archive` |
| Climate (CMIP6) | Proyecciones a 2050, escenarios de cambio climático (10 km) | `climate-api.open-meteo.com/v1/climate` |
| Marine | Altura de olas y oleaje para la costa Pacífica | `marine-api.open-meteo.com/v1/marine` |
| Air Quality | PM2.5, PM10, O₃, NO₂ por municipio | `air-quality-api.open-meteo.com/v1/air-quality` |
| Flood (GloFAS) | Caudal de ríos para alerta hidrológica | `flood-api.open-meteo.com/v1/flood` |

**Ejemplo — pronóstico Pasto:**
```
GET https://api.open-meteo.com/v1/forecast
    ?latitude=1.21&longitude=-77.28
    &daily=temperature_2m_max,precipitation_sum
    &timezone=America/Bogota
```

**Ejemplo — histórico ERA5:**
```
GET https://archive-api.open-meteo.com/v1/era5
    ?latitude=1.21&longitude=-77.28
    &start_date=2021-01-01&end_date=2021-12-31
    &hourly=temperature_2m
```

**Ejemplo — oleaje costa de Tumaco (Marine API):**
```
GET https://marine-api.open-meteo.com/v1/marine
    ?latitude=1.81&longitude=-78.76
    &daily=wave_height_max,wave_period_max&timezone=America/Bogota
```

### 3.2 NOAA / CPC — índices ENSO oficiales (cron, texto plano)

Fuente canónica del estado del fenómeno. No es REST: publica archivos de texto de actualización mensual/semanal. **ONI ≥ +0.5 °C = El Niño; ONI ≤ −0.5 °C = La Niña; intermedio = neutral.**

| Recurso | Contenido | URL |
|---------|-----------|-----|
| ONI histórico (ASCII) | Serie trimestral móvil completa desde 1950 | `cpc.ncep.noaa.gov/data/indices/oni.ascii.txt` |
| Niño 3.4 semanal | Anomalía SST semanal (formato .for) | `cpc.ncep.noaa.gov/data/indices/wksst8110.for` |
| ONI v5 (HTML) | Tabla oficial de referencia y umbrales | `origin.cpc.ncep.noaa.gov/.../ONI_v5.php` |
| PSL Dashboard | Comparación de índices ENSO (1948 y 1870) | `psl.noaa.gov/enso/dashboard.html` |

> **Cómo capturar ONI_v5.php.** La forma robusta NO es raspar el HTML sino consumir el archivo plano `oni.ascii.txt` (mismas cifras, formato estable). Para Niño 3.4, `wksst8110.for` trae columnas de ancho fijo.

### 3.3 IDEAM vía datos.gov.co — pronóstico y alertas oficiales (cron, SoQL)

Fuente oficial colombiana. Socrata/SODA, JSON, filtros tipo SQL (SoQL). Incluye **alertas por municipio** (fenómeno, nivel, fechas, sinopsis) que ninguna API internacional ofrece.

```
GET https://www.datos.gov.co/resource/{dataset-id}.json
    ?departamento=NARI%C3%91O
    &$order=fecha%20DESC
    &$limit=200

// Dataset de pronóstico+alertas: st8p-pai8 (verificar id vigente).
// Requiere sslverify=false en wp_remote_get (cert estatal).
```

> **Nota.** Los identificadores de dataset pueden rotar. El módulo de configuración (Sección 8) permite actualizar el `dataset-id` sin tocar código.

### 3.4 NASA POWER — clima histórico e indicadores (sin clave)

Datos climáticos globales gratuitos. Ideal para perfiles agroclimáticos de café y cacao. Los productos en tiempo casi real se reemplazan por versiones de calidad climática 2–3 meses después; úsese para histórico.

```
GET https://power.larc.nasa.gov/api/temporal/daily/point
    ?parameters=T2M,PRECTOTCORR&community=AG
    &latitude=1.21&longitude=-77.28
    &start=20250101&end=20260601&format=JSON
```

### 3.5 IOC Sea Level Monitoring — nivel del mar Pacífico (cron/REST)

Operada por VLIZ bajo la COI/UNESCO. Mareógrafos en tiempo real (datos cada minuto, actualización cada 5 min). JSON/XML vía REST; máximo 30 días por llamada. La v2 requiere clave; la consulta básica de estación es abierta.

```
// Servicio REST (datos crudos, sin control de calidad):
GET https://api.ioc-sealevelmonitoring.org/?query=data
    &code={codigo_estacion}&timestart=...&timestop=...&format=json

// Buscar la estación de Tumaco/Pacífico en:
//   https://www.ioc-sealevelmonitoring.org/map.php
// Costa de Nariño = zona de subducción (sismo Tumaco 1979, Mw 8.2, tsunami 6 m).
```

> **Relevancia.** Los 7 municipios costeros (Tumaco, Francisco Pizarro, Mosquera, Olaya Herrera, El Charco, La Tola, Santa Bárbara) se benefician del monitoreo de nivel del mar y oleaje. Combinar IOC (nivel) + Open-Meteo Marine (olas) para el shortcode «mar».

### 3.6 OpenAQ — calidad del aire (clave gratuita)

API REST abierta (PM2.5, PM10, SO₂, NO₂, CO, O₃). Clave gratuita en explore.openaq.org. Cobertura limitada en Nariño; **se usa Open-Meteo Air Quality como primaria** y OpenAQ como complemento.

```
GET https://api.openaq.org/v3/locations
    ?coordinates=1.21,-77.28&radius=25000
    (header) X-API-Key: {clave}
```

### 3.7 SIVIGILA / datos.gov.co — salud pública (cron, SoQL)

El INS publica eventos de vigilancia en datos.gov.co. Interesa el **dengue** (vector Aedes aegypti, favorecido por El Niño). SoQL filtrando por departamento y semana epidemiológica.

```
GET https://www.datos.gov.co/resource/{dataset-dengue}.json
    ?departamento_ocurrencia=NARI%C3%91O
    &$where=year=2026&$order=semana DESC&$limit=500

// Eventos: dengue (210), dengue grave (220), EDA, IRA.
```

### 3.8 Recursos hídricos
- **Open-Meteo Flood (GloFAS):** caudal diario de ríos por coordenada, sin clave. Primario.
- **IDEAM hidrología (datos.gov.co):** niveles y caudales de estaciones limnimétricas. Complementa GloFAS con dato local oficial.
- **Open-Meteo soil moisture:** humedad de suelo por capas (en Forecast API), útil para sequía agrícola en sabana y exprovincia de Obando.

### 3.9 Resumen — matriz de fuentes

| Fuente | Capa | Clave | Frecuencia | Shortcode que alimenta |
|--------|------|-------|------------|------------------------|
| Open-Meteo Forecast | Navegador | No | Horaria | pronóstico, mapas, estado |
| Open-Meteo Historical | Navegador | No | Diaria | histórico |
| Open-Meteo Climate | Navegador | No | Estática | histórico (escenarios) |
| Open-Meteo Marine | Navegador | No | Horaria | mar |
| Open-Meteo Air/Flood | Navegador | No | Horaria | ambiental, hídrico |
| NOAA ONI / 3.4 | Cron | No | Mensual/Sem. | estado, globo, histórico |
| IDEAM datos.gov.co | Cron | No | Diaria | estado, mapas (alertas) |
| NASA POWER | Mixto | No | Diaria | histórico (agro) |
| IOC Sea Level | Cron | v2 sí | 5 min | mar |
| OpenAQ | Navegador | Sí | Horaria | ambiental |
| SIVIGILA | Cron | No | Semanal | salud |

---

## 4. Shortcodes (componentes independientes)

Cada elemento es un shortcode autónomo. `municipio` admite código DIVIPOLA o nombre; por defecto `departamento` agrega los 64.

| Shortcode | Qué muestra | Atributos principales |
|-----------|-------------|------------------------|
| `[man_estado]` | Estado actual ENSO + condiciones del día (semáforo, ONI vigente, fase) | municipio, compacto |
| `[man_pronostico]` | Pronóstico 7–16 días (D3) + texto de análisis | municipio, dias |
| `[man_historico]` | Series históricas y episodios ENSO 2015–2024 (D3plus) | municipio, desde, hasta |
| `[man_globo]` | Globo 3D Three.js cinematográfico con animación del fenómeno | calidad, autorotar |
| `[man_timeline]` | Línea de tiempo del globo (slider de meses ONI) | inicio, fin |
| `[man_mapa]` | Mapa coroplético de Nariño por municipio (Leaflet + D3) | variable, mes |
| `[man_mar]` | Nivel del mar + oleaje Pacífico (IOC + Marine) | estacion |
| `[man_salud]` | Casos dengue/EDA/IRA vs clima (D3plus correlación) | evento, año |
| `[man_hidrico]` | Caudales y humedad de suelo (GloFAS + IDEAM) | municipio |
| `[man_estado_api]` | Panel público de salud de las APIs (verde/rojo) | — |

**Ejemplos:** `[man_globo calidad="alta"]` · `[man_pronostico municipio="52835" dias="14"]` · `[man_mapa variable="riesgo" mes="2026-10"]`

### 4.1 Contrato común de cada shortcode
1. Render esqueleto inmediato (skeleton) para no bloquear la página.
2. Carga de datos asíncrona desde la REST interna o API pública según capa.
3. Texto de análisis debajo del gráfico (Sección 5.4).
4. Estado de error elegante con reintento y mensaje institucional.
5. Atribución de fuentes (Open-Meteo CC BY 4.0, NOAA, IDEAM) en el pie del componente.

---

## 5. Algoritmos de análisis, pronóstico y métodos predictivos

### 5.1 Parser del ONI (NOAA) — clasificación de fase

`oni.ascii.txt` trae columnas: SEAS (trimestre), YR, TOTAL, ANOM. La anomalía (ANOM) es el ONI.

```php
// Pseudocódigo PHP (class-man-enso.php)
function clasificar_fase(float $oni): string {
    if ($oni >= 0.5)  return 'El Niño';
    if ($oni <= -0.5) return 'La Niña';
    return 'Neutral';
}
// Intensidad (umbrales NOAA sobre ONI):
//  0.5–0.9 débil | 1.0–1.4 moderado | 1.5–1.9 fuerte | >=2.0 muy fuerte
function intensidad(float $oni): string { /* abs($oni) -> etiqueta */ }

// Episodio 'oficial' = umbral superado 5 trimestres consecutivos solapados.
function es_episodio(array $serie_oni): bool { /* ventana de 5 */ }
```

Niño 3.4 semanal (de `wksst8110.for`): columnas de ancho fijo; extraer la anomalía de la región 3.4 para el «pulso» semanal del semáforo.

### 5.2 Anomalías por municipio

Anomalía = valor observado/pronosticado − climatología de referencia (1991–2020) del mismo municipio (NASA POWER o ERA5):

```
anom_T(mun, fecha) = T_pronostico(mun, fecha) - T_clim(mun, mes(fecha))
anom_lluvia(mun, fecha) = (P_pronostico - P_clim) / P_clim   // % respecto a normal
// Climatología precalculada por cron y cacheada por municipio+mes.
```

### 5.3 Índice de riesgo municipal (compuesto)

Índice 0–1 por municipio y mes, heurístico y transparente (auditable):

```
riesgo(mun, mes) =
    w1 * f_enso(ONI_mes)                 // empuje del fenómeno (0..1)
  + w2 * g_anom(anom_lluvia(mun, mes))   // exceso/déficit de lluvia
  + w3 * h_expo(mun)                     // exposición: población, ladera, costa
  + w4 * k_sector(mun)                   // sensibilidad agrícola/hídrica
// w1+w2+w3+w4 = 1; pesos configurables en el panel admin.
```

> **Diferenciación territorial.** El Niño NO afecta igual a todo Nariño: en la zona andina suele asociarse a **déficit de lluvia** (sequía, incendios), mientras en el litoral Pacífico puede traer **más lluvia y oleaje**. El signo de `f_enso` debe invertirse por subregión.

### 5.4 Generación automática de texto de análisis

Plantillas + reglas (no requiere IA externa, funciona offline):

```
// Ejemplo de salida para [man_estado] municipio=Pasto:
"En junio de 2026 el índice ONI es +0.4 °C (fase neutral, en transición
 hacia El Niño). Para Pasto se pronostica temperatura ligeramente sobre
 lo normal y lluvias por debajo del promedio. Riesgo municipal: medio (0.52)."

// Plantillas parametrizadas por: fase, intensidad, signo de anomalías,
// subregión y nivel de riesgo. Tono institucional, claro, sin tecnicismos.
```

### 5.5 Pronóstico probabilístico de fase
- **Fuente primaria:** plumas/probabilidades del IRI/CPC (El Niño / Neutral / La Niña por trimestre). Se cachean y se grafican como área apilada.
- **Suavizado local:** media móvil de 3 meses sobre el ONI (coincide con la definición del ONI).
- **Proyección municipal:** se cruza la probabilidad de fase con la respuesta histórica del municipio (El Niño 2015-16 y 2023-24) para estimar el rango esperado de T° y lluvia.

> **Honestidad estadística.** Mostrar siempre la incertidumbre (BoxWhisker / banda) y la «barrera de predictibilidad de primavera boreal». No presentar pronósticos largos como certezas: usar «probable», «favorecido», con porcentajes.

---

## 6. Componentes visuales: D3.js, D3plus.js, Three.js

### 6.1 Gráficos prediseñados D3plus.js

D3plus v3 vía CDN. Cada gráfico con paleta institucional y tooltip en español:

| Gráfico D3plus | Dato que representa | Shortcode |
|----------------|---------------------|-----------|
| LinePlot | Evolución ONI observado + proyectado (15 meses) | `[man_historico]`, `[man_estado]` |
| StackedArea | Precipitación acumulada por subregión | `[man_historico]` |
| BarChart | Anomalía de T° por municipio (top severos) | `[man_mapa]` |
| Geomap | Coroplético 64 municipios por variable | `[man_mapa]` |
| BoxWhisker | Dispersión de pronósticos (incertidumbre) | `[man_pronostico]` |
| Plot (scatter) | Correlación dengue vs temperatura | `[man_salud]` |
| Treemap | Población expuesta por nivel de riesgo | `[man_mapa]` |
| Radar | Perfil ambiental municipal (T°, lluvia, viento, riesgo) | `[man_estado]` |

> **Patrón de carga.** Reutilizar el skill `d3plus-wordpress`: enqueue por `wp_enqueue_scripts`, paso de datos PHP→JS por `wp_localize_script`, contenedor SVG responsivo y accesible (aria-label con el texto de análisis).

### 6.2 D3.js puro (controles finos)
- **Semáforo ENSO animado:** gauge D3 que transiciona entre La Niña–Neutral–El Niño según ONI vigente.
- **Sparkline del slider:** mini-serie ONI embebida en la línea de tiempo del globo.
- **Curvas de probabilidad:** área apilada de probabilidades de fase por trimestre.

### 6.3 Globo 3D cinematográfico (Three.js)

Mejora del globo existente hacia acabado cinematográfico, manteniendo el **modo ligero**.

**Escena y cámara**
- **Iluminación:** luz direccional (sol) + ambiental tenue + hemisférica. Sombra suave del terminador día/noche.
- **Cámara:** PerspectiveCamera con aproximación inicial (dolly-in) tipo intro de documental, luego órbita libre con damping (`OrbitControls` con `enableDamping`).
- **Atmósfera:** shader de dispersión (rim glow azulado) en un mesh ligeramente mayor que la Tierra; fresnel en el fragment shader.
- **Fondo:** starfield procedimental (Points) + leve nebulosa; bloom sutil (UnrealBloomPass) solo en calidad alta.

**Tierra y fenómeno**
- **Textura:** Blue Marble desde CDN con fallback en cascada a continentes procedimentales.
- **Capa ENSO:** halo sobre el Pacífico ecuatorial cuyo color (azul→rojo) y opacidad se interpolan según el ONI del mes activo. En El Niño, lengua cálida roja desde la línea de cambio de fecha hacia Sudamérica.
- **Foco Nariño:** marcador pulsante sobre el SW de Colombia; con la línea de tiempo, la cámara puede encuadrar la región.
- **Animación temporal:** al avanzar el mes, la capa ENSO transiciona suavemente (lerp de color y anomalía); pico esperado septiembre–octubre.

**Rendimiento y accesibilidad**
- **Modo ligero:** detecta GPU/devicePixelRatio; desactiva bloom y reduce segmentos.
- **Pausa fuera de viewport:** IntersectionObserver detiene el render-loop cuando el globo no está visible.
- **Accesibilidad:** descripción textual equivalente y controles de teclado para el slider.

> **Three.js: nota de versión.** Si se usa r128, `OrbitControls` y `CapsuleGeometry` no están disponibles; usar geometrías básicas (Sphere/Cylinder) y cargar OrbitControls como script aparte, o fijar una versión más reciente vía CDN. Validar antes de producción.

### 6.4 Mapas por municipio
- **Base:** Leaflet con el GeoJSON DANE de los 64 municipios (incluido en `data/`).
- **Coroplético:** relleno por variable seleccionable (riesgo, anomalía T°, precipitación, alerta IDEAM) con escala por percentiles, paleta `#f5e9a2 → #fcb900 → #00a22d` o semáforo de riesgo.
- **Interacción:** clic en municipio → panel lateral con pronóstico Open-Meteo en vivo + mini-gráficos D3 + texto de análisis.
- **Módulo generador:** función `manGenerarMapa(variable, mes)` invocable por cualquier shortcode (Sección 7).

---

## 7. Módulo generador de gráficos/mapas por municipio

Servicio interno reutilizable que cualquier shortcode invoca para producir, bajo demanda, un gráfico o mapa de un municipio concreto.

**Contrato del servicio (JS front):**
```
manGenerar({
  municipio: '52001',        // DIVIPOLA o 'departamento'
  tipo: 'mapa'|'linea'|'barra'|'radar'|'scatter',
  variable: 'riesgo'|'temp'|'lluvia'|'oni'|'dengue'|'oleaje',
  periodo: { desde:'2026-01', hasta:'2027-03' },
  contenedor: '#id'
}) -> Promise<void>
```

**Flujo interno:**
1. Resuelve coordenadas del municipio desde la tabla (Sección 10).
2. Decide capa: dato puntual → Open-Meteo directo; dato oficial/ENSO → REST interna cacheada.
3. Calcula anomalía/riesgo (Sección 5).
4. Renderiza con D3plus/D3/Leaflet según `tipo`.
5. Inserta el texto de análisis (Sección 5.4) y la atribución de fuentes.

**Endpoint REST interno de apoyo:**
```
GET /wp-json/man/v1/municipio/{divipola}?mes=2026-10
  -> { nombre, lat, lon, subregion, oni, fase, anom_t, anom_lluvia,
       riesgo, alerta_ideam, texto_analisis }

GET /wp-json/man/v1/departamento?mes=2026-10
  -> [ {divipola, riesgo, ...}, ... x64 ]   // para el coroplético
```

---

## 8. Módulo de configuración y actualización por API

Panel de administración donde cada fuente es una tarjeta editable, sin tocar código. Resuelve la rotación de `dataset-id` de datos.gov.co, claves de IOC/OpenAQ y cambios de endpoint.

**Campos por cada API:**
- **Estado:** activa/inactiva (toggle).
- **URL base / dataset-id:** editable.
- **Clave (si aplica):** campo cifrado (OpenAQ, IOC v2).
- **Frecuencia de sincronización:** selector (1, 6, 12, 24 h).
- **TTL de caché:** minutos de vida del transient.
- **sslverify:** toggle (por defecto OFF para portales estatales CO).
- **Última sincronización + resultado:** timestamp y nº de registros.
- **Botones:** «Sincronizar ahora» y «Probar conexión».

**Persistencia:** opciones en `wp_options` bajo `man_api_config` (claves sensibles cifradas, Sección 9.4). Cada cambio queda en `wp_man_audit`.

---

## 9. Seguridad (transversal y prioritaria)

> **Principio rector.** El plugin es público y consume fuentes externas; toda entrada es no confiable y toda salida se escapa. Seguridad por defecto, no como añadido.

### 9.1 Entradas y salidas
- **Sanitización:** todo atributo de shortcode y parámetro REST pasa por `sanitize_text_field`, `absint`, validación de DIVIPOLA contra lista blanca de 64 códigos.
- **Escape de salida:** `esc_html`, `esc_attr`, `esc_url`, `wp_json_encode` en todo dato que llegue al DOM.
- **Validación de coordenadas:** solo lat/lon dentro del bounding box de Nariño (evita SSRF/abuso de APIs).

### 9.2 Autorización y nonces
- **Endpoints REST públicos (solo lectura):** rate-limit por IP y nada de datos sensibles.
- **Acciones admin:** `current_user_can('manage_options')` + `wp_verify_nonce`.
- **AJAX:** nonce dedicado (`man_nonce`) verificado en cada handler.

### 9.3 Rate-limiting y abuso
- **Capa servidor:** límite de N peticiones/min por IP a la REST interna (transient contador).
- **Capa front:** debounce de clics en el mapa; caché en memoria de respuestas Open-Meteo por sesión.
- **Backoff:** ante 429/5xx de una fuente, reintento con espera exponencial y caída a caché.

### 9.4 Gestión de claves y secretos
- **Cifrado en reposo:** claves (OpenAQ, IOC v2) cifradas con `sodium_crypto_secretbox` usando clave derivada de `AUTH_KEY`/`SECURE_AUTH_SALT` de wp-config; nunca en texto plano en BD ni logs.
- **Sin secretos en el front:** ninguna clave se expone al navegador; las fuentes con clave se consumen solo por cron.

### 9.5 Cabeceras y transporte
- **CSP:** política que permita los CDNs usados (D3, D3plus, Three.js, Leaflet, textura globo) y los hosts de API; bloquear el resto.
- **HTTPS forzado** en el portal; las APIs públicas se llaman por https.
- **.htaccess (Plesk):** gzip de JSON, cabeceras MIME/CORS correctas, denegar acceso directo a `includes/` y `data/*.json` sensibles.

### 9.6 Privacidad y cumplimiento
- **Sin PII:** el plugin no recoge datos personales; los datos de salud se usan agregados por municipio/semana (nunca individuales).
- **Atribución de licencias:** Open-Meteo CC BY 4.0, NOAA, IDEAM, NASA POWER en el pie y en el README (requisito legal).
- **Auditoría:** tabla `wp_man_audit` con cada sincronización, cambio de config y error, con timestamp UTC (display America/Bogota).

---

## 10. Coordenadas de los 64 municipios de Nariño

Coordenadas oficiales del centroide municipal (cartografía DANE / GeoJSON DIVIPOLA incluido en `data/narino_municipios.geojson`). Listas para usar como `latitude/longitude` en Open-Meteo y NASA POWER. El departamento agrega los 64 (capital: Pasto).

> **Municipios costeros del Pacífico (7)** para los shortcodes de mar/oleaje/nivel: SAN ANDRÉS DE TUMACO, FRANCISCO PIZARRO, MOSQUERA, OLAYA HERRERA, EL CHARCO, LA TOLA, SANTA BÁRBARA.

### 10.1 Tabla maestra (orden alfabético)

| DIVIPOLA | Municipio | Latitud | Longitud | Subregión (PDD) |
|----------|-----------|---------|----------|-----------------|
| 52019 | ALBÁN | 1.4699 | -77.0688 | Río Mayo |
| 52022 | ALDANA | 0.9134 | -77.6954 | Ex-Provincia de Obando |
| 52036 | ANCUYA | 1.2452 | -77.5312 | Occidente |
| 52051 | ARBOLEDA | 1.4801 | -77.1299 | Juanambú |
| 52079 | BARBACOAS | 1.4456 | -78.1562 | Telembí |
| 52083 | BELÉN | 1.5908 | -77.0429 | Río Mayo |
| 52110 | BUESACO | 1.3152 | -77.1164 | Juanambú |
| 52240 | CHACHAGÜÍ | 1.3865 | -77.2697 | Centro |
| 52203 | COLÓN | 1.6363 | -77.0473 | Río Mayo |
| 52207 | CONSACÁ | 1.2091 | -77.4406 | Occidente |
| 52210 | CONTADERO | 0.9327 | -77.5281 | Ex-Provincia de Obando |
| 52224 | CUASPUD CARLOSAMA | 0.8754 | -77.7359 | Ex-Provincia de Obando |
| 52227 | CUMBAL | 0.9442 | -77.9596 | Ex-Provincia de Obando |
| 52233 | CUMBITARA | 1.7256 | -77.5928 | Cordillera |
| 52215 | CÓRDOBA | 0.7708 | -77.3603 | Ex-Provincia de Obando |
| 52250 | EL CHARCO | 2.1831 | -77.7957 | Sanquianga |
| 52254 | EL PEÑOL | 1.5123 | -77.4305 | Cordillera |
| 52256 | EL ROSARIO | 1.8451 | -77.4383 | Cordillera |
| 52258 | EL TABLÓN DE GÓMEZ | 1.4094 | -76.9853 | Juanambú |
| 52260 | EL TAMBO | 1.4303 | -77.3831 | Frontera Pacífica |
| 52520 | FRANCISCO PIZARRO | 2.0885 | -78.5919 | Pacífico Sur |
| 52287 | FUNES | 0.9142 | -77.3284 | Ex-Provincia de Obando |
| 52317 | GUACHUCAL | 0.9750 | -77.7376 | Ex-Provincia de Obando |
| 52320 | GUAITARILLA | 1.1514 | -77.5301 | Sabana |
| 52323 | GUALMATÁN | 0.9286 | -77.5826 | Ex-Provincia de Obando |
| 52352 | ILES | 0.9805 | -77.5187 | Ex-Provincia de Obando |
| 52354 | IMUÉS | 1.0729 | -77.5015 | Sabana |
| 52356 | IPIALES | 0.5586 | -77.3704 | Ex-Provincia de Obando |
| 52378 | LA CRUZ | 1.5842 | -76.9233 | Río Mayo |
| 52381 | LA FLORIDA | 1.3339 | -77.3882 | Centro |
| 52385 | LA LLANADA | 1.5540 | -77.7032 | Abades-La Llanada |
| 52390 | LA TOLA | 2.4193 | -78.2099 | Sanquianga |
| 52399 | LA UNIÓN | 1.6197 | -77.1428 | Río Mayo |
| 52405 | LEIVA | 1.9390 | -77.3119 | Cordillera |
| 52411 | LINARES | 1.3952 | -77.5209 | Occidente |
| 52418 | LOS ANDES | 1.6726 | -77.7105 | Abades-La Llanada |
| 52427 | MAGÜÍ | 1.9069 | -78.0447 | Telembí |
| 52435 | MALLAMA | 1.1560 | -77.8466 | Centro-Occidente / Abades |
| 52473 | MOSQUERA | 2.4425 | -78.4388 | Sanquianga |
| 52480 | NARIÑO | 1.2809 | -77.3539 | Centro |
| 52490 | OLAYA HERRERA | 2.2899 | -78.2947 | Sanquianga |
| 52506 | OSPINA | 1.0298 | -77.5524 | Sabana |
| 52001 | PASTO | 1.0836 | -77.2061 | Centro |
| 52540 | POLICARPA | 1.7353 | -77.4813 | Cordillera |
| 52560 | POTOSÍ | 0.7227 | -77.4248 | Ex-Provincia de Obando |
| 52565 | PROVIDENCIA | 1.2329 | -77.5984 | Centro-Occidente / Abades |
| 52573 | PUERRES | 0.8265 | -77.3222 | Ex-Provincia de Obando |
| 52585 | PUPIALES | 0.9168 | -77.6334 | Ex-Provincia de Obando |
| 52612 | RICAURTE | 1.2028 | -78.0477 | Pie de Monte Costero |
| 52621 | ROBERTO PAYÁN | 1.8976 | -78.3811 | Telembí |
| 52678 | SAMANIEGO | 1.4306 | -77.6918 | Centro-Occidente / Abades |
| 52835 | SAN ANDRÉS DE TUMACO | 1.6361 | -78.6139 | Pacífico Sur |
| 52685 | SAN BERNARDO | 1.5298 | -77.0207 | Juanambú |
| 52687 | SAN LORENZO | 1.5421 | -77.2187 | Juanambú |
| 52693 | SAN PABLO | 1.6816 | -76.9753 | Río Mayo |
| 52694 | SAN PEDRO DE CARTAGO | 1.5368 | -77.1014 | Juanambú |
| 52683 | SANDONÁ | 1.2881 | -77.4567 | Occidente |
| 52696 | SANTA BÁRBARA | 2.3022 | -77.8744 | Sanquianga |
| 52699 | SANTACRUZ | 1.2852 | -77.7446 | Centro-Occidente / Abades |
| 52720 | SAPUYES | 1.0362 | -77.6804 | Sabana |
| 52786 | TAMINANGO | 1.5917 | -77.3252 | Cordillera |
| 52788 | TANGUA | 1.0641 | -77.3506 | Centro |
| 52838 | TÚQUERRES | 1.1344 | -77.6307 | Sabana |
| 52885 | YACUANQUER | 1.1256 | -77.4247 | Centro |

### 10.2 Arreglo JavaScript listo para el plugin

Copiar tal cual en `assets/js/municipios.js`. Coordenadas redondeadas a 5 decimales (precisión < 1.5 m).

```javascript
const MUNICIPIOS_NARINO = [
  { divipola:"52019", nombre:"ALBÁN", lat:1.46985, lon:-77.06881, subregion:"Río Mayo" },
  { divipola:"52022", nombre:"ALDANA", lat:0.91343, lon:-77.69539, subregion:"Ex-Provincia de Obando" },
  { divipola:"52036", nombre:"ANCUYA", lat:1.24525, lon:-77.53116, subregion:"Occidente" },
  { divipola:"52051", nombre:"ARBOLEDA", lat:1.48005, lon:-77.12985, subregion:"Juanambú" },
  { divipola:"52079", nombre:"BARBACOAS", lat:1.44564, lon:-78.15621, subregion:"Telembí" },
  { divipola:"52083", nombre:"BELÉN", lat:1.59076, lon:-77.0429, subregion:"Río Mayo" },
  { divipola:"52110", nombre:"BUESACO", lat:1.31522, lon:-77.11637, subregion:"Juanambú" },
  { divipola:"52240", nombre:"CHACHAGÜÍ", lat:1.3865, lon:-77.26969, subregion:"Centro" },
  { divipola:"52203", nombre:"COLÓN", lat:1.63633, lon:-77.04732, subregion:"Río Mayo" },
  { divipola:"52207", nombre:"CONSACÁ", lat:1.20907, lon:-77.44064, subregion:"Occidente" },
  { divipola:"52210", nombre:"CONTADERO", lat:0.93267, lon:-77.52809, subregion:"Ex-Provincia de Obando" },
  { divipola:"52224", nombre:"CUASPUD CARLOSAMA", lat:0.87543, lon:-77.73592, subregion:"Ex-Provincia de Obando" },
  { divipola:"52227", nombre:"CUMBAL", lat:0.94422, lon:-77.95958, subregion:"Ex-Provincia de Obando" },
  { divipola:"52233", nombre:"CUMBITARA", lat:1.72559, lon:-77.59282, subregion:"Cordillera" },
  { divipola:"52215", nombre:"CÓRDOBA", lat:0.7708, lon:-77.36033, subregion:"Ex-Provincia de Obando" },
  { divipola:"52250", nombre:"EL CHARCO", lat:2.18315, lon:-77.79574, subregion:"Sanquianga" },
  { divipola:"52254", nombre:"EL PEÑOL", lat:1.51228, lon:-77.43051, subregion:"Cordillera" },
  { divipola:"52256", nombre:"EL ROSARIO", lat:1.84509, lon:-77.43826, subregion:"Cordillera" },
  { divipola:"52258", nombre:"EL TABLÓN DE GÓMEZ", lat:1.40943, lon:-76.98527, subregion:"Juanambú" },
  { divipola:"52260", nombre:"EL TAMBO", lat:1.43026, lon:-77.38312, subregion:"Frontera Pacífica" },
  { divipola:"52520", nombre:"FRANCISCO PIZARRO", lat:2.08853, lon:-78.59193, subregion:"Pacífico Sur" },
  { divipola:"52287", nombre:"FUNES", lat:0.91422, lon:-77.32843, subregion:"Ex-Provincia de Obando" },
  { divipola:"52317", nombre:"GUACHUCAL", lat:0.97504, lon:-77.73759, subregion:"Ex-Provincia de Obando" },
  { divipola:"52320", nombre:"GUAITARILLA", lat:1.15137, lon:-77.53011, subregion:"Sabana" },
  { divipola:"52323", nombre:"GUALMATÁN", lat:0.92864, lon:-77.58262, subregion:"Ex-Provincia de Obando" },
  { divipola:"52352", nombre:"ILES", lat:0.98053, lon:-77.51866, subregion:"Ex-Provincia de Obando" },
  { divipola:"52354", nombre:"IMUÉS", lat:1.07288, lon:-77.50151, subregion:"Sabana" },
  { divipola:"52356", nombre:"IPIALES", lat:0.55861, lon:-77.37036, subregion:"Ex-Provincia de Obando" },
  { divipola:"52378", nombre:"LA CRUZ", lat:1.58418, lon:-76.92335, subregion:"Río Mayo" },
  { divipola:"52381", nombre:"LA FLORIDA", lat:1.33393, lon:-77.38823, subregion:"Centro" },
  { divipola:"52385", nombre:"LA LLANADA", lat:1.55401, lon:-77.70317, subregion:"Abades-La Llanada" },
  { divipola:"52390", nombre:"LA TOLA", lat:2.41931, lon:-78.20991, subregion:"Sanquianga" },
  { divipola:"52399", nombre:"LA UNIÓN", lat:1.6197, lon:-77.14285, subregion:"Río Mayo" },
  { divipola:"52405", nombre:"LEIVA", lat:1.93898, lon:-77.31194, subregion:"Cordillera" },
  { divipola:"52411", nombre:"LINARES", lat:1.39517, lon:-77.52094, subregion:"Occidente" },
  { divipola:"52418", nombre:"LOS ANDES", lat:1.6726, lon:-77.71054, subregion:"Abades-La Llanada" },
  { divipola:"52427", nombre:"MAGÜÍ", lat:1.90686, lon:-78.04474, subregion:"Telembí" },
  { divipola:"52435", nombre:"MALLAMA", lat:1.15595, lon:-77.84665, subregion:"Centro-Occidente / Abades" },
  { divipola:"52473", nombre:"MOSQUERA", lat:2.44249, lon:-78.43883, subregion:"Sanquianga" },
  { divipola:"52480", nombre:"NARIÑO", lat:1.28086, lon:-77.35389, subregion:"Centro" },
  { divipola:"52490", nombre:"OLAYA HERRERA", lat:2.28989, lon:-78.29472, subregion:"Sanquianga" },
  { divipola:"52506", nombre:"OSPINA", lat:1.02982, lon:-77.55235, subregion:"Sabana" },
  { divipola:"52001", nombre:"PASTO", lat:1.08361, lon:-77.2061, subregion:"Centro" },
  { divipola:"52540", nombre:"POLICARPA", lat:1.73535, lon:-77.48134, subregion:"Cordillera" },
  { divipola:"52560", nombre:"POTOSÍ", lat:0.72268, lon:-77.42481, subregion:"Ex-Provincia de Obando" },
  { divipola:"52565", nombre:"PROVIDENCIA", lat:1.23286, lon:-77.59844, subregion:"Centro-Occidente / Abades" },
  { divipola:"52573", nombre:"PUERRES", lat:0.82652, lon:-77.32225, subregion:"Ex-Provincia de Obando" },
  { divipola:"52585", nombre:"PUPIALES", lat:0.91677, lon:-77.63337, subregion:"Ex-Provincia de Obando" },
  { divipola:"52612", nombre:"RICAURTE", lat:1.20276, lon:-78.04765, subregion:"Pie de Monte Costero" },
  { divipola:"52621", nombre:"ROBERTO PAYÁN", lat:1.89758, lon:-78.38112, subregion:"Telembí" },
  { divipola:"52678", nombre:"SAMANIEGO", lat:1.43056, lon:-77.6918, subregion:"Centro-Occidente / Abades" },
  { divipola:"52835", nombre:"SAN ANDRÉS DE TUMACO", lat:1.6361, lon:-78.61391, subregion:"Pacífico Sur" },
  { divipola:"52685", nombre:"SAN BERNARDO", lat:1.52978, lon:-77.02071, subregion:"Juanambú" },
  { divipola:"52687", nombre:"SAN LORENZO", lat:1.54214, lon:-77.21873, subregion:"Juanambú" },
  { divipola:"52693", nombre:"SAN PABLO", lat:1.68158, lon:-76.97528, subregion:"Río Mayo" },
  { divipola:"52694", nombre:"SAN PEDRO DE CARTAGO", lat:1.53682, lon:-77.1014, subregion:"Juanambú" },
  { divipola:"52683", nombre:"SANDONÁ", lat:1.28811, lon:-77.4567, subregion:"Occidente" },
  { divipola:"52696", nombre:"SANTA BÁRBARA", lat:2.30216, lon:-77.87437, subregion:"Sanquianga" },
  { divipola:"52699", nombre:"SANTACRUZ", lat:1.28518, lon:-77.74457, subregion:"Centro-Occidente / Abades" },
  { divipola:"52720", nombre:"SAPUYES", lat:1.03619, lon:-77.68045, subregion:"Sabana" },
  { divipola:"52786", nombre:"TAMINANGO", lat:1.59166, lon:-77.32525, subregion:"Cordillera" },
  { divipola:"52788", nombre:"TANGUA", lat:1.06408, lon:-77.35063, subregion:"Centro" },
  { divipola:"52838", nombre:"TÚQUERRES", lat:1.13444, lon:-77.63073, subregion:"Sabana" },
  { divipola:"52885", nombre:"YACUANQUER", lat:1.12555, lon:-77.42468, subregion:"Centro" }
];
```

### 10.3 Bounding box de Nariño (validación de coordenadas)

```javascript
// Para la validación de seguridad (Sección 9.1):
const NARINO_BBOX = { latMin: 0.35, latMax: 2.70, lonMin: -79.10, lonMax: -76.85 };
// Rechazar cualquier lat/lon fuera de este recuadro en la REST interna.
```

> **Punto del Pacífico para Marine/IOC.** Para oleaje frente a Tumaco usar lat `1.81`, lon `-78.76` (mar abierto frente al puerto); el centroide municipal terrestre no devuelve datos marinos.

---

## 11. Módulos de verificación de funcionalidad de las APIs

Doble verificación: interna (admin) y pública (transparencia ciudadana).

### 11.1 Panel admin de salud de APIs
- **Test por fuente:** «Probar conexión» hace una petición mínima y reporta latencia, código HTTP y validez del JSON/formato.
- **Semáforo:** verde (OK), amarillo (degradado/lento o usando caché), rojo (caído).
- **Checks específicos:** ONI parseado tiene ≥ N filas; datos.gov.co devuelve registros de Nariño; Open-Meteo responde para Pasto.
- **Alertas:** aviso en el dashboard de WP y correo opcional si una fuente lleva > X horas caída.

### 11.2 Shortcode público `[man_estado_api]`
- Tabla compacta con cada fuente, su estado y la hora de última actualización. Refuerza la confianza ciudadana.

### 11.3 Pruebas automatizadas
- **Unitarias (PHPUnit):** parsers (ONI ancho fijo, SoQL), clasificador de fase, cálculo de anomalía e índice de riesgo.
- **Contrato de API:** mocks de respuestas reales para detectar cambios de esquema upstream.
- **E2E (Playwright):** cada shortcode renderiza, el mapa responde al clic, el slider del globo cambia colores.

---

## 12. Construcción del API y del plugin por etapas

Entrega incremental, auditando antes de implementar y evitando regresiones. Cada etapa es desplegable y verificable.

| Etapa | Objetivo | Entregable | Criterio de aceptación |
|-------|----------|------------|------------------------|
| 0 | Andamiaje | Plugin base, tablas, REST vacía, menú admin, seguridad base | Activa sin errores; nonces y caps en su sitio |
| 1 | ENSO oficial | Sync ONI+Niño3.4, clasificador de fase, `[man_estado]` | Semáforo muestra fase vigente correcta |
| 2 | Pronóstico | Open-Meteo Forecast directo, `[man_pronostico]` con D3 + texto | 7–16 días por municipio con análisis |
| 3 | Mapa | GeoJSON + coroplético Leaflet/D3, generador por municipio | 64 municipios coloreados; clic abre panel |
| 4 | Histórico | ERA5/POWER + episodios ENSO, `[man_historico]` D3plus | Series y episodios 2015–2024 visibles |
| 5 | Globo 3D | Three.js cinematográfico + `[man_timeline]` | Animación fluida; modo ligero; pico sep–oct |
| 6 | Mar y costa | IOC + Marine, `[man_mar]` para 7 costeros | Nivel del mar y oleaje de Tumaco en vivo |
| 7 | Salud e hídrico | SIVIGILA + GloFAS/IDEAM, `[man_salud]`, `[man_hidrico]` | Correlación clima–dengue; caudales |
| 8 | Config + verificación | Panel config por API + `[man_estado_api]` | Editar dataset-id sin código; salud visible |
| 9 | Endurecimiento | Rate-limit, cifrado claves, CSP, auditoría, pruebas | Pasa PHPUnit/Playwright; checklist seguridad |

> **Orden recomendado de API interna.** Construir primero `/municipio/{divipola}` y `/departamento` (alimentan estado, mapa y generador), luego `/oni`, `/historico`, `/salud`. Versionar siempre como `/man/v1/`.

---

## 13. Resumen ejecutivo y próximos pasos

Plugin de WordPress modular, seguro y construido por etapas, que comunica a la ciudadanía el estado del fenómeno El Niño/La Niña y las condiciones ambientales de los 64 municipios de Nariño, con visualizaciones D3/D3plus, globo 3D cinematográfico en Three.js, mapas por municipio y análisis automático en lenguaje claro.

**Decisiones clave:** Open-Meteo como núcleo sin clave consumido desde el navegador; NOAA/IDEAM/SIVIGILA/IOC sincronizados por cron; cada componente como shortcode independiente; configuración de cada API editable sin código; seguridad transversal (sanitización, nonces, rate-limit, cifrado de claves).

**Arranque sugerido:** Etapas 0–2 (andamiaje + ENSO oficial + pronóstico) producen ya una pieza pública útil; las etapas 3–5 añaden el impacto visual (mapa, histórico, globo); 6–9 completan mar, salud, hídrico, configuración y endurecimiento.

---

*Fuentes: NOAA/CPC, IRI Columbia, IDEAM (datos.gov.co), Open-Meteo (CC BY 4.0), NASA POWER, IOC/VLIZ Sea Level, OpenAQ, INS/SIVIGILA, DANE (cartografía). Atribución obligatoria en el plugin.*
