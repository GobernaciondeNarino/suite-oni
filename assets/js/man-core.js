/* Núcleo compartido de los shortcodes: helpers de fetch a la REST interna,
   manejo de skeleton/errores y utilidades de formato. Expone window.MANcore. */
(function () {
  'use strict';

  var MAN = window.MAN || { rest: '', nonce: '', mesActual: '' };

  /** GET a la REST interna del plugin.
      NOTA: no se envía X-WP-Nonce a propósito — todos los endpoints son
      públicos de solo lectura y un nonce caducado servido por la caché de
      página provocaría 403 (rest_cookie_invalid_nonce) en visitantes. */
  function rest(path, params) {
    var url = MAN.rest + path;
    if (params) {
      var q = Object.keys(params)
        .filter(function (k) { return params[k] !== undefined && params[k] !== null && params[k] !== ''; })
        .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
        .join('&');
      if (q) { url += (url.indexOf('?') >= 0 ? '&' : '?') + q; }
    }
    return fetch(url).then(function (r) {
      if (!r.ok) { throw new Error('HTTP ' + r.status); }
      return r.json();
    });
  }

  /** GET a una URL pública externa (Open-Meteo). */
  function externo(url) {
    return fetch(url).then(function (r) {
      if (!r.ok) { throw new Error('HTTP ' + r.status); }
      return r.json();
    });
  }

  /** Formato numérico es-CO. */
  function num(v, dec) {
    var d = dec || 0;
    try {
      return Number(v).toLocaleString('es-CO', { minimumFractionDigits: d, maximumFractionDigits: d });
    } catch (e) {
      return String(v);
    }
  }

  /** Crea un elemento con clase y HTML opcionales. */
  function el(tag, cls, html) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (html != null) { e.innerHTML = html; }
    return e;
  }

  /** Escapa texto para inserción segura en el DOM. */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /** Quita el skeleton de carga de un contenedor. */
  function quitarSkeleton(cont) {
    var s = cont.querySelector('.man-skeleton');
    if (s) { s.parentNode.removeChild(s); }
  }

  /** Muestra un estado de error elegante con botón de reintento. */
  function error(cont, msg, reintentar) {
    quitarSkeleton(cont);
    var prev = cont.querySelector('.man-error');
    if (prev) { prev.parentNode.removeChild(prev); }
    var base = esc(msg || 'No se pudieron cargar los datos. Intente de nuevo.');
    // Pista de contexto: si el navegador está sin conexión, se aclara.
    var pista = (typeof navigator !== 'undefined' && navigator.onLine === false)
      ? '<span class="man-mute-line">Parece que no hay conexión a internet.</span>'
      : '';
    var box = el('div', 'man-error', '<p>' + base + '</p>' + pista);
    box.setAttribute('role', 'alert');
    var b = el('button', 'man-btn', 'Reintentar');
    b.type = 'button';
    b.addEventListener('click', function () {
      if (box.parentNode) { box.parentNode.removeChild(box); }
      if (typeof reintentar === 'function') { reintentar(); }
    });
    box.appendChild(b);
    cont.insertBefore(box, cont.firstChild);
  }

  /** Ejecuta fn cuando el DOM esté listo. */
  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  /** Dibuja un gráfico de línea simple (SVG puro, sin dependencias). */
  function lineaSimple(fechas, vals, opts) {
    opts = opts || {};
    var NS = 'http://www.w3.org/2000/svg';
    var W = opts.w || 560, H = opts.h || 220, m = { t: 14, r: 16, b: 38, l: 40 };
    var iw = W - m.l - m.r, ih = H - m.t - m.b, n = fechas.length;
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
    svg.setAttribute('class', 'man-grafico');
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');

    var valid = vals.filter(function (v) { return v != null; });
    if (!valid.length) { return svg; }
    var color = opts.color || 'var(--man-acento,#10A13B)';
    var minv = Math.min.apply(null, valid), maxv = Math.max.apply(null, valid);
    if (minv === maxv) { minv -= 1; maxv += 1; }
    var x = function (i) { return m.l + (n <= 1 ? iw / 2 : iw * i / (n - 1)); };
    var y = function (v) { return m.t + ih - ih * ((v - minv) / (maxv - minv)); };

    if (opts.area) {
      var ap = 'M' + x(0) + ',' + (m.t + ih);
      for (var a = 0; a < n; a++) { if (vals[a] != null) { ap += 'L' + x(a) + ',' + y(vals[a]); } }
      ap += 'L' + x(n - 1) + ',' + (m.t + ih) + 'Z';
      var area = document.createElementNS(NS, 'path');
      area.setAttribute('d', ap); area.setAttribute('fill', color); area.setAttribute('opacity', '0.10');
      svg.appendChild(area);
    }

    var d = '';
    for (var i = 0; i < n; i++) { if (vals[i] == null) { continue; } d += (d ? 'L' : 'M') + x(i) + ',' + y(vals[i]); }
    var path = document.createElementNS(NS, 'path');
    path.setAttribute('d', d); path.setAttribute('fill', 'none'); path.setAttribute('stroke', color);
    path.setAttribute('stroke-width', opts.width || 2.2); path.setAttribute('stroke-linejoin', 'round');
    svg.appendChild(path);

    [minv, (minv + maxv) / 2, maxv].forEach(function (t) {
      var ty = document.createElementNS(NS, 'text');
      ty.setAttribute('x', m.l - 6); ty.setAttribute('y', y(t) + 3);
      ty.setAttribute('font-size', '10'); ty.setAttribute('fill', 'var(--man-mute,#6b7280)'); ty.setAttribute('text-anchor', 'end');
      ty.textContent = Math.round(t * 10) / 10;
      svg.appendChild(ty);
    });
    var paso = Math.max(1, Math.ceil(n / 6));
    for (var j = 0; j < n; j += paso) {
      var lb = document.createElementNS(NS, 'text');
      lb.setAttribute('x', x(j)); lb.setAttribute('y', H - 14);
      lb.setAttribute('font-size', '10'); lb.setAttribute('fill', 'var(--man-mute,#6b7280)'); lb.setAttribute('text-anchor', 'middle');
      lb.textContent = String(fechas[j]).slice(5);
      svg.appendChild(lb);
    }
    return svg;
  }

  /** Busca un municipio por DIVIPOLA en el arreglo global. */
  function municipio(divipola) {
    var lista = window.MUNICIPIOS_NARINO || [];
    for (var i = 0; i < lista.length; i++) {
      if (lista[i].divipola === divipola) { return lista[i]; }
    }
    return null;
  }

  /** Línea/área interactiva con D3plus (tooltip, ejes, leyenda) si está
      disponible; si no, cae al SVG simple. Renderiza dentro de `node`.
      opts: { area, color(hex), xTitle, yTitle, serie }. */
  function lineaInteractiva(node, fechas, vals, opts) {
    opts = opts || {};
    if (!node) { return; }
    node.innerHTML = '';
    if (window.d3plus && (opts.area ? d3plus.AreaPlot : d3plus.LinePlot)) {
      try {
        var rows = [];
        for (var i = 0; i < fechas.length; i++) {
          if (vals[i] != null) { rows.push({ x: String(fechas[i]), y: +vals[i], serie: opts.serie || 'serie' }); }
        }
        var Cls = opts.area ? d3plus.AreaPlot : d3plus.LinePlot;
        var viz = new Cls().select(node).data(rows).groupBy('serie').x('x').y('y');
        var g = function (m, a) { if (typeof viz[m] === 'function') { viz[m](a); } };
        g('detectResize', true);
        g('legend', false);
        g('xConfig', { title: opts.xTitle || '' });
        g('yConfig', { title: opts.yTitle || '' });
        g('color', function () { return opts.color || '#003087'; });
        g('tooltipConfig', {
          title: function () { return opts.yTitle || ''; },
          tbody: [
            [opts.xTitle || 'x', function (d) { return d.x; }],
            [opts.yTitle || 'y', function (d) { return num(d.y, 2); }]
          ]
        });
        viz.render();
        return;
      } catch (e) { /* cae al SVG simple */ }
    }
    node.appendChild(lineaSimple(fechas, vals, opts));
  }

  window.MANcore = {
    rest: rest,
    lineaInteractiva: lineaInteractiva,
    externo: externo,
    num: num,
    el: el,
    esc: esc,
    quitarSkeleton: quitarSkeleton,
    error: error,
    ready: ready,
    lineaSimple: lineaSimple,
    municipio: municipio,
    MAN: MAN
  };
})();
