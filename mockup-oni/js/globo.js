// =====================================================================
// globo.js — Escena Three.js del globo terráqueo
// Responsabilidades: tierra + iluminación, anomalía del Pacífico (shader),
// flechas de alisios, marcador de Nariño con halo pulsante, cámaras
// predefinidas y transiciones suaves.
// =====================================================================

import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

// DECISIÓN: la textura de la Tierra se sirve desde CDN público (no se intenta
// cargar local) — así no aparece el 404 ruidoso en consola del assets/earth.jpg
// faltante. Si el equipo TIC sube su propia textura más adelante, basta
// reemplazar RUTAS_TEXTURA[0] por './assets/earth.jpg'.
const RUTAS_TEXTURA = [
  'https://unpkg.com/three-globe@2.31.1/example/img/earth-blue-marble.jpg',
  'https://cdn.jsdelivr.net/npm/three-globe@2.31.1/example/img/earth-blue-marble.jpg',
];

// Conversión lat/lng (grados) → vector unitario sobre esfera
function latLngAVector3(lat, lng, radio = 1) {
  const phi = (90 - lat) * Math.PI / 180;
  const theta = (lng + 180) * Math.PI / 180;
  return new THREE.Vector3(
    -radio * Math.sin(phi) * Math.cos(theta),
     radio * Math.cos(phi),
     radio * Math.sin(phi) * Math.sin(theta)
  );
}

// Interpolación de color por ONI (azul neutro → dorado → naranja → rojo)
function colorPorOni(oni) {
  const v = Math.max(0, Math.min(2, oni));
  if (v < 0.5) {
    const t = v / 0.5;
    return new THREE.Color().lerpColors(new THREE.Color(0x2196f3), new THREE.Color(0xffeb3b), t);
  } else if (v < 1.0) {
    const t = (v - 0.5) / 0.5;
    return new THREE.Color().lerpColors(new THREE.Color(0xffeb3b), new THREE.Color(0xff9800), t);
  } else {
    const t = Math.min(1, (v - 1.0) / 1.0);
    return new THREE.Color().lerpColors(new THREE.Color(0xff9800), new THREE.Color(0xd32f2f), t);
  }
}

// Color del marcador por nivel de alerta
const COLORES_ALERTA = {
  verde:    0x2e7d32,
  amarillo: 0xf9a825,
  naranja:  0xef6c00,
  rojo:     0xc62828,
};

export class GloboElNino {
  constructor(contenedor) {
    this.contenedor = contenedor;
    this.modoLigero = false;
    this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Estado actual (interpolado suavemente)
    this.estado = {
      oni: 0,
      oniObjetivo: 0,
      nivelAlerta: 'verde',
      colorMarcadorActual: new THREE.Color(COLORES_ALERTA.verde),
      colorMarcadorObjetivo: new THREE.Color(COLORES_ALERTA.verde),
    };

    this._construirEscena();
    this._construirGlobo();
    this._construirAnomaliaPacifico();
    this._construirAlisios();
    this._construirParticulasAlisios();
    this._construirHeatMapPacifico();
    this._construirFocoCalorCostero();
    this._construirNubesPacifico();
    this._construirTeleconexion();
    this._construirMarcadorNarino();
    this._construirOndasNarino();
    this._construirEstrellas();

    // Cámaras predefinidas — posiciones en coordenadas esféricas alrededor del globo
    this.posCamaraDefault = new THREE.Vector3(0, 1.5, 4.2);
    this.posCamaraMecanismo = new THREE.Vector3(-3.5, 0.6, -2.2); // mira al Pacífico ecuatorial
    // DECISIÓN: cámara "Impacto local" mucho más cercana — apunta directamente
    // a Nariño (lat ~1.5°N, lng ~-77.5°W) a ~1.6 radios desde el centro para
    // permitir distinguir los 64 municipios del heat map sin tener que
    // hacer zoom manual.
    this.posCamaraLocal = new THREE.Vector3(0.34, 0.35, 1.55);
    this.camara.position.copy(this.posCamaraDefault);

    // Transición de cámara
    this._camaraTransicion = null;

    this._registrarEventos();
    this._loop();
  }

  // -------------------- ESCENA BASE --------------------
  _construirEscena() {
    this.escena = new THREE.Scene();
    this.escena.background = new THREE.Color(0x000814);

    const w = this.contenedor.clientWidth;
    const h = this.contenedor.clientHeight;
    this.camara = new THREE.PerspectiveCamera(45, w / h, 0.1, 100);

    this.renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    this.renderer.setSize(w, h);
    this.contenedor.appendChild(this.renderer.domElement);

    // Luces
    this.luzAmbiente = new THREE.AmbientLight(0xffffff, 0.45);
    this.escena.add(this.luzAmbiente);
    this.luzSol = new THREE.DirectionalLight(0xffffff, 1.1);
    this.luzSol.position.set(5, 3, 5);
    this.escena.add(this.luzSol);

    // Controles
    this.controles = new OrbitControls(this.camara, this.renderer.domElement);
    this.controles.enableDamping = true;
    this.controles.dampingFactor = 0.07;
    // DECISIÓN: minDistance bajado de 2.2 → 1.06 para permitir acercarse
    // lo suficiente a Nariño y distinguir cada municipio del heat map
    // (el globo tiene radio 1.0; los polígonos están en 1.012, así que
    // 1.06 deja ~0.05 de margen sin atravesar la geometría).
    this.controles.minDistance = 1.06;
    this.controles.maxDistance = 12;
    this.controles.zoomSpeed = 1.4;            // scroll más responsivo
    this.controles.rotateSpeed = 0.55;
    // A medida que el usuario se acerca, OrbitControls aplica menos
    // velocidad de rotación implícita; subimos panSpeed por consistencia.
    this.controles.panSpeed = 0.8;
    this.controles.autoRotate = !this.reducedMotion;
    this.controles.autoRotateSpeed = 0.25;

    // Pausa auto-rotación cuando el usuario interactúa
    this.controles.addEventListener('start', () => { this.controles.autoRotate = false; });
  }

  // -------------------- GLOBO TIERRA --------------------
  _construirGlobo() {
    const geometria = new THREE.SphereGeometry(1, 64, 64);

    // DECISIÓN: material inicial con color de océano. Si la textura carga, la aplicamos.
    // Si falla, dejamos un océano sólido (no pantalla negra).
    this.materialGlobo = new THREE.MeshPhongMaterial({
      color: 0x1A5276,
      specular: 0x222a44,
      shininess: 18,
    });
    this.globo = new THREE.Mesh(geometria, this.materialGlobo);
    this.escena.add(this.globo);

    this._cargarTextura();

    // Atmósfera sutil (esfera ligeramente mayor con material aditivo)
    const geoAtm = new THREE.SphereGeometry(1.025, 48, 48);
    const matAtm = new THREE.MeshBasicMaterial({
      color: 0x4488cc,
      transparent: true,
      opacity: 0.08,
      side: THREE.BackSide,
    });
    this.atmosfera = new THREE.Mesh(geoAtm, matAtm);
    this.escena.add(this.atmosfera);
  }

