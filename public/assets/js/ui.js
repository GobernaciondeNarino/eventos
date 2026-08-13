/* =========================================================================
   ui.js — Piezas de interfaz compartidas por todas las pantallas
   Avisos, ventanas modales, hoja inferior, interruptores y formateadores.
   Sin dependencias externas.
   ========================================================================= */
(function (global) {
  'use strict';

  /* ---- Escape ---------------------------------------------------------------
     Todo dato que venga de una persona (nombre, entidad, tema de la charla)
     pasa por aquí antes de tocar innerHTML. En fase 2 el servidor volverá a
     escapar al renderizar; esto es la defensa del lado del navegador.        */
  function esc(valor) {
    return String(valor == null ? '' : valor)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /** Construye HTML a partir de una plantilla, escapando cada interpolación. */
  function html(strings) {
    var valores = Array.prototype.slice.call(arguments, 1);
    return strings.reduce(function (acc, str, i) {
      var v = valores[i - 1];
      if (Array.isArray(v)) v = v.join('');
      else if (v && v.__raw) v = v.__raw;
      else v = esc(v);
      return acc + v + str;
    });
  }
  html.raw = function (s) { return { __raw: String(s) }; };

  /* ---- Selectores cortos --------------------------------------------------- */
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function on(el, evento, sel, fn) {
    if (typeof sel === 'function') { el.addEventListener(evento, sel); return; }
    el.addEventListener(evento, function (e) {
      var t = e.target.closest(sel);
      if (t && el.contains(t)) fn.call(t, e, t);
    });
  }

  /* ---- Avisos flotantes ----------------------------------------------------- */
  var tToast;
  function toast(mensaje, ms) {
    var previo = $('.toast');
    if (previo) previo.remove();
    var el = document.createElement('div');
    el.className = 'toast';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.textContent = mensaje;
    document.body.appendChild(el);
    clearTimeout(tToast);
    tToast = setTimeout(function () { el.remove(); }, ms || 2600);
    return el;
  }

  /* ---- Ventana modal --------------------------------------------------------
     Con foco atrapado y cierre por Escape: sin esto, el teclado se pierde
     detrás del velo y la pantalla queda inutilizable con lector.            */
  var modalAbierto = null, focoPrevio = null;

  function modal(opciones) {
    cerrarModal();
    var o = opciones || {};
    var cont = document.createElement('div');
    cont.className = 'modal';
    cont.setAttribute('role', 'dialog');
    cont.setAttribute('aria-modal', 'true');
    cont.setAttribute('aria-label', o.titulo || 'Detalle');
    cont.innerHTML =
      '<div class="modal__panel">' +
        '<div class="modal__head">' +
          '<span>' + esc(o.etiqueta || '') + '</span>' +
          '<button class="modal__close" type="button" aria-label="Cerrar">&times;</button>' +
        '</div>' +
        '<div class="modal__body">' + (o.html || esc(o.texto || '')) + '</div>' +
      '</div>';

    focoPrevio = document.activeElement;
    document.body.appendChild(cont);
    document.body.style.overflow = 'hidden';
    modalAbierto = cont;

    cont.addEventListener('click', function (e) {
      if (e.target === cont || e.target.closest('.modal__close')) cerrarModal();
    });
    document.addEventListener('keydown', teclaModal);

    var enfocable = cont.querySelector('.modal__close');
    if (enfocable) enfocable.focus();
    return cont;
  }

  function teclaModal(e) {
    if (!modalAbierto) return;
    if (e.key === 'Escape') { cerrarModal(); return; }
    if (e.key !== 'Tab') return;
    var focos = $$('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])', modalAbierto)
      .filter(function (el) { return el.offsetParent !== null; });
    if (!focos.length) return;
    var primero = focos[0], ultimo = focos[focos.length - 1];
    if (e.shiftKey && document.activeElement === primero) { e.preventDefault(); ultimo.focus(); }
    else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primero.focus(); }
  }

  function cerrarModal() {
    if (!modalAbierto) return;
    modalAbierto.remove();
    modalAbierto = null;
    document.body.style.overflow = '';
    document.removeEventListener('keydown', teclaModal);
    if (focoPrevio && focoPrevio.focus) focoPrevio.focus();
  }

  /* ---- Interruptores --------------------------------------------------------- */
  function interruptor(el, alCambiar) {
    if (!el) return;
    el.setAttribute('role', 'switch');
    if (!el.hasAttribute('aria-checked')) el.setAttribute('aria-checked', 'false');
    el.addEventListener('click', function () {
      var nuevo = el.getAttribute('aria-checked') !== 'true';
      el.setAttribute('aria-checked', String(nuevo));
      if (alCambiar) alCambiar(nuevo);
    });
  }

  /* ---- Grupos de opciones tipo "chip" ----------------------------------------- */
  function grupoChips(contenedor, alElegir) {
    if (!contenedor) return;
    contenedor.addEventListener('click', function (e) {
      var chip = e.target.closest('.chip');
      if (!chip || !contenedor.contains(chip)) return;
      $$('.chip', contenedor).forEach(function (c) {
        c.classList.toggle('is-active', c === chip);
        c.setAttribute('aria-pressed', String(c === chip));
      });
      if (alElegir) alElegir(chip.dataset.valor, chip);
    });
  }

  /* ---- Formateadores ----------------------------------------------------------- */
  var fmt = {
    /** 1085234567 -> 1.085.234.567 (formato colombiano) */
    documento: function (n) {
      var s = String(n == null ? '' : n).replace(/\D/g, '');
      return s.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    },
    numero: function (n) {
      return new Intl.NumberFormat('es-CO').format(Number(n) || 0);
    },
    /** Iniciales para el avatar: "María Fernanda Zambrano" -> "MZ" */
    iniciales: function (nombre) {
      var p = String(nombre || '').trim().split(/\s+/).filter(Boolean);
      if (!p.length) return '?';
      return (p[0][0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
    },
    fecha: function (iso) {
      var d = iso instanceof Date ? iso : new Date(iso);
      if (isNaN(d)) return String(iso || '');
      return d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' });
    },
    hora: function (iso) {
      var d = iso instanceof Date ? iso : new Date(iso);
      if (isNaN(d)) return '';
      return d.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' });
    },
    /** Oculta parte del correo en pantallas compartidas: ma***@narino.gov.co */
    correoParcial: function (correo) {
      var m = String(correo || '').split('@');
      if (m.length !== 2) return esc(correo);
      var u = m[0];
      return (u.length <= 2 ? u[0] + '*' : u.slice(0, 2) + '***') + '@' + m[1];
    }
  };

  /* ---- Validaciones reutilizables ------------------------------------------------ */
  var valida = {
    correo: function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(v || '').trim()); },
    documento: function (v) {
      var s = String(v || '').replace(/\D/g, '');
      return s.length >= 5 && s.length <= 12;
    },
    nombre: function (v) { return String(v || '').trim().length >= 5; },
    telefono: function (v) {
      var s = String(v || '').replace(/[^\d+]/g, '');
      return s === '' || (s.replace(/\D/g, '').length >= 7 && s.replace(/\D/g, '').length <= 15);
    }
  };

  /** Marca un campo con error y devuelve false; lo limpia y devuelve true. */
  function marcar(input, ok, mensaje) {
    if (!input) return ok;
    input.classList.toggle('is-invalid', !ok);
    input.setAttribute('aria-invalid', String(!ok));
    var id = input.id ? input.id + '-error' : null;
    var slot = id ? document.getElementById(id) : input.parentElement.querySelector('.error');
    if (slot) {
      slot.textContent = ok ? '' : (mensaje || '');
      slot.classList.toggle('hidden', ok);
    }
    return ok;
  }

  /* ---- Descargas locales ----------------------------------------------------------
     El prototipo genera los archivos en el navegador; en fase 2 los exportables
     grandes se piden al servidor.                                              */
  function descargar(nombre, contenido, tipo) {
    var blob = new Blob([contenido], { type: (tipo || 'text/plain') + ';charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = nombre;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }

  function aCsv(filas) {
    return filas.map(function (fila) {
      return fila.map(function (celda) {
        var s = String(celda == null ? '' : celda);
        // Un valor que empiece por = + - @ puede ejecutarse como fórmula
        // al abrir el CSV en Excel; se neutraliza con un apóstrofo.
        if (/^[=+\-@\t\r]/.test(s)) s = "'" + s;
        return '"' + s.replace(/"/g, '""') + '"';
      }).join(';');
    }).join('\r\n');
  }

  function aVcf(contactos) {
    return contactos.map(function (c) {
      return [
        'BEGIN:VCARD', 'VERSION:3.0',
        'FN:' + (c.nombre || ''),
        'ORG:' + (c.entidad || ''),
        'EMAIL;TYPE=WORK:' + (c.correo || ''),
        c.tel ? 'TEL;TYPE=CELL:' + c.tel : '',
        'NOTE:Contacto intercambiado en ' + (c.evento || 'el evento'),
        'END:VCARD'
      ].filter(Boolean).join('\r\n');
    }).join('\r\n');
  }

  global.UI = {
    esc: esc, html: html, $: $, $$: $$, on: on,
    toast: toast, modal: modal, cerrarModal: cerrarModal,
    interruptor: interruptor, grupoChips: grupoChips,
    fmt: fmt, valida: valida, marcar: marcar,
    descargar: descargar, aCsv: aCsv, aVcf: aVcf
  };
})(window);
