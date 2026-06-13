# Seguridad — Monitor Ambiental y Fenómeno El Niño (Nariño)

Documento de seguridad del plugin. Resume el modelo de amenazas, los controles
implementados y los resultados de la revisión de seguridad realizada en la
versión **1.2.0**. La seguridad es transversal y prioritaria (Sección 9 de la
guía técnica).

## Superficie de ataque

| Vector | Quién accede | Control principal |
|--------|--------------|-------------------|
| Shortcodes (atributos) | autores de contenido | `shortcode_atts` + sanitización + escape de salida |
| REST pública `/wp-json/man/v1/*` | cualquiera (solo lectura) | rate-limit por IP, validación/lista blanca de parámetros, escape |
| Panel admin (Fuentes, Apariencia, Elementos) | `manage_options` | capacidad + nonce (`check_admin_referer`) |
| AJAX (`man_probar`, `man_sincronizar`) | `manage_options` | capacidad + `check_ajax_referer` |
| Cron / sincronización | servidor | URLs configuradas solo por admin; `wp_remote_get` con timeout |
| Datos abiertos JSON/CSV | cualquiera | neutralización de inyección de fórmulas CSV, `nosniff` |

## Controles implementados

- **Entradas.** DIVIPOLA contra **lista blanca** de los 64 municipios; mes con regex `AAAA-MM`; `sanitize_key`/`sanitize_text_field`/`esc_url_raw`; enteros acotados. La vista y el tipo de `[man_grafico]` se validan contra una **lista blanca** (vista inexistente → 404; tipo incompatible → tipo por defecto).
- **Salida.** `esc_html` / `esc_attr` / `esc_url` en todo el HTML; en JS, inserciones por `textContent` o `MANcore.esc()`.
- **CSS (anti-inyección).** `MAN_Estilos::sanitizar_css()` elimina `; { } < > \ " ' @` y comentarios, neutraliza `url() / expression() / image-set() / -moz-binding`, conserva `rgba()/calc()/var()`, normaliza a una línea y **acota la longitud**. Los valores acaban como variables CSS `--man-*` usadas en propiedades controladas y, además, se vuelven a escapar con `esc_attr`.
- **Autorización.** Todas las páginas y acciones de administración comprueban `current_user_can('manage_options')` **y** un nonce. La REST del front es **pública de solo lectura** a propósito (compatibilidad con caché de página) — no expone escrituras ni datos sensibles.
- **Anti-abuso.** Rate-limit por IP (transient) en cada endpoint REST. La IP se toma **solo de `REMOTE_ADDR`** (no se confía en `X-Forwarded-For`, no falsificable).
- **Secretos.** Las claves de API se cifran en reposo con `sodium_crypto_secretbox`, con clave derivada de las sales de `wp-config.php`. Nunca se imprimen ni viajan en texto plano.
- **Anti-SSRF.** `validar_bbox()` para coordenadas; las URLs externas solo las define un administrador (rol confiable) y se consultan con `wp_remote_get` (timeout, `redirection` limitada, UA propio). Open-Meteo se consume **directo desde el navegador** (sin proxy PHP).
- **Base de datos.** Consultas con `$wpdb->prepare` / `insert` / `replace` y formatos tipados; nombres de tabla desde `$wpdb->prefix`.
- **CSV.** `celda_csv()` neutraliza la inyección de fórmulas (`= + - @ TAB CR`) sin dañar números negativos legítimos; cabecera `X-Content-Type-Options: nosniff`.
- **Desinstalación.** `uninstall.php` elimina tablas, opciones, transients y cron solo bajo `WP_UNINSTALL_PLUGIN`.

## Resultados de la revisión (v1.2.0)

**Confirmado correcto:** capacidades + nonces en todo el admin/AJAX; REST pública de solo lectura con rate-limit; IP no falsificable; cifrado de claves; listas blancas (DIVIPOLA, vista, tipo); SQL parametrizado; escape de salida; neutralización CSV.

**Endurecido en esta versión:**
- `sanitizar_css()` reforzada (más caracteres/funciones bloqueados, sin comentarios, longitud acotada).
- Nuevo endpoint `/render` con **doble lista blanca** (vista + tipo) y parámetros sanitizados; el motor de gráficos no expone datos arbitrarios.
- CDN de D3plus fijada a una versión concreta (`@d3plus/core@3.1.4`) — evita cargar un bundle vacío y reduce el riesgo de la cadena de suministro.

## Recomendaciones operativas (servidor)

1. Mantener `wp-config.php` con sales únicas (de ellas depende el cifrado de claves).
2. Servir todo por **HTTPS**; dejar **"Verificar SSL" activado** salvo en portales estatales que lo requieran.
3. Limitar el cron de WordPress a `manage_options` y revisar **Monitor Ambiental → Salud de APIs** periódicamente.
4. Considerar **Subresource Integrity (SRI)** o el alojamiento local de las librerías CDN (D3, D3plus, Leaflet, Three.js, Anime.js) si la política de la entidad lo exige.

## Reporte de vulnerabilidades

Escribir a la Secretaría TIC, Innovación y Gobierno Abierto de la Gobernación de
Nariño. No abrir incidencias públicas con detalles explotables hasta su
corrección.
