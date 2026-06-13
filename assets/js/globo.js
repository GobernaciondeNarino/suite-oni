/* [man_globo] — Globo 3D cinematográfico (Three.js 0.160, módulo ES).
   Atmósfera fresnel, estrellas, halo ENSO interpolado por ONI, marcador de
   Nariño pulsante, OrbitControls con damping, modo ligero y pausa fuera de
   viewport. Escucha el evento 'man:mes' de la línea de tiempo. */
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

var CFG = window.MANGLOBO || { rest: '', calidad: 'auto', autorotar: true, textura: '', mesActual: '' };

document.querySelectorAll('[data-man-globo]').forEach(initGlobo);

function initGlobo(cont) {
  var lienzo = cont.querySelector('.man-globo__lienzo');
  var cinta = cont.querySelector('.man-globo__cinta');
  if (!lienzo) { return; }

  var ancho = function () { return lienzo.clientWidth || 480; };
  var alto = function () { return Math.max(320, Math.round((lienzo.clientWidth || 480) * 0.62)); };
  var ligero = CFG.calidad === 'baja' ||
    (CFG.calidad === 'auto' && ((window.devicePixelRatio || 1) > 2 || (navigator.hardwareConcurrency || 4) <= 4));

  var renderer = new THREE.WebGLRenderer({ antialias: !ligero, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, ligero ? 1 : 2));
  renderer.setSize(ancho(), alto());
  lienzo.appendChild(renderer.domElement);

  var scene = new THREE.Scene();
  var camera = new THREE.PerspectiveCamera(42, ancho() / alto(), 0.1, 100);
  camera.position.set(0, 0.6, 4.4);

  var controls = new OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true;
  controls.dampingFactor = 0.06;
  controls.rotateSpeed = 0.5;
  controls.enablePan = false;
  controls.minDistance = 2.6;
  controls.maxDistance = 7;
  controls.autoRotate = !!CFG.autorotar;
  controls.autoRotateSpeed = 0.35;

  // Iluminación: sol direccional + ambiental + hemisférica.
  scene.add(new THREE.AmbientLight(0x88aacc, 0.55));
  scene.add(new THREE.HemisphereLight(0xbfd4ff, 0x202a40, 0.5));
  var sol = new THREE.DirectionalLight(0xffffff, 1.1);
  sol.position.set(5, 3, 5);
  scene.add(sol);

  // Tierra.
  var R = 1.4;
  var mat = new THREE.MeshPhongMaterial({ color: 0x18324f, shininess: 8, specular: 0x223344 });
  var tierra = new THREE.Mesh(new THREE.SphereGeometry(R, ligero ? 48 : 96, ligero ? 48 : 96), mat);
  scene.add(tierra);
  if (CFG.textura) {
    new THREE.TextureLoader().load(CFG.textura, function (t) {
      if ('SRGBColorSpace' in THREE) { t.colorSpace = THREE.SRGBColorSpace; }
      mat.map = t; mat.color.set(0xffffff); mat.needsUpdate = true;
    }, undefined, function () { /* fallback: color procedimental ya aplicado */ });
  }

  // Atmósfera (rim glow fresnel).
  var atmMat = new THREE.ShaderMaterial({
    transparent: true, side: THREE.BackSide, blending: THREE.AdditiveBlending, depthWrite: false,
    vertexShader: 'varying vec3 vN; void main(){ vN = normalize(normalMatrix * normal); gl_Position = projectionMatrix * modelViewMatrix * vec4(position,1.0); }',
    fragmentShader: 'varying vec3 vN; void main(){ float i = pow(0.72 - dot(vN, vec3(0.0,0.0,1.0)), 2.0); gl_FragColor = vec4(0.30,0.55,1.0,1.0) * i; }'
  });
  scene.add(new THREE.Mesh(new THREE.SphereGeometry(R * 1.14, 48, 48), atmMat));

  // Estrellas (solo calidad alta).
  if (!ligero) { scene.add(estrellas(ligero)); }

  // Halo ENSO sobre el Pacífico ecuatorial.
  var halo = crearHalo(R);
  scene.add(halo.mesh);
  halo.base = 0.2;

  // Marcador de Nariño (sobre el SW de Colombia).
  var marcador = crearMarcador(R, 1.4, -78.0);
  marcador.pulso = 0.6;
  tierra.add(marcador.mesh);

  aplicarOni(0);

  // ONI inicial desde la REST interna.
  if (CFG.rest) {
    fetch(CFG.rest + '/oni').then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.actual) { aplicarOni(+d.actual.oni); etiqueta(d.actual.mes, d.actual.oni, d.actual.fase); }
    }).catch(function () { /* mantiene neutral */ });
  }

  // Sincronía con la línea de tiempo.
  window.addEventListener('man:mes', function (e) {
    if (e.detail) { aplicarOni(+e.detail.oni); etiqueta(e.detail.mes, e.detail.oni, e.detail.fase); }
  });

  // Oculta el skeleton.
  var sk = cont.querySelector('.man-skeleton');
  if (sk) { sk.style.display = 'none'; }

  // Loop con pausa fuera de viewport.
  var visible = true, t = 0;
  function animar() {
    requestAnimationFrame(animar);
    if (!visible) { return; }
    t += 0.016;
    controls.update();
    marcador.mesh.scale.setScalar(1 + 0.25 * marcador.pulso * (0.5 + 0.5 * Math.sin(t * 3)));
    halo.mat.opacity = halo.base + 0.06 * Math.sin(t * 2);
    renderer.render(scene, camera);
  }
  animar();

  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (es) { visible = es[0].isIntersecting; }, { threshold: 0.05 }).observe(lienzo);
  }

  window.addEventListener('resize', function () {
    renderer.setSize(ancho(), alto());
    camera.aspect = ancho() / alto();
    camera.updateProjectionMatrix();
  });

  /* --- helpers --- */
  function aplicarOni(oni) {
    var mag = Math.min(1, Math.abs(oni) / 2);
    halo.mat.color.copy(oni >= 0 ? new THREE.Color(0xc62828) : new THREE.Color(0x1565c0));
    halo.base = 0.12 + 0.5 * mag;
    halo.mesh.scale.setScalar(0.8 + 0.6 * mag);
    marcador.pulso = 0.4 + 0.6 * mag;
    marcador.mesh.material.color.set(oni >= 0.5 ? 0xc62828 : (oni <= -0.5 ? 0x1565c0 : 0xFFD500));
  }
  function etiqueta(mes, oni, fase) {
    if (cinta) { cinta.textContent = mesTxt(mes) + ' · ONI ' + (oni >= 0 ? '+' : '') + (+oni).toFixed(1) + ' · ' + (fase || ''); }
  }
}

