// =====================================================================
// graficos.js — Chart.js: 4 pestañas (Ambiental, Agrícola, Salud, Recursos)
// Cada gráfico anual lleva marcador en el mes activo.
// =====================================================================

// DECISIÓN: Chart.js se carga vía <script> en index.html — usamos el global window.Chart.

// DECISIÓN: paleta de los gráficos alineada con el manual de identidad
// institucional (verde #10A13B, amarillo #FFD500). Los rojos/azul oscuro se
// mantienen para significados específicos: rojo = alerta/severidad,
// azul = referencia/datos hídricos.
const PALETA = {
  verde: '#10A13B',
  verdeOscuro: '#0a7d2c',
  amarillo: '#FFD500',
  amarilloSuave: '#fff3a8',
  azul: '#1A5276',
  azulOscuro: '#003087',
  rojo: '#c62828',
  tinta: '#1a1f2c',
  tintaSuave: '#4a5568',
  // Aliases para mantener compatibilidad con código anterior
  dorado: '#FFD500',
};

const NIVELES = { bajo: 1, medio: 2, alto: 3 };

export class PanelGraficos {
  constructor(datos, historico = null) {
    this.datos = datos; // JSON completo
    this.historico = historico; // historico_enso_episodios.json (opcional)
    this.indiceActivo = 0;
    this.graficos = {}; // refs Chart.js
    this.pestanaActiva = 'ambiental';

    // Índice donde acaba el último mes observado (para línea discontinua)
    this.indiceUltimoObservado = (() => {
      const meses = this.datos.narino.meses || [];
      for (let i = meses.length - 1; i >= 0; i--) {
        if (meses[i].tipo_dato === 'observado') return i;
      }
      return -1;
    })();

    this._registrarPestañas();
    this._construirTodo();
    if (this.historico) this._construirHistorico();
  }

  // Plugin Chart.js: traza la sección proyectada de cada dataset con guión
  // y un sombreado leve para el rango proyectado del eje X.
  _segmentoProyectado() {
    const corte = this.indiceUltimoObservado;
    if (corte < 0) return undefined;
    return {
      borderDash: (ctx) => (ctx.p0DataIndex >= corte ? [6, 4] : undefined),
    };
  }

  _registrarPestañas() {
    const pestanas = document.querySelectorAll('.pestana');
    pestanas.forEach((p) => {
      p.addEventListener('click', () => this._activarPestaña(p.dataset.pestana));
    });
  }

  _activarPestaña(nombre) {
    this.pestanaActiva = nombre;
    document.querySelectorAll('.pestana').forEach((p) => {
      p.setAttribute('aria-selected', p.dataset.pestana === nombre ? 'true' : 'false');
    });
    const mapa = {
      ambiental: 'pestanaAmbiental',
      agricola: 'pestanaAgricola',
      salud: 'pestanaSalud',
      recursos: 'pestanaRecursos',
      historico: 'pestanaHistorico',
    };
    Object.entries(mapa).forEach(([k, id]) => {
      const el = document.getElementById(id);
      if (el) el.hidden = (k !== nombre);
    });
    // Notificar el cambio para que el panel lateral actualice su análisis
    window.dispatchEvent(new CustomEvent('pestana-cambio', {
      detail: { pestana: nombre },
    }));
  }

  _nombresMeses() {
    return this.datos.narino.meses.map((m) => (m.nombre_mes || '').substring(0, 3));
  }

  _valoresMes(camino) {
    // Devuelve array anual recorriendo narino.meses[i].indicadores.<camino>
    return this.datos.narino.meses.map((m) => {
      const partes = camino.split('.');
      let v = m.indicadores;
      for (const p of partes) {
        if (v == null) return null;
        v = v[p];
      }
      return (typeof v === 'number') ? v : null;
    });
  }

