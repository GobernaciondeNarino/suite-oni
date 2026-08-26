# Auditoría del sistema — v1.38.0

**Fecha:** 26 de agosto de 2026 · **Versión auditada:** 1.37.1 → **1.38.0**
**Alcance:** 30 archivos PHP (~9.700 líneas), 24 archivos JavaScript, 8 conectores
de API, 15 endpoints REST y la tubería de predicción del ONI.
**Método:** revisión de código en tres frentes (seguridad y corrección, calidad y
duplicación, salud de APIs), pruebas en vivo contra cada API externa y pruebas
automatizadas del parser (`php tests/test-fase1.php`).

---

## 1. Resumen ejecutivo

El hallazgo más grave no era una vulnerabilidad, sino un **fallo silencioso de
datos**: la fuente del pronóstico oficial de NOAA/CPC llevaba tiempo devolviendo
HTTP 200 sin datos utilizables, y el sistema caía a escenarios sembrados con
fecha de corte de **junio de 2026** sin que nada lo advirtiera. La página pública
mostraba como pronóstico una curva congelada, etiquetada además como "Ensamble
NOAA/IRI".

Se corrigió la fuente, el parser y el orden de prioridades: **hoy la predicción
se calcula con el ONI observado más reciente y se renueva a diario**.

| Frente | Hallazgos | Corregidos en 1.38.0 | Pendientes |
|---|---|---|---|
| Seguridad | 17 | 12 | 5 |
| Corrección / datos | 8 | 8 | 0 |
| Calidad y duplicación | 20 | 11 | 9 |
| **Total** | **45** | **31** | **14** |

> **Actualización 1.39.0.** Se cerraron **A-3** (dataset de SIVIGILA identificado y fuente activa) y **A-7** (atribución de climatología corregida).
>
> **Actualización 1.40.0.** Se cerraron **A-1** (procedencia de datos visible para la ciudadanía y el operador) y **A-2** (aviso de clave ilegible), y se corrigieron dos fallos detectados al revisar NASA FIRMS: «Probar conexión» daba por buena cualquier respuesta HTTP 200 —el mismo mecanismo que ocultó el fallo de NOAA— y un conteo real de cero focos se etiquetaba como «modelado». Quedan **10 pendientes**, todas de prioridad B y C.

Vulnerabilidades **críticas: 0**. Se verificó explícitamente que no hay SQL sin
`prepare`, `unserialize`, handlers de administración sin nonce ni capacidad, ni
salida sin escapar.

---

## 2. Salud de las APIs (verificada en vivo el 26/08/2026)

| API | Estado | Latencia | Nota |
|---|---|---|---|
| NOAA/CPC — ONI (`oni.ascii.txt`) | Operativa | 0,63 s | Serie completa desde 1950 |
| NOAA/CPC — Niño 3.4 semanal | Operativa | 0,72 s | |
| NOAA/CPC — probabilidades ENSO | **Reparada** | 0,66 s | Estaba caída de forma silenciosa (ver §3.1) |
| IDEAM — FEWS (estaciones, series, SZH) | Operativa | 1,1–2,7 s | Cadena de certificados incompleta (ver §3.6) |
| IOC Sea Level (Tumaco `tumc2`) | Operativa | 1,2 s | |
| Open-Meteo — Forecast / Marine / Flood / Archive | Operativas | 0,53–0,81 s | Sin clave, CORS directo |
| OpenStreetMap y CDNs (jsdelivr, unpkg) | Operativas | 0,18–0,27 s | |
| SIVIGILA vía datos.gov.co | No verificable | 0,58 s | Inactiva por defecto: falta `dataset_id` vigente |
| NASA FIRMS | No verificable | — | Inactiva por defecto: requiere MAP_KEY gratuita |

**NASA POWER** aparece citada en el panel de administración pero **no se
consume**: no existe ninguna llamada a esa API en el código. Conviene retirar la
mención o implementar la fuente (ver backlog A-7).

---

## 3. Hallazgos corregidos en 1.38.0

