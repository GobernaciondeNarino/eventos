/* =========================================================================
   tema.js — Identidad visual configurable por evento
   -------------------------------------------------------------------------
   Requisito 4: cada evento personaliza colores, tipografía y logo sin tocar
   código. Este archivo es la única puerta de entrada a esa configuración.

   Fase 1 (prototipo): el tema vive en localStorage y el panel de identidad
   lo edita en vivo.
   Fase 2 (con backend): el servidor imprime en el <head>

       <script>window.EVENTO_TEMA = { ...fila de la tabla evento_tema... }</script>

   y este archivo lo toma de ahí. La estructura del objeto es la misma en los
   dos casos, así que las pantallas no cambian.

   Debe cargarse de forma síncrona en el <head>, antes de pintar, para que no
   se vea el destello del tema por defecto.
   ========================================================================= */
(function (global) {
  'use strict';

  var CLAVE = 'eventostic.tema.v1';

  var PRESETS = {
    'tic-nocturno': {
      nombre: 'TIC Nocturno',
      descripcion: 'La paleta del prototipo: fondo profundo y cian de alta visibilidad.',
      colores: {
        brand: '#0C2E3C', accent: '#35E0F5', bg: '#050D15', surface: '#08151F',
        sunken: '#05101A', line: '#1E4557', title: '#E4F7FD', text: '#89AEC0',
        muted: '#4E7285', onBrand: '#E4F7FD'
      }
    },
    'narino-verde': {
      nombre: 'Nariño Verde',
      descripcion: 'Verde territorial, para eventos de conectividad rural.',
      colores: {
        brand: '#123A31', accent: '#7CF7C8', bg: '#07120F', surface: '#0A1B17',
        sunken: '#061310', line: '#22463A', title: '#E6FBF3', text: '#93BCAB',
        muted: '#4E7A69', onBrand: '#E6FBF3'
      }
    },
    'institucional-azul': {
      nombre: 'Institucional Azul',
      descripcion: 'Azul de gobierno, sobrio, alineado a la imagen departamental.',
      colores: {
        brand: '#12324F', accent: '#4EA8FF', bg: '#0A0F1A', surface: '#101827',
        sunken: '#0B111C', line: '#243449', title: '#EAF2FF', text: '#8FA3B8',
        muted: '#566B84', onBrand: '#EAF2FF'
      }
    },
    'creativa-magenta': {
      nombre: 'Creativa Magenta',
      descripcion: 'Para economía creativa y contenidos digitales.',
      colores: {
        brand: '#2B1F3D', accent: '#FF5FD1', bg: '#100D1A', surface: '#191330',
        sunken: '#120E22', line: '#3A2E4A', title: '#F6ECFF', text: '#AFA3C4',
        muted: '#6E6187', onBrand: '#F6ECFF'
      }
    },
    'claro-institucional': {
      nombre: 'Claro Institucional',
      descripcion: 'Fondo blanco, pensado para proyección y material impreso.',
      colores: {
        brand: '#0C4A6E', accent: '#0284C7', bg: '#F4F8FB', surface: '#FFFFFF',
        sunken: '#F0F5F9', line: '#C9DCE8', title: '#0B2534', text: '#44647A',
        muted: '#6E8A9C', onBrand: '#FFFFFF'
      }
    }
  };

  /* Las cuatro familias están autoalojadas en assets/fonts (ver
     herramientas/descargar-fuentes.py). No se consulta ningún CDN: el
     navegador solo descarga los archivos de la familia que se esté usando. */
  var TIPOGRAFIAS = {
    tecnologica: { nombre: 'Tecnológica', muestra: 'Chakra Petch · IBM Plex' },
    institucional: { nombre: 'Institucional', muestra: 'Barlow Condensed · Source Sans' },
    neutra: { nombre: 'Neutra', muestra: 'Inter · JetBrains Mono' },
    editorial: { nombre: 'Editorial', muestra: 'Space Grotesk · IBM Plex' }
  };

  var POR_DEFECTO = {
    evento: 'Semana TIC Nariño 2026',
    dependencia: 'Secretaría TIC, Innovación y Gobierno Abierto',
    logo: '',                       // data URI o ruta; vacío = iniciales
    preset: 'tic-nocturno',
    tipografia: 'tecnologica',
    colores: null                   // si es null se usan los del preset
  };

  /* ---- Utilidades ---------------------------------------------------------- */
  function hexARgb(hex) {
    var h = String(hex || '').replace('#', '').trim();
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    if (!/^[0-9a-fA-F]{6}$/.test(h)) return null;
    return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
  }

  function esHexValido(hex) { return hexARgb(hex) !== null; }

  /** Luminancia relativa (WCAG) — sirve para avisar de contrastes malos. */
  function luminancia(hex) {
    var rgb = hexARgb(hex);
    if (!rgb) return 0;
    var c = rgb.map(function (v) {
      v /= 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }

  function contraste(hexA, hexB) {
    var a = luminancia(hexA), b = luminancia(hexB);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
  }

  function leer() {
    var base = Object.assign({}, POR_DEFECTO);
    // El backend manda; localStorage solo se usa si no hay backend.
    if (global.EVENTO_TEMA && typeof global.EVENTO_TEMA === 'object') {
      return Object.assign(base, global.EVENTO_TEMA);
    }
    try {
      var crudo = global.localStorage && global.localStorage.getItem(CLAVE);
      if (crudo) Object.assign(base, JSON.parse(crudo));
    } catch (e) { /* almacenamiento bloqueado: se sigue con el tema por defecto */ }
    return base;
  }

  function guardar(tema) {
    try {
      global.localStorage.setItem(CLAVE, JSON.stringify(tema));
    } catch (e) { /* modo privado o cuota llena: el tema dura la sesión */ }
  }

  /* ---- Aplicación al documento -------------------------------------------- */
  var VARS = {
    brand: '--c-brand', accent: '--c-accent', bg: '--c-bg', surface: '--c-surface',
    sunken: '--c-sunken', line: '--c-line', title: '--c-title', text: '--c-text',
    muted: '--c-muted', onBrand: '--c-on-brand'
  };
  var CON_RGB = { brand: '--c-brand-rgb', accent: '--c-accent-rgb', bg: '--c-bg-rgb', surface: '--c-surface-rgb', line: '--c-line-rgb' };

  function colores(tema) {
    var preset = PRESETS[tema.preset] || PRESETS['tic-nocturno'];
    return Object.assign({}, preset.colores, tema.colores || {});
  }

  function aplicar(tema) {
    var raiz = document.documentElement;
    var c = colores(tema);

    raiz.setAttribute('data-preset', tema.preset);
    raiz.setAttribute('data-tipografia', tema.tipografia);

    Object.keys(VARS).forEach(function (k) {
      if (!c[k] || !esHexValido(c[k])) return;
      raiz.style.setProperty(VARS[k], c[k]);
      if (CON_RGB[k]) raiz.style.setProperty(CON_RGB[k], hexARgb(c[k]).join(', '));
    });

    // En el <head> todavía no hay <body>; el título definitivo lo pone layout.js.
    if (document.body) document.title = tituloPagina(tema);
  }

  function tituloPagina(tema) {
    var base = document.body && document.body.getAttribute('data-titulo');
    return (base ? base + ' · ' : '') + (tema.evento || 'Plataforma de Eventos TIC');
  }

  /* ---- API pública --------------------------------------------------------- */
  var actual = leer();

  var Tema = {
    presets: PRESETS,
    tipografias: TIPOGRAFIAS,
    porDefecto: POR_DEFECTO,

    get: function () { return JSON.parse(JSON.stringify(actual)); },
    colores: function () { return colores(actual); },

    /** Aplica un cambio parcial y repinta. Devuelve el tema resultante. */
    set: function (parche) {
      actual = Object.assign({}, actual, parche || {});
      if (parche && parche.preset && !parche.colores) actual.colores = null;
      guardar(actual);
      aplicar(actual);
      document.dispatchEvent(new CustomEvent('tema:cambio', { detail: Tema.get() }));
      return Tema.get();
    },

    /** Cambia un solo color manteniendo el resto del preset. */
    setColor: function (clave, hex) {
      if (!VARS[clave] || !esHexValido(hex)) return Tema.get();
      var c = Object.assign({}, actual.colores || {});
      c[clave] = hex;
      return Tema.set({ colores: c });
    },

    reset: function () {
      actual = Object.assign({}, POR_DEFECTO);
      guardar(actual);
      aplicar(actual);
      document.dispatchEvent(new CustomEvent('tema:cambio', { detail: Tema.get() }));
      return Tema.get();
    },

    aplicar: function () { aplicar(actual); },
    iniciales: function () {
      return String(actual.evento || 'E').trim().charAt(0).toUpperCase();
    },
    contraste: contraste,
    esHexValido: esHexValido,

    /** Revisa el contraste del tema y devuelve los avisos encontrados. */
    revisarContraste: function () {
      var c = colores(actual), avisos = [];
      function ver(a, b, etiqueta, minimo) {
        var r = contraste(a, b);
        if (r < minimo) avisos.push({ etiqueta: etiqueta, razon: r.toFixed(2), minimo: minimo });
      }
      ver(c.title, c.bg, 'Títulos sobre el fondo', 4.5);
      ver(c.text, c.surface, 'Texto sobre las tarjetas', 4.5);
      ver(c.muted, c.surface, 'Etiquetas sobre las tarjetas', 3);
      ver(c.accent, c.surface, 'Énfasis sobre las tarjetas', 3);
      ver(c.onBrand, c.brand, 'Texto sobre el color principal', 4.5);
      return avisos;
    }
  };

  aplicar(actual);
  global.Tema = Tema;
})(window);
