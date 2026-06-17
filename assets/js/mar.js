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
      var chart = C.el('div', 'man-mar__grafico');
      chart.style.minHeight = '220px';
      cuerpo.appendChild(chart);
      C.lineaInteractiva(chart, fechas, olas, { area: true, color: '#003087', xTitle: 'Día', yTitle: 'Altura de ola (m)' });
    } else {
      cuerpo.appendChild(C.el('p', 'man-analisis', 'Sin datos de oleaje disponibles en este momento.'));
    }

    // El texto descriptivo/análisis ya no va aquí: se coloca aparte con
    // [man_info elemento="mar" tipo="descripcion|analisis"].
    cont.insertBefore(cuerpo, cont.querySelector('.man-fuentes'));
  }

  function limpiar(cont) {
    var x = cont.querySelector('.man-mar__cuerpo'); if (x) { x.parentNode.removeChild(x); }
    var e = cont.querySelector('.man-error'); if (e) { e.parentNode.removeChild(e); }
  }
})();