### 3.1 El pronóstico oficial llevaba tiempo caído sin avisar (crítico para el dato)
La URL configurada (`…/roni/probabilities.php`) es hoy un *stub* de 256 bytes con
`<meta http-equiv="refresh">`. `wp_remote_get` sigue redirecciones 3xx, pero no
meta-refresh, así que recibía HTML sin tabla: **HTTP 200 engañoso**.
Aunque hubiera llegado a la página real, el parser tampoco habría funcionado:
exigía «trigrama + año» (`JAS 2026`) cuando la página publica el trigrama **sin
año**, y asumía el orden El Niño · Neutral · La Niña cuando el orden real es
**La Niña · Neutral · El Niño** — es decir, de haber casado, habría invertido los
datos.
**Corregido:** URL con barra final (más migración de la opción ya guardada),
detección del orden de columnas desde la cabecera de la tabla e inferencia del
año desde la fecha de emisión. Cubierto por pruebas.

### 3.2 La predicción servía escenarios congelados de junio de 2026
La trayectoria central de cada mes proyectado se tomaba de la semilla
(`$ens_central`) y el modelo propio quedaba relegado a una línea secundaria;
el riesgo por municipio también prefería la semilla al cálculo con datos vivos.
**Corregido:** la central es ahora el modelo recalculado sobre el ONI observado
más reciente; la semilla pasa a ser una línea de contraste explícita
(`escenario_oni`, "Escenario de planeación") y el riesgo se calcula con el ONI y
el déficit hídrico vigentes. La leyenda ya no atribuye a NOAA/IRI una curva
calculada localmente.

### 3.3 «Bomba de tiempo»: la predicción se habría vaciado en marzo de 2027
El objetivo por defecto era la constante `'2027-02'`, repetida en 20 sitios.
Cuando el ONI observado la alcanzara, el bucle de proyección no habría producido
ningún mes y `/prediccion`, `/abierto/prediccion` y los shortcodes
`[man_prediccion*]` habrían devuelto series vacías **en silencio**.
**Corregido:** horizonte móvil de 9 meses desde el último dato observado
(`MAN_Rest::objetivo_por_defecto()`), expuesto también al front para que el
JavaScript no lleve su propia fecha.

### 3.4 La serie ONI se congelaba al agotarse la ventana sembrada
Los meses observados fuera de la ventana de la semilla se descartaban.
**Corregido:** se añaden (nunca hacia atrás, para no alterar el inicio de la
línea de tiempo).

### 3.5 Rate-limit que bloqueaba a usuarios legítimos
Cada petición permitida reescribía el *transient* con el TTL completo, así que la
ventana se estiraba indefinidamente: un tablero que sondeara sin pausa acababa
recibiendo 429 pese a mantenerse por debajo del límite por minuto.
**Corregido:** ventana fija codificada en la clave.

### 3.6 Verificación TLS que ignoraba la configuración
El *checkbox* «Verificar SSL» solo se respetaba en el cron; todas las consultas
en vivo desde REST desactivaban la verificación incondicionalmente.
**Corregido:** todos los caminos leen la configuración (`MAN_Rest::ssl_ideam()`).
Se confirmó en vivo que el certificado de `fews.ideam.gov.co` tiene la **cadena
incompleta** (`unable to get local issuer certificate`), lo que justifica
mantener su excepción; `datos.gov.co` e `ioc-sealevelmonitoring.org` pasan a
verificar por defecto, porque sus certificados son válidos.

### 3.7 Amplificación de peticiones contra un servidor estatal
`/estacion-serie` aceptaba cualquier código inventado y **no cacheaba los
fallos**, de modo que cada código distinto disparaba una petición de hasta 15 s
contra IDEAM en cada visita. Lo mismo ocurría en las rutas de estaciones, capas
del mapa, subzonas (~8 MB) e histórico multi-API, que solo cacheaban cuando la
respuesta traía datos.
**Corregido:** cupo propio de 20 peticiones/minuto y caché de resultados vacíos
con TTL corto (5–10 min).

