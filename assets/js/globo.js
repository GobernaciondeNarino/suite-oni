/* [man_globo] — Globo 3D cinematográfico del fenómeno ENSO (Three.js 0.160, módulo ES).
   Porta y mejora las técnicas del mockup: textura Blue Marble con cascada de CDN
   y fallback procedimental, atmósfera fresnel, anomalía Niño-3.4 (shader pulsante
   modulado por ONI), alisios que se debilitan, mapa de calor con deriva O→E,
   nubes que se disipan al este, teleconexión Pacífico→Nariño, marcador con halo y
   ondas, y estrellas. Interpola el ONI suavemente y se SINCRONIZA con la línea de
   tiempo vía el evento 'man:mes'. Modo ligero + pausa fuera de viewport. */
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

var CFG = window.MANGLOBO || { rest: '', calidad: 'auto', autorotar: true, textura: '', mesActual: '' };

var RUTAS_TEXTURA = [
  CFG.textura || 'https://unpkg.com/three-globe@2.31.1/example/img/earth-blue-marble.jpg',
  'https://cdn.jsdelivr.net/npm/three-globe@2.31.1/example/img/earth-blue-marble.jpg'
];

var COLORES_ALERTA = { verde: 0x2e7d32, amarillo: 0xf9a825, naranja: 0xef6c00, rojo: 0xc62828 };

function latLngAVector3(lat, lng, radio) {
  radio = radio || 1;
  var phi = (90 - lat) * Math.PI / 180;
  var theta = (lng + 180) * Math.PI / 180;
  return new THREE.Vector3(
    -radio * Math.sin(phi) * Math.cos(theta),
    radio * Math.cos(phi),
    radio * Math.sin(phi) * Math.sin(theta)
  );
}

/* Interpolación de color por ONI: azul neutro → dorado → naranja → rojo.
   Reusa un color "scratch" (sin asignar objetos por fotograma → apto para miles
   de partículas). Los llamadores leen/copian el resultado de inmediato. */
var _oc1 = new THREE.Color(0x2196f3), _oc2 = new THREE.Color(0xffeb3b),
  _oc3 = new THREE.Color(0xff9800), _oc4 = new THREE.Color(0xd32f2f),
  _ocScratch = new THREE.Color();
function colorPorOni(oni) {
  var v = Math.max(0, Math.min(2, oni));
  if (v < 0.5) { return _ocScratch.lerpColors(_oc1, _oc2, v / 0.5); }
  if (v < 1.0) { return _ocScratch.lerpColors(_oc2, _oc3, (v - 0.5) / 0.5); }
  return _ocScratch.lerpColors(_oc3, _oc4, Math.min(1, (v - 1.0) / 1.0));
}

/* Longitud aproximada de la costa pacífica americana por latitud (México →
   Chile), unos grados mar adentro para no pintar sobre el continente. */
function coastLng(lat) {
  if (lat >= 8) { return -86 - (lat - 8) * 1.9; }       // Centroamérica → México
  if (lat <= -14) { return -80 + (lat + 14) * 0.05; }   // Perú → Chile
  return -82;                                            // franja ecuatorial
}

function nivelPorOni(oni) {
  var a = Math.abs(oni);
  if (a >= 1.5) { return 'rojo'; }
  if (a >= 1.0) { return 'naranja'; }
  if (a >= 0.5) { return 'amarillo'; }
  return 'verde';
}
function faseNombre(oni) { return oni >= 0.5 ? 'El Niño' : (oni <= -0.5 ? 'La Niña' : 'Neutral'); }

/* Helpers GeoJSON → polígonos sobre la esfera (capa de municipios de Nariño). */
function featurePoligonos(feat) {
  var g = feat.geometry || {};
  if (g.type === 'Polygon') { return [g.coordinates]; }
  if (g.type === 'MultiPolygon') { return g.coordinates; }
  return [];
}
function anillosAVectores(anillos, radio) {
  return anillos.map(function (anillo) {
    return anillo.map(function (par) { return latLngAVector3(par[1], par[0], radio); });
  });
}
function triangularFan(verts) {
  var idx = [];
  for (var i = 1; i < verts.length - 1; i++) { idx.push(0, i, i + 1); }
  return idx;
}
function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}
function titulo(s) {
  return String(s || '').toLowerCase().replace(/\b\w/g, function (c) { return c.toUpperCase(); });
}

/* Textura de punto suave (degradado radial) → partículas redondas, no cuadradas
   (estilo "particle-love"). Se reutiliza en todas las capas de partículas. */
var _texPunto = null;
function texturaPunto() {
  if (_texPunto) { return _texPunto; }
  var c = document.createElement('canvas');
  c.width = 64; c.height = 64;
  var ctx = c.getContext('2d');
  var g = ctx.createRadialGradient(32, 32, 0, 32, 32, 32);
  g.addColorStop(0, 'rgba(255,255,255,1)');
  g.addColorStop(0.35, 'rgba(255,255,255,0.55)');
  g.addColorStop(1, 'rgba(255,255,255,0)');
  ctx.fillStyle = g;
  ctx.beginPath(); ctx.arc(32, 32, 32, 0, Math.PI * 2); ctx.fill();
  _texPunto = new THREE.CanvasTexture(c);
  return _texPunto;
}

/* Textura de NUBE esponjosa: varios lóbulos suaves superpuestos con base plana,
   borde difuminado y fondo transparente → parece una nube real (no una bola). */
var _texNube = null;
function texturaNube() {
  if (_texNube) { return _texNube; }
  var c = document.createElement('canvas');
  c.width = 160; c.height = 96;
  var ctx = c.getContext('2d');
  // Lóbulos [x, y, r] que forman el cúmulo (más altos en el centro, base plana).
  var lobulos = [
    [48, 60, 30], [80, 48, 36], [112, 60, 30],
    [64, 64, 26], [96, 64, 26], [80, 58, 40]
  ];
  lobulos.forEach(function (b) {
    var g = ctx.createRadialGradient(b[0], b[1], 0, b[0], b[1], b[2]);
    g.addColorStop(0, 'rgba(255,255,255,0.96)');
    g.addColorStop(0.45, 'rgba(244,248,252,0.6)');
    g.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = g;
    ctx.beginPath(); ctx.arc(b[0], b[1], b[2], 0, Math.PI * 2); ctx.fill();
  });
  _texNube = new THREE.CanvasTexture(c);
  return _texNube;
}

/* Mapea lat/lng a una "zona" del fenómeno para el tooltip educativo. */
function zonaFenomeno(lat, lng) {
  if (lat >= -14 && lat <= 14 && lng >= -180 && lng <= -78) {
    if (lng <= -150) {
      return { titulo: 'Pacífico occidental', lineas: [
        'Nubes y convección (lluvias): en El Niño se desplazan hacia el este.',
        'Vientos alisios: soplan de este a oeste a lo largo del ecuador.'
      ] };
    }
    if (lng >= -92 && lat >= -12 && lat <= 6) {
      return { titulo: 'Pacífico oriental — costa', lineas: [
        'Calentamiento del mar frente a Sudamérica; se reduce el afloramiento frío.'
      ] };
    }
    return { titulo: 'Lengua cálida del Pacífico (Niño-3.4)', lineas: [
      'Anomalía de temperatura del mar (SST): el corazón de El Niño.',
      'Los vientos alisios se debilitan y el agua cálida se desplaza al este.'
    ] };
  }
  return null;
}

function esLigero() {
  return CFG.calidad === 'baja' ||
    (CFG.calidad === 'auto' && ((window.devicePixelRatio || 1) > 2 || (navigator.hardwareConcurrency || 4) <= 4));
}

