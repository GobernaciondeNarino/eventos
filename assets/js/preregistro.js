/* Preregistro: el perfil «expositor» y la casilla de exposición van juntos.

   La fotografía tiene su propio guion, foto.js: allí está el editor con el que
   se centra y se acerca antes de subirla.

   Quien marca que va a exponer casi siempre quiere el perfil de expositor, y
   al revés. Mantenerlos sincronizados evita el caso —frecuente en la fase de
   pruebas— de alguien que llena la propuesta pero queda registrado como
   participante y luego no entiende por qué su carnet no dice «expositor». */
(function () {
  'use strict';

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
