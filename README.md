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
Al empaquetar, `.distignore` marca lo que no forma parte del plugin instalable
(el prototipo `mockup-oni/`, `docs/`, `tests/` y las herramientas de desarrollo).

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
| `[man_prediccion]` | **Predicción del ONI** a 9 meses vista desde el último dato observado (línea + banda de incertidumbre + umbrales de fase + probabilidad por trimestre + texto predictivo). Modelo propio del plugin contrastado con el pronóstico oficial NOAA/CPC | `hasta`, `modelo`, `probabilidad` |
| `[man_datos]` | Botón de datos abiertos (JSON/CSV/Ver API) | `recurso`, `municipio`, `mes`, `texto` |
| `[man_historico]` | Episodios ENSO 2015–2024 (barras ONI pico, **interactivo vía el motor D3plus**: tooltip, leyenda, cambiar tipo) | `alto`, `theme` |
| `[man_mar]` | Oleaje Pacífico (Open-Meteo Marine) + nivel del mar (IOC) | `estacion` |
| `[man_salud]` | **Enfermedades sensibles al clima** (SIVIGILA/INS): cifras, serie anual, reparto por enfermedad y municipios más afectados | `grupo` (`ETV`/`ETA`) |
| `[man_hidrico]` | Caudal de ríos (GloFAS) + humedad de suelo | `municipio` |
| `[man_estado_api]` | Panel público de salud de las APIs | — |
| `[man_asistente]` | **Asistente de IA**: botón «Asistente» que abre una ventana para preguntar sobre los datos del sitio, por texto o por voz | `municipio`, `voz`, `etiqueta` |

`municipio` admite código DIVIPOLA (`52001`) o nombre, o `departamento` para el agregado.

**Ejemplos**
```
[man_estado municipio="52835"]
[man_pronostico municipio="52001" dias="14"]
[man_mapa variable="riesgo" mes="2026-10"]
[man_globo calidad="alta"] [man_timeline]
[man_animacion estado="el_nino"]
[man_estadisticas tipo="oni"]
[man_estadisticas tipo="probabilidad"] [man_estadisticas tipo="riesgo"]
[man_prediccion]                       # horizonte móvil (9 meses)
[man_prediccion hasta="2027-06"]       # o un mes objetivo concreto
[man_prediccion modelo="no" probabilidad="si"]
[man_datos recurso="municipios" texto="Descarga el riesgo por municipio"]
[man_datos recurso="prediccion" texto="Descarga la predicción del ONI"]
```

### Motor de gráficos D3plus (`[man_grafico]`)
Motor genérico de **3 capas** (ver el archivo [`skill`](skill)): el shortcode emite solo un `<figure>` con `data-*` (cacheable, sin datos en el HTML) → `grafico.js` pide `/wp-json/man/v1/render?view=…&type=…` → `renderer.js` elige la clase D3plus y dibuja. Trae barra de herramientas (Detalle · Compartir · Datos · Imagen PNG · Descarga JSON · **Cambiar tipo en vivo**), modales, temas claro/oscuro y tokens `--man-g-*`.

