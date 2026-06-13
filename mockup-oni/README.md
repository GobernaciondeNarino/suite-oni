# Globo El Niño 2026 — Nariño

Pieza educativa interactiva del Fenómeno de El Niño y sus afectaciones en el departamento de Nariño. Three.js + Chart.js, sin proceso de build, lista para Plesk.

> **Gobernación de Nariño · Secretaría TIC, Innovación y Gobierno Abierto**
> Apoyo al Plan de Contingencia ante El Niño (Ley 1523 de 2012).

**URL actual de producción:** https://gobiernoabierto.narino.gov.co/datos/enso/

## Identidad gráfica aplicada (Manual jun. 2024)

- **Paleta institucional:** verde `#10A13B` + amarillo `#FFD500`.
- **Tipografía:** Hind Madurai (Google Fonts) + Source Sans 3 Italic para apoyos.
- **Filosofía:** minimalismo, espacios negativos, jerarquía clara, sin gradientes.
- Los colores semánticos del clima (azules/rojos del Pacífico) y el semáforo de alertas (verde→rojo) se conservan **sólo** donde su uso es universal y técnico, manteniendo la identidad institucional en marca, botones primarios y acentos.

---

## ⚡ Despliegue rápido en Plesk

### Lista exacta de archivos a subir

Sube el **contenido entero** de la carpeta `globo-elnino/` al directorio de producción (`httpdocs/datos/enso/` o donde te convenga):

```
datos/enso/
├── index.html
├── .htaccess
├── README.md  (opcional)
├── assets/
│   └── TIC.png                                 (53 KB — banner institucional)
├── css/
│   └── estilos.css
├── js/
│   ├── main.js
│   ├── globo.js
│   ├── timeline.js
│   ├── graficos.js
│   ├── explicativo.js
│   ├── prevencion.js
│   └── mapaNarino.js
└── data/
    ├── datos_globo_elnino_narino_2026.json    (27 KB  — 15 meses 2026-01→2027-03, ONI observado+proyectado)
    ├── predicciones_elnino_narino_2026.json   (418 KB — 64 municipios DIVIPOLA, serie 15 meses)
    ├── historico_enso_episodios.json          (4 KB   — 2015-16, 2018-19, 2023-24)
    └── narino_municipios.geojson              (354 KB — 64 municipios DIVIPOLA)
```

> **NO subir** archivos `*-old.json` que quedan en la carpeta `data/` local: son
> backups de la versión 1.0 (12 meses). El código sólo carga las rutas sin sufijo.
>
> **Indispensables:** los 3 JSON principales y el GeoJSON. Sin `predicciones_*` el mapa
> de calor cae a respaldo por subregión; sin `historico_enso_episodios` se deshabilita
> el botón "📊 Históricos", la pestaña "Histórico" y el sparkline del slider. La
> compresión gzip del `.htaccess` reduce ~75% el tamaño de los JSON.

**De `assets/` sólo es indispensable `TIC.png`** (banner institucional usado en la barra superior). La textura de la Tierra se sirve por CDN público automáticamente.

### Verificación post-despliegue (cliente)

1. Visitar `https://gobiernoabierto.narino.gov.co/datos/enso/`.
2. `Ctrl + F5` para evitar caché.
3. Esperado en consola del navegador:
   - `[Predicciones] 64 municipios cargados.`
   - `[MapaNariño] 64 municipios cargados; 64 con predicción mensual.`
   - `[Globo El Niño] Inicialización completa.`
   - Sin errores en rojo (`favicon.ico 404` es inofensivo y opcional).
4. Mover el slider de meses: cada municipio debe cambiar de color (verde → amarillo → naranja → rojo) según el mes. Pico esperado en **septiembre–octubre**. Municipio más severo del escenario: **SAN LORENZO** (índice 0.797 en octubre).

---

## 🎬 Qué incluye la experiencia

### 1. Globo 3D interactivo
- Textura Blue Marble desde CDN (fallback en cascada hacia continentes procedimentales si CDN falla).
- Atmósfera sutil + estrellas de fondo.
- Auto-rotación lenta; rotación libre con mouse y touch.
- Modo ligero para equipos modestos.

### 2. Animación del fenómeno (al avanzar el mes o usar la línea de tiempo)
- **Anomalía térmica del Pacífico**: shader animado azul→amarillo→rojo según ONI.
- **Heat map del Pacífico**: 500 partículas térmicas distribuidas en la región Niño-3.4 con gradiente y modulación radial.
- **Flujo de partículas de alisios** (E→O): viajan rápidas y blancas en estado neutral; en El Niño frenan y se vuelven azuladas.
- **Nubes vaporosas** sobre Pacífico occidental que **se disipan y se desplazan al este** con ONI alto (lluvia que se va).
- **Marcador de Nariño** con cambio de color por nivel de alerta + **ondas concéntricas pulsantes** en naranja/rojo.
- **Curva dorada de teleconexión** Pacífico → Nariño, con energía fluyendo (visible cuando ONI > 0.3).