function estrellas(ligero) {
  var g = new THREE.BufferGeometry();
  var n = ligero ? 400 : 1200;
  var pos = new Float32Array(n * 3);
  for (var i = 0; i < n; i++) {
    var r = 20 + Math.random() * 30;
    var th = Math.random() * Math.PI * 2;
    var ph = Math.acos(2 * Math.random() - 1);
    pos[i * 3] = r * Math.sin(ph) * Math.cos(th);
    pos[i * 3 + 1] = r * Math.cos(ph);
    pos[i * 3 + 2] = r * Math.sin(ph) * Math.sin(th);
  }
  g.setAttribute('position', new THREE.BufferAttribute(pos, 3));
  return new THREE.Points(g, new THREE.PointsMaterial({ color: 0xffffff, size: 0.08, sizeAttenuation: true, transparent: true, opacity: 0.7 }));
}

function crearHalo(R) {
  var m = new THREE.SpriteMaterial({ color: 0xc62828, transparent: true, opacity: 0.2, depthWrite: false, blending: THREE.AdditiveBlending });
  var s = new THREE.Sprite(m);
  s.position.copy(llToVec(0, -140, R * 1.02));
  s.scale.set(1.8, 1.0, 1);
  return { mesh: s, mat: m };
}

function crearMarcador(R, lat, lon) {
  var m = new THREE.MeshBasicMaterial({ color: 0xFFD500 });
  var s = new THREE.Mesh(new THREE.SphereGeometry(0.03, 16, 16), m);
  s.position.copy(llToVec(lat, lon, R * 1.01));
  return { mesh: s };
}

function llToVec(lat, lon, r) {
  var phi = (90 - lat) * Math.PI / 180;
  var theta = (lon + 180) * Math.PI / 180;
  return new THREE.Vector3(-r * Math.sin(phi) * Math.cos(theta), r * Math.cos(phi), r * Math.sin(phi) * Math.sin(theta));
}

function mesTxt(mes) {
  var M = { '01': 'Ene', '02': 'Feb', '03': 'Mar', '04': 'Abr', '05': 'May', '06': 'Jun', '07': 'Jul', '08': 'Ago', '09': 'Sep', '10': 'Oct', '11': 'Nov', '12': 'Dic' };
  var p = String(mes || '').split('-');
  return p[1] ? (M[p[1]] + ' ' + p[0]) : '';
}
