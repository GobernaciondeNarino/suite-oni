/* [man_historico] — episodios ENSO 2015–2024: barras de ONI pico (D3) + tarjetas. */
(function () {
  'use strict';
  var C = window.MANcore;
  var NS = 'http://www.w3.org/2000/svg';

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-historico]'), cargar);
  });

  function cargar(cont) {
    C.rest('/historico')
      .then(function (d) { pintar(cont, d); })
      .catch(function () { C.error(cont, 'No se pudieron cargar los episodios históricos.', function () { cargar(cont); }); });
  }

  function pintar(cont, d) {
    C.quitarSkeleton(cont);
    limpiar(cont);
    var eps = d.episodios || [];
    var cuerpo = C.el('div', 'man-historico__cuerpo');
    cuerpo.appendChild(C.el('p', 'man-titulo', 'Episodios de El Niño que afectaron a Nariño'));

    if (!eps.length) {
      cuerpo.appendChild(C.el('p', 'man-analisis', 'No hay episodios históricos disponibles.'));
    } else {
      cuerpo.appendChild(barras(eps));
      var grid = C.el('div', 'man-cards');
      eps.forEach(function (e) { grid.appendChild(tarjeta(e)); });
      cuerpo.appendChild(grid);
    }

    cont.insertBefore(cuerpo, cont.querySelector('.man-fuentes'));
  }

  function tarjeta(e) {
    var cat = String(e.categoria || '').replace(/_/g, ' ');
    var html = '<p class="man-titulo">' + C.esc(e.periodo || '') + '</p>' +
      '<p>ONI pico <strong>+' + C.esc(e.oni_pico) + '</strong> · ' + C.esc(cat) + '</p>';
    if (e.contexto) { html += '<p class="man-analisis">' + C.esc(e.contexto) + '</p>'; }
    var inar = e.impactos_narino;
    if (inar) {
      var det = inar.municipios_afectados ? (inar.municipios_afectados + ' municipios afectados') : '';
      if (inar.hectareas_afectadas) { det += (det ? ' · ' : '') + C.num(inar.hectareas_afectadas, 0) + ' ha'; }
      if (det) { html += '<p class="man-mute-line">Nariño: ' + C.esc(det) + '</p>'; }
    }
    return C.el('div', 'man-card-item', html);
  }

  function barras(eps) {
    var W = 520, H = 200, m = { t: 16, r: 16, b: 46, l: 36 };
    var iw = W - m.l - m.r, ih = H - m.t - m.b;
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
    svg.setAttribute('class', 'man-grafico');
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');

    var x = d3.scaleBand().domain(eps.map(function (e) { return e.periodo; })).range([m.l, m.l + iw]).padding(0.3);
    var maxv = Math.max.apply(null, eps.map(function (e) { return +e.oni_pico || 0; }));
    var y = d3.scaleLinear().domain([0, Math.max(3, maxv)]).range([m.t + ih, m.t]).nice();

    eps.forEach(function (e) {
      var v = +e.oni_pico || 0;
      var bx = x(e.periodo), by = y(v);
      var rect = document.createElementNS(NS, 'rect');
      rect.setAttribute('x', bx); rect.setAttribute('y', by);
      rect.setAttribute('width', x.bandwidth()); rect.setAttribute('height', (m.t + ih) - by);
      rect.setAttribute('fill', v >= 2 ? '#c62828' : (v >= 1.5 ? '#ef6c00' : '#f9a825'));
      rect.setAttribute('rx', '2');
      svg.appendChild(rect);

      texto(svg, bx + x.bandwidth() / 2, by - 5, '+' + v, 'var(--man-texto,#1a1f2c)', 11);
      texto(svg, bx + x.bandwidth() / 2, H - 16, e.periodo, 'var(--man-mute,#6b7280)', 10);
    });
    return svg;
  }

  function texto(svg, x, y, txt, fill, size) {
    var t = document.createElementNS(NS, 'text');
    t.setAttribute('x', x); t.setAttribute('y', y);
    t.setAttribute('font-size', size); t.setAttribute('fill', fill); t.setAttribute('text-anchor', 'middle');
    t.textContent = txt;
    svg.appendChild(t);
  }

  function limpiar(cont) {
    var x = cont.querySelector('.man-historico__cuerpo'); if (x) { x.parentNode.removeChild(x); }
    var e = cont.querySelector('.man-error'); if (e) { e.parentNode.removeChild(e); }
  }
})();
