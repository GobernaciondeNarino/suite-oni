/* [man_mapa] — coroplético Leaflet de los 64 municipios + panel al clic con
   pronóstico Open-Meteo en vivo. */
(function () {
  'use strict';
  var C = window.MANcore;

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-mapa]'), init);
  });

  function init(cont) {
    if (typeof L === 'undefined') { C.error(cont, 'La librería de mapas (Leaflet) no está disponible.'); return; }
    var mes = cont.getAttribute('data-mes') || (C.MAN && C.MAN.mesActual) || '';
    var geoUrl = cont.getAttribute('data-geojson');
    var lienzo = cont.querySelector('.man-mapa__lienzo');

    var map = L.map(lienzo, { scrollWheelZoom: false }).setView([1.4, -77.8], 7);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 12, attribution: '© OpenStreetMap'
    }).addTo(map);

    Promise.all([C.externo(geoUrl), C.rest('/departamento', { mes: mes })])
      .then(function (res) {
        var geo = res[0], dep = res[1] || [];
        var byDiv = {};
        dep.forEach(function (r) { byDiv[r.divipola] = r; });
        C.quitarSkeleton(cont);

        var capa = L.geoJSON(geo, {
          style: function (f) {
            var r = byDiv[f.properties.MPIO_CDPMP];
            return { color: '#ffffff', weight: 1, fillColor: r ? r.color : '#cccccc', fillOpacity: 0.75 };
          },
          onEachFeature: function (f, layer) {
            var div = f.properties.MPIO_CDPMP;
            var nom = f.properties.MPIO_CNMBR;
            var r = byDiv[div];
            layer.bindTooltip(nom + (r ? (' · riesgo ' + C.num(r.riesgo, 2)) : ''), { sticky: true });
            layer.on('mouseover', function () { layer.setStyle({ weight: 2 }); });
            layer.on('mouseout', function () { layer.setStyle({ weight: 1 }); });
            layer.on('click', function () { panel(cont, div, nom); });
          }
        }).addTo(map);

        try { map.fitBounds(capa.getBounds(), { padding: [10, 10] }); } catch (e) { /* ignorado */ }
      })
      .catch(function () {
        C.error(cont, 'No se pudo cargar el mapa de Nariño.', function () { map.remove(); init(cont); });
      });
  }

  function panel(cont, div, nom) {
    var p = cont.querySelector('.man-mapa__panel');
    if (!p) { return; }
    p.hidden = false;
    p.innerHTML = '<p class="man-titulo">' + C.esc(nom) + '</p><p class="man-skeleton">Cargando…</p>';

    C.rest('/municipio/' + encodeURIComponent(div)).then(function (d) {
      var html = '<p class="man-titulo">' + C.esc(d.nombre) + '</p>' +
        '<p>ONI ' + (d.oni >= 0 ? '+' : '') + C.num(d.oni, 1) + ' · ' + C.esc(d.fase) + '</p>' +
        '<p>Riesgo <span class="man-chip" style="background:' + C.esc(d.color) + '">' + C.esc(d.nivel_etiqueta) + ' · ' + C.num(d.riesgo, 2) + '</span></p>' +
        '<p class="man-analisis">' + C.esc(d.texto_analisis) + '</p>';
      p.innerHTML = html;

      var mun = C.municipio(div);
      if (mun) {
        var url = 'https://api.open-meteo.com/v1/forecast?latitude=' + mun.lat + '&longitude=' + mun.lon +
          '&current=temperature_2m,precipitation&timezone=America%2FBogota';
        C.externo(url).then(function (o) {
          if (o.current) {
            p.appendChild(C.el('p', null, 'Ahora: ' + C.num(o.current.temperature_2m, 1) + ' °C · ' + C.num(o.current.precipitation, 1) + ' mm'));
          }
        }).catch(function () { /* dato puntual opcional */ });
      }
    }).catch(function () {
      p.innerHTML = '<p class="man-error">No se pudo cargar el municipio.</p>';
    });
  }
})();
