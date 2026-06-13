/* [man_salud] — dengue (SIVIGILA) sensible al clima. Degrada con elegancia
   si la fuente aún no está activada. */
(function () {
  'use strict';
  var C = window.MANcore;

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-salud]'), cargar);
  });

  function cargar(cont) {
    C.rest('/salud')
      .then(function (d) { pintar(cont, d); })
      .catch(function () { C.error(cont, 'No se pudieron cargar los datos de salud.', function () { cargar(cont); }); });
  }

  function pintar(cont, d) {
    C.quitarSkeleton(cont);
    limpiar(cont);
    var cuerpo = C.el('div', 'man-salud__cuerpo');
    cuerpo.appendChild(C.el('p', 'man-titulo', 'Salud y clima — dengue'));

    if (d.disponible) {
      cuerpo.appendChild(C.el('p', 'man-valores', '<strong>' + C.num(d.total, 0) + '</strong> registros de Nariño en la última sincronización.'));
      cuerpo.appendChild(C.el('p', 'man-analisis',
        'El Niño favorece al vector Aedes aegypti (más calor y agua almacenada por racionamientos), lo que puede elevar los casos de dengue. Vigile su evolución junto a las anomalías de temperatura.'));
    } else {
      cuerpo.appendChild(C.el('p', 'man-analisis',
        'La fuente SIVIGILA aún no está activada o no tiene un dataset-id configurado. Un administrador puede activarla en Monitor Ambiental → Fuentes para mostrar aquí los casos de dengue de Nariño y su relación con el clima.'));
    }

    cont.insertBefore(cuerpo, cont.querySelector('.man-fuentes'));
  }

  function limpiar(cont) {
    var x = cont.querySelector('.man-salud__cuerpo'); if (x) { x.parentNode.removeChild(x); }
    var e = cont.querySelector('.man-error'); if (e) { e.parentNode.removeChild(e); }
  }
})();
