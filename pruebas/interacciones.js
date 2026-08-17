/* Lo que solo se puede comprobar en un navegador de verdad.

   extremo-a-extremo.php verifica lo que responde el servidor; esto verifica lo
   que pasa después: si las pestañas cambian de panel, si la ficha se abre en un
   diálogo sin recargar, si el lector de QR decodifica el código que la propia
   plataforma pinta, y si la portada del celular queda en una sola columna.

   Uso:  node pruebas/interacciones.js [http://127.0.0.1:8900/cumbreAI]

   Requiere la plataforma instalada y sirviéndose:
     BASE=/cumbreAI php -S 127.0.0.1:8900 -t . pruebas/servidor.php &
     php pruebas/extremo-a-extremo.php     (deja los datos de prueba) */

const { chromium } = require('playwright');
const fs = require('fs');

const EJECUTABLE = [
  '/opt/pw-browsers/chromium/chrome-linux/chrome',
  '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
].find(r => { try { return fs.existsSync(r); } catch (e) { return false; } });
const LANZAR = EJECUTABLE ? { executablePath: EJECUTABLE } : {};

const BASE = (process.argv[2] || 'http://127.0.0.1:8900/cumbreAI').replace(/\/$/, '');
const ADMIN = { correo: 'aerazo@narino.gov.co', clave: 'una frase larga y facil de recordar' };

let ok = 0;
const fallos = [];

function comprobar(nombre, condicion, extra = '') {
  if (condicion) {
    ok++;
    console.log('  ✓ ' + nombre);
  } else {
    fallos.push(nombre);
    console.log('  ✗ ' + nombre + (extra ? '  → ' + extra : ''));
  }
}

function titulo(t) {
  console.log('\n' + t + '\n' + '─'.repeat(58));
}

