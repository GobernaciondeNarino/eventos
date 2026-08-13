/* =========================================================================
   layout.js — Armazón de la aplicación
   -------------------------------------------------------------------------
   Pinta la barra lateral, la barra superior móvil y la navegación inferior a
   partir de un único manifiesto. Cada página solo declara en su <body>:

     data-pantalla="agenda"     clave de la pantalla activa
     data-base="../"            ruta relativa a /public
     data-area="admin"          "publico" | "admin" (por defecto se deduce)

   En fase 2 estas mismas listas se generan en PHP: el manifiesto se traduce
   a un include y la marca de "activo" la pone el enrutador.
   ========================================================================= */
(function (global) {
  'use strict';

  var IC = {
    home: 'M3 10.6 12 3.5l9 7.1V20a1 1 0 0 1-1 1h-4.5v-6.5h-7V21H5a1 1 0 0 1-1-1z',
    form: 'M4 20.5h4l10.5-10.5-4-4L4 16.5zM14.5 6l4 4M4 3.5h6',
    card: 'M3 6h18v12H3zM7 10.5h3.5M7 14h6.5M16 9.5h3v3.5h-3z',
    qr: 'M4 8.5V4.5h4M20 8.5V4.5h-4M4 15.5v4h4M20 15.5v4h-4M4.5 12h15',
    users: 'M15.5 19.5v-1a3.5 3.5 0 0 0-7 0v1M12 11.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6M19.5 19.5v-1a3 3 0 0 0-2.2-2.9',
    cal: 'M4 6.5h16v14H4zM4 10.5h16M8.5 3.5v4M15.5 3.5v4M8 14h3M8 17.5h8',
    scan: 'M4 8V5a1 1 0 0 1 1-1h3M20 8V5a1 1 0 0 0-1-1h-3M4 16v3a1 1 0 0 0 1 1h3M20 16v3a1 1 0 0 1-1 1h-3M7 12h10',
    table: 'M3.5 5h17v14h-17zM3.5 10h17M9.5 10v9M15 10v9',
    grid: 'M4 4.5h6v6H4zM14 4.5h6v6h-6zM4 14h6v6H4zM14 14h2.5v2.5H14M18 17.5h2v2h-2',
    theme: 'M12 3.5a8.5 8.5 0 1 0 0 17 1.9 1.9 0 0 0 0-3.8 4.7 4.7 0 0 1 0-9.4 8.5 8.5 0 0 0 0-3.8M8 8.5h.01M7 13h.01M12 7h.01',
    user: 'M12 12.5a4 4 0 1 0 0-8 4 4 0 0 0 0 8M5 20.5a7 7 0 0 1 14 0',
    panel: 'M4 4.5h6.5v6H4zM13.5 4.5H20v10h-6.5zM4 13.5h6.5v6H4zM13.5 17.5H20v2h-6.5z',
    mic: 'M12 3.5a2.5 2.5 0 0 1 2.5 2.5v6a2.5 2.5 0 0 1-5 0V6A2.5 2.5 0 0 1 12 3.5M6 11.5a6 6 0 0 0 12 0M12 17.5v3',
    evento: 'M3.5 7.5h17v12h-17zM3.5 11.5h17M8 4v3.5M16 4v3.5M7.5 15h4',
    salir: 'M9 5.5H5.5v13H9M14 8.5l3.5 3.5L14 15.5M17 12H9'
  };

  var PUBLICO = [
    { clave: 'ingreso', etiqueta: 'Ingreso', icono: IC.home, url: 'index.html' },
    { clave: 'preregistro', etiqueta: 'Preregistro', icono: IC.form, url: 'preregistro.html' },
    { clave: 'carnet', etiqueta: 'Mi carnet', icono: IC.card, url: 'carnet.html' },
    { clave: 'checkin', etiqueta: 'Check-in QR', icono: IC.qr, url: 'checkin.html' },
    { clave: 'contactos', etiqueta: 'Contactos', icono: IC.users, url: 'contactos.html' },
    { clave: 'agenda', etiqueta: 'Agenda', icono: IC.cal, url: 'agenda.html' }
  ];

  var ADMIN = [
    { clave: 'panel', etiqueta: 'Panel', icono: IC.panel, url: 'admin/index.html' },
    { clave: 'admin-escaner', etiqueta: 'Escanear carnet', icono: IC.scan, url: 'admin/escaner.html' },
    { clave: 'admin-registros', etiqueta: 'Registros', icono: IC.table, url: 'admin/registros.html' },
    { clave: 'admin-qr', etiqueta: 'QR por día', icono: IC.grid, url: 'admin/qr-dias.html' },
    { clave: 'admin-expositores', etiqueta: 'Expositores', icono: IC.mic, url: 'admin/expositores.html' },
    { clave: 'admin-organizadores', etiqueta: 'Organizadores', icono: IC.users, url: 'admin/organizadores.html' },
    { clave: 'admin-eventos', etiqueta: 'Eventos', icono: IC.evento, url: 'admin/eventos.html' },
    { clave: 'admin-identidad', etiqueta: 'Identidad', icono: IC.theme, url: 'admin/identidad.html' }
  ];

  var MOVIL = ['ingreso', 'agenda', 'checkin', 'contactos'];

  function icono(d, tam, grosor) {
    return '<svg viewBox="0 0 24 24" width="' + (tam || 17) + '" height="' + (tam || 17) + '"'
      + ' fill="none" stroke="currentColor" stroke-width="' + (grosor || 1.5) + '"'
      + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<path d="' + d + '"></path></svg>';
  }

  function enlace(item, activa, base) {
    var esActiva = item.clave === activa;
    return '<a class="navlink' + (esActiva ? ' is-active' : '') + '" href="' + base + item.url + '"'
      + (esActiva ? ' aria-current="page"' : '') + '>'
      + icono(item.icono) + '<span>' + UI.esc(item.etiqueta) + '</span></a>';
  }

  function marca(tema, tam) {
    var contenido = tema.logo
      ? '<img src="' + UI.esc(tema.logo) + '" alt="">'
      : UI.esc(Tema.iniciales());
    return '<span class="brandmark' + (tam ? ' brandmark--' + tam : '') + '" aria-hidden="true">' + contenido + '</span>';
  }

  function construir() {
    var cuerpo = document.body;
    var activa = cuerpo.getAttribute('data-pantalla') || '';
    var base = cuerpo.getAttribute('data-base') || './';
    var tema = Tema.get();
    var todas = PUBLICO.concat(ADMIN);
    var actual = todas.filter(function (i) { return i.clave === activa; })[0];

    /* ---- Barra lateral ---- */
    var lateral = document.createElement('aside');
    lateral.className = 'sidebar';
    lateral.innerHTML =
      '<div class="sidebar__brand">' + marca(tema) +
        '<span class="brandtext">' +
          '<span class="brandtext__name">' + UI.esc(tema.evento) + '</span>' +
          '<span class="brandtext__sub">Plataforma de eventos</span>' +
        '</span>' +
      '</div>' +
      '<nav class="sidebar__nav" aria-label="Navegación principal">' +
        '<div class="sidebar__group">' +
          '<span class="sidebar__groupLabel">Participante</span>' +
          PUBLICO.map(function (i) { return enlace(i, activa, base); }).join('') +
        '</div>' +
        '<div class="sidebar__group">' +
          '<span class="sidebar__groupLabel">Administración</span>' +
          ADMIN.map(function (i) { return enlace(i, activa, base); }).join('') +
        '</div>' +
      '</nav>' +
      '<div class="sidebar__foot"><span class="pulse"></span><span>Sesión segura</span></div>';

    /* ---- Barra superior (móvil) ---- */
    var superior = document.createElement('header');
    superior.className = 'topbar';
    superior.innerHTML =
      '<div class="row" style="min-width:0;gap:10px">' + marca(tema, 'sm') +
        '<span class="brandtext__name">' + UI.esc(tema.evento) + '</span>' +
      '</div>' +
      '<span class="topbar__screen">' + UI.esc(actual ? actual.etiqueta : '') + '</span>';

    /* ---- Navegación inferior (móvil) ---- */
    var pestanas = MOVIL.map(function (clave) {
      var i = todas.filter(function (x) { return x.clave === clave; })[0];
      var act = clave === activa;
      return '<a class="tabbar__btn' + (act ? ' is-active' : '') + '" href="' + base + i.url + '"'
        + ' aria-label="' + UI.esc(i.etiqueta) + '"' + (act ? ' aria-current="page"' : '') + '>'
        + icono(i.icono, 22, act ? 2 : 1.5) + '</a>';
    }).join('');

    var inferior = document.createElement('nav');
    inferior.className = 'tabbar';
    inferior.setAttribute('aria-label', 'Navegación rápida');
    inferior.innerHTML = '<div class="tabbar__inner">' + pestanas +
      '<button class="tabbar__btn" type="button" id="abrir-menu" aria-label="Más opciones" aria-expanded="false">' +
      icono(IC.user, 22, 1.5) + '</button></div>';

    var contenedor = document.querySelector('.app');
    var columna = document.querySelector('.app__body');
    if (!contenedor || !columna) return;
    contenedor.insertBefore(lateral, contenedor.firstChild);
    columna.insertBefore(superior, columna.firstChild);
    document.body.appendChild(inferior);

    /* ---- Hoja inferior con el menú completo ---- */
    document.getElementById('abrir-menu').addEventListener('click', function () {
      var boton = this;
      boton.setAttribute('aria-expanded', 'true');
      var hoja = document.createElement('div');
      hoja.className = 'sheet';
      hoja.innerHTML =
        '<div class="sheet__panel" role="dialog" aria-label="Ir a">' +
          '<div class="card__head"><span>Ir a</span></div>' +
          '<div class="sheet__list">' +
            todas.map(function (i) { return enlace(i, activa, base); }).join('') +
          '</div>' +
        '</div>';
      hoja.addEventListener('click', function (e) {
        if (e.target === hoja) { hoja.remove(); boton.setAttribute('aria-expanded', 'false'); }
      });
      document.body.appendChild(hoja);
    });
  }

  function saltoDeContenido() {
    if (document.querySelector('.skip-link')) return;
    var a = document.createElement('a');
    a.className = 'skip-link';
    a.href = '#contenido';
    a.textContent = 'Saltar al contenido';
    document.body.insertBefore(a, document.body.firstChild);
  }

  function iniciar() {
    saltoDeContenido();
    Tema.aplicar();   // ya hay <body>: se completa el título con el nombre del evento
    construir();
    // Si cambia la identidad en otra pestaña, la barra se redibuja.
    document.addEventListener('tema:cambio', function () {
      var vieja = document.querySelector('.sidebar');
      var top = document.querySelector('.topbar');
      var tab = document.querySelector('.tabbar');
      if (vieja) vieja.remove();
      if (top) top.remove();
      if (tab) tab.remove();
      construir();
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();

  global.Layout = { iconos: IC, publico: PUBLICO, admin: ADMIN, icono: icono, marca: marca };
})(window);
