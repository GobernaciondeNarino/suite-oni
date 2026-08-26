/* [man_estado_api] — panel público de salud de las APIs (transparencia ciudadana). */
(function () {
  'use strict';
  var C = window.MANcore;

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-estado-api]'), cargar);
  });

  function cargar(cont) {
    C.rest('/estado-apis')
      .then(function (d) { pintar(cont, d); })
      .catch(function () { C.error(cont, 'No se pudo consultar el estado de las fuentes.', function () { cargar(cont); }); });
  }

  function color(estado) {
    var m = { ok: '#2e7d32', degradado: '#f9a825', caido: '#c62828', 'sin datos': '#ef6c00', inactiva: '#9aa0a6' };
    return m[estado] || '#9aa0a6';
  }

  // Mapa fuente configurada → clave de procedencia del backend.
  var ORIGEN = {
    noaa_oni: 'oni', iri_enso: 'pronostico_oficial', ideam: 'alertas_ideam',
    ioc: 'nivel_mar', sivigila: 'salud', firms: 'focos_calor', deficit: 'deficit_hidrico'
  };
  var LEYENDA = { vivo: 'en vivo', respaldo: 'respaldo', ausente: 'sin datos' };

  function origen(proc, slug) {
    if (!proc || !ORIGEN[slug] || !proc[ORIGEN[slug]]) { return '—'; }
    return LEYENDA[proc[ORIGEN[slug]].estado] || '—';
  }

  function pintar(cont, d) {
    limpiar(cont);
    // La respuesta pasó a {fuentes, procedencia}; se acepta el array plano de
    // versiones anteriores por si queda una respuesta cacheada.
    var filas = (d && d.fuentes) ? d.fuentes : (Array.isArray(d) ? d : []);
    var proc = (d && d.procedencia) ? d.procedencia : null;

    var cuerpo = C.el('div', 'man-estado-api__cuerpo');
    cuerpo.appendChild(C.el('p', 'man-titulo', 'Estado de las fuentes de datos'));

    if (proc) {
      var respaldo = Object.keys(proc).filter(function (k) { return proc[k].estado === 'respaldo'; });
      if (respaldo.length) {
        var av = C.el('p', 'man-aviso',
          'Mostrando datos de respaldo en ' + respaldo.length +
          (respaldo.length === 1 ? ' fuente' : ' fuentes') + ': ' +
          respaldo.map(function (k) { return C.esc(proc[k].etiqueta); }).join(', ') +
          '. La información de esos componentes puede no estar al día.');
        av.setAttribute('role', 'status');
        cuerpo.appendChild(av);
      }
    }

    var tabla = C.el('table', 'man-tabla');
    var html = '<thead><tr><th>Fuente</th><th>Estado</th><th>Datos que sirve</th><th>Última actualización</th></tr></thead><tbody>';
    filas.forEach(function (f) {
      html += '<tr><td>' + C.esc(f.fuente) + '</td>' +
        '<td><span class="man-pip" style="background:' + color(f.estado) + '"></span>' + C.esc(f.estado) + '</td>' +
        '<td>' + C.esc(origen(proc, f.slug)) + '</td>' +
        '<td>' + C.esc(f.ultima) + '</td></tr>';
    });
    html += '</tbody>';
    tabla.innerHTML = html;
    cuerpo.appendChild(tabla);

    cont.insertBefore(cuerpo, cont.querySelector('.man-fuentes'));
  }

  function limpiar(cont) { C.limpiar(cont, 'man-estado-api__cuerpo'); }
})();