  _datasetMarcador(valoresAnuales, color) {
    // Punto único en el mes activo (resaltado)
    const puntos = valoresAnuales.map((v, i) => (i === this.indiceActivo ? v : null));
    return {
      label: 'Mes activo',
      data: puntos,
      borderColor: color,
      backgroundColor: color,
      pointRadius: 7,
      pointHoverRadius: 9,
      showLine: false,
    };
  }

  _construirTodo() {
    this._construirAmbiental();
    this._construirAgricola();
    this._construirSalud();
    this._construirRecursos();
    this._aplicarSegmentoProyectadoATodos();
  }

  // Recorre todos los gráficos creados y aplica línea discontinua al tramo
  // proyectado del primer dataset (que es la serie principal). El segundo
  // dataset suele ser el "marcador del mes activo" y no necesita guión.
  _aplicarSegmentoProyectadoATodos() {
    const seg = this._segmentoProyectado();
    if (!seg) return;
    Object.values(this.graficos).forEach((ch) => {
      if (!ch?.data?.datasets?.length) return;
      ch.data.datasets.forEach((ds, i) => {
        // Sólo a las líneas, no a barras ni al marcador
        const esLinea = (ch.config.type === 'line') && ds.label !== 'Mes activo';
        if (esLinea) {
          ds.segment = seg;
          // Punto opaco en observado, semi-transparente en proyectado
          ds.pointBackgroundColor = (ctx) =>
            ctx.dataIndex > this.indiceUltimoObservado
              ? 'rgba(232,160,32,0.55)'
              : ds.borderColor;
        }
      });
      ch.update('none');
    });
  }

  // ---------- AMBIENTAL ----------
  _construirAmbiental() {
    const meses = this._nombresMeses();
    const deficit = this._valoresMes('ambiental.deficit_hidrico');
    this.graficos.deficit = new window.Chart(
      document.getElementById('graficoDeficitHidrico').getContext('2d'),
      {
        type: 'line',
        data: {
          labels: meses,
          datasets: [
            {
              label: 'Déficit hídrico (0–100)',
              data: deficit,
              borderColor: PALETA.azul,
              backgroundColor: 'rgba(26, 82, 118, 0.18)',
              fill: true, tension: 0.35, pointRadius: 3,
            },
            this._datasetMarcador(deficit, PALETA.dorado),
          ],
        },
        options: this._opcionesBase({ y: { min: 0, max: 100 } }),
      }
    );

    const precip = this._valoresMes('ambiental.precipitacion_mm');
    const caudal = this._valoresMes('ambiental.nivel_caudal_pct');
    this.graficos.precipCaudal = new window.Chart(
      document.getElementById('graficoPrecipitacionCaudal').getContext('2d'),
      {
        type: 'line',
        data: {
          labels: meses,
          datasets: [
            { label: 'Precipitación (mm)', data: precip, borderColor: PALETA.azul, backgroundColor: 'rgba(26,82,118,0.1)', tension: 0.3, yAxisID: 'y' },
            { label: 'Caudal (%)', data: caudal, borderColor: PALETA.dorado, backgroundColor: 'rgba(232,160,32,0.1)', tension: 0.3, yAxisID: 'y1' },
            this._datasetMarcador(precip, PALETA.azulOscuro),
          ],
        },
        options: this._opcionesBase({
          y:  { type: 'linear', position: 'left',  title: { display: true, text: 'mm' } },
          y1: { type: 'linear', position: 'right', title: { display: true, text: '%' }, grid: { drawOnChartArea: false }, min: 0, max: 100 },
        }),
      }
    );

    // Barras: focos y hectáreas del mes activo
    this.graficos.focos = new window.Chart(
      document.getElementById('graficoFocos').getContext('2d'),
      {
        type: 'bar',
        data: {
          labels: ['Focos de calor', 'Hectáreas en riesgo'],
          datasets: [{
            label: 'Mes activo',
            data: [0, 0],
            backgroundColor: [PALETA.rojo, PALETA.dorado],
          }],
        },
        options: this._opcionesBase({ y: { beginAtZero: true } }, /* sinLeyenda */ true),
      }
    );

    // 4º gráfico: índice de severidad ambiental (combinación normalizada)
    const severidad = this.datos.narino.meses.map((m) => {
      const a = m.indicadores.ambiental;
      // Compuesto: déficit (0-100) + focos normalizados + hectáreas normalizadas
      const focosN = Math.min(100, (a.focos_calor || 0) / 2);          // 200 focos → 100
      const haN = Math.min(100, (a.hectareas_en_riesgo || 0) / 110);   // 11.000 ha → 100
      return Math.round((a.deficit_hidrico * 0.5) + (focosN * 0.25) + (haN * 0.25));
    });
    this.graficos.severidad = new window.Chart(
      document.getElementById('graficoSeveridadAmbiental').getContext('2d'),
      {
        type: 'line',
        data: {
          labels: meses,
          datasets: [
            { label: 'Índice de severidad', data: severidad, borderColor: PALETA.rojo, backgroundColor: 'rgba(198,40,40,0.18)', fill: true, tension: 0.35 },
            this._datasetMarcador(severidad, PALETA.dorado),
          ],
        },
        options: this._opcionesBase({ y: { min: 0, max: 100 } }),
      }
    );
  }

