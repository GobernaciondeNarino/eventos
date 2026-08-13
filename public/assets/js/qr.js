/* =========================================================================
   qr.js — Generador de códigos QR (ISO/IEC 18004), sin dependencias.
   -------------------------------------------------------------------------
   Los QR de esta plataforma no son decorativos: el del carnet identifica a la
   persona y el del día autoriza el ingreso, así que tienen que ser legibles
   por cualquier lector comercial. Se implementa modo byte (UTF-8), versiones
   1 a 20 y los cuatro niveles de corrección de error.

   Uso:
     QR.svg("https://eventos.narino.gov.co/c/AB12", { ecl: "M", quiet: 4 })
     QR.matrix("texto", "Q")   -> arreglo de arreglos de booleanos

   La estructura sigue la referencia pública de Project Nayuki (dominio
   público / MIT), reescrita para este proyecto.
   ========================================================================= */
(function (global) {
  'use strict';

  var ECL = { L: 0, M: 1, Q: 2, H: 3 };
  // Bits de nivel de corrección tal como van en la información de formato.
  var ECL_FORMAT_BITS = { L: 1, M: 0, Q: 3, H: 2 };

  // Palabras de corrección por bloque, por nivel [L,M,Q,H] y versión 1..40.
  var ECC_PER_BLOCK = [
    [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28],
    [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26],
    [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30],
    [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28]
  ];

  // Cantidad de bloques de corrección, por nivel y versión 1..40.
  var NUM_BLOCKS = [
    [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8],
    [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16],
    [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20],
    [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25]
  ];

  var MAX_VERSION = 20;

  /* ---- Aritmética en GF(256) con polinomio 0x11D ------------------------- */
  function gfMultiply(x, y) {
    var z = 0;
    for (var i = 7; i >= 0; i--) {
      z = ((z << 1) ^ ((z >>> 7) * 0x11D)) & 0xFF;
      z ^= ((y >>> i) & 1) * x;
    }
    return z;
  }

  function rsDivisor(degree) {
    var result = [];
    for (var i = 0; i < degree - 1; i++) result.push(0);
    result.push(1);
    var root = 1;
    for (var j = 0; j < degree; j++) {
      for (var k = 0; k < result.length; k++) {
        result[k] = gfMultiply(result[k], root);
        if (k + 1 < result.length) result[k] ^= result[k + 1];
      }
      root = gfMultiply(root, 0x02);
    }
    return result;
  }

  function rsRemainder(data, divisor) {
    var result = divisor.map(function () { return 0; });
    data.forEach(function (b) {
      var factor = b ^ result.shift();
      result.push(0);
      divisor.forEach(function (d, i) { result[i] ^= gfMultiply(d, factor); });
    });
    return result;
  }

  /* ---- Capacidades ------------------------------------------------------- */
  function rawDataModules(version) {
    var result = (16 * version + 128) * version + 64;
    if (version >= 2) {
      var numAlign = Math.floor(version / 7) + 2;
      result -= (25 * numAlign - 10) * numAlign - 55;
      if (version >= 7) result -= 36;
    }
    return result;
  }

  function dataCodewords(version, eclIdx) {
    return Math.floor(rawDataModules(version) / 8)
      - ECC_PER_BLOCK[eclIdx][version] * NUM_BLOCKS[eclIdx][version];
  }

  function alignmentPositions(version) {
    if (version === 1) return [];
    var numAlign = Math.floor(version / 7) + 2;
    var step = Math.ceil((version * 4 + 4) / (numAlign * 2 - 2)) * 2;
    var result = [6];
    for (var pos = version * 4 + 17 - 7; result.length < numAlign; pos -= step) result.splice(1, 0, pos);
    return result;
  }

  /* ---- Codificación de los datos ----------------------------------------- */
  function toUtf8(str) {
    var out = [];
    var encoded = encodeURIComponent(str);
    for (var i = 0; i < encoded.length; i++) {
      if (encoded[i] === '%') {
        out.push(parseInt(encoded.substr(i + 1, 2), 16));
        i += 2;
      } else {
        out.push(encoded.charCodeAt(i));
      }
    }
    return out;
  }

  function BitBuffer() { this.bits = []; }
  BitBuffer.prototype.append = function (value, len) {
    for (var i = len - 1; i >= 0; i--) this.bits.push((value >>> i) & 1);
  };

  function buildCodewords(bytes, version, eclIdx) {
    var bb = new BitBuffer();
    bb.append(4, 4);                                  // modo byte
    bb.append(bytes.length, version < 10 ? 8 : 16);   // contador de caracteres
    bytes.forEach(function (b) { bb.append(b, 8); });

    var capacityBits = dataCodewords(version, eclIdx) * 8;
    bb.append(0, Math.min(4, capacityBits - bb.bits.length));      // terminador
    bb.append(0, (8 - bb.bits.length % 8) % 8);                    // ajuste a byte
    for (var pad = 0xEC; bb.bits.length < capacityBits; pad ^= 0xEC ^ 0x11) bb.append(pad, 8);

    var data = [];
    for (var i = 0; i < bb.bits.length; i += 8) {
      var byte = 0;
      for (var j = 0; j < 8; j++) byte = (byte << 1) | bb.bits[i + j];
      data.push(byte);
    }
    return data;
  }

  function interleave(data, version, eclIdx) {
    var numBlocks = NUM_BLOCKS[eclIdx][version];
    var blockEccLen = ECC_PER_BLOCK[eclIdx][version];
    var rawCodewords = Math.floor(rawDataModules(version) / 8);
    var numShortBlocks = numBlocks - rawCodewords % numBlocks;
    var shortBlockLen = Math.floor(rawCodewords / numBlocks);

    var blocks = [], divisor = rsDivisor(blockEccLen), k = 0;
    for (var i = 0; i < numBlocks; i++) {
      var len = shortBlockLen - blockEccLen + (i < numShortBlocks ? 0 : 1);
      var dat = data.slice(k, k + len);
      k += len;
      var ecc = rsRemainder(dat, divisor);
      // Los bloques cortos llevan un relleno para que todos queden alineados;
      // ese byte se salta al intercalar, no viaja en el código.
      if (i < numShortBlocks) dat.push(0);
      blocks.push(dat.concat(ecc));
    }

    var result = [];
    for (var i2 = 0; i2 < blocks[0].length; i2++) {
      for (var j = 0; j < blocks.length; j++) {
        if (i2 !== shortBlockLen - blockEccLen || j >= numShortBlocks) result.push(blocks[j][i2]);
      }
    }
    return result;
  }

  /* ---- Trazado de la matriz ---------------------------------------------- */
  function Matrix(size) {
    this.size = size;
    this.modules = [];
    this.reserved = [];
    for (var i = 0; i < size; i++) {
      this.modules.push(new Array(size).fill(false));
      this.reserved.push(new Array(size).fill(false));
    }
  }

  Matrix.prototype.set = function (x, y, dark, reserve) {
    if (x < 0 || y < 0 || x >= this.size || y >= this.size) return;
    this.modules[y][x] = dark;
    if (reserve) this.reserved[y][x] = true;
  };

  function drawFunctionPatterns(m, version) {
    var size = m.size, i;

    // Patrones de tiempo
    for (i = 0; i < size; i++) {
      m.set(6, i, i % 2 === 0, true);
      m.set(i, 6, i % 2 === 0, true);
    }

    // Tres patrones de búsqueda con su separador
    [[3, 3], [size - 4, 3], [3, size - 4]].forEach(function (c) {
      for (var dy = -4; dy <= 4; dy++) {
        for (var dx = -4; dx <= 4; dx++) {
          var dist = Math.max(Math.abs(dx), Math.abs(dy));
          var x = c[0] + dx, y = c[1] + dy;
          if (x >= 0 && x < size && y >= 0 && y < size) m.set(x, y, dist !== 2 && dist !== 4, true);
        }
      }
    });

    // Patrones de alineación
    var pos = alignmentPositions(version), n = pos.length;
    for (i = 0; i < n; i++) {
      for (var j = 0; j < n; j++) {
        if ((i === 0 && j === 0) || (i === 0 && j === n - 1) || (i === n - 1 && j === 0)) continue;
        for (var dy2 = -2; dy2 <= 2; dy2++) {
          for (var dx2 = -2; dx2 <= 2; dx2++) {
            m.set(pos[j] + dx2, pos[i] + dy2, Math.max(Math.abs(dx2), Math.abs(dy2)) !== 1, true);
          }
        }
      }
    }

    // Espacios reservados para la información de formato. El índice 6 se salta:
    // ahí pasa el patrón de tiempo y la información de formato no lo ocupa.
    for (i = 0; i <= 8; i++) {
      if (i === 6) continue;
      m.set(i, 8, false, true);
      m.set(8, i, false, true);
    }
    for (i = 0; i < 8; i++) { m.set(size - 1 - i, 8, false, true); m.set(8, size - 1 - i, false, true); }
    m.set(8, size - 8, true, true); // módulo siempre oscuro

    // Información de versión (versión 7 en adelante)
    if (version >= 7) {
      var rem = version;
      for (i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25);
      var bits = version << 12 | rem;
      for (i = 0; i < 18; i++) {
        var bit = ((bits >>> i) & 1) === 1;
        var a = size - 11 + i % 3, b = Math.floor(i / 3);
        m.set(a, b, bit, true);
        m.set(b, a, bit, true);
      }
    }
  }

  function drawFormatBits(m, eclKey, mask) {
    var data = ECL_FORMAT_BITS[eclKey] << 3 | mask;
    var rem = data;
    for (var i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
    var bits = ((data << 10) | rem) ^ 0x5412;
    var size = m.size, j;

    for (j = 0; j <= 5; j++) m.set(8, j, ((bits >>> j) & 1) === 1, true);
    m.set(8, 7, ((bits >>> 6) & 1) === 1, true);
    m.set(8, 8, ((bits >>> 7) & 1) === 1, true);
    m.set(7, 8, ((bits >>> 8) & 1) === 1, true);
    for (j = 9; j < 15; j++) m.set(14 - j, 8, ((bits >>> j) & 1) === 1, true);

    for (j = 0; j < 8; j++) m.set(size - 1 - j, 8, ((bits >>> j) & 1) === 1, true);
    for (j = 8; j < 15; j++) m.set(8, size - 15 + j, ((bits >>> j) & 1) === 1, true);
    m.set(8, size - 8, true, true);
  }

  function drawCodewords(m, codewords) {
    var size = m.size, i = 0;
    for (var right = size - 1; right >= 1; right -= 2) {
      if (right === 6) right = 5;
      for (var vert = 0; vert < size; vert++) {
        for (var j = 0; j < 2; j++) {
          var x = right - j;
          var upward = ((right + 1) & 2) === 0;
          var y = upward ? size - 1 - vert : vert;
          if (!m.reserved[y][x] && i < codewords.length * 8) {
            m.modules[y][x] = ((codewords[i >>> 3] >>> (7 - (i & 7))) & 1) === 1;
            i++;
          }
        }
      }
    }
  }

  function applyMask(m, mask) {
    for (var y = 0; y < m.size; y++) {
      for (var x = 0; x < m.size; x++) {
        if (m.reserved[y][x]) continue;
        var invert;
        switch (mask) {
          case 0: invert = (x + y) % 2 === 0; break;
          case 1: invert = y % 2 === 0; break;
          case 2: invert = x % 3 === 0; break;
          case 3: invert = (x + y) % 3 === 0; break;
          case 4: invert = (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0; break;
          case 5: invert = x * y % 2 + x * y % 3 === 0; break;
          case 6: invert = (x * y % 2 + x * y % 3) % 2 === 0; break;
          case 7: invert = ((x + y) % 2 + x * y % 3) % 2 === 0; break;
        }
        if (invert) m.modules[y][x] = !m.modules[y][x];
      }
    }
  }

  /* Penalizaciones de la norma: N1=3 (rachas), N2=3 (bloques 2×2),
     N3=40 (patrón parecido al de búsqueda), N4=10 (desbalance). */
  function penalty(m) {
    var size = m.size, result = 0, x, y;

    // Regla 1 y 3 por filas
    for (y = 0; y < size; y++) {
      var runColor = false, runLen = 0, history = [0, 0, 0, 0, 0, 0, 0];
      for (x = 0; x < size; x++) {
        if (m.modules[y][x] === runColor) {
          runLen++;
          if (runLen === 5) result += 3;
          else if (runLen > 5) result += 1;
        } else {
          addHistory(history, runLen, size);
          if (!runColor) result += countFinderPatterns(history) * 40;
          runColor = m.modules[y][x];
          runLen = 1;
        }
      }
      result += terminateAndCount(runColor, runLen, history, size) * 40;
    }

    // Regla 1 y 3 por columnas
    for (x = 0; x < size; x++) {
      var runColorC = false, runLenC = 0, historyC = [0, 0, 0, 0, 0, 0, 0];
      for (y = 0; y < size; y++) {
        if (m.modules[y][x] === runColorC) {
          runLenC++;
          if (runLenC === 5) result += 3;
          else if (runLenC > 5) result += 1;
        } else {
          addHistory(historyC, runLenC, size);
          if (!runColorC) result += countFinderPatterns(historyC) * 40;
          runColorC = m.modules[y][x];
          runLenC = 1;
        }
      }
      result += terminateAndCount(runColorC, runLenC, historyC, size) * 40;
    }

    // Regla 2: bloques de 2×2 del mismo color.
    for (y = 0; y < size - 1; y++) {
      for (x = 0; x < size - 1; x++) {
        var c = m.modules[y][x];
        if (c === m.modules[y][x + 1] && c === m.modules[y + 1][x] && c === m.modules[y + 1][x + 1]) result += 3;
      }
    }

    // Regla 4: desbalance entre módulos claros y oscuros.
    var dark = 0;
    for (y = 0; y < size; y++) for (x = 0; x < size; x++) if (m.modules[y][x]) dark++;
    var total = size * size;
    var k = Math.ceil(Math.abs(dark * 20 - total * 10) / total) - 1;
    return result + k * 10;
  }

  function addHistory(history, runLen, size) {
    if (history[0] === 0) runLen += size; // borde claro virtual al inicio
    history.pop();
    history.unshift(runLen);
  }

  function countFinderPatterns(h) {
    var n = h[1];
    var core = n > 0 && h[2] === n && h[3] === n * 3 && h[4] === n && h[5] === n;
    return (core && h[0] >= n * 4 && h[6] >= n ? 1 : 0)
      + (core && h[6] >= n * 4 && h[0] >= n ? 1 : 0);
  }

  function terminateAndCount(runColor, runLen, history, size) {
    if (runColor) { addHistory(history, runLen, size); runLen = 0; }
    runLen += size; // borde claro virtual al final
    addHistory(history, runLen, size);
    return countFinderPatterns(history);
  }

  /* ---- API pública -------------------------------------------------------- */
  function build(text, eclKey, forceMask) {
    eclKey = (eclKey || 'M').toUpperCase();
    if (!(eclKey in ECL)) eclKey = 'M';
    var eclIdx = ECL[eclKey];
    var bytes = toUtf8(String(text));

    var version = 1;
    while (version <= MAX_VERSION) {
      var capacity = dataCodewords(version, eclIdx) * 8;
      var needed = 4 + (version < 10 ? 8 : 16) + bytes.length * 8;
      if (needed <= capacity) break;
      version++;
    }
    if (version > MAX_VERSION) {
      throw new RangeError('El contenido excede la capacidad del QR (versión 20, nivel ' + eclKey + ').');
    }

    var codewords = interleave(buildCodewords(bytes, version, eclIdx), version, eclIdx);
    var m = new Matrix(version * 4 + 17);
    drawFunctionPatterns(m, version);
    drawFormatBits(m, eclKey, 0);
    drawCodewords(m, codewords);

    // Se prueban las ocho máscaras y se conserva la de menor penalización.
    var snapshot = m.modules.map(function (r) { return r.slice(); });
    var bestMask = 0, minPenalty = Infinity;
    if (forceMask != null) {
      bestMask = forceMask;
    } else {
      for (var mask = 0; mask < 8; mask++) {
        m.modules = snapshot.map(function (r) { return r.slice(); });
        drawFormatBits(m, eclKey, mask);
        applyMask(m, mask);
        var p = penalty(m);
        if (p < minPenalty) { minPenalty = p; bestMask = mask; }
      }
    }
    m.modules = snapshot.map(function (r) { return r.slice(); });
    drawFormatBits(m, eclKey, bestMask);
    applyMask(m, bestMask);

    return { modules: m.modules, size: m.size, version: version, mask: bestMask, ecl: eclKey };
  }

  var QR = {
    encode: build,

    matrix: function (text, ecl) { return build(text, ecl).modules; },

    /**
     * Devuelve el QR como cadena SVG lista para inyectar.
     * opts: { ecl, quiet, dark, light, className, title }
     */
    svg: function (text, opts) {
      opts = opts || {};
      var q = opts.quiet == null ? 4 : opts.quiet;
      var code = build(text, opts.ecl);
      var size = code.size + q * 2;
      var dark = opts.dark || '#000000';
      var light = opts.light || '#FFFFFF';
      var path = [];

      for (var y = 0; y < code.size; y++) {
        for (var x = 0; x < code.size; x++) {
          if (code.modules[y][x]) path.push('M' + (x + q) + ' ' + (y + q) + 'h1v1h-1z');
        }
      }

      return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + size + ' ' + size + '"'
        + ' shape-rendering="crispEdges" role="img"'
        + (opts.title ? ' aria-label="' + escapeAttr(opts.title) + '"' : ' aria-hidden="true"')
        + (opts.className ? ' class="' + escapeAttr(opts.className) + '"' : '')
        + '><rect width="' + size + '" height="' + size + '" fill="' + light + '"/>'
        + '<path fill="' + dark + '" d="' + path.join('') + '"/></svg>';
    },

    /** Pinta el QR dentro de un elemento del DOM. */
    render: function (el, text, opts) {
      if (!el) return null;
      try {
        el.innerHTML = QR.svg(text, opts);
        el.setAttribute('data-qr-value', text);
      } catch (err) {
        el.innerHTML = '<span class="error">' + escapeAttr(err.message) + '</span>';
      }
      return el;
    }
  };

  function escapeAttr(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  global.QR = QR;
  if (typeof module === 'object' && module.exports) module.exports = QR;
})(typeof window !== 'undefined' ? window : globalThis);
