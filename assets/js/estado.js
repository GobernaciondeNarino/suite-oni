/* [man_estado] — semáforo ENSO (gauge D3) + condiciones + texto de análisis. */
(function () {
  'use strict';
  var C = window.MANcore;
  var NS = 'http://www.w3.org/2000/svg';

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-estado]'), cargar);
  });

  function cargar(cont) {
    var div = cont.getAttribute('data-municipio') || 'departamento';
    var prom = (div === 'departamento') ? C.rest('/oni') : C.rest('/municipio/' + encodeURIComponent(div));
    prom.then(function (d) { pintar(cont, div, d); })
      .catch(function () { C.error(cont, 'No se pudo cargar el estado del fenómeno.', function () { cargar(cont); }); });
  }

  function pintar(cont, div, d) {
    C.quitarSkeleton(cont);
    limpiar(cont);

    var oni, fase, intens, texto, riesgo = null, nivelEt = '', color = '';
    if (div === 'departamento') {
      oni = +d.actual.oni; fase = d.actual.fase; intens = d.actual.intensidad;
      texto = 'Estado del fenómeno ENSO para el departamento de Nariño. El ONI corresponde al promedio oficial de NOAA/CPC.';
    } else {
      oni = +d.oni; fase = d.fase; intens = d.intensidad; texto = d.texto_analisis;
      riesgo = d.riesgo; nivelEt = d.nivel_etiqueta; color = d.color;
    }

    var cuerpo = C.el('div', 'man-estado__cuerpo');
    cuerpo.appendChild(gauge(oni, fase));

    var info = C.el('div', 'man-estado__info');
    info.appendChild(C.el('p', 'man-valores',
      'ONI <strong>' + (oni >= 0 ? '+' : '') + C.num(oni, 1) + ' °C</strong> · Fase <strong style="color:' + faseColor(oni) + '">' + C.esc(fase) + '</strong> · ' + C.esc(intens)));
    if (riesgo != null) {
      info.appendChild(C.el('p', null, 'Riesgo municipal: <span class="man-chip" style="background:' + C.esc(color) + '">' + C.esc(nivelEt) + ' · ' + C.num(riesgo, 2) + '</span>'));
    }
    info.appendChild(C.el('p', 'man-analisis', C.esc(texto)));
    cuerpo.appendChild(info);

    cont.insertBefore(cuerpo, cont.querySelector('.man-fuentes'));
  }

  function gauge(oni, fase) {
    var w = 260, h = 150, cx = w / 2, cy = h - 12, r = 110, ri = r - 16;
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
    svg.setAttribute('class', 'man-gauge');
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'Semáforo ENSO. ONI ' + oni + ', fase ' + fase);

    var scale = d3.scaleLinear().domain([-2, 2]).range([-Math.PI / 2, Math.PI / 2]);
    var arc = d3.arc().innerRadius(ri).outerRadius(r);
    var zonas = [[-2, -0.5, '#1565c0'], [-0.5, 0.5, '#2e7d32'], [0.5, 2, '#c62828']];

    zonas.forEach(function (z) {
      var p = document.createElementNS(NS, 'path');
      p.setAttribute('d', arc({ startAngle: scale(z[0]), endAngle: scale(z[1]) }));
      p.setAttribute('transform', 'translate(' + cx + ',' + cy + ')');
      p.setAttribute('fill', z[2]);
      p.setAttribute('opacity', '0.85');
      svg.appendChild(p);
    });

    var a = scale(Math.max(-2, Math.min(2, oni)));
    var x2 = cx + (ri - 6) * Math.sin(a), y2 = cy - (ri - 6) * Math.cos(a);
    var ln = document.createElementNS(NS, 'line');
    ln.setAttribute('x1', cx); ln.setAttribute('y1', cy);
    ln.setAttribute('x2', x2); ln.setAttribute('y2', y2);
    ln.setAttribute('stroke', 'var(--man-texto,#1a1f2c)');
    ln.setAttribute('stroke-width', '3'); ln.setAttribute('stroke-linecap', 'round');
    svg.appendChild(ln);

    var dot = document.createElementNS(NS, 'circle');
    dot.setAttribute('cx', cx); dot.setAttribute('cy', cy); dot.setAttribute('r', '5');
    dot.setAttribute('fill', 'var(--man-texto,#1a1f2c)');
    svg.appendChild(dot);

    etiqueta(svg, cx - r + 8, cy + 12, 'La Niña', 'start');
    etiqueta(svg, cx, cy - r + 2, 'Neutral', 'middle');
    etiqueta(svg, cx + r - 8, cy + 12, 'El Niño', 'end');
    return svg;
  }

  function etiqueta(svg, x, y, txt, anchor) {
    var t = document.createElementNS(NS, 'text');
    t.setAttribute('x', x); t.setAttribute('y', y);
    t.setAttribute('font-size', '11');
    t.setAttribute('fill', 'var(--man-mute,#6b7280)');
    t.setAttribute('text-anchor', anchor);
    t.textContent = txt;
    svg.appendChild(t);
  }

  function faseColor(oni) { return oni >= 0.5 ? '#c62828' : (oni <= -0.5 ? '#1565c0' : '#2e7d32'); }

  function limpiar(cont) {
    var x = cont.querySelector('.man-estado__cuerpo'); if (x) { x.parentNode.removeChild(x); }
    var e = cont.querySelector('.man-error'); if (e) { e.parentNode.removeChild(e); }
  }
})();
