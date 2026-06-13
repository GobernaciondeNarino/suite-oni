// =====================================================================
// prevencion.js — Panel de recomendaciones de prevención (MEJORA al spec)
//
// DECISIÓN: el catálogo de recomendaciones no estaba en el JSON entregado.
// Se construye localmente con base en buenas prácticas de gestión de riesgo
// del Plan de Contingencia (Ley 1523 de 2012) y se modulan por nivel de
// alerta. Para cada nivel mostramos acciones por sector (hídrico, agrícola,
// salud, incendios, comunidad) y la lista de riesgos por subregión viene
// del propio JSON (narino.subregiones).
// =====================================================================

const RECOMENDACIONES = {
  verde: {
    titulo: 'Normalidad — preparación preventiva',
    descripcion: 'Condiciones normales. Es el momento de consolidar planes y reservas.',
    acciones: {
      hidrico: [
        'Hacer inventario de fuentes hídricas y acueductos rurales.',
        'Revisar planes de uso eficiente del agua (PUEAA) y reservas.',
      ],
      agricola: [
        'Identificar cultivos vulnerables y zonas de riesgo histórico.',
        'Capacitar a productores en prácticas de conservación de suelos.',
      ],
      salud: [
        'Revisar disponibilidad de suero oral y campañas de prevención EDA/IRA.',
      ],
      incendios: [
        'Mantenimiento de equipos de bomberos y brigadas comunitarias.',
        'Identificar zonas con vegetación seca histórica.',
      ],
      comunidad: [
        'Informar a la población sobre el Plan de Contingencia.',
      ],
    },
  },
  amarillo: {
    titulo: 'Vigilancia — alistamiento de recursos',
    descripcion: 'Se observan condiciones que podrían escalar. Activar protocolos de alistamiento.',
    acciones: {
      hidrico: [
        'Monitoreo diario de caudales y niveles de embalses.',
        'Coordinar con empresas de servicios públicos un plan de racionamiento progresivo.',
      ],
      agricola: [
        'Recomendar siembras escalonadas y variedades resistentes a sequía.',
        'Promover sistemas de riego eficientes.',
      ],
      salud: [
        'Reforzar puestos de salud rurales con sales de rehidratación e insumos para IRA.',
        'Iniciar vigilancia epidemiológica activa.',
      ],
      incendios: [
        'Activar puestos de mando avanzados en zonas críticas.',
        'Quemas controladas suspendidas; fortalecer comunicación de riesgo.',
      ],
      comunidad: [
        'Difundir mensajes preventivos por radios comunitarias.',
        'Capacitación a juntas de acción comunal en gestión del riesgo.',
      ],
    },
  },
  naranja: {
    titulo: 'Alerta — respuesta operativa',
    descripcion: 'Afectaciones probables. Activar comités de gestión del riesgo.',
    acciones: {
      hidrico: [
        'Activar plan de racionamiento de acueductos en municipios críticos.',
        'Habilitar carro-tanques y puntos de distribución de agua.',
        'Articular con CORPONARIÑO seguimiento al nivel de caudales.',
      ],
      agricola: [
        'Activar Mesa Departamental Agroclimática.',
        'Apoyo técnico para mitigación de pérdidas en papa, café, cacao y pastos.',
        'Censo de productores afectados para apoyo posterior.',
      ],
      salud: [
        'Brigadas móviles de salud en zonas rurales dispersas.',
        'Distribución masiva de suero oral y mosquiteros tratados.',
        'Vigilancia reforzada de EDA, IRA y enfermedades vectoriales.',
      ],
      incendios: [
        'Prohibición total de quemas; vigilancia satelital de focos.',
        'Pre-posicionar equipos en zonas críticas (cordillera, Pacífico transición).',
      ],
      comunidad: [
        'Activar Sala de Crisis Departamental.',
        'Coordinar con UNGRD y organismos de socorro.',
        'Comunicación constante a la ciudadanía por todos los canales.',
      ],
    },
  },
  rojo: {
    titulo: 'Emergencia — respuesta máxima',
    descripcion: 'Condiciones críticas. Activar plan de contingencia completo y declarar calamidad si corresponde.',
    acciones: {
      hidrico: [
        'Distribución prioritaria de agua potable por carro-tanques.',
        'Articulación con Min Vivienda y Ejército para apoyo logístico.',
        'Restricción severa de usos no esenciales del agua.',
      ],
      agricola: [
        'Activar mecanismos de apoyo económico a productores afectados.',
        'Gestión ante Fondo de Calamidades y Min Agricultura.',
        'Bancos de alimentos para población vulnerable.',
      ],
      salud: [
        'Atención humanitaria activa: alimentación, agua, salud.',
        'Refuerzo hospitalario por aumento de IRA y EDA.',
        'Protección especial a niños, adultos mayores y comunidades indígenas.',
      ],
      incendios: [
        'Despliegue máximo de bomberos, brigadas y apoyo aéreo si se requiere.',
        'Evacuaciones preventivas en zonas con incendios incontrolables.',
      ],
      comunidad: [
        'Declaración de calamidad pública si los daños lo justifican (Ley 1523).',
        'Activación de albergues temporales.',
        'Coordinación 24/7 con todas las entidades del SNGRD.',
      ],
    },
  },
};

