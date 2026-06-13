/* [man_mar] — oleaje del Pacífico (Open-Meteo Marine en vivo) + nivel del mar IOC. */
(function () {
  'use strict';
  var C = window.MANcore;

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-mar]'), cargar);
  });

  function cargar(cont) {
    C.rest('/mar').then(function (d) {
      var p = d.punto_oleaje || { lat: 1.81, lon: -78.76 };
      var url = 'https://marine-api.open-meteo.com/v1/marine?latitude=' + p.lat + '&longitude=' + p.lon +
        '&daily=wave_height_max,wave_period_max&timezone=America%2FBogota';
      return C.externo(url).then(function (o) { pintar(cont, d, o); });
    }).catch(function () {
      C.error(cont, 'No se pudo cargar el estado del mar.', function () { cargar(cont); });
    });
  }

  function pintar(cont, d, o) {
    C.quitarSkeleton(cont);
    limpiar(cont);
    var cuerpo = C.el('div', 'man-mar__cuerpo');
    cuerpo.appendChild(C.el('p', 'man-titulo', 'Mar y oleaje — Pacífico de Nariño'));

    var dd = o.daily || {};
    var fechas = dd.time || [], olas = dd.wave_height_max || [];
    if (fechas.length) {
      cuerpo.appendChild(C.lineaSimple(fechas, olas, { color: 'var(--man-acento-tecnico,#003087)', area: true }));
      var maxOla = Math.max.apply(null, olas.filter(function (v) { return v != null; }));
      cuerpo.appendChild(C.el('p', 'man-analisis',
        'Oleaje máximo previsto de ' + C.num(maxOla, 1) + ' m frente a Tumaco. ' +
        (maxOla >= 2.5 ? 'Condiciones de marejada: precaución para faenas y embarcaciones menores.' : 'Oleaje dentro de rangos habituales.')));
    } else {
      cuerpo.appendChild(C.el('p', 'man-analisis', 'Sin datos de oleaje disponibles en este momento.'));
    }

    if (d.disponible && d.nivel) {
      var muestras = d.nivel.muestras ? d.nivel.muestras.length : 0;
      cuerpo.appendChild(C.el('p', 'man-mute-line', 'Nivel del mar (IOC, estación ' + C.esc(d.nivel.estacion || '') + '): ' + C.num(muestras, 0) + ' muestras recientes.'));
    } else {
      cuerpo.appendChild(C.el('p', 'man-mute-line', 'Nivel del mar (IOC): fuente no activada todavía en el panel de Fuentes.'));
    }

    cont.insertBefore(cuerpo, cont.querySelector('.man-fuentes'));
  }

  function limpiar(cont) {
    var x = cont.querySelector('.man-mar__cuerpo'); if (x) { x.parentNode.removeChild(x); }
    var e = cont.querySelector('.man-error'); if (e) { e.parentNode.removeChild(e); }
  }
})();
