/* Volteo del carnet. El reverso —donde está el QR— se muestra al tocarlo. */
(function () {
  'use strict';

  var carnet = document.getElementById('carnet');
  var boton = document.getElementById('voltear');
  if (!carnet) return;

  function voltear() {
    var reverso = carnet.classList.toggle('is-flipped');
    if (boton) boton.textContent = reverso ? 'Ver el anverso' : 'Ver el reverso';
    carnet.setAttribute('aria-label', reverso
      ? 'Carnet digital, reverso con el código QR. Presiona para volver al anverso.'
      : 'Carnet digital, anverso. Presiona para ver el reverso con el código QR.');
  }

  carnet.addEventListener('click', voltear);
  if (boton) {
    boton.addEventListener('click', function (e) {
      e.stopPropagation();
      voltear();
    });
  }
})();