  _cargarTextura() {
    const loader = new THREE.TextureLoader();
    loader.crossOrigin = 'anonymous';

    const aplicar = (textura) => {
      textura.colorSpace = THREE.SRGBColorSpace;
      this.materialGlobo.map = textura;
      this.materialGlobo.color = new THREE.Color(0xffffff);
      this.materialGlobo.needsUpdate = true;
      this._texturaListaResuelta = true;
    };

    // Timeout de seguridad: si nada responde en 5s → fallback procedimental.
    this._texturaListaResuelta = false;
    setTimeout(() => {
      if (!this._texturaListaResuelta) {
        this._dibujarContinentesFallback();
        this._texturaListaResuelta = true;
      }
    }, 5000);

    // Cadena de CDNs. Cualquier 404 silencioso entre ellos no asusta al usuario.
    const intentar = (i) => {
      if (i >= RUTAS_TEXTURA.length) {
        if (!this._texturaListaResuelta) {
          this._dibujarContinentesFallback();
          this._texturaListaResuelta = true;
        }
        return;
      }
      loader.load(RUTAS_TEXTURA[i], aplicar, undefined, () => intentar(i + 1));
    };
    intentar(0);
  }

  // Fallback: textura procedimental con continentes sencillos
  _dibujarContinentesFallback() {
    const canvas = document.createElement('canvas');
    canvas.width = 2048; canvas.height = 1024;
    const ctx = canvas.getContext('2d');
    // Océano
    ctx.fillStyle = '#1A5276';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    // Continentes (siluetas aproximadas)
    ctx.fillStyle = '#3d8b6e';
    const continentes = [
      // [x, y, w, h] en proporción de equirectangular
      [380, 150, 320, 380],   // América del Norte
      [550, 480, 200, 380],   // América del Sur
      [900, 180, 280, 220],   // Europa
      [950, 380, 340, 380],   // África
      [1180, 180, 540, 380],  // Asia
      [1620, 580, 180, 130],  // Australia
    ];
    continentes.forEach(([x, y, w, h]) => {
      ctx.beginPath();
      ctx.ellipse(x + w/2, y + h/2, w/2, h/2, 0, 0, Math.PI * 2);
      ctx.fill();
    });
    const tex = new THREE.CanvasTexture(canvas);
    tex.colorSpace = THREE.SRGBColorSpace;
    this.materialGlobo.map = tex;
    this.materialGlobo.color = new THREE.Color(0xffffff);
    this.materialGlobo.needsUpdate = true;
  }

  // -------------------- ANOMALÍA PACÍFICO (Niño-3.4) --------------------
  _construirAnomaliaPacifico() {
    // Posición central de la región Niño-3.4: lat 0, lng -145
    const centro = latLngAVector3(0, -145, 1.005);
    const normal = centro.clone().normalize();

    // Plano circular tangencial al globo
    const geo = new THREE.CircleGeometry(0.55, 64);
    const uniforms = {
      uColor: { value: new THREE.Color(0x2196f3) },
      uIntensidad: { value: 0.0 },   // 0 → 1 (modulado por ONI)
      uTiempo: { value: 0 },
    };
    this.uniformsAnomalia = uniforms;

    // DECISIÓN: shader simple — gradiente radial pulsante modulado por intensidad (ONI).
    const mat = new THREE.ShaderMaterial({
      uniforms,
      transparent: true,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
      side: THREE.DoubleSide,
      vertexShader: /* glsl */`
        varying vec2 vUv;
        void main() {
          vUv = uv;
          gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
        }
      `,
      fragmentShader: /* glsl */`
        uniform vec3 uColor;
        uniform float uIntensidad;
        uniform float uTiempo;
        varying vec2 vUv;
        void main() {
          vec2 p = vUv - 0.5;
          float r = length(p) * 2.0;
          if (r > 1.0) discard;
          float gradiente = pow(1.0 - r, 1.8);
          float pulso = 0.85 + 0.15 * sin(uTiempo * 1.8);
          float alfa = gradiente * uIntensidad * pulso;
          gl_FragColor = vec4(uColor, alfa);
        }
      `,
    });

    this.anomalia = new THREE.Mesh(geo, mat);
    this.anomalia.position.copy(centro);
    this.anomalia.lookAt(centro.clone().add(normal));
    this.escena.add(this.anomalia);
  }

  // -------------------- FLECHAS DE ALISIOS --------------------
  _construirAlisios() {
    // Pequeñas flechas a lo largo del ecuador del Pacífico, que apuntan de E → O.
    // Su escala se reduce con ONI alto (alisios debilitados).
    this.alisios = new THREE.Group();
    this.escena.add(this.alisios);

    const longitudes = [-160, -140, -120, -100, -180, 160];
    const offsetsLat = [-3, 3, 0];
    const matAlisio = new THREE.MeshBasicMaterial({ color: 0xffffff });

    this.alisios.flechas = [];
    longitudes.forEach((lng) => {
      offsetsLat.forEach((lat) => {
        const flecha = this._crearFlechaAlisio(matAlisio);
        const pos = latLngAVector3(lat, lng, 1.04);
        flecha.position.copy(pos);

        // Orientación: apunta hacia el oeste (lng - 10°)
        const objetivo = latLngAVector3(lat, lng - 10, 1.04);
        flecha.lookAt(objetivo);

        flecha.userData.escalaBase = 0.04 + Math.random() * 0.02;
        flecha.scale.setScalar(flecha.userData.escalaBase);
        this.alisios.add(flecha);
        this.alisios.flechas.push(flecha);
      });
    });
  }

  // ============= PARTÍCULAS DE VIENTO (ALISIOS) — capa atmosférica ============
  // RADIO 1.035–1.050 (encima de la superficie, en la atmósfera baja).
  // Color: blanco en neutral, azul-frío cuando ONI sube y el flujo se debilita.
  // Dirección: E → O (lng decrece). En ONI ≥ 1.33 quedan detenidas (no
  // retrógradas, ver corrección 2 del documento técnico).
  // Reciclaje: banda Niño-3.4 ampliada [-175°, -100°].
  _construirParticulasAlisios() {
    const cantidad = 220;
    this._particulasAlisios = [];

    const geo = new THREE.BufferGeometry();
    const posiciones = new Float32Array(cantidad * 3);
    const colores = new Float32Array(cantidad * 3);

    for (let i = 0; i < cantidad; i++) {
      const lat = -8 + Math.random() * 16;             // banda ecuatorial ±8°
      // DECISIÓN (analisis-enso-particulas, Corrección 4): inicializar dentro
      // de la región Niño-3.4 ampliada (-175° a -100°). El rango anterior
      // [-180°, -80°] desbordaba sobre territorio continental colombiano.
      const lng = -175 + Math.random() * 75;           // banda Niño-3.4
      const radio = 1.035 + Math.random() * 0.015;
      const v = latLngAVector3(lat, lng, radio);
      posiciones[i*3] = v.x; posiciones[i*3+1] = v.y; posiciones[i*3+2] = v.z;
      this._particulasAlisios.push({ lat, lng, radio, velocidadBase: 0.5 + Math.random() * 0.5 });
      colores[i*3] = 1; colores[i*3+1] = 1; colores[i*3+2] = 1;
    }
    geo.setAttribute('position', new THREE.BufferAttribute(posiciones, 3));
    geo.setAttribute('color', new THREE.BufferAttribute(colores, 3));

    const mat = new THREE.PointsMaterial({
      size: 0.022,
      vertexColors: true,
      transparent: true,
      opacity: 0.85,
      sizeAttenuation: true,
      depthWrite: false,
    });
    this.particulasAlisios = new THREE.Points(geo, mat);
    this.escena.add(this.particulasAlisios);
  }

