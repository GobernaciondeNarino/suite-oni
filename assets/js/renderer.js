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
    var datos0 = (payload && payload.data) || [];
    if (node && !datos0.length) { return vacio(node, payload); }
    if (!window.d3plus) { return fallbackSVG(node, payload); }
    try {
      return renderD3plus(node, payload, opts);
    } catch (e) {
      return fallbackSVG(node, payload);
    }
  }

  function renderD3plus(node, payload, opts) {
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

    // 4) Ejes con título + tooltip ENRIQUECIDO: dimensión, serie, todas las
    //    medidas y la fase ENSO cuando hay ONI (la mayor información posible).
    var dimX = dims[0];
    function faseOni(v) {
      v = +v; return v >= 0.5 ? 'El Niño' : (v <= -0.5 ? 'La Niña' : 'Neutral');
    }
    function tbodyRico() {
      var t = [];
      if (dimX != null) { t.push([etiqueta(dimX), function (r) { return r[dimX] != null ? r[dimX] : ''; }]); }
      if (grupo && grupo !== dimX && grupo !== '_metric') { t.push([etiqueta(grupo), function (r) { return r[grupo] != null ? r[grupo] : ''; }]); }
      if (grupo === '_metric') { t.push(['Serie', function (r) { return r._metric != null ? r._metric : ''; }]); }
      var ms = (yField === '_value') ? ['_value'] : measures;
      ms.forEach(function (m) { t.push([etiqueta(m), function (r) { return fmt(r[m]); }]); });
      if (ms.indexOf('oni') >= 0) { t.push(['Fase', function (r) { return faseOni(r.oni); }]); }
      return t;
    }
    if (['bar', 'stacked_bar', 'line', 'area', 'stacked_area', 'box_whisker'].indexOf(key) >= 0) {
      call(viz, 'xConfig', { title: etiqueta(dims[0]) });
      call(viz, 'yConfig', { title: etiqueta(yField === '_value' ? '_value' : yField) });
      call(viz, 'tooltipConfig', {
        title: function (d) { return String(d[grupo] != null ? d[grupo] : (d[dimX] != null ? d[dimX] : '')); },
        tbody: tbodyRico()
      });
    } else {
      call(viz, 'tooltipConfig', {
        title: function (d) { return String(d[dims[0]] != null ? d[dims[0]] : ''); },
        tbody: (function () {
          var t = [];
          measures.forEach(function (m) { t.push([etiqueta(m), function (r) { return fmt(r[m]); }]); });
          return t;
        })()
      });
    }

    viz.render();
    return viz;
  }

  function fmt(v) {
    if (typeof v !== 'number') { return v == null ? '' : String(v); }
    try { return v.toLocaleString('es-CO', { maximumFractionDigits: 2 }); } catch (e) { return String(v); }
  }

  /* ---------- Estado "sin datos" ---------- */
  function vacio(node, payload) {
    if (!node) { return null; }
    node.innerHTML = '';
    var v = (payload && payload.view) || {};
    var p = document.createElement('p');
    p.className = 'man-analisis';
    p.style.cssText = 'padding:18px;color:var(--man-mute,#6b7280);text-align:center;font-size:.9rem';
    p.textContent = 'Aún no hay datos para «' + (v.name || 'esta vista') + '». Sincroniza la fuente correspondiente en Monitor Ambiental → Fuentes.';
    node.appendChild(p);
    return null;
  }

  /* ---------- Fallback SVG (si d3plus no carga o falla) ---------- */
  var SVGNS = 'http://www.w3.org/2000/svg';
  function svgEl(name, attrs) {
    var e = document.createElementNS(SVGNS, name);
    for (var k in attrs) { if (Object.prototype.hasOwnProperty.call(attrs, k)) { e.setAttribute(k, attrs[k]); } }
    return e;
  }

  function fallbackSVG(node, payload) {
    if (!node) { return null; }
    var view = (payload && payload.view) || {};
    var chart = (payload && payload.chart) || {};
    var data = (payload && payload.data) || [];
    var dim = (view.dimensions || [])[0];
    var med = (view.measures || [])[0];
    if (!data.length || !med) { return vacio(node, payload); }
    node.innerHTML = '';

    var esLinea = ['line', 'area', 'stacked_area'].indexOf(chart.key) >= 0 || view.category === 'temporal';
    var W = 720, H = 340, m = { t: 16, r: 16, b: 46, l: 44 };
    var iw = W - m.l - m.r, ih = H - m.t - m.b;
    var svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, 'class': 'man-grafico', preserveAspectRatio: 'xMidYMid meet', role: 'img', 'aria-label': view.name || 'Gráfico' });

    var vals = data.map(function (r) { return +r[med] || 0; });
    var maxv = Math.max.apply(null, vals.concat([0]));
    var minv = Math.min.apply(null, vals.concat([0]));
    if (maxv === minv) { maxv = minv + 1; }
    function yy(v) { return m.t + ih - ((v - minv) / (maxv - minv)) * ih; }
    var base = (0 >= minv && 0 <= maxv) ? 0 : minv;
    svg.appendChild(svgEl('line', { x1: m.l, y1: yy(base), x2: m.l + iw, y2: yy(base), stroke: '#e5e7eb', 'stroke-width': 1 }));

    var n = data.length, color = PALETTE[0];
    function px(i) { return esLinea ? (m.l + (n > 1 ? (i / (n - 1)) * iw : iw / 2)) : (m.l + (iw / n) * (i + 0.5)); }
    if (esLinea) {
      var pts = data.map(function (r, i) { return px(i) + ',' + yy(+r[med] || 0); });
      svg.appendChild(svgEl('polyline', { points: pts.join(' '), fill: 'none', stroke: color, 'stroke-width': 2.4, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));
      data.forEach(function (r, i) {
        var c = svgEl('circle', { cx: px(i), cy: yy(+r[med] || 0), r: 2.6, fill: color });
        var ttl = svgEl('title'); ttl.textContent = (r[dim] != null ? r[dim] + ': ' : '') + fmt(+r[med]); c.appendChild(ttl);
        svg.appendChild(c);
      });
    } else {
      var bw = (iw / n) * 0.7;
      data.forEach(function (r, i) {
        var v = +r[med] || 0, x0 = m.l + (iw / n) * (i + 0.15);
        var ya = yy(Math.max(0, v)), yb = yy(Math.min(0, v));
        var rect = svgEl('rect', { x: x0, y: Math.min(ya, yb), width: bw, height: Math.max(1, Math.abs(yb - ya)), fill: color, rx: 2 });
        var ttl = svgEl('title'); ttl.textContent = (r[dim] != null ? r[dim] + ': ' : '') + fmt(v); rect.appendChild(ttl);
        svg.appendChild(rect);
      });
    }

    var paso = Math.max(1, Math.ceil(n / 8));
    data.forEach(function (r, i) {
      if (i % paso !== 0) { return; }
      var t = svgEl('text', { x: px(i), y: H - 26, 'text-anchor': 'middle', 'font-size': 9, fill: '#9aa0aa' });
      t.textContent = String(r[dim] != null ? r[dim] : '');
      svg.appendChild(t);
    });
    var nota = svgEl('text', { x: m.l, y: H - 8, 'font-size': 9, fill: '#9aa0aa' });
    nota.textContent = (view.name || '') + ' · vista simple (d3plus no disponible)';
    svg.appendChild(nota);

    node.appendChild(svg);
    return null;
  }

  window.MANRenderer = { render: render, PALETTE: PALETTE, etiqueta: etiqueta };
})();
