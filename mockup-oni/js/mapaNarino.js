// =====================================================================
// mapaNarino.js — Capa GeoJSON de municipios de Nariño sobre el globo 3D
// Heat map POR MUNICIPIO basado en predicciones_elnino_narino_2026.json
// (índice de riesgo y nivel de alerta mensual, llave MPIO_CDPMP/DIVIPOLA).
// =====================================================================

import * as THREE from 'three';

const RUTA_GEOJSON = './data/narino_municipios.geojson';

// Paleta oficial del JSON de predicciones (manual Gobernación + IDEAM).
// Sirve como respaldo si predicciones.meta.paleta no llega.
const PALETA_DEFAULT = {
  verde:    '#2E7D32',
  amarillo: '#E8A020',
  naranja:  '#E8731A',
  rojo:     '#C0392B',
};

// Factor de "calor" por nivel — modula opacidad y elevación del polígono.
const FACTOR_NIVEL = { verde: 0.2, amarillo: 0.5, naranja: 0.78, rojo: 1.0 };

// lat/lng (grados) → vector 3D sobre la esfera
function latLngAVector3(lat, lng, radio = 1.012) {
  const phi = (90 - lat) * Math.PI / 180;
  const theta = (lng + 180) * Math.PI / 180;
  return new THREE.Vector3(
    -radio * Math.sin(phi) * Math.cos(theta),
     radio * Math.cos(phi),
     radio * Math.sin(phi) * Math.sin(theta)
  );
}

function anillosAVectores(anillos, radio) {
  return anillos.map((anillo) =>
    anillo.map(([lng, lat]) => latLngAVector3(lat, lng, radio))
  );
}

function featurePoligonos(feature) {
  const g = feature.geometry;
  if (g.type === 'Polygon') return [g.coordinates];
  if (g.type === 'MultiPolygon') return g.coordinates;
  return [];
}

// Triangulación tipo "fan" (sin earcut) — suficiente para municipios
// casi-convexos. La precisión del polígono no es crítica; el color sí.
function triangularFan(vertices2D) {
  if (vertices2D.length < 3) return [];
  const idx = [];
  for (let i = 1; i < vertices2D.length - 1; i++) {
    idx.push(0, i, i + 1);
  }
  return idx;
}

// Mezcla continua de la paleta según índice 0..1, replicando los umbrales
// del JSON de predicciones (verde<0.35, amarillo<0.55, naranja<0.7, rojo).
function colorPorIndice(indice, paletaHex) {
  const v = Math.max(0, Math.min(1, indice ?? 0));
  const cVerde    = new THREE.Color(paletaHex.verde);
  const cAmarillo = new THREE.Color(paletaHex.amarillo);
  const cNaranja  = new THREE.Color(paletaHex.naranja);
  const cRojo     = new THREE.Color(paletaHex.rojo);
  if (v < 0.35) return new THREE.Color().lerpColors(cVerde, cAmarillo, v / 0.35);
  if (v < 0.55) return new THREE.Color().lerpColors(cAmarillo, cNaranja, (v - 0.35) / 0.20);
  if (v < 0.75) return new THREE.Color().lerpColors(cNaranja, cRojo, (v - 0.55) / 0.20);
  return cRojo;
}

export class MapaNarino {
  /**
   * @param {Object} globo       — instancia de GloboElNino
   * @param {Object} datos       — JSON principal (datos_globo_elnino_narino_2026)
   * @param {Object} predicciones — JSON por municipio (predicciones_elnino_narino_2026)
   */
  constructor(globo, datos, predicciones) {
    this.globo = globo;
    this.datos = datos;
    this.predicciones = predicciones || null;
    this.paleta = (predicciones?.meta?.paleta) || PALETA_DEFAULT;

    // Índice por DIVIPOLA → ficha completa del municipio en predicciones
    this.indicePredicciones = {};
    if (this.predicciones?.municipios) {
      Object.entries(this.predicciones.municipios).forEach(([cod, m]) => {
        this.indicePredicciones[cod] = m;
      });
    }

    this.grupo = new THREE.Group();
    this.grupo.name = 'mapa-narino';
    this.municipios = []; // {divipola, nombre, ficha, grupo, material}
    this.visible = false;
    this.indiceMes = 0;

    // Tooltip DOM
    this.tooltip = document.getElementById('tooltipMunicipio');
    this.raycaster = new THREE.Raycaster();
    this.mouse = new THREE.Vector2();

    this.globo.escena.add(this.grupo);
    this.grupo.visible = false;

    this._cargar();
    this._registrarHover();
  }