### 3. Línea de tiempo mensual (control maestro)
- Enero–Diciembre 2026 (datos del JSON).
- Play/Pause, ← / →, slider con gradiente de alertas (verde→rojo).
- Velocidad lenta/normal/rápida.
- Atajos de teclado: flechas, espacio.

### 4. Panel lateral "¿Cómo se genera?"
- Drawer lateral derecho (no modal); **empuja al globo** para que ambos sean visibles al mismo tiempo.
- 5 pasos del mecanismo, cada uno con cámara cinemática (curva Bezier 3D) y resalte en escena.
- Barra de progreso superior + botón de avance automático (auto-play 3.5 s).
- En móvil: panel inferior colapsable; globo encoge en altura.

### 5. Mapa de Nariño georreferenciado (GeoJSON + predicciones)
- Los **64 municipios** se proyectan sobre el globo en su posición real (clave de unión `MPIO_CDPMP` ↔ predicciones).
- **Heat map por municipio**: cada polígono se colorea por el `indice_riesgo` mensual real del JSON `predicciones_elnino_narino_2026.json` (gradiente continuo verde 0.0 → amarillo → naranja → rojo 1.0).
- Sincronizado con la línea de tiempo: al mover el slider o reproducir la animación, los 64 municipios se repintan al mes activo.
- Tooltip flotante al hover: nombre · chip de nivel · índice de riesgo · déficit hídrico · focos de calor · % cultivos en riesgo · régimen climático.
- Visible por defecto. Se oculta sólo cuando el usuario pide "🌎 Mecanismo" o avanza por los pasos 1–4 del modo explicativo.

### 6. Cuatro pestañas de gráficos (4+ gráficos cada una)

| Pestaña | Gráficos |
|---------|----------|
| **🌱 Ambiental** | Déficit hídrico · Precipitación+Caudal · Focos+Hectáreas · Índice de severidad compuesto |
| **🌾 Agrícola** | Cultivos en riesgo · Variación mensual (Δ) · Riesgo acumulado · Cultivos críticos del mes |
| **🏥 Salud** | Semáforo del mes · Evolución EDA/IRA/Vectores · Presión agregada · Radar del mes activo |
| **💧 Recursos** | Acueductos en racionamiento · Reducción hidroeléctrica · Acueductos acumulado · Hidro vs déficit (doble eje) |

Cada curva anual lleva un punto dorado sobre el mes activo. Todas las cifras provienen del JSON entregado.

### 7. Panel de recomendaciones de prevención
- Por cada nivel de alerta (verde→rojo) muestra acciones por sector: 💧 hídrico · 🌾 agrícola · 🏥 salud · 🔥 incendios · 🤝 comunidad.
- Riesgos por subregión desplegables (datos del JSON).

### 8. Pie con aviso responsable
- Cintillo dorado permanente con la cláusula de naturaleza ilustrativa de los datos.
- Referencia explícita a la Ley 1523 de 2012.

---

## 🔧 Cómo actualizar los datos

Reemplazar `data/datos_globo_elnino_narino_2026.json` conservando la misma estructura del archivo:

- `meta`, `fenomeno`, `mecanismo`
- `global.meses[12]` (ONI, fase, probabilidad)
- `narino.meses[12]` (nivel_alerta_general, resumen, indicadores ambiental/agrícola/salud/recursos)
- `narino.subregiones[]`
- `leyenda_alertas`

No se necesita rebuild: el navegador hace `fetch` directo al JSON.

Para el mapa de municipios, reemplazar `data/narino_municipios.geojson` por una versión actualizada con los mismos atributos clave: `MPIO_CNMBR`, `LATITUD`, `LONGITUD`, geometría Polygon/MultiPolygon.

---

## 🐛 Errores comunes y solución

| Síntoma | Causa | Solución |
|---------|-------|----------|
| Modal "No se pudieron cargar los datos" persistente | CSS `.capa-error` ocultaba el atributo `hidden`. **Ya corregido** con `display: none !important`. | Subir el CSS actualizado y hacer `Ctrl+F5`. |
| `earth.jpg 404` en consola | Antes el código intentaba cargar local primero. **Ya corregido**: se carga directo desde CDN, sin intento local. | Subir el `js/globo.js` actualizado. |
| Globo no aparece | Browser sin WebGL2 o bloqueado por extensión | Probar otro navegador, deshabilitar extensiones agresivas. |
| Gráficos no aparecen | Chart.js bloqueado | Verificar que el dominio puede cargar desde `cdn.jsdelivr.net`. |
| Drawer cubre el globo | Versión vieja del CSS | Subir CSS actualizado (`body.drawer-abierto .escena { width: calc(100% - 380px) }`). |

---

## ⚙️ Stack técnico

- **Three.js 0.160** via importmap (módulos ES, sin build).
- **Chart.js 4.4** via CDN (`<script>` global).
- **HTML/CSS/JS vanilla**, sin Webpack/Vite.
- **GeoJSON** del DANE (DIVIPOLA).
- Servido por **Plesk** como archivos estáticos.

---

## 📄 Aviso

> Los datos del JSON son un **escenario de planeación ilustrativo**, no pronósticos oficiales. Verificar contra boletines vigentes del IDEAM y NOAA-CPC.
