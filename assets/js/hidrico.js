/* [man_hidrico] — caudal de ríos (Open-Meteo Flood/GloFAS) + humedad de suelo
   (Open-Meteo Forecast), en vivo por municipio. */
(function () {
  'use strict';
  var C = window.MANcore;

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-hidrico]'), cargar);
  });

  function cargar(cont) {
    var div = cont.getAttribute('data-municipio');
    var mun = C.municipio(div);
    if (!mun) { C.error(cont, 'Municipio no válido.'); return; }

    var flood = 'https://flood-api.open-meteo.com/v1/flood?latitude=' + mun.lat + '&longitude=' + mun.lon + '&daily=river_discharge';
    var soil = 'https://api.open-meteo.com/v1/forecast?latitude=' + mun.lat + '&longitude=' + mun.lon +
      '&hourly=soil_moisture_0_to_1cm&forecast_days=2&timezone=America%2FBogota';

    Promise.all([C.externo(flood).then(ok, fail), C.externo(soil).then(ok, fail)])
      .then(function (res) { pintar(cont, mun, res[0], res[1]); })
      .catch(function () { C.error(cont, 'No se pudo cargar la información hídrica (Open-Meteo).', function () { cargar(cont); }); });
  }

  function ok(d) { return d; }
  function fail() { return null; }

  function pintar(cont, mun, flood, soil) {
    C.quitarSkeleton(cont);
    limpiar(cont);
    var cuerpo = C.el('div', 'man-hidrico__cuerpo');
    cuerpo.appendChild(C.el('p', 'man-titulo', 'Recursos hídricos — ' + C.esc(mun.nombre)));

    var fechas = [], caudal = [];
    if (flood && flood.daily) { fechas = flood.daily.time || []; caudal = flood.daily.river_discharge || []; }

    if (fechas.length && caudal.filter(function (v) { return v != null; }).length) {
      cuerpo.appendChild(C.lineaSimple(fechas, caudal, { color: 'var(--man-acento-tecnico,#003087)', area: true }));
      var ult = ultimo(caudal);
      cuerpo.appendChild(C.el('p', 'man-analisis', 'Caudal estimado del río más cercano: ' + C.num(ult, 1) + ' m³/s (modelo GloFAS).'));
    } else {
      cuerpo.appendChild(C.el('p', 'man-analisis', 'Sin datos de caudal para este municipio (puede no haber un río modelado por GloFAS en la celda).'));
    }

    if (soil && soil.hourly && soil.hourly.soil_moisture_0_to_1cm) {
      var hum = ultimo(soil.hourly.soil_moisture_0_to_1cm);
      if (hum != null) {
        cuerpo.appendChild(C.el('p', 'man-mute-line', 'Humedad de suelo (0–1 cm): ' + C.num(hum, 2) + ' m³/m³ — ' +
          (hum < 0.15 ? 'suelo seco (riesgo de sequía agrícola).' : (hum > 0.4 ? 'suelo muy húmedo.' : 'humedad moderada.'))));
      }
    }

    cont.insertBefore(cuerpo, cont.querySelector('.man-fuentes'));
  }

  function ultimo(arr) {
    for (var i = arr.length - 1; i >= 0; i--) { if (arr[i] != null) { return arr[i]; } }
    return null;
  }

  function limpiar(cont) {
    var x = cont.querySelector('.man-hidrico__cuerpo'); if (x) { x.parentNode.removeChild(x); }
    var e = cont.querySelector('.man-error'); if (e) { e.parentNode.removeChild(e); }
  }
})();
