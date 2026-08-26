/* [man_salud] — enfermedades sensibles al clima en Nariño (SIVIGILA/INS).
   Muestra el acumulado por grupo (ETV / ETA), la serie anual, el reparto por
   enfermedad y los municipios más afectados. Degrada con elegancia si la
   fuente aún no se ha sincronizado. */
(function () {
  'use strict';
  var C = window.MANcore;

  var GRUPOS = {
    ETV: { etiqueta: 'Transmitidas por vectores', color: '#c62828' },
    ETA: { etiqueta: 'Por agua y alimentos', color: '#1565c0' }
  };

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-salud]'), cargar);
  });

  function cargar(cont) {
    var grupo = cont.getAttribute('data-grupo') || '';
    C.rest('/salud', grupo ? { grupo: grupo } : null)
      .then(function (d) { pintar(cont, d, grupo); })
      .catch(function () { C.error(cont, 'No se pudieron cargar los datos de salud.', function () { cargar(cont); }); });
  }

  function pintar(cont, d, grupo) {
    limpiar(cont);
    var cuerpo = C.el('div', 'man-salud__cuerpo');
    cuerpo.appendChild(C.el('p', 'man-titulo', 'Enfermedades sensibles al clima'));

    if (!d || !d.disponible) {
      cuerpo.appendChild(C.el('p', 'man-analisis', C.esc(
        (d && d.mensaje) ? d.mensaje
          : 'La fuente SIVIGILA aún no se ha sincronizado. Un administrador puede activarla en Monitor Ambiental → Fuentes.'
      )));
      cont.insertBefore(cuerpo, cont.querySelector('.man-fuentes'));
      return;
    }

    if (d.aviso) {
      var av = C.el('p', 'man-aviso', C.esc(d.aviso));
      av.setAttribute('role', 'status');
      cuerpo.appendChild(av);
    }

    var cob = d.cobertura || {};
    if (cob.desde && cob.hasta) {
      cuerpo.appendChild(C.el('p', 'man-mute-line',
        'Casos notificados en Nariño · ' + C.esc(cob.desde) + '–' + C.esc(cob.hasta)));
    }

    cuerpo.appendChild(cifras(d, grupo));

    if (d.anual && d.anual.length) {
      cuerpo.appendChild(C.el('p', 'man-subtitulo', 'Evolución anual'));
      cuerpo.appendChild(serieAnual(d.anual, grupo));
    }

    if (d.eventos && d.eventos.length) {
      cuerpo.appendChild(C.el('p', 'man-subtitulo', 'Por enfermedad'));
      cuerpo.appendChild(barrasEventos(d.eventos));
    }

    if (d.municipios && d.municipios.length) {
      cuerpo.appendChild(C.el('p', 'man-subtitulo', 'Municipios más afectados' +
        (cob.ventana_municipal ? ' (' + C.esc(cob.ventana_municipal) + ')' : '')));
      cuerpo.appendChild(barrasMunicipios(d.municipios.slice(0, 8)));
    }

    if (d.nota_ensayo) {
      cuerpo.appendChild(C.el('p', 'man-analisis', C.esc(d.nota_ensayo)));
    }

    cont.insertBefore(cuerpo, cont.querySelector('.man-fuentes'));
  }

  /* ---------- Cifras clave ---------- */
  function cifras(d, grupo) {
    var t = d.totales || {};
    var fila = C.el('div', 'man-salud__cifras');

    if (grupo !== 'ETA') { fila.appendChild(cifra('Vectores (ETV)', t.ETV, GRUPOS.ETV.color)); }
    if (grupo !== 'ETV') { fila.appendChild(cifra('Agua y alimentos (ETA)', t.ETA, GRUPOS.ETA.color)); }

    var muertes = (t.muertes_dengue || 0) + (t.muertes_malaria || 0);
    if (muertes > 0 && !grupo) {
      fila.appendChild(cifra('Muertes notificadas', muertes, 'var(--man-mute,#6b7280)'));
    }
    return fila;
  }

  function cifra(rotulo, valor, color) {
    var box = C.el('div', 'man-salud__cifra');
    box.appendChild(C.el('span', 'man-salud__cifra-rotulo', C.esc(rotulo)));
    var n = C.el('strong', null, C.num(valor || 0, 0));
    n.style.color = color;
    box.appendChild(n);
    return box;
  }

  /* ---------- Serie anual (barras apiladas, SVG puro) ---------- */
  function serieAnual(anual, grupo) {
    var NS = 'http://www.w3.org/2000/svg';
    var W = 640, H = 190, m = { t: 12, r: 10, b: 26, l: 46 };
    var iw = W - m.l - m.r, ih = H - m.t - m.b;
    var n = anual.length;

    var verETV = grupo !== 'ETA', verETA = grupo !== 'ETV';
    var max = 0;
    anual.forEach(function (a) {
      var v = (verETV ? +a.ETV : 0) + (verETA ? +a.ETA : 0);
      if (v > max) { max = v; }
    });
    if (!max) { max = 1; }

    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
    svg.setAttribute('class', 'man-grafico');
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'Casos por año, de ' + anual[0].anio + ' a ' + anual[n - 1].anio);

    // Rejilla y eje Y.
    [0, 0.5, 1].forEach(function (f) {
      var y = m.t + ih - ih * f;
      var ln = document.createElementNS(NS, 'line');
      ln.setAttribute('x1', m.l); ln.setAttribute('x2', m.l + iw);
      ln.setAttribute('y1', y); ln.setAttribute('y2', y);
      ln.setAttribute('stroke', 'var(--man-borde-color,#e5e7eb)');
      ln.setAttribute('stroke-width', '1');
      svg.appendChild(ln);
      texto(svg, m.l - 6, y + 3, C.num(Math.round(max * f), 0), 'end', 9);
    });

    var bw = iw / n, ancho = Math.max(3, bw * 0.62);
    anual.forEach(function (a, i) {
      var x = m.l + bw * i + (bw - ancho) / 2;
      var y = m.t + ih;
      [['ETA', verETA], ['ETV', verETV]].forEach(function (par) {
        if (!par[1]) { return; }
        var v = +a[par[0]] || 0;
        var h = ih * (v / max);
        y -= h;
        if (h <= 0) { return; }
        var r = document.createElementNS(NS, 'rect');
        r.setAttribute('x', x); r.setAttribute('y', y);
        r.setAttribute('width', ancho); r.setAttribute('height', h);
        r.setAttribute('fill', GRUPOS[par[0]].color);
        r.setAttribute('opacity', par[0] === 'ETA' ? '0.85' : '1');
        var tt = document.createElementNS(NS, 'title');
        tt.textContent = a.anio + ' · ' + GRUPOS[par[0]].etiqueta + ': ' + C.num(v, 0) + ' casos';
        r.appendChild(tt);
        svg.appendChild(r);
      });
      // Etiqueta de año: una de cada tres para que no se amontonen.
      if (i % 3 === 0 || i === n - 1) {
        texto(svg, m.l + bw * i + bw / 2, H - 8, a.anio, 'middle', 9);
      }
    });

    var fig = C.el('div', 'man-salud__grafico');
    fig.appendChild(svg);
    fig.appendChild(leyenda(verETV, verETA));
    return fig;
  }

  function leyenda(verETV, verETA) {
    var box = C.el('div', 'man-salud__leyenda');
    [['ETV', verETV], ['ETA', verETA]].forEach(function (par) {
      if (!par[1]) { return; }
      var it = C.el('span', 'man-salud__leyenda-item');
      var sw = C.el('i', 'man-salud__swatch');
      sw.style.background = GRUPOS[par[0]].color;
      it.appendChild(sw);
      it.appendChild(document.createTextNode(GRUPOS[par[0]].etiqueta));
      box.appendChild(it);
    });
    return box;
  }

  /* ---------- Barras horizontales ---------- */
  function barrasEventos(eventos) {
    var vivos = eventos.filter(function (e) { return !e.letal; }).slice(0, 8);
    var max = vivos.length ? +vivos[0].casos : 1;
    return listaBarras(vivos.map(function (e) {
      return { etiqueta: e.evento, valor: +e.casos, color: GRUPOS[e.grupo] ? GRUPOS[e.grupo].color : GRUPOS.ETV.color };
    }), max);
  }

  function barrasMunicipios(muni) {
    var max = muni.length ? +muni[0].total : 1;
    return listaBarras(muni.map(function (m) {
      return { etiqueta: m.municipio, valor: +m.total, color: 'var(--man-acento-tecnico,#003087)' };
    }), max);
  }

  function listaBarras(filas, max) {
    var lista = C.el('ul', 'man-salud__barras');
    filas.forEach(function (f) {
      var li = C.el('li', 'man-salud__barra');
      li.appendChild(C.el('span', 'man-salud__barra-rotulo', C.esc(f.etiqueta)));
      var pista = C.el('span', 'man-salud__barra-pista');
      var relleno = C.el('i', null);
      relleno.style.width = Math.max(1, (f.valor / (max || 1)) * 100) + '%';
      relleno.style.background = f.color;
      pista.appendChild(relleno);
      li.appendChild(pista);
      li.appendChild(C.el('span', 'man-salud__barra-valor', C.num(f.valor, 0)));
      lista.appendChild(li);
    });
    return lista;
  }

  function texto(svg, x, y, t, anchor, size) {
    var NS = 'http://www.w3.org/2000/svg';
    var e = document.createElementNS(NS, 'text');
    e.setAttribute('x', x); e.setAttribute('y', y);
    e.setAttribute('text-anchor', anchor || 'start');
    e.setAttribute('font-size', size || 10);
    e.setAttribute('fill', 'var(--man-mute,#6b7280)');
    e.textContent = t;
    svg.appendChild(e);
    return e;
  }

  function limpiar(cont) { C.limpiar(cont, 'man-salud__cuerpo'); }
})();
