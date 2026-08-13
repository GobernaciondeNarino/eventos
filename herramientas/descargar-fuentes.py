#!/usr/bin/env python3
"""Descarga las tipografías de los presets y las deja autoalojadas.

Por qué autoalojar y no usar el CDN de Google:
  1. La interfaz se ve igual en redes cerradas o con salida restringida, que es
     el caso de varias sedes y alcaldías del departamento.
  2. La IP de cada asistente dejaría de viajar a un tercero en cada visita, algo
     difícil de justificar en una plataforma pública sujeta a la Ley 1581.
  3. Permite una política de seguridad de contenido sin orígenes externos
     (ver docs/SEGURIDAD.md).

Uso:
    python3 herramientas/descargar-fuentes.py

Escribe los .woff2 y el archivo fuentes.css en public/assets/fonts/.
Solo hay que volver a ejecutarlo si se agregan presets tipográficos nuevos.
"""
import os
import re
import subprocess

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DESTINO = os.path.join(RAIZ, "public", "assets", "fonts")

# El agente de usuario decide el formato que entrega Google: con uno moderno
# devuelve woff2, que es la mitad de peso que woff.
UA = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) "
      "Chrome/120.0.0.0 Safari/537.36")

FAMILIAS = [
    "Chakra+Petch:wght@500;600;700",
    "IBM+Plex+Sans:wght@400;500;600",
    "IBM+Plex+Mono:wght@400;500",
    "Barlow+Condensed:wght@600;700",
    "Source+Sans+3:wght@400;600",
    "Inter:wght@400;600;700",
    "JetBrains+Mono:wght@400",
    "Space+Grotesk:wght@500;700",
]

# Suficiente para español: incluye tildes, ñ y signos de apertura.
SUBCONJUNTOS = {"latin", "latin-ext"}

BLOQUE = re.compile(r"(?:/\*\s*([a-z0-9\-]+)\s*\*/\s*)?@font-face\s*\{([^}]*)\}")

ENCABEZADO = """/* =========================================================================
   Tipografías autoalojadas — generadas con herramientas/descargar-fuentes.py
   -------------------------------------------------------------------------
   Ninguna pantalla consulta un CDN: los archivos WOFF2 viven en este mismo
   directorio. Así la interfaz se ve igual en redes cerradas y no se filtra la
   IP de los asistentes a un tercero.

   Familias incluidas, una por preset tipográfico:
     tecnológica    Chakra Petch · IBM Plex Sans · IBM Plex Mono
     institucional  Barlow Condensed · Source Sans 3 · IBM Plex Mono
     neutra         Inter · JetBrains Mono
     editorial      Space Grotesk · IBM Plex Sans · IBM Plex Mono

   Están todas declaradas, pero el navegador solo descarga la familia que el
   tema activo usa de verdad: una @font-face sin texto que la use no se pide.
   ========================================================================= */

"""


def traer(url, binario=False):
    salida = subprocess.run(
        ["curl", "-sS", "-L", "--max-time", "60", "-A", UA, url],
        capture_output=True, check=True)
    return salida.stdout if binario else salida.stdout.decode("utf-8")


def main():
    os.makedirs(DESTINO, exist_ok=True)
    declaraciones, nuevos, omitidos = [], 0, 0

    for familia in FAMILIAS:
        css = traer(f"https://fonts.googleapis.com/css2?family={familia}&display=swap")

        for subconjunto, cuerpo in BLOQUE.findall(css):
            subconjunto = subconjunto or "latin"
            if subconjunto not in SUBCONJUNTOS:
                omitidos += 1
                continue

            url = re.search(r"url\((https://[^)]+\.woff2)\)", cuerpo)
            nombre_fam = re.search(r"font-family:\s*'([^']+)'", cuerpo)
            peso = re.search(r"font-weight:\s*(\d+)", cuerpo)
            if not (url and nombre_fam and peso):
                continue

            estilo = re.search(r"font-style:\s*(\w+)", cuerpo)
            rango = re.search(r"unicode-range:\s*([^;]+);", cuerpo)
            archivo = "{}-{}-{}.woff2".format(
                nombre_fam.group(1).lower().replace(" ", "-"), peso.group(1), subconjunto)

            ruta = os.path.join(DESTINO, archivo)
            if not os.path.exists(ruta):
                with open(ruta, "wb") as f:
                    f.write(traer(url.group(1), binario=True))
                nuevos += 1

            declaraciones.append(
                "@font-face {\n"
                f"  font-family: '{nombre_fam.group(1)}';\n"
                f"  font-style: {estilo.group(1) if estilo else 'normal'};\n"
                f"  font-weight: {peso.group(1)};\n"
                "  font-display: swap;\n"
                f"  src: url('{archivo}') format('woff2');\n"
                + (f"  unicode-range: {rango.group(1).strip()};\n" if rango else "")
                + "}")

    with open(os.path.join(DESTINO, "fuentes.css"), "w", encoding="utf-8") as f:
        f.write(ENCABEZADO + "\n\n".join(declaraciones) + "\n")

    total = sum(os.path.getsize(os.path.join(DESTINO, n))
                for n in os.listdir(DESTINO) if n.endswith(".woff2"))
    print(f"{len(declaraciones)} declaraciones · {nuevos} archivos nuevos · "
          f"{omitidos} subconjuntos omitidos · {total / 1024:.0f} KB en disco")


if __name__ == "__main__":
    main()
