/* [man_grafico] — hidratador (capa 2 del motor de gráficos, ver /skill).
   Lee los data-* del <figure>, pide /render a la REST, llama a MANRenderer y
   cablea la barra de herramientas (Detalle, Compartir, Datos, Imagen PNG,
   Descarga JSON, Cambiar tipo en vivo) y los modales. Toda salida se escapa. */
(function () {
  'use strict';
  var C = window.MANcore;

  var TIPO_LABEL = {
    bar: 'Barras', stacked_bar: 'Barras apiladas', line: 'Líneas', area: 'Área',
    stacked_area: 'Área apilada', pie: 'Pastel', donut: 'Dona', treemap: 'Treemap', box_whisker: 'Caja y bigotes'
  };
  var ICON = { explicacion: 'editor-help', detalle: 'info-outline', compartir: 'share', datos: 'editor-table', imagen: 'format-image', descarga: 'download', cambiar: 'image-rotate' };
  var LABEL = { explicacion: 'Cómo funciona', detalle: 'Detalle', compartir: 'Compartir', datos: 'Datos', imagen: 'Imagen', descarga: 'Descarga', cambiar: 'Cambiar' };
  var DEFAULT_ACTIONS = ['explicacion', 'detalle', 'compartir', 'datos', 'imagen', 'descarga', 'cambiar'];

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-grafico]'), init);
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-analisis]'), initAnalisisBloque);
  });

  /* ---------- [man_analisis] — bloque de análisis independiente ----------
     Renderiza SOLO el texto (descriptivo/cuantitativo) de una vista, sin la
     gráfica. Si pertenece a un grupo, se sincroniza con el gráfico del grupo;
     si no, pide /render por su cuenta. */
  function initAnalisisBloque(box) {
    var st = {
      view: box.getAttribute('data-view') || 'oni_serie',
      type: box.getAttribute('data-type') || '',
      hasta: box.getAttribute('data-hasta') || '2027-02',
      mes: box.getAttribute('data-mes') || '',
      modo: box.getAttribute('data-modo') || 'ambos',
      titulo: box.getAttribute('data-titulo') || '',
      grupo: box.getAttribute('data-grupo') || ''
    };

    var pintado = false;
    function render(p) {
      if (!p) { return; }
      pintado = true;
      pintarAnalisisBloque(box, p, st.modo, st.titulo);
    }
    function fetchPropio() {
      C.rest('/render', { view: st.view, type: st.type, hasta: st.hasta, mes: st.mes })
        .then(render)
        .catch(function () { if (!pintado) { C.error(box, 'No se pudo cargar el análisis.', function () { pintado = false; initAnalisisBloque(box); }); } });
    }

    // Modo composable: escucha el payload del grupo (lo emite el [man_grafico]).
    // onPayload entrega de inmediato el último payload si ya existe.
    if (st.grupo && window.MANGrupo) {
      var est = window.MANGrupo.init(st.grupo, { view: st.view, type: st.type, mes: st.mes, hasta: st.hasta });
      st.view = est.view || st.view; st.mes = est.mes || st.mes; st.hasta = est.hasta || st.hasta;
      window.MANGrupo.onPayload(st.grupo, function (payload) { render(payload); });
      // Si no hay ningún gráfico en el grupo que publique, pedimos por cuenta propia.
      setTimeout(function () { if (!pintado) { fetchPropio(); } }, 1200);
      return;
    }

    fetchPropio();
  }

  function pintarAnalisisBloque(box, p, modo, titulo) {
    var v = (p && p.view) || {};
    var a = v.analisis || null;
    var sk = box.querySelector('.man-skeleton'); if (sk) { sk.parentNode.removeChild(sk); }
    box.innerHTML = '';
    if (titulo) { box.appendChild(C.el('p', 'man-g__analisis-titulo', C.esc(titulo))); }
    else if (v.name) { box.appendChild(C.el('p', 'man-g__analisis-titulo', C.esc(v.name))); }

    var algo = false;
    if (modo === 'descripcion' && (v.descripcion_larga || v.description)) {
      box.appendChild(C.el('p', 'man-g__analisis-desc', C.esc(v.descripcion_larga || v.description))); algo = true;
    }
    if (modo === 'como_funciona' && v.como_funciona) {
      box.appendChild(C.el('p', 'man-g__analisis-desc', C.esc(v.como_funciona))); algo = true;
    }
    if ((modo === 'ambos' || modo === 'descriptivo') && a && a.descriptivo) {
      box.appendChild(C.el('p', 'man-g__analisis-desc', C.esc(a.descriptivo))); algo = true;
    }
    if ((modo === 'ambos' || modo === 'cuantitativo') && a && a.cuantitativo) {
      box.appendChild(C.el('p', 'man-g__analisis-num', C.esc(a.cuantitativo))); algo = true;
    }
    if (!algo) { box.appendChild(C.el('p', 'man-g__analisis-desc', 'Sin contenido disponible para esta vista.')); }
  }

  function init(fig) {
    var chartEl = fig.querySelector('.man-g__chart');
    var titleEl = fig.querySelector('.man-g__title');
    if (!chartEl) { return; }
    var st = {
      view: fig.getAttribute('data-view') || 'oni_serie',
      type: fig.getAttribute('data-type') || '',
      hasta: fig.getAttribute('data-hasta') || '2027-02',
      mes: fig.getAttribute('data-mes') || '',
      legend: fig.getAttribute('data-legend') !== '0',
      legendStyle: fig.getAttribute('data-legend-style') || 'text',
      legendPos: fig.getAttribute('data-legend-pos') || 'bottom',
      analisis: fig.getAttribute('data-analisis') || 'ambos',
      toolbar: fig.getAttribute('data-toolbar') !== '0',
      actions: parseActions(fig.getAttribute('data-actions')),
      grupo: fig.getAttribute('data-grupo') || '',
      payload: null, viz: null
    };

    // Composable: si pertenece a un grupo, el estado del grupo manda. El gráfico
    // se re-renderiza cuando un filtro cambia el grupo.
    if (st.grupo && window.MANGrupo) {
      var est = window.MANGrupo.init(st.grupo, { view: st.view, type: st.type, mes: st.mes, hasta: st.hasta });
      st.view = est.view || st.view; st.type = est.type || st.type;
      st.mes = est.mes || st.mes; st.hasta = est.hasta || st.hasta;
      window.MANGrupo.subscribe(st.grupo, function (estado) {
        var cambio = estado.view !== st.view || estado.type !== st.type || estado.mes !== st.mes || estado.hasta !== st.hasta;
        st.view = estado.view; st.type = estado.type; st.mes = estado.mes; st.hasta = estado.hasta;
        if (cambio) { cargar(fig, chartEl, titleEl, st); }
      });
    }

    cargar(fig, chartEl, titleEl, st);
  }

  function parseActions(s) {
    if (!s) { return DEFAULT_ACTIONS.slice(); }
    var arr = String(s).split(',').map(function (x) { return x.trim(); })
      .filter(function (x) { return DEFAULT_ACTIONS.indexOf(x) >= 0; });
    return arr.length ? arr : DEFAULT_ACTIONS.slice();
  }

  function cargar(fig, chartEl, titleEl, st) {
    C.rest('/render', { view: st.view, type: st.type, hasta: st.hasta, mes: st.mes })
      .then(function (p) {
        st.payload = p;
        st.type = (p.chart && p.chart.key) || st.type;
        var nombre = (p.view && p.view.name) || 'Gráfico';
        if (titleEl) { titleEl.textContent = nombre; }
        // Accesibilidad: el lienzo del gráfico es la "imagen" con su descripción.
        chartEl.setAttribute('role', 'img');
        chartEl.setAttribute('aria-label', nombre + (p.view && p.view.description ? '. ' + p.view.description : ''));
        quitarSkeleton(fig);
        if (st.toolbar) { construirToolbar(fig, chartEl, titleEl, st); }
        dibujar(fig, chartEl, st);
        pintarAnalisis(fig, p, st.analisis);
        if (st.grupo && window.MANGrupo) { window.MANGrupo.payload(st.grupo, p); }
      })
      .catch(function () { C.error(fig, 'No se pudo cargar el gráfico.', function () { cargar(fig, chartEl, titleEl, st); }); });
  }

  function dibujar(fig, chartEl, st) {
    try {
      if (!window.MANRenderer) { throw new Error('renderer'); }
      st.viz = window.MANRenderer.render(chartEl, st.payload, { legend: st.legend, legendStyle: st.legendStyle, legendPos: st.legendPos });
      fig.setAttribute('data-type', st.type);
      var sel = fig.querySelector('.man-g__swap select');
      if (sel) { sel.value = st.type; }
    } catch (e) {
      chartEl.innerHTML = '<p class="man-analisis" style="padding:12px">No se pudo dibujar el gráfico. Verifica la conexión con la CDN de D3plus.</p>';
    }
  }

  function reRender(fig, chartEl, st, nuevoTipo) {
    chartEl.classList.add('is-loading');
    C.rest('/render', { view: st.view, type: nuevoTipo, hasta: st.hasta, mes: st.mes })
      .then(function (p) {
        st.payload = p; st.type = (p.chart && p.chart.key) || nuevoTipo;
        dibujar(fig, chartEl, st);
        chartEl.classList.remove('is-loading');
      })
      .catch(function () { chartEl.classList.remove('is-loading'); });
  }

  /* ---------- Análisis (descriptivo + cuantitativo) ---------- */
  function pintarAnalisis(fig, p, modo) {
    var prev = fig.querySelector('.man-g__analisis');
    if (prev) { prev.parentNode.removeChild(prev); }
    if (modo === 'no') { return; }
    var a = (p && p.view && p.view.analisis) || null;
    if (!a) { return; }
    var box = C.el('div', 'man-g__analisis');
    if ((modo === 'ambos' || modo === 'descriptivo') && a.descriptivo) {
      box.appendChild(C.el('p', 'man-g__analisis-desc', C.esc(a.descriptivo)));
    }
    if ((modo === 'ambos' || modo === 'cuantitativo') && a.cuantitativo) {
      box.appendChild(C.el('p', 'man-g__analisis-num', C.esc(a.cuantitativo)));
    }
    if (!box.childNodes.length) { return; }
    var pie = fig.querySelector('.man-fuentes');
    if (pie) { fig.insertBefore(box, pie); } else { fig.appendChild(box); }
  }

  /* ---------- Barra de herramientas ---------- */
  function construirToolbar(fig, chartEl, titleEl, st) {
    if (fig.querySelector('.man-g__toolbar')) { return; }
    var bar = C.el('div', 'man-g__toolbar');
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', 'Acciones del gráfico');

    st.actions.forEach(function (a) {
      if (a === 'cambiar') { bar.appendChild(swap(fig, chartEl, st)); return; }
      var b = C.el('button', 'man-g__action');
      b.type = 'button';
      b.setAttribute('data-accion', a);
      b.innerHTML = '<span class="dashicons dashicons-' + ICON[a] + '" aria-hidden="true"></span><span class="man-g__action-txt">' + C.esc(LABEL[a]) + '</span>';
      bar.appendChild(b);
    });

    bar.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('.man-g__action') : null;
      if (!b) { return; }
      var a = b.getAttribute('data-accion');
      if (a === 'explicacion') { openModal(fig, '¿Cómo funciona este gráfico?', explicacionNodo(st.payload)); }
      else if (a === 'detalle') { openModal(fig, 'Detalle del gráfico', detalleNodo(st.payload)); }
      else if (a === 'datos') { openModal(fig, 'Datos de la vista', tablaNodo(st.payload)); }
      else if (a === 'imagen') { exportarPNG(chartEl, st); }
      else if (a === 'descarga') { descargarJSON(st.payload, st); }
      else if (a === 'compartir') { compartir(fig, b); }
    });

    if (titleEl && titleEl.nextSibling) { fig.insertBefore(bar, titleEl.nextSibling); }
    else { fig.insertBefore(bar, chartEl); }
  }

  function swap(fig, chartEl, st) {
    var wrap = C.el('div', 'man-g__swap');
    wrap.innerHTML = '<span class="dashicons dashicons-' + ICON.cambiar + '" aria-hidden="true"></span><span class="man-g__action-txt">Cambiar</span><span class="man-g__caret" aria-hidden="true">▾</span>';
    var sel = document.createElement('select');
    sel.setAttribute('aria-label', 'Cambiar tipo de gráfico');
    var compat = (st.payload && st.payload.compatible) || [];
    compat.forEach(function (t) {
      var o = document.createElement('option');
      o.value = t; o.textContent = TIPO_LABEL[t] || t;
      sel.appendChild(o);
    });
    sel.value = st.type;
    sel.addEventListener('change', function () {
      if (st.grupo && window.MANGrupo) { window.MANGrupo.set(st.grupo, { type: sel.value }); }
      else { reRender(fig, chartEl, st, sel.value); }
    });
    wrap.appendChild(sel);
    return wrap;
  }

  /* ---------- Modales ---------- */
  function getModal(fig) {
    var m = fig.querySelector('.man-g__modal');
    if (m) { return m; }
    m = C.el('div', 'man-g__modal');
    m.setAttribute('hidden', '');
    m.innerHTML = '<div class="man-g__modal-back" data-cerrar="1"></div>' +
      '<div class="man-g__modal-panel" role="dialog" aria-modal="true">' +
      '<div class="man-g__modal-head"><strong class="man-g__modal-title"></strong>' +
      '<button type="button" class="man-g__modal-x" aria-label="Cerrar" data-cerrar="1">×</button></div>' +
      '<div class="man-g__modal-body"></div></div>';
    fig.appendChild(m);
    m.addEventListener('click', function (e) { if (e.target.getAttribute('data-cerrar')) { m.setAttribute('hidden', ''); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { m.setAttribute('hidden', ''); } });
    return m;
  }

  function openModal(fig, titulo, nodo) {
    var m = getModal(fig);
    m.querySelector('.man-g__modal-title').textContent = titulo;
    var body = m.querySelector('.man-g__modal-body');
    body.innerHTML = '';
    body.appendChild(nodo);
    m.removeAttribute('hidden');
  }

  function explicacionNodo(p) {
    var v = (p && p.view) || {};
    var wrap = C.el('div', 'man-g__expl');
    var txt = v.como_funciona || v.description || 'Sin explicación disponible para esta vista.';
    wrap.appendChild(C.el('p', 'man-g__analisis-desc', C.esc(txt)));
    return wrap;
  }

  function detalleNodo(p) {
    var v = (p && p.view) || {};
    var dl = C.el('dl', 'man-g__dl');
    var filas = (p && p.data) ? p.data.length : 0;
    par(dl, 'Tipo de gráfico', (p && p.chart && p.chart.label) || '—');
    par(dl, 'Categoría', v.category || '—');
    par(dl, 'Dimensiones', (v.dimensions || []).join(', ') || '—');
    par(dl, 'Medidas', (v.measures || []).join(', ') || '—');
    par(dl, 'Filas', String(filas));
    if (v.description) { par(dl, 'Descripción', v.description); }
    return dl;
  }
  function par(dl, k, val) {
    dl.appendChild(C.el('dt', null, C.esc(k)));
    dl.appendChild(C.el('dd', null, C.esc(val)));
  }

  function tablaNodo(p) {
    var v = (p && p.view) || {};
    var cols = (v.dimensions || []).concat(v.measures || []);
    var data = (p && p.data) || [];
    var tabla = C.el('table', 'man-g__tabla');
    var thead = C.el('thead'), trh = C.el('tr');
    cols.forEach(function (c) { trh.appendChild(C.el('th', null, C.esc(c))); });
    thead.appendChild(trh); tabla.appendChild(thead);
    var tbody = C.el('tbody');
    data.forEach(function (row) {
      var tr = C.el('tr');
      cols.forEach(function (c) {
        var val = row[c];
        var txt = (typeof val === 'number') ? C.num(val, (Math.round(val) === val ? 0 : 2)) : (val == null ? '' : String(val));
        tr.appendChild(C.el('td', null, C.esc(txt)));
      });
      tbody.appendChild(tr);
    });
    tabla.appendChild(tbody);
    return tabla;
  }

  /* ---------- Exportar / compartir ---------- */
  function nombre(st) {
    return 'man-' + (st.view || 'grafico') + '-' + (st.type || '');
  }

  function exportarPNG(chartEl, st) {
    var svg = chartEl.querySelector('svg');
    if (!svg) { return; }
    var rect = svg.getBoundingClientRect();
    var w = Math.max(320, Math.round(rect.width || svg.clientWidth || 800));
    var h = Math.max(240, Math.round(rect.height || svg.clientHeight || 500));
    var clone = svg.cloneNode(true);
    clone.setAttribute('width', w); clone.setAttribute('height', h);
    var xml = new XMLSerializer().serializeToString(clone);
    var url = URL.createObjectURL(new Blob([xml], { type: 'image/svg+xml;charset=utf-8' }));
    var img = new Image();
    img.onload = function () {
      try {
        var s = 2, cv = document.createElement('canvas');
        cv.width = w * s; cv.height = h * s;
        var ctx = cv.getContext('2d');
        ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, cv.width, cv.height);
        ctx.drawImage(img, 0, 0, cv.width, cv.height);
        URL.revokeObjectURL(url);
        cv.toBlob(function (blob) {
          if (blob) { descargar(blob, nombre(st) + '.png'); }
          else { descargarTexto(xml, nombre(st) + '.svg', 'image/svg+xml'); }
        });
      } catch (e) { URL.revokeObjectURL(url); descargarTexto(xml, nombre(st) + '.svg', 'image/svg+xml'); }
    };
    img.onerror = function () { URL.revokeObjectURL(url); descargarTexto(xml, nombre(st) + '.svg', 'image/svg+xml'); };
    img.src = url;
  }

  function descargarJSON(p, st) {
    var payload = { view: (p && p.view) || {}, data: (p && p.data) || [] };
    descargarTexto(JSON.stringify(payload, null, 2), nombre(st) + '.json', 'application/json');
  }

  function descargarTexto(txt, name, type) {
    descargar(new Blob([txt], { type: type + ';charset=utf-8' }), name);
  }
  function descargar(blob, name) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = name;
    document.body.appendChild(a); a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
  }

  function compartir(fig, btn) {
    var url = location.href.split('#')[0] + '#' + fig.id;
    var ok = function () { var t = btn.querySelector('.man-g__action-txt'); if (t) { var o = t.textContent; t.textContent = 'URL copiada'; btn.classList.add('is-success'); setTimeout(function () { t.textContent = o; btn.classList.remove('is-success'); }, 1600); } };
    if (navigator.share) { navigator.share({ title: document.title, url: url }).catch(function () {}); return; }
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(ok).catch(function () {}); return; }
    try {
      var ta = document.createElement('textarea'); ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); ok();
    } catch (e) { /* sin portapapeles */ }
  }

  function quitarSkeleton(fig) {
    var s = fig.querySelector('.man-skeleton');
    if (s) { s.parentNode.removeChild(s); }
  }
})();
