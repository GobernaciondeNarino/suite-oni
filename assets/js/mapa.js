/* [man_mapa] — coroplético Leaflet de los 64 municipios + panel al clic con
   pronóstico Open-Meteo en vivo. */
(function () {
  'use strict';
  var C = window.MANcore;

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-mapa]'), init);
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-mapa-cuant]'), cuant);
  });

  /* [man_mapa_cuantitativo] — cifras del mapa calculadas en vivo desde /departamento. */
  function cuant(box) {
    var mes = box.getAttribute('data-mes') || '';
    C.rest('/departamento', { mes: mes }).then(function (dep) {
      dep = dep || [];
      var n = dep.length, niveles = { bajo: 0, medio: 0, alto: 0, extremo: 0 }, suma = 0, top = null;
      dep.forEach(function (r) {
        if (niveles[r.nivel] != null) { niveles[r.nivel]++; }
        suma += +r.riesgo || 0;
        if (!top || (+r.riesgo || 0) > (+top.riesgo || 0)) { top = r; }
      });
      var prom = n ? suma / n : 0;
      var altos = niveles.alto + niveles.extremo;
      box.innerHTML = '';
      box.appendChild(C.el('p', 'man-g__analisis-num',
        altos + ' de ' + n + ' municipios en riesgo alto o extremo. Promedio departamental: ' + C.num(prom, 2) +
        ' en una escala de 0 a 1.' + (top ? ' Mayor riesgo: ' + C.esc(top.nombre || top.municipio || '') + ' (' + C.num(top.riesgo, 2) + ').' : '')));
    }).catch(function () {
      box.innerHTML = '<p class="man-mute-line">No se pudieron calcular las cifras del mapa.</p>';
    });
  }

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

        // Demarcación del departamento: contorno (disuelto) de Nariño, encima.
        var deptoUrl = cont.getAttribute('data-geojson-depto');
        if (deptoUrl) {
          C.externo(deptoUrl).then(function (dep) {
            L.geoJSON(dep, { interactive: false, style: { color: '#1a1f2c', weight: 2.5, opacity: 0.95, fill: false } }).addTo(map);
          }).catch(function () { /* contorno opcional */ });
        }
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

      // Gráfico secundario en d3plus: indicadores del municipio.
      if (d.indicadores && typeof d3plus !== 'undefined' && d3plus.BarChart) {
        var ind = d.indicadores;
        var labels = { deficit_hidrico: 'Déficit hídrico', focos_calor: 'Focos de calor', nivel_caudal_pct: 'Caudal (%)', precipitacion_mm: 'Precip. (mm)', area_cultivos_riesgo_pct: 'Cultivos en riesgo (%)' };
        var rows = [];
        Object.keys(labels).forEach(function (k) { if (ind[k] != null && !isNaN(+ind[k])) { rows.push({ ind: labels[k], valor: +ind[k] }); } });
        if (rows.length) {
          var chartDiv = C.el('div', 'man-mapa__chart');
          chartDiv.style.minHeight = '210px';
          p.appendChild(chartDiv);
          try {
            new d3plus.BarChart().select(chartDiv).data(rows).groupBy('ind').x('ind').y('valor')
              .discrete('x').color(function () { return '#0080C3'; })
              .legend(false).detectResize(true)
              .xConfig({ title: 'Indicador del municipio' }).yConfig({ title: 'Valor' })
              .render();
          } catch (e) { /* si d3plus falla, el panel queda con el texto */ }
        }
      }

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
