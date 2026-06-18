# Predicción analítica en cada card + saneamiento UI/UX — Plan de implementación

> **Para ejecutores:** Implementar tarea por tarea. Cada tarea deja el plugin funcional y verificable (`php -l`, `node --check`, prueba en vivo de APIs). **Subir la versión del plugin en cada cambio** (cabecera `Version:` + `MAN_VERSION`). No usar PowerShell `Set-Content` para fuentes; solo la herramienta de edición.

**Objetivo:** Que TODA card del catálogo (gráficos, mapas, componentes) ofrezca cuatro piezas reutilizables como shortcode — **descripción, análisis descriptivo (cualitativo), análisis cuantitativo y predicción** — donde la predicción se calcula con métodos de ciencia de datos transparentes y auditables. Además, cerrar las brechas de accesibilidad y responsive detectadas en la auditoría UI/UX del frontend.

**Arquitectura:** Se extiende el motor de vistas (`MAN_Views`) con un método `prediccion($id, $datos, $args)` que delega en una nueva clase `MAN_Predict` (construida sobre la `MAN_Forecast` existente: OLS, Holt amortiguado, gaussiana, bandas). La predicción se expone como una quinta pieza en `obtener()` y como shortcodes `[man_prediccion_dato view="X"]` / `[man_analisis_predictivo]`, y se inyecta en `tarjeta_elemento()` para que cada card del admin la liste. Los componentes-mapa y los `$info` reciben sus textos (descripción/cualitativo/cuantitativo/predicción) vía un registro `textos-elementos.php` ampliado y helpers de cómputo en vivo.

**Stack:** PHP 7.4 (namespace `GobernacionNarino\MonitorAmbiental`), shortcodes WP, D3plus v2 + Leaflet + Three.js por CDN (sin build), REST `/wp-json/man/v1`. Verificación: PHP portátil en `C:\Users\jobga\AppData\Local\Temp\phpchk\php.exe`, `node --check`, conteo UTF-8 con `preg_match_all('/./us', …)`.

---

## Métodos de ciencia de datos por categoría de vista

La predicción NO inventa datos: extrapola con incertidumbre explícita y se etiqueta siempre como estimación. Cada vista usa el método apropiado a su naturaleza.

| Categoría | Vistas | Método predictivo | Salida |
|---|---|---|---|
| ENSO temporal | `oni_serie`, `oni_observado`, `oni_pronostico` | Holt amortiguado (`MAN_Forecast::proyectar_oni`) + clasificación de fase gaussiana | Valor a +3 meses, fase más probable y %, banda 90% |
| Probabilístico | `prob_fase` | Integración gaussiana sobre umbrales ±0,5 °C (ya existe) | Trimestre y fase dominante con probabilidad |
| Series mensuales (escenario) | `deficit_serie`, `precip_caudal`, `focos_serie`, `cultivos_riesgo`, `acueductos`, `hidro_reduccion` | OLS (`regresion_lineal`) + estacionalidad mes-del-año + banda = σ_residual·√h | Próximos 3 meses, pendiente, R², banda |
| Anual multi-fuente | `historico_apis` | Regresión lineal sobre índice anual normalizado | Año siguiente proyectado + R² + tendencia |
| Estaciones FEWS observadas | `fews_nivel`, `fews_caudal`, `fews_precipitacion`, `fews_temperatura` | Persistencia con deriva (AR(1) aprox.) + probabilidad de cruce de umbral (`probabilidad_gaussiana`) | % de estaciones con probabilidad de superar umbral / tendencia regional |
| Estaciones FEWS simuladas | `fews_nivel_pronostico`, `fews_caudal_pronostico` | El valor ya es pronóstico del modelo FEWS → conteo por banda de alerta amarilla/naranja/roja | Nº de estaciones que el modelo proyecta en cada banda |
| Calidad | `fews_calidad` | Tendencia del ICA + clasificación de categoría proyectada | Categoría ICA esperada y estaciones en riesgo de bajar de banda |
| Subzonas | `fews_szh_alertas`, `fews_szh_pobs` | Escalamiento de alerta desde distribución actual + tendencia de precipitación regional | Subzonas con riesgo de subir de nivel |
| Riesgo / categóricas | `riesgo_municipios`, `riesgo_subregion`, `deficit_municipios`, `focos_municipios` | Regresión condicionada por ENSO: re-evaluar el modelo de riesgo (`MAN_Risk`) con el ONI proyectado del mes objetivo | Riesgo proyectado del top territorio en el horizonte |
| Mapas | `man_mapa`, `man_mapa_fews`, `man_mapa_geo`, `man_estaciones` | Estado proyectado al horizonte (coroplético del mes proyectado / nº esperado de estaciones en alerta) | Frase de proyección territorial |

