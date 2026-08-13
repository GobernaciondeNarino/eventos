/* =========================================================================
   identidad.js — Previsualización en vivo de la identidad del evento.
   -------------------------------------------------------------------------
   Escribe las mismas variables CSS que el servidor imprime al guardar, así que
   lo que se ve mientras se ajusta es exactamente lo que quedará. El servidor
   vuelve a validar cada color: esto es comodidad, no control.
   ========================================================================= */
(function () {
  'use strict';

  var formulario = document.querySelector('[data-presets]');
  if (!formulario) return;

  var PRESETS = {};
  try {
    PRESETS = JSON.parse(formulario.getAttribute('data-presets')) || {};
  } catch (e) {
    return;
  }

  var VARIABLES = {
    brand: '--c-brand', accent: '--c-accent', bg: '--c-bg', surface: '--c-surface',
    sunken: '--c-sunken', line: '--c-line', title: '--c-title', text: '--c-text',
    muted: '--c-muted', onBrand: '--c-on-brand'
  };
  var CON_RGB = {
    brand: '--c-brand-rgb', accent: '--c-accent-rgb', bg: '--c-bg-rgb',
    surface: '--c-surface-rgb', line: '--c-line-rgb'
  };

  var raiz = document.documentElement;

  function esHex(valor) { return /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(valor); }

  function aRgb(hex) {
    var h = hex.replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
  }

  function aplicarColor(clave, hex) {
    if (!VARIABLES[clave] || !esHex(hex)) return;
    raiz.style.setProperty(VARIABLES[clave], hex);
    if (CON_RGB[clave]) raiz.style.setProperty(CON_RGB[clave], aRgb(hex).join(', '));
  }

  /* ---- Selectores de color ------------------------------------------------ */
  formulario.addEventListener('input', function (e) {
    var porRueda = e.target.getAttribute && e.target.getAttribute('data-color');
    var porTexto = e.target.getAttribute && e.target.getAttribute('data-hex');

    if (porRueda) {
      var campoTexto = formulario.querySelector('[data-hex="' + porRueda + '"]');
      if (campoTexto) campoTexto.value = e.target.value.toUpperCase();
      aplicarColor(porRueda, e.target.value);
      revisarContraste();
    } else if (porTexto) {
      var valor = e.target.value.trim();
      if (valor && valor[0] !== '#') valor = '#' + valor;
      var valido = esHex(valor);
      e.target.classList.toggle('is-invalid', !valido);
      if (valido) {
        var rueda = formulario.querySelector('[data-color="' + porTexto + '"]');
        if (rueda) rueda.value = valor;
        aplicarColor(porTexto, valor);
        revisarContraste();
      }
    }

    // Nombre del evento en la previsualización
    var vista = e.target.getAttribute && e.target.getAttribute('data-vista');
    if (vista) {
      var destino = document.getElementById(vista);
      if (destino) destino.textContent = e.target.value || 'Evento sin nombre';
    }
  });

  /* ---- Cambio de paleta base ---------------------------------------------- */
  formulario.addEventListener('change', function (e) {
    if (e.target.hasAttribute && e.target.hasAttribute('data-preset-radio')) {
      var colores = PRESETS[e.target.value];
      if (!colores) return;

      Object.keys(colores).forEach(function (clave) {
        aplicarColor(clave, colores[clave]);
        var rueda = formulario.querySelector('[data-color="' + clave + '"]');
        var texto = formulario.querySelector('[data-hex="' + clave + '"]');
        if (rueda) rueda.value = colores[clave];
        if (texto) {
          texto.value = colores[clave];
          texto.classList.remove('is-invalid');
        }
      });
      revisarContraste();
    }

    if (e.target.hasAttribute && e.target.hasAttribute('data-tipografia-radio')) {
      raiz.setAttribute('data-tipografia', e.target.value);
    }
  });

  /* ---- Contraste (WCAG 2.1) ------------------------------------------------
     Se recalcula al vuelo con la misma fórmula del servidor, para que nadie
     tenga que guardar y volver a mirar.                                    */
  var PARES = [
    ['Títulos sobre el fondo', 'title', 'bg', 4.5],
    ['Texto sobre las tarjetas', 'text', 'surface', 4.5],
    ['Etiquetas sobre las tarjetas', 'muted', 'surface', 3],
    ['Énfasis sobre las tarjetas', 'accent', 'surface', 3],
    ['Texto sobre el color principal', 'onBrand', 'brand', 4.5]
  ];

  function luminancia(hex) {
    return aRgb(hex).map(function (v) {
      var x = v / 255;
      return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
    }).reduce(function (suma, canal, i) {
      return suma + canal * [0.2126, 0.7152, 0.0722][i];
    }, 0);
  }

  function contraste(a, b) {
    var la = luminancia(a);
    var lb = luminancia(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
  }

  function colorActual(clave) {
    var campo = formulario.querySelector('[data-hex="' + clave + '"]');
    return campo && esHex(campo.value) ? campo.value : '#000000';
  }

  function revisarContraste() {
    var lista = document.querySelector('[data-contraste]');
    if (!lista) return;

    lista.innerHTML = PARES.map(function (par) {
      var razon = contraste(colorActual(par[1]), colorActual(par[2]));
      var cumple = razon >= par[3];
      return '<div class="check ' + (cumple ? 'check--ok' : 'check--warn') + '">'
        + '<span class="check__icon" aria-hidden="true">' + (cumple ? '✓' : '▲') + '</span>'
        + '<div class="stack" style="gap:2px">'
        + '<span class="check__name">' + par[0] + '</span>'
        + '<span class="check__detail">mínimo ' + par[3] + ':1' + (cumple ? '' : ' — poco legible') + '</span>'
        + '</div>'
        + '<span class="check__value">' + razon.toFixed(2) + ':1</span>'
        + '</div>';
    }).join('');
  }
})();
