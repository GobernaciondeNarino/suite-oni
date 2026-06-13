// =====================================================================
// analisisPestana.js — Análisis textual por pestaña activa + mes activo.
//
// Composición:
//   1) Análisis estructural de la pestaña — investigacion-enso-graficas-
//      mejoras-narino-2026.md (sección 2).
//   2) Lectura del mes activo — ElNino2026_Narino_Analisis_MesAMes.md
//      (sólo el "estado" narrativo y referencias contextuales; las cifras
//      las leemos directamente del JSON para evitar duplicar la data).
//   3) Puntos de atención al leer (estructural).
// =====================================================================

// ---------- 1) Análisis estructural por pestaña ----------
const ANALISIS_PESTANA = {
  ambiental: {
    icono: '🌱',
    titulo: 'Ambiental',
    paragrafos: [
      'El déficit hídrico escala de 18/100 (ene-2026, ONI -0.40) a <b>88/100 (oct-2026)</b> y desciende a 42/100 en marzo de 2027. La precipitación cae de 143 mm a 76 mm en el pico y se recupera a 120 mm. El caudal sigue la precipitación.',
      'A partir de junio 2026 las series se trazan en línea discontinua porque los meses son proyectados (no observados).',
    ],
  },

  agricola: {
    icono: '🌾',
    titulo: 'Agrícola',
    paragrafos: [
      'El área de cultivos en riesgo sube de 12 % (ene-2026) a <b>58 % (oct-2026)</b> y baja a 28 % en marzo 2027. Las barras de variación mensual muestran incrementos (rojo) durante la fase ascendente y decrementos (verde) en el declive del evento.',
      'En la fase ascendente (jul-dic) aparecen simultáneamente <i>papa, café, cacao y pastos/leche</i> como cultivos críticos.',
    ],
  },

  salud: {
    icono: '🏥',
    titulo: 'Salud',
    paragrafos: [
      'El semáforo escala correctamente <b>BAJO/BAJO/BAJO</b> en feb 2026 (ONI negativo) a <b>ALTO/ALTO/ALTO</b> entre ago y nov 2026. La gráfica escalonada (Bajo=1, Medio=2, Alto=3) facilita lectura por umbrales.',
      'El radar del mes activo es útil para entender la presión sectorial simultánea (EDA · IRA · Vectores).',
    ],
  },

  recursos: {
    icono: '💧',
    titulo: 'Recursos',
    paragrafos: [
      'Los acueductos en racionamiento suben de 4 a <b>20 municipios</b> en el pico y descienden a 10 al cierre. La reducción hidroeléctrica llega al 23-32 % en octubre (referencia 2015-16: 30-40 %).',
      'La gráfica de doble eje hidroeléctrica vs déficit hídrico expone visualmente la correlación entre la disponibilidad de agua en cuencas andinas y la generación de energía.',
    ],
  },

  historico: {
    icono: '📊',
    titulo: 'Histórico',
    paragrafos: [
      'Las tarjetas comparan los tres episodios documentados con el evento <b>2026-27 proyectado</b>. La escala relativa de ONI pico es: 2015-16 (2.6) > 2026-27 (2.10) ≈ 2023-24 (2.06) > 2018-19 (0.9).',
      'La secuencia: La Niña 2020-23 → El Niño fuerte 2023-24 → neutral 2024 → La Niña débil 2024-26 → El Niño 2026-27.',
    ],
  },
};

// ---------- 2) Narrativa de "Estado ENSO" por mes ----------
// Extraída de ElNino2026_Narino_Analisis_MesAMes.md. Sólo describe la fase
// y el comportamiento atmosférico/oceánico — las cifras locales se leen del
// JSON para no duplicar contenido.
const ESTADO_POR_MES = {
  '2026-01': 'ENSO neutral · Condiciones normales en el Pacífico. Alisios fuertes E→O.',
  '2026-02': 'ENSO neutral · Pacífico ecuatorial estable; leve aumento del déficit hídrico estacional.',
  '2026-03': 'ENSO neutral · Pacífico exactamente en el promedio histórico.',
  '2026-04': 'Neutral cálido · El Pacífico comienza a calentarse; alisios pierden intensidad levemente.',
  '2026-05': 'Umbral El Niño superado (+0.5 °C) · Alisios se debilitan notablemente.',
  '2026-06': 'El Niño débil confirmado · El Pacífico oriental supera 1 °C sobre el promedio.',
  '2026-07': 'El Niño moderado en desarrollo · Alisios casi detenidos; agua cálida avanza hacia el este.',
  '2026-08': 'El Niño fuerte · Vientos alisios prácticamente detenidos sobre el Pacífico ecuatorial.',
  '2026-09': 'El Niño fuerte en aceleración · La anomalía de temperatura alcanza +1.9 °C.',
  '2026-10': 'El Niño muy fuerte · +2.1 °C en el Pacífico ecuatorial. Pico comparable al 2015-16.',
  '2026-11': 'Doble pico consecutivo · Segunda lectura de +2.1 °C; temporada seca extendida.',
  '2026-12': 'Inicio del declive · ONI baja levemente a +2.0 °C; impactos aún críticos.',
  '2027-01': 'Declive del evento en curso · El Pacífico se enfría gradualmente.',
  '2027-02': 'El Niño moderado · Alisios se recuperan progresivamente.',
  '2027-03': 'El Niño débil · Alisios recuperan actividad normal; retorno a neutralidad proyectado 2H 2027.',
};

