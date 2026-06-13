// =====================================================================
// explicativo.js — Panel lateral del mecanismo del fenómeno.
// 5 pasos, cada uno con cámara cinemática + highlight + barra de progreso.
// Soporta avance automático con auto-play.
// =====================================================================

export class ModoExplicativo {
  constructor({ datos, globo, timeline }) {
    this.datos = datos;
    this.globo = globo;
    this.timeline = timeline;
    this.paso = 1;
    this.totalPasos = (datos.mecanismo?.como_se_genera || []).length || 5;
    this.autoTimer = null;
    this.autoIntervaloMs = 3500;

    this.drawer = document.getElementById('modalExplicativo');
    this.botonAbrir = document.getElementById('botonExplicativo');
    this.botonCerrar = document.getElementById('botonCerrarExplicativo');
    this.btnAnterior = document.getElementById('btnPasoAnterior');
    this.btnSiguiente = document.getElementById('btnPasoSiguiente');
    this.btnAuto = document.getElementById('btnAutoPaso');
    this.iconoAuto = document.getElementById('iconoAuto');
    this.indicador = document.getElementById('explicativoIndicador');
    this.barra = document.getElementById('explicativoBarra');
    this.queProduce = document.getElementById('explicativoQueProduce');
    this.olPasos = document.getElementById('explicativoPasos');

    this._renderContenido();
    this._registrarEventos();
  }

  _renderContenido() {
    this.queProduce.textContent = this.datos.mecanismo?.que_lo_produce || '';
    this.olPasos.innerHTML = '';
    (this.datos.mecanismo?.como_se_genera || []).forEach((p) => {
      const li = document.createElement('li');
      const h4 = document.createElement('h4');
      h4.textContent = p.titulo || `Paso ${p.paso}`;
      const txt = document.createElement('p');
      txt.textContent = p.texto || '';
      li.appendChild(h4);
      li.appendChild(txt);
      this.olPasos.appendChild(li);
    });
  }

  _registrarEventos() {
    this.botonAbrir.addEventListener('click', () => this.abrir());
    this.botonCerrar.addEventListener('click', () => this.cerrar());
    this.btnAnterior.addEventListener('click', () => { this._pausarAuto(); this.irA(this.paso - 1); });
    this.btnSiguiente.addEventListener('click', () => { this._pausarAuto(); this.irA(this.paso + 1); });
    this.btnAuto.addEventListener('click', () => this._alternarAuto());

    document.addEventListener('keydown', (e) => {
      if (this.drawer.hidden) return;
      if (e.key === 'Escape') this.cerrar();
      if (e.key === 'ArrowRight') { this._pausarAuto(); this.irA(this.paso + 1); }
      if (e.key === 'ArrowLeft')  { this._pausarAuto(); this.irA(this.paso - 1); }
    });
  }

  abrir() {
    this.drawer.hidden = false;
    document.body.classList.add('drawer-abierto');
    // DECISIÓN: la escena cambia ancho con transición CSS de 0.35s. Esperamos
    // a que termine y luego redimensionamos el canvas Three.js para que el
    // globo no quede deformado.
    setTimeout(() => this.globo.redimensionar(), 400);
    this.timeline.pausar();
    this.paso = 1;
    this._aplicar();
  }

  cerrar() {
    this.drawer.hidden = true;
    document.body.classList.remove('drawer-abierto');
    setTimeout(() => this.globo.redimensionar(), 400);
    this._pausarAuto();
    this.globo.limpiarResaltadoMecanismo();
    if (typeof this._restaurar === 'function') this._restaurar();
  }

  irA(n) {
    if (n < 1) n = 1;
    if (n > this.totalPasos) {
      // Al avanzar más allá del último paso: parar auto, no cerrar bruscamente
      this._pausarAuto();
      return;
    }
    this.paso = n;
    this._aplicar();
  }

  setRestaurador(fn) { this._restaurar = fn; }

  _alternarAuto() {
    if (this.autoTimer) this._pausarAuto();
    else this._iniciarAuto();
  }

  _iniciarAuto() {
    this.iconoAuto.textContent = '⏸';
    this.btnAuto.classList.add('boton--primario');
    this.autoTimer = setInterval(() => {
      if (this.paso >= this.totalPasos) {
        this.irA(1); // loop suave
      } else {
        this.irA(this.paso + 1);
      }
    }, this.autoIntervaloMs);
  }

  _pausarAuto() {
    if (this.autoTimer) {
      clearInterval(this.autoTimer);
      this.autoTimer = null;
    }
    this.iconoAuto.textContent = '▶';
    this.btnAuto.classList.remove('boton--primario');
  }

  _aplicar() {
    const lis = this.olPasos.querySelectorAll('li');
    lis.forEach((li, i) => li.classList.toggle('activo', (i + 1) === this.paso));
    this.indicador.textContent = `${this.paso} / ${this.totalPasos}`;
    this.barra.style.width = `${(this.paso / this.totalPasos) * 100}%`;
    this.btnAnterior.disabled = (this.paso === 1);
    this.btnSiguiente.disabled = (this.paso === this.totalPasos);
    this.btnSiguiente.textContent = (this.paso === this.totalPasos) ? 'Final ✓' : 'Siguiente ▶';

    // Disparar la animación correspondiente en el globo
    this.globo.resaltarMecanismo(this.paso);

    // Scroll del paso activo al centro del panel
    const liActivo = lis[this.paso - 1];
    if (liActivo) liActivo.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}
