# API FEWS IDEAM — Guía de Fuentes de Datos para Nariño

**Contrato:** GN0384-2026  
**Secretaría TIC, Innovación y Gobierno Abierto — Gobernación de Nariño**  
**Fecha:** 2026-06-17

---

## Contexto

El Visor FEWS de IDEAM (`fews.ideam.gov.co/visorfews/nacional`) es una aplicación React que consume dos tipos de fuentes:

1. **Archivos JSON estáticos** servidos por nginx desde el propio servidor FEWS de IDEAM.
2. **API REST Socrata** del portal datos.gov.co, con datasets históricos y cuasi-real.

No existe una API REST dinámica tipo Delft-FEWS PI Service expuesta públicamente. Todo el consumo de datos se hace mediante fetch a rutas de archivos estáticos o a endpoints Socrata.

---

## Fuente 1 — FEWS IDEAM (archivos estáticos)

**Base URL:**
```
https://fews.ideam.gov.co/visorfews/data/
```

### 1.1 Catálogo y alertas en tiempo real

```
GET https://fews.ideam.gov.co/visorfews/data/ReporteTablaEstaciones.json
```

Archivo maestro. Contiene todas las estaciones activas del país con su estado de alerta actual.

**Campos principales:**

| Campo | Descripción |
|---|---|
| `codigoEstacion` | ID de la estación (ej: `0052065020`) |
| `nombreEstacion` | Nombre completo (ej: `BARBACOAS [52065020]`) |
| `departamento` | Filtrar por `"NARIÑO"` |
| `municipio` | Municipio donde está ubicada |
| `zonaHidrografica` | Cuenca hidrográfica (Patía, Telembí, Juanambú…) |
| `subzonaHidrografica` | Subcuenca |
| `categoria` | Pluviométrica, Limnimétrica, Climática… |
| `nivelAlerta` | `Normal` / `Alerta` / `Peligro` |
| `latitud` | Coordenada para Leaflet |
| `longitud` | Coordenada para Leaflet |
| `ultimoValor` | Último dato registrado |

> **Uso recomendado:** punto de entrada para el mapa Leaflet. Filtrar `departamento == "NARIÑO"` para obtener ~60–80 estaciones activas. Los campos `latitud`, `longitud` y `nivelAlerta` son suficientes para renderizar marcadores con color de alerta.

---

### 1.2 Series de tiempo por estación

El patrón de URL es:

```
GET https://fews.ideam.gov.co/visorfews/data/series/json{TIPO}/{codigoEstacion}.json
```

Donde `{TIPO}` es una letra que identifica la variable:

| Tipo | Variable | Prioridad Nariño |
|---|---|---|
| `H` | Nivel hidrológico | ★★★ crítico inundaciones |
| `P` | Precipitación (lluvia) | ★★★ crítico Nariño |
| `T` | Temperatura del aire | ★★ correlación ENSO |
| `Q` | Caudal | ★★ ríos principales |

**Ejemplos:**
```
https://fews.ideam.gov.co/visorfews/data/series/jsonH/0052065020.json
https://fews.ideam.gov.co/visorfews/data/series/jsonP/0052065020.json
https://fews.ideam.gov.co/visorfews/data/series/jsonT/0052065020.json
```

**Campos de la serie de tiempo:**

| Campo | Descripción |
|---|---|
| `fecha` | Timestamp de la medición |
| `valorObservado` | Valor registrado por el observador |
| `valorSensor` | Valor del sensor automático |
| `umbralAlerta` | Nivel a partir del cual se activa alerta |
| `umbralPeligro` | Nivel de peligro inminente |

> **Nota:** los campos `umbralAlerta` y `umbralPeligro` solo están presentes en series de nivel hidrológico (`jsonH`). Son los umbrales definidos por la UNGRD para cada cuenca.

**Estaciones de Nariño confirmadas** (observadas en DevTools):

| Código | Nombre | Tipo |
|---|---|---|
| `0052065020` | BARBACOAS | Pluviométrica — Río Telembí |
| `0047015040` | (por confirmar) | — |
| `0044017120` | (por confirmar) | — |
| `0052077010` | (por confirmar) | — |
| `0052077020` | (por confirmar) | — |

---

## Fuente 2 — datos.gov.co (API Socrata/SODA)

**Base URL:**
```
https://www.datos.gov.co/resource/
```

No requiere autenticación para lectura. Sin `app_token` hay throttling estricto; para producción se recomienda registrar un token gratuito en datos.gov.co.

**Sintaxis de filtrado (SoQL):**
```
?$where=departamento='NARIÑO' AND fechaobservacion > '2026-01-01'
?$limit=1000
?$select=fechaobservacion,valorobservado,codigoestacion
```

---

### 2.1 Catálogo nacional de estaciones

```
GET https://www.datos.gov.co/resource/hp9r-jxuu.json?DEPARTAMENTO=NARIÑO&$limit=500
```

Dataset estático (2016, actualización ocasional). Útil para obtener el listado oficial de estaciones activas en Nariño y cruzar códigos con FEWS.

**Campos principales:**

| Campo | Descripción |
|---|---|
| `codigo` | ID oficial de la estación |
| `nombre` | Nombre de la estación |
| `categoria` | Limnimétrica, Climática, Agrometeorológica… |
| `estado` | `Activa` / `Suspendida` / `En mantenimiento` |
| `ubicaci_n` | Objeto anidado con `latitude` y `longitude` |
| `departamento` | Para filtrar `NARIÑO` |
| `municipio` | Municipio |

**Extracción de coordenadas (JavaScript):**
```javascript
var lat = parseFloat(estacion["ubicaci_n"]["latitude"]);
var lon = parseFloat(estacion["ubicaci_n"]["longitude"]);
```

