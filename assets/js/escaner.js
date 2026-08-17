/* =========================================================================
   escaner.js — Lector de QR dentro de la aplicación.
   -------------------------------------------------------------------------
   No es la vía principal: los códigos de esta plataforma son URLs, así que la
   aplicación de cámara del propio teléfono los abre sin necesidad de nada de
   esto. Este lector existe para quien ya está dentro de la plataforma y
   prefiere no salir, y para los operadores que acreditan a mucha gente
   seguida.

   Hay tres caminos, y se usan en este orden:

     1. BarcodeDetector, que decodifica en el navegador y es lo más rápido.
        Está en Chrome y en los navegadores de Android.

     2. Nuestro decodificador (qr-lector.js) sobre los fotogramas del vídeo.
        Es el camino de Safari en iPhone, que no trae BarcodeDetector: ahí el
        lector decía «lector no disponible en este navegador» y dejaba al
        operador tecleando cédulas en la puerta.

     3. Una foto tomada con la aplicación de cámara del sistema, que se
        decodifica igual que un fotograma. Es la salida cuando el navegador no
        entrega la cámara en directo: un navegador dentro de otra aplicación
        —el de WhatsApp, el del correo—, o un permiso denegado.

   El tercero siempre está disponible, porque siempre puede hacer falta.
   ========================================================================= */