  async _cargar() {
    try {
      const r = await fetch(RUTA_GEOJSON, { cache: 'no-cache' });
      if (!r.ok) throw new Error(`GeoJSON HTTP ${r.status}`);
      const gj = await r.json();
      this._construirPoligonos(gj);
      const conPred = this.municipios.filter((m) => m.ficha).length;
      console.log(
        `[MapaNariño] ${this.municipios.length} municipios cargados; ` +
        `${conPred} con predicción mensual.`
      );
      // Aplica el mes actual una vez los polígonos están listos
      this.actualizarMes(this.indiceMes);
    } catch (e) {
      console.warn('[MapaNariño] No se pudo cargar el GeoJSON:', e.message);
    }
  }

  _construirPoligonos(geojson) {
    const features = geojson.features || [];
    features.forEach((feat) => {
      const props = feat.properties || {};
      const divipola = String(props.MPIO_CDPMP || '');
      const nombre = props.MPIO_CNMBR || 'Municipio';
      const ficha = this.indicePredicciones[divipola] || null;

      const poligonos = featurePoligonos(feat);

      // Material por municipio (color editable por mes)
      const matRelleno = new THREE.MeshBasicMaterial({
        color: 0x2e7d32,
        transparent: true,
        opacity: 0.55,
        side: THREE.DoubleSide,
        depthWrite: false,
      });
      const matBorde = new THREE.LineBasicMaterial({
        color: 0xffffff,
        transparent: true,
        opacity: 0.55,
      });

      const grupoMun = new THREE.Group();
      grupoMun.userData = { divipola, nombre };

      poligonos.forEach((anillos) => {
        const anillosV = anillosAVectores(anillos, 1.012);
        const exterior = anillosV[0];
        if (!exterior || exterior.length < 3) return;

        // RELLENO
        const idx = triangularFan(exterior);
        const posiciones = new Float32Array(exterior.length * 3);
        exterior.forEach((v, i) => {
          posiciones[i*3] = v.x; posiciones[i*3+1] = v.y; posiciones[i*3+2] = v.z;
        });
        const geoRell = new THREE.BufferGeometry();
        geoRell.setAttribute('position', new THREE.BufferAttribute(posiciones, 3));
        geoRell.setIndex(idx);
        geoRell.computeVertexNormals();
        const mesh = new THREE.Mesh(geoRell, matRelleno);
        grupoMun.add(mesh);

        // BORDE
        const geoBor = new THREE.BufferGeometry().setFromPoints([...exterior, exterior[0]]);
        const linea = new THREE.Line(geoBor, matBorde);
        grupoMun.add(linea);
      });

      this.grupo.add(grupoMun);
      this.municipios.push({
        divipola,
        nombre,
        ficha,
        grupo: grupoMun,
        material: matRelleno,
        materialBorde: matBorde,
      });
    });
  }

  /**
   * Actualiza el coloreado de TODOS los municipios para un índice de mes
   * (0..11). Lee `serie_mensual[indice]` de cada ficha de predicciones.
   * Compatible con la antigua firma `(mesNarino)`.
   */
  actualizarMes(indiceOMes, mesNarinoOpcional) {
    // Resolver índice de mes a partir de los argumentos recibidos
    let indice = 0;
    if (typeof indiceOMes === 'number') {
      indice = indiceOMes;
    } else if (indiceOMes && typeof indiceOMes === 'object') {
      // Llamado antiguo: pasaba el objeto narino.meses[i] — derivamos índice por "mes"
      const m = indiceOMes.mes;
      if (typeof m === 'string') {
        const num = parseInt(m.split('-')[1], 10);
        if (!isNaN(num)) indice = Math.max(0, Math.min(11, num - 1));
      }
    }
    this.indiceMes = indice;
    this.estadoMes = mesNarinoOpcional || null;

    if (!this.municipios.length) return;

    this.municipios.forEach((m) => {
      // Si tenemos predicción por municipio → usar índice real
      if (m.ficha?.serie_mensual?.[indice]) {
        const reg = m.ficha.serie_mensual[indice];
        const color = colorPorIndice(reg.indice_riesgo, this.paleta);
        m.material.color.copy(color);
        const factor = FACTOR_NIVEL[reg.nivel] ?? 0.4;
        m.material.opacity = 0.42 + factor * 0.5; // 0.52 → 0.92
        // Borde levemente teñido del mismo tono
        m.materialBorde.color.copy(color).offsetHSL(0, 0, 0.18);
        m.materialBorde.opacity = 0.55 + factor * 0.25;
      } else {
        // Respaldo: nivel general del mes (verde por defecto)
        const nivel = mesNarinoOpcional?.nivel_alerta_general || 'verde';
        const color = new THREE.Color(this.paleta[nivel] || this.paleta.verde);
        m.material.color.copy(color);
        m.material.opacity = 0.4 + (FACTOR_NIVEL[nivel] ?? 0.2) * 0.4;
      }
    });
  }

