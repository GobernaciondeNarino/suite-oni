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

  function pintar(cont, filas) {
    C.quitarSkeleton(cont);
    limpiar(cont);
    var cuerpo = C.el('div', 'man-estado-api__cuerpo');
    cuerpo.appendChild(C.el('p', 'man-titulo', 'Estado de las fuentes de datos'));

    var tabla = C.el('table', 'man-tabla');
    var html = '<thead><tr><th>Fuente</th><th>Estado</th><th>Última actualización</th></tr></thead><tbody>';
    (filas || []).forEach(function (f) {
      html += '<tr><td>' + C.esc(f.fuente) + '</td>' +
        '<td><span class="man-pip" style="background:' + color(f.estado) + '"></span>' + C.esc(f.estado) + '</td>' +
        '<td>' + C.esc(f.ultima) + '</td></tr>';
    });
    html += '</tbody>';
    tabla.innerHTML = html;
    cuerpo.appendChild(tabla);

    cont.insertBefore(cuerpo, cont.querySelector('.man-fuentes'));
  }

  function limpiar(cont) {
    var x = cont.querySelector('.man-estado-api__cuerpo'); if (x) { x.parentNode.removeChild(x); }
    var e = cont.querySelector('.man-error'); if (e) { e.parentNode.removeChild(e); }
  }
})();
