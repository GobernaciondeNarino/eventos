/* =========================================================================
   instalador.js — Asistente de instalación (requisito 9)
   -------------------------------------------------------------------------
   Seis pasos, al estilo de los instaladores clásicos: comprobar, conectar,
   decidir qué hacer con las tablas, crear la cuenta, configurar el evento y
   cerrar.

   En esta fase todo lo que "consulta el servidor" está simulado y así se
   advierte en pantalla. Lo que sí es real es el plan de tablas y el SQL: se
   generan desde esquema.js, la misma definición que usará la fase funcional.
   ========================================================================= */
(function () {
  'use strict';

  var TOTAL = 6;
  var paso = 1;
  var maximoAlcanzado = 1;
  var modo = 'limpio';

  var TITULOS = ['Servidor', 'Base de datos', 'Tablas', 'Cuenta', 'Evento', 'Fin'];

  /* ---- Navegación ----------------------------------------------------------- */
  function pintarPasos() {
    UI.$('#pasos').innerHTML = TITULOS.map(function (t, i) {
      var n = i + 1;
      var clase = n === paso ? ' is-active' : (n < paso ? ' is-done' : '');
      return '<div class="wizard-step' + clase + '"' + (n === paso ? ' aria-current="step"' : '') + '>'
        + '<span class="wizard-step__n">Paso ' + n + '</span>'
        + '<span class="wizard-step__t">' + UI.esc(t) + '</span>'
        + '</div>';
    }).join('');
  }

  function ir(n) {
    if (n > maximoAlcanzado + 1) return;
    paso = n;
    maximoAlcanzado = Math.max(maximoAlcanzado, n);
    UI.$$('[data-paso]').forEach(function (s) {
      s.classList.toggle('hidden', Number(s.dataset.paso) !== n);
    });
    pintarPasos();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-siguiente]');
    if (!b) return;
    var destino = Number(b.dataset.siguiente);
    if (destino > paso && !validarPaso(paso)) return;
    if (destino === 4 && paso === 3) aplicarEsquema();
    if (destino === 6 && paso === 5) instalar();
    ir(destino);
  });

  function validarPaso(n) {
    if (n === 2) {
      var ok = UI.marcar(UI.$('#bd-nombre'), UI.$('#bd-nombre').value.trim().length > 0,
        'Escribe el nombre de la base de datos.');
      ok = UI.marcar(UI.$('#bd-usuario'), UI.$('#bd-usuario').value.trim().length > 0,
        'Escribe el usuario de la base de datos.') && ok;
      ok = UI.marcar(UI.$('#bd-prefijo'), /^[a-z][a-z0-9_]{0,15}$/.test(UI.$('#bd-prefijo').value.trim()),
        'El prefijo debe empezar por letra minúscula y usar solo letras, números y guion bajo.') && ok;
      if (!ok) UI.toast('Revisa los datos de conexión.');
      return ok;
    }
    if (n === 4) {
      var nombre = UI.$('#ad-nombre'), correo = UI.$('#ad-correo');
      var clave = UI.$('#ad-clave'), clave2 = UI.$('#ad-clave2');
      var v = UI.marcar(nombre, UI.valida.nombre(nombre.value), 'Escribe el nombre completo.');
      v = UI.marcar(correo, UI.valida.correo(correo.value), 'Escribe un correo válido.') && v;
      v = UI.marcar(clave, clave.value.length >= 12, 'La contraseña debe tener al menos 12 caracteres.') && v;
      v = UI.marcar(clave2, clave2.value === clave.value && clave2.value.length > 0,
        'Las dos contraseñas deben coincidir.') && v;
      if (!v) UI.toast('Revisa los datos de la cuenta.');
      return v;
    }
    return true;
  }

  /* =========================================================================
     Paso 1 · Comprobación del servidor
     ========================================================================= */
  var REQUISITOS = [
    { nombre: 'Versión de PHP', detalle: 'Se requiere 8.1 o superior', valor: '8.4.19', estado: 'ok' },
    { nombre: 'Extensión PDO MySQL', detalle: 'Acceso a la base de datos', valor: 'presente', estado: 'ok' },
    { nombre: 'Extensión mbstring', detalle: 'Manejo correcto de tildes y ñ', valor: 'presente', estado: 'ok' },
    { nombre: 'Extensión openssl', detalle: 'Cifrado del documento y de los tokens', valor: 'presente', estado: 'ok' },
    { nombre: 'Extensión gd o imagick', detalle: 'Redimensionar el logo y las fotos del carnet', valor: 'gd 2.3', estado: 'ok' },
    { nombre: 'Extensión sodium', detalle: 'Argon2id para las contraseñas', valor: 'presente', estado: 'ok' },
    { nombre: 'HTTPS activo', detalle: 'Sin TLS las credenciales viajan en claro', valor: 'no detectado', estado: 'warn' },
    { nombre: 'Envío de correo', detalle: 'Para enviar el carnet al asistente', valor: 'SMTP sin configurar', estado: 'warn' }
  ];

  var PERMISOS = [
    { nombre: 'config/', detalle: 'Aquí se escribe config.php', valor: 'escritura', estado: 'ok' },
    { nombre: 'almacen/logos/', detalle: 'Logos de cada evento', valor: 'escritura', estado: 'ok' },
    { nombre: 'almacen/fotos/', detalle: 'Fotografías de los carnets', valor: 'escritura', estado: 'ok' },
    { nombre: 'almacen/respaldos/', detalle: 'Copias antes de cada migración', valor: 'no existe', estado: 'warn' },
    { nombre: 'public/', detalle: 'Debe ser el único directorio publicado', valor: 'solo lectura', estado: 'ok' }
  ];

  function pintarChecks(destino, items) {
    UI.$(destino).innerHTML = items.map(function (r) {
      var icono = r.estado === 'ok' ? '✓' : (r.estado === 'warn' ? '▲' : '✕');
      return '<div class="check check--' + r.estado + '">'
        + '<span class="check__icon" aria-hidden="true">' + icono + '</span>'
        + '<div class="stack" style="gap:2px">'
          + '<span class="check__name">' + UI.esc(r.nombre) + '</span>'
          + '<span class="check__detail">' + UI.esc(r.detalle) + '</span>'
        + '</div>'
        + '<span class="check__value">' + UI.esc(r.valor) + '</span>'
        + '</div>';
    }).join('');
  }

  UI.$('#revisar-otra-vez').addEventListener('click', function () {
    this.innerHTML = '<span class="spinner"></span> Comprobando…';
    var boton = this;
    setTimeout(function () {
      boton.textContent = 'Volver a comprobar';
      pintarChecks('#requisitos', REQUISITOS);
      pintarChecks('#permisos', PERMISOS);
      UI.toast('Comprobación repetida: 2 avisos, ningún bloqueo.');
    }, 800);
  });

  /* =========================================================================
     Paso 2 · Conexión
     ========================================================================= */
  UI.$('#probar-conexion').addEventListener('click', function () {
    if (!validarPaso(2)) return;
    var estado = UI.$('#estado-conexion');
    estado.innerHTML = '<span class="spinner"></span> Conectando…';
    setTimeout(function () {
      estado.innerHTML = '<span style="color:var(--c-ok)">✓ Conexión correcta · MariaDB 10.11 · utf8mb4</span>';
      detectar();
    }, 900);
  });

  /* =========================================================================
     Paso 3 · Tablas
     ========================================================================= */
  var MODOS = [
    {
      clave: 'limpio',
      titulo: 'Instalación limpia',
      texto: 'Crea las tablas desde cero. Si ya existen con este prefijo, se eliminan primero.',
      destructivo: true
    },
    {
      clave: 'actualizar',
      titulo: 'Actualizar una instalación existente',
      texto: 'Conserva los datos y solo aplica los cambios de esquema que faltan.',
      destructivo: false
    },
    {
      clave: 'anexar',
      titulo: 'Anexar sin tocar lo existente',
      texto: 'Crea únicamente las tablas que falten. Las que ya están se dejan como están.',
      destructivo: false
    }
  ];

  // Lo que "se encuentra" en la base: en la fase funcional sale de un
  // SHOW TABLES más la fila de la tabla migracion.
  var DETECTADO = { existentes: ['evento', 'evento_dia', 'persona'], version: null };

  function pintarModos() {
    UI.$('#modos').innerHTML = MODOS.map(function (m) {
      return '<label class="notice" style="cursor:pointer;align-items:flex-start;'
        + (m.clave === modo ? 'border-color:var(--c-accent);background:var(--a-08)' : '') + '">'
        + '<input type="radio" name="modo" value="' + m.clave + '"' + (m.clave === modo ? ' checked' : '')
          + ' style="margin-top:2px;width:16px;height:16px;accent-color:var(--c-accent)">'
        + '<span class="stack" style="gap:3px">'
          + '<strong style="font-family:var(--f-display);font-size:14px;letter-spacing:.06em;text-transform:uppercase;color:var(--c-title)">'
            + UI.esc(m.titulo) + '</strong>'
          + '<span class="help">' + UI.esc(m.texto) + '</span>'
        + '</span></label>';
    }).join('');
  }

  UI.$('#modos').addEventListener('change', function (e) {
    if (e.target.name !== 'modo') return;
    modo = e.target.value;
    pintarModos();
    detectar();
  });

  function detectar() {
    var prefijo = UI.$('#bd-prefijo').value.trim() || 'evt_';
    var nuevas = [], existentes = [], alteradas = [];

    Esquema.tablas.forEach(function (t) {
      var esta = DETECTADO.existentes.indexOf(t.nombre) !== -1;
      if (!esta) { nuevas.push(t); return; }
      if (modo === 'limpio') { alteradas.push(t); return; }
      if (modo === 'actualizar' && t.nombre === 'persona') { alteradas.push(t); return; }
      existentes.push(t);
    });

    var acciones = {
      limpio: { nuevas: 'Se creará', existentes: 'Se conservará', alteradas: 'Se recreará (se pierden los datos)' },
      actualizar: { nuevas: 'Se creará', existentes: 'Sin cambios', alteradas: 'Se modificará conservando los datos' },
      anexar: { nuevas: 'Se creará', existentes: 'Se dejará intacta', alteradas: 'Se dejará intacta' }
    }[modo];

    var filas = Esquema.tablas.map(function (t) {
      var estado, clase;
      if (nuevas.indexOf(t) !== -1) { estado = acciones.nuevas; clase = 'tag--ok'; }
      else if (alteradas.indexOf(t) !== -1) {
        estado = acciones.alteradas;
        clase = modo === 'limpio' ? 'tag--danger' : 'tag--warn';
      } else { estado = acciones.existentes; clase = 'tag--mute'; }

      return '<div class="plan-row">'
        + '<div class="stack" style="gap:2px;min-width:0">'
          + '<span class="plan-row__t">' + UI.esc(prefijo + t.nombre) + '</span>'
          + '<span class="check__detail">' + UI.esc(t.nota) + '</span>'
        + '</div>'
        + '<span class="mono muted" style="font-size:11px">' + t.columnas.length + ' columnas</span>'
        + '<span class="tag ' + clase + '">' + UI.esc(estado) + '</span>'
        + '</div>';
    }).join('');

    UI.$('#plan').innerHTML = filas;
    UI.$('#resumen-plan').textContent = nuevas.length + ' nuevas · ' + alteradas.length
      + ' afectadas · ' + existentes.length + ' sin tocar';

    UI.$('#sql').textContent = Esquema.sql(prefijo, modo);

    var aviso = UI.$('#aviso-destructivo');
    if (modo === 'limpio' && DETECTADO.existentes.length) {
      aviso.classList.remove('hidden');
      aviso.className = 'notice notice--danger';
      UI.$('#texto-destructivo').innerHTML = '<strong>Esta operación borra datos.</strong> Se detectaron '
        + DETECTADO.existentes.length + ' tablas con el prefijo <span class="mono">' + UI.esc(prefijo)
        + '</span>. En modo limpio se eliminan con todo su contenido. El asistente hará una copia '
        + 'en <span class="mono">almacen/respaldos/</span> antes de continuar.';
    } else {
      aviso.classList.remove('hidden');
      aviso.className = 'notice';
      UI.$('#texto-destructivo').textContent = modo === 'actualizar'
        ? 'Ninguna tabla se elimina. Aun así, el asistente hace una copia de seguridad antes de aplicar los cambios.'
        : 'Ninguna tabla existente se toca. Solo se crean las que faltan.';
    }
  }

  UI.$('#copiar-sql').addEventListener('click', function () {
    var texto = UI.$('#sql').textContent;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(texto).then(function () {
        UI.toast('SQL copiado al portapapeles.');
      }, function () {
        UI.descargar('esquema.sql', texto, 'text/plain');
      });
    } else {
      UI.descargar('esquema.sql', texto, 'text/plain');
    }
  });

  function aplicarEsquema() {
    UI.toast('Plan aplicado: ' + Esquema.tablas.length + ' tablas procesadas.');
  }

  /* =========================================================================
     Paso 4 · Cuenta administradora
     ========================================================================= */
  UI.$('#ad-clave').addEventListener('input', function () {
    var v = this.value;
    // Puntuación sencilla: la longitud pesa más que la variedad de símbolos,
    // que es lo que de verdad resiste un ataque por fuerza bruta.
    var puntos = Math.min(60, v.length * 4)
      + (/[a-z]/.test(v) && /[A-Z]/.test(v) ? 10 : 0)
      + (/\d/.test(v) ? 10 : 0)
      + (/[^\w\s]/.test(v) ? 10 : 0)
      + (/\s/.test(v) ? 10 : 0);
    puntos = Math.min(100, puntos);

    UI.$('#fuerza').style.width = puntos + '%';
    UI.$('#fuerza').style.background = puntos < 40 ? 'var(--c-danger)'
      : (puntos < 70 ? 'var(--c-warn)' : 'var(--c-ok)');
    UI.$('#fuerza-texto').textContent = v.length < 12
      ? 'Faltan ' + (12 - v.length) + ' caracteres para el mínimo.'
      : (puntos < 70 ? 'Aceptable. Una frase con varias palabras la haría más fuerte.'
                     : 'Buena contraseña.');
  });

  /* =========================================================================
     Paso 5 · Evento e identidad
     ========================================================================= */
  function pintarIdentidad() {
    var t = Tema.get();

    UI.$('#presets-install').innerHTML = Object.keys(Tema.presets).map(function (k) {
      var p = Tema.presets[k];
      var muestras = ['brand', 'accent', 'bg'].map(function (c) {
        return '<span style="width:10px;height:10px;background:' + p.colores[c] + ';border:1px solid rgba(255,255,255,.15)"></span>';
      }).join('');
      return '<button type="button" class="chip' + (k === t.preset ? ' is-active' : '') + '"'
        + ' data-preset="' + UI.esc(k) + '" aria-pressed="' + (k === t.preset) + '"'
        + ' style="display:flex;align-items:center;gap:8px">'
        + '<span style="display:flex;gap:2px">' + muestras + '</span>' + UI.esc(p.nombre) + '</button>';
    }).join('');

    UI.$('#tipos-install').innerHTML = Object.keys(Tema.tipografias).map(function (k) {
      var f = Tema.tipografias[k];
      return '<button type="button" class="chip' + (k === t.tipografia ? ' is-active' : '') + '"'
        + ' data-tipografia="' + UI.esc(k) + '" aria-pressed="' + (k === t.tipografia) + '">'
        + UI.esc(f.nombre) + '</button>';
    }).join('');
  }

  UI.$('#presets-install').addEventListener('click', function (e) {
    var b = e.target.closest('[data-preset]');
    if (!b) return;
    Tema.set({ preset: b.dataset.preset, colores: null });
    pintarIdentidad();
  });

  UI.$('#tipos-install').addEventListener('click', function (e) {
    var b = e.target.closest('[data-tipografia]');
    if (!b) return;
    Tema.set({ tipografia: b.dataset.tipografia });
    pintarIdentidad();
  });

  UI.$('#ev-nombre').addEventListener('input', function () {
    Tema.set({ evento: this.value.trim() || 'Evento sin nombre' });
  });
  UI.$('#ev-dependencia').addEventListener('input', function () {
    Tema.set({ dependencia: this.value.trim() });
  });

  /* =========================================================================
     Paso 6 · Cierre
     ========================================================================= */
  function instalar() {
    var prefijo = UI.$('#bd-prefijo').value.trim() || 'evt_';
    var dias = Number(UI.$('#ev-dias').value) || 3;

    pintarChecks('#resultado', [
      { nombre: 'Archivo de configuración', detalle: 'config/config.php', valor: 'escrito', estado: 'ok' },
      { nombre: 'Tablas del esquema', detalle: 'Prefijo ' + prefijo, valor: Esquema.tablas.length + ' tablas', estado: 'ok' },
      { nombre: 'Versión del esquema', detalle: 'Registrada en ' + prefijo + 'migracion', valor: Esquema.version, estado: 'ok' },
      { nombre: 'Cuenta administradora', detalle: UI.$('#ad-correo').value || 'sin definir', valor: 'creada', estado: 'ok' },
      { nombre: 'Segundo factor', detalle: 'Obligatorio para administradores', valor: UI.$('#ad-2fa').checked ? 'activado' : 'desactivado', estado: UI.$('#ad-2fa').checked ? 'ok' : 'warn' },
      { nombre: 'Primer evento', detalle: UI.$('#ev-nombre').value, valor: dias + ' jornadas', estado: 'ok' },
      { nombre: 'Códigos QR de acceso', detalle: 'Uno por jornada', valor: dias + ' generados', estado: 'ok' },
      { nombre: 'HTTPS', detalle: 'Sin TLS la plataforma no debe salir a producción', valor: 'pendiente', estado: 'warn' }
    ]);

    UI.$('#siguientes').innerHTML = [
      ['01', 'Elimina la carpeta de instalación', 'Borra o renombra public/install/. Es el paso que más se olvida y deja abierta una vía para reinstalar encima de los datos.'],
      ['02', 'Activa HTTPS', 'Un certificado válido en el dominio del evento. Sin él, las contraseñas y los tokens de carnet viajan en claro.'],
      ['03', 'Configura el envío de correo', 'Sin SMTP la plataforma no puede enviar el carnet ni los enlaces de acceso.'],
      ['04', 'Programa las copias de seguridad', 'Una copia diaria de la base durante la semana del evento, y una antes de cada actualización.'],
      ['05', 'Sube el logo y revisa el contraste', 'Desde Identidad del evento: la revisión de contraste avisa si el texto quedará ilegible bajo el sol.']
    ].map(function (s) {
      return '<div class="steps__item">'
        + '<span class="steps__n">' + s[0] + '</span>'
        + '<div class="stack" style="gap:5px">'
          + '<strong class="steps__t">' + UI.esc(s[1]) + '</strong>'
          + '<span class="steps__d">' + UI.esc(s[2]) + '</span>'
        + '</div></div>';
    }).join('');
  }

  /* ---- Arranque -------------------------------------------------------------- */
  pintarPasos();
  pintarChecks('#requisitos', REQUISITOS);
  pintarChecks('#permisos', PERMISOS);
  pintarModos();
  detectar();
  pintarIdentidad();
})();
