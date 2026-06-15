/* [man_filtro] y [man_panel] — componentes composables enlazados por grupo.
   - [man_filtro control="vista|tipo|mes"] emite cambios al grupo (window.MANGrupo).
   - [man_panel] muestra título, descripción y detalles del gráfico vigente.
   Un [man_grafico grupo="X"] del mismo grupo se re-renderiza al cambiar el filtro. */
(function () {
  'use strict';
  var C = window.MANcore;
  var TIPO_LABEL = {
    bar: 'Barras', stacked_bar: 'Barras apiladas', line: 'Líneas', area: 'Área',
    stacked_area: 'Área apilada', pie: 'Pastel', donut: 'Dona', treemap: 'Treemap', box_whisker: 'Caja y bigotes'
  };
  var _vistas = null, _prom = null;

  function vistas() {
    if (_vistas) { return Promise.resolve(_vistas); }
    if (_prom) { return _prom; }
    _prom = C.rest('/vistas').then(function (d) { _vistas = (d && d.vistas) || []; return _vistas; })
      .catch(function () { _vistas = []; return _vistas; });
    return _prom;
  }
  function vistaPorId(id) { for (var i = 0; i < _vistas.length; i++) { if (_vistas[i].id === id) { return _vistas[i]; } } return null; }

  C.ready(function () {
    if (!window.MANGrupo) { return; }
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-filtro]'), initFiltro);
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-panel]'), initPanel);
  });

  /* ---------------- Filtros ---------------- */
  function initFiltro(cont) {
    var grupo = cont.getAttribute('data-grupo') || '';
    var control = cont.getAttribute('data-control') || 'vista';
    if (!grupo) { return; }
    if (control === 'mes') { return filtroMes(cont, grupo); }
    return filtroSelect(cont, grupo, control);
  }

  function filtroSelect(cont, grupo, control) {
    vistas().then(function () {
      C.quitarSkeleton(cont);
      var sel = C.el('select', 'man-filtro__select');
      var label = C.el('label', 'man-filtro__label', C.esc(control === 'tipo' ? 'Gráfico' : 'Vista') + ' ');
      label.appendChild(sel);
      cont.appendChild(label);

      function poblarVista() {
        sel.innerHTML = '';
        _vistas.forEach(function (v) { var o = document.createElement('option'); o.value = v.id; o.textContent = v.name; sel.appendChild(o); });
        var est = window.MANGrupo.get(grupo);
        sel.value = est.view || (_vistas[0] && _vistas[0].id);
      }
      function poblarTipo() {
        var est = window.MANGrupo.get(grupo);
        var v = vistaPorId(est.view);
        var compat = (v && v.compatibles) || ['bar'];
        sel.innerHTML = '';
        compat.forEach(function (t) { var o = document.createElement('option'); o.value = t; o.textContent = TIPO_LABEL[t] || t; sel.appendChild(o); });
        sel.value = (compat.indexOf(est.type) >= 0) ? est.type : (v ? v.default : compat[0]);
      }

      if (control === 'tipo') {
        poblarTipo();
        window.MANGrupo.subscribe(grupo, function () { poblarTipo(); });
        sel.addEventListener('change', function () { window.MANGrupo.set(grupo, { type: sel.value }); });
      } else {
        poblarVista();
        window.MANGrupo.subscribe(grupo, function (estado) { if (sel.value !== estado.view) { sel.value = estado.view; } });
        sel.addEventListener('change', function () {
          var v = vistaPorId(sel.value);
          window.MANGrupo.set(grupo, { view: sel.value, type: v ? v.default : '' });
        });
      }
    });
  }

  function filtroMes(cont, grupo) {
    var inicio = cont.getAttribute('data-inicio') || '2026-03';
    var fin = cont.getAttribute('data-fin') || '2027-03';
    C.rest('/oni').then(function (d) {
      var serie = (d && d.serie) || [];
      var win = serie.filter(function (s) { return s.mes >= inicio && s.mes <= fin; });
      if (!win.length) { win = serie; }
      if (!win.length) { return; }
      C.quitarSkeleton(cont);
      var lbl = C.el('span', 'man-filtro__mes');
      var label = C.el('label', 'man-filtro__label', 'Mes ');
      label.appendChild(lbl);
      var slider = document.createElement('input');
      slider.type = 'range'; slider.min = 0; slider.max = win.length - 1; slider.value = 0;
      slider.className = 'man-timeline__rango';
      slider.setAttribute('aria-label', 'Mes');
      cont.appendChild(label);
      var pista = C.el('div', 'man-filtro__pista'); pista.appendChild(slider); cont.appendChild(pista);
      function set(i) {
        i = Math.max(0, Math.min(win.length - 1, i));
        slider.value = i; var s = win[i];
        lbl.textContent = mesLargo(s.mes);
        window.MANGrupo.set(grupo, { mes: s.mes });
      }
      slider.addEventListener('input', function () { set(parseInt(slider.value, 10)); });
      set(0);
    }).catch(function () { C.error(cont, 'No se pudo cargar el filtro de mes.', function () { filtroMes(cont, grupo); }); });
  }

  /* ---------------- Panel (título + descripción + detalles) ---------------- */
  function initPanel(cont) {
    var grupo = cont.getAttribute('data-grupo') || '';
    if (!grupo) { return; }
    window.MANGrupo.onPayload(grupo, function (p) { pintarPanel(cont, p); });
  }

  function pintarPanel(cont, p) {
    C.quitarSkeleton(cont);
    var prev = cont.querySelector('.man-panel__cuerpo');
    if (prev) { prev.parentNode.removeChild(prev); }
    var v = (p && p.view) || {};
    var cuerpo = C.el('div', 'man-panel__cuerpo');
    cuerpo.appendChild(C.el('p', 'man-titulo', C.esc(v.name || 'Gráfico')));
    if (v.description) { cuerpo.appendChild(C.el('p', 'man-analisis', C.esc(v.description))); }
    var dl = C.el('dl', 'man-panel__dl');
    par(dl, 'Tipo', (p.chart && p.chart.label) || '—');
    par(dl, 'Categoría', v.category || '—');
    par(dl, 'Dimensiones', (v.dimensions || []).join(', ') || '—');
    par(dl, 'Medidas', (v.measures || []).join(', ') || '—');
    par(dl, 'Filas', String((p.data || []).length));
    cuerpo.appendChild(dl);
    var pie = cont.querySelector('.man-fuentes');
    if (pie) { cont.insertBefore(cuerpo, pie); } else { cont.appendChild(cuerpo); }
  }
  function par(dl, k, val) { dl.appendChild(C.el('dt', null, C.esc(k))); dl.appendChild(C.el('dd', null, C.esc(val))); }

  function mesLargo(mes) {
    var M = { '01': 'Enero', '02': 'Febrero', '03': 'Marzo', '04': 'Abril', '05': 'Mayo', '06': 'Junio', '07': 'Julio', '08': 'Agosto', '09': 'Septiembre', '10': 'Octubre', '11': 'Noviembre', '12': 'Diciembre' };
    var p = String(mes).split('-');
    return (M[p[1]] || p[1]) + ' ' + p[0];
  }
})();