  // ---------- AGRÍCOLA ----------
  _construirAgricola() {
    const meses = this._nombresMeses();
    const cultivos = this._valoresMes('agricola.area_cultivos_en_riesgo_pct');
    this.graficos.cultivos = new window.Chart(
      document.getElementById('graficoCultivosRiesgo').getContext('2d'),
      {
        type: 'line',
        data: {
          labels: meses,
          datasets: [
            { label: 'Cultivos en riesgo (%)', data: cultivos, borderColor: PALETA.verde, backgroundColor: 'rgba(46,125,50,0.15)', fill: true, tension: 0.3 },
            this._datasetMarcador(cultivos, PALETA.dorado),
          ],
        },
        options: this._opcionesBase({ y: { min: 0, max: 100 } }),
      }
    );

    // Variación mensual = diferencia respecto al mes anterior (barras + signo)
    const variacion = cultivos.map((v, i, arr) => i === 0 ? 0 : (v - arr[i-1]));
    this.graficos.variacionAgr = new window.Chart(
      document.getElementById('graficoVariacionAgricola').getContext('2d'),
      {
        type: 'bar',
        data: {
          labels: meses,
          datasets: [{
            label: 'Δ pp respecto al mes anterior',
            data: variacion,
            backgroundColor: variacion.map((v) => v >= 0 ? PALETA.rojo : PALETA.verde),
          }],
        },
        options: this._opcionesBase({ y: { beginAtZero: true } }),
      }
    );

    // Acumulado anual (suma corrida) — comunica "qué tan severo va siendo el año"
    const acumulado = cultivos.reduce((acc, v) => { acc.push((acc.length ? acc[acc.length-1] : 0) + v); return acc; }, []);
    this.graficos.acumAgr = new window.Chart(
      document.getElementById('graficoRiesgoAcumulado').getContext('2d'),
      {
        type: 'line',
        data: {
          labels: meses,
          datasets: [
            { label: 'Riesgo acumulado', data: acumulado, borderColor: PALETA.azul, backgroundColor: 'rgba(26,82,118,0.18)', fill: true, tension: 0.25 },
            this._datasetMarcador(acumulado, PALETA.dorado),
          ],
        },
        options: this._opcionesBase({ y: { beginAtZero: true } }),
      }
    );
  }

