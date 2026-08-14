/* =========================================================================
   escaner.js — Lector de QR dentro de la aplicación.
   -------------------------------------------------------------------------
   No es la vía principal: los códigos de esta plataforma son URLs, así que la
   aplicación de cámara del propio teléfono los abre sin necesidad de nada de
   esto. Este lector existe para quien ya está dentro de la plataforma y
   prefiere no salir, y para los operadores que acreditan a mucha gente
   seguida.

   Usa BarcodeDetector, que está en Chrome y en los navegadores de Android. En
   los que no lo tienen, se explica la alternativa en vez de dejar un botón que
   no hace nada.
   ========================================================================= */
(function () {
  'use strict';

  var panel = document.querySelector('[data-escaner]');
  if (!panel) return;

  var video = panel.querySelector('[data-escaner-video]');
  var boton = panel.querySelector('[data-escaner-iniciar]');
  var pista = panel.querySelector('[data-escaner-pista]');
  var alterna = panel.querySelector('[data-escaner-alterna]');

  var soportado = 'BarcodeDetector' in window
    && navigator.mediaDevices
    && typeof navigator.mediaDevices.getUserMedia === 'function';

  if (!soportado) {
    boton.disabled = true;
    boton.textContent = 'Lector no disponible en este navegador';
    if (alterna) {
      alterna.innerHTML = '<strong>Usa la cámara del teléfono.</strong> Abre la aplicación de '
        + 'cámara y apunta al código: se abrirá esta misma plataforma y el registro quedará hecho. '
        + 'El lector integrado necesita un navegador basado en Chrome.';
    }
    return;
  }

  var flujo = null;
  var detector = null;
  var buscando = false;

  function detener() {
    buscando = false;
    if (flujo) {
      flujo.getTracks().forEach(function (pista) { pista.stop(); });
      flujo = null;
    }
    if (video) {
      video.hidden = true;
      video.srcObject = null;
    }
    boton.textContent = 'Abrir la cámara';
  }

  function fallar(mensaje) {
    detener();
    if (pista) pista.textContent = mensaje;
  }

  boton.addEventListener('click', function () {
    if (buscando) {
      detener();
      return;
    }
    iniciar();
  });

  function iniciar() {
    boton.textContent = 'Detener';
    if (pista) pista.textContent = 'Buscando un código…';

    navigator.mediaDevices.getUserMedia({
      // La cámara trasera es la que se usa para escanear.
      video: { facingMode: { ideal: 'environment' } },
      audio: false
    }).then(function (obtenido) {
      flujo = obtenido;
      video.srcObject = flujo;
      video.hidden = false;
      return video.play();
    }).then(function () {
      detector = new window.BarcodeDetector({ formats: ['qr_code'] });
      buscando = true;
      buscar();
    }).catch(function (error) {
      if (error && error.name === 'NotAllowedError') {
        fallar('No diste permiso para la cámara. Puedes usar la aplicación de cámara del teléfono.');
      } else if (error && error.name === 'NotFoundError') {
        fallar('Este dispositivo no tiene cámara disponible.');
      } else {
        fallar('No se pudo abrir la cámara. Usa la aplicación de cámara del teléfono.');
      }
    });
  }

  function buscar() {
    if (!buscando) return;

    detector.detect(video).then(function (codigos) {
      if (!buscando) return;

      for (var i = 0; i < codigos.length; i++) {
        var valor = codigos[i].rawValue || '';
        if (esNuestro(valor)) {
          buscando = false;
          if (pista) pista.textContent = 'Código reconocido. Abriendo…';
          detener();
          window.location.href = valor;
          return;
        }
      }
      requestAnimationFrame(buscar);
    }).catch(function () {
      // Un fotograma ilegible no es un error: se sigue intentando.
      if (buscando) requestAnimationFrame(buscar);
    });
  }

  /**
   * Solo se sigue un código si apunta a esta misma instalación.
   *
   * Sin esta comprobación, el lector se convertiría en un redirector abierto:
   * bastaría con pegar un QR falso encima del de la puerta para llevar a los
   * asistentes a una página que imite el acceso y les pida el correo.
   */
  function esNuestro(valor) {
    try {
      var url = new URL(valor, window.location.href);
      if (url.origin !== window.location.origin) return false;

      var base = document.body.getAttribute('data-base') || '/';
      if (!url.pathname.startsWith(base)) return false;

      // La base llega sin barra final ('/cumbreAI'), así que al recortarla
      // queda '/d/xxxx' con barra delante. Sin quitarla, la expresión no casa
      // y el lector descarta todos los códigos: funcionaba solo cuando la
      // aplicación colgaba de la raíz del dominio.
      var resto = url.pathname.slice(base.length).replace(/^\/+/, '');
      return /^(d|c)\/[a-f0-9]{16,64}$/.test(resto);
    } catch (e) {
      return false;
    }
  }

  window.addEventListener('pagehide', detener);
})();