const ETIQUETAS_SECCION = {
  hidrico: '💧 Recurso hídrico',
  agricola: '🌾 Agricultura',
  salud: '🏥 Salud pública',
  incendios: '🔥 Incendios forestales',
  comunidad: '🤝 Comunidad y gobierno',
};

const CLASES_NIVEL = {
  verde: 'alerta-verde',
  amarillo: 'alerta-amarillo',
  naranja: 'alerta-naranja',
  rojo: 'alerta-rojo',
};

export class PanelPrevencion {
  constructor(datos) {
    this.datos = datos;
    this.contenedor = document.getElementById('prevencionContenido');
    this.intro = document.getElementById('prevencionIntro');
    this.nivelTexto = document.getElementById('nivelAlertaTexto');
    this.listaSubregiones = document.getElementById('listaSubregiones');
    this._renderSubregiones();
    this._cablearAcordeonPrincipal();
  }

  // Acordeón externo: el título "Recomendaciones de prevención" expande/colapsa
  // TODA la sección. Los 5 sub-sectores quedan visibles como bloques fijos.
  _cablearAcordeonPrincipal() {
    const cabecera = document.getElementById('recomendacionesCabecera');
    const seccion = document.getElementById('recomendacionesAcordeon');
    if (!cabecera || !seccion) return;
    cabecera.addEventListener('click', () => {
      const activo = seccion.classList.toggle('activo');
      cabecera.setAttribute('aria-expanded', activo ? 'true' : 'false');
    });
    // Estado inicial: expandido
    seccion.classList.add('activo');
    cabecera.setAttribute('aria-expanded', 'true');
  }

  _renderSubregiones() {
    const subs = this.datos.narino?.subregiones || [];
    this.listaSubregiones.innerHTML = '';
    subs.forEach((s) => {
      const li = document.createElement('li');
      const strong = document.createElement('strong');
      strong.textContent = s.nombre;
      li.appendChild(strong);
      if (Array.isArray(s.riesgos) && s.riesgos.length) {
        const ul = document.createElement('ul');
        s.riesgos.forEach((r) => {
          const li2 = document.createElement('li');
          li2.textContent = r;
          ul.appendChild(li2);
        });
        li.appendChild(ul);
      }
      this.listaSubregiones.appendChild(li);
    });
  }

  actualizarMes(indice) {
    const mes = this.datos.narino.meses[indice];
    const nivel = mes.nivel_alerta_general || 'verde';
    const leyenda = this.datos.leyenda_alertas?.[nivel] || nivel;
    const rec = RECOMENDACIONES[nivel] || RECOMENDACIONES.verde;

    // Chip de nivel en la cabecera del acordeón
    this.nivelTexto.textContent = `${leyenda}`;
    this.nivelTexto.className = 'recomendaciones__nivel-chip';
    this.nivelTexto.classList.add(CLASES_NIVEL[nivel] || '');

    // Intro corta
    if (this.intro) {
      this.intro.innerHTML = `<strong>${rec.titulo}</strong> — ${rec.descripcion}`;
    }

    // Sub-sectores como bloques fijos (NO acordeón). Hídrico, agrícola, salud,
    // incendios, comunidad — todos visibles cuando el acordeón principal está
    // expandido.
    this.contenedor.innerHTML = '';
    Object.entries(rec.acciones).forEach(([clave, lista]) => {
      const bloque = document.createElement('div');
      bloque.className = `recomendaciones__sector recomendaciones__sector--${clave}`;
      const h4 = document.createElement('h4');
      h4.className = 'recomendaciones__sector-titulo';
      h4.textContent = ETIQUETAS_SECCION[clave] || clave;
      const ul = document.createElement('ul');
      lista.forEach((accion) => {
        const li = document.createElement('li');
        li.textContent = accion;
        ul.appendChild(li);
      });
      bloque.appendChild(h4);
      bloque.appendChild(ul);
      this.contenedor.appendChild(bloque);
    });
  }
}