Métodos base disponibles en `MAN_Forecast` (reutilizar, no reescribir): `regresion_lineal` (OLS, pendiente/intercepto/R²), `suavizar_media_movil`, `proyectar_oni` (Holt damped + reversión a la media + barrera de primavera boreal), `probabilidad_gaussiana`, `cdf_normal`, `banda_incertidumbre`, `sumar_meses`, `meses_entre`.

---

## Estructura de archivos

- **Crear** `includes/analysis/class-man-predict.php` — motor de predicción por categoría (devuelve un objeto `{metodo, horizonte, texto, valor, banda, prob}`); consume `MAN_Forecast` y `MAN_Risk`.
- **Modificar** `includes/data/class-man-views.php` — añadir `prediccion($id,$datos,$args)`, incluirla en `obtener()`, y un mapa estacional helper.
- **Modificar** `includes/data/textos-graficos.php` — añadir clave `prediccion` (texto base ≥? — la predicción es dinámica; el texto base es la *metodología*, ≥200 car.) por vista. (La cifra la calcula `MAN_Predict`; el párrafo explica el método.)
- **Modificar** `includes/shortcodes/class-man-shortcodes.php` — registrar `[man_prediccion_dato]` y `[man_analisis_predictivo]`; soportar `modo='prediccion'` en `bloque_analisis`; añadir textos de predicción a los componentes-mapa.
- **Modificar** `includes/data/textos-elementos.php` — añadir `cuantitativo` y `prediccion` a los 10 elementos `$info` y a `man_mapa_fews` / `man_mapa_geo` / `man_estaciones`.
- **Modificar** `includes/admin/class-man-admin.php` — `tarjeta_elemento()`: añadir la pieza **Predicción** a las cards de gráfico y a los componentes; `$info()` y `$s` que generen las 4 piezas.
- **Modificar** `includes/class-man-rest.php` — ruta `/render` (o `/vistas`) debe incluir `prediccion` en el payload de la vista para que el front la consuma.
- **Modificar** `assets/js/grafico.js` / `renderer.js` — render de la pieza predicción cuando `modo='prediccion'`.
- **UI/UX** `assets/js/timeline.js`, `globo.js`, `animacion.js`, `assets/css/estilos.css`, `assets/css/grafico.css` — correcciones de la auditoría.
- **Verificación** scripts node ad-hoc contra las APIs FEWS/Open-Meteo para validar cifras.

---

## FASE 0 — Motor de predicción (núcleo)

### Tarea 0.1: `MAN_Predict` con la firma y el caso ENSO

**Archivos:**
- Crear: `includes/analysis/class-man-predict.php`
- Modificar (carga): `includes/class-man-plugin.php` (añadir el require junto a las demás clases de `includes/analysis/`)

- [ ] **Paso 1: Crear la clase con el dispatcher por categoría y el caso ENSO.**

