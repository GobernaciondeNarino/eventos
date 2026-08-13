"""Compara este generador de QR, matriz a matriz, contra la librería de
referencia `qrcode` de Python, con la máscara forzada para que la comparación
sea exacta. Cubre las versiones 1 a 20 y los cuatro niveles de corrección.

    pip install qrcode
    python3 pruebas/qr-contra-referencia.py
"""
import json, os, subprocess, sys, random, tempfile

AQUI = os.path.dirname(os.path.abspath(__file__))
import qrcode
from qrcode.util import QRData, MODE_8BIT_BYTE
from qrcode.constants import ERROR_CORRECT_L, ERROR_CORRECT_M, ERROR_CORRECT_Q, ERROR_CORRECT_H

LEVELS = {'L': ERROR_CORRECT_L, 'M': ERROR_CORRECT_M, 'Q': ERROR_CORRECT_Q, 'H': ERROR_CORRECT_H}
random.seed(7)

ALPHA = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789:/.-_?=&áéíóúñÑ"

cases = []
# textos reales de la plataforma + longitudes crecientes hasta versión ~20
fixed = [
    "https://eventos.narino.gov.co/c/AB12CD34EF",
    "https://eventos.narino.gov.co/d/EVT-2026-D1-4F9A2C",
    "EVTIC1|c|9f3a2b7d10c4|1|1755043200",
    "Ñañez Güépez — Secretaría TIC, Nariño",
]
for t in fixed:
    for lvl in "LMQH":
        for mask in range(8):
            cases.append({"text": t, "ecl": lvl, "mask": mask})

for n in [1, 7, 16, 30, 62, 120, 250, 400, 620, 900, 1200]:
    t = "".join(random.choice(ALPHA) for _ in range(n))
    for lvl in "LMQH":
        cases.append({"text": t, "ecl": lvl, "mask": random.randrange(8)})

casos = os.path.join(tempfile.gettempdir(), "casos-qr.json")
with open(casos, "w") as f:
    json.dump(cases, f)

js = json.loads(subprocess.check_output(["node", os.path.join(AQUI, "_qr-node.js"), casos], text=True))

fails, skipped, checked = [], 0, 0
for c, got in zip(cases, js):
    payload = c["text"].encode("utf-8")
    try:
        q = qrcode.QRCode(error_correction=LEVELS[c["ecl"]], border=0, mask_pattern=c["mask"])
        q.add_data(QRData(payload, mode=MODE_8BIT_BYTE))
        q.make(fit=True)
        if q.version > 20:
            skipped += 1
            continue
        ref = ["".join("1" if v else "0" for v in row) for row in q.modules]
    except Exception as e:
        skipped += 1
        continue

    if not got["ok"]:
        fails.append((c, "js error: " + got["err"]))
        continue
    checked += 1
    if got["version"] != q.version:
        fails.append((c, f"version js={got['version']} ref={q.version}"))
        continue
    if got["m"] != ref:
        diff = sum(1 for a, b in zip("".join(got["m"]), "".join(ref)) if a != b)
        fails.append((c, f"matriz difiere en {diff} módulos (v{q.version})"))

print(f"casos comparados: {checked}   omitidos: {skipped}   fallos: {len(fails)}")
for c, why in fails[:12]:
    print("  FALLO", c["ecl"], "mask", c["mask"], "len", len(c["text"]), "->", why)
sys.exit(1 if fails else 0)