**Vistas disponibles** (`view`): `oni_serie`, `prob_fase`, `riesgo_subregion`, `riesgo_municipios`, `episodios`, `salud_anual`, `salud_eventos`, `salud_estacional`, `salud_municipios`.
**Tipos** (`type`): `bar`, `stacked_bar`, `line`, `area`, `stacked_area`, `pie`, `donut`, `treemap`, `box_whisker` (se restringe a los compatibles con la vista).
```
[man_grafico view="oni_serie" type="line"]
[man_grafico view="riesgo_subregion" type="treemap" theme="oscuro"]
[man_grafico view="prob_fase" type="stacked_bar" actions="datos,imagen,cambiar"]
[man_grafico view="salud_anual" type="stacked_area"]
[man_grafico view="salud_eventos" type="treemap"]
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
NOAA ±0,5 °C. Todo se contrasta con el **pronóstico oficial de NOAA/CPC** y se
comunica siempre la incertidumbre (no son certezas). Lógica auditable en
`includes/analysis/class-man-forecast.php` y `class-man-texto.php`.

**Datos frescos, no semillas (desde 1.38.0).** La línea central es el modelo
recalculado sobre el **ONI observado más reciente** de NOAA; el escenario de
planeación sembrado se dibuja aparte como línea de contraste. La predicción se
materializa en caché con **TTL de 24 h** y cada sincronización del ONI o del
pronóstico oficial **la invalida**, de modo que se rehace con los datos del día.
El mes objetivo es un **horizonte móvil** de 9 meses desde el último observado
(9 trimestres solapados, el mismo alcance que publica NOAA/CPC): no hay fechas
fijas que caduquen. Puede fijarse uno concreto con `hasta="AAAA-MM"`.

### Salud sensible al clima (SIVIGILA/INS)
`[man_salud]` muestra los eventos de notificación obligatoria de Nariño que
responden a las condiciones climáticas, agrupados en dos familias:

- **ETV — transmitidas por vectores.** El calor acelera la reproducción del
  mosquito y acorta el ciclo de incubación; la sequía multiplica los criaderos
  por el almacenamiento doméstico de agua. Incluye dengue y dengue grave,
  chikunguña, zika, leishmaniasis cutánea y mucosa, y malaria en todas sus
  formas (falcíparum, vivax, malariae, mixta y complicada).
- **ETA — transmitidas por agua y alimentos.** La escasez de agua potable por
  sequía y la contaminación de fuentes por inundación favorecen su transmisión.
  Incluye fiebre tifoidea y paratifoidea, y hepatitis A.

La **mortalidad por dengue y por malaria** se contabiliza aparte y nunca se suma
a la incidencia.

```
[man_salud]                  # ETV + ETA
[man_salud grupo="ETV"]      # solo vectores
[man_salud grupo="ETA"]      # solo agua y alimentos
```

**Fuente:** dataset `4hyg-wa9d` de datos.gov.co, filtrado por `cod_dpto_o=52`,
con una fila por evento, año, semana epidemiológica y municipio. La agregación
se hace en el servidor con SoQL, así que cada sincronización descarga unos pocos
kilobytes en vez de las ~100.000 filas del departamento. Cobertura **2007–2022**.

> **Cómo leerlo.** Son casos notificados, con el rezago propio de la vigilancia
> epidemiológica: no son un efecto directo ni inmediato del ENSO. El clima altera
> la abundancia del vector y la calidad del agua, pero la incidencia depende
> además del control vectorial, la minería, la movilidad y el acceso a agua
> potable. En Nariño la carga se concentra en el litoral Pacífico, donde la
> malaria es endémica.

### Asistente de IA (módulo opcional)
Un asistente que responde preguntas de la ciudadanía **solo con los datos que
este plugin publica** (ONI, predicción, riesgo por municipio, alertas del IDEAM,
salud y focos de calor). No consulta la base de datos ni navega por internet: el
servidor le entrega un resumen del estado vigente y el modelo redacta sobre eso.

Se configura en **Monitor Ambiental → Asistente IA**:

- **Proveedor**: Claude (Anthropic), ChatGPT (OpenAI) o Gemini (Google). Se
  configura el que se quiera y solo trabaja el seleccionado.
- **Voz**: ElevenLabs, con su **clave y su ID de voz**. Si se activa, cada
  respuesta trae un botón para escucharla.
- **Dos interruptores independientes**: *Activar* (el asistente responde) y
  *Visible en el sitio* (se pinta en el front). Permite dejarlo configurado y
  probado pero oculto hasta decidir publicarlo.
- **Topes de uso**: consultas por visitante y hora, y tope diario global.
- **Presentación**: nombre del anfitrión, saludo inicial y preguntas sugeridas
  (una por línea, hasta 8). Si se dejan vacíos, usa textos de ejemplo.

```
[man_asistente]                          # botón «Asistente» + ventana
[man_asistente etiqueta="Pregúntame"]    # otro texto en el botón
[man_asistente municipio="52835"]        # enfocado en un municipio
[man_asistente voz="no"]                 # solo texto
[man_globo asistente="no"]               # globo sin el asistente encima
```

**La ventana.** El botón «Asistente» abre una ventana con el nombre del
anfitrión, su saludo, las preguntas sugeridas («Puedes preguntar:») y la barra
de entrada: **micrófono** · campo «Escribe tu pregunta…» con la **flecha de
enviar** dentro · **detener** (corta la consulta o el audio en curso) ·
**cerrar**. Se cierra también con Escape. Los iconos son SVG en línea que toman
el color del tema, y todos los botones miden al menos 44 px para el dedo; en
móvil, *cerrar* sube a la esquina para dejarle sitio al campo de texto.

**Interacción por voz.** Hablarle usa el micrófono del navegador (Chrome, Edge y
Android; en Firefox e iOS el botón no aparece y queda el texto), así que el audio
del visitante **no sale de su equipo** ni consume API. La respuesta hablada sí la
sintetiza ElevenLabs desde el servidor, y se guarda en caché por texto: una misma
respuesta no se vuelve a pagar.

**Seguridad y costo.** Las claves se guardan cifradas con `sodium_crypto_secretbox`
y nunca llegan al navegador: el front habla con la REST del plugin
(`/wp-json/man/v1/ai/consulta` y `/ai/voz`), que actúa de intermediaria. Como el
endpoint es público y cada consulta cuesta dinero, hay cupo por IP, tope diario y
caché de respuestas repetidas. El prompt del sistema trata los datos como
información, no como instrucciones, y limita al asistente a su dominio.

**Si algo falla.** *Monitor Ambiental → Asistente IA* trae dos bloques al final:
**Diagnóstico**, que recorre toda la cadena en orden (interruptores, clave,
modelo, salida a internet del servidor, cifrado, voz y cupos) y señala el primer
punto roto con qué hacer para arreglarlo; y **Registro de errores**, con el
código HTTP y el motivo exacto que devolvió el proveedor, incluida su respuesta
cruda. En el sitio público el detalle también se escribe en la **consola del
navegador** (F12 → Consola); con sesión de administrador aparece el motivo
técnico, y sin ella solo el mensaje general. Las claves nunca entran ni al
registro ni a la consola.

**Códigos MAN-AI.** Cada fallo posible tiene un código estable, el mismo en la
consola, en el registro y en la tabla *Códigos de error* del panel, que dice
qué pasó y qué hacer:

| Rango | Dónde se rompió | Ejemplos |
|---|---|---|
| `MAN-AI-0xx` | Configuración del panel | 001 módulo desactivado · 002 sin clave · 003 clave ilegible |
| `MAN-AI-1xx` | Salida a internet del servidor | 101 conexión bloqueada · 102 tiempo agotado |
| `MAN-AI-2xx` | El proveedor de IA devolvió error | 201 clave rechazada · 204 sin saldo · 206 caída del proveedor |
| `MAN-AI-3xx` | Respuesta inutilizable | 301 no es JSON · 303 el modelo declinó |
| `MAN-AI-4xx` | Cupos propios | 401 cupo por hora · 402 tope diario |
| `MAN-AI-5xx` | Voz (ElevenLabs) | 503 petición rechazada · 504 el ID de voz no existe |
| `MAN-AI-6xx` | Navegador del visitante | 602 micrófono denegado · 604 la REST no responde · 605 tiempo de espera |

Al visitante nunca se le muestra el código ni el texto técnico del navegador
(«Failed to fetch»): recibe una frase en español y el código queda en la consola.

> El asistente **no es un canal oficial de alertas**. Los textos del componente
> lo advierten y remiten al IDEAM, la UNGRD y la línea 123.

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
GET /wp-json/man/v1/abierto/prediccion?formato=json                # horizonte móvil
GET /wp-json/man/v1/abierto/prediccion?hasta=2027-06&formato=json  # mes objetivo fijo
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

Sanitización (lista blanca de 64 DIVIPOLA + bounding-box de Nariño), escape de
salida, nonces, capacidades, rate-limit por IP con ventana fija, claves de API
cifradas con `sodium_crypto_secretbox` y verificación TLS activa salvo donde el
panel la desactive de forma explícita (hoy solo IDEAM/FEWS, cuyo certificado
presenta la cadena incompleta).

Los endpoints que consultan servidores externos bajo demanda tienen **cupo propio
y caché de fallos**, para no convertirse en amplificadores de carga contra las
fuentes estatales. Tras un proxy o CDN, resuelva la IP real del visitante con el
filtro `man_ip_cliente` (debe devolver una IP ya validada); si no, todos los
visitantes comparten el mismo cupo.

Auditoría del sistema y backlog de mejoras:
[`docs/auditoria/`](docs/auditoria/2026-08-26-auditoria-v1.38.0.md).

---

## Fuentes y atribución (obligatoria)

NOAA/CPC · IRI · IDEAM (FEWS) · **Open-Meteo (CC BY 4.0, incluye reanálisis ERA5)** · IOC/VLIZ Sea Level · **INS/SIVIGILA (datos.gov.co)** · NASA FIRMS · DANE (cartografía).

> Los escenarios de planeación (semillas JSON) son ilustrativos, no pronósticos oficiales. Verificar contra boletines vigentes de IDEAM y NOAA-CPC.
