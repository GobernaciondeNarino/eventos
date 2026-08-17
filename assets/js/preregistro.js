/* Preregistro: el perfil «expositor» y la casilla de exposición van juntos.

   Quien marca que va a exponer casi siempre quiere el perfil de expositor, y
   al revés. Mantenerlos sincronizados evita el caso —frecuente en la fase de
   pruebas— de alguien que llena la propuesta pero queda registrado como
   participante y luego no entiende por qué su carnet no dice «expositor». */
(function () {
  'use strict';

  /* ---- Vista previa de la fotografía --------------------------------------
     Se lee el archivo en el propio navegador con una URL de objeto: no se sube
     nada hasta enviar el formulario. Sin esto, quien elige la foto en el
     celular no tiene forma de saber si tomó la que quería hasta después de
     guardar y volver al carnet.                                            */
  var campoFoto = document.querySelector('[data-vista-previa]');
  if (campoFoto) {
    var vista = document.getElementById(campoFoto.getAttribute('data-vista-previa'));
    var vacia = document.getElementById('foto-vacia');
    var anterior = null;

    campoFoto.addEventListener('change', function () {
      var archivo = campoFoto.files && campoFoto.files[0];
      if (!archivo || !vista) return;

      // La anterior se libera: si no, cada foto elegida deja su copia en
      // memoria hasta que se recarga la página.
      if (anterior) URL.revokeObjectURL(anterior);
      anterior = URL.createObjectURL(archivo);

      vista.src = anterior;
      vista.hidden = false;
      if (vacia) vacia.hidden = true;
    });

    window.addEventListener('pagehide', function () {
      if (anterior) URL.revokeObjectURL(anterior);
    });
  }

  var casilla = document.getElementById('expositor');
  var perfil = document.getElementById('rol');
  if (!casilla || !perfil) return;

  casilla.addEventListener('change', function () {
    if (casilla.checked && perfil.value === 'participante') {
      perfil.value = 'expositor';
    } else if (!casilla.checked && perfil.value === 'expositor') {
      perfil.value = 'participante';
    }
  });

  perfil.addEventListener('change', function () {
    if (perfil.value === 'expositor' && !casilla.checked) {
      casilla.checked = true;
      casilla.dispatchEvent(new Event('change'));
    }
  });
})();
