/* Recorre los caminos reales de la plataforma —preregistro, carnet, check-in,
   escáner del operador, identidad e instalador— y verifica que hagan lo que
   dicen. No basta con que la página cargue.

   Uso:  node pruebas/flujos.js

   Requiere el servidor estático levantado en el puerto 8899:
         npx http-server public -p 8899 -s
*/
const { chromium } = require('playwright');
const B = 'http://127.0.0.1:8899';

let ok = 0, mal = 0;
function check(nombre, condicion, extra) {
  if (condicion) { ok++; console.log('  ✓ ' + nombre); }
  else { mal++; console.log('  ✗ ' + nombre + (extra ? '  → ' + extra : '')); }
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 950 } });
  const errores = [];
  ctx.on('page', p => {
    p.on('pageerror', e => errores.push(p.url().split('/').pop() + ': ' + e.message));
    p.on('console', m => { if (m.type() === 'error') errores.push(p.url().split('/').pop() + ': ' + m.text()); });
  });
  const page = await ctx.newPage();

  // ---------- Preregistro ----------
  console.log('\nPreregistro');
  await page.goto(B + '/preregistro.html', { waitUntil: 'networkidle' });
  await page.fill('#nombre', 'A');
  await page.click('button[type=submit]');
  check('bloquea el envío con datos inválidos', await page.locator('#nombre.is-invalid').count() === 1);
  check('exige la autorización de datos', !(await page.locator('#habeas-error').isHidden()));

  await page.fill('#nombre', 'María Fernanda Zambrano');
  await page.fill('#doc', '1085234567');
  await page.click('#switch-expositor');
  check('el bloque de expositor aparece', await page.locator('#bloque-expositor').isVisible());
  await page.click('button[type=submit]');
  check('el expositor debe describir su tema', await page.locator('#tema.is-invalid').count() === 1);

  await page.fill('#tema', 'Datos abiertos para decidir mejor');
  await page.selectOption('#categoria', { index: 2 });
  await page.fill('#detalle', 'Un resumen suficientemente largo para pasar la validación de treinta caracteres.');
  await page.check('#habeas');
  await page.selectOption('#depto', 'Nariño');
  await page.waitForTimeout(800);
  check('el municipio se habilita tras elegir departamento', !(await page.locator('#municipio').isDisabled()));
  await page.selectOption('#municipio', 'Ipiales');
  await page.click('button[type=submit]');
  await page.waitForURL('**/carnet.html', { timeout: 5000 }).catch(() => {});
  check('el formulario válido lleva al carnet', page.url().endsWith('carnet.html'), page.url());

  // ---------- Carnet ----------
  console.log('\nCarnet');
  check('conserva el nombre capturado',
    (await page.locator('#carnet-campos').innerText()).includes('MARÍA FERNANDA ZAMBRANO'));
  check('conserva el municipio capturado',
    (await page.locator('#resumen').innerText()).includes('Ipiales'));
  await page.click('.chip[data-valor="organizador"]');
  check('el rol cambia el color del carnet',
    await page.getAttribute('#carnet', 'data-rol') === 'organizador');
  check('el rótulo del carnet cambia',
    (await page.locator('#carnet-rol').innerText()).trim() === 'ORGANIZADOR');
  await page.click('#voltear');
  await page.waitForTimeout(900);
  check('el reverso trae un QR real', await page.locator('#carnet-qr svg path').count() === 1);

  // ---------- Check-in ----------
  console.log('\nCheck-in');
  await page.goto(B + '/checkin.html', { waitUntil: 'networkidle' });
  await page.click('#escanear');
  await page.waitForTimeout(1200);
  check('tras escanear pide el correo', await page.locator('#pane-correo').isVisible());
  await page.fill('#correo-checkin', 'no-es-correo');
  await page.click('#confirmar');
  check('rechaza un correo inválido', await page.locator('#correo-checkin.is-invalid').count() === 1);
  await page.fill('#correo-checkin', 'mzambrano@narino.gov.co');
  await page.click('#confirmar');
  check('sella el ingreso', await page.locator('#pane-listo').isVisible());
  check('el historial muestra la jornada', (await page.locator('#historial').innerText()).includes('Día 1'));

  // ---------- Agenda ----------
  console.log('\nAgenda');
  await page.goto(B + '/agenda.html', { waitUntil: 'networkidle' });
  const dia1 = await page.locator('.talk__title').allInnerTexts();
  await page.click('.chip[data-valor="2"]');
  const dia2 = await page.locator('.talk__title').allInnerTexts();
  check('el cambio de día cambia la lista', JSON.stringify(dia1) !== JSON.stringify(dia2));
  await page.fill('#buscar-agenda', 'ciberseguridad');
  check('la búsqueda filtra', await page.locator('.talk').count() === 1);
  await page.click('.talk');
  check('abre el detalle en modal', await page.locator('.modal').count() === 1);
  await page.keyboard.press('Escape');
  check('Escape cierra el modal', await page.locator('.modal').count() === 0);

  // ---------- Contactos ----------
  console.log('\nContactos');
  await page.goto(B + '/contactos.html', { waitUntil: 'networkidle' });
  const antes = await page.locator('.contact').count();
  await page.click('#escanear-carnet');
  check('escanear agrega un contacto', await page.locator('.contact').count() === antes + 1);
  await page.click('#switch-tel');
  check('al ocultar el teléfono desaparece del listado',
    !(await page.locator('.contact').first().innerText()).includes('+57'));
  await page.click('#mi-qr');
  check('muestra mi QR de contacto', await page.locator('.modal svg path').count() === 1);
  await page.keyboard.press('Escape');

  // ---------- Registros ----------
  console.log('\nRegistros');
  await page.goto(B + '/admin/registros.html', { waitUntil: 'networkidle' });
  const todas = await page.locator('.table__row').count();
  await page.fill('#buscar', 'tumaco');
  check('la búsqueda filtra la tabla', await page.locator('.table__row').count() < todas);
  await page.fill('#buscar', '');
  await page.selectOption('#filtro-rol', 'expositor');
  check('el filtro por perfil funciona', await page.locator('.table__row').count() === 3);
  const [descarga] = await Promise.all([
    page.waitForEvent('download', { timeout: 5000 }).catch(() => null),
    page.click('[data-exportar="csv"]')
  ]);
  check('exporta un CSV', !!descarga && descarga.suggestedFilename() === 'registros.csv');

  // ---------- Escáner del operador ----------
  console.log('\nEscáner del operador');
  await page.goto(B + '/admin/escaner.html', { waitUntil: 'networkidle' });
  await page.click('#escanear');
  await page.waitForTimeout(1100);
  check('reconoce un carnet', await page.locator('#pane-encontrado').isVisible());
  await page.click('#confirmar');
  check('sella la asistencia', await page.locator('#pane-listo').isVisible());
  check('el contador de escaneos sube',
    (await page.locator('#conteo-escaneos').innerText()).includes('129'));

  // ---------- QR por día ----------
  console.log('\nQR por día');
  await page.goto(B + '/admin/qr-dias.html', { waitUntil: 'networkidle' });
  check('hay un QR por jornada', await page.locator('.qr-frame svg path').count() === 3);
  const tokenAntes = await page.locator('.card__body .mono').first().innerText();
  await page.click('[data-regenerar="1"]');
  await page.click('#confirmar-regen');
  await page.waitForTimeout(300);
  const tokenDespues = await page.locator('.card__body .mono').first().innerText();
  check('regenerar cambia el token', tokenDespues !== tokenAntes, tokenAntes + ' → ' + tokenDespues);
  check('el token nuevo mantiene el formato', /^EVT-2026-D1-[0-9A-F]{6}$/.test(tokenDespues), tokenDespues);

  // ---------- Expositores ----------
  console.log('\nExpositores');
  await page.goto(B + '/admin/expositores.html', { waitUntil: 'networkidle' });
  await page.click('.table__row');
  check('abre la propuesta', await page.locator('.modal').count() === 1);
  await page.click('[data-decidir="observada"]');
  check('exige la observación al devolver', await page.locator('.modal').count() === 1);
  await page.fill('#observacion', 'Ajustar la duración a 20 minutos.');
  await page.click('[data-decidir="aprobada"]');
  check('aprobar cierra el modal', await page.locator('.modal').count() === 0);

  // ---------- Identidad ----------
  console.log('\nIdentidad del evento');
  await page.goto(B + '/admin/identidad.html', { waitUntil: 'networkidle' });
  const acentoAntes = await page.evaluate(() =>
    getComputedStyle(document.documentElement).getPropertyValue('--c-accent').trim());
  await page.click('[data-preset="narino-verde"]');
  const acentoDespues = await page.evaluate(() =>
    getComputedStyle(document.documentElement).getPropertyValue('--c-accent').trim());
  check('el preset repinta la paleta', acentoAntes !== acentoDespues, acentoAntes + ' → ' + acentoDespues);

  await page.click('[data-tipografia="neutra"]');
  const fuente = await page.evaluate(() =>
    getComputedStyle(document.documentElement).getPropertyValue('--f-display').trim());
  check('la tipografía cambia', fuente.includes('Inter'), fuente);

  await page.fill('#nombre-evento', 'Hackatón Datos Abiertos');
  check('el nombre se refleja en la vista previa',
    (await page.locator('#vista-nombre').textContent()) === 'Hackatón Datos Abiertos');
  check('la revisión de contraste calcula', await page.locator('#contraste .check').count() === 5);

  await page.goto(B + '/index.html', { waitUntil: 'networkidle' });
  check('la identidad persiste entre pantallas',
    (await page.locator('.sidebar .brandtext__name').innerText()).toLowerCase().includes('hackatón'));
  await page.click('#restablecer').catch(() => {});

  // ---------- Instalador ----------
  console.log('\nInstalador');
  await page.goto(B + '/install/index.html', { waitUntil: 'networkidle' });
  await page.click('[data-siguiente="2"]');
  check('avanza al paso 2', await page.locator('[data-paso="2"]').isVisible());
  await page.click('[data-siguiente="3"]');
  check('no avanza sin datos de la base', await page.locator('[data-paso="2"]').isVisible());
  await page.fill('#bd-nombre', 'eventos_tic');
  await page.fill('#bd-usuario', 'eventos_app');
  await page.fill('#bd-prefijo', 'MAL-PREFIJO');
  await page.click('[data-siguiente="3"]');
  check('valida el prefijo de tablas', await page.locator('#bd-prefijo.is-invalid').count() === 1);
  await page.fill('#bd-prefijo', 'evt_');
  await page.click('#probar-conexion');
  await page.waitForTimeout(1100);
  check('la prueba de conexión responde',
    (await page.locator('#estado-conexion').innerText()).includes('correcta'));
  await page.click('[data-siguiente="3"]');
  check('llega al plan de tablas', await page.locator('[data-paso="3"]').isVisible());
  const sql = await page.locator('#sql').innerText();
  check('el SQL contiene las 14 tablas', (sql.match(/CREATE TABLE/g) || []).length === 14,
    (sql.match(/CREATE TABLE/g) || []).length + ' encontradas');
  check('el modo limpio avisa del borrado',
    (await page.locator('#texto-destructivo').innerText()).includes('borra datos'));
  check('el modo limpio genera DROP', sql.includes('DROP TABLE IF EXISTS'));

  await page.check('input[value="actualizar"]');
  const sql2 = await page.locator('#sql').innerText();
  check('el modo actualizar no borra', !sql2.includes('DROP TABLE'));
  check('el modo actualizar usa IF NOT EXISTS', sql2.includes('CREATE TABLE IF NOT EXISTS'));
  check('el modo actualizar propone un ALTER', sql2.includes('ALTER TABLE'));

  await page.check('input[value="anexar"]');
  check('anexar no toca lo existente',
    (await page.locator('#texto-destructivo').innerText()).includes('Solo se crean'));

  await page.click('[data-siguiente="4"]');
  await page.click('[data-siguiente="5"]');
  check('no avanza sin cuenta administradora', await page.locator('[data-paso="4"]').isVisible());
  await page.fill('#ad-nombre', 'Andrea Lucía Erazo');
  await page.fill('#ad-correo', 'aerazo@narino.gov.co');
  await page.fill('#ad-clave', 'corto');
  await page.click('[data-siguiente="5"]');
  check('exige contraseña de 12 caracteres', await page.locator('#ad-clave.is-invalid').count() === 1);
  await page.fill('#ad-clave', 'una frase larga y facil de recordar');
  await page.fill('#ad-clave2', 'otra distinta');
  await page.click('[data-siguiente="5"]');
  check('exige que las contraseñas coincidan', await page.locator('#ad-clave2.is-invalid').count() === 1);
  await page.fill('#ad-clave2', 'una frase larga y facil de recordar');
  await page.click('[data-siguiente="5"]');
  check('llega al paso del evento', await page.locator('[data-paso="5"]').isVisible());
  await page.click('[data-siguiente="6"]');
  check('termina la instalación', await page.locator('[data-paso="6"]').isVisible());
  check('el resultado lista lo hecho', await page.locator('#resultado .check').count() === 8);
  check('avisa de borrar la carpeta install',
    (await page.locator('.notice--danger').innerText()).includes('install'));

  await browser.close();
  console.log('\n' + '─'.repeat(52));
  console.log(`${ok} comprobaciones correctas · ${mal} fallidas`);
  if (errores.length) {
    console.log('\nErrores de consola:');
    [...new Set(errores)].slice(0, 10).forEach(e => console.log('  ! ' + e));
  }
  process.exit(mal || errores.length ? 1 : 0);
})();
