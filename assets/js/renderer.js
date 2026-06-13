/* Renderer genérico D3plus (capa 3 del motor de gráficos, ver /skill).
   No sabe nada del dominio: recibe un payload {chart, view, data, compatible}
   y dibuja el SVG. window.MANRenderer.render(node, payload, opts) → instancia.
   Cubre: bar, stacked_bar, line, area, stacked_area, pie, donut, treemap,
   box_whisker. Métodos d3plus opcionales se invocan con guarda. */
(function () {
  'use strict';

  var PALETTE = [
    '#10A13B', '#003087', '#0ea5e9', '#E8A020', '#14b8a6',
    '#ef6c00', '#c62828', '#a855f7', '#ec4899', '#6366f1'
  ];

  /* Descarta filas cuya medida principal sea 0/null/NaN (salvo grafos). */
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

  /* Series derivadas que no deben apilarse (evita doble conteo). */
  function isDerived(name) {
    return /^(total|pct_|participacion_|cobertura_)/i.test(String(name));
  }

  /* Reshape ancho→largo: cada medida pasa a una fila {_metric,_value,...dims}. */
  function reshapeWideToLong(data, dims, measures) {
    var keep = (measures || []).filter(function (m) { return !isDerived(m); });
    var out = [];
    data.forEach(function (r) {
      keep.forEach(function (m) {
        var row = { _metric: m, _value: +r[m] || 0 };
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

    var viz = new Cls().select(node).data(
      (['network', 'rings', 'sankey'].indexOf(key) < 0) ? filterMeaningful(data, measures) : data
    );

    // Configuración común (con guarda: distintas builds de d3plus).
    call(viz, 'detectResize', true);
    call(viz, 'legend', opts.legend !== false);
    call(viz, 'legendPosition', 'bottom');
    call(viz, 'shapeConfig', { fill: function (d, i) { return PALETTE[i % PALETTE.length]; } });
    if (opts.legendStyle === 'icons') { call(viz, 'legendConfig', { label: false }); }

    switch (key) {
      case 'bar':
        viz.groupBy(dims[0]).x(dims[0]).y(measures[0]);
        break;
      case 'stacked_bar':
        viz.data(reshapeWideToLong(data, dims, measures))
          .groupBy(['_metric', dims[0]]).x(dims[0]).y('_value');
        call(viz, 'stacked', true);
        break;
      case 'line':
        viz.groupBy(dims[1] || dims[0]).x(dims[0]).y(measures[0]);
        break;
      case 'area':
        viz.groupBy(dims[1] || dims[0]).x(dims[0]).y(measures[0]);
        break;
      case 'stacked_area':
        viz.data(reshapeWideToLong(data, dims, measures))
          .groupBy('_metric').x(dims[0]).y('_value');
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
        viz.groupBy(dims[0]).x(dims[0]).y(measures[0]);
    }

    viz.render();
    return viz;
  }

  window.MANRenderer = { render: render, PALETTE: PALETTE };
})();
