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

Sin proceso de build: D3, D3plus, Leaflet y Three.js se cargan por CDN.

---

## Shortcodes

| Shortcode | Qué muestra | Atributos |
|-----------|-------------|-----------|
| `[man_estado]` | Semáforo ENSO + condiciones (gauge D3 + texto) | `municipio`, `compacto` |
| `[man_pronostico]` | Pronóstico 7–16 días (Open-Meteo en vivo) | `municipio`, `dias` |
| `[man_mapa]` | Coroplético de los 64 municipios + panel al clic | `variable`, `mes` |
| `[man_globo]` | Globo 3D cinematográfico (Three.js) | `calidad`, `autorotar` |
| `[man_timeline]` | Slider de meses ONI que controla el globo | `inicio`, `fin` |
| `[man_prediccion]` | **Predicción del ONI hasta feb-2027** (línea + banda de incertidumbre + umbrales de fase + probabilidad por trimestre + texto predictivo). Modelo propio del plugin contrastado con el ensamble NOAA/IRI | `hasta`, `modelo`, `probabilidad` |
| `[man_datos]` | Botón de datos abiertos (JSON/CSV/Ver API) | `recurso`, `municipio`, `mes`, `texto` |
| `[man_historico]` | Episodios ENSO 2015–2024 (barras ONI pico + tarjetas) | `desde`, `hasta` |
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
[man_prediccion hasta="2027-02"]
[man_prediccion hasta="2027-02" modelo="no" probabilidad="si"]
[man_datos recurso="municipios" texto="Descarga el riesgo por municipio"]
[man_datos recurso="prediccion" texto="Descarga la predicción del ONI"]
```

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
GET /wp-json/man/v1/abierto/oni?formato=csv
GET /wp-json/man/v1/abierto/prediccion?hasta=2027-02&formato=json
GET /wp-json/man/v1/abierto/52001?formato=json
```

El shortcode `[man_datos]` genera los botones de **Descargar JSON**, **Descargar CSV**, **Ver API** y **Copiar URL** (recursos: `municipios`, `oni`, `prediccion`, `municipio`).

REST interna (para el front): `/wp-json/man/v1/municipio/{divipola}`, `/departamento`, `/oni`, `/prediccion`, `/historico`, `/estado-apis`.

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