class GloboMAN {
  constructor(cont) {
    this.cont = cont;
    this.lienzo = cont.querySelector('.man-globo__lienzo') || cont;
    this.cMes = cont.querySelector('.man-globo__cintillo-mes');
    this.cOni = cont.querySelector('.man-globo__cintillo-oni');
    this.cProb = cont.querySelector('.man-globo__cintillo-prob');
    this.cResumen = cont.querySelector('.man-globo__cintillo-resumen');
    this.ligero = esLigero();
    this.reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    this.visible = true;

    this.estado = {
      oni: 0, oniObjetivo: 0, nivel: 'verde', mes: '',
      colorMarc: new THREE.Color(COLORES_ALERTA.verde),
      colorMarcObj: new THREE.Color(COLORES_ALERTA.verde)
    };
    this._focoObjetivo = 0;
    this._focoOpacidad = 0;
    this._focoBloqueado = false;
    this._mapaMuns = [];
    this._mapaMeshes = [];

    this._escena();
    this._globo();
    this._anomalia();
    this._alisios();
    this._particulasAlisios();
    this._heat();
    this._focoCalor();
    this._nubes();
    this._teleconexion();
    this._teleconexionesGlobales();
    this._marcador();
    this._ondas();
    this._estrellas();
    this._mapaNarino();
    this._hover();
    this._controles();
    this._eventos();
    this._cargaInicial();
    this._quitarSkeleton();
    this._loop();
  }

  _ancho() { return this.lienzo.clientWidth || 480; }
  _alto() { return Math.max(340, Math.round((this.lienzo.clientWidth || 480) * 0.62)); }

