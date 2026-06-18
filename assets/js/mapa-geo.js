/* [man_mapa_fews] y [man_mapa_geo] — mapa multi-fuente de Nariño.
   Dibuja los límites de departamento, subregiones y municipios y superpone las
   capas georreferenciadas (estaciones FEWS por red; en el set "todas" también
   focos NASA FIRMS y el mareógrafo IOC). Cada capa se puede conmutar. */
(function () {
  'use strict';
  var C = window.MANcore;

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-mapa-geo]'), init);
  });

  function colorAlerta(a, base) {
    return a === 'alta' ? '#C0392B' : (a === 'media' ? '#F1C40F' : base);
  }

  function init(cont) {
    if (typeof L === 'undefined') { C.error(cont, 'La librería de mapas (Leaflet) no está disponible.'); return; }
    var set = cont.getAttribute('data-set') === 'todas' ? 'todas' : 'fews';
    var lienzo = cont.querySelector('.man-mapa__lienzo');
    if (!lienzo) { return; }

    var map = L.map(lienzo, { scrollWheelZoom: false }).setView([1.4, -77.8], 7);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 13, attribution: '© OpenStreetMap' }).addTo(map);

    // Panes para ordenar límites bajo los marcadores (no interceptan clics).
    ['manMuni', 'manSub', 'manDepto'].forEach(function (p, i) {
      map.createPane(p);
      map.getPane(p).style.zIndex = 320 + i * 10;
      map.getPane(p).style.pointerEvents = 'none';
    });

    var overlays = {};
    var pendientes = [];

    // --- Límites: municipios, subregiones, departamento ---
    var geoMun = cont.getAttribute('data-geojson');
    if (geoMun) {
      pendientes.push(C.externo(geoMun).then(function (geo) {
        overlays['Municipios'] = L.geoJSON(geo, { pane: 'manMuni', interactive: false, style: { color: '#9aa0a6', weight: 0.7, opacity: 0.7, fill: false } }).addTo(map);
      }).catch(function () {}));
    }
    var geoSub = cont.getAttribute('data-geojson-sub');
    if (geoSub) {
      pendientes.push(C.externo(geoSub).then(function (geo) {
        overlays['Subregiones'] = L.geoJSON(geo, { pane: 'manSub', interactive: false, style: { color: '#5f6368', weight: 1.6, opacity: 0.9, fill: false } }).addTo(map);
      }).catch(function () {}));
    }
    var geoDep = cont.getAttribute('data-geojson-depto');
    if (geoDep) {
      pendientes.push(C.externo(geoDep).then(function (geo) {
        overlays['Departamento'] = L.geoJSON(geo, { pane: 'manDepto', interactive: false, style: { color: '#1a1f2c', weight: 2.6, opacity: 0.95, fill: false } }).addTo(map);
      }).catch(function () {}));
    }

    // --- Capas de datos ---
    pendientes.push(
      C.rest('/geo', { set: set }).then(function (d) {
        var capas = (d && d.capas) || [];
        var puntos = [];
        capas.forEach(function (capa) {
          var grupo = L.layerGroup();
          (capa.items || []).forEach(function (it) {
            if (it.lat == null || it.lng == null) { return; }
            var radio = capa.tipo === 'foco' ? Math.min(14, 5 + Math.sqrt(+it.valor || 1) * 2) : (capa.tipo === 'mar' ? 8 : 6);
            var alerta = it.alerta || 'normal';
            var m = L.circleMarker([it.lat, it.lng], {
              radius: radio,
              color: alerta === 'normal' ? '#ffffff' : colorAlerta(alerta, capa.color),
              weight: alerta === 'normal' ? 1.2 : 2.4,
              fillColor: capa.color,
              fillOpacity: 0.85
            });
            m.bindPopup(popup(capa, it));
            m.addTo(grupo);
            puntos.push([it.lat, it.lng]);
          });
          grupo.addTo(map);
          overlays[capa.etiqueta] = grupo;
        });
        return puntos;
      }).catch(function () { return []; })
    );

    Promise.all(pendientes).then(function (res) {
      C.quitarSkeleton(cont);
      L.control.layers(null, overlays, { collapsed: false }).addTo(map);
      var puntos = (res && res[res.length - 1]) || [];
      if (puntos && puntos.length) { map.fitBounds(puntos, { padding: [24, 24], maxZoom: 10 }); }
      setTimeout(function () { map.invalidateSize(); }, 250);
    });
  }

  function popup(capa, it) {
    var html = '<strong>' + C.esc(it.nombre || capa.etiqueta) + '</strong>';
    html += '<br><span style="color:' + C.esc(capa.color) + '">' + C.esc(capa.etiqueta) + '</span>';
    if (it.municipio) { html += '<br>' + C.esc(it.municipio); }
    if (it.valor != null) { html += '<br>Valor: ' + C.num(it.valor, 2) + ' ' + C.esc(it.unidad || ''); }
    if (it.alerta && it.alerta !== 'normal') { html += '<br>Alerta: ' + C.esc(it.alerta); }
    return html;
  }
})();
