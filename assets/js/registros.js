/* =========================================================================
   registros.js — La ficha de una persona, dentro de un diálogo.
   -------------------------------------------------------------------------
   Sin este guion todo sigue funcionando: el botón de perfil es un enlace a
   /admin/registros/{id}, que es una pantalla completa. Lo que aporta esto es
   no perder el filtro ni la posición de la tabla al mirar a una persona, que
   en una jornada con seiscientos registros importa.

   Las acciones de dentro de la ficha —generar una contraseña nueva, ver el QR
   de acceso— se envían igual por POST y la respuesta vuelve a ser la ficha, así
   que se reemplaza el contenido del diálogo y se sigue.
   ========================================================================= */
(function () {
  'use strict';

  var dialogo = document.getElementById('modal-ficha');
  if (!dialogo || !window.App || !window.fetch) return;

  var destino = dialogo.querySelector('[data-ficha-destino]');
  if (!destino) return;

  var cargando = '<div class="row" style="justify-content:center;padding:24px">'
    + '<span class="spinner"></span></div>';

  function pintar(html) {
    destino.innerHTML = html;
    // El diálogo se acaba de llenar: el foco va al primer elemento útil, que
    // es lo que espera quien navega con teclado o con lector de pantalla.
    var primero = destino.querySelector('button, [href]');
    if (primero) primero.focus();
  }

  function traer(url, opciones) {
    destino.innerHTML = cargando;

    return fetch(url, Object.assign({
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }, opciones || {}))
      .then(function (r) {
        if (!r.ok) throw new Error(String(r.status));
        return r.text();
      })
      .then(pintar)
      .catch(function () {
        // Si algo falla se ofrece la pantalla completa, que no depende de esto.
        destino.innerHTML = '<div class="notice notice--warn">'
          + '<span class="notice__icon">▲</span><span>No se pudo cargar la ficha. '
          + '<a href="' + url.split('?')[0] + '">Ábrela en su propia página</a>.</span></div>';
      });
  }

  /* ---- Abrir desde la tabla ------------------------------------------- */
  document.addEventListener('click', function (e) {
    var boton = e.target.closest('[data-ficha]');
    if (!boton) return;
    e.preventDefault();
    window.App.abrirDialogo(dialogo);
    traer(boton.getAttribute('data-ficha'));
  });

  /* ---- Enlaces de dentro de la ficha ---------------------------------- */
  destino.addEventListener('click', function (e) {
    var enlace = e.target.closest('[data-ficha-ver]');
    if (!enlace) return;
    e.preventDefault();
    traer(enlace.getAttribute('data-ficha-ver'));
  });

  /* ---- Formularios de dentro de la ficha ------------------------------ */
  destino.addEventListener('submit', function (e) {
    var formulario = e.target.closest('[data-ficha-accion]');
    if (!formulario) return;

    var pregunta = formulario.getAttribute('data-confirmar');
    if (pregunta && !window.confirm(pregunta)) {
      e.preventDefault();
      return;
    }

    e.preventDefault();
    traer(formulario.getAttribute('action'), {
      method: 'POST',
      body: new FormData(formulario)
    });
  });
})();
