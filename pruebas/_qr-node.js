const QR = require('../public/assets/js/qr.js');
const cases = JSON.parse(require('fs').readFileSync(process.argv[2], 'utf8'));
const out = cases.map(c => {
  try {
    const r = QR.encode(c.text, c.ecl, c.mask);
    return { ok: true, version: r.version, size: r.size, mask: r.mask,
             m: r.modules.map(row => row.map(v => v ? 1 : 0).join('')) };
  } catch (e) { return { ok: false, err: e.message }; }
});
console.log(JSON.stringify(out));
