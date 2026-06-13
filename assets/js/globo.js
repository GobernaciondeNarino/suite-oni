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

/* Interpolación de color por ONI: azul neutro → dorado → naranja → rojo. */
function colorPorOni(oni) {
  var v = Math.max(0, Math.min(2, oni));
  if (v < 0.5) { return new THREE.Color().lerpColors(new THREE.Color(0x2196f3), new THREE.Color(0xffeb3b), v / 0.5); }
  if (v < 1.0) { return new THREE.Color().lerpColors(new THREE.Color(0xffeb3b), new THREE.Color(0xff9800), (v - 0.5) / 0.5); }
  return new THREE.Color().lerpColors(new THREE.Color(0xff9800), new THREE.Color(0xd32f2f), Math.min(1, (v - 1.0) / 1.0));
}

function nivelPorOni(oni) {
  var a = Math.abs(oni);
  if (a >= 1.5) { return 'rojo'; }
  if (a >= 1.0) { return 'naranja'; }
  if (a >= 0.5) { return 'amarillo'; }
  return 'verde';
}

function esLigero() {
  return CFG.calidad === 'baja' ||
    (CFG.calidad === 'auto' && ((window.devicePixelRatio || 1) > 2 || (navigator.hardwareConcurrency || 4) <= 4));
}

class GloboMAN {
  constructor(cont) {
    this.cont = cont;
    this.lienzo = cont.querySelector('.man-globo__lienzo') || cont;
    this.cinta = cont.querySelector('.man-globo__cinta');
    this.ligero = esLigero();
    this.reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    this.visible = true;

    this.estado = {
      oni: 0, oniObjetivo: 0, nivel: 'verde',
      colorMarc: new THREE.Color(COLORES_ALERTA.verde),
      colorMarcObj: new THREE.Color(COLORES_ALERTA.verde)
    };

    this._escena();
    this._globo();
    this._anomalia();
    this._alisios();
    this._heat();
    this._nubes();
    this._teleconexion();
    this._marcador();
    this._ondas();
    this._estrellas();
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

    this.renderer = new THREE.WebGLRenderer({ antialias: !this.ligero, alpha: false });
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, this.ligero ? 1 : 2));
    this.renderer.setSize(this._ancho(), this._alto());
    this.lienzo.appendChild(this.renderer.domElement);

    this.escena.add(new THREE.AmbientLight(0xffffff, 0.5));
    this.escena.add(new THREE.HemisphereLight(0xbfd4ff, 0x202a40, 0.45));
    var sol = new THREE.DirectionalLight(0xffffff, 1.1);
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
    this.matGlobo = new THREE.MeshPhongMaterial({ color: 0x1A5276, specular: 0x222a44, shininess: 18 });
    this.globo = new THREE.Mesh(new THREE.SphereGeometry(1, seg, seg), this.matGlobo);
    this.escena.add(this.globo);
    this._cargarTextura();

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
    var lats = [-3, 0, 3];
    var self = this;
    lngs.forEach(function (lng) {
      lats.forEach(function (lat) {
        var mat = new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0.85 });
        var flecha = self._flecha(mat);
        var pos = latLngAVector3(lat, lng, 1.04);
        flecha.position.copy(pos);
        // Orienta la flecha tangente al globo, apuntando al oeste (lng decreciente).
        var oeste = latLngAVector3(lat, lng - 6, 1.04);
        flecha.lookAt(oeste);
        flecha.userData = { escalaBase: 0.16 };
        flecha.scale.setScalar(0.16);
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
    var n = this.ligero ? 220 : 520;
    this._heatPts = [];
    var pos = new Float32Array(n * 3), col = new Float32Array(n * 3);
    for (var i = 0; i < n; i++) {
      var lat = -8 + Math.random() * 16;
      var lng = -170 + Math.random() * 60;
      var p = { lat: lat, latBase: lat, lng: lng, radio: 1.012, velocidadBase: 0.5 + Math.random(), ruido: Math.random(), fase: Math.random() * 6.28 };
      this._heatPts.push(p);
      var v = latLngAVector3(lat, lng, p.radio);
      pos[i * 3] = v.x; pos[i * 3 + 1] = v.y; pos[i * 3 + 2] = v.z;
      col[i * 3] = 0.13; col[i * 3 + 1] = 0.59; col[i * 3 + 2] = 0.95;
    }
    var geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    geo.setAttribute('color', new THREE.BufferAttribute(col, 3));
    this.heat = new THREE.Points(geo, new THREE.PointsMaterial({ size: 0.05, vertexColors: true, transparent: true, opacity: 0.35, depthWrite: false, blending: THREE.AdditiveBlending, sizeAttenuation: true }));
    this.escena.add(this.heat);
  }

  /* ---------------- nubes (Pacífico occidental) ---------------- */
  _nubes() {
    this._nubesArr = [];
    this.nubes = new THREE.Group();
    var cant = this.ligero ? 10 : 16;
    for (var i = 0; i < cant; i++) {
      var lat = -3 + Math.random() * 6;
      var lng = -160 + Math.random() * 25;
      var m = new THREE.Mesh(
        new THREE.SphereGeometry(0.05 + Math.random() * 0.03, 12, 12),
        new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0.55, depthWrite: false })
      );
      m.position.copy(latLngAVector3(lat, lng, 1.06));
      m.userData = { lat: lat, lngBase: lng, lngActual: lng };
      this.nubes.add(m); this._nubesArr.push(m);
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

  /* ---------------- API: aplicar ONI ---------------- */
  setOni(oni, mes, fase) {
    this.estado.oniObjetivo = +oni || 0;
    this.estado.nivel = nivelPorOni(this.estado.oniObjetivo);
    this.estado.colorMarcObj = new THREE.Color(COLORES_ALERTA[this.estado.nivel] || COLORES_ALERTA.verde);
    if (this.cinta && mes) {
      this.cinta.textContent = this._mesTxt(mes) + ' · ONI ' + (oni >= 0 ? '+' : '') + (+oni).toFixed(1) + (fase ? ' · ' + fase : '');
    }
  }

  _eventos() {
    var self = this;
    window.addEventListener('man:mes', function (e) {
      if (e.detail) { self.setOni(+e.detail.oni, e.detail.mes, e.detail.fase); }
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
      if (d && d.actual) { self.setOni(+d.actual.oni, d.actual.mes, d.actual.fase); }
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
        pt.lng += drift * pt.velocidadBase * dt;
        if (pt.lng > -110) { pt.lng = -170; }
        pt.lat = pt.latBase + Math.sin(tSeg * 0.6 + pt.fase) * 0.4;
        var v = latLngAVector3(pt.lat, pt.lng, pt.radio);
        pos[i * 3] = v.x; pos[i * 3 + 1] = v.y; pos[i * 3 + 2] = v.z;
        var dlat = pt.lat / 8, dlng = (pt.lng + 140) / 30;
        var prox = 1 - Math.min(1, Math.sqrt(dlat * dlat + dlng * dlng) * 0.7);
        var c = colorPorOni(oniN * (0.4 + 0.6 * prox) + pt.ruido * 0.15);
        col[i * 3] = c.r; col[i * 3 + 1] = c.g; col[i * 3 + 2] = c.b;
      }
      self.heat.geometry.attributes.position.needsUpdate = true;
      self.heat.geometry.attributes.color.needsUpdate = true;
      self.heat.material.opacity = 0.32 + Math.min(0.45, oniN * 0.35);

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

      self.controles.update();
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
