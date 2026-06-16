/* [man_prediccion] — predicción de la trayectoria del ONI hasta el mes objetivo
   (por defecto febrero de 2027).

   Dibuja, con D3 puro: ONI observado (línea sólida) + ensamble oficial proyectado
   (línea discontinua) + modelo propio del plugin (línea de puntos) + banda de
   incertidumbre, con umbrales de fase ±0,5 °C, separador observado/proyección,
   marcador del mes objetivo y reveal animado de la proyección (respeta
   prefers-reduced-motion). Añade barras de probabilidad por trimestre y la
   narrativa predictiva automática. Componente independiente y maquetable. */
(function () {
  'use strict';
  var C = window.MANcore;
  var NS = 'http://www.w3.org/2000/svg';
  var uid = 0;
  var MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-prediccion]'), cargar);
  });

  function cargar(cont) {
    var pa = (cont.getAttribute('data-partes') || '').trim();
    var opts = {
      hasta: cont.getAttribute('data-hasta') || '2027-02',
      modelo: cont.getAttribute('data-modelo') !== 'no',
      probabilidad: cont.getAttribute('data-probabilidad') !== 'no',
      // null = todas las secciones; si no, solo las indicadas.
      partes: pa ? pa.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : null
    };
    C.rest('/prediccion', { hasta: opts.hasta })
      .then(function (d) { pintar(cont, opts, d); })
      .catch(function () { C.error(cont, 'No se pudo calcular la predicción del fenómeno.', function () { cargar(cont); }); });
  }

  function pintar(cont, opts, d) {
    C.quitarSkeleton(cont);
    limpiar(cont);
    var serie = (d && d.serie) || [];
    var cuerpo = C.el('div', 'man-prediccion__cuerpo');
    // ¿Mostrar esta sección? (permite dividir gráfico y textos en shortcodes distintos.)
    var ver = function (sec) { return !opts.partes || opts.partes.indexOf(sec) >= 0; };

    if (ver('titulo')) {
      cuerpo.appendChild(C.el('p', 'man-titulo', 'Predicción del fenómeno ENSO hasta ' + mesLargo(d.objetivo_mes)));
    }

    if (ver('chips')) {
      cuerpo.appendChild(chips(d));
    }

    if (ver('grafico')) {
      if (serie.length) {
        cuerpo.appendChild(grafica(d, opts));
      } else {
        cuerpo.appendChild(C.el('p', 'man-analisis', 'Aún no hay serie ONI suficiente para proyectar.'));
      }
    }

    if (ver('probabilidad') && opts.probabilidad && d.prob_trimestres && d.prob_trimestres.length) {
      cuerpo.appendChild(barrasProb(d.prob_trimestres));
    }

    if (ver('texto') && d.texto_analisis) {
      cuerpo.appendChild(C.el('p', 'man-analisis', C.esc(d.texto_analisis)));
    }

    if (ver('metodologia')) {
      if (d.ficha_tecnica) {
        cuerpo.appendChild(fichaTecnica(d.ficha_tecnica));
      } else if (d.regresion) {
        cuerpo.appendChild(C.el('p', 'man-mute-line', 'Método: tendencia amortiguada + reversión a la media. R² ' + C.num(d.regresion.r2, 2) + '.'));
      }
    }

    cont.insertBefore(cuerpo, cont.querySelector('.man-fuentes'));
  }

  /* ---------- Cabecera de cifras clave ---------- */
  function chips(d) {
    var row = C.el('div', 'man-prediccion__chips');
    if (d.actual) { row.appendChild(chip('Estado actual (' + mesCorto(d.actual.mes) + ')', d.actual.oni, d.actual.fase)); }
    if (d.pico) { row.appendChild(chip('Pico previsto (' + mesCorto(d.pico.mes) + ')', d.pico.oni, d.pico.fase)); }
    if (d.objetivo) { row.appendChild(chip(mesLargo(d.objetivo.mes), d.objetivo.oni, d.objetivo.fase)); }
    return row;
  }

  function chip(rotulo, oni, fase) {
    var box = C.el('div', 'man-prediccion__chip');
    box.appendChild(C.el('span', 'man-prediccion__chip-rotulo', C.esc(rotulo)));
    box.appendChild(C.el('strong', null, (oni >= 0 ? '+' : '') + C.num(oni, 1) + ' °C'));
    var ch = C.el('span', 'man-chip', C.esc(fase));
    ch.style.background = faseColor(oni);
    box.appendChild(ch);
    return box;
  }

  /* ---------- Gráfica D3 (SVG) ---------- */
  function grafica(d, opts) {
    var serie = d.serie;
    var n = serie.length;
    var W = 720, H = 320, m = { t: 18, r: 64, b: 36, l: 40 };
    var iw = W - m.l - m.r, ih = H - m.t - m.b;
    var id = 'man-pred-rev-' + (++uid);

    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
    svg.setAttribute('class', 'man-grafico man-prediccion__grafico');
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', d.texto_analisis ? d.texto_analisis : 'Predicción del índice ONI');

    // Índice del primer punto proyectado.
    var iProj = -1;
    for (var k = 0; k < n; k++) { if (serie[k].tipo === 'proyectado') { iProj = k; break; } }
    if (iProj < 0) { iProj = n; }
    var iLastObs = Math.max(0, iProj - 1);

    // Escalas.
    var vals = [];
    serie.forEach(function (p) {
      vals.push(+p.oni);
      if (p.banda_min != null) { vals.push(+p.banda_min); }
      if (p.banda_max != null) { vals.push(+p.banda_max); }
      if (p.modelo_oni != null) { vals.push(+p.modelo_oni); }
    });
    var minv = Math.min.apply(null, vals.concat([-0.6]));
    var maxv = Math.max.apply(null, vals.concat([0.6]));
    var pad = (maxv - minv) * 0.08 || 0.5;
    var x = d3.scaleLinear().domain([0, Math.max(1, n - 1)]).range([m.l, m.l + iw]);
    var y = d3.scaleLinear().domain([minv - pad, maxv + pad]).range([m.t + ih, m.t]).nice();

    // Bandas de fase (muy sutiles) + líneas de umbral ±0,5 y base 0.
    zonaFase(svg, x, y, m, iw, y.domain()[1], 0.5, '#c62828', 0.05);   // El Niño: máx … +0,5
    zonaFase(svg, x, y, m, iw, -0.5, y.domain()[0], '#1565c0', 0.05);  // La Niña: −0,5 … mín
    umbral(svg, x, y, m, iw, 0.5, '#c62828', 'El Niño +0,5');
    umbral(svg, x, y, m, iw, -0.5, '#1565c0', 'La Niña −0,5');
    umbral(svg, x, y, m, iw, 0, 'var(--man-mute,#9aa0aa)', null);

    // Ejes (etiquetas Y y meses).
    ejes(svg, x, y, m, ih, serie, n);

    // Separador observado / proyección.
    if (iProj < n) {
      var sx = x(iLastObs);
      var sep = linea(sx, m.t, sx, m.t + ih, 'var(--man-mute,#c4c9d2)', 1);
      sep.setAttribute('stroke-dasharray', '3 3');
      svg.appendChild(sep);
      etiqueta(svg, m.l + 4, m.t + 10, 'observado', 'start', 'var(--man-mute,#9aa0aa)', 9);
      etiqueta(svg, sx + 4, m.t + 10, 'proyección', 'start', 'var(--man-mute,#9aa0aa)', 9);
    }

    // Grupo proyección bajo clip animable (reveal de izquierda a derecha).
    var defs = document.createElementNS(NS, 'defs');
    var clip = document.createElementNS(NS, 'clipPath');
    clip.setAttribute('id', id);
    var rect = document.createElementNS(NS, 'rect');
    var revX = x(iLastObs);
    var revFull = (m.l + iw) - revX + 2;
    rect.setAttribute('x', revX); rect.setAttribute('y', m.t - 4);
    rect.setAttribute('height', ih + 8);
    rect.setAttribute('width', reduce ? revFull : 0);
    clip.appendChild(rect); defs.appendChild(clip); svg.appendChild(defs);

    var gProj = document.createElementNS(NS, 'g');
    gProj.setAttribute('clip-path', 'url(#' + id + ')');
    svg.appendChild(gProj);

    // Banda de incertidumbre (área).
    var banda = [{ i: iLastObs, lo: +serie[iLastObs].oni, hi: +serie[iLastObs].oni }];
    for (var b = iProj; b < n; b++) {
      if (serie[b].banda_min != null && serie[b].banda_max != null) {
        banda.push({ i: b, lo: +serie[b].banda_min, hi: +serie[b].banda_max });
      }
    }
    if (banda.length > 1) {
      var area = d3.area().x(function (p) { return x(p.i); })
        .y0(function (p) { return y(p.lo); }).y1(function (p) { return y(p.hi); });
      var pa = document.createElementNS(NS, 'path');
      pa.setAttribute('d', area(banda));
      pa.setAttribute('fill', 'var(--man-acento,#10A13B)');
      pa.setAttribute('opacity', '0.14');
      gProj.appendChild(pa);
    }

    // Línea central observada (sólida, color neutro) + proyectada (discontinua).
    // Los puntos sí van coloreados por fase (abajo), reflejando la transición.
    trazo(svg, segmento(serie, 0, iLastObs, 'oni'), x, y, 'var(--man-texto,#1a1f2c)', 2.4, null, false);
    var lineaProj = trazo(gProj, segmento(serie, iLastObs, n - 1, 'oni'), x, y, '#003087', 2.4, '6 4', false);

    // Línea del modelo propio del plugin (puntos), si procede.
    if (opts.modelo) {
      var mod = [{ i: iLastObs, v: +serie[iLastObs].oni }];
      for (var mm = iProj; mm < n; mm++) { if (serie[mm].modelo_oni != null) { mod.push({ i: mm, v: +serie[mm].modelo_oni }); } }
      if (mod.length > 1) { trazo(gProj, mod, x, y, 'var(--man-acento,#10A13B)', 1.8, '1 4', true); }
    }

    // Puntos observados.
    for (var o = 0; o <= iLastObs; o++) { punto(svg, x(o), y(+serie[o].oni), 2.6, faseColor(serie[o].oni)); }

    // Marcador del mes objetivo.
    var io = n - 1;
    var ox = x(io), oy = y(+serie[io].oni);
    punto(gProj, ox, oy, 4, '#003087');
    var lab = (serie[io].oni >= 0 ? '+' : '') + C.num(serie[io].oni, 1) + ' °C';
    etiqueta(gProj, Math.min(ox, m.l + iw - 2), oy - 10, mesCorto(serie[io].mes) + ': ' + lab, 'end', 'var(--man-texto,#1a1f2c)', 11, true);

    // Leyenda.
    leyenda(svg, m, ih, opts.modelo);

    // Reveal animado.
    if (!reduce) {
      tween(950, function (kk) { rect.setAttribute('width', kk * revFull); });
    }

    // Interactividad: tooltip al pasar el cursor (mes, ONI, fase, banda, modelo).
    interactividad(svg, serie, x, y, m, iw, ih);

    void lineaProj;
    return svg;
  }

  /* ---------- Interactividad (hover/touch) de la gráfica D3 ---------- */
  function interactividad(svg, serie, x, y, m, iw, ih) {
    var n = serie.length;
    if (!n) { return; }

    var foco = document.createElementNS(NS, 'g');
    foco.setAttribute('class', 'man-pred-foco');
    foco.style.opacity = '0';
    var vline = linea(0, m.t, 0, m.t + ih, 'var(--man-mute,#9aa0aa)', 1);
    vline.setAttribute('stroke-dasharray', '2 3');
    foco.appendChild(vline);
    var dot = punto(foco, 0, 0, 4, '#fff');
    dot.setAttribute('stroke', '#003087'); dot.setAttribute('stroke-width', 2);
    var tip = document.createElementNS(NS, 'g');
    var bg = document.createElementNS(NS, 'rect');
    bg.setAttribute('rx', 5); bg.setAttribute('fill', 'rgba(26,31,44,.92)');
    tip.appendChild(bg);
    foco.appendChild(tip);
    svg.appendChild(foco);

    var overlay = document.createElementNS(NS, 'rect');
    overlay.setAttribute('x', m.l); overlay.setAttribute('y', m.t);
    overlay.setAttribute('width', iw); overlay.setAttribute('height', ih);
    overlay.setAttribute('fill', 'transparent');
    overlay.style.cursor = 'crosshair';
    svg.appendChild(overlay);

    var pt = svg.createSVGPoint();
    function aCoord(evt) {
      var src = (evt.touches && evt.touches[0]) ? evt.touches[0] : evt;
      pt.x = src.clientX; pt.y = src.clientY;
      var ctm = svg.getScreenCTM();
      return ctm ? pt.matrixTransform(ctm.inverse()) : null;
    }
    function mover(evt) {
      var c = aCoord(evt); if (!c) { return; }
      var i = Math.max(0, Math.min(n - 1, Math.round(x.invert(c.x))));
      var s = serie[i];
      var px = x(i), py = y(+s.oni);
      vline.setAttribute('x1', px); vline.setAttribute('x2', px);
      dot.setAttribute('cx', px); dot.setAttribute('cy', py);
      dot.setAttribute('stroke', faseColor(s.oni));

      var lineas = [
        mesCorto(s.mes) + (s.tipo === 'proyectado' ? ' · proyección' : ' · observado'),
        'ONI ' + (s.oni >= 0 ? '+' : '') + C.num(s.oni, 2) + ' °C · ' + (s.fase || '')
      ];
      if (s.banda_min != null && s.banda_max != null) {
        lineas.push('Banda ' + C.num(s.banda_min, 2) + ' a ' + C.num(s.banda_max, 2) + ' °C');
      }
      if (s.modelo_oni != null) {
        lineas.push('Modelo plugin ' + (s.modelo_oni >= 0 ? '+' : '') + C.num(s.modelo_oni, 2) + ' °C');
      }

      while (tip.firstChild) { tip.removeChild(tip.firstChild); }
      tip.appendChild(bg);
      var pad = 7, lh = 14, maxw = 0;
      lineas.forEach(function (t, k) {
        etiqueta(tip, pad, pad + lh * (k + 0.85), t, 'start', '#fff', k === 0 ? 10.5 : 10, k === 0);
        maxw = Math.max(maxw, t.length * (k === 0 ? 6.1 : 5.8));
      });
      var bw = maxw + pad * 2, bh = lh * lineas.length + pad * 1.2;
      bg.setAttribute('width', bw); bg.setAttribute('height', bh);
      var tx = px + 12; if (tx + bw > m.l + iw) { tx = px - bw - 12; }
      var ty = Math.max(m.t, py - bh - 8);
      tip.setAttribute('transform', 'translate(' + tx + ',' + ty + ')');
      foco.style.opacity = '1';
    }
    function ocultar() { foco.style.opacity = '0'; }
    overlay.addEventListener('mousemove', mover);
    overlay.addEventListener('mouseenter', mover);
    overlay.addEventListener('mouseleave', ocultar);
    overlay.addEventListener('touchstart', function (e) { mover(e); }, { passive: true });
    overlay.addEventListener('touchmove', function (e) { mover(e); e.preventDefault(); }, { passive: false });
    overlay.addEventListener('touchend', ocultar);
  }

  function segmento(serie, a, b, campo) {
    var arr = [];
    for (var i = a; i <= b; i++) { arr.push({ i: i, v: +serie[i][campo] }); }
    return arr;
  }

  function trazo(parent, datos, x, y, color, w, dash, redondo) {
    var line = d3.line().x(function (p) { return x(p.i); }).y(function (p) { return y(p.v); });
    var path = document.createElementNS(NS, 'path');
    path.setAttribute('d', line(datos));
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke', color);
    path.setAttribute('stroke-width', w);
    path.setAttribute('stroke-linejoin', 'round');
    path.setAttribute('stroke-linecap', 'round');
    if (dash) { path.setAttribute('stroke-dasharray', dash); }
    if (redondo) { path.setAttribute('opacity', '0.9'); }
    parent.appendChild(path);
    return path;
  }

  function ejes(svg, x, y, m, ih, serie, n) {
    var dom = y.domain();
    var ticks = y.ticks(5);
    ticks.forEach(function (t) {
      var gy = y(t);
      var gl = linea(m.l, gy, m.l + (x.range()[1] - m.l), gy, 'var(--man-borde-color,#eef0f3)', 1);
      svg.appendChild(gl);
      etiqueta(svg, m.l - 6, gy + 3, (t > 0 ? '+' : '') + (Math.round(t * 10) / 10), 'end', 'var(--man-mute,#9aa0aa)', 10);
    });
    var paso = Math.max(1, Math.ceil(n / 8));
    for (var j = 0; j < n; j += paso) {
      etiqueta(svg, x(j), (m.t + ih) + 16, mesCorto(serie[j].mes), 'middle', 'var(--man-mute,#9aa0aa)', 9);
    }
    void dom;
  }

  function zonaFase(svg, x, y, m, iw, vTop, vBot, color, op) {
    if (vTop <= vBot) { return; }
    var yt = y(vTop), yb = y(vBot);
    var r = document.createElementNS(NS, 'rect');
    r.setAttribute('x', m.l); r.setAttribute('y', Math.min(yt, yb));
    r.setAttribute('width', iw); r.setAttribute('height', Math.abs(yb - yt));
    r.setAttribute('fill', color); r.setAttribute('opacity', op);
    svg.appendChild(r);
  }

  function umbral(svg, x, y, m, iw, v, color, rotulo) {
    var dom = y.domain();
    if (v < dom[0] || v > dom[1]) { return; }
    var gy = y(v);
    var ln = linea(m.l, gy, m.l + iw, gy, color, 1);
    ln.setAttribute('stroke-dasharray', '2 4'); ln.setAttribute('opacity', '0.7');
    svg.appendChild(ln);
    if (rotulo) { etiqueta(svg, m.l + iw + 4, gy + 3, rotulo, 'start', color, 9); }
  }

  function leyenda(svg, m, ih, conModelo) {
    var y0 = m.t + ih + 30, x0 = m.l;
    var items = [['Observado', 'var(--man-texto,#1a1f2c)', 'solid'], ['Ensamble NOAA/IRI', '#003087', 'dash']];
    if (conModelo) { items.push(['Modelo del plugin', 'var(--man-acento,#10A13B)', 'dot']); }
    var cx = x0;
    items.forEach(function (it) {
      var ln = linea(cx, y0, cx + 22, y0, it[1], 2.4);
      if (it[2] === 'dash') { ln.setAttribute('stroke-dasharray', '6 4'); }
      if (it[2] === 'dot') { ln.setAttribute('stroke-dasharray', '1 4'); }
      svg.appendChild(ln);
      var tx = etiqueta(svg, cx + 28, y0 + 3, it[0], 'start', 'var(--man-mute,#6b7280)', 10);
      cx += 30 + (it[0].length * 5.6) + 16;
      void tx;
    });
  }

  /* ---------- Barras de probabilidad por trimestre ---------- */
  function barrasProb(trims) {
    var wrap = C.el('div', 'man-prediccion__prob');
    wrap.appendChild(C.el('p', 'man-prediccion__prob-titulo', 'Probabilidad de fase por trimestre'));
    trims.forEach(function (t) {
      var fila = C.el('div', 'man-prediccion__prob-fila');
      fila.appendChild(C.el('span', 'man-prediccion__prob-rotulo', C.esc(t.etiqueta)));
      var barra = C.el('div', 'man-prediccion__barra');
      barra.appendChild(seg(t.el_nino, '#c62828', 'El Niño ' + C.num(t.el_nino, 0) + '%'));
      barra.appendChild(seg(t.neutral, '#2e7d32', 'Neutral ' + C.num(t.neutral, 0) + '%'));
      barra.appendChild(seg(t.la_nina, '#1565c0', 'La Niña ' + C.num(t.la_nina, 0) + '%'));
      fila.appendChild(barra);
      var dom = dominante(t);
      fila.appendChild(C.el('span', 'man-prediccion__prob-dom', C.esc(dom)));
      wrap.appendChild(fila);
    });
    return wrap;
  }

  function seg(pct, color, titulo) {
    var s = C.el('span', 'man-prediccion__barra-seg');
    s.style.width = Math.max(0, +pct || 0) + '%';
    s.style.background = color;
    s.setAttribute('title', titulo);
    return s;
  }

  function dominante(t) {
    var arr = [['El Niño', +t.el_nino || 0], ['Neutral', +t.neutral || 0], ['La Niña', +t.la_nina || 0]];
    arr.sort(function (a, b) { return b[1] - a[1]; });
    return arr[0][0] + ' ' + C.num(arr[0][1], 0) + '%';
  }

  /* ---------- Ficha técnica (metodología profesional) ---------- */
  function fichaTecnica(f) {
    var det = C.el('details', 'man-prediccion__ficha');
    det.appendChild(C.el('summary', null, 'Ficha técnica / metodología'));
    var b = C.el('div', 'man-prediccion__ficha-cuerpo');
    if (f.modelo) { b.appendChild(C.el('p', null, '<strong>Modelo:</strong> ' + C.esc(f.modelo))); }
    if (f.ajuste) {
      b.appendChild(C.el('p', null, '<strong>Ajuste:</strong> ' + C.num(f.ajuste.meses, 0) + ' meses · pendiente ' +
        C.num(f.ajuste.pendiente_oni_mes, 2) + ' °C/mes · R² ' + C.num(f.ajuste.r2, 2)));
    }
    if (f.confianza) { b.appendChild(C.el('p', null, '<strong>Confianza:</strong> ' + C.esc(f.confianza))); }
    if (f.clasificacion_fase) { b.appendChild(C.el('p', null, '<strong>Clasificación de fase:</strong> ' + C.esc(f.clasificacion_fase))); }
    listaFicha(b, 'Supuestos', f.supuestos);
    listaFicha(b, 'Limitaciones', f.limitaciones);
    listaFicha(b, 'Referencias', f.referencias);
    if (f.naturaleza) { b.appendChild(C.el('p', 'man-prediccion__ficha-nat', C.esc(f.naturaleza))); }
    det.appendChild(b);
    return det;
  }
  function listaFicha(b, titulo, arr) {
    if (!arr || !arr.length) { return; }
    b.appendChild(C.el('p', 'man-prediccion__ficha-h', C.esc(titulo) + ':'));
    var ul = document.createElement('ul');
    arr.forEach(function (x) { ul.appendChild(C.el('li', null, C.esc(x))); });
    b.appendChild(ul);
  }

  /* ---------- Utilidades SVG ---------- */
  function linea(x1, y1, x2, y2, color, w) {
    var l = document.createElementNS(NS, 'line');
    l.setAttribute('x1', x1); l.setAttribute('y1', y1); l.setAttribute('x2', x2); l.setAttribute('y2', y2);
    l.setAttribute('stroke', color); l.setAttribute('stroke-width', w);
    return l;
  }

  function punto(parent, cx, cy, r, color) {
    var c = document.createElementNS(NS, 'circle');
    c.setAttribute('cx', cx); c.setAttribute('cy', cy); c.setAttribute('r', r); c.setAttribute('fill', color);
    parent.appendChild(c);
    return c;
  }

  function etiqueta(parent, x, y, txt, anchor, fill, size, bold) {
    var t = document.createElementNS(NS, 'text');
    t.setAttribute('x', x); t.setAttribute('y', y);
    t.setAttribute('font-size', size); t.setAttribute('fill', fill); t.setAttribute('text-anchor', anchor);
    if (bold) { t.setAttribute('font-weight', '600'); }
    t.textContent = txt;
    parent.appendChild(t);
    return t;
  }

  function tween(ms, cb, done) {
    var t0 = null;
    function step(t) {
      if (t0 === null) { t0 = t; }
      var k = Math.min(1, (t - t0) / ms);
      cb(k < 0 ? 0 : (1 - Math.pow(1 - k, 3))); // ease-out cúbico
      if (k < 1) { requestAnimationFrame(step); } else if (done) { done(); }
    }
    requestAnimationFrame(step);
  }

  /* ---------- Formato ---------- */
  function mesCorto(mes) {
    var p = String(mes || '').split('-');
    if (p.length < 2) { return String(mes || ''); }
    var i = (+p[1] - 1) % 12;
    return MESES[i] + ' ' + p[0].slice(2);
  }
  function mesLargo(mes) {
    var nombres = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    var p = String(mes || '').split('-');
    if (p.length < 2) { return String(mes || ''); }
    return nombres[(+p[1] - 1) % 12] + ' de ' + p[0];
  }
  function faseColor(oni) { return oni >= 0.5 ? '#c62828' : (oni <= -0.5 ? '#1565c0' : '#2e7d32'); }

  function limpiar(cont) {
    var x = cont.querySelector('.man-prediccion__cuerpo'); if (x) { x.parentNode.removeChild(x); }
    var e = cont.querySelector('.man-error'); if (e) { e.parentNode.removeChild(e); }
  }
})();