```php
<?php
/**
 * Motor de predicción por vista. Calcula una estimación a futuro con
 * incertidumbre explícita, reutilizando MAN_Forecast (OLS, Holt amortiguado,
 * gaussiana). Cada método es transparente y auditable; NO sustituye pronósticos
 * oficiales y SIEMPRE comunica el método y la banda.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Predict {

	/**
	 * Predicción para una serie temporal numérica por OLS + estacionalidad.
	 *
	 * @param array $valores Valores cronológicos.
	 * @param array $meses   Etiquetas AAAA-MM paralelas (para estacionalidad), opcional.
	 * @param int   $h       Horizonte (pasos a futuro).
	 * @return array {metodo, horizonte, proyeccion[], pendiente, r2, banda}
	 */
	public static function serie_temporal( array $valores, array $meses = array(), $h = 3 ) {
		$reg = MAN_Forecast::regresion_lineal( $valores );
		$n   = count( $valores );
		// Desviación de los residuos para la banda.
		$res = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$est  = $reg['intercepto'] + $reg['pendiente'] * $i;
			$res += ( $valores[ $i ] - $est ) * ( $valores[ $i ] - $est );
		}
		$sigma = $n > 2 ? sqrt( $res / ( $n - 2 ) ) : 0.0;
		$proy  = array();
		for ( $k = 1; $k <= $h; $k++ ) {
			$x          = $n - 1 + $k;
			$v          = $reg['intercepto'] + $reg['pendiente'] * $x;
			$b          = $sigma * sqrt( $k );
			$proy[]     = array( 'paso' => $k, 'valor' => round( $v, 2 ), 'banda_min' => round( $v - 1.645 * $b, 2 ), 'banda_max' => round( $v + 1.645 * $b, 2 ) );
		}
		return array(
			'metodo'     => 'Regresión lineal (OLS) con banda de incertidumbre 90%',
			'horizonte'  => $h,
			'proyeccion' => $proy,
			'pendiente'  => round( $reg['pendiente'], 4 ),
			'r2'         => $reg['r2'],
			'sigma'      => round( $sigma, 3 ),
		);
	}

	/**
	 * Probabilidad de que un valor supere un umbral, dada una incertidumbre.
	 *
	 * @param float $valor  Valor actual/proyectado.
	 * @param float $umbral Umbral de alerta.
	 * @param float $sigma  Incertidumbre.
	 * @return float Probabilidad 0–100.
	 */
	public static function prob_supera_umbral( $valor, $umbral, $sigma ) {
		$s = max( 0.01, (float) $sigma );
		$z = ( (float) $umbral - (float) $valor ) / $s;
		return round( ( 1.0 - MAN_Forecast::cdf_normal( $z ) ) * 100, 0 );
	}
}
```

- [ ] **Paso 2:** `php -l includes/analysis/class-man-predict.php` → sin errores.
- [ ] **Paso 3:** Registrar el require en el cargador y `php -l` del cargador.
- [ ] **Paso 4: Commit** `feat(predict): motor MAN_Predict (OLS + cruce de umbral)`.

### Tarea 0.2: Test rápido del motor (sin WP)

- [ ] **Paso 1:** Script PHP que incluye `class-man-forecast.php` + `class-man-predict.php` (stub `defined('ABSPATH')`) y verifica que `serie_temporal([1,2,3,4,5])` proyecta ~6,7,8 con pendiente ≈ 1 y R² ≈ 1; que `prob_supera_umbral(0.9, 1.0, 0.2)` ≈ 31%.
- [ ] **Paso 2:** Ejecutar con el PHP portátil; confirmar salidas.

---

## FASE 1 — Predicción en las vistas de gráfico (`$g`)

### Tarea 1.1: `MAN_Views::prediccion()` + integración en `obtener()`

**Archivos:** Modificar `includes/data/class-man-views.php`

- [ ] **Paso 1:** Añadir `'prediccion' => self::prediccion( $id, $datos, $args )` al array que devuelve `obtener()`.
- [ ] **Paso 2:** Implementar `private static function prediccion($id,$datos,$args)` con un `switch` por categoría que produzca `{metodo, texto, valor, banda, prob, horizonte}`. Patrón para series temporales:

```php
case 'deficit_serie':
case 'focos_serie':
case 'cultivos_riesgo':
case 'acueductos':
case 'hidro_reduccion':
	$col   = self::medida_de( $id ); // 'deficit'|'focos'|…
	$vals  = array_map( function ( $r ) use ( $col ) { return (float) $r[ $col ]; }, $datos );
	$p     = MAN_Predict::serie_temporal( $vals, array(), 3 );
	$ult   = $p['proyeccion'] ? end( $p['proyeccion'] ) : null;
	$texto = $ult
		? sprintf( 'Con %s, la proyección a 3 meses es %s (banda 90%%: %s–%s). %s.',
			$p['metodo'], number_format_i18n( $ult['valor'], 1 ), number_format_i18n( $ult['banda_min'], 1 ),
			number_format_i18n( $ult['banda_max'], 1 ),
			$p['pendiente'] >= 0 ? 'Tendencia al alza' : 'Tendencia a la baja' )
		: 'Serie insuficiente para proyectar.';
	return array( 'metodo' => $p['metodo'], 'texto' => $texto, 'r2' => $p['r2'] );
```