---

### 2.2 Temperatura histórica

```
GET https://www.datos.gov.co/resource/sbwg-7ju4.json
```

**Filtro recomendado para Nariño:**
```
?$where=departamento='NARIÑO' AND fechaobservacion > '2025-01-01'&$limit=5000
```

**Campos:**

| Campo | Descripción |
|---|---|
| `fechaobservacion` | Datetime ISO — convertir a `Date` |
| `valorobservado` | Temperatura en °C (string → float) |
| `codigoestacion` | ID estación |
| `nombreestacion` | Nombre |
| `departamento` | Para filtrar |
| `municipio` | Municipio |
| `zonahidrografica` | Cuenca |

---

### 2.3 Precipitación histórica

```
GET https://www.datos.gov.co/resource/s54a-sgyg.json
```

**Filtro recomendado:**
```
?$where=departamento='NARIÑO' AND fechaobservacion > '2025-01-01'&$limit=5000
```

**Campos:**

| Campo | Descripción |
|---|---|
| `fechaobservacion` | Datetime ISO |
| `valorobservado` | Precipitación acumulada en mm (string → float) |
| `codigoestacion` | ID estación |
| `nombreestacion` | Nombre |
| `departamento` | Para filtrar |
| `municipio` | Municipio |
| `zonahidrografica` | Cuenca |

---

### 2.4 Datos cuasi-real (todos los sensores) ★★★

```
GET https://www.datos.gov.co/resource/57sv-p2fu.json
```

El dataset más valioso para monitoreo en tiempo casi-real. Agrupa todos los tipos de sensores en una sola tabla. Solo contiene observaciones recientes (últimos días).

**Campos:**

| Campo | Descripción |
|---|---|
| `fechaobservacion` | Timestamp reciente |
| `valorobservado` | Valor del sensor (string → float) |
| `codigoestacion` | ID estación |
| `codigosensor` | Tipo de variable medida (ver tabla) |
| `departamento` | Para filtrar `NARIÑO` |
| `municipio` | Municipio |

**Códigos de sensor relevantes:**

| `codigosensor` | Variable |
|---|---|
| `0061` | Nivel hidrológico |
| `0071` | Temperatura del aire |
| `0086` | Precipitación |
| `0011` | Humedad relativa |

**Ejemplo de consulta para estaciones Nariño — precipitación últimas 48h:**
```
?$where=departamento='NARIÑO' 
  AND codigosensor='0086'
  AND fechaobservacion > '2026-06-15T00:00:00'
&$limit=2000
```

---

## Proxy PHP para WordPress

Las llamadas desde el plugin deben ir por el servidor (CORS restrictivo en FEWS). Usar `wp_remote_get` con `sslverify => false`.

```php
function gn_fews_proxy() {
    $tipo = sanitize_text_field( $_GET['tipo'] ?? 'H' );
    $cod  = sanitize_text_field( $_GET['cod']  ?? '' );

    if ( $cod ) {
        $url = "https://fews.ideam.gov.co/visorfews/data/series/json{$tipo}/{$cod}.json";
    } else {
        $url = "https://fews.ideam.gov.co/visorfews/data/ReporteTablaEstaciones.json";
    }

    $res  = wp_remote_get( $url, array( 'sslverify' => false, 'timeout' => 15 ) );
    $body = wp_remote_retrieve_body( $res );

    header( 'Content-Type: application/json; charset=utf-8' );
    wp_send_json( json_decode( $body ) );
}
add_action( 'wp_ajax_nopriv_gn_fews', 'gn_fews_proxy' );
add_action( 'wp_ajax_gn_fews',        'gn_fews_proxy' );
```

**Llamada desde el front-end (ES5 compatible):**
```javascript
jQuery.getJSON(
    ajaxurl + '?action=gn_fews&cod=0052065020&tipo=H',
    function( data ) {
        // data es el array de la serie de tiempo
        console.log( data );
    }
);
```

**Para datos cuasi-real (Socrata — sin CORS, puede llamarse directo):**
```javascript
var url = 'https://www.datos.gov.co/resource/57sv-p2fu.json'
        + '?$where=departamento=%27NARI%C3%91O%27'
        + '%20AND%20codigosensor=%270086%27'
        + '&$limit=1000';

jQuery.getJSON( url, function( data ) {
    console.log( data );
});
```

---

## Estrategia de integración — Monitor Ambiental Nariño

| Capa | Fuente | Frecuencia |
|---|---|---|
| Mapa Leaflet con marcadores de alerta | `ReporteTablaEstaciones.json` (FEWS) | Cada 30 min |
| Gráfica de nivel por estación | `jsonH/{cod}.json` (FEWS) | Al hacer clic |
| Gráfica de lluvia por estación | `jsonP/{cod}.json` (FEWS) | Al hacer clic |
| Panel últimas 24h | `57sv-p2fu` (Socrata NRT) | Cada hora |
| Análisis histórico / ENSO | `s54a-sgyg` + `sbwg-7ju4` (Socrata) | Bajo demanda |

---

## Referencias

- Visor FEWS IDEAM: https://fews.ideam.gov.co/visorfews/nacional
- Catálogo estaciones IDEAM: https://www.datos.gov.co/d/hp9r-jxuu
- Dataset NRT: https://www.datos.gov.co/d/57sv-p2fu
- Temperatura histórica: https://www.datos.gov.co/d/sbwg-7ju4
- Precipitación histórica: https://www.datos.gov.co/d/s54a-sgyg
- Documentación SODA API: https://dev.socrata.com/
- Estaciones Nariño (vista filtrada): https://www.datos.gov.co/d/wnau-q7ys