  // -------------------- HEAT MAP DEL PACÍFICO (Niño-3.4 amplio) --------------------
  // DECISIÓN: 500 partículas térmicas distribuidas en banda lat -8 a +8,
  // lng -170 a -110. Cada una toma color en gradiente azul→amarillo→rojo
  // según el ONI actual, con un ruido por partícula para que el patrón
  // luzca orgánico.
  // ============= PARTÍCULAS DE AGUA (HEAT MAP NIÑO-3.4) — capa superficial =====
  // RADIO 1.008–1.026 (al nivel del mar, en la superficie del Pacífico).
  // 500 partículas en lat ±8°, lng -170° a -110° (región oficial Niño-3.4).
  // Color: gradiente azul → amarillo → rojo según ONI (anomalía SST).
  // DECISIÓN (req. usuario): añadimos un drift sutil W→E que se intensifica
  // con ONI. Esto representa las ondas de Kelvin que desplazan aguas cálidas
  // hacia el Pacífico oriental durante El Niño. El movimiento es ~5× más
  // lento que los alisios (atmósfera) y con jitter latitudinal para que
  // parezca turbulencia oceánica, no flujo aéreo.
  _construirHeatMapPacifico() {
    const cantidad = 500;
    this._heatPuntos = [];
    const posiciones = new Float32Array(cantidad * 3);
    const colores = new Float32Array(cantidad * 3);

    for (let i = 0; i < cantidad; i++) {
      const lat = -8 + Math.random() * 16;
      const lng = -170 + Math.random() * 60;
      const radio = 1.008 + Math.random() * 0.018;
      const v = latLngAVector3(lat, lng, radio);
      posiciones[i*3] = v.x; posiciones[i*3+1] = v.y; posiciones[i*3+2] = v.z;
      colores[i*3] = 0.2; colores[i*3+1] = 0.5; colores[i*3+2] = 1.0;
      this._heatPuntos.push({
        lat, lng, radio,
        latBase: lat,                           // referencia para jitter vertical
        ruido: Math.random(),
        velocidadBase: 0.4 + Math.random() * 0.5, // variación por partícula
        faseJitter: Math.random() * Math.PI * 2,  // fase del wobble lat
      });
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(posiciones, 3));
    geo.setAttribute('color', new THREE.BufferAttribute(colores, 3));
    const mat = new THREE.PointsMaterial({
      size: 0.06,
      vertexColors: true,
      transparent: true,
      opacity: 0.55,
      sizeAttenuation: true,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
    });
    this.heatMap = new THREE.Points(geo, mat);
    this.escena.add(this.heatMap);
  }

  // ============= FOCO DE CALOR COSTERO (capa estacional ago-dic 2026) ============
  // RADIO 1.014–1.030 (sobre la superficie, encima del heat map del Pacífico).
  // ~500 partículas sobre el Pacífico oriental.
  // GEOMETRÍA (ajustes req. usuario):
  //   · lat -8° a +8° (MISMO ALTO que el heat map del Pacífico)
  //   · lng -110° a -83° (NO cubre Nariño: -77.5° queda ~5.5° al este)
  // Color: gradiente amarillo cálido → naranja → rojo intenso (additive blending).
  // Drift sutil hacia el OESTE durante la animación (req. usuario).
  // Visibilidad estacional: aparece suavemente desde agosto, pico en oct-nov,
  // desaparece suavemente hacia diciembre. La opacidad se interpola frame a
  // frame en el loop según _intensidadFocoCalor(mesActual).
  _construirFocoCalorCostero() {
    const cantidad = 520;
    this._focoCalorPuntos = [];
    const posiciones = new Float32Array(cantidad * 3);
    const colores = new Float32Array(cantidad * 3);

    // Rangos de la región (sin tocar territorio continental):
    this._fcLatMin = -8;   this._fcLatMax = 8;     // mismo alto del heat map
    this._fcLngMin = -110; this._fcLngMax = -83;   // recorte al oeste de Nariño
    const lngCentro = (this._fcLngMin + this._fcLngMax) / 2; // ~-96.5
    const latCentro = 0;
    const semiLat = (this._fcLatMax - this._fcLatMin) / 2;   // 8
    const semiLng = (this._fcLngMax - this._fcLngMin) / 2;   // 13.5

    let i = 0;
    let intentos = 0;
    while (i < cantidad && intentos < cantidad * 25) {
      intentos++;
      const lat = this._fcLatMin + Math.random() * (this._fcLatMax - this._fcLatMin);
      const lng = this._fcLngMin + Math.random() * (this._fcLngMax - this._fcLngMin);
      // Distancia normalizada al centro (elipse semiLat × semiLng)
      const dLat = (lat - latCentro) / semiLat;
      const dLng = (lng - lngCentro) / semiLng;
      const dist = Math.sqrt(dLat*dLat + dLng*dLng);
      // Acepta con probabilidad gaussiana — produce forma orgánica, bordes deshilachados
      if (Math.random() > Math.exp(-dist * dist * 1.2)) continue;
      const radio = 1.014 + Math.random() * 0.016;
      const v = latLngAVector3(lat, lng, radio);
      posiciones[i*3]     = v.x;
      posiciones[i*3 + 1] = v.y;
      posiciones[i*3 + 2] = v.z;

      // Color por cercanía al centro: amarillo dentro, rojo en los bordes
      const cercania = Math.max(0, 1 - dist);
      const c = new THREE.Color().lerpColors(
        new THREE.Color(0xff2200),   // rojo profundo (bordes)
        new THREE.Color(0xffd000),   // amarillo cálido (centro)
        cercania
      );
      c.lerp(new THREE.Color(0xff7a00), 0.25);
      colores[i*3]     = c.r;
      colores[i*3 + 1] = c.g;
      colores[i*3 + 2] = c.b;

      this._focoCalorPuntos.push({
        lat, lng, radio,
        latBase: lat, lngBase: lng,
        ruido: Math.random(),
        fase: Math.random() * Math.PI * 2,
      });
      i++;
    }
    // Si por rechazo quedaron menos, recortamos el buffer
    const N = i;
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(posiciones.slice(0, N*3), 3));
    geo.setAttribute('color',    new THREE.BufferAttribute(colores.slice(0, N*3), 3));

