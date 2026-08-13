/* Identidad del evento (requisito 4): colores, tipografía y logo configurables
   sin tocar código. Todo cambio se aplica en vivo sobre las variables CSS. */
(function () {
  'use strict';

  var CAMPOS = [
    { clave: 'brand', etiqueta: 'Color principal', pista: 'Barras y botones sólidos' },
    { clave: 'accent', etiqueta: 'Color de énfasis', pista: 'Bordes, foco y estados activos' },
    { clave: 'bg', etiqueta: 'Fondo de la aplicación', pista: 'Lienzo plano' },
    { clave: 'surface', etiqueta: 'Fondo de tarjetas', pista: 'Paneles y formularios' },
    { clave: 'line', etiqueta: 'Color de líneas', pista: 'Bordes y separadores' },
    { clave: 'title', etiqueta: 'Color de títulos', pista: 'Encabezados' },
    { clave: 'text', etiqueta: 'Color del texto', pista: 'Párrafos y etiquetas' },
    { clave: 'muted', etiqueta: 'Color de metadatos', pista: 'Rótulos pequeños' }
  ];

  var LIMITE_LOGO = 512 * 1024;

  /* ---- Nombre y dependencia -------------------------------------------------- */
  var tema = Tema.get();
  UI.$('#nombre-evento').value = tema.evento;
  UI.$('#dependencia').value = tema.dependencia;

  UI.$('#nombre-evento').addEventListener('input', function () {
    Tema.set({ evento: this.value.trim() || 'Evento sin nombre' });
  });
  UI.$('#dependencia').addEventListener('input', function () {
    Tema.set({ dependencia: this.value.trim() });
  });

  /* ---- Logo ------------------------------------------------------------------
     Se guarda como data URI para que el prototipo funcione sin servidor. En la
     fase funcional el archivo se sube, se valida el tipo real (no solo la
     extensión), se reescribe la imagen y se sirve desde una ruta estática.  */
  UI.$('#zona-logo').addEventListener('click', function () { UI.$('#archivo-logo').click(); });

  UI.$('#archivo-logo').addEventListener('change', function () {
    var archivo = this.files && this.files[0];
    if (!archivo) return;

    var permitidos = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'];
    if (permitidos.indexOf(archivo.type) === -1) {
      UI.toast('Formato no admitido. Usa SVG, PNG, JPG o WEBP.');
      this.value = '';
      return;
    }
    if (archivo.size > LIMITE_LOGO) {
      UI.toast('El archivo pesa ' + Math.round(archivo.size / 1024) + ' KB; el máximo son 512 KB.');
      this.value = '';
      return;
    }

    var lector = new FileReader();
    lector.onload = function () {
      Tema.set({ logo: lector.result });
      pintarLogo();
      UI.toast('Logo actualizado.');
    };
    lector.readAsDataURL(archivo);
  });

  UI.$('#quitar-logo').addEventListener('click', function () {
    Tema.set({ logo: '' });
    UI.$('#archivo-logo').value = '';
    pintarLogo();
    UI.toast('Se volvió a la marca por iniciales.');
  });

  function pintarLogo() {
    var t = Tema.get();
    var zona = UI.$('#zona-logo');
    if (t.logo) {
      zona.innerHTML = '<img src="' + UI.esc(t.logo) + '" alt="Logo del evento" style="max-width:100%;max-height:100%;object-fit:contain">';
      UI.$('#quitar-logo').classList.remove('hidden');
    } else {
      zona.innerHTML = '<span style="font-size:20px;color:var(--c-accent)">+</span>'
        + '<span class="mono" style="font-size:9.5px;color:var(--c-muted);line-height:1.4;letter-spacing:.08em;text-transform:uppercase">Subir logo</span>';
      UI.$('#quitar-logo').classList.add('hidden');
    }
  }

  /* ---- Presets de paleta ------------------------------------------------------ */
  function pintarPresets() {
    var actual = Tema.get().preset;
    UI.$('#presets').innerHTML = Object.keys(Tema.presets).map(function (k) {
      var p = Tema.presets[k];
      var muestras = ['brand', 'accent', 'bg', 'title'].map(function (c) {
        return '<span style="width:11px;height:11px;background:' + p.colores[c] + ';border:1px solid rgba(255,255,255,.15)"></span>';
      }).join('');
      return '<button type="button" class="chip' + (k === actual ? ' is-active' : '') + '"'
        + ' data-preset="' + UI.esc(k) + '" aria-pressed="' + (k === actual) + '"'
        + ' title="' + UI.esc(p.descripcion) + '"'
        + ' style="display:flex;align-items:center;gap:8px">'
        + '<span style="display:flex;gap:2px">' + muestras + '</span>' + UI.esc(p.nombre) + '</button>';
    }).join('');
  }

  UI.$('#presets').addEventListener('click', function (e) {
    var b = e.target.closest('[data-preset]');
    if (!b) return;
    Tema.set({ preset: b.dataset.preset, colores: null });
    refrescar();
    UI.toast('Paleta «' + Tema.presets[b.dataset.preset].nombre + '» aplicada.');
  });

  /* ---- Colores uno a uno ------------------------------------------------------ */
  function pintarColores() {
    var c = Tema.colores();
    UI.$('#colores').innerHTML = CAMPOS.map(function (f) {
      return '<div class="plan-row" style="grid-template-columns:1fr 44px 88px">'
        + '<div class="stack" style="gap:3px">'
          + '<strong style="font-size:13px;font-weight:500;color:var(--c-title)">' + UI.esc(f.etiqueta) + '</strong>'
          + '<span class="mono muted" style="font-size:10.5px">' + UI.esc(f.pista) + '</span>'
        + '</div>'
        + '<input type="color" value="' + UI.esc(c[f.clave]) + '" data-color="' + UI.esc(f.clave) + '"'
          + ' aria-label="' + UI.esc(f.etiqueta) + '"'
          + ' style="width:44px;height:32px;padding:0;border:1px solid var(--a-28);background:transparent;cursor:pointer">'
        + '<input class="input input--mono" data-hex="' + UI.esc(f.clave) + '" value="' + UI.esc(c[f.clave]) + '"'
          + ' aria-label="Código hexadecimal de ' + UI.esc(f.etiqueta) + '"'
          + ' style="padding:7px 8px;font-size:11.5px;text-align:center">'
        + '</div>';
    }).join('');
  }

  UI.$('#colores').addEventListener('input', function (e) {
    var color = e.target.dataset.color;
    var hex = e.target.dataset.hex;

    if (color) {
      Tema.setColor(color, e.target.value);
      var campo = UI.$('[data-hex="' + color + '"]');
      if (campo) campo.value = e.target.value.toUpperCase();
      pintarContraste();
    } else if (hex) {
      var valor = e.target.value.trim();
      if (!valor.startsWith('#')) valor = '#' + valor;
      var ok = Tema.esHexValido(valor);
      e.target.classList.toggle('is-invalid', !ok);
      if (ok) {
        Tema.setColor(hex, valor);
        var selector = UI.$('[data-color="' + hex + '"]');
        if (selector) selector.value = valor;
        pintarContraste();
      }
    }
  });

  /* ---- Tipografía -------------------------------------------------------------- */
  function pintarTipografias() {
    var actual = Tema.get().tipografia;
    UI.$('#tipografias').innerHTML = Object.keys(Tema.tipografias).map(function (k) {
      var t = Tema.tipografias[k];
      return '<button type="button" class="chip' + (k === actual ? ' is-active' : '') + '"'
        + ' data-tipografia="' + UI.esc(k) + '" aria-pressed="' + (k === actual) + '"'
        + ' style="display:flex;flex-direction:column;align-items:flex-start;gap:4px;padding:12px 14px;text-align:left">'
        + '<span style="font-family:var(--f-display);font-size:15px;font-weight:600;letter-spacing:.06em">' + UI.esc(t.nombre) + '</span>'
        + '<span class="mono" style="font-size:10.5px;opacity:.75">' + UI.esc(t.muestra) + '</span>'
        + '</button>';
    }).join('');
  }

  UI.$('#tipografias').addEventListener('click', function (e) {
    var b = e.target.closest('[data-tipografia]');
    if (!b) return;
    Tema.set({ tipografia: b.dataset.tipografia });
    pintarTipografias();
    UI.toast('Tipografía «' + Tema.tipografias[b.dataset.tipografia].nombre + '» aplicada.');
  });

  /* ---- Contraste ---------------------------------------------------------------- */
  function pintarContraste() {
    var avisos = Tema.revisarContraste();
    var c = Tema.colores();

    var todas = [
      { etiqueta: 'Títulos sobre el fondo', a: c.title, b: c.bg, minimo: 4.5 },
      { etiqueta: 'Texto sobre las tarjetas', a: c.text, b: c.surface, minimo: 4.5 },
      { etiqueta: 'Etiquetas sobre las tarjetas', a: c.muted, b: c.surface, minimo: 3 },
      { etiqueta: 'Énfasis sobre las tarjetas', a: c.accent, b: c.surface, minimo: 3 },
      { etiqueta: 'Texto sobre el color principal', a: c.onBrand, b: c.brand, minimo: 4.5 }
    ];

    UI.$('#contraste').innerHTML = todas.map(function (t) {
      var razon = Tema.contraste(t.a, t.b);
      var ok = razon >= t.minimo;
      return '<div class="check ' + (ok ? 'check--ok' : 'check--warn') + '">'
        + '<span class="check__icon" aria-hidden="true">' + (ok ? '✓' : '▲') + '</span>'
        + '<div class="stack" style="gap:2px">'
          + '<span class="check__name">' + UI.esc(t.etiqueta) + '</span>'
          + '<span class="check__detail">mínimo ' + t.minimo + ':1' + (ok ? '' : ' — poco legible') + '</span>'
        + '</div>'
        + '<span class="check__value">' + razon.toFixed(2) + ':1</span>'
        + '</div>';
    }).join('');

    UI.$('#guardar').textContent = avisos.length
      ? 'Guardar de todos modos (' + avisos.length + ' avisos)'
      : 'Guardar identidad';
  }

  /* ---- Previsualización ---------------------------------------------------------- */
  function pintarVista() {
    var t = Tema.get();
    var marca = t.logo ? '<img src="' + UI.esc(t.logo) + '" alt="">' : UI.esc(Tema.iniciales());
    UI.$('#vista-marca').innerHTML = marca;
    UI.$('#vista-marca-2').innerHTML = marca;
    UI.$('#vista-nombre').textContent = t.evento;
    UI.$('#vista-nombre-2').textContent = t.evento;
  }

  function refrescar() {
    pintarPresets();
    pintarColores();
    pintarTipografias();
    pintarContraste();
    pintarLogo();
    pintarVista();
  }

  // La previsualización se redibuja ante cualquier cambio del tema, venga de
  // donde venga. Se engancha solo pintarVista y no refrescar() completo: si se
  // reconstruyeran los campos de color, el selector perdería el foco a media
  // edición y arrastrar el cursor por la paleta sería imposible.
  document.addEventListener('tema:cambio', pintarVista);

  UI.$('#restablecer').addEventListener('click', function () {
    Tema.reset();
    UI.$('#nombre-evento').value = Tema.get().evento;
    UI.$('#dependencia').value = Tema.get().dependencia;
    refrescar();
    UI.toast('Identidad restablecida a los valores de fábrica.');
  });

  UI.$('#guardar').addEventListener('click', function () {
    var avisos = Tema.revisarContraste();
    if (avisos.length) {
      UI.modal({
        etiqueta: 'Accesibilidad',
        titulo: 'Contrastes por debajo del mínimo',
        html: '<h2 style="font-size:22px">Hay ' + avisos.length + ' contrastes bajos</h2>'
          + '<p class="help">Estas combinaciones no alcanzan el mínimo de la norma WCAG 2.1 AA. '
          + 'Se pueden guardar, pero parte del texto será difícil de leer para personas con baja visión '
          + 'o bajo luz directa.</p>'
          + '<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.8;color:var(--c-text)">'
          + avisos.map(function (a) {
              return '<li>' + UI.esc(a.etiqueta) + ' — ' + UI.esc(a.razon) + ':1 (mínimo ' + a.minimo + ':1)</li>';
            }).join('')
          + '</ul>'
          + '<div class="row row--end">'
            + '<button class="btn" id="volver-ajustar">Volver a ajustar</button>'
            + '<button class="btn btn--primary" id="guardar-igual">Guardar de todos modos</button>'
          + '</div>'
      });
      UI.$('#volver-ajustar').addEventListener('click', UI.cerrarModal);
      UI.$('#guardar-igual').addEventListener('click', function () {
        UI.cerrarModal();
        UI.toast('Identidad guardada con avisos de contraste.');
      });
      return;
    }
    UI.toast('Identidad guardada y aplicada a toda la plataforma.');
  });

  refrescar();
})();