// Nombre legible del mes
const NOMBRE_MES = {
  '01': 'Enero', '02': 'Febrero', '03': 'Marzo', '04': 'Abril',
  '05': 'Mayo',  '06': 'Junio',   '07': 'Julio', '08': 'Agosto',
  '09': 'Septiembre', '10': 'Octubre', '11': 'Noviembre', '12': 'Diciembre',
};

function formatearNombreMes(mes) {
  if (!mes) return '';
  const [a, m] = mes.split('-');
  return `${NOMBRE_MES[m] || m} ${a}`;
}

// Etiqueta de "marca" si el mes está en un extremo del evento
function marcaContexto(mes) {
  if (mes === '2026-10') return { etiqueta: 'PICO', clase: 'rojo' };
  if (mes === '2026-11') return { etiqueta: 'PICO sostenido', clase: 'rojo' };
  if (mes === '2026-02' || mes === '2027-02') return { etiqueta: 'cierre verde', clase: 'verde' };
  return null;
}

// ---------- 3) Lectores por pestaña — qué cifras del mes mostrar ----------
// Devuelven un array de pares {etiqueta, valor} relevantes para esa pestaña.
const LECTORES_POR_PESTANA = {
  ambiental: (mesNarino) => {
    const a = mesNarino?.indicadores?.ambiental || {};
    return [
      { etiqueta: 'Déficit hídrico',  valor: `${a.deficit_hidrico ?? '—'}/100` },
      { etiqueta: 'Precipitación',    valor: `${a.precipitacion_mm ?? '—'} mm` },
      { etiqueta: 'Caudal',           valor: `${a.nivel_caudal_pct ?? '—'} %` },
      { etiqueta: 'Focos de calor',   valor: `${a.focos_calor ?? '—'}` },
      { etiqueta: 'Hectáreas en riesgo', valor: (a.hectareas_en_riesgo ?? 0).toLocaleString('es-CO') },
    ];
  },
  agricola: (mesNarino) => {
    const ag = mesNarino?.indicadores?.agricola || {};
    const cultivos = (ag.cultivos_criticos || []).join(', ') || '—';
    return [
      { etiqueta: 'Cultivos en riesgo', valor: `${ag.area_cultivos_en_riesgo_pct ?? '—'} %` },
      { etiqueta: 'Cultivos críticos',  valor: cultivos },
    ];
  },
  salud: (mesNarino) => {
    const s = mesNarino?.indicadores?.salud || {};
    return [
      { etiqueta: 'Presión EDA',      valor: capitalizar(s.presion_eda) },
      { etiqueta: 'Presión IRA',      valor: capitalizar(s.presion_ira) },
      { etiqueta: 'Alerta vectores',  valor: capitalizar(s.alerta_vectores) },
    ];
  },
  recursos: (mesNarino) => {
    const r = mesNarino?.indicadores?.recursos || {};
    return [
      { etiqueta: 'Acueductos en racionamiento', valor: `${r.acueductos_en_racionamiento ?? '—'} mun.` },
      { etiqueta: 'Reducción hidroeléctrica',    valor: `${r.reduccion_hidroelectrica_pct ?? '—'} %` },
    ];
  },
  historico: (_mesNarino, mesGlobal) => {
    const oni = mesGlobal?.oni;
    if (typeof oni !== 'number') return [];
    // Comparación contextual con episodios del JSON histórico
    const pares = [];
    pares.push({ etiqueta: 'ONI mes activo', valor: `${oni >= 0 ? '+' : ''}${oni.toFixed(2)}` });
    if (oni >= 2.0)      pares.push({ etiqueta: 'Comparable a',  valor: '2015-16 (pico 2.6) · 2023-24 (2.06)' });
    else if (oni >= 1.5) pares.push({ etiqueta: 'Comparable a',  valor: '2023-24 (2.06) en ascenso' });
    else if (oni >= 1.0) pares.push({ etiqueta: 'Comparable a',  valor: '2018-19 (pico 0.9) + 1 categoría' });
    else if (oni >= 0.5) pares.push({ etiqueta: 'Comparable a',  valor: 'umbral 2018-19 (0.9)' });
    else                 pares.push({ etiqueta: 'Comparable a',  valor: 'fase neutral o La Niña débil' });
    return pares;
  },
};