### 3.8 El municipio de Nariño recibía la alerta de cualquier otro
`buscar_alerta_ideam()` comparaba el nombre contra el registro entero
serializado; como todas las filas de FEWS contienen `depart: "Nariño"`, el
municipio de Nariño (DIVIPOLA 52480) casaba con **todas** y se le atribuía
siempre la primera alerta de la lista.
**Corregido:** comparación contra el campo `municipio`.

### 3.9 La alerta roja era inalcanzable
Se evaluaba el umbral naranja antes que el rojo (siendo el rojo mayor), y ambos
devolvían el mismo nivel: la gradación prometida en la interfaz se reducía a
normal/media/alta y el nivel máximo nunca se distinguía.
**Corregido:** orden de mayor a menor y nivel propio `maxima`, con su color.

### 3.10 Otros corregidos
- La **frecuencia por fuente** del panel no decidía nada: se sincronizaban todas
  en cada corrida. Ahora se respeta (con un margen del 10 % para el cron de WP).
- El **mes en curso** se calculaba en UTC: el último día de cada mes, entre las
  19:00 y medianoche hora de Colombia, el sistema saltaba a un mes sin datos.
- `/estaciones` y las capas del mapa **cacheaban formas distintas bajo la misma
  clave**: la respuesta del endpoint variaba según quién la poblara primero.
- `dataset_id` se borraba en guardados parciales (perdiendo el código `tumc2`).
- Los *assets* del panel se encolaban en cualquier página cuyo hook contuviera
  «man-».
- **Fuga de memoria del globo 3D:** sus listeners de `window` no se retiraban
  nunca, reteniendo toda la escena Three.js. Se añadió `destruir()`.
- Se dejaron de crear objetos `THREE.Color` **por partícula y por fotograma**, y
  los índices de columna del CSV de FIRMS se resuelven una vez, no por fila.
- **Peticiones sin límite de tiempo** en todo el front: una API colgada dejaba el
  *skeleton* girando indefinidamente. Ahora abortan a los 15 s.
- Duplicación: 9 copias de `limpiar()`, 4 de formateo de mes, 3 de color de fase,
  3 de copiado al portapapeles y 2 de color de alerta, unificadas en
  `man-core.js`.

---

## 4. Lista de auditoría — mejoras pendientes

Prioridad: **A** (alta, siguiente versión) · **B** (media) · **C** (deuda técnica).

### A — Alta

| # | Mejora | Dónde | Por qué importa |
|---|---|---|---|
| ~~A-1~~ | ~~Alertar cuando una fuente cae a semilla~~ — **resuelto en 1.40.0**: `MAN_Rest::procedencia()` distingue vivo/respaldo/ausente, con aviso en el panel, en el componente público y columna «Datos que sirve» | `class-man-rest.php`, `class-man-admin.php` | — |
| ~~A-2~~ | ~~Avisar cuando `descifrar()` falla~~ — **resuelto en 1.40.0**: la sincronización se detiene con un mensaje que nombra la causa y la solución | `class-man-sync.php` | — |
| ~~A-3~~ | ~~Fijar el `dataset_id` vigente de SIVIGILA~~ — **resuelto en 1.39.0**: dataset `4hyg-wa9d`, 15 eventos ETV/ETA, fuente activa | `class-man-sync-sivigila.php` | — |
| A-4 | Regenerar por cron la semilla municipal `predicciones_elnino_narino_2026.json` (corte jun-2026) | `data/`, nuevo conector | Sigue siendo el respaldo cuando no hay ONI sincronizado: conviene que no envejezca |
| A-5 | Botón «borrar clave de API» en el panel | `class-man-api-config.php:127-130` | Hoy una clave guardada no se puede eliminar desde la interfaz |
| A-6 | Documentar el filtro `man_ip_cliente` para sitios tras proxy o CDN | `SECURITY.md`, `README.md` | Sin él, todos los visitantes comparten cupo y reciben 429 |
| ~~A-7~~ | ~~Retirar la mención a NASA POWER~~ — **resuelto en 1.39.0**: la climatología se atribuye a ERA5 vía Open-Meteo, que es lo que realmente se consulta | `class-man-admin.php`, `README.md` | — |

### B — Media

