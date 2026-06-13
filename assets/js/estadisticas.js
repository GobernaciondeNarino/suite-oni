/* [man_estadisticas] — gráficos estadísticos prediseñados con D3plus
   (tooltip y leyenda en español, paleta institucional). Tipos:
     · oni          → LinePlot del ONI observado + proyectado
     · probabilidad → BarChart apilado de fase por trimestre
     · riesgo       → BarChart del riesgo medio por subregión
   Si D3plus no estuviera disponible, cae a un SVG simple (MANcore). */
(function () {
  'use strict';
  var C = window.MANcore;
  var COL = { el_nino: '#c62828', neutral: '#2e7d32', la_nina: '#1565c0', obs: '#1a1f2c', proy: '#003087' };

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-estadisticas]'), cargar);
  });

  function cargar(cont) {
    var tipo = cont.getAttribute('data-tipo') || 'oni';
    var hasta = cont.getAttribute('data-hasta') || '2027-02';
    var mes = cont.getAttribute('data-mes') || '';
    var lienzo = cont.querySelector('.man-estadisticas__lienzo');
    var has = (typeof window.d3plus !== 'undefined');
    if (tipo === 'probabilidad') { return prob(cont, lienzo, hasta, has); }
    if (tipo === 'riesgo') { return riesgo(cont, lienzo, mes, has); }
    return oni(cont, lienzo, hasta, has);
  }

  /* ---------- ONI observado + proyectado ---------- */
  function oni(cont, lienzo, hasta, has) {
    C.rest('/prediccion', { hasta: hasta })
      .then(function (d) {
        listo(cont);
        titulo(cont, 'Evolución del índice ONI (observado y proyectado)');
        var rows = [];
        (d.serie || []).forEach(function (p) {
          rows.push({ Mes: p.mes, ONI: +p.oni, Serie: p.tipo === 'proyectado' ? 'Proyectado' : 'Observado' });
        });
        if (has && window.d3plus && d3plus.LinePlot) {
          try {
            new d3plus.LinePlot()
              .select(lienzo).data(rows).groupBy('Serie').x('Mes').y('ONI')
              .baseline(0)
              .xConfig({ title: 'Mes' }).yConfig({ title: 'ONI (°C)' })
              .color(function (x) { return x.Serie === 'Proyectado' ? COL.proy : COL.obs; })
              .legend(true)
              .render();
            return;
          } catch (e) { /* cae a SVG */ }
        }
        var fechas = (d.serie || []).map(function (p) { return p.mes; });
        var vals = (d.serie || []).map(function (p) { return +p.oni; });
        lienzo.appendChild(C.lineaSimple(fechas, vals, { area: true, color: COL.proy }));
      })
      .catch(function () { C.error(cont, 'No se pudieron cargar las estadísticas del ONI.', function () { oni(cont, lienzo, hasta, has); }); });
  }

  /* ---------- Probabilidad de fase por trimestre ---------- */
  function prob(cont, lienzo, hasta, has) {
    C.rest('/prediccion', { hasta: hasta })
      .then(function (d) {
        listo(cont);
        titulo(cont, 'Probabilidad de fase ENSO por trimestre');
        var trims = d.prob_trimestres || [];
        var rows = [];
        trims.forEach(function (t) {
          rows.push({ Trimestre: t.etiqueta, Fase: 'El Niño', Probabilidad: +t.el_nino });
          rows.push({ Trimestre: t.etiqueta, Fase: 'Neutral', Probabilidad: +t.neutral });
          rows.push({ Trimestre: t.etiqueta, Fase: 'La Niña', Probabilidad: +t.la_nina });
        });
        if (has && window.d3plus && d3plus.BarChart && rows.length) {
          try {
            new d3plus.BarChart()
              .select(lienzo).data(rows).groupBy('Fase').x('Trimestre').y('Probabilidad').stacked(true)
              .xConfig({ title: '' }).yConfig({ title: 'Probabilidad (%)' })
              .color(function (x) {
                return x.Fase === 'El Niño' ? COL.el_nino : (x.Fase === 'La Niña' ? COL.la_nina : COL.neutral);
              })
              .legend(true)
              .render();
            return;
          } catch (e) { /* cae a barras simples */ }
        }
        fallbackProb(lienzo, trims);
      })
      .catch(function () { C.error(cont, 'No se pudieron cargar las probabilidades de fase.', function () { prob(cont, lienzo, hasta, has); }); });
  }

  /* ---------- Riesgo medio por subregión ---------- */
  function riesgo(cont, lienzo, mes, has) {
    C.rest('/departamento', { mes: mes })
      .then(function (lista) {
        listo(cont);
        titulo(cont, 'Riesgo ambiental medio por subregión de Nariño');
        var acc = {};
        (lista || []).forEach(function (m) {
          var s = m.subregion || 'Sin clasificar';
          if (!acc[s]) { acc[s] = { suma: 0, n: 0 }; }
          acc[s].suma += +m.riesgo || 0; acc[s].n++;
        });
        var rows = Object.keys(acc).map(function (s) {
          return { Subregion: s, Riesgo: Math.round((acc[s].suma / acc[s].n) * 100) / 100 };
        }).sort(function (a, b) { return b.Riesgo - a.Riesgo; });

        if (has && window.d3plus && d3plus.BarChart && rows.length) {
          try {
            new d3plus.BarChart()
              .select(lienzo).data(rows).groupBy('Subregion').x('Subregion').y('Riesgo')
              .discrete('x')
              .xConfig({ title: 'Subregión' }).yConfig({ title: 'Índice de riesgo (0–1)' })
              .color(function (x) { return escalaRiesgo(x.Riesgo); })
              .legend(false)
              .render();
            return;
          } catch (e) { /* cae a barras simples */ }
        }
        fallbackRiesgo(lienzo, rows);
      })
      .catch(function () { C.error(cont, 'No se pudo cargar el riesgo por subregión.', function () { riesgo(cont, lienzo, mes, has); }); });
  }

  /* ---------- Fallbacks SVG (sin D3plus) ---------- */
  function fallbackProb(lienzo, trims) {
    var NS = 'http://www.w3.org/2000/svg';
    var W = 560, H = 260, m = { t: 12, r: 12, b: 50, l: 32 }, iw = W - m.l - m.r, ih = H - m.t - m.b;
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H); svg.setAttribute('class', 'man-grafico');
    var bw = iw / Math.max(1, trims.length) * 0.7;
    trims.forEach(function (t, i) {
      var x0 = m.l + (iw / trims.length) * (i + 0.15);
      var acumulado = 0;
      [['el_nino', COL.el_nino], ['neutral', COL.neutral], ['la_nina', COL.la_nina]].forEach(function (f) {
        var v = +t[f[0]] || 0, h = ih * v / 100;
        var r = document.createElementNS(NS, 'rect');
        r.setAttribute('x', x0); r.setAttribute('y', m.t + ih - acumulado - h);
        r.setAttribute('width', bw); r.setAttribute('height', h); r.setAttribute('fill', f[1]);
        svg.appendChild(r); acumulado += h;
      });
      txt(svg, x0 + bw / 2, H - 30, t.etiqueta, 8);
    });
    lienzo.appendChild(svg);
  }
  function fallbackRiesgo(lienzo, rows) {
    var NS = 'http://www.w3.org/2000/svg';
    var W = 560, H = 260, m = { t: 12, r: 12, b: 60, l: 36 }, iw = W - m.l - m.r, ih = H - m.t - m.b;
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H); svg.setAttribute('class', 'man-grafico');
    var bw = iw / Math.max(1, rows.length) * 0.7;
    rows.forEach(function (d, i) {
      var x0 = m.l + (iw / rows.length) * (i + 0.15), h = ih * Math.min(1, d.Riesgo);
      var r = document.createElementNS(NS, 'rect');
      r.setAttribute('x', x0); r.setAttribute('y', m.t + ih - h); r.setAttribute('width', bw);
      r.setAttribute('height', h); r.setAttribute('fill', escalaRiesgo(d.Riesgo));
      svg.appendChild(r);
      txt(svg, x0 + bw / 2, m.t + ih - h - 4, C.num(d.Riesgo, 2), 9);
      txt(svg, x0 + bw / 2, H - 40, d.Subregion, 8);
    });
    lienzo.appendChild(svg);
  }
  function txt(svg, x, y, s, size) {
    var NS = 'http://www.w3.org/2000/svg';
    var t = document.createElementNS(NS, 'text');
    t.setAttribute('x', x); t.setAttribute('y', y); t.setAttribute('font-size', size);
    t.setAttribute('text-anchor', 'middle'); t.setAttribute('fill', 'var(--man-mute,#6b7280)');
    t.textContent = s; svg.appendChild(t);
  }

  /* ---------- Utilidades ---------- */
  function escalaRiesgo(v) {
    return v >= 0.66 ? '#C0392B' : (v >= 0.5 ? '#E8731A' : (v >= 0.33 ? '#E8A020' : '#2E7D32'));
  }
  function titulo(cont, t) {
    if (cont.querySelector('.man-estadisticas__titulo')) { return; }
    var p = C.el('p', 'man-titulo man-estadisticas__titulo', C.esc(t));
    cont.insertBefore(p, cont.firstChild);
  }
  function listo(cont) {
    C.quitarSkeleton(cont);
    var e = cont.querySelector('.man-error'); if (e) { e.parentNode.removeChild(e); }
  }
})();
