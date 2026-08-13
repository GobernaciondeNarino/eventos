/* Carga cada pantalla en un navegador real y verifica que se arme el armazón,
   que no haya errores de consola y que nada desborde en horizontal.

   Uso:  node pruebas/paginas.js            (escritorio, 1440 px)
         ANCHO=390 node pruebas/paginas.js  (móvil)

   Requiere el servidor estático levantado en el puerto 8899:
         npx http-server public -p 8899 -s
*/
const { chromium } = require('playwright');
const path = require('path');

const BASE = 'http://127.0.0.1:8899';
const PAGINAS = [
  'index.html', 'preregistro.html', 'carnet.html', 'checkin.html', 'contactos.html', 'agenda.html',
  'admin/login.html', 'admin/index.html', 'admin/escaner.html', 'admin/registros.html',
  'admin/qr-dias.html', 'admin/expositores.html', 'admin/organizadores.html',
  'admin/eventos.html', 'admin/identidad.html', 'install/index.html'
];
const paginas = process.argv.slice(2).length ? process.argv.slice(2) : PAGINAS;

(async () => {
  const browser = await chromium.launch();
  let fallos = 0;
  for (const p of paginas) {
    const ancho = Number(process.env.ANCHO || 1440);
    const ctx = await browser.newContext({ viewport: { width: ancho, height: 950 }, isMobile: ancho < 700, hasTouch: ancho < 700 });
    const page = await ctx.newPage();
    const errores = [];
    page.on('console', m => { if (m.type() === 'error') errores.push('console: ' + m.text()); });
    page.on('pageerror', e => errores.push('pageerror: ' + e.message));
    page.on('requestfailed', r => {
      const u = r.url();
      if (!u.includes('fonts.googleapis') && !u.includes('fonts.gstatic')) {
        errores.push('request fallida: ' + u + ' — ' + (r.failure() || {}).errorText);
      }
    });
    const resp = await page.goto(BASE + '/' + p, { waitUntil: 'networkidle', timeout: 20000 }).catch(e => { errores.push('goto: ' + e.message); return null; });
    await page.waitForTimeout(700);

    // ¿la barra lateral se construyó? ¿hay contenido?
    const info = await page.evaluate(() => ({
      titulo: document.title,
      conShell: !!document.querySelector('.app'),
      nav: !!document.querySelector('.sidebar .navlink'),
      main: (document.querySelector('.main') || {}).childElementCount || 0,
      qrs: document.querySelectorAll('svg[viewBox]').length,
      alto: document.body.scrollHeight,
      desborde: document.documentElement.scrollWidth > window.innerWidth + 2
    }));
    const nombre = p.replace(/[\/]/g, '_').replace('.html', '');
    await page.screenshot({ path: path.join(__dirname, 'capturas', (process.env.ANCHO||'1440') + '-' + nombre + '.png'), fullPage: true });
    const navOk = info.conShell ? info.nav : true;
    const contenidoOk = info.conShell ? info.main > 0 : info.alto > 400;
    if (errores.length || !navOk || !contenidoOk || info.desborde) fallos++;
    console.log(`${resp && resp.ok() ? '·' : '!'} ${p.padEnd(28)} nav:${info.conShell?(info.nav?'si':'NO'):'n/a'} bloques:${info.main} alto:${info.alto} desbordeH:${info.desborde?'SI':'no'}`);
    errores.slice(0, 6).forEach(e => console.log('     ' + e));
    await ctx.close();
  }
  await browser.close();
  console.log(fallos ? `\n${fallos} página(s) con problemas` : '\nTodas las páginas cargan limpias');
  process.exit(fallos ? 1 : 0);
})();