| # | Mejora | Dónde | Por qué importa |
|---|---|---|---|
| B-1 | Trocear las funciones de más de 100 líneas (16 detectadas; las mayores: `mapa_apis_datos` 242, `construir_prediccion` 232, `catalogo_por_api` 212, `_loop` 170) | `class-man-admin.php`, `class-man-rest.php`, `globo.js` | Son las piezas más difíciles de revisar y donde se escondían varios de estos fallos |
| B-2 | Servir la tabla de 64 municipios desde PHP (`wp_localize_script`) en vez de mantenerla duplicada a mano en `municipios.js` | `class-man-municipios.php:44-109`, `municipios.js:4-68` | Dos copias de los mismos 64 registros que pueden divergir |
| B-3 | Clase base para los 7 conectores de sincronización (fetch + caché + error repetidos) | `includes/sync/` | Cada conector repite el mismo preludio y el mismo retorno de error |
| B-4 | Mover a `includes/data/textos-*.php` los textos largos incrustados en PHP y JS | `class-man-shortcodes.php:747-767`, `salud.js`, `pronostico.js`, `hidrico.js` | Hoy no son editables ni traducibles sin tocar código |
| B-5 | Sustituir la espera fija de 1,2 s por una señal real del bus de grupo | `grafico.js:56` | Carrera que puede provocar doble renderizado |
| B-6 | Extraer un helper común de Leaflet (mapa, teselas, contorno departamental) | `mapa.js`, `mapa-geo.js`, `estaciones.js` | Tres montajes casi idénticos |
| B-7 | Retirar los `transients` con `delete_transient()` en la desinstalación | `uninstall.php:31-35` | Con Redis/Memcached sobreviven al borrado por SQL |

### C — Deuda técnica

| # | Mejora | Dónde |
|---|---|---|
| C-1 | Decidir el destino de `mockup-oni/` (hoy excluido del paquete, aún en el repositorio) | `mockup-oni/` |
| C-2 | Unificar el idioma de la API interna del motor de gráficos (inglés) con el resto del plugin (español) | `grafico.js`, `renderer.js`, `grupo.js` |
| C-3 | Sincronizar la versión de la textura del globo entre PHP (`three-globe@2.31.0`) y su respaldo en JS (`@2.31.1`) | `class-man-shortcodes.php:271`, `globo.js:14` |
| C-4 | Documentar o retirar `/historico/oni` y `/pronostico/oni`, sin consumidores en el front | `class-man-rest.php:106-121` |
| C-5 | Eliminar el respaldo inalcanzable `svgLinea()` y las variables muertas (`void lineaProj`…) | `estaciones.js:145-165`, `prediccion.js` |
| C-6 | Ampliar la cobertura de pruebas a `MAN_Forecast` y `MAN_Risk` (hoy solo se prueban parser, geometría y déficit) | `tests/` |
| C-7 | Aprovechar los *workflows* recién instalados para que la revisión de seguridad corra en cada *pull request* | `.github/workflows/` |

---

## 5. Verificación de esta entrega

```bash
php tests/test-fase1.php     # 22 comprobaciones, incluidas 7 nuevas del parser oficial
php -l <cada archivo .php>   # sin errores de sintaxis
node --check <cada .js>      # sin errores de sintaxis
```

Las pruebas nuevas cubren el formato oficial vigente de NOAA/CPC (trigrama sin
año, columnas en orden La Niña · Neutral · El Niño), la inferencia del año desde
la fecha de emisión, el cruce de año en diciembre, el párrafo descriptivo que no
debe confundirse con la cabecera y la página caída que debe producir cero filas
para que actúe el respaldo. Se validaron además contra el HTML real descargado
de NOAA/CPC el 26/08/2026, que hoy publica 100 % de probabilidad de El Niño para
JAS 2026, decreciendo hasta 82 % en MAM 2027.

---

## 6. Recordatorio sobre el uso de estos datos

Los escenarios de planeación son ilustrativos y el modelo del plugin es una línea
base estadística transparente, **no un pronóstico oficial**. Toda comunicación
pública debe verificarse contra los boletines vigentes de IDEAM y NOAA-CPC.