    const mat = new THREE.PointsMaterial({
      size: 0.058,
      vertexColors: true,
      transparent: true,
      opacity: 0,                  // arranca invisible
      sizeAttenuation: true,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
    });
    this.focoCalor = new THREE.Points(geo, mat);
    this.escena.add(this.focoCalor);

    // Opacidad actual (interpolada frame a frame hacia _focoCalorObjetivo)
    this._focoCalorOpacidad = 0;
    this._focoCalorObjetivo = 0;
    // Bandera: si el usuario eligió "Oculto" en el select, la opacidad
    // objetivo se fuerza a 0 sin importar el mes.
    this._focoCalorBloqueado = false;
  }

  // Permite al control del UI ocultar/mostrar esta capa.
  setFocoCalorBloqueado(bloqueado) {
    this._focoCalorBloqueado = !!bloqueado;
    if (bloqueado) {
      this._focoCalorObjetivo = 0;
    } else if (this.estado.mesActual) {
      // Re-calcular objetivo a partir del mes actual
      this._focoCalorObjetivo = this._intensidadFocoCalor(this.estado.mesActual);
    }
  }

  // Devuelve intensidad objetivo (0..1) según el mes activo.
  // Sólo visible entre ago-2026 y dic-2026, con curva tipo campana.
  _intensidadFocoCalor(mesId) {
    if (!mesId) return 0;
    const [aStr, mStr] = mesId.split('-');
    const a = parseInt(aStr, 10);
    const m = parseInt(mStr, 10);
    if (a !== 2026) return 0;
    if (m < 8 || m > 12) return 0;
    // Curva: 8→0.30 (aparece suave), 9→0.70, 10→1.0, 11→1.0, 12→0.35 (desaparece suave)
    const curva = { 8: 0.30, 9: 0.70, 10: 1.0, 11: 1.0, 12: 0.35 };
    return curva[m] || 0;
  }

  // -------------------- NUBES SOBRE PACÍFICO --------------------
  // DECISIÓN: pequeñas esferas vaporosas sobre el Pacífico ecuatorial central.
  // En ONI bajo son densas (lluvia normal); al subir ONI se disipan: opacidad
  // baja y se dispersan al este. Comunica "la zona de lluvias se desplaza".
  _construirNubesPacifico() {
    this.nubes = new THREE.Group();
    const cantidad = 16;
    this._nubes = [];
    for (let i = 0; i < cantidad; i++) {
      const lat = -3 + Math.random() * 6;
      const lng = -160 + Math.random() * 25; // Pacífico occidental
      const radio = 1.06;
      const v = latLngAVector3(lat, lng, radio);
      const geo = new THREE.SphereGeometry(0.05 + Math.random() * 0.03, 12, 12);
      const mat = new THREE.MeshBasicMaterial({
        color: 0xffffff,
        transparent: true,
        opacity: 0.55,
        depthWrite: false,
      });
      const m = new THREE.Mesh(geo, mat);
      m.position.copy(v);
      // DECISIÓN (analisis-enso-particulas, Corrección 3A): lngActual permite
      // interpolar fotograma a fotograma el desplazamiento de la nube hacia
      // el este, evitando saltos bruscos en transiciones de mes.
      m.userData = { lat, lng, lngBase: lng, lngActual: lng };
      this.nubes.add(m);
      this._nubes.push(m);
    }
    this.escena.add(this.nubes);
  }

  // -------------------- ONDAS CONCÉNTRICAS EN NARIÑO --------------------
  _construirOndasNarino() {
    this.ondasNarino = [];
    const pos = latLngAVector3(1.2, -77.3, 1.0);
    const normal = pos.clone().normalize();

    for (let i = 0; i < 3; i++) {
      const geo = new THREE.RingGeometry(0.04, 0.045, 48);
      const mat = new THREE.MeshBasicMaterial({
        color: 0xc62828,
        transparent: true,
        opacity: 0,
        side: THREE.DoubleSide,
        depthWrite: false,
      });
      const malla = new THREE.Mesh(geo, mat);
      malla.position.copy(pos.clone().multiplyScalar(1.045));
      malla.lookAt(pos.clone().multiplyScalar(2));
      malla.userData = { fase: i / 3 };
      this.escena.add(malla);
      this.ondasNarino.push(malla);
    }
  }

  // -------------------- TELECONEXIÓN PACÍFICO → NARIÑO --------------------
  // DECISIÓN: una curva que sale del centro de la anomalía y termina en Nariño.
  // Su opacidad sube con el ONI: explica visualmente "cómo lo que pasa allá
  // afecta acá".
  _construirTeleconexion() {
    const inicio = latLngAVector3(0, -145, 1.02);
    const fin = latLngAVector3(1.2, -77.3, 1.02);

    // Punto de control para curva sobre la superficie (eleva el arco)
    const medio = inicio.clone().add(fin).multiplyScalar(0.5).normalize().multiplyScalar(1.55);
    const curva = new THREE.QuadraticBezierCurve3(inicio, medio, fin);
    const puntos = curva.getPoints(80);

    const geo = new THREE.BufferGeometry().setFromPoints(puntos);
    const mat = new THREE.LineBasicMaterial({
      color: 0xE8A020,
      transparent: true,
      opacity: 0,
      linewidth: 2,
    });
    this.teleconexion = new THREE.Line(geo, mat);
    this.escena.add(this.teleconexion);

    // Pequeñas partículas que fluyen a lo largo de la curva (efecto "energía")
    const cantidadFlujo = 30;
    const posicionesFlujo = new Float32Array(cantidadFlujo * 3);
    for (let i = 0; i < cantidadFlujo; i++) {
      const t = i / cantidadFlujo;
      const p = curva.getPoint(t);
      posicionesFlujo[i*3] = p.x; posicionesFlujo[i*3+1] = p.y; posicionesFlujo[i*3+2] = p.z;
    }
    const geoF = new THREE.BufferGeometry();
    geoF.setAttribute('position', new THREE.BufferAttribute(posicionesFlujo, 3));
    const matF = new THREE.PointsMaterial({
      size: 0.04,
      color: 0xE8A020,
      transparent: true,
      opacity: 0,
      sizeAttenuation: true,
      depthWrite: false,
    });
    this.flujoTeleconexion = new THREE.Points(geoF, matF);
    this.flujoTeleconexion.userData.curva = curva;
    this.flujoTeleconexion.userData.faseT = new Float32Array(cantidadFlujo);
    for (let i = 0; i < cantidadFlujo; i++) {
      this.flujoTeleconexion.userData.faseT[i] = i / cantidadFlujo;
    }
    this.escena.add(this.flujoTeleconexion);
  }

  _crearFlechaAlisio(material) {
    // Flecha sencilla: cono pequeño + cilindro
    const grupo = new THREE.Group();
    const cuerpo = new THREE.Mesh(
      new THREE.CylinderGeometry(0.06, 0.06, 0.6, 8),
      material
    );
    cuerpo.rotation.x = Math.PI / 2;
    cuerpo.position.z = -0.05;
    grupo.add(cuerpo);
    const punta = new THREE.Mesh(
      new THREE.ConeGeometry(0.18, 0.35, 10),
      material
    );
    punta.rotation.x = -Math.PI / 2;
    punta.position.z = -0.42;
    grupo.add(punta);
    return grupo;
  }

  // -------------------- MARCADOR NARIÑO --------------------
  _construirMarcadorNarino() {
    // DECISIÓN: usamos un pequeño cono que apunta hacia adentro del globo (estilo pin).
    const pos = latLngAVector3(1.2, -77.3, 1.0);
    const normal = pos.clone().normalize();

    this.marcadorGrupo = new THREE.Group();
    this.escena.add(this.marcadorGrupo);

    // DECISIÓN: el marcador (esfera + asta) usa material transparente con
    // opacidad media (0.55) para no tapar el mapa GeoJSON de los municipios
    // cuando se ve la capa de Nariño. La esfera se hace ligeramente más
    // pequeña (0.018 vs 0.025) y se eleva un poco más para flotar sobre los
    // polígonos sin ocluirlos.
    const matMarc = new THREE.MeshStandardMaterial({
      color: COLORES_ALERTA.verde,
      emissive: 0x000000,
      transparent: true,
      opacity: 0.55,
      depthWrite: false,
    });
    this.materialMarcador = matMarc;
    const esfera = new THREE.Mesh(new THREE.SphereGeometry(0.018, 16, 16), matMarc);
    esfera.position.copy(pos.clone().multiplyScalar(1.055));
    this.marcadorGrupo.add(esfera);

    // Asta del pin (también semitransparente)
    const asta = new THREE.Mesh(
      new THREE.CylinderGeometry(0.0035, 0.0035, 0.06, 8),
      matMarc
    );
    asta.position.copy(pos.clone().multiplyScalar(1.025));
    asta.lookAt(pos.clone().multiplyScalar(2));
    asta.rotateX(Math.PI / 2);
    this.marcadorGrupo.add(asta);

    // Halo pulsante (anillo)
    const haloGeo = new THREE.RingGeometry(0.04, 0.08, 32);
    const haloMat = new THREE.MeshBasicMaterial({
      color: COLORES_ALERTA.verde,
      transparent: true,
      opacity: 0.0,
      side: THREE.DoubleSide,
      depthWrite: false,
    });
    this.halo = new THREE.Mesh(haloGeo, haloMat);
    this.halo.position.copy(pos.clone().multiplyScalar(1.05));
    this.halo.lookAt(pos.clone().multiplyScalar(2));
    this.marcadorGrupo.add(this.halo);
  }

  // -------------------- ESTRELLAS DE FONDO --------------------
  _construirEstrellas() {
    const cantidad = 1800;
    const posiciones = new Float32Array(cantidad * 3);
    for (let i = 0; i < cantidad; i++) {
      const r = 40 + Math.random() * 20;
      const phi = Math.acos(2 * Math.random() - 1);
      const theta = Math.random() * Math.PI * 2;
      posiciones[i*3]   = r * Math.sin(phi) * Math.cos(theta);
      posiciones[i*3+1] = r * Math.cos(phi);
      posiciones[i*3+2] = r * Math.sin(phi) * Math.sin(theta);
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(posiciones, 3));
    const mat = new THREE.PointsMaterial({ color: 0xffffff, size: 0.18, sizeAttenuation: true, transparent: true, opacity: 0.7 });
    this.estrellas = new THREE.Points(geo, mat);
    this.escena.add(this.estrellas);
  }

  // -------------------- API PÚBLICA --------------------

  /**
   * Actualiza el estado del globo según el mes seleccionado.
   * @param {object} mesGlobal - entrada de global.meses[i]
   * @param {object} mesNarino - entrada de narino.meses[i]
   */
  actualizarMes(mesGlobal, mesNarino) {
    this.estado.oniObjetivo = mesGlobal.oni ?? 0;
    this.estado.nivelAlerta = mesNarino.nivel_alerta_general || 'verde';
    this.estado.colorMarcadorObjetivo = new THREE.Color(COLORES_ALERTA[this.estado.nivelAlerta] ?? COLORES_ALERTA.verde);
    this.estado.mesActual = mesGlobal.mes || '';
    // Capa estacional de foco de calor costero (ago-dic 2026).
    // Si el usuario la ocultó desde el select, el objetivo queda en 0.
    this._focoCalorObjetivo = this._focoCalorBloqueado
      ? 0
      : this._intensidadFocoCalor(this.estado.mesActual);
  }

  irACamara(nombre) {
    let destino;
    if (nombre === 'mecanismo') destino = this.posCamaraMecanismo;
    else if (nombre === 'local') destino = this.posCamaraLocal;
    else destino = this.posCamaraDefault;

    // DECISIÓN: curva Bezier 3D para una transición cinemática (no lineal).
    // El punto de control queda más lejos del globo (radio 5.5) para que la
    // cámara "barra" el globo en arco en vez de cortar derecho.
    const desde = this.camara.position.clone();
    const hacia = destino.clone();
    const medio = desde.clone().add(hacia).multiplyScalar(0.5).normalize().multiplyScalar(5.5);
    const curva = new THREE.QuadraticBezierCurve3(desde, medio, hacia);

    this._camaraTransicion = {
      curva,
      t: 0,
      duracion: this.reducedMotion ? 0.1 : 1.4,
    };
    this.controles.autoRotate = false;
  }

  limpiarResaltadoMecanismo() {
    this._escalaAlisiosForzada = undefined;
  }

  resaltarMecanismo(paso) {
    // 1: estado normal → 2: alisios débiles → 3: calentamiento → 4: atmósfera → 5: Nariño
    const evento = new CustomEvent('mecanismo-paso', { detail: { paso } });
    window.dispatchEvent(evento);

    if (paso === 1) {
      this._destacarAlisios(1.0);
      this._forzarOni(0.0);
      this.irACamara('mecanismo');
    } else if (paso === 2) {
      this._destacarAlisios(0.5);
      this._forzarOni(0.2);
      this.irACamara('mecanismo');
    } else if (paso === 3) {
      this._destacarAlisios(0.25);
      this._forzarOni(1.0);
      this.irACamara('mecanismo');
    } else if (paso === 4) {
      this._destacarAlisios(0.15);
      this._forzarOni(1.4);
      this.irACamara('mecanismo');
    } else if (paso === 5) {
      this._destacarAlisios(0.1);
      this._forzarOni(1.4);
      this.irACamara('local');
    }
  }

  setModoLigero(activo) {
    this.modoLigero = activo;
    this.estrellas.visible = !activo;
    this.particulasAlisios.visible = !activo;
    this.flujoTeleconexion.visible = !activo;
    this.heatMap.visible = !activo;
    if (this.focoCalor) this.focoCalor.visible = !activo;
    this.nubes.visible = !activo;
    this.ondasNarino.forEach((o) => { o.visible = !activo; });
    this.controles.autoRotate = !activo && !this.reducedMotion;
    this.renderer.setPixelRatio(activo ? 1 : Math.min(window.devicePixelRatio, 2));
  }

  redimensionar() {
    const w = this.contenedor.clientWidth;
    const h = this.contenedor.clientHeight;
    this.camara.aspect = w / h;
    this.camara.updateProjectionMatrix();
    this.renderer.setSize(w, h);
  }

  // ============= EPISODIO HISTÓRICO (representación FÍSICA del fenómeno) =============
  // Reproduce un episodio histórico ENSO con FIDELIDAD al mecanismo real:
  //   1. Fuerza el ONI del globo a la magnitud pico del episodio, lo que
  //      automáticamente activa los sistemas existentes:
  //        · anomalía SST del Pacífico (shader, intensidad ∝ ONI)
  //        · alisios débiles (las flechas se atenúan al subir ONI)
  //        · partículas de alisios frenan y se vuelven azuladas
  //        · nubes vaporosas se desplazan al ESTE (Pacífico central → Sudamérica)
  //        · curva de teleconexión Pacífico → Nariño aparece
  //        · marcador de Nariño cambia color por nivel de alerta
  //   2. Sobreimpone un "stream" cálido a lo largo del ecuador del Pacífico
  //      centro-oriental (Niño-3.4, lat 5°S a 5°N, lng -170° a -120°) que es
  //      EXACTAMENTE la región donde se mide la anomalía. Las partículas
  //      fluyen W→E (sentido del desplazamiento de aguas cálidas en El Niño).
  //   3. Cuando ONI ≥ 1.5 (fuerte+) añade halo pulsante sobre Niño-3.4.
  reproducirEpisodioHistorico(episodio) {
    if (!episodio) return;
    this.limpiarEpisodioHistorico();

    const oni = Number(episodio.oni_pico || 1);
    const categoria = episodio.categoria || 'moderado';
    const colorMap = {
      debil:       0x9ccc65,
      moderado:    0xfdd835,
      fuerte:      0xef6c00,
      muy_fuerte:  0xd32f2f,
      proyectado:  0x1A5276,
    };
    const color = new THREE.Color(colorMap[categoria] || 0xfdd835);

    // PASO 1 — Guardar ONI previo y forzar el del episodio. Esto dispara
    // las animaciones existentes (alisios, anomalía SST, nubes, teleconexión)
    // a la intensidad correcta del evento histórico.
    this._oniGuardado = this.estado.oniObjetivo;
    this._forzarOni(oni);

    // PASO 2 — Stream cálido a lo largo de la región Niño-3.4
    // (zona oficial donde NOAA mide el ONI). El flujo es W→E (signo positivo
    // en longitud), reflejando el desplazamiento real de aguas cálidas.
    const LATS_NINO34 = { min: -5, max: 5 };
    const LNGS_NINO34 = { min: -170, max: -120 };

    // Cantidad de partículas escala con intensidad (60 mínimo a 320 muy fuerte)
    const cantidad = Math.round(60 + 260 * Math.min(1, oni / 2.6));
    const posiciones = new Float32Array(cantidad * 3);
    const offsets = new Float32Array(cantidad);
    for (let i = 0; i < cantidad; i++) {
      offsets[i] = Math.random();
      // Distribución inicial homogénea por toda la región Niño-3.4
      const lat = LATS_NINO34.min + Math.random() * (LATS_NINO34.max - LATS_NINO34.min);
      const lng = LNGS_NINO34.min + Math.random() * (LNGS_NINO34.max - LNGS_NINO34.min);
      const p = latLngAVector3(lat, lng, 1.018);
      posiciones[i*3]     = p.x;
      posiciones[i*3 + 1] = p.y;
      posiciones[i*3 + 2] = p.z;
    }

    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(posiciones, 3));

    const mat = new THREE.PointsMaterial({
      color,
      size: 0.024 + 0.014 * Math.min(1, oni / 2.6),
      transparent: true,
      opacity: 0.88,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
    });

    const puntos = new THREE.Points(geo, mat);
    this.escena.add(puntos);

    // PASO 3 — Halo pulsante sobre el centro de Niño-3.4 (lat 0°, lng -145°)
    // visible solo en episodios fuerte+. Indica el "epicentro" de la anomalía.
    let halo = null;
    if (oni >= 1.2) {
      const haloGeo = new THREE.RingGeometry(0.08, 0.14, 48);
      const haloMat = new THREE.MeshBasicMaterial({
        color, transparent: true, opacity: 0.55,
        side: THREE.DoubleSide, depthWrite: false,
        blending: THREE.AdditiveBlending,
      });
      halo = new THREE.Mesh(haloGeo, haloMat);
      halo.position.copy(latLngAVector3(0, -145, 1.025));
      halo.lookAt(0, 0, 0);
      this.escena.add(halo);
    }

    this._episodioHistorico = {
      puntos, halo,
      cantidad, offsets,
      latsRange: LATS_NINO34,
      lngsRange: LNGS_NINO34,
      tInicio: performance.now(),
      // Velocidad del flujo cálido W→E escala con ONI (más alto = más rápido)
      velocidadLngPorSeg: 8 + 12 * Math.min(1, oni / 2.6),
      intensidad: Math.min(1.5, oni / 1.5),
      categoria,
      oni,
    };

    // Cámara a vista de mecanismo para ver el Pacífico ecuatorial
    // (no a Nariño; el evento histórico se ve mejor desde el Pacífico).
    this.irACamara('mecanismo');
  }

  limpiarEpisodioHistorico() {
    if (!this._episodioHistorico) return;
    const { puntos, halo } = this._episodioHistorico;
    if (puntos) {
      this.escena.remove(puntos);
      puntos.geometry.dispose();
      puntos.material.dispose();
    }
    if (halo) {
      this.escena.remove(halo);
      halo.geometry.dispose();
      halo.material.dispose();
    }
    this._episodioHistorico = null;
    // Restaurar ONI previo si lo habíamos guardado
    if (typeof this._oniGuardado === 'number') {
      this._forzarOni(this._oniGuardado);
      this._oniGuardado = undefined;
    }
  }

  // Convierte lat/lng (grados) → Vector3 en la esfera del globo
  _latLngVec(lat, lng, radio = 1.0) {
    return latLngAVector3(lat, lng, radio);
  }

  // -------------------- INTERNOS --------------------

  _forzarOni(v) { this.estado.oniObjetivo = v; }

  _destacarAlisios(factor) {
    // Al resaltar el mecanismo, sobreescribimos la escala objetivo
    this._escalaAlisiosForzada = factor;
  }

  _registrarEventos() {
    window.addEventListener('resize', () => this.redimensionar());
  }

  _loop() {
    let ultimo = performance.now();
    const tick = (ahora) => {
      const dt = Math.min(0.05, (ahora - ultimo) / 1000);
      ultimo = ahora;

      // Interpolación suave de ONI
      this.estado.oni += (this.estado.oniObjetivo - this.estado.oni) * Math.min(1, dt * 2.5);

      // Anomalía: color e intensidad
      const color = colorPorOni(this.estado.oni);
      this.uniformsAnomalia.uColor.value.copy(color);
      this.uniformsAnomalia.uIntensidad.value = Math.max(0.05, Math.min(1, this.estado.oni / 1.5));
      this.uniformsAnomalia.uTiempo.value += dt;

      // Alisios: escala disminuye al subir ONI
      const factorOni = Math.max(0.15, 1 - this.estado.oni * 0.6);
      const escalaForzada = this._escalaAlisiosForzada;
      const factor = (escalaForzada !== undefined) ? escalaForzada : factorOni;
      this.alisios.flechas.forEach((f) => {
        const objetivo = f.userData.escalaBase * factor;
        const actual = f.scale.x;
        const nuevo = actual + (objetivo - actual) * Math.min(1, dt * 3);
        f.scale.setScalar(nuevo);
      });
      // Color: blanco → amarillento al debilitarse
      const colorAli = new THREE.Color().lerpColors(
        new THREE.Color(0xfff5b0), new THREE.Color(0xffffff), factor
      );
      this.alisios.flechas.forEach((f) => {
        f.children.forEach((m) => { if (m.material) m.material.color = colorAli; });
      });

      // Partículas de alisios: avanzan de E a O. En ONI alto frenan y se enfrían.
      // DECISIÓN (analisis-enso-particulas, Corrección 2): El Niño debilita
      // los alisios pero NO los invierte de forma perceptible. Con ONI >= 1.33
      // el flujo llega a 0 (alisios detenidos), nunca negativo. Antes
      // permitía hasta -0.2 generando flujo retrógrado incoherente.
      const velocidadFlujo = Math.max(0, 1.2 - this.estado.oni * 0.9);
      const posicionesAli = this.particulasAlisios.geometry.attributes.position.array;
      const coloresAli = this.particulasAlisios.geometry.attributes.color.array;
      const colorCalido = new THREE.Color(0xffffff);
      const colorFrio = new THREE.Color(0x6db4ff);
      const mezclaColor = Math.min(1, this.estado.oni / 1.2);
      for (let i = 0; i < this._particulasAlisios.length; i++) {
        const p = this._particulasAlisios[i];
        p.lng -= velocidadFlujo * p.velocidadBase * dt * 8;
        // DECISIÓN (analisis-enso-particulas, Corrección 1): Reciclar dentro
        // de la banda del Pacífico ecuatorial Niño-3.4 ampliada
        // (-175° oeste a -100° este, frente a Perú/Ecuador). El reciclaje
        // anterior llegaba a -80° (territorio continental colombiano).
        if (p.lng < -175) p.lng = -100;
        if (p.lng > -100) p.lng = -175;
        const v = latLngAVector3(p.lat, p.lng, p.radio);
        posicionesAli[i*3] = v.x; posicionesAli[i*3+1] = v.y; posicionesAli[i*3+2] = v.z;
        const c = new THREE.Color().lerpColors(colorCalido, colorFrio, mezclaColor);
        coloresAli[i*3] = c.r; coloresAli[i*3+1] = c.g; coloresAli[i*3+2] = c.b;
      }
      this.particulasAlisios.geometry.attributes.position.needsUpdate = true;
      this.particulasAlisios.geometry.attributes.color.needsUpdate = true;

      // DECISIÓN: declarar pulsa AQUÍ (antes de las ondas de Nariño que lo
      // usan). Antes estaba más abajo y caía en temporal dead zone → mataba
      // el render entero.
      const pulsa = (this.estado.nivelAlerta === 'naranja' || this.estado.nivelAlerta === 'rojo');

      // Teleconexión: opacidad sube con ONI
      const opTele = Math.min(0.8, Math.max(0, (this.estado.oni - 0.3) * 0.85));
      this.teleconexion.material.opacity = opTele;
      this.flujoTeleconexion.material.opacity = opTele;

      // ====== AGUA: Heat map del Pacífico (Niño-3.4) — color + drift sutil ======
      const coloresHeat = this.heatMap.geometry.attributes.color.array;
      const posicionesHeat = this.heatMap.geometry.attributes.position.array;
      const oniN = Math.min(1.5, Math.max(0, this.estado.oni));
      // DECISIÓN (req. usuario): drift sutil W→E que representa el desplazamiento
      // de aguas cálidas durante El Niño (ondas Kelvin). Base ~0.4°/s y se suma
      // hasta +1.6°/s con ONI máximo. Es ~6× más lento que los alisios.
      const driftAguaPorSeg = 0.4 + 1.2 * oniN;
      const tSeg = ahora * 0.001;
      for (let i = 0; i < this._heatPuntos.length; i++) {
        const pt = this._heatPuntos[i];
        // Drift longitudinal (W→E, lng creciente)
        pt.lng += driftAguaPorSeg * pt.velocidadBase * dt;
        // Reciclar dentro de la región Niño-3.4 [-170°, -110°]
        if (pt.lng > -110) pt.lng = -170;
        // Wobble latitudinal sutil (parece turbulencia, no flujo aéreo)
        pt.lat = pt.latBase + Math.sin(tSeg * 0.6 + pt.faseJitter) * 0.4;

        const v = latLngAVector3(pt.lat, pt.lng, pt.radio);
        posicionesHeat[i*3]     = v.x;
        posicionesHeat[i*3 + 1] = v.y;
        posicionesHeat[i*3 + 2] = v.z;

        // Color: distancia al centro de la región (lat 0, lng -140)
        const dlat = pt.lat / 8;
        const dlng = (pt.lng + 140) / 30;
        const proximidad = 1 - Math.min(1, Math.sqrt(dlat*dlat + dlng*dlng) * 0.7);
        const temp = oniN * (0.4 + 0.6 * proximidad) + pt.ruido * 0.15;
        const c = colorPorOni(temp);
        coloresHeat[i*3] = c.r; coloresHeat[i*3+1] = c.g; coloresHeat[i*3+2] = c.b;
      }
      this.heatMap.geometry.attributes.color.needsUpdate = true;
      this.heatMap.geometry.attributes.position.needsUpdate = true;
      this.heatMap.material.opacity = 0.35 + Math.min(0.45, oniN * 0.35);

      // ====== FOCO DE CALOR COSTERO (capa estacional ago-dic 2026) ======
      // Interpolamos opacidad actual hacia el objetivo (fade in/out ~0.8s).
      if (this.focoCalor) {
        this._focoCalorOpacidad +=
          (this._focoCalorObjetivo - this._focoCalorOpacidad) * Math.min(1, dt * 1.2);
        const opacFC = Math.max(0, this._focoCalorOpacidad);
        this.focoCalor.material.opacity = opacFC * 0.85;
        this.focoCalor.visible = opacFC > 0.005;

        if (opacFC > 0.05 && this._focoCalorPuntos.length) {
          // DECISIÓN (req. usuario): drift sutil hacia el OESTE (lng decrece).
          // Velocidad: 0.35 °/s — recorre el ancho de la mancha en ~75 s.
          // Reciclaje: al cruzar el límite oeste reaparece en el este.
          const driftOeste = 0.35 * dt;
          const lngMin = this._fcLngMin;
          const lngMax = this._fcLngMax;
          const posFC = this.focoCalor.geometry.attributes.position.array;
          const tSecFC = ahora * 0.001;

          for (let i = 0; i < this._focoCalorPuntos.length; i++) {
            const pt = this._focoCalorPuntos[i];
            // Drift global hacia el oeste sobre el lngBase de la partícula
            pt.lngBase -= driftOeste;
            if (pt.lngBase < lngMin) pt.lngBase = lngMax;
            // Ondulación sutil sobre el base (la mancha respira)
            const ondLat = pt.latBase + Math.sin(tSecFC * 0.7 + pt.fase) * 0.4;
            const ondLng = pt.lngBase + Math.cos(tSecFC * 0.5 + pt.fase) * 0.6;
            const v = latLngAVector3(ondLat, ondLng, pt.radio);
            posFC[i*3]     = v.x;
            posFC[i*3 + 1] = v.y;
            posFC[i*3 + 2] = v.z;
          }
          this.focoCalor.geometry.attributes.position.needsUpdate = true;
        }
      }

      // Nubes: en ONI bajo son densas; con ONI alto se disipan y se mueven al este.
      // DECISIÓN (analisis-enso-particulas, Corrección 3B): interpolamos
      // lngActual hacia el lngObjetivo cada frame, suavizando la transición
      // entre meses (antes el salto era instantáneo y rompía la coherencia).
      const opacNube = Math.max(0.05, 0.7 - oniN * 0.55);
      this._nubes.forEach((n) => {
        n.material.opacity = opacNube;
        const lngObjetivo = n.userData.lngBase + oniN * 25;
        n.userData.lngActual += (lngObjetivo - n.userData.lngActual) * Math.min(1, dt * 1.5);
        const v = latLngAVector3(n.userData.lat, n.userData.lngActual, 1.06);
        n.position.copy(v);
      });

      // Ondas en Nariño: pulsan en naranja/rojo
      if (pulsa && !this.reducedMotion) {
        const ahoraSeg = ahora * 0.001;
        this.ondasNarino.forEach((onda) => {
          const periodo = 2.4;
          let t = ((ahoraSeg / periodo) + onda.userData.fase) % 1;
          // Expansión 1× a 3×; opacidad cae con la expansión
          onda.scale.setScalar(1 + t * 2.5);
          onda.material.opacity = (1 - t) * 0.7;
          onda.material.color.copy(this.estado.colorMarcadorActual);
        });
      } else {
        this.ondasNarino.forEach((o) => { o.material.opacity = 0; });
      }

      // Animar flujo de la teleconexión
      const curva = this.flujoTeleconexion.userData.curva;
      const fases = this.flujoTeleconexion.userData.faseT;
      const posF = this.flujoTeleconexion.geometry.attributes.position.array;
      for (let i = 0; i < fases.length; i++) {
        fases[i] = (fases[i] + dt * 0.25) % 1;
        const p = curva.getPoint(fases[i]);
        posF[i*3] = p.x; posF[i*3+1] = p.y; posF[i*3+2] = p.z;
      }
      this.flujoTeleconexion.geometry.attributes.position.needsUpdate = true;

      // Marcador: interpolar color
      this.estado.colorMarcadorActual.lerp(this.estado.colorMarcadorObjetivo, Math.min(1, dt * 3));
      this.materialMarcador.color.copy(this.estado.colorMarcadorActual);
      this.materialMarcador.emissive.copy(this.estado.colorMarcadorActual).multiplyScalar(0.35);
      this.halo.material.color.copy(this.estado.colorMarcadorActual);

      // Halo: pulso solo en naranja/rojo (pulsa ya declarado arriba)
      if (pulsa && !this.reducedMotion) {
        const t = ahora * 0.003;
        const op = 0.45 + 0.35 * Math.sin(t * 2);
        this.halo.material.opacity = op;
        const esc = 1 + 0.25 * Math.sin(t * 2);
        this.halo.scale.setScalar(esc);
      } else {
        this.halo.material.opacity = 0;
        this.halo.scale.setScalar(1);
      }

      // Transición de cámara (curva Bezier con easing)
      if (this._camaraTransicion) {
        const tr = this._camaraTransicion;
        tr.t += dt / tr.duracion;
        const k = Math.min(1, tr.t);
        const ease = k < 0.5 ? 2*k*k : 1 - Math.pow(-2*k + 2, 2)/2;
        const p = tr.curva.getPoint(ease);
        this.camara.position.copy(p);
        this.camara.lookAt(0, 0, 0);
        if (k >= 1) this._camaraTransicion = null;
      }

      // Episodio histórico activo: flujo W→E de aguas cálidas a lo largo
      // de la región Niño-3.4 (mecanismo real del fenómeno El Niño).
      if (this._episodioHistorico) {
        const ep = this._episodioHistorico;
        const tSec = (performance.now() - ep.tInicio) / 1000;

        const pos = ep.puntos.geometry.attributes.position;
        for (let i = 0; i < ep.cantidad; i++) {
          // Cada partícula tiene un offset propio + avanza con t para
          // distribuir el movimiento (no todas en fase).
          let lng = ep.lngsRange.min +
            (((ep.offsets[i] + tSec * ep.velocidadLngPorSeg / 360) * 360)
              % (ep.lngsRange.max - ep.lngsRange.min));
          // El offset[i] determina la latitud fija de cada partícula
          // (estratificación: cada partícula se mueve en su "carril")
          const lat = ep.latsRange.min +
            ((ep.offsets[i] * 7919) % 1) * (ep.latsRange.max - ep.latsRange.min);
          const p = latLngAVector3(lat, lng, 1.018);
          pos.array[i*3]     = p.x;
          pos.array[i*3 + 1] = p.y;
          pos.array[i*3 + 2] = p.z;
        }
        pos.needsUpdate = true;

        // Halo pulsa (visible solo si fue creado, ONI >= 1.2)
        if (ep.halo) {
          const fase = tSec * 1.2;
          const escala = 0.9 + 0.25 * Math.sin(fase);
          ep.halo.scale.set(escala, escala, escala);
          ep.halo.material.opacity = 0.4 + 0.25 * Math.sin(fase + 0.6);
        }
      }

      this.controles.update();
      this.renderer.render(this.escena, this.camara);
      requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  }
}