  /* ---------------- escena base ---------------- */
  _escena() {
    this.escena = new THREE.Scene();
    this.escena.background = new THREE.Color(0x000814);

    this.camara = new THREE.PerspectiveCamera(45, this._ancho() / this._alto(), 0.1, 100);
    this.camara.position.set(0, 1.2, 4.2);
    // Cámaras predefinidas (vista global / mecanismo Pacífico / impacto Nariño).
    this.camDefault = new THREE.Vector3(0, 1.2, 4.2);
    this.camMecanismo = new THREE.Vector3(-3.5, 0.6, -2.2);
    this.camLocal = new THREE.Vector3(0.34, 0.35, 1.55);
    this._camTransicion = null;

    this.renderer = new THREE.WebGLRenderer({ antialias: !this.ligero, alpha: false });
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, this.ligero ? 1 : 2));
    this.renderer.setSize(this._ancho(), this._alto());
    this.lienzo.appendChild(this.renderer.domElement);

    this.escena.add(new THREE.AmbientLight(0xffffff, 0.33));
    this.escena.add(new THREE.HemisphereLight(0xbfd4ff, 0x14202e, 0.32));
    var sol = new THREE.DirectionalLight(0xffffff, 1.3);
    sol.position.set(5, 3, 5);
    this.escena.add(sol);

    this.controles = new OrbitControls(this.camara, this.renderer.domElement);
    this.controles.enableDamping = true;
    this.controles.dampingFactor = 0.07;
    this.controles.rotateSpeed = 0.55;
    this.controles.zoomSpeed = 1.2;
    this.controles.enablePan = false;
    this.controles.minDistance = 1.3;
    this.controles.maxDistance = 12;
    this.controles.autoRotate = !!CFG.autorotar && !this.reduced;
    this.controles.autoRotateSpeed = 0.25;
    this.controles.addEventListener('start', () => { this.controles.autoRotate = false; });
  }

  /* ---------------- tierra + atmósfera ---------------- */
  _globo() {
    var seg = this.ligero ? 48 : 64;
    this.matGlobo = new THREE.MeshPhongMaterial({ color: 0x1A5276, specular: 0x202a40, shininess: 15, emissive: 0x000000 });
    this.globo = new THREE.Mesh(new THREE.SphereGeometry(1, seg, seg), this.matGlobo);
    this.escena.add(this.globo);
    this._cargarTextura();   // mapa de día (Blue Marble, cascada).
    this._capasTextura();    // especular (océano), relieve y luces nocturnas.
    this._capaNubes();       // capa de nubes en rotación.

    // Atmósfera fresnel (rim glow azulado).
    var atmMat = new THREE.ShaderMaterial({
      transparent: true, side: THREE.BackSide, blending: THREE.AdditiveBlending, depthWrite: false,
      vertexShader: 'varying vec3 vN; void main(){ vN = normalize(normalMatrix * normal); gl_Position = projectionMatrix * modelViewMatrix * vec4(position,1.0); }',
      fragmentShader: 'varying vec3 vN; void main(){ float i = pow(0.72 - dot(vN, vec3(0.0,0.0,1.0)), 2.0); gl_FragColor = vec4(0.30,0.55,1.0,1.0) * i; }'
    });
    this.escena.add(new THREE.Mesh(new THREE.SphereGeometry(1.14, 48, 48), atmMat));
  }

  _cargarTextura() {
    var self = this;
    var loader = new THREE.TextureLoader();
    loader.crossOrigin = 'anonymous';
    var resuelto = false;
    var aplicar = function (tex) {
      if ('SRGBColorSpace' in THREE) { tex.colorSpace = THREE.SRGBColorSpace; }
      self.matGlobo.map = tex; self.matGlobo.color = new THREE.Color(0xffffff); self.matGlobo.needsUpdate = true;
      resuelto = true;
    };
    setTimeout(function () { if (!resuelto) { self._continentesFallback(); resuelto = true; } }, 5000);
    var intentar = function (i) {
      if (i >= RUTAS_TEXTURA.length) { if (!resuelto) { self._continentesFallback(); resuelto = true; } return; }
      loader.load(RUTAS_TEXTURA[i], aplicar, undefined, function () { intentar(i + 1); });
    };
    intentar(0);
  }

  _continentesFallback() {
    var c = document.createElement('canvas'); c.width = 2048; c.height = 1024;
    var ctx = c.getContext('2d');
    ctx.fillStyle = '#1A5276'; ctx.fillRect(0, 0, c.width, c.height);
    ctx.fillStyle = '#3d8b6e';
    [[380, 150, 320, 380], [550, 480, 200, 380], [900, 180, 280, 220], [950, 380, 340, 380], [1180, 180, 540, 380], [1620, 580, 180, 130]]
      .forEach(function (r) { ctx.beginPath(); ctx.ellipse(r[0] + r[2] / 2, r[1] + r[3] / 2, r[2] / 2, r[3] / 2, 0, 0, Math.PI * 2); ctx.fill(); });
    var t = new THREE.CanvasTexture(c);
    if ('SRGBColorSpace' in THREE) { t.colorSpace = THREE.SRGBColorSpace; }
    this.matGlobo.map = t; this.matGlobo.color = new THREE.Color(0xffffff); this.matGlobo.needsUpdate = true;
  }

  /* Capas que dan aspecto realista (inspirado en el ejemplo TSL Earth):
     brillo especular del océano, relieve y luces nocturnas en el lado oscuro.
     Texturas con CORS (unpkg/three-globe); cada una falla en silencio. */
  _capasTextura() {
    if (this.ligero) { return; }
    var self = this;
    var loader = new THREE.TextureLoader();
    loader.crossOrigin = 'anonymous';
    var base = 'https://unpkg.com/three-globe/example/img/';
    var carga = function (url, cb) { loader.load(url, cb, undefined, function () { /* sin esa capa */ }); };

    // Especular: el agua refleja la luz del sol (los océanos brillan).
    carga(base + 'earth-water.png', function (t) {
      self.matGlobo.specularMap = t;
      self.matGlobo.specular = new THREE.Color(0x6b7a90);
      self.matGlobo.shininess = 22;
      self.matGlobo.needsUpdate = true;
    });
    // Relieve sutil (montañas/topografía).
    carga(base + 'earth-topology.png', function (t) {
      self.matGlobo.bumpMap = t;
      self.matGlobo.bumpScale = 0.025;
      self.matGlobo.needsUpdate = true;
    });
    // Luces nocturnas: se ven en el lado oscuro (el día las lava por el brillo).
    carga(base + 'earth-night.jpg', function (t) {
      if ('SRGBColorSpace' in THREE) { t.colorSpace = THREE.SRGBColorSpace; }
      self.matGlobo.emissiveMap = t;
      self.matGlobo.emissive = new THREE.Color(0xffe7b3);
      if ('emissiveIntensity' in self.matGlobo) { self.matGlobo.emissiveIntensity = 0.45; }
      self.matGlobo.needsUpdate = true;
    });
  }

  /* Capa de nubes semitransparente en rotación lenta. */
  _capaNubes() {
    if (this.ligero) { return; }
    var self = this;
    var loader = new THREE.TextureLoader();
    loader.crossOrigin = 'anonymous';
    loader.load('https://unpkg.com/three-globe/example/img/clouds/clouds.png', function (t) {
      var m = new THREE.MeshPhongMaterial({ map: t, transparent: true, opacity: 0.38, depthWrite: false });
      self.capaNubes = new THREE.Mesh(new THREE.SphereGeometry(1.012, 48, 48), m);
      self.globo.add(self.capaNubes);
    }, undefined, function () { /* sin nubes */ });
  }

  /* ---------------- anomalía Niño-3.4 (shader) ---------------- */
  _anomalia() {
    var centro = latLngAVector3(0, -145, 1.005);
    this.uAnom = { uColor: { value: new THREE.Color(0x2196f3) }, uIntensidad: { value: 0 }, uTiempo: { value: 0 } };
    var mat = new THREE.ShaderMaterial({
      uniforms: this.uAnom, transparent: true, depthWrite: false,
      blending: THREE.AdditiveBlending, side: THREE.DoubleSide,
      vertexShader: 'varying vec2 vUv; void main(){ vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position,1.0); }',
      fragmentShader: 'uniform vec3 uColor; uniform float uIntensidad; uniform float uTiempo; varying vec2 vUv; void main(){ vec2 p = vUv - 0.5; float r = length(p)*2.0; if(r>1.0) discard; float g = pow(1.0-r,1.8); float pulso = 0.85 + 0.15*sin(uTiempo*1.8); gl_FragColor = vec4(uColor, g*uIntensidad*pulso); }'
    });
    this.anomalia = new THREE.Mesh(new THREE.CircleGeometry(0.55, 64), mat);
    this.anomalia.position.copy(centro);
    this.anomalia.lookAt(centro.clone().add(centro.clone().normalize()));
    this.escena.add(this.anomalia);
  }

  /* ---------------- alisios (flechas E→O) ---------------- */
  _alisios() {
    this.alisios = new THREE.Group();
    this.flechas = [];
    this.escena.add(this.alisios);
    var lngs = [-180, -160, -140, -120, -100, 160];
    var lats = [-4, 4];
    var self = this;
    lngs.forEach(function (lng) {
      lats.forEach(function (lat) {
        var mat = new THREE.MeshBasicMaterial({ color: 0xdfeaf5, transparent: true, opacity: 0.5 });
        var flecha = self._flecha(mat);
        var pos = latLngAVector3(lat, lng, 1.05);
        flecha.position.copy(pos);
        // Orienta la flecha tangente al globo, apuntando al oeste (lng decreciente).
        var oeste = latLngAVector3(lat, lng - 6, 1.05);
        flecha.lookAt(oeste);
        flecha.userData = { escalaBase: 0.085 };
        flecha.scale.setScalar(0.085);
        self.alisios.add(flecha);
        self.flechas.push(flecha);
      });
    });
  }

  _flecha(material) {
    var g = new THREE.Group();
    var cuerpo = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.06, 0.6, 8), material);
    cuerpo.rotation.x = Math.PI / 2; cuerpo.position.z = -0.05; g.add(cuerpo);
    var punta = new THREE.Mesh(new THREE.ConeGeometry(0.18, 0.35, 10), material);
    punta.rotation.x = -Math.PI / 2; punta.position.z = -0.42; g.add(punta);
    return g;
  }

  /* ---------------- mapa de calor Niño-3.4 ---------------- */
  _heat() {
    var n = this.ligero ? 620 : 1500;
    this._heatPts = [];
    var pos = new Float32Array(n * 3), col = new Float32Array(n * 3);
    for (var i = 0; i < n; i++) {
      // Pacífico ecuatorial + franja costera oriental (México → Chile), y se
      // extiende al oeste hasta cerca de Australia para desvanecerse sin corte.
      var lat = -42 + Math.random() * 70;
      var lng = -230 + Math.random() * (coastLng(lat) + 230);
      var p = { lat: lat, latBase: lat, lng: lng, radio: 1.012, velocidadBase: 0.5 + Math.random(), ruido: Math.random(), fase: Math.random() * 6.28 };
      this._heatPts.push(p);
      var v = latLngAVector3(lat, lng, p.radio);
      pos[i * 3] = v.x; pos[i * 3 + 1] = v.y; pos[i * 3 + 2] = v.z;
      col[i * 3] = 0.13; col[i * 3 + 1] = 0.59; col[i * 3 + 2] = 0.95;
    }
    var geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    geo.setAttribute('color', new THREE.BufferAttribute(col, 3));
    this.heat = new THREE.Points(geo, new THREE.PointsMaterial({ size: 0.05, map: texturaPunto(), vertexColors: true, transparent: true, opacity: 0.34, depthWrite: false, blending: THREE.AdditiveBlending, sizeAttenuation: true }));
    this.escena.add(this.heat);
  }

  /* ---------------- nubes (Pacífico occidental) ---------------- */
  _nubes() {
    this._nubesArr = [];
    this.nubes = new THREE.Group();
    var cant = this.ligero ? 8 : 12;
    var tex = texturaNube();
    for (var i = 0; i < cant; i++) {
      var lat = -4 + Math.random() * 8;
      var lng = -165 + Math.random() * 30;
      // Sprite (billboard) con textura de nube → parece una nube real desde cualquier ángulo.
      var s = new THREE.Sprite(new THREE.SpriteMaterial({ map: tex, transparent: true, opacity: 0.5, depthWrite: false }));
      var esc = 0.11 + Math.random() * 0.07;
      s.scale.set(esc * 1.7, esc, 1);
      s.position.copy(latLngAVector3(lat, lng, 1.06));
      s.userData = { lat: lat, lngBase: lng, lngActual: lng };
      this.nubes.add(s);
      this._nubesArr.push(s);
    }
    this.escena.add(this.nubes);
  }

  /* ---------------- teleconexión Pacífico → Nariño ---------------- */
  _teleconexion() {
    var ini = latLngAVector3(0, -145, 1.02);
    var fin = latLngAVector3(1.2, -77.3, 1.02);
    var medio = ini.clone().add(fin).multiplyScalar(0.5).normalize().multiplyScalar(1.55);
    var curva = new THREE.QuadraticBezierCurve3(ini, medio, fin);

    this.tele = new THREE.Line(
      new THREE.BufferGeometry().setFromPoints(curva.getPoints(80)),
      new THREE.LineBasicMaterial({ color: 0xE8A020, transparent: true, opacity: 0 })
    );
    this.escena.add(this.tele);

    var nf = 30, pf = new Float32Array(nf * 3);
    for (var i = 0; i < nf; i++) { var p = curva.getPoint(i / nf); pf[i * 3] = p.x; pf[i * 3 + 1] = p.y; pf[i * 3 + 2] = p.z; }
    var geoF = new THREE.BufferGeometry();
    geoF.setAttribute('position', new THREE.BufferAttribute(pf, 3));
    this.flujo = new THREE.Points(geoF, new THREE.PointsMaterial({ size: 0.04, color: 0xE8A020, transparent: true, opacity: 0, depthWrite: false, sizeAttenuation: true }));
    this.flujo.userData = { curva: curva, fases: new Float32Array(nf) };
    for (var j = 0; j < nf; j++) { this.flujo.userData.fases[j] = j / nf; }
    this.escena.add(this.flujo);
  }

  /* Teleconexiones GLOBALES: el fenómeno no afecta solo a Nariño. Arcos suaves
     desde el Pacífico ecuatorial a regiones canónicamente impactadas por El
     Niño; aparecen y se intensifican con el ONI. */
  _teleconexionesGlobales() {
    this.teleGlobal = [];
    var origen = latLngAVector3(0, -145, 1.02);
    // [lat, lng] de impactos típicos: Perú, sur de EE.UU., Cuerno de África,
    // Australia/Indonesia, sur de Asia.
    var destinos = [[-8, -79], [33, -112], [3, 40], [-22, 134], [20, 78]];
    var self = this;
    destinos.forEach(function (d) {
      var fin   = latLngAVector3(d[0], d[1], 1.02);
      var medio = origen.clone().add(fin).multiplyScalar(0.5).normalize().multiplyScalar(1.5);
      var curva = new THREE.QuadraticBezierCurve3(origen, medio, fin);
      var line  = new THREE.Line(
        new THREE.BufferGeometry().setFromPoints(curva.getPoints(60)),
        new THREE.LineBasicMaterial({ color: 0xE8A020, transparent: true, opacity: 0 })
      );
      self.escena.add(line);
      self.teleGlobal.push(line);
      var marc = new THREE.Sprite(new THREE.SpriteMaterial({ map: texturaPunto(), color: 0xffb347, transparent: true, opacity: 0, depthWrite: false, blending: THREE.AdditiveBlending }));
      marc.position.copy(fin);
      marc.scale.set(0.12, 0.12, 0.12);
      self.escena.add(marc);
      self.teleGlobal.push(marc);
    });
  }

  /* ---------------- marcador de Nariño ---------------- */
  _marcador() {
    var pos = latLngAVector3(1.2, -77.3, 1.0);
    this.matMarc = new THREE.MeshStandardMaterial({ color: COLORES_ALERTA.verde, emissive: 0x000000, transparent: true, opacity: 0.9, depthWrite: false });
    this.marc = new THREE.Group();
    var esfera = new THREE.Mesh(new THREE.SphereGeometry(0.022, 16, 16), this.matMarc);
    esfera.position.copy(pos.clone().multiplyScalar(1.05));
    this.marc.add(esfera);
    var asta = new THREE.Mesh(new THREE.CylinderGeometry(0.004, 0.004, 0.07, 8), this.matMarc);
    asta.position.copy(pos.clone().multiplyScalar(1.025));
    asta.lookAt(pos.clone().multiplyScalar(2)); asta.rotateX(Math.PI / 2);
    this.marc.add(asta);
    this.halo = new THREE.Mesh(
      new THREE.RingGeometry(0.04, 0.08, 32),
      new THREE.MeshBasicMaterial({ color: COLORES_ALERTA.verde, transparent: true, opacity: 0, side: THREE.DoubleSide, depthWrite: false })
    );
    this.halo.position.copy(pos.clone().multiplyScalar(1.05));
    this.halo.lookAt(pos.clone().multiplyScalar(2));
    this.marc.add(this.halo);
    this.escena.add(this.marc);
  }

  /* ---------------- ondas concéntricas en Nariño ---------------- */
  _ondas() {
    this.ondas = [];
    var pos = latLngAVector3(1.2, -77.3, 1.0);
    for (var i = 0; i < 3; i++) {
      var malla = new THREE.Mesh(
        new THREE.RingGeometry(0.04, 0.045, 48),
        new THREE.MeshBasicMaterial({ color: 0xc62828, transparent: true, opacity: 0, side: THREE.DoubleSide, depthWrite: false })
      );
      malla.position.copy(pos.clone().multiplyScalar(1.045));
      malla.lookAt(pos.clone().multiplyScalar(2));
      malla.userData = { fase: i / 3 };
      this.escena.add(malla); this.ondas.push(malla);
    }
  }

  /* ---------------- estrellas ---------------- */
  _estrellas() {
    var n = this.ligero ? 600 : 1600;
    var pos = new Float32Array(n * 3);
    for (var i = 0; i < n; i++) {
      var r = 40 + Math.random() * 20, phi = Math.acos(2 * Math.random() - 1), th = Math.random() * Math.PI * 2;
      pos[i * 3] = r * Math.sin(phi) * Math.cos(th); pos[i * 3 + 1] = r * Math.cos(phi); pos[i * 3 + 2] = r * Math.sin(phi) * Math.sin(th);
    }
    var geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    this.escena.add(new THREE.Points(geo, new THREE.PointsMaterial({ color: 0xffffff, size: 0.16, sizeAttenuation: true, transparent: true, opacity: 0.7 })));
  }

  /* ---------------- partículas de alisios (flujo atmosférico O←E) ---------------- */
  _particulasAlisios() {
    var n = this.ligero ? 90 : 150;
    this._pAli = [];
    var pos = new Float32Array(n * 3), col = new Float32Array(n * 3);
    for (var i = 0; i < n; i++) {
      var lat = -8 + Math.random() * 16, lng = -175 + Math.random() * 75, radio = 1.035 + Math.random() * 0.015;
      var v = latLngAVector3(lat, lng, radio);
      pos[i * 3] = v.x; pos[i * 3 + 1] = v.y; pos[i * 3 + 2] = v.z;
      col[i * 3] = 1; col[i * 3 + 1] = 1; col[i * 3 + 2] = 1;
      this._pAli.push({ lat: lat, lng: lng, radio: radio, vel: 0.5 + Math.random() * 0.5 });
    }
    var geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    geo.setAttribute('color', new THREE.BufferAttribute(col, 3));
    this.pAli = new THREE.Points(geo, new THREE.PointsMaterial({ size: 0.02, map: texturaPunto(), vertexColors: true, transparent: true, opacity: 0.55, sizeAttenuation: true, depthWrite: false }));
    this.escena.add(this.pAli);
  }

  /* ---------------- foco de calor costero (estacional, Pacífico oriental) ---------------- */
  _focoCalor() {
    var n = this.ligero ? 260 : 520;
    this._fcPts = [];
    var pos = new Float32Array(n * 3), col = new Float32Array(n * 3);
    this._fcLngMin = -110; this._fcLngMax = -83;
    var latMin = -8, latMax = 8, lngC = (this._fcLngMin + this._fcLngMax) / 2, latC = 0, sLat = (latMax - latMin) / 2, sLng = (this._fcLngMax - this._fcLngMin) / 2;
    var i = 0, intentos = 0;
    while (i < n && intentos < n * 25) {
      intentos++;
      var lat = latMin + Math.random() * (latMax - latMin);
      var lng = this._fcLngMin + Math.random() * (this._fcLngMax - this._fcLngMin);
      var dLat = (lat - latC) / sLat, dLng = (lng - lngC) / sLng, dist = Math.sqrt(dLat * dLat + dLng * dLng);
      if (Math.random() > Math.exp(-dist * dist * 1.2)) { continue; }
      var radio = 1.014 + Math.random() * 0.016, v = latLngAVector3(lat, lng, radio);
      pos[i * 3] = v.x; pos[i * 3 + 1] = v.y; pos[i * 3 + 2] = v.z;
      var c = new THREE.Color().lerpColors(new THREE.Color(0xff2200), new THREE.Color(0xffd000), Math.max(0, 1 - dist));
      c.lerp(new THREE.Color(0xff7a00), 0.25);
      col[i * 3] = c.r; col[i * 3 + 1] = c.g; col[i * 3 + 2] = c.b;
      this._fcPts.push({ latBase: lat, lngBase: lng, radio: radio, fase: Math.random() * 6.28 });
      i++;
    }
    var geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos.slice(0, i * 3), 3));
    geo.setAttribute('color', new THREE.BufferAttribute(col.slice(0, i * 3), 3));
    this.foco = new THREE.Points(geo, new THREE.PointsMaterial({ size: 0.06, map: texturaPunto(), vertexColors: true, transparent: true, opacity: 0, sizeAttenuation: true, depthWrite: false, blending: THREE.AdditiveBlending }));
    this.escena.add(this.foco);
  }

  // Intensidad estacional (ago-dic 2026): campana 0.30→0.70→1.0→1.0→0.35.
  _intensidadFoco(mes) {
    var p = String(mes || '').split('-');
    if (p.length < 2 || +p[0] !== 2026) { return 0; }
    var curva = { 8: 0.30, 9: 0.70, 10: 1.0, 11: 1.0, 12: 0.35 };
    return curva[+p[1]] || 0;
  }

  /* ---------------- mapa de Nariño (GeoJSON coloreado por riesgo mensual) ---------------- */
  _mapaNarino() {
    var self = this;
    if (!CFG.geojson) { return; }
    this.mapaGrupo = new THREE.Group();
    this.escena.add(this.mapaGrupo);

    var serieRiesgo = {};
    var pintar = function () {
      fetch(CFG.geojson, { cache: 'force-cache' }).then(function (r) { return r.json(); }).then(function (gj) {
        (gj.features || []).forEach(function (feat) {
          var props = feat.properties || {};
          var divipola = String(props.MPIO_CDPMP || props.divipola || '');
          var nombre = props.MPIO_CNMBR || props.nombre || 'Municipio';
          var matR = new THREE.MeshBasicMaterial({ color: 0x2e7d32, transparent: true, opacity: 0.6, side: THREE.DoubleSide, depthWrite: false });
          var matB = new THREE.LineBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0.4 });
          var muni = { divipola: divipola, nombre: nombre, matR: matR, matB: matB, serie: serieRiesgo[divipola] || null };
          var gMun = new THREE.Group();
          featurePoligonos(feat).forEach(function (anillos) {
            var av = anillosAVectores(anillos, 1.013);
            var ext = av[0];
            if (!ext || ext.length < 3) { return; }
            var p = new Float32Array(ext.length * 3);
            ext.forEach(function (v, k) { p[k * 3] = v.x; p[k * 3 + 1] = v.y; p[k * 3 + 2] = v.z; });
            var geoR = new THREE.BufferGeometry();
            geoR.setAttribute('position', new THREE.BufferAttribute(p, 3));
            geoR.setIndex(triangularFan(ext));
            geoR.computeVertexNormals();
            var meshR = new THREE.Mesh(geoR, matR);
            meshR.userData.muni = muni;
            gMun.add(meshR);
            self._mapaMeshes.push(meshR);
            gMun.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(ext.concat([ext[0]])), matB));
          });
          self.mapaGrupo.add(gMun);
          self._mapaMuns.push(muni);
        });
        self._mapaMes(self.estado.mes || CFG.mesActual);
      }).catch(function () { /* sin mapa: el resto del globo sigue */ });
    };

    if (CFG.rest) {
      fetch(CFG.rest + '/mapa-narino').then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.municipios) { serieRiesgo = d.municipios; }
        pintar();
      }).catch(pintar);
    } else { pintar(); }
  }

  // Tooltip al pasar el mouse: municipio (si el mapa está cargado) o el
  // fenómeno (SST, alisios, nubes…) sobre el globo, con los datos del mes.
  _hover() {
    var self = this;
    this.raycaster = new THREE.Raycaster();
    this.mouse = new THREE.Vector2();
    var dom = this.renderer.domElement;
    this.tip = document.createElement('div');
    this.tip.className = 'man-globo__tip';
    this.tip.hidden = true;
    this.cont.appendChild(this.tip);

    var onMove = function (e) {
      var rect = dom.getBoundingClientRect();
      self.mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
      self.mouse.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;
      self.raycaster.setFromCamera(self.mouse, self.camara);

      // 1) Municipios (si la capa del mapa está cargada).
      if (self._mapaMeshes.length) {
        var hm = self.raycaster.intersectObjects(self._mapaMeshes, false);
        if (hm.length && hm[0].object.userData.muni) {
          self._tooltipMuni(e, hm[0].object.userData.muni);
          dom.style.cursor = 'pointer';
          return;
        }
      }
      // 2) Fenómeno sobre el globo (raycast a la esfera de la Tierra).
      var hg = self.raycaster.intersectObject(self.globo, false);
      if (hg.length) { self._tooltipFenomeno(e, hg[0].point); }
      else { self.tip.hidden = true; dom.style.cursor = ''; }
    };
    dom.addEventListener('pointermove', onMove);
    dom.addEventListener('pointerleave', function () { self.tip.hidden = true; dom.style.cursor = ''; });
  }

  // Tooltip educativo del fenómeno según la zona y el mes activo.
  _tooltipFenomeno(e, point) {
    var p = point.clone().normalize();
    var lat = 90 - Math.acos(Math.max(-1, Math.min(1, p.y))) * 180 / Math.PI;
    var lng = Math.atan2(p.z, -p.x) * 180 / Math.PI - 180;
    if (lng < -180) { lng += 360; }

    var z = zonaFenomeno(lat, lng);
    if (!z) { this.tip.hidden = true; this.renderer.domElement.style.cursor = ''; return; }
    this.renderer.domElement.style.cursor = 'help';

    var oni = this.estado.oniObjetivo || 0;
    var mes = this._mesTxt(this.estado.mes) || '';
    var meta = (mes ? mes + ' · ' : '') + 'ONI ' + (oni >= 0 ? '+' : '') + oni.toFixed(1) + ' °C' + (this.estado.faseTxt ? ' · ' + this.estado.faseTxt : '');
    var html = '<strong>' + esc(z.titulo) + '</strong>';
    z.lineas.forEach(function (l) { html += '<span class="tt-linea">' + esc(l) + '</span>'; });
    html += '<span class="tt-meta">' + esc(meta) + '</span>';

    this.tip.innerHTML = html;
    this.tip.hidden = false;
    var rect = this.cont.getBoundingClientRect();
    var x = e.clientX - rect.left, y = e.clientY - rect.top;
    var w = this.tip.offsetWidth || 200, half = w / 2;
    if (x - half < 6) { x = half + 6; }
    if (x + half > rect.width - 6) { x = rect.width - half - 6; }
    this.tip.style.transform = (y - (this.tip.offsetHeight || 80) - 14 < 0) ? 'translate(-50%, 16px)' : 'translate(-50%, calc(-100% - 12px))';
    this.tip.style.left = x + 'px';
    this.tip.style.top = y + 'px';
  }

  _tooltipMuni(e, muni) {
    var rect = this.cont.getBoundingClientRect();
    var x = e.clientX - rect.left, y = e.clientY - rect.top;
    var data = muni.serie || {};
    var arr = data.serie || [];
    var reg = null;
    for (var i = 0; i < arr.length; i++) { if (arr[i].mes === this.estado.mes) { reg = arr[i]; break; } }
    if (!reg && arr.length) { reg = arr[arr.length - 1]; }

    var html = '<strong>' + esc(titulo(muni.nombre)) + '</strong>';
    if (reg) {
      html += '<span class="tt-nivel" style="background:' + esc(reg.color) + '">' + esc(String(reg.nivel || '').toUpperCase()) + ' · ' + Math.round(reg.riesgo * 100) + '/100</span>';
      html += '<span class="tt-linea">' + esc(this._mesTxt(reg.mes)) + ' · ' + (reg.tipo === 'proyectado' ? 'proyectado' : 'observado') + '</span>';
      var ind = reg.ind || {};
      if (ind.deficit_hidrico != null) { html += '<span class="tt-linea">Déficit hídrico: <b>' + esc(ind.deficit_hidrico) + '/100</b></span>'; }
      if (ind.focos_calor != null) { html += '<span class="tt-linea">Focos de calor: <b>' + esc(ind.focos_calor) + '</b></span>'; }
      if (ind.area_cultivos_riesgo_pct != null) { html += '<span class="tt-linea">Cultivos en riesgo: <b>' + esc(ind.area_cultivos_riesgo_pct) + '%</b></span>'; }
    }
    // Lo que puede pasar: pico previsto.
    if (data.mes_pico) {
      html += '<span class="tt-linea tt-pico">▲ Pico previsto: <b>' + esc(this._mesTxt(data.mes_pico) || data.mes_pico) + '</b>' + (data.indice_pico != null ? ' (' + Math.round(data.indice_pico * 100) + '/100)' : '') + '</span>';
    }
    // Lo que ha pasado: afectación histórica.
    if (data.historico) {
      html += '<span class="tt-linea tt-hist">Afectación histórica: <b>' + esc(data.historico) + '</b></span>';
    }
    html += '<span class="tt-meta">DIVIPOLA ' + esc(muni.divipola) + (data.regimen ? ' · ' + esc(data.regimen) : '') + '</span>';

    this.tip.innerHTML = html;
    this.tip.hidden = false;
    var w = this.tip.offsetWidth || 180, half = w / 2;
    if (x - half < 6) { x = half + 6; }
    if (x + half > rect.width - 6) { x = rect.width - half - 6; }
    this.tip.style.transform = (y - (this.tip.offsetHeight || 70) - 14 < 0) ? 'translate(-50%, 16px)' : 'translate(-50%, calc(-100% - 12px))';
    this.tip.style.left = x + 'px';
    this.tip.style.top = y + 'px';
  }

  // Recolorea los municipios para el mes activo.
  _mapaMes(mes) {
    if (!this._mapaMuns || !this._mapaMuns.length) { return; }
    this._mapaMuns.forEach(function (m) {
      var reg = null;
      if (m.serie && m.serie.serie) {
        for (var i = 0; i < m.serie.serie.length; i++) { if (m.serie.serie[i].mes === mes) { reg = m.serie.serie[i]; break; } }
        if (!reg && m.serie.serie.length) { reg = m.serie.serie[m.serie.serie.length - 1]; }
      }
      if (reg) {
        var c = new THREE.Color(reg.color);
        m.matR.color.copy(c);
        m.matR.opacity = 0.5 + Math.min(1, reg.riesgo) * 0.42;
        m.matB.color.copy(c).offsetHSL(0, 0, 0.18);
      }
    });
  }

  /* ---------------- API: aplicar ONI ---------------- */
  setOni(oni, mes, fase, prob, resumen) {
    this.estado.oniObjetivo = +oni || 0;
    this.estado.nivel = nivelPorOni(this.estado.oniObjetivo);
    this.estado.faseTxt = fase || this.estado.faseTxt || '';
    this.estado.colorMarcObj = new THREE.Color(COLORES_ALERTA[this.estado.nivel] || COLORES_ALERTA.verde);
    if (mes) {
      this.estado.mes = mes;
      this._focoObjetivo = this._intensidadFoco(mes);
      this._mapaMes(mes);
    }
    // Cintillo de datos clave (mes · ONI · probabilidad · resumen).
    if (this.cMes && mes) { this.cMes.textContent = this._mesTxt(mes); }
    if (this.cOni) { this.cOni.textContent = (oni >= 0 ? '+' : '') + (+oni).toFixed(1) + ' °C'; }
    if (this.cProb && prob != null) { this.cProb.textContent = Math.round(prob) + '%'; }
    if (this.cResumen && resumen != null) { this.cResumen.textContent = resumen; }
  }

  /* ---------------- cámara cinemática ---------------- */
  irACamara(nombre) {
    var destino = (nombre === 'mecanismo') ? this.camMecanismo : (nombre === 'local' ? this.camLocal : this.camDefault);
    var desde = this.camara.position.clone();
    var medio = desde.clone().add(destino).multiplyScalar(0.5).normalize().multiplyScalar(5.5);
    // Curva Bezier 3D: la cámara "barre" el globo en arco (no en línea recta).
    this._camTransicion = { curva: new THREE.QuadraticBezierCurve3(desde, medio, destino.clone()), t: 0, dur: this.reduced ? 0.1 : 1.4 };
    this.controles.autoRotate = false;
  }

  /* ---------------- toolbar flotante + drawers ---------------- */
  _controles() {
    var self = this;
    // Cámaras.
    Array.prototype.forEach.call(this.cont.querySelectorAll('[data-camara]'), function (b) {
      b.addEventListener('click', function () { self.irACamara(b.getAttribute('data-camara')); });
    });
    // Drawers (mecanismo / histórico).
    Array.prototype.forEach.call(this.cont.querySelectorAll('[data-panel]'), function (b) {
      b.addEventListener('click', function () { self._toggleDrawer(b.getAttribute('data-panel'), b); });
    });
    Array.prototype.forEach.call(this.cont.querySelectorAll('.man-globo__drawer [data-cerrar]'), function (x) {
      x.addEventListener('click', function () {
        var d = x.closest('.man-globo__drawer'); if (d) { d.hidden = true; }
      });
    });
  }

  _toggleDrawer(cual, btn) {
    var self = this;
    var drawer = this.cont.querySelector('.man-globo__drawer[data-drawer="' + cual + '"]');
    if (!drawer) { return; }
    // Cierra los demás.
    Array.prototype.forEach.call(this.cont.querySelectorAll('.man-globo__drawer'), function (d) { if (d !== drawer) { d.hidden = true; } });
    Array.prototype.forEach.call(this.cont.querySelectorAll('[data-panel]'), function (b) { if (b !== btn) { b.setAttribute('aria-expanded', 'false'); } });
    var abrir = drawer.hidden;
    drawer.hidden = !abrir;
    if (btn) { btn.setAttribute('aria-expanded', abrir ? 'true' : 'false'); }
    if (!abrir) { return; }
    var cuerpo = drawer.querySelector('.man-globo__drawer-cuerpo');
    if (cuerpo.getAttribute('data-listo')) { return; }
    if (cual === 'mecanismo') { self._pintarMecanismo(cuerpo); }
    else if (cual === 'historico') { self._pintarHistorico(cuerpo); }
  }

  _pintarMecanismo(cuerpo) {
    var m = this.mecanismo;
    if (!m) { cuerpo.innerHTML = '<p>No hay información del mecanismo disponible.</p>'; return; }
    var html = m.que ? '<p class="man-globo__mec-intro">' + esc(m.que) + '</p>' : '';
    if (m.pasos && m.pasos.length) {
      html += '<ol class="man-globo__pasos">';
      m.pasos.forEach(function (p) { html += '<li>' + esc(p) + '</li>'; });
      html += '</ol>';
    }
    cuerpo.innerHTML = html;
    cuerpo.setAttribute('data-listo', '1');
  }

  _pintarHistorico(cuerpo) {
    var self = this;
    if (!CFG.rest) { cuerpo.innerHTML = '<p>Sin conexión a datos.</p>'; return; }
    cuerpo.innerHTML = '<p class="man-globo__mec-intro">Selecciona un episodio para representar su intensidad en el globo.</p>';
    fetch(CFG.rest + '/historico').then(function (r) { return r.json(); }).then(function (d) {
      var eps = (d && d.episodios) || [];
      if (!eps.length) { cuerpo.innerHTML = '<p>No hay episodios históricos.</p>'; return; }
      var cont = document.createElement('div');
      cont.className = 'man-globo__eps';
      eps.forEach(function (e) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'man-globo__ep';
        b.innerHTML = '<strong>' + esc(e.periodo || '') + '</strong><span>ONI pico +' + esc(e.oni_pico) + ' · ' + esc(String(e.categoria || '').replace(/_/g, ' ')) + '</span>';
        b.addEventListener('click', function () {
          self.setOni(+e.oni_pico || 0, self.estado.mes, faseNombre(+e.oni_pico || 0));
          self.irACamara('mecanismo');
        });
        cont.appendChild(b);
      });
      cuerpo.appendChild(cont);
      cuerpo.setAttribute('data-listo', '1');
    }).catch(function () { cuerpo.innerHTML = '<p>No se pudieron cargar los episodios.</p>'; });
  }

  _eventos() {
    var self = this;
    window.addEventListener('man:mes', function (e) {
      if (e.detail) { self.setOni(+e.detail.oni, e.detail.mes, e.detail.fase, e.detail.prob, e.detail.resumen); }
    });
    // Capas: activar/desactivar elementos del globo desde el menú "Capas".
    window.addEventListener('man:capa', function (e) {
      if (!e.detail) { return; }
      var v = !!e.detail.visible;
      switch (e.detail.capa) {
        case 'calor': if (self.heat) { self.heat.visible = v; } break;
        case 'foco': self._focoBloqueado = !v; if (self.foco && !v) { self.foco.visible = false; } break;
        case 'nubes': if (self.nubes) { self.nubes.visible = v; } break;
        case 'mapa': if (self.mapaGrupo) { self.mapaGrupo.visible = v; } break;
      }
    });
    window.addEventListener('resize', function () { self.redimensionar(); });
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (es) { self.visible = es[0].isIntersecting; }, { threshold: 0.04 }).observe(this.lienzo);
    }
  }

  _cargaInicial() {
    var self = this;
    if (!CFG.rest) { return; }
    fetch(CFG.rest + '/oni').then(function (r) { return r.json(); }).then(function (d) {
      self.mecanismo = (d && d.mecanismo) ? d.mecanismo : null;
      if (d && d.actual) {
        var prob = null, resumen = '', serie = (d && d.serie) || [];
        for (var i = 0; i < serie.length; i++) {
          if (serie[i].mes === d.actual.mes) { prob = serie[i].prob; resumen = serie[i].resumen; break; }
        }
        self.setOni(+d.actual.oni, d.actual.mes, d.actual.fase, prob, resumen);
      }
    }).catch(function () { /* mantiene neutral */ });
  }

  _quitarSkeleton() {
    var sk = this.cont.querySelector('.man-skeleton');
    if (sk) { sk.style.display = 'none'; }
  }

  redimensionar() {
    this.renderer.setSize(this._ancho(), this._alto());
    this.camara.aspect = this._ancho() / this._alto();
    this.camara.updateProjectionMatrix();
  }

  /* ---------------- loop de animación ---------------- */
  _loop() {
    var self = this;
    var ultimo = performance.now();
    function tick(ahora) {
      requestAnimationFrame(tick);
      if (!self.visible) { ultimo = ahora; return; }
      var dt = Math.min(0.05, (ahora - ultimo) / 1000); ultimo = ahora;
      var e = self.estado;

      // Interpolación suave del ONI hacia el objetivo.
      e.oni += (e.oniObjetivo - e.oni) * Math.min(1, dt * 2.5);
      var oniN = Math.min(1.5, Math.max(0, e.oni));
      var pulsa = (e.nivel === 'naranja' || e.nivel === 'rojo');

      // Anomalía: color + intensidad + pulso.
      self.uAnom.uColor.value.copy(colorPorOni(e.oni));
      self.uAnom.uIntensidad.value = Math.max(0.05, Math.min(1, e.oni / 1.5));
      self.uAnom.uTiempo.value += dt;

      // Alisios: se debilitan (encogen y amarillean) al subir el ONI.
      var factor = Math.max(0.15, 1 - e.oni * 0.6);
      var colAli = new THREE.Color().lerpColors(new THREE.Color(0xfff5b0), new THREE.Color(0xffffff), factor);
      self.flechas.forEach(function (f) {
        var obj = f.userData.escalaBase * factor;
        f.scale.setScalar(f.scale.x + (obj - f.scale.x) * Math.min(1, dt * 3));
        f.children.forEach(function (m) { if (m.material) { m.material.color = colAli; } });
      });

      // Mapa de calor: deriva O→E + color por proximidad/ONI.
      var pos = self.heat.geometry.attributes.position.array;
      var col = self.heat.geometry.attributes.color.array;
      var drift = 0.4 + 1.2 * oniN, tSeg = ahora * 0.001;
      for (var i = 0; i < self._heatPts.length; i++) {
        var pt = self._heatPts[i];
        var cl = coastLng(pt.lat);
        pt.lng += drift * pt.velocidadBase * dt;
        if (pt.lng > cl) { pt.lng = -230; }
        var lt = pt.latBase + Math.sin(tSeg * 0.6 + pt.fase) * 0.4;
        pt.lat = lt;
        // Posición en línea (sin asignar Vector3) — eficiente con miles de partículas.
        var phi = (90 - lt) * 0.0174533, theta = (pt.lng + 180) * 0.0174533, sp = Math.sin(phi);
        pos[i * 3] = -pt.radio * sp * Math.cos(theta);
        pos[i * 3 + 1] = pt.radio * Math.cos(phi);
        pos[i * 3 + 2] = pt.radio * sp * Math.sin(theta);
        // Eastness relativa a la costa de esa latitud (0 oeste → 1 en la costa).
        var est = Math.max(0, Math.min(1, (pt.lng + 180) / (cl + 180)));
        // (a) Lengua ecuatorial: punta delgada al oeste, se ensancha al este.
        var eqBand = Math.max(0, 1 - Math.abs(lt) / (2 + 6 * est));
        var tongue = eqBand * eqBand * (0.12 + 0.88 * est);
        // (b) Pluma costera del Pacífico oriental, ancha en latitud (México→Chile).
        var aLat = Math.abs(lt + 7);
        var latEnv = aLat <= 22 ? 1 : Math.max(0, 1 - (aLat - 22) / 14);
        var coastal = Math.pow(est, 2.6) * latEnv;
        var warmth = Math.max(tongue, coastal * 0.95);
        var c = colorPorOni(warmth * oniN * 1.7 + pt.ruido * 0.08);
        // Desvanecimiento hacia el oeste (Australia): con blending aditivo,
        // color→negro = invisible, así no hay corte en la línea de cambio de fecha.
        var wf = Math.min(1, Math.max(0, (pt.lng + 225) / 42));
        col[i * 3] = c.r * wf; col[i * 3 + 1] = c.g * wf; col[i * 3 + 2] = c.b * wf;
      }
      self.heat.geometry.attributes.position.needsUpdate = true;
      self.heat.geometry.attributes.color.needsUpdate = true;
      self.heat.material.opacity = 0.32 + Math.min(0.45, oniN * 0.35);

      // Partículas de alisios: fluyen de E→O (lng decrece); frenan y se enfrían al subir el ONI.
      var velFlujo = Math.max(0, 1.2 - e.oni * 0.9);
      var pa = self.pAli.geometry.attributes.position.array, ca = self.pAli.geometry.attributes.color.array;
      var cCal = new THREE.Color(0xffffff), cFrio = new THREE.Color(0x6db4ff), mez = Math.min(1, e.oni / 1.2);
      for (var ia = 0; ia < self._pAli.length; ia++) {
        var ptA = self._pAli[ia];
        ptA.lng -= velFlujo * ptA.vel * dt * 8;
        if (ptA.lng < -175) { ptA.lng = -100; }
        if (ptA.lng > -100) { ptA.lng = -175; }
        var vA = latLngAVector3(ptA.lat, ptA.lng, ptA.radio);
        pa[ia * 3] = vA.x; pa[ia * 3 + 1] = vA.y; pa[ia * 3 + 2] = vA.z;
        var cA = new THREE.Color().lerpColors(cCal, cFrio, mez);
        ca[ia * 3] = cA.r; ca[ia * 3 + 1] = cA.g; ca[ia * 3 + 2] = cA.b;
      }
      self.pAli.geometry.attributes.position.needsUpdate = true;
      self.pAli.geometry.attributes.color.needsUpdate = true;

      // Foco de calor costero: opacidad estacional interpolada + deriva al oeste + respiración.
      self._focoOpacidad += (self._focoObjetivo - self._focoOpacidad) * Math.min(1, dt * 1.2);
      var opFC = Math.max(0, self._focoOpacidad);
      self.foco.material.opacity = opFC * 0.85;
      self.foco.visible = !self._focoBloqueado && opFC > 0.005;
      if (opFC > 0.05) {
        var pf = self.foco.geometry.attributes.position.array, tsFC = ahora * 0.001;
        for (var ifc = 0; ifc < self._fcPts.length; ifc++) {
          var ptF = self._fcPts[ifc];
          ptF.lngBase -= 0.35 * dt;
          if (ptF.lngBase < self._fcLngMin) { ptF.lngBase = self._fcLngMax; }
          var vF = latLngAVector3(ptF.latBase + Math.sin(tsFC * 0.7 + ptF.fase) * 0.4, ptF.lngBase + Math.cos(tsFC * 0.5 + ptF.fase) * 0.6, ptF.radio);
          pf[ifc * 3] = vF.x; pf[ifc * 3 + 1] = vF.y; pf[ifc * 3 + 2] = vF.z;
        }
        self.foco.geometry.attributes.position.needsUpdate = true;
      }

      // Nubes: se disipan y migran al este con el ONI.
      var opNube = Math.max(0.05, 0.7 - oniN * 0.55);
      self._nubesArr.forEach(function (n) {
        n.material.opacity = opNube;
        var obj = n.userData.lngBase + oniN * 25;
        n.userData.lngActual += (obj - n.userData.lngActual) * Math.min(1, dt * 1.5);
        n.position.copy(latLngAVector3(n.userData.lat, n.userData.lngActual, 1.06));
      });

      // Teleconexión: opacidad sube con el ONI + flujo de partículas.
      var opTele = Math.min(0.8, Math.max(0, (e.oni - 0.3) * 0.85));
      self.tele.material.opacity = opTele;
      self.flujo.material.opacity = opTele;
      var cv = self.flujo.userData.curva, fs = self.flujo.userData.fases, pF = self.flujo.geometry.attributes.position.array;
      for (var k = 0; k < fs.length; k++) {
        fs[k] = (fs[k] + dt * 0.25) % 1;
        var pp = cv.getPoint(fs[k]); pF[k * 3] = pp.x; pF[k * 3 + 1] = pp.y; pF[k * 3 + 2] = pp.z;
      }
      self.flujo.geometry.attributes.position.needsUpdate = true;

      // Teleconexiones globales: aparecen y se intensifican con el ONI.
      if (self.teleGlobal) {
        var opG = Math.min(0.5, Math.max(0, (e.oni - 0.4) * 0.6));
        for (var tg = 0; tg < self.teleGlobal.length; tg++) {
          var og = self.teleGlobal[tg];
          og.material.opacity = og.isSprite ? opG * 1.5 : opG;
        }
      }

      // Marcador: interpola color hacia el nivel de alerta.
      e.colorMarc.lerp(e.colorMarcObj, Math.min(1, dt * 3));
      self.matMarc.color.copy(e.colorMarc);
      self.matMarc.emissive.copy(e.colorMarc).multiplyScalar(0.35);
      self.halo.material.color.copy(e.colorMarc);

      // Ondas + halo: pulsan solo en alerta naranja/roja.
      if (pulsa && !self.reduced) {
        var ts = ahora * 0.001;
        self.ondas.forEach(function (o) {
          var t = ((ts / 2.4) + o.userData.fase) % 1;
          o.scale.setScalar(1 + t * 2.5);
          o.material.opacity = (1 - t) * 0.7;
          o.material.color.copy(e.colorMarc);
        });
        var hp = ahora * 0.003;
        self.halo.material.opacity = 0.45 + 0.35 * Math.sin(hp * 2);
        self.halo.scale.setScalar(1 + 0.25 * Math.sin(hp * 2));
      } else {
        self.ondas.forEach(function (o) { o.material.opacity = 0; });
        self.halo.material.opacity = 0; self.halo.scale.setScalar(1);
      }

      if (self.capaNubes) { self.capaNubes.rotation.y += dt * 0.012; }

      // Transición cinemática de cámara (Bezier + easing). Mientras dura, no
      // actualizamos OrbitControls para que no compita con el movimiento.
      if (self._camTransicion) {
        var tr = self._camTransicion;
        tr.t += dt / tr.dur;
        var kc = Math.min(1, tr.t);
        var ease = kc < 0.5 ? 2 * kc * kc : 1 - Math.pow(-2 * kc + 2, 2) / 2;
        var pc = tr.curva.getPoint(ease);
        self.camara.position.copy(pc);
        self.camara.lookAt(0, 0, 0);
        if (kc >= 1) { self._camTransicion = null; }
      } else {
        self.controles.update();
      }
      self.renderer.render(self.escena, self.camara);
    }
    requestAnimationFrame(tick);
  }

  _mesTxt(mes) {
    var M = { '01': 'Ene', '02': 'Feb', '03': 'Mar', '04': 'Abr', '05': 'May', '06': 'Jun', '07': 'Jul', '08': 'Ago', '09': 'Sep', '10': 'Oct', '11': 'Nov', '12': 'Dic' };
    var p = String(mes || '').split('-');
    return p[1] ? (M[p[1]] + ' ' + p[0]) : '';
  }
}

document.querySelectorAll('[data-man-globo]').forEach(function (cont) {
  try { new GloboMAN(cont); } catch (e) { /* WebGL no disponible: deja el skeleton/fallback */ }
});
