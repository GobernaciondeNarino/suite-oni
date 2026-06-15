/* Renderer genérico D3plus (capa 3 del motor de gráficos, ver /skill).
   Recibe un payload {chart, view, data, compatible} y dibuja un SVG
   profesional e interactivo: color POR SERIE (no por punto), ejes con título,
   tooltips nativos y leyenda interactiva. window.MANRenderer.render(node,
   payload, opts) → instancia d3plus. Métodos opcionales con guarda. */
(function () {
  'use strict';

  var PALETTE = [
    '#10A13B', '#003087', '#0ea5e9', '#E8A020', '#14b8a6',
    '#ef6c00', '#c62828', '#a855f7', '#ec4899', '#6366f1'
  ];

  // Etiquetas legibles para ejes/tooltip por nombre de campo.
  var ETIQUETAS = {
    mes: 'Mes', fecha: 'Fecha', oni: 'ONI (°C)', tipo: 'Serie',
    trimestre: 'Trimestre', el_nino: 'El Niño (%)', neutral: 'Neutral (%)', la_nina: 'La Niña (%)',
    subregion: 'Subregión', municipio: 'Municipio', riesgo: 'Índice de riesgo',
    periodo: 'Periodo', oni_pico: 'ONI pico (°C)', _value: 'Valor', _metric: 'Serie'
  };
  function etiqueta(campo, fallback) {
    if (ETIQUETAS[campo]) { return ETIQUETAS[campo]; }
    if (!campo) { return fallback || ''; }
    return String(campo).charAt(0).toUpperCase() + String(campo).slice(1).replace(/_/g, ' ');
  }

  function filterMeaningful(data, measures) {
    if (!measures || !measures.length) { return data; }
    var m = measures[0];
    return data.filter(function (r) {
      var v = r[m];
      if (v === null || v === undefined) { return false; }
      if (typeof v === 'number' && isNaN(v)) { return false; }
      return v !== 0;
    });
  }

  function isDerived(name) { return /^(total|pct_|participacion_|cobertura_)/i.test(String(name)); }

  function reshapeWideToLong(data, dims, measures) {
    var keep = (measures || []).filter(function (m) { return !isDerived(m); });
    var out = [];
    data.forEach(function (r) {
      keep.forEach(function (m) {
        var row = { _metric: etiqueta(m), _value: +r[m] || 0 };
        (dims || []).forEach(function (d) { row[d] = r[d]; });
        out.push(row);
      });
    });
    return out;
  }

  function call(viz, metodo, arg) {
    if (viz && typeof viz[metodo] === 'function') { viz[metodo](arg); }
    return viz;
  }

  /* Mapa estable grupo→color para colorear POR SERIE (corrige la leyenda). */
  function colorPorGrupo(data, campo) {
    var grupos = [];
    data.forEach(function (r) { var g = r[campo]; if (grupos.indexOf(g) < 0) { grupos.push(g); } });
    var cmap = {};
    grupos.forEach(function (g, i) { cmap[g] = PALETTE[i % PALETTE.length]; });
    return function (d) { return cmap[d[campo]] || PALETTE[0]; };
  }

  function render(node, payload, opts) {
    opts = opts || {};
    if (!window.d3plus) { throw new Error('d3plus no disponible'); }

    var chart = payload.chart || {};
    var view = payload.view || {};
    var data = payload.data || [];
    var dims = view.dimensions || [];
    var measures = view.measures || [];
    var key = chart.key;

    var Cls = window.d3plus[chart.class];
    if (typeof Cls !== 'function') { throw new Error('Clase d3plus desconocida: ' + chart.class); }
    if (node) { node.innerHTML = ''; }

    // 1) Prepara los datos y resuelve campos (eje X, Y y CAMPO DE SERIE).
    var esGrafo = ['network', 'rings', 'sankey'].indexOf(key) >= 0;
    var base = esGrafo ? data : filterMeaningful(data, measures);
    var plotData = base, grupo = dims[0], xField = dims[0], yField = measures[0], stacked = false;

    if (key === 'stacked_bar' || key === 'stacked_area') {
      plotData = reshapeWideToLong(base, dims, measures);
      grupo = '_metric'; yField = '_value';
      stacked = (key === 'stacked_bar');
    } else if (key === 'line' || key === 'area') {
      grupo = dims[1] || dims[0];
    }

    // 2) Instancia y configuración común.
    var viz = new Cls().select(node).data(plotData);
    call(viz, 'detectResize', true);
    call(viz, 'legend', opts.legend !== false);
    // Leyenda abajo por defecto (no come el ancho del gráfico); configurable.
    call(viz, 'legendPosition', opts.legendPos || 'bottom');
    // Color POR SERIE (no por índice de punto) — corrige la leyenda duplicada.
    // NO se fija labelConfig.fontFamily: d3plus mide el texto con su fuente para
    // ajustarlo/truncarlo dentro de cada forma; pisarla con 'inherit' lo rompe.
    call(viz, 'color', colorPorGrupo(plotData, grupo));
    if (opts.legendStyle === 'icons') { call(viz, 'legendConfig', { label: false }); }

    // 3) Configuración por tipo.
    switch (key) {
      case 'bar':
        viz.groupBy(grupo).x(xField).y(yField);
        call(viz, 'discrete', 'x');
        break;
      case 'stacked_bar':
        viz.groupBy(['_metric', dims[0]]).x(dims[0]).y('_value');
        call(viz, 'stacked', true);
        call(viz, 'discrete', 'x');
        break;
      case 'line':
        viz.groupBy(grupo).x(xField).y(yField);
        break;
      case 'area':
        viz.groupBy(grupo).x(xField).y(yField);
        break;
      case 'stacked_area':
        viz.groupBy('_metric').x(dims[0]).y('_value');
        break;
      case 'pie':
      case 'donut':
        viz.groupBy(dims[0]).value(measures[0]);
        break;
      case 'treemap':
        viz.groupBy([dims[0]]).sum(measures[0]);
        break;
      case 'box_whisker':
        viz.groupBy(dims[0]).value(measures[0]);
        break;
      default:
        viz.groupBy(grupo).x(xField).y(yField);
    }

    // 4) Ejes con título + tooltip informativo (solo en cartesianos).
    if (['bar', 'stacked_bar', 'line', 'area', 'stacked_area', 'box_whisker'].indexOf(key) >= 0) {
      call(viz, 'xConfig', { title: etiqueta(dims[0]) });
      call(viz, 'yConfig', { title: etiqueta(yField === '_value' ? '_value' : yField) });
      call(viz, 'tooltipConfig', {
        title: function (d) { return String(d[grupo] != null ? d[grupo] : ''); },
        tbody: [
          [etiqueta(dims[0]), function (d) { return d[dims[0]]; }],
          [etiqueta(yField === '_value' ? '_value' : yField), function (d) { return fmt(d[yField]); }]
        ]
      });
    } else {
      call(viz, 'tooltipConfig', {
        title: function (d) { return String(d[dims[0]] != null ? d[dims[0]] : ''); },
        tbody: [[etiqueta(measures[0]), function (d) { return fmt(d[measures[0]]); }]]
      });
    }

    viz.render();
    return viz;
  }

  function fmt(v) {
    if (typeof v !== 'number') { return v == null ? '' : String(v); }
    try { return v.toLocaleString('es-CO', { maximumFractionDigits: 2 }); } catch (e) { return String(v); }
  }

  window.MANRenderer = { render: render, PALETTE: PALETTE, etiqueta: etiqueta };
})();