- [ ] **Paso 3:** Caso ENSO (`oni_*`): reusar `MAN_Forecast::proyectar_oni` sobre la serie observada y `probabilidad_gaussiana` para la fase; texto con valor a +3 meses y fase dominante.
- [ ] **Paso 4:** Caso riesgo/categórico: obtener ONI proyectado del mes objetivo (`construir_prediccion`) y reconstruir el riesgo del top territorio con `MAN_Risk`; texto «Con el ONI proyectado a X en MES, el riesgo de TERRITORIO pasa de A a ~B».
- [ ] **Paso 5:** Caso estaciones FEWS observadas: % de estaciones cuyo valor está a < 1σ del umbral (probabilidad media de cruce con `MAN_Predict::prob_supera_umbral`).
- [ ] **Paso 6:** Caso FEWS simuladas/calidad/SZH: conteo por banda de alerta proyectada.
- [ ] **Paso 7:** `php -l`; commit.

### Tarea 1.2: Texto de metodología por vista (`textos-graficos.php`)

- [ ] **Paso 1:** Añadir a cada entrada una clave `prediccion` con un párrafo (≥200 car.) que explique el MÉTODO (no la cifra), p. ej.: «La proyección usa una regresión lineal por mínimos cuadrados sobre la serie y una banda de incertidumbre del 90% que crece con el horizonte; para Nariño se interpreta junto con la fase ENSO proyectada…». La cifra dinámica la añade `MAN_Predict` en `texto`.
- [ ] **Paso 2:** `MAN_Views::prediccion()` antepone `texto` (cifra) al párrafo de metodología.
- [ ] **Paso 3:** Verificar conteo de caracteres ≥200 de cada `prediccion`.

### Tarea 1.3: Shortcode y pieza en el catálogo

**Archivos:** `class-man-shortcodes.php`, `class-man-admin.php`, `grafico.js`/`renderer.js`

- [ ] **Paso 1:** Registrar `add_shortcode('man_prediccion_dato', …)` y `add_shortcode('man_analisis_predictivo', …)` → ambos llaman a `bloque_analisis($atts, 'prediccion')`.
- [ ] **Paso 2:** En `bloque_analisis`, mapear `modo='prediccion'` a la pieza `prediccion.texto` de la vista (vía `/render`/`obtener`).
- [ ] **Paso 3:** En `tarjeta_elemento()` (tipo `grafico`), añadir al array de piezas: `'Predicción' => '[man_prediccion_dato view="'.$v.'"]'`.
- [ ] **Paso 4:** En el front (`grafico.js`/`renderer.js`), renderizar la pieza predicción con un estilo diferenciado (ícono/encabezado «Predicción (estimación)»).
- [ ] **Paso 5:** `php -l`, `node --check`; commit.

---

## FASE 2 — Predicción y textos en mapas y componentes `$info`

### Tarea 2.1: Textos de los componentes-mapa nuevos

**Archivos:** `textos-elementos.php`, `class-man-shortcodes.php`

- [ ] **Paso 1:** Añadir entradas en `textos-elementos.php` para `mapa_fews`, `mapa_geo`, `estaciones` con `descripcion`, `descriptivo`, `cuantitativo` (texto base) y `prediccion` (metodología). ≥375 car. en descripción/análisis.
- [ ] **Paso 2:** Añadir shortcodes `[man_mapa_fews_descripcion]`, `[man_mapa_fews_analisis]`, `[man_mapa_fews_prediccion]` (y equivalentes `geo`) que pinten esos textos; o un genérico `[man_info elemento="mapa_fews" tipo="prediccion"]` (preferido: extender `sc_info` para aceptar `tipo='cuantitativo'|'prediccion'`).
- [ ] **Paso 3:** Cuantitativo y predicción en vivo: helper que llama a `/geo` o `/estaciones` y cuenta estaciones en alerta + proyección (p. ej., «11 de 17 en alerta; se proyecta que N superen umbral en 72 h»). Cachear 1 h.

### Tarea 2.2: Cuarteto en los componentes `$info`

- [ ] **Paso 1:** Para `estado, globo, timeline, animacion, pronostico, hidrico, mar, salud, datos`: añadir en `textos-elementos.php` las claves `cuantitativo` y `prediccion` que faltan.
- [ ] **Paso 2:** Extender el atajo `$info()` del catálogo para emitir las 4 piezas: Descripción, Análisis cualitativo, Análisis cuantitativo, Predicción.
- [ ] **Paso 3:** `sc_info` acepta `tipo` ∈ {descripcion, analisis, cuantitativo, prediccion}.
- [ ] **Paso 4:** `php -l`; commit.

### Tarea 2.3: Cards del admin actualizadas