  // ---------- SALUD ----------
  _construirSalud() {
    const meses = this._nombresMeses();
    const eda  = this.datos.narino.meses.map((m) => NIVELES[m.indicadores.salud.presion_eda] ?? 0);
    const ira  = this.datos.narino.meses.map((m) => NIVELES[m.indicadores.salud.presion_ira] ?? 0);
    const vect = this.datos.narino.meses.map((m) => NIVELES[m.indicadores.salud.alerta_vectores] ?? 0);

    this.graficos.salud = new window.Chart(
      document.getElementById('graficoSalud').getContext('2d'),
      {
        type: 'line',
        data: {
          labels: meses,
          datasets: [
            { label: 'EDA',      data: eda,  borderColor: PALETA.azul,    tension: 0.3, stepped: 'middle' },
            { label: 'IRA',      data: ira,  borderColor: PALETA.dorado,  tension: 0.3, stepped: 'middle' },
            { label: 'Vectores', data: vect, borderColor: PALETA.rojo,    tension: 0.3, stepped: 'middle' },
          ],
        },
        options: this._opcionesBase({
          y: {
            min: 0, max: 3,
            ticks: {
              stepSize: 1,
              callback: (v) => ({ 0: '—', 1: 'Bajo', 2: 'Medio', 3: 'Alto' }[v] ?? ''),
            },
          },
        }),
      }
    );

    // Agregado: suma de los 3 niveles por mes (0..9)
    const agregado = eda.map((_, i) => (eda[i] || 0) + (ira[i] || 0) + (vect[i] || 0));
    this.graficos.saludAgregado = new window.Chart(
      document.getElementById('graficoSaludAgregado').getContext('2d'),
      {
        type: 'bar',
        data: {
          labels: meses,
          datasets: [
            { label: 'Presión sanitaria total', data: agregado, backgroundColor: PALETA.rojo },
            this._datasetMarcador(agregado, PALETA.dorado),
          ],
        },
        options: this._opcionesBase({ y: { min: 0, max: 9, ticks: { stepSize: 1 } } }),
      }
    );

    // Radar del mes activo (se actualiza en actualizarMes)
    this.graficos.saludRadar = new window.Chart(
      document.getElementById('graficoSaludRadar').getContext('2d'),
      {
        type: 'radar',
        data: {
          labels: ['EDA', 'IRA', 'Vectores'],
          datasets: [{
            label: 'Mes activo',
            data: [0, 0, 0],
            backgroundColor: 'rgba(232,160,32,0.35)',
            borderColor: PALETA.dorado,
            pointBackgroundColor: PALETA.azulOscuro,
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: { r: { min: 0, max: 3, ticks: { stepSize: 1, callback: (v) => ({ 0: '—', 1: 'Bajo', 2: 'Medio', 3: 'Alto' }[v] ?? '') } } },
          plugins: { legend: { position: 'bottom' } },
        },
      }
    );
  }

  // ---------- RECURSOS ----------
  _construirRecursos() {
    const meses = this._nombresMeses();
    const acueductos = this._valoresMes('recursos.acueductos_en_racionamiento');
    const hidro = this._valoresMes('recursos.reduccion_hidroelectrica_pct');

    this.graficos.acueductos = new window.Chart(
      document.getElementById('graficoAcueductos').getContext('2d'),
      {
        type: 'bar',
        data: {
          labels: meses,
          datasets: [
            { label: 'Acueductos en racionamiento', data: acueductos, backgroundColor: PALETA.azul },
            this._datasetMarcador(acueductos, PALETA.dorado),
          ],
        },
        options: this._opcionesBase({ y: { beginAtZero: true } }),
      }
    );

    this.graficos.hidro = new window.Chart(
      document.getElementById('graficoHidro').getContext('2d'),
      {
        type: 'line',
        data: {
          labels: meses,
          datasets: [
            { label: 'Reducción hidroeléctrica (%)', data: hidro, borderColor: PALETA.rojo, backgroundColor: 'rgba(198,40,40,0.15)', fill: true, tension: 0.3 },
            this._datasetMarcador(hidro, PALETA.dorado),
          ],
        },
        options: this._opcionesBase({ y: { min: 0, max: 50 } }),
      }
    );

    // Acumulado anual de acueductos en racionamiento
    const acumAcueductos = acueductos.reduce((acc, v) => { acc.push((acc.length ? acc[acc.length-1] : 0) + v); return acc; }, []);
    this.graficos.acueductosAcum = new window.Chart(
      document.getElementById('graficoAcueductosAcum').getContext('2d'),
      {
        type: 'line',
        data: {
          labels: meses,
          datasets: [
            { label: 'Acueductos × meses (acumulado)', data: acumAcueductos, borderColor: PALETA.azulOscuro, backgroundColor: 'rgba(0,48,135,0.15)', fill: true, tension: 0.25 },
            this._datasetMarcador(acumAcueductos, PALETA.dorado),
          ],
        },
        options: this._opcionesBase({ y: { beginAtZero: true } }),
      }
    );

    // Hidro (%) vs déficit hídrico (0..100) — dispersión / doble eje
    const deficit = this._valoresMes('ambiental.deficit_hidrico');
    this.graficos.hidroVsDef = new window.Chart(
      document.getElementById('graficoHidroVsDeficit').getContext('2d'),
      {
        type: 'line',
        data: {
          labels: meses,
          datasets: [
            { label: 'Reducción hidro (%)', data: hidro, borderColor: PALETA.rojo, tension: 0.3, yAxisID: 'y' },
            { label: 'Déficit hídrico (0–100)', data: deficit, borderColor: PALETA.azul, tension: 0.3, yAxisID: 'y1' },
          ],
        },
        options: this._opcionesBase({
          y:  { type: 'linear', position: 'left',  min: 0, max: 50, title: { display: true, text: '% reducción' } },
          y1: { type: 'linear', position: 'right', min: 0, max: 100, title: { display: true, text: 'déficit' }, grid: { drawOnChartArea: false } },
        }),
      }
    );
  }

  _opcionesBase(scalesExtra = {}, sinLeyenda = false) {
    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 600 },
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: !sinLeyenda, position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
        tooltip: { enabled: true },
      },
      scales: {
        x: { ticks: { font: { size: 10 } } },
        ...scalesExtra,
      },
    };
  }

  // ---------- API ----------
  actualizarMes(indice) {
    this.indiceActivo = indice;
    const mes = this.datos.narino.meses[indice];
    const indic = mes.indicadores;

    // Ambiental
    this._refrescarMarcador(this.graficos.deficit, this._valoresMes('ambiental.deficit_hidrico'), PALETA.dorado);
    this._refrescarMarcador(this.graficos.precipCaudal, this._valoresMes('ambiental.precipitacion_mm'), PALETA.azulOscuro);
    this.graficos.focos.data.datasets[0].data = [
      indic.ambiental.focos_calor ?? 0,
      indic.ambiental.hectareas_en_riesgo ?? 0,
    ];
    this.graficos.focos.update();
    // Severidad: recalcular cada vez (es un derivado)
    const sevAnual = this.datos.narino.meses.map((m) => {
      const a = m.indicadores.ambiental;
      const focosN = Math.min(100, (a.focos_calor || 0) / 2);
      const haN = Math.min(100, (a.hectareas_en_riesgo || 0) / 110);
      return Math.round((a.deficit_hidrico * 0.5) + (focosN * 0.25) + (haN * 0.25));
    });
    this._refrescarMarcador(this.graficos.severidad, sevAnual, PALETA.dorado);

    // Agrícola
    this._refrescarMarcador(this.graficos.cultivos, this._valoresMes('agricola.area_cultivos_en_riesgo_pct'), PALETA.dorado);
    const cultAnual = this._valoresMes('agricola.area_cultivos_en_riesgo_pct');
    const acumulado = cultAnual.reduce((acc, v) => { acc.push((acc.length ? acc[acc.length-1] : 0) + v); return acc; }, []);
    this._refrescarMarcador(this.graficos.acumAgr, acumulado, PALETA.dorado);
    const ul = document.getElementById('listaCultivos');
    ul.innerHTML = '';
    (indic.agricola.cultivos_criticos || []).forEach((c) => {
      const li = document.createElement('li');
      li.textContent = c;
      ul.appendChild(li);
    });

    // Salud
    this._semaforo('semEda',  indic.salud.presion_eda);
    this._semaforo('semIra',  indic.salud.presion_ira);
    this._semaforo('semVect', indic.salud.alerta_vectores);
    const eda = this.datos.narino.meses.map((m) => NIVELES[m.indicadores.salud.presion_eda] ?? 0);
    const ira = this.datos.narino.meses.map((m) => NIVELES[m.indicadores.salud.presion_ira] ?? 0);
    const vect = this.datos.narino.meses.map((m) => NIVELES[m.indicadores.salud.alerta_vectores] ?? 0);
    const agregado = eda.map((_, i) => eda[i] + ira[i] + vect[i]);
    this._refrescarMarcador(this.graficos.saludAgregado, agregado, PALETA.dorado);
    // Radar del mes activo
    this.graficos.saludRadar.data.datasets[0].data = [
      NIVELES[indic.salud.presion_eda] ?? 0,
      NIVELES[indic.salud.presion_ira] ?? 0,
      NIVELES[indic.salud.alerta_vectores] ?? 0,
    ];
    this.graficos.saludRadar.update();

    // Recursos
    this._refrescarMarcador(this.graficos.acueductos, this._valoresMes('recursos.acueductos_en_racionamiento'), PALETA.dorado);
    this._refrescarMarcador(this.graficos.hidro, this._valoresMes('recursos.reduccion_hidroelectrica_pct'), PALETA.dorado);
    const acumAcueductos = this._valoresMes('recursos.acueductos_en_racionamiento')
      .reduce((acc, v) => { acc.push((acc.length ? acc[acc.length-1] : 0) + v); return acc; }, []);
    this._refrescarMarcador(this.graficos.acueductosAcum, acumAcueductos, PALETA.dorado);
    // Hidro vs déficit no tiene marcador adicional, sólo update general
    this.graficos.hidroVsDef.update();
  }

  _refrescarMarcador(chart, valoresAnuales, color) {
    const dsMarcador = chart.data.datasets[chart.data.datasets.length - 1];
    if (dsMarcador && dsMarcador.label === 'Mes activo') {
      dsMarcador.data = valoresAnuales.map((v, i) => (i === this.indiceActivo ? v : null));
    }
    chart.update('none');
  }

  // ---------- HISTÓRICO ENSO (pestaña nueva) ----------
  _construirHistorico() {
    const episodios = this.historico?.episodios || [];
    if (!episodios.length) return;

    // Tarjetas resumen
    const contTarj = document.getElementById('historicoTarjetas');
    if (contTarj) {
      contTarj.innerHTML = '';
      episodios.forEach((ep) => {
        const cat = ep.categoria || 'sin dato';
        const tarj = document.createElement('article');
        tarj.className = `historico-tarjeta historico-tarjeta--${cat}`;
        tarj.innerHTML = `
          <header>
            <strong>${ep.periodo}</strong>
            <span class="historico-tarjeta__chip">${this._etiquetaCategoria(cat)}</span>
          </header>
          <p class="historico-tarjeta__oni">ONI pico <b>${ep.oni_pico ?? '—'}</b> · ${ep.duracion_meses ?? '—'} meses</p>
          <p class="historico-tarjeta__contexto">${ep.contexto || ''}</p>
          <dl class="historico-tarjeta__metricas">
            ${this._renderMetricasImpacto(ep.impactos_colombia, 'Colombia')}
            ${this._renderMetricasImpacto(ep.impactos_narino, 'Nariño')}
          </dl>
        `;
        contTarj.appendChild(tarj);
      });

      // Tarjeta especial: evento actual proyectado (2026-27)
      const oniProyMax = Math.max(...(this.datos.global?.meses || []).map((m) => m.oni || 0));
      const tarjActual = document.createElement('article');
      tarjActual.className = 'historico-tarjeta historico-tarjeta--actual';
      tarjActual.innerHTML = `
        <header>
          <strong>2026-27 (proyectado)</strong>
          <span class="historico-tarjeta__chip">en curso</span>
        </header>
        <p class="historico-tarjeta__oni">ONI pico proyectado <b>${oniProyMax.toFixed(2)}</b></p>
        <p class="historico-tarjeta__contexto">${this.datos.fenomeno?.intensidad_prevista || ''}</p>
        <dl class="historico-tarjeta__metricas">
          <dt>Probabilidad</dt><dd>${this.datos.fenomeno?.probabilidad_resumen || '—'}</dd>
        </dl>
      `;
      contTarj.appendChild(tarjActual);
    }

    // Secuencia narrativa
    const seq = document.getElementById('historicoSecuencia');
    if (seq && this.historico?.contexto_enso_reciente?.secuencia) {
      seq.textContent = this.historico.contexto_enso_reciente.secuencia;
    }
    const fuente = document.getElementById('historicoFuente');
    if (fuente && this.historico?.meta?.fuentes) {
      fuente.textContent = `Fuentes: ${this.historico.meta.fuentes.join(' · ')}`;
    }

    // Gráfico de barras comparativo ONI pico
    const cvs = document.getElementById('graficoHistoricoONI');
    if (cvs && window.Chart) {
      const labels = episodios.map((e) => e.periodo);
      const vals = episodios.map((e) => e.oni_pico ?? 0);
      const cats = episodios.map((e) => e.categoria);
      const oniProyMax = Math.max(...(this.datos.global?.meses || []).map((m) => m.oni || 0));
      labels.push('2026-27 (proy.)');
      vals.push(Number(oniProyMax.toFixed(2)));
      cats.push('proyectado');

      const colorCat = {
        debil: '#2e7d32',
        moderado: '#f9a825',
        fuerte: '#ef6c00',
        muy_fuerte: '#c62828',
        proyectado: '#1A5276',
      };
      const colores = cats.map((c) => colorCat[c] || '#999');

      this.graficos.historicoONI = new window.Chart(cvs.getContext('2d'), {
        type: 'bar',
        data: {
          labels,
          datasets: [{
            label: 'ONI pico',
            data: vals,
            backgroundColor: colores,
            borderColor: colores,
            borderWidth: 1,
          }],
        },
        options: {
          ...this._opcionesBase({ y: { beginAtZero: true, max: 3, title: { display: true, text: 'ONI pico (°C)' } } }, true),
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: (ctx) => `ONI pico ${ctx.parsed.y} · ${this._etiquetaCategoria(cats[ctx.dataIndex])}`,
              },
            },
          },
        },
      });
    }
  }

  _etiquetaCategoria(cat) {
    return ({
      debil: 'Débil',
      moderado: 'Moderado',
      fuerte: 'Fuerte',
      muy_fuerte: 'Muy fuerte',
      proyectado: 'En desarrollo',
    })[cat] || cat;
  }

  _renderMetricasImpacto(obj, etiqueta) {
    if (!obj) return '';
    const formato = (k, v) => {
      const key = k.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
      const val = (typeof v === 'number') ? v.toLocaleString('es-CO') : v;
      return `<dt>${key}</dt><dd>${val}</dd>`;
    };
    const items = Object.entries(obj)
      .filter(([k]) => k !== 'detalle' && k !== 'alertas' && k !== 'instrumento' && k !== 'corte')
      .slice(0, 4)
      .map(([k, v]) => formato(k, v))
      .join('');
    return `<dt class="historico-tarjeta__grupo">${etiqueta}</dt>${items}`;
  }

  _semaforo(idElemento, nivel) {
    const el = document.getElementById(idElemento);
    if (!el) return;
    el.classList.remove('semaforo__item--bajo', 'semaforo__item--medio', 'semaforo__item--alto');
    const texto = (nivel || 'sin dato');
    if (nivel === 'bajo' || nivel === 'medio' || nivel === 'alto') {
      el.classList.add(`semaforo__item--${nivel}`);
    }
    const span = el.querySelector('.semaforo__nivel');
    if (span) span.textContent = texto;
  }
}
