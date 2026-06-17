/* [man_estaciones] — estaciones hidrológicas IDEAM/FEWS de Nariño.
   Mapa Leaflet con marcadores por nivel de alerta; al hacer clic en una
   estación muestra su detalle y la serie de nivel (proxy REST del plugin). */
(function () {
  'use strict';
  var C = window.MANcore;

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-estaciones]'), init);
  });

  function init(cont) {
    if (typeof L === 'undefined') { C.error(cont, 'Mapa no disponible (Leaflet).'); return; }
    var variable = cont.getAttribute('data-variable') || 'nivel';
    C.rest('/estaciones', { variable: variable })
      .then(function (d) { montar(cont, (d && d.estaciones) || []); })
      .catch(function () { C.error(cont, 'No se pudieron cargar las estaciones IDEAM/FEWS.', function () { init(cont); }); });
  }

  function colorAlerta(a) { return a === 'alta' ? '#C0392B' : (a === 'media' ? '#F1C40F' : '#2ECC71'); }

  function montar(cont, estaciones) {
    C.quitarSkeleton(cont);
    var mapEl = cont.querySelector('.man-estaciones__mapa');
    var info = cont.querySelector('.man-estaciones__info');
    if (!mapEl || !info) { return; }

    var map = L.map(mapEl, { scrollWheelZoom: false }).setView([1.3, -77.7], 7);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap', maxZoom: 14 }).addTo(map);

    // Capa de límites debajo de los marcadores (no intercepta los clics).
    map.createPane('manLimites');
    map.getPane('manLimites').style.zIndex = 350;
    var geoMun = cont.getAttribute('data-geojson');
    if (geoMun) {
      C.externo(geoMun).then(function (geo) {
        L.geoJSON(geo, { pane: 'manLimites', interactive: false, style: { color: '#6b7280', weight: 0.8, opacity: 0.6, fill: false } }).addTo(map);
      }).catch(function () { /* límites opcionales */ });
    }
    var geoDep = cont.getAttribute('data-geojson-depto');
    if (geoDep) {
      C.externo(geoDep).then(function (dep) {
        L.geoJSON(dep, { pane: 'manLimites', interactive: false, style: { color: '#1a1f2c', weight: 2.5, opacity: 0.95, fill: false } }).addTo(map);
      }).catch(function () { /* demarcación opcional */ });
    }

    var puntos = [];
    estaciones.forEach(function (e) {
      if (e.lat == null || e.lng == null) { return; }
      var m = L.circleMarker([e.lat, e.lng], { radius: 7, color: '#fff', weight: 1.5, fillColor: colorAlerta(e.nivel_alerta), fillOpacity: 0.9 }).addTo(map);
      m.bindTooltip(C.esc(e.estacion || '') + (e.corriente ? ' · río ' + C.esc(e.corriente) : ''));
      m.on('click', function () { detalle(info, e); });
      puntos.push([e.lat, e.lng]);
    });
    if (puntos.length) { map.fitBounds(puntos, { padding: [20, 20], maxZoom: 9 }); }
    if (estaciones.length) { detalle(info, estaciones[0]); }
    setTimeout(function () { map.invalidateSize(); }, 250);
  }

  function categoriaIca(v) {
    if (v == null) { return ''; }
    if (v <= 0.25) { return 'muy mala'; }
    if (v <= 0.50) { return 'mala'; }
    if (v <= 0.70) { return 'regular'; }
    if (v <= 0.90) { return 'aceptable'; }
    return 'buena';
  }

  function detalle(info, e) {
    info.innerHTML = '';
    var u = e.unidad || '';
    var esIca = u === 'ICA';
    info.appendChild(C.el('p', 'man-estaciones__nombre', C.esc(e.estacion || 'Estación')));
    info.appendChild(C.el('p', 'man-estaciones__meta',
      (e.corriente ? 'Río ' + C.esc(e.corriente) + ' · ' : '') + C.esc(e.municipio || '') + ' · alerta: ' + C.esc(e.nivel_alerta || '—')));
    if (e.valor != null) {
      var detalleVal = esIca
        ? 'Índice de calidad del agua (ICA): ' + C.num(e.valor, 2) + ' · ' + categoriaIca(e.valor)
        : 'Último valor: ' + C.num(e.valor, 2) + ' ' + C.esc(u) + (e.umbral != null ? ' · umbral ' + C.num(e.umbral, 2) + ' ' + C.esc(u) : '');
      info.appendChild(C.el('p', 'man-estaciones__nivel', detalleVal));
    }
    // La red de calidad no expone serie temporal por el proxy: solo el último valor.
    if (e.tipo_serie) {
      var lienzo = C.el('div', 'man-estaciones__serie');
      info.appendChild(lienzo);
      cargarSerie(lienzo, e.id, e.tipo_serie);
    } else if (esIca) {
      info.appendChild(C.el('p', 'man-mute-line', 'Escala ICA 0–1: ≤0,25 muy mala, ≤0,50 mala, ≤0,70 regular, ≤0,90 aceptable, >0,90 buena.'));
    }
  }

  function cargarSerie(lienzo, cod, tipo) {
    if (!cod) { lienzo.appendChild(C.el('p', 'man-mute-line', 'Estación sin código de serie.')); return; }
    lienzo.appendChild(C.el('p', 'man-mute-line', 'Cargando serie…'));
    C.rest('/estacion-serie', { cod: cod, tipo: tipo || 'H' })
      .then(function (d) {
        lienzo.innerHTML = '';
        var series = (d && d.series) || [];
        var s = null;
        series.forEach(function (x) {
          if (!x.datos || !x.datos.length) { return; }
          if (!s) { s = x; }
          if (x.clave.charAt(0) === (tipo || 'H')) { s = x; } // prefiere la serie del tipo pedido
        });
        if (!s) { lienzo.appendChild(C.el('p', 'man-mute-line', 'Sin serie disponible para esta estación.')); return; }
        lienzo.appendChild(C.el('p', 'man-estaciones__serie-titulo', C.esc(s.label)));

        var chartDiv = C.el('div', 'man-estaciones__chart');
        chartDiv.style.minHeight = '230px';
        lienzo.appendChild(chartDiv);

        // Gráfico secundario en D3plus.
        if (typeof d3plus !== 'undefined' && d3plus.LinePlot) {
          try {
            new d3plus.LinePlot()
              .select(chartDiv).data(s.datos)
              .groupBy(function () { return s.label; }).x('fecha').y('valor')
              .color(function () { return '#0080C3'; })
              .legend(false).detectResize(true)
              .xConfig({ title: 'Fecha' }).yConfig({ title: s.label })
              .tooltipConfig({ tbody: [['Fecha', function (d) { return d.fecha; }], [s.label, function (d) { return d.valor; }]] })
              .render();
            return;
          } catch (e) { /* cae a SVG */ }
        }
        var vals = s.datos.map(function (p) { return p.valor; });
        var fechas = s.datos.map(function (p) { return p.fecha; });
        if (typeof C.lineaSimple === 'function') {
          chartDiv.appendChild(C.lineaSimple(fechas, vals, { area: true, color: '#0080C3' }));
        } else {
          chartDiv.appendChild(svgLinea(vals, '#0080C3'));
        }
      })
      .catch(function () {
        lienzo.innerHTML = '';
        lienzo.appendChild(C.el('p', 'man-mute-line', 'No se pudo cargar la serie de la estación.'));
      });
  }

  /* Fallback SVG simple si MANcore no trae lineaSimple. */
  function svgLinea(vals, color) {
    var NS = 'http://www.w3.org/2000/svg';
    var W = 560, H = 160, m = 8;
    var min = Math.min.apply(null, vals), max = Math.max.apply(null, vals);
    if (min === max) { max = min + 1; }
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
    svg.setAttribute('class', 'man-grafico');
    var n = vals.length, pts = vals.map(function (v, i) {
      var x = m + (n > 1 ? (i / (n - 1)) * (W - 2 * m) : (W - 2 * m) / 2);
      var y = m + (1 - (v - min) / (max - min)) * (H - 2 * m);
      return x + ',' + y;
    });
    var pl = document.createElementNS(NS, 'polyline');
    pl.setAttribute('points', pts.join(' '));
    pl.setAttribute('fill', 'none'); pl.setAttribute('stroke', color); pl.setAttribute('stroke-width', '2');
    svg.appendChild(pl);
    return svg;
  }
})();