- [ ] **Paso 1:** Reemplazar en `class-man-admin.php` los `$s('man_mapa_fews',…)` y `$s('man_mapa_geo',…)` por cards multi con las 4 piezas.
- [ ] **Paso 2:** Verificar que cada tab del catálogo muestre, por card, las 4 piezas (más «¿Cómo funciona?» donde aplique).

---

## FASE 3 — Saneamiento UI/UX (hallazgos de la auditoría)

### Tarea 3.1: Accesibilidad de la línea de tiempo (ALTA)
- [ ] Navegación por flechas (← →) entre marcas de mes; `aria-label` descriptivos en play/pausa y anterior/siguiente; `aria-live="polite"` que anuncie «mes + ONI».
  *Archivos:* `assets/js/timeline.js`.

### Tarea 3.2: `prefers-reduced-motion` en JS (ALTA)
- [ ] `window.matchMedia('(prefers-reduced-motion: reduce)').matches` para: no autoplay en timeline, no animar Anime.js (mostrar estado final), reducir el globo.
  *Archivos:* `timeline.js`, `animacion.js`, `globo.js`.

### Tarea 3.3: Responsive 360px (ALTA)
- [ ] Marcas de timeline sin solape (mostrar 1 de cada N en móvil); globo `height` con `min()` para dejar scroll; tooltips con *clamp* al viewport.
  *Archivos:* `estilos.css`, `timeline.js`, `globo.js`.

### Tarea 3.4: Contraste de gráficos en tema oscuro (ALTA/WCAG)
- [ ] Ajustar paleta/heatmap en `.man-g--dark` para ≥4.5:1; borde/halo en marcadores claros.
  *Archivos:* `grafico.css`, `renderer.js`.

### Tarea 3.5: Focus trap en drawers del globo (MEDIA)
- [ ] `role="dialog"`, `aria-modal="true"`, mover foco al abrir y devolverlo al cerrar; Esc cierra.
  *Archivos:* `globo.js`, `estilos.css`.

### Tarea 3.6: Estados de carga animados y mensajes de error con contexto (MEDIA)
- [ ] Skeleton con animación sutil; `C.error` que distinga timeout/404/sin-datos.
  *Archivos:* `estilos.css`, `man-core.js`.

---

## FASE 4 — Verificación integral y documentación

### Tarea 4.1: Verificación
- [ ] `php -l` de todos los archivos tocados; `node --check` de todos los JS.
- [ ] Pruebas en vivo (node) de las cifras de predicción contra las APIs (ONI, Open-Meteo, FEWS) para evitar absurdos (p. ej., probabilidad fuera de 0–100, bandas invertidas).
- [ ] Recorrido del catálogo: cada card muestra sus 4 piezas y copia shortcodes válidos.

### Tarea 4.2: Documentación y página de inicio
- [ ] Actualizar el párrafo de cada sección si procede; documentar `[man_prediccion_dato]` en el callout del catálogo.
- [ ] Plantilla de **frontpage** de ejemplo (orden recomendado de shortcodes) en `docs/` para armar la página de inicio con jerarquía clara: héroe (estado + globo) → línea de tiempo → mapas → gráficos por fuente → predicciones → descargas.

---

## Autorrevisión del plan

1. **Cobertura del requisito:** las 4 piezas (descripción, análisis descriptivo, análisis cuantitativo, predicción) quedan en TODAS las cards: gráficos (Fase 1), mapas y `$info` (Fase 2). ✔
2. **Ciencia de datos:** métodos explícitos por categoría (OLS, Holt amortiguado, gaussiana de cruce de umbral, regresión condicionada por ENSO), todos sobre `MAN_Forecast`. ✔
3. **UI/UX del frontpage:** Fase 3 cubre los hallazgos ALTA/MEDIA de la auditoría + plantilla de inicio en 4.2. ✔
4. **Consistencia de tipos:** la pieza se llama `prediccion` en views, REST, shortcodes y catálogo (no mezclar con `pronostico`). ✔
5. **Sin placeholders:** los pasos traen el patrón de código real; las cifras se calculan en vivo. ✔

## Decisiones tomadas (defaults, ajustables)
- Horizonte por defecto: **3 meses** (series) / **72 h** (estaciones) / **mes objetivo de la predicción ENSO** (riesgo).
- Banda de incertidumbre: **90%** (1,645σ), creciente con el horizonte.
- La predicción se etiqueta SIEMPRE como «estimación» y cita su método; no se presenta como dato oficial.