function capitalizar(v) {
  if (!v) return '—';
  return v.charAt(0).toUpperCase() + v.slice(1);
}

// =====================================================================
// Clase
// =====================================================================
export class AnalisisPestana {
  constructor(datos) {
    this.datos = datos;
    this.elTitulo = document.getElementById('analisisTitulo');
    this.elIcono = document.getElementById('analisisIcono');
    this.elCuerpo = document.getElementById('analisisCuerpo');
    this.elSeccion = document.getElementById('analisisPestana');
    this.elCabecera = document.getElementById('analisisCabecera');
    this.pestanaActual = 'ambiental';
    this.indiceActual = 0;
    this._cablearAcordeon();
    this._registrarEventos();
    this._render();
  }

  // Acordeón: la cabecera (título completo) expande/colapsa el cuerpo.
  _cablearAcordeon() {
    if (!this.elCabecera || !this.elSeccion) return;
    this.elCabecera.addEventListener('click', () => {
      const activo = this.elSeccion.classList.toggle('activo');
      this.elCabecera.setAttribute('aria-expanded', activo ? 'true' : 'false');
    });
  }

  _registrarEventos() {
    window.addEventListener('pestana-cambio', (e) => {
      const nombre = e.detail?.pestana;
      if (nombre && ANALISIS_PESTANA[nombre]) {
        this.pestanaActual = nombre;
        this._render();
      }
    });
    document.querySelectorAll('.pestana[data-pestana]').forEach((p) => {
      p.addEventListener('click', () => {
        if (ANALISIS_PESTANA[p.dataset.pestana]) {
          this.pestanaActual = p.dataset.pestana;
          this._render();
        }
      });
    });
  }

  actualizarMes(indice) {
    this.indiceActual = indice;
    this._render();
  }

  _render() {
    const info = ANALISIS_PESTANA[this.pestanaActual] || ANALISIS_PESTANA.ambiental;
    const mesGlobal = this.datos?.global?.meses?.[this.indiceActual] || {};
    const mesNarino = this.datos?.narino?.meses?.[this.indiceActual] || {};
    const mesId = mesGlobal.mes || '';
    const nombreMes = formatearNombreMes(mesId);
    const marca = marcaContexto(mesId);

    // Encabezado
    if (this.elIcono) this.elIcono.textContent = info.icono;
    if (this.elTitulo) {
      this.elTitulo.textContent = `Análisis · ${info.titulo}${nombreMes ? ' · ' + nombreMes : ''}`;
    }
    if (!this.elCuerpo) return;

    // --- Bloque MES ACTIVO (lectura específica) ---
    let html = '<div class="analisis-pestana__mes">';
    html += '<div class="analisis-pestana__mes-cabecera">';
    html += `<span class="analisis-pestana__mes-pill">${nombreMes || '—'}</span>`;
    if (typeof mesGlobal.oni === 'number') {
      const tipo = mesGlobal.tipo_dato === 'proyectado' ? 'proy.' : 'obs.';
      html += `<span class="analisis-pestana__mes-oni">ONI ${mesGlobal.oni >= 0 ? '+' : ''}${mesGlobal.oni.toFixed(2)} <em>(${tipo})</em></span>`;
    }
    if (marca) {
      html += `<span class="analisis-pestana__mes-marca analisis-pestana__mes-marca--${marca.clase}">${marca.etiqueta}</span>`;
    }
    html += '</div>';

    const estado = ESTADO_POR_MES[mesId];
    if (estado) {
      html += `<p class="analisis-pestana__mes-estado">${estado}</p>`;
    }

    // Indicadores del dominio de la pestaña activa
    const lector = LECTORES_POR_PESTANA[this.pestanaActual];
    if (lector) {
      const pares = lector(mesNarino, mesGlobal);
      if (pares.length) {
        html += '<dl class="analisis-pestana__mes-tabla">';
        pares.forEach(({ etiqueta, valor }) => {
          html += `<dt>${etiqueta}</dt><dd>${valor}</dd>`;
        });
        html += '</dl>';
      }
    }
    html += '</div>';

    // --- Bloque PESTAÑA (estructural / fijo) ---
    html += '<div class="analisis-pestana__estructural">';
    info.paragrafos.forEach((p) => {
      html += `<p class="analisis-pestana__parrafo">${p}</p>`;
    });
    html += '</div>';

    this.elCuerpo.innerHTML = html;
  }
}
