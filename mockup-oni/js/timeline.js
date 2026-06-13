// =====================================================================
// timeline.js — Control maestro de mes activo (play/pause/scrubber)
// =====================================================================

export class Timeline {
  constructor({ meses, onCambioMes }) {
    this.meses = meses;             // array de N meses (12, 15, ...)
    this.indice = 0;
    this.reproduciendo = false;
    this.intervaloMs = 1200;
    this._timer = null;
    this.onCambioMes = onCambioMes;
    this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    this._elementos = {
      btnAtras: document.getElementById('btnAtras'),
      btnPlay: document.getElementById('btnPlay'),
      btnAdelante: document.getElementById('btnAdelante'),
      slider: document.getElementById('sliderMes'),
      selectVelocidad: document.getElementById('selectVelocidad'),
      marcas: document.getElementById('marcasMeses'),
      divisor: document.getElementById('timelineDivisor'),
    };

    // DECISIÓN: el slider se vuelve dinámico (12 o 15 meses según el JSON).
    // Ajustamos max al número real de elementos, y la grid de marcas también.
    const max = Math.max(0, this.meses.length - 1);
    this._elementos.slider.setAttribute('max', String(max));
    this._elementos.marcas.style.gridTemplateColumns = `repeat(${this.meses.length}, 1fr)`;

    this._renderMarcas();
    this._renderDivisorObservado();
    this._registrarEventos();
    this._notificar();
  }

  _renderMarcas() {
    const ul = this._elementos.marcas;
    ul.innerHTML = '';
    this.meses.forEach((m, i) => {
      const li = document.createElement('li');
      // En ventanas de 15+ meses, mostramos año en los meses de transición (ene/dic).
      const nombre = (m.nombre_mes || '').substring(0, 3);
      const anio = m.mes ? m.mes.split('-')[0] : '';
      const debeMostrarAnio = (nombre === 'Ene' || (i === 0));
      li.textContent = debeMostrarAnio && anio ? `${nombre} ${anio.slice(2)}` : nombre;
      li.dataset.indice = String(i);
      // Marca visual: observado vs proyectado
      if (m.tipo_dato === 'proyectado') li.classList.add('marca--proyectado');
      else if (m.tipo_dato === 'observado') li.classList.add('marca--observado');
      li.addEventListener('click', () => this.irA(i));
      ul.appendChild(li);
    });
    this._actualizarMarcaActiva();
  }

  // Pinta una línea vertical en el slider donde acaba el último mes observado.
  _renderDivisorObservado() {
    if (!this._elementos.divisor) return;
    const ultimoObservado = [...this.meses].reverse().findIndex((m) => m.tipo_dato === 'observado');
    if (ultimoObservado === -1) { this._elementos.divisor.hidden = true; return; }
    // Índice real (desde el inicio)
    const idx = this.meses.length - 1 - ultimoObservado;
    // El divisor se coloca DESPUÉS de este índice — en el espacio entre éste y el siguiente.
    const max = Math.max(1, this.meses.length - 1);
    const porcentaje = ((idx + 0.5) / this.meses.length) * 100;
    this._elementos.divisor.style.left = `${porcentaje}%`;
    this._elementos.divisor.hidden = false;
    // Ofrecemos al exterior el índice del último observado (útil para gráficos)
    this.indiceUltimoObservado = idx;
  }

  _actualizarMarcaActiva() {
    const lis = this._elementos.marcas.querySelectorAll('li');
    lis.forEach((li) => {
      li.classList.toggle('activo', Number(li.dataset.indice) === this.indice);
    });
  }

  _registrarEventos() {
    this._elementos.btnAtras.addEventListener('click', () => this.atras());
    this._elementos.btnAdelante.addEventListener('click', () => this.adelante());
    this._elementos.btnPlay.addEventListener('click', () => this.alternarReproduccion());
    this._elementos.slider.addEventListener('input', (e) => {
      this.irA(parseInt(e.target.value, 10));
    });
    this._elementos.selectVelocidad.addEventListener('change', (e) => {
      this.intervaloMs = parseInt(e.target.value, 10);
      if (this.reproduciendo) {
        this._detenerTimer();
        this._iniciarTimer();
      }
    });

    // Atajos de teclado
    document.addEventListener('keydown', (e) => {
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') return;
      if (e.key === 'ArrowLeft')  this.atras();
      if (e.key === 'ArrowRight') this.adelante();
      if (e.key === ' ') { e.preventDefault(); this.alternarReproduccion(); }
    });
  }

  irA(i) {
    if (i < 0) i = 0;
    if (i > this.meses.length - 1) i = this.meses.length - 1;
    if (i === this.indice) return;
    this.indice = i;
    this._elementos.slider.value = String(i);
    this._actualizarMarcaActiva();
    this._notificar();
  }

  atras() { this.pausar(); this.irA(this.indice - 1); }
  adelante() { this.pausar(); this.irA(this.indice + 1); }

  alternarReproduccion() {
    if (this.reproduciendo) this.pausar();
    else this.reproducir();
  }

  reproducir() {
    // DECISIÓN: respetar prefers-reduced-motion → no auto-reproducir, exigir interacción manual.
    if (this.reducedMotion) return;
    this.reproduciendo = true;
    this._elementos.btnPlay.textContent = '⏸';
    this._iniciarTimer();
  }

  pausar() {
    this.reproduciendo = false;
    this._elementos.btnPlay.textContent = '▶';
    this._detenerTimer();
  }

  _iniciarTimer() {
    this._detenerTimer();
    this._timer = setInterval(() => {
      let siguiente = this.indice + 1;
      if (siguiente >= this.meses.length) siguiente = 0; // loop
      this.indice = siguiente;
      this._elementos.slider.value = String(siguiente);
      this._actualizarMarcaActiva();
      this._notificar();
    }, this.intervaloMs);
  }

  _detenerTimer() {
    if (this._timer) { clearInterval(this._timer); this._timer = null; }
  }

  _notificar() {
    if (typeof this.onCambioMes === 'function') {
      this.onCambioMes(this.indice);
    }
  }
}
