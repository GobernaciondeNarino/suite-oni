/* [man_pronostico] — pronóstico 7–16 días (Open-Meteo en vivo) con línea D3,
   banda de incertidumbre, precipitación y texto de análisis. */
(function () {
  'use strict';
  var C = window.MANcore;
  var NS = 'http://www.w3.org/2000/svg';

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-pronostico]'), function (cont) {
      cargar(cont);
      // Permite recargar con otro municipio (lo usa el selector [man_pronostico_select]).
      cont.addEventListener('man:recargar', function () { cargar(cont); });
    });
  });

  function cargar(cont) {
    var div = cont.getAttribute('data-municipio');
    var dias = Math.max(1, Math.min(16, parseInt(cont.getAttribute('data-dias'), 10) || 7));
    var mun = C.municipio(div);
    if (!mun) { C.error(cont, 'Municipio no válido.'); return; }

    var url = 'https://api.open-meteo.com/v1/forecast?latitude=' + mun.lat + '&longitude=' + mun.lon +
      '&daily=temperature_2m_max,temperature_2m_min,precipitation_sum&forecast_days=' + dias +
      '&timezone=America%2FBogota';

    C.externo(url)
      .then(function (d) { pintar(cont, mun, dias, d); })
      .catch(function () { C.error(cont, 'No se pudo cargar el pronóstico (Open-Meteo).', function () { cargar(cont); }); });
  }

  function pintar(cont, mun, dias, d) {
    C.quitarSkeleton(cont);
    limpiar(cont);

    var dd = d.daily || {};
    var fechas = dd.time || [], tmax = dd.temperature_2m_max || [], tmin = dd.temperature_2m_min || [], prec = dd.precipitation_sum || [];
    if (!fechas.length) { C.error(cont, 'Sin datos de pronóstico.'); return; }

    var cuerpo = C.el('div', 'man-pronostico__cuerpo');
    cuerpo.appendChild(C.el('p', 'man-titulo', 'Pronóstico ' + C.esc(mun.nombre) + ' · ' + dias + ' días'));
    var tip = C.el('div', 'man-pronostico__tip');
    tip.hidden = true;
    cuerpo.appendChild(grafico(cuerpo, tip, fechas, tmax, tmin, prec));
    cuerpo.appendChild(tip);

    var tprom = promedio(tmax), ptot = total(prec), n = fechas.length;
    var tend = tmax[n - 1] > tmax[0] ? 'Tendencia térmica al alza.' : (tmax[n - 1] < tmax[0] ? 'Tendencia térmica a la baja.' : 'Temperatura estable.');
    cuerpo.appendChild(C.el('p', 'man-analisis',
      'Pronóstico a ' + dias + ' días para ' + C.esc(mun.nombre) + '. Temperatura máxima media de ' + C.num(tprom, 1) +
      ' °C y precipitación acumulada estimada de ' + C.num(ptot, 0) + ' mm. ' + tend +
      ' Las cifras a más de 7 días son probables, no certezas: consulte el boletín vigente del IDEAM.'));

    cont.insertBefore(cuerpo, cont.querySelector('.man-fuentes'));
  }

  function grafico(cont, tip, fechas, tmax, tmin, prec) {
    var W = 640, H = 260, m = { t: 16, r: 30, b: 40, l: 34 };
    var iw = W - m.l - m.r, ih = H - m.t - m.b;
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
    svg.setAttribute('class', 'man-grafico');
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');

    var x = d3.scalePoint().domain(fechas).range([m.l, m.l + iw]);
    var allT = tmax.concat(tmin).filter(function (v) { return v != null; });
    var y = d3.scaleLinear().domain([Math.min.apply(null, allT) - 1, Math.max.apply(null, allT) + 1]).range([m.t + ih, m.t]).nice();
    var maxP = Math.max(1, Math.max.apply(null, prec.filter(function (v) { return v != null; })));
    var yp = d3.scaleLinear().domain([0, maxP]).range([m.t + ih, m.t]);

    // Barras de precipitación.
    var bw = Math.max(3, (iw / fechas.length) * 0.5);
    prec.forEach(function (p, i) {
      if (p == null) { return; }
      var by = yp(p);
      var rect = document.createElementNS(NS, 'rect');
      rect.setAttribute('x', x(fechas[i]) - bw / 2);
      rect.setAttribute('y', by);
      rect.setAttribute('width', bw);
      rect.setAttribute('height', (m.t + ih) - by);
      rect.setAttribute('fill', 'var(--man-acento-tecnico,#003087)');
      rect.setAttribute('opacity', '0.18');
      svg.appendChild(rect);
    });

    // Banda tmin–tmax.
    var pts = [];
    for (var i = 0; i < fechas.length; i++) { if (tmax[i] != null) { pts.push(x(fechas[i]) + ',' + y(tmax[i])); } }
    for (var j = fechas.length - 1; j >= 0; j--) { if (tmin[j] != null) { pts.push(x(fechas[j]) + ',' + y(tmin[j])); } }
    var poly = document.createElementNS(NS, 'polygon');
    poly.setAttribute('points', pts.join(' '));
    poly.setAttribute('fill', 'var(--man-acento,#10A13B)');
    poly.setAttribute('opacity', '0.12');
    svg.appendChild(poly);

    linea(svg, fechas, tmax, x, y, 'var(--man-acento,#10A13B)', 2.4);
    linea(svg, fechas, tmin, x, y, 'var(--man-mute,#6b7280)', 1.4);

    // Eje Y (ticks).
    y.ticks(4).forEach(function (t) {
      var tx = document.createElementNS(NS, 'text');
      tx.setAttribute('x', m.l - 6); tx.setAttribute('y', y(t) + 3);
      tx.setAttribute('font-size', '10'); tx.setAttribute('fill', 'var(--man-mute,#6b7280)'); tx.setAttribute('text-anchor', 'end');
      tx.textContent = t;
      svg.appendChild(tx);
    });

    // Etiquetas X.
    var paso = Math.ceil(fechas.length / 7);
    fechas.forEach(function (f, i) {
      if (i % paso !== 0) { return; }
      var t = document.createElementNS(NS, 'text');
      t.setAttribute('x', x(f)); t.setAttribute('y', H - 14);
      t.setAttribute('font-size', '10'); t.setAttribute('fill', 'var(--man-mute,#6b7280)'); t.setAttribute('text-anchor', 'middle');
      t.textContent = f.slice(5);
      svg.appendChild(t);
    });

    // Capa de interacción: tooltip por día (hover).
    var bandW = iw / fechas.length;
    fechas.forEach(function (f, i) {
      var r = document.createElementNS(NS, 'rect');
      r.setAttribute('x', x(f) - bandW / 2); r.setAttribute('y', m.t);
      r.setAttribute('width', bandW); r.setAttribute('height', ih);
      r.setAttribute('fill', 'transparent');
      r.style.cursor = 'crosshair';
      r.addEventListener('mousemove', function (e) {
        tip.innerHTML = '<strong>' + C.esc(f) + '</strong>' +
          '<span>Máx: ' + C.num(tmax[i], 1) + ' °C · Mín: ' + C.num(tmin[i], 1) + ' °C</span>' +
          '<span>Lluvia: ' + C.num(prec[i], 1) + ' mm</span>';
        tip.hidden = false;
        var cr = cont.getBoundingClientRect();
        tip.style.left = (e.clientX - cr.left) + 'px';
        tip.style.top = (e.clientY - cr.top) + 'px';
      });
      r.addEventListener('mouseleave', function () { tip.hidden = true; });
      svg.appendChild(r);
    });

    return svg;
  }

  function linea(svg, fechas, vals, x, y, color, ancho) {
    var p = document.createElementNS(NS, 'path');
    var d = '';
    for (var i = 0; i < fechas.length; i++) {
      if (vals[i] == null) { continue; }
      d += (d ? 'L' : 'M') + x(fechas[i]) + ',' + y(vals[i]);
    }
    p.setAttribute('d', d);
    p.setAttribute('fill', 'none');
    p.setAttribute('stroke', color);
    p.setAttribute('stroke-width', ancho);
    p.setAttribute('stroke-linejoin', 'round');
    svg.appendChild(p);
  }

  function promedio(a) {
    var v = a.filter(function (x) { return x != null; });
    return v.length ? v.reduce(function (s, x) { return s + x; }, 0) / v.length : 0;
  }
  function total(a) {
    return a.filter(function (x) { return x != null; }).reduce(function (s, x) { return s + x; }, 0);
  }
  function limpiar(cont) {
    var x = cont.querySelector('.man-pronostico__cuerpo'); if (x) { x.parentNode.removeChild(x); }
    var e = cont.querySelector('.man-error'); if (e) { e.parentNode.removeChild(e); }
  }
})();