(async () => {
  const navegador = await chromium.launch(LANZAR);

  /* =====================================================================
     Portada en un teléfono
     ===================================================================== */
  titulo('Portada en 390 px');

  const movil = await navegador.newContext({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 3,
    isMobile: true,
    hasTouch: true,
  });
  const p1 = await movil.newPage();
  const erroresPortada = [];
  p1.on('console', m => { if (m.type() === 'error') erroresPortada.push(m.text()); });

  await p1.goto(BASE + '/', { waitUntil: 'networkidle' });

  const columnas = await p1.evaluate(() => {
    const split = document.querySelector('.split--balanced');
    if (!split) return null;
    const hijos = Array.from(split.children).map(el => Math.round(el.getBoundingClientRect().left));
    return { columnas: getComputedStyle(split).gridTemplateColumns, izquierdas: hijos };
  });
  comprobar('la portada usa .split--balanced', columnas !== null);
  comprobar('en el celular queda en una sola columna',
    columnas && !/\s/.test(columnas.columnas.trim()), columnas && columnas.columnas);
  comprobar('las dos secciones quedan una debajo de otra',
    columnas && new Set(columnas.izquierdas).size === 1,
    columnas && JSON.stringify(columnas.izquierdas));

  const anchoBoton = await p1.evaluate(() => {
    const b = Array.from(document.querySelectorAll('button, .btn'))
      .find(el => /preregistrarme|registrarme/i.test(el.textContent.trim()));
    return b ? Math.round(b.getBoundingClientRect().width) : 0;
  });
  comprobar('el botón principal ocupa el ancho de la tarjeta', anchoBoton > 250, String(anchoBoton));

  comprobar('«Entrar y ver mi carnet» es un botón',
    (await p1.locator('a.btn', { hasText: 'Entrar y ver mi carnet' }).count()) === 1);

  comprobar('sin errores de consola en la portada', erroresPortada.length === 0,
    erroresPortada.slice(0, 2).join(' | '));

  /* =====================================================================
     Panel: pestañas y ficha
     ===================================================================== */
  titulo('Panel');

  const escritorio = await navegador.newContext({ viewport: { width: 1440, height: 900 } });
  const p3 = await escritorio.newPage();
  const erroresPanel = [];
  p3.on('console', m => { if (m.type() === 'error') erroresPanel.push(m.text()); });

  await p3.goto(BASE + '/admin/entrar', { waitUntil: 'networkidle' });
  await p3.fill('#correo', ADMIN.correo);
  await p3.fill('#clave', ADMIN.clave);
  await p3.click('button[type=submit]');
  await p3.waitForLoadState('networkidle');

  /* ---------------------------------------------------------------------
     El lector de QR, sobre un código que pinta la propia plataforma
     ---------------------------------------------------------------------
     Se hace en /admin/escaner porque esa pantalla ya carga qr-lector.js: la
     política de seguridad de la plataforma no admite guiones inyectados, y
     está bien que no los admita.                                          */
  titulo('Lector de QR en el navegador');

  await p3.goto(BASE + '/admin/escaner', { waitUntil: 'networkidle' });

  const leido = await p3.evaluate(async (base) => {
    const salida = { hayLector: !!window.LectorQr, svg: '', leido: null };
    if (!window.LectorQr) return salida;

    const svg = await (await fetch(base + '/medios/qr/dia/1.svg', {
      credentials: 'same-origin'
    })).text().catch(() => '');
    salida.svg = svg.slice(0, 4);
    if (!svg.startsWith('<svg')) return salida;

    const imagen = await new Promise((resolver, rechazar) => {
      const blob = new Blob([svg], { type: 'image/svg+xml' });
      const url = URL.createObjectURL(blob);
      const img = new Image();
      img.onload = () => {
        const lienzo = document.createElement('canvas');
        lienzo.width = 420;
        lienzo.height = 420;
        const cx = lienzo.getContext('2d');
        cx.fillStyle = '#fff';
        cx.fillRect(0, 0, 420, 420);
        // Con margen alrededor, como cuando se fotografía un pliego pegado.
        cx.drawImage(img, 34, 34, 352, 352);
        URL.revokeObjectURL(url);
        resolver(cx.getImageData(0, 0, 420, 420));
      };
      img.onerror = () => { URL.revokeObjectURL(url); rechazar(new Error('svg')); };
      img.src = url;
    });

    try { salida.leido = window.LectorQr.desdeImagen(imagen); }
    catch (e) { salida.leido = 'ERROR: ' + e.message; }
    return salida;
  }, BASE);

  comprobar('el escáner carga el lector propio', leido.hayLector === true);
  comprobar('el servidor entrega el SVG del código del día', leido.svg === '<svg', leido.svg);
  comprobar('y el lector decodifica ese mismo código sobre un lienzo',
    typeof leido.leido === 'string' && leido.leido.includes('/d/'),
    String(leido.leido).slice(0, 70));

  comprobar('el escáner ofrece el «Lector desde cámara»',
    (await p3.locator('[data-escaner-foto]').count()) === 1);

  // ---- Pestañas de autenticación ----
  await p3.goto(BASE + '/admin/autenticacion', { waitUntil: 'networkidle' });

  const visiblesAlAbrir = await p3.evaluate(() =>
    Array.from(document.querySelectorAll('[role=tabpanel]'))
      .filter(el => el.offsetParent !== null).map(el => el.id));
  comprobar('solo se ve un panel al abrir', visiblesAlAbrir.length === 1,
    JSON.stringify(visiblesAlAbrir));

  await p3.click('[data-pestana="panel-whatsapp"]');
  const trasClic = await p3.evaluate(() =>
    Array.from(document.querySelectorAll('[role=tabpanel]'))
      .filter(el => el.offsetParent !== null).map(el => el.id));
  comprobar('al pulsar «WhatsApp» se cambia de panel',
    trasClic.length === 1 && trasClic[0] === 'panel-whatsapp', JSON.stringify(trasClic));

  comprobar('el campo de WhatsApp queda visible y utilizable',
    await p3.locator('#wa-cuenta').isVisible());

  // Con el teclado también: flecha derecha pasa a la siguiente.
  await p3.locator('[data-pestana="panel-whatsapp"]').focus();
  await p3.keyboard.press('ArrowRight');
  const conTeclado = await p3.evaluate(() =>
    Array.from(document.querySelectorAll('[role=tabpanel]'))
      .filter(el => el.offsetParent !== null).map(el => el.id));
  comprobar('las flechas del teclado también cambian de pestaña',
    conTeclado[0] === 'panel-sms', JSON.stringify(conTeclado));

  // ---- Ficha de una persona ----
  await p3.goto(BASE + '/admin/registros', { waitUntil: 'networkidle' });

  const antesDeAbrir = p3.url();
  // Se abre la de una persona concreta, no «la primera de la tabla»: el orden
  // del listado depende de los filtros y la prueba tiene que saber a quién mira.
  await p3.locator('[data-ficha][aria-label*="Zambrano"]').first().click();
  await p3.waitForSelector('#modal-ficha [data-ficha-contenido]', { timeout: 5000 });

  comprobar('la ficha se abre sin salir de la tabla', p3.url() === antesDeAbrir, p3.url());
  comprobar('el diálogo muestra el nombre de la persona',
    (await p3.locator('#modal-ficha').innerText()).includes('Zambrano'),
    (await p3.locator('#modal-ficha').innerText()).slice(0, 90).replace(/\n/g, ' | '));
  comprobar('y dice que la contraseña no se puede ver',
    (await p3.locator('#modal-ficha').innerText()).includes('no se puede ver'));

  await p3.keyboard.press('Escape');
  comprobar('se cierra con Escape',
    await p3.locator('#modal-ficha').isHidden());

  comprobar('sin errores de consola en el panel', erroresPanel.length === 0,
    erroresPanel.slice(0, 2).join(' | '));

  /* =====================================================================
     QR por día: agregar y eliminar
     ===================================================================== */
  titulo('Jornadas');

  await p3.goto(BASE + '/admin/qr-dias', { waitUntil: 'networkidle' });
  comprobar('la tarjeta de agregar un día está a la vista',
    await p3.locator('form[action$="/qr-dias/agregar"]').isVisible());
  comprobar('cada jornada ofrece cambiar fecha y horario',
    (await p3.locator('form[action$="/qr-dias/ajustar"]').count()) >= 1);

  await navegador.close();

  console.log('\n' + '─'.repeat(58));
  console.log(ok + ' comprobaciones correctas · ' + fallos.length + ' fallidas');
  fallos.forEach(f => console.log('  ✗ ' + f));
  process.exit(fallos.length ? 1 : 0);
})();