  setVisible(v) {
    this.visible = v;
    this.grupo.visible = v;
  }

  _registrarHover() {
    const dom = this.globo.renderer.domElement;
    // Cache de meshes para no rearmar el array en cada pointermove.
    this._cacheMeshes = null;
    const obtenerMeshes = () => {
      if (this._cacheMeshes) return this._cacheMeshes;
      const meshes = [];
      this.municipios.forEach((m) => {
        m.grupo.children.forEach((c) => { if (c.isMesh) meshes.push(c); });
      });
      this._cacheMeshes = meshes;
      return meshes;
    };

    const onMove = (e) => {
      if (!this.visible || !this.municipios.length) return;
      const rect = dom.getBoundingClientRect();
      this.mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
      this.mouse.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;
      this.raycaster.setFromCamera(this.mouse, this.globo.camara);

      const hits = this.raycaster.intersectObjects(obtenerMeshes(), false);
      if (hits.length) {
        const mesh = hits[0].object;
        const mun = this.municipios.find((m) => m.grupo.children.includes(mesh));
        if (mun) {
          this._mostrarTooltip(e, mun);
          dom.style.cursor = 'pointer';
          return;
        }
      }
      this._ocultarTooltip();
      dom.style.cursor = '';
    };

    // Soportamos pointermove y mousemove para máxima compatibilidad
    dom.addEventListener('pointermove', onMove);
    dom.addEventListener('mousemove', onMove);
    dom.addEventListener('pointerleave', () => {
      this._ocultarTooltip();
      dom.style.cursor = '';
    });
  }

  _mostrarTooltip(evento, municipio) {
    if (!this.tooltip) return;
    const rect = this.globo.contenedor.getBoundingClientRect();
    let x = evento.clientX - rect.left;
    let y = evento.clientY - rect.top;

    // Nombre del municipio normalizado (capitalización legible)
    const nombre = String(municipio.nombre || '').replace(
      /\w\S*/g,
      (t) => t.charAt(0).toUpperCase() + t.slice(1).toLowerCase()
    );

    let html = `<strong>${nombre}</strong>`;

    const reg = municipio.ficha?.serie_mensual?.[this.indiceMes];
    if (reg) {
      const nivel = (reg.nivel || '').toUpperCase();
      const idx = (reg.indice_riesgo * 100).toFixed(0);
      const ind = reg.ind || {};
      html += `<span class="tt-nivel tt-nivel--${reg.nivel}">${nivel}</span>`;
      html += `<span class="tt-linea">Índice de riesgo: <b>${idx}/100</b></span>`;
      html += `<span class="tt-linea">Déficit hídrico: <b>${ind.deficit_hidrico ?? '—'}/100</b></span>`;
      html += `<span class="tt-linea">Focos de calor: <b>${ind.focos_calor ?? '—'}</b></span>`;
      html += `<span class="tt-linea">Cultivos en riesgo: <b>${ind.area_cultivos_riesgo_pct ?? '—'}%</b></span>`;
      if (municipio.ficha?.regimen) {
        html += `<span class="tt-meta">Régimen: ${municipio.ficha.regimen} · DIVIPOLA ${municipio.divipola}</span>`;
      }
    } else {
      const nivel = this.estadoMes?.nivel_alerta_general || 'sin dato';
      html += `<span class="tt-linea">Alerta general: <em>${nivel}</em></span>`;
      html += `<span class="tt-meta">DIVIPOLA ${municipio.divipola}</span>`;
    }

    this.tooltip.innerHTML = html;
    this.tooltip.hidden = false;

    // Mantenemos el tooltip dentro del lienzo: si se sale por la derecha o
    // por arriba, lo "anclamos" al lado contrario del cursor.
    // El tooltip tiene transform: translate(-50%, calc(-100% - 10px))
    const tipoAncho = this.tooltip.offsetWidth || 220;
    const tipoAlto  = this.tooltip.offsetHeight || 110;
    const margen = 8;
    // Anchor horizontal — clamp
    const mitad = tipoAncho / 2;
    if (x - mitad < margen) x = mitad + margen;
    if (x + mitad > rect.width - margen) x = rect.width - mitad - margen;
    // Si está muy arriba, mostramos debajo del cursor en vez de arriba
    if (y - tipoAlto - 12 < 0) {
      this.tooltip.style.transform = 'translate(-50%, 14px)';
    } else {
      this.tooltip.style.transform = 'translate(-50%, calc(-100% - 10px))';
    }

    this.tooltip.style.left = `${x}px`;
    this.tooltip.style.top = `${y}px`;
  }

  _ocultarTooltip() {
    if (this.tooltip) this.tooltip.hidden = true;
  }
}