(function () {
  'use strict';

  var panel = document.querySelector('[data-escaner]');
  if (!panel) return;

  var video = panel.querySelector('[data-escaner-video]');
  var boton = panel.querySelector('[data-escaner-iniciar]');
  var botonFoto = panel.querySelector('[data-escaner-foto]');
  var campoFoto = panel.querySelector('[data-escaner-archivo]');
  var pista = panel.querySelector('[data-escaner-pista]');
  var alterna = panel.querySelector('[data-escaner-alterna]');

  var hayCamara = !!(navigator.mediaDevices
    && typeof navigator.mediaDevices.getUserMedia === 'function');
  var hayNativo = 'BarcodeDetector' in window;
  var hayNuestro = !!window.LectorQr;

  var flujo = null;
  var detector = null;
  var buscando = false;
  var lienzo = null;
  var contexto = null;
  var ultimoIntento = 0;

  /* ---------------------------------------------------------------------
     Estado inicial de la pantalla
     --------------------------------------------------------------------- */

  if (!hayCamara && !hayNuestro) {
    // Ni cámara en directo ni decodificador: no queda nada que ofrecer.
    boton.disabled = true;
    boton.textContent = 'Lector no disponible en este navegador';
    if (alterna) {
      alterna.innerHTML = '<strong>Usa la cámara del teléfono.</strong> Abre la aplicación de '
        + 'cámara y apunta al código: se abrirá esta misma plataforma y el registro quedará hecho.';
    }
  } else if (!hayCamara) {
    // Sin vídeo en directo, pero se puede tomar una foto.
    boton.hidden = true;
    if (alterna) {
      alterna.textContent = 'Este navegador no entrega la cámara en directo. Toma una foto del '
        + 'código con el botón de abajo y la leemos igual.';
    }
  }

  if (botonFoto) {
    botonFoto.hidden = !hayNuestro;
  }

  function mensaje(texto) {
    if (pista) pista.textContent = texto;
  }

  /* ---------------------------------------------------------------------
     Cámara en directo
     --------------------------------------------------------------------- */

  function detener() {
    buscando = false;
    if (flujo) {
      flujo.getTracks().forEach(function (t) { t.stop(); });
      flujo = null;
    }
    if (video) {
      video.hidden = true;
      video.srcObject = null;
    }
    boton.textContent = 'Abrir la cámara';
  }

  function fallar(texto) {
    detener();
    mensaje(texto);
  }

  if (boton) {
    boton.addEventListener('click', function () {
      if (buscando) { detener(); return; }
      iniciar();
    });
  }

  function iniciar() {
    boton.textContent = 'Detener';
    mensaje('Buscando un código…');

    navigator.mediaDevices.getUserMedia({
      // La cámara trasera es la que se usa para escanear. La resolución se
      // pide alta a propósito: un QR pequeño en la pantalla de otro teléfono
      // no tiene suficientes píxeles por módulo a 640×480.
      video: {
        facingMode: { ideal: 'environment' },
        width: { ideal: 1280 },
        height: { ideal: 720 }
      },
      audio: false
    }).then(function (obtenido) {
      flujo = obtenido;
      video.srcObject = flujo;
      video.hidden = false;
      return video.play();
    }).then(function () {
      if (hayNativo) {
        detector = new window.BarcodeDetector({ formats: ['qr_code'] });
      }
      buscando = true;
      buscar();
    }).catch(function (error) {
      if (error && error.name === 'NotAllowedError') {
        fallar('No diste permiso para la cámara. Puedes tomar una foto del código.');
      } else if (error && error.name === 'NotFoundError') {
        fallar('Este dispositivo no tiene cámara disponible.');
      } else {
        fallar('No se pudo abrir la cámara. Prueba tomando una foto del código.');
      }
    });
  }

  function buscar() {
    if (!buscando) return;

    if (detector) {
      detector.detect(video).then(function (codigos) {
        if (!buscando) return;
        for (var i = 0; i < codigos.length; i++) {
          if (seguir(codigos[i].rawValue || '')) return;
        }
        requestAnimationFrame(buscar);
      }).catch(function () {
        // Un fotograma ilegible no es un error: se sigue intentando.
        if (buscando) requestAnimationFrame(buscar);
      });
      return;
    }

    // Decodificación propia. Se limita a unos diez intentos por segundo: más
    // no lee más códigos y sí calienta el teléfono y le gasta la batería
    // durante un turno entero en la puerta.
    var ahora = Date.now();
    if (ahora - ultimoIntento < 90) {
      requestAnimationFrame(buscar);
      return;
    }
    ultimoIntento = ahora;

    var imagen = fotograma(video, video.videoWidth, video.videoHeight);
    if (imagen) {
      var valor = null;
      try { valor = window.LectorQr.desdeImagen(imagen); } catch (e) { valor = null; }
      if (valor && seguir(valor)) return;
    }
    requestAnimationFrame(buscar);
  }

  /**
   * Copia el fotograma a un lienzo y devuelve sus píxeles.
   *
   * Se reduce a 720 px de lado mayor: por encima de eso no se lee ni un código
   * más y cada fotograma cuesta el doble.
   */
  function fotograma(fuente, ancho, alto) {
    if (!ancho || !alto) return null;

    var escala = Math.min(1, 720 / Math.max(ancho, alto));
    var w = Math.max(1, Math.round(ancho * escala));
    var h = Math.max(1, Math.round(alto * escala));

    if (!lienzo) {
      lienzo = document.createElement('canvas');
      contexto = lienzo.getContext('2d', { willReadFrequently: true });
    }
    if (!contexto) return null;

    if (lienzo.width !== w || lienzo.height !== h) {
      lienzo.width = w;
      lienzo.height = h;
    }

    try {
      contexto.drawImage(fuente, 0, 0, w, h);
      return contexto.getImageData(0, 0, w, h);
    } catch (e) {
      return null;
    }
  }

  /* ---------------------------------------------------------------------
     Foto tomada con la aplicación de cámara del sistema
     --------------------------------------------------------------------- */

  if (botonFoto && campoFoto) {
    botonFoto.addEventListener('click', function () {
      campoFoto.click();
    });

    campoFoto.addEventListener('change', function () {
      var archivo = campoFoto.files && campoFoto.files[0];
      if (!archivo) return;

      mensaje('Leyendo la foto…');
      var url = URL.createObjectURL(archivo);
      var imagen = new Image();

      imagen.onload = function () {
        URL.revokeObjectURL(url);
        var datos = fotograma(imagen, imagen.naturalWidth, imagen.naturalHeight);
        var valor = null;
        if (datos) {
          try { valor = window.LectorQr.desdeImagen(datos); } catch (e) { valor = null; }
        }

        if (!valor) {
          mensaje('No se distingue ningún código en esa foto. Acércate más y que el código '
            + 'quede completo y enfocado.');
          campoFoto.value = '';
          return;
        }
        if (!seguir(valor)) {
          mensaje('Ese código no es de esta plataforma.');
          campoFoto.value = '';
        }
      };

      imagen.onerror = function () {
        URL.revokeObjectURL(url);
        mensaje('No se pudo abrir esa imagen.');
        campoFoto.value = '';
      };

      imagen.src = url;
    });
  }

  /* ---------------------------------------------------------------------
     Seguir un código
     --------------------------------------------------------------------- */

  /** Devuelve true si el código era nuestro y ya se está abriendo. */
  function seguir(valor) {
    if (!esNuestro(valor)) return false;
    buscando = false;
    mensaje('Código reconocido. Abriendo…');
    detener();
    window.location.href = valor;
    return true;
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

      // Los tres códigos que emite la plataforma: jornada, carnet y acceso.
      return /^(d|c)\/[a-f0-9]{16,64}$/.test(resto)
        || /^entrar\/qr\/[a-f0-9]{16,64}$/.test(resto);
    } catch (e) {
      return false;
    }
  }

  window.addEventListener('pagehide', detener);
})();
