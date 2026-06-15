# Checklist de verificación — Fase 1 (datos/APIs)

> El entorno de desarrollo no tiene PHP/WordPress; estos pasos se ejecutan en el
> servidor (Plesk) tras desplegar, y los tests de lógica pura donde haya PHP CLI.

## A. Tests de lógica pura (cualquier máquina con PHP)
- [ ] `php tests/test-fase1.php` → todas las aserciones en `ok`, salida final `TODO OK`.
  Cubre: parser de probabilidades ENSO (CSV y HTML), point-in-polygon, índice de déficit.

## B. Activación / migración (Plesk)
- [ ] **Sitios nuevos:** al activar el plugin se siembran las 3 fuentes nuevas
      (`iri_enso`, `firms`, `deficit`) en **Monitor Ambiental → Fuentes**.
- [ ] **Sitios ya activos:** `add_option` no re-siembra. Para que aparezcan las
      fuentes nuevas, **desactivar y reactivar** el plugin, o re-guardar la
      configuración de Fuentes una vez. (Migración por `man_version` queda para una mejora futura.)

## C. Configuración de claves
- [ ] **FIRMS:** obtener una `MAP_KEY` gratuita en
      https://firms.modaps.eosdis.nasa.gov/api/map_key/ y pegarla en el campo
      «Clave» de la tarjeta *NASA FIRMS*; marcar **Activa**; Guardar.
- [ ] IRI/CPC y Déficit no requieren clave.

## D. Sincronización y «Probar conexión»
- [ ] En cada tarjeta nueva pulsar **Probar conexión** → HTTP 200.
- [ ] Pulsar **Sincronizar ahora** en *NOAA/CPC ENSO oficial*, *NASA FIRMS* y
      *Déficit hídrico*. Verificar «Última sincronización» reciente y resultado `OK`.

## E. Endpoints REST (sustituir el dominio)
- [ ] `GET /wp-json/man/v1/prediccion?fuente=ambos` → JSON con bloque `oficial`
      (probabilidades) **y** `serie` del modelo.
- [ ] `GET /wp-json/man/v1/prediccion?fuente=oficial` → incluye `oficial`.
- [ ] `GET /wp-json/man/v1/prediccion?fuente=modelo` → **sin** bloque `oficial`.
- [ ] `GET /wp-json/man/v1/pronostico/oni?fuente=oficial` → trae `oficial`.
- [ ] `GET /wp-json/man/v1/municipio/52835` → campos `deficit`, `deficit_fuente`,
      `focos`, `focos_fuente` (real cuando hay sync; modelado si no).
- [ ] `GET /wp-json/man/v1/estado-apis` → lista incluye `iri_enso`, `firms`, `deficit`
      con su estado.

## F. Resiliencia (fallback)
- [ ] Poner temporalmente una URL inválida en la fuente *NOAA/CPC ENSO oficial*,
      Sincronizar ahora → resultado `ERROR … → fallback semilla`, **sin error
      fatal**, y el endpoint `/prediccion` sigue respondiendo (estado `mantenimiento`).
- [ ] Restaurar la URL oficial.

## G. Notas
- La fuente oficial primaria es la página de probabilidades de NOAA/CPC
  (`/products/analysis_monitoring/enso/roni/probabilities.php`). El IRI dejó de
  publicar datos descargables. Si NOAA cambia el formato, ajustar
  `MAN_Enso::parse_iri_probabilities()` (es tolerante a CSV/texto/HTML).
