/* =========================================================================
   qr-lector.js — Decodificador de códigos QR.
   -------------------------------------------------------------------------
   Existe por una razón concreta: Safari en iPhone no trae BarcodeDetector, y
   ahí vive la mitad de los asistentes. Hasta ahora el lector de la aplicación
   se rendía con un «lector no disponible en este navegador» y dejaba al
   operador tecleando cédulas en la puerta.

   No se usa ninguna biblioteca externa: la plataforma no carga nada de una CDN
   —ver docs/SEGURIDAD.md—, así que el decodificador está escrito aquí. Es el
   espejo del generador de app/Nucleo/Qr.php, y las pruebas comparan uno contra
   otro: pruebas/qr-lector.php genera con PHP y decodifica con esto.

   Dos entradas:

     LectorQr.desdeMatriz(m)   m[y][x] booleano. Salta todo el procesamiento de
                               imagen; es lo que prueban los tests.
     LectorQr.desdeImagen(d)   un ImageData de un <canvas>. Hace el camino
                               completo: binarizar, ubicar los tres patrones de
                               búsqueda, enderezar y muestrear.

   Devuelven la cadena decodificada, o null.
   ========================================================================= */
(function () {
  'use strict';

  /* =======================================================================
     GF(256) con el polinomio 0x11D, el mismo del generador
     ======================================================================= */

  var EXP = new Uint8Array(512);
  var LOG = new Uint8Array(256);

  (function tablas() {
    var x = 1;
    for (var i = 0; i < 255; i++) {
      EXP[i] = x;
      LOG[x] = i;
      x <<= 1;
      if (x & 0x100) x ^= 0x11D;
    }
    for (var j = 255; j < 512; j++) EXP[j] = EXP[j - 255];
  })();

  function mul(a, b) {
    if (a === 0 || b === 0) return 0;
    return EXP[LOG[a] + LOG[b]];
  }

  function div(a, b) {
    if (b === 0) throw new Error('división por cero en GF(256)');
    if (a === 0) return 0;
    return EXP[LOG[a] + 255 - LOG[b]];
  }

  function inv(a) { return EXP[255 - LOG[a]]; }

  /** Evalúa un polinomio (p[0] es el coeficiente de mayor grado) en x. */
  function evaluar(p, x) {
    var y = 0;
    for (var i = 0; i < p.length; i++) y = mul(y, x) ^ p[i];
    return y;
  }

  /* =======================================================================
     Reed-Solomon: corrección de errores
     -----------------------------------------------------------------------
     Berlekamp-Massey para el polinomio localizador, Chien para las posiciones
     y Forney para los valores. Las raíces del generador son alfa^0..alfa^(t-1),
     que es lo que arma Qr::divisor() en el lado de PHP.
     ======================================================================= */

  /**
   * Corrige en el sitio. Devuelve true si el bloque quedó válido.
   * @param {Uint8Array} bytes datos + corrección, en orden de transmisión
   * @param {number} nEcc palabras de corrección
   */
  function corregir(bytes, nEcc) {
    var n = bytes.length;
    var i, j;

    // Síndromes. Si todos son cero no hay nada que corregir.
    var sind = new Uint8Array(nEcc);
    var hayError = false;
    for (i = 0; i < nEcc; i++) {
      var s = 0;
      for (j = 0; j < n; j++) s = mul(s, EXP[i]) ^ bytes[j];
      sind[i] = s;
      if (s !== 0) hayError = true;
    }
    if (!hayError) return true;

    // Berlekamp-Massey. lambda y B se guardan con el grado 0 primero, que es
    // lo cómodo para el algoritmo; se dan la vuelta al final.
    var lambda = [1];
    var B = [1];
    var L = 0, m = 1, b = 1;

    for (var r = 0; r < nEcc; r++) {
      var delta = sind[r];
      for (i = 1; i <= L; i++) delta ^= mul(lambda[i] || 0, sind[r - i]);

      if (delta === 0) {
        m++;
      } else if (2 * L <= r) {
        var previo = lambda.slice();
        var escala = div(delta, b);
        for (i = 0; i < B.length; i++) {
          var pos = i + m;
          lambda[pos] = (lambda[pos] || 0) ^ mul(escala, B[i]);
        }
        L = r + 1 - L;
        B = previo;
        b = delta;
        m = 1;
      } else {
        var escala2 = div(delta, b);
        for (i = 0; i < B.length; i++) {
          var pos2 = i + m;
          lambda[pos2] = (lambda[pos2] || 0) ^ mul(escala2, B[i]);
        }
        m++;
      }
    }

    for (i = 0; i < lambda.length; i++) if (lambda[i] === undefined) lambda[i] = 0;
    while (lambda.length > 1 && lambda[lambda.length - 1] === 0) lambda.pop();
    var grado = lambda.length - 1;

    // Más errores que capacidad: el bloque no se puede recuperar.
    if (grado > (nEcc >> 1) || grado === 0) return false;

    // Chien: se buscan las raíces de lambda entre alfa^-0 .. alfa^-(n-1).
    var posiciones = [];
    for (i = 0; i < n; i++) {
      // Se prueba x = alfa^-i, es decir EXP[255 - i % 255].
      var x = EXP[(255 - (i % 255)) % 255];
      var v = 0;
      for (j = 0; j < lambda.length; j++) v ^= mul(lambda[j], EXP[(LOG[x] * j) % 255] || (j === 0 ? 1 : 0));
      if (v === 0) posiciones.push(i);
    }
    if (posiciones.length !== grado) return false;

    // Omega = S(x) * Lambda(x) mod x^nEcc, con el grado 0 primero.
    var omega = new Array(nEcc).fill(0);
    for (i = 0; i < nEcc; i++) {
      var acc = 0;
      for (j = 0; j <= i && j < lambda.length; j++) acc ^= mul(sind[i - j], lambda[j]);
      omega[i] = acc;
    }

    // Forney.
    for (var k = 0; k < posiciones.length; k++) {
      var p = posiciones[k];
      var Xk = EXP[p % 255];              // localizador
      var Xinv = inv(Xk);

      var numer = 0;
      for (j = 0; j < nEcc; j++) numer ^= mul(omega[j], EXP[(LOG[Xinv] * j) % 255] || (j === 0 ? 1 : 0));

      var denom = 0;
      for (j = 1; j < lambda.length; j += 2) {
        denom ^= mul(lambda[j], EXP[(LOG[Xinv] * (j - 1)) % 255] || (j === 1 ? 1 : 0));
      }
      if (denom === 0) return false;

      var valor = mul(Xk, div(numer, denom));
      // La posición p es el exponente contando desde el final del arreglo.
      var indice = n - 1 - p;
      if (indice < 0 || indice >= n) return false;
      bytes[indice] ^= valor;
    }

    // Se recalculan los síndromes: si alguno sigue vivo, la «corrección» era
    // ruido y devolver el bloque sería peor que fallar.
    for (i = 0; i < nEcc; i++) {
      var s2 = 0;
      for (j = 0; j < n; j++) s2 = mul(s2, EXP[i]) ^ bytes[j];
      if (s2 !== 0) return false;
    }
    return true;
  }

  /* =======================================================================
     Tablas del formato QR (mismas que app/Nucleo/Qr.php)
     ======================================================================= */

  var ECC_POR_BLOQUE = [
    [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28],
    [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26],
    [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30],
    [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28]
  ];

  var BLOQUES = [
    [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8],
    [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16],
    [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20],
    [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25]
  ];

  /** Los dos bits del nivel, tal como viajan en la información de formato. */
  var NIVEL_DE_BITS = { 1: 0, 0: 1, 3: 2, 2: 3 };   // bits → índice L,M,Q,H

  var VERSION_MAXIMA = 20;

  function modulosCrudos(version) {
    var r = (16 * version + 128) * version + 64;
    if (version >= 2) {
      var alin = Math.floor(version / 7) + 2;
      r -= (25 * alin - 10) * alin - 55;
      if (version >= 7) r -= 36;
    }
    return r;
  }

  function posicionesAlineacion(version) {
    if (version === 1) return [];
    var cuantos = Math.floor(version / 7) + 2;
    var paso = Math.ceil((version * 4 + 4) / (cuantos * 2 - 2)) * 2;
    var res = [6];
    for (var p = version * 4 + 17 - 7; res.length < cuantos; p -= paso) res.splice(1, 0, p);
    return res;
  }

  /* =======================================================================
     Matriz → texto
     ======================================================================= */

  /** Marca qué módulos son de función y no llevan datos. */
  function mapaReservado(version, n) {
    var res = [];
    var y, x, i, j;
    for (y = 0; y < n; y++) res.push(new Uint8Array(n));

    var marcar = function (x0, y0) {
      if (x0 >= 0 && y0 >= 0 && x0 < n && y0 < n) res[y0][x0] = 1;
    };

    for (i = 0; i < n; i++) { marcar(6, i); marcar(i, 6); }

    var centros = [[3, 3], [n - 4, 3], [3, n - 4]];
    for (i = 0; i < centros.length; i++) {
      for (var dy = -4; dy <= 4; dy++) {
        for (var dx = -4; dx <= 4; dx++) marcar(centros[i][0] + dx, centros[i][1] + dy);
      }
    }

    var pos = posicionesAlineacion(version);
    for (i = 0; i < pos.length; i++) {
      for (j = 0; j < pos.length; j++) {
        if ((i === 0 && j === 0) || (i === 0 && j === pos.length - 1) || (i === pos.length - 1 && j === 0)) continue;
        for (var ey = -2; ey <= 2; ey++) {
          for (var ex = -2; ex <= 2; ex++) marcar(pos[j] + ex, pos[i] + ey);
        }
      }
    }

    for (i = 0; i <= 8; i++) {
      if (i === 6) continue;
      marcar(i, 8); marcar(8, i);
    }
    for (i = 0; i < 8; i++) { marcar(n - 1 - i, 8); marcar(8, n - 1 - i); }
    marcar(8, n - 8);

    if (version >= 7) {
      for (i = 0; i < 18; i++) {
        var a = n - 11 + i % 3;
        var b = Math.floor(i / 3);
        marcar(a, b); marcar(b, a);
      }
    }
    return res;
  }

  function enmascarar(mascara, x, y) {
    switch (mascara) {
      case 0: return (x + y) % 2 === 0;
      case 1: return y % 2 === 0;
      case 2: return x % 3 === 0;
      case 3: return (x + y) % 3 === 0;
      case 4: return (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0;
      case 5: return (x * y) % 2 + (x * y) % 3 === 0;
      case 6: return ((x * y) % 2 + (x * y) % 3) % 2 === 0;
      case 7: return ((x + y) % 2 + (x * y) % 3) % 2 === 0;
      default: return false;
    }
  }

  /**
   * Lee la información de formato: nivel de corrección y máscara.
   *
   * Hay dos copias en el código. Se prueban las dos contra las 32 combinaciones
   * válidas y gana la que menos bits tenga distintos: es la corrección BCH sin
   * implementar BCH, y con 32 opciones sale igual de fiable.
   */
  function leerFormato(m, n) {
    var lecturas = [];
    var i, bits;

    bits = 0;
    for (i = 0; i <= 5; i++) bits |= (m[i][8] ? 1 : 0) << i;
    bits |= (m[7][8] ? 1 : 0) << 6;
    bits |= (m[8][8] ? 1 : 0) << 7;
    bits |= (m[8][7] ? 1 : 0) << 8;
    for (i = 9; i < 15; i++) bits |= (m[8][14 - i] ? 1 : 0) << i;
    lecturas.push(bits);

    bits = 0;
    for (i = 0; i < 8; i++) bits |= (m[8][n - 1 - i] ? 1 : 0) << i;
    for (i = 8; i < 15; i++) bits |= (m[n - 15 + i][8] ? 1 : 0) << i;
    lecturas.push(bits);

    var mejor = null;
    var mejorDistancia = 99;

    for (var datos = 0; datos < 32; datos++) {
      var resto = datos;
      for (i = 0; i < 10; i++) resto = ((resto << 1) ^ ((resto >> 9) * 0x537)) & 0x7FF;
      var completo = (((datos << 10) | (resto & 0x3FF)) ^ 0x5412) & 0x7FFF;

      for (var k = 0; k < lecturas.length; k++) {
        var d = 0, v = completo ^ lecturas[k];
        while (v) { d += v & 1; v >>= 1; }
        if (d < mejorDistancia) {
          mejorDistancia = d;
          mejor = { nivel: NIVEL_DE_BITS[(datos >> 3) & 3], mascara: datos & 7 };
        }
      }
    }

    // Con más de tres bits mal, la lectura no es de fiar.
    return mejorDistancia <= 3 ? mejor : null;
  }

  /** Recorre la matriz en zigzag y devuelve las palabras intercaladas. */
  function leerPalabras(m, n, version, mascara, reservado) {
    var total = Math.floor(modulosCrudos(version) / 8);
    var bytes = new Uint8Array(total);
    var bit = 0;

    for (var derecha = n - 1; derecha >= 1; derecha -= 2) {
      if (derecha === 6) derecha = 5;
      for (var vertical = 0; vertical < n; vertical++) {
        for (var j = 0; j < 2; j++) {
          var x = derecha - j;
          var haciaArriba = ((derecha + 1) & 2) === 0;
          var y = haciaArriba ? n - 1 - vertical : vertical;
          if (reservado[y][x] || bit >= total * 8) continue;

          var oscuro = !!m[y][x];
          if (enmascarar(mascara, x, y)) oscuro = !oscuro;
          if (oscuro) bytes[bit >> 3] |= 0x80 >> (bit & 7);
          bit++;
        }
      }
    }
    return bytes;
  }

  /**
   * Deshace el intercalado por bloques y corrige cada uno.
   * Devuelve los bytes de datos, o null si algún bloque es irrecuperable.
   */
  function desintercalar(palabras, version, nivel) {
    var cuantos = BLOQUES[nivel][version];
    var largoEcc = ECC_POR_BLOQUE[nivel][version];
    var crudas = Math.floor(modulosCrudos(version) / 8);
    var cortos = cuantos - crudas % cuantos;
    var largoCorto = Math.floor(crudas / cuantos);
    var i, j;

    var bloques = [];
    for (i = 0; i < cuantos; i++) {
      bloques.push(new Uint8Array(largoCorto + (i < cortos ? 0 : 1)));
    }

    // El recorrido es el inverso exacto de Qr::intercalar(): al armar el código
    // se le agrega un byte de relleno a los bloques cortos para que todos
    // midan lo mismo, y ese byte se salta al intercalar. Aquí hay que saltarlo
    // en el mismo sitio y, sobre todo, seguir escribiendo *sin* dejar el hueco:
    // por eso cada bloque lleva su propio cursor y no se usa el índice del
    // recorrido. Escribiendo en bloques[j][i] los bloques cortos quedaban
    // corridos un byte a partir de esa posición y no había Reed-Solomon que
    // los salvara.
    var k = 0;
    var cursor = new Array(cuantos).fill(0);
    var maximo = largoCorto + 1;
    for (i = 0; i < maximo; i++) {
      for (j = 0; j < cuantos; j++) {
        if (i === largoCorto - largoEcc && j < cortos) continue;
        if (cursor[j] >= bloques[j].length || k >= palabras.length) continue;
        bloques[j][cursor[j]++] = palabras[k++];
      }
    }

    var datos = [];
    for (j = 0; j < cuantos; j++) {
      if (!corregir(bloques[j], largoEcc)) return null;
      var largoDatos = bloques[j].length - largoEcc;
      for (i = 0; i < largoDatos; i++) datos.push(bloques[j][i]);
    }
    return datos;
  }

  var ALFANUMERICO = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

  /** Interpreta el flujo de bits: modos numérico, alfanumérico y byte. */
  function leerSegmentos(datos, version) {
    var bits = [];
    for (var i = 0; i < datos.length; i++) {
      for (var b = 7; b >= 0; b--) bits.push((datos[i] >> b) & 1);
    }

    var pos = 0;
    var tomar = function (cuantos) {
      if (pos + cuantos > bits.length) return -1;
      var v = 0;
      for (var j = 0; j < cuantos; j++) v = (v << 1) | bits[pos++];
      return v;
    };

    var bytesSalida = [];
    var texto = '';
    var vaciarBytes = function () {
      if (!bytesSalida.length) return;
      texto += decodificarUtf8(bytesSalida);
      bytesSalida = [];
    };

    while (pos + 4 <= bits.length) {
      var modo = tomar(4);
      if (modo <= 0) break;          // 0 = terminador

      var anchoCuenta;
      if (modo === 1) anchoCuenta = version < 10 ? 10 : (version < 27 ? 12 : 14);
      else if (modo === 2) anchoCuenta = version < 10 ? 9 : (version < 27 ? 11 : 13);
      else if (modo === 4) anchoCuenta = version < 10 ? 8 : 16;
      else if (modo === 7) { tomar(8); continue; }   // ECI: se salta el indicador
      else return null;                              // kanji y modos raros

      var cuenta = tomar(anchoCuenta);
      if (cuenta < 0) return null;

      if (modo === 4) {
        for (var n = 0; n < cuenta; n++) {
          var byte = tomar(8);
          if (byte < 0) return null;
          bytesSalida.push(byte);
        }
      } else if (modo === 2) {
        vaciarBytes();
        var restan = cuenta;
        while (restan >= 2) {
          var par = tomar(11);
          if (par < 0) return null;
          texto += ALFANUMERICO[Math.floor(par / 45)] + ALFANUMERICO[par % 45];
          restan -= 2;
        }
        if (restan === 1) {
          var uno = tomar(6);
          if (uno < 0) return null;
          texto += ALFANUMERICO[uno];
        }
      } else {
        vaciarBytes();
        var quedan = cuenta;
        while (quedan >= 3) {
          var tres = tomar(10);
          if (tres < 0) return null;
          texto += String(tres).padStart(3, '0');
          quedan -= 3;
        }
        if (quedan === 2) {
          var dos = tomar(7);
          if (dos < 0) return null;
          texto += String(dos).padStart(2, '0');
        } else if (quedan === 1) {
          var ultimo = tomar(4);
          if (ultimo < 0) return null;
          texto += String(ultimo);
        }
      }
    }

    vaciarBytes();
    return texto;
  }

  /**
   * Bytes → cadena, interpretándolos como UTF-8.
   *
   * El generador de la plataforma escribe siempre UTF-8 sin declarar ECI, que
   * es lo que hace todo el mundo. Si la secuencia no es UTF-8 válida se cae a
   * ISO-8859-1, que es lo que dice la norma por omisión.
   */
  function decodificarUtf8(bytes) {
    var crudo = '';
    for (var i = 0; i < bytes.length; i++) crudo += String.fromCharCode(bytes[i]);
    try {
      return decodeURIComponent(escape(crudo));
    } catch (e) {
      return crudo;
    }
  }

  /** Matriz de booleanos → texto. */
  function desdeMatriz(m) {
    if (!m || !m.length) return null;
    var n = m.length;
    if (n < 21 || (n - 17) % 4 !== 0) return null;

    var version = (n - 17) / 4;
    if (version < 1 || version > VERSION_MAXIMA) return null;

    var formato = leerFormato(m, n);
    if (!formato) return null;

    var reservado = mapaReservado(version, n);
    var palabras = leerPalabras(m, n, version, formato.mascara, reservado);
    var datos = desintercalar(palabras, version, formato.nivel);
    if (!datos) return null;

    return leerSegmentos(datos, version);
  }

  /* =======================================================================
     Imagen → matriz
     ======================================================================= */

  /** Escala de grises, en un solo arreglo. */
  function aGrises(imagen) {
    var d = imagen.data;
    var g = new Uint8Array(imagen.width * imagen.height);
    for (var i = 0, p = 0; i < g.length; i++, p += 4) {
      // Pesos enteros: es una multiplicación por píxel y en un teléfono de
      // gama baja esto se corre treinta veces por segundo.
      g[i] = (d[p] * 77 + d[p + 1] * 150 + d[p + 2] * 29) >> 8;
    }
    return g;
  }

  /**
   * Suavizado de 3×3, separable.
   *
   * La búsqueda de los patrones cuenta corridas de píxeles del mismo color, y
   * un solo píxel volteado por el ruido parte una corrida en dos y tira abajo
   * la proporción 1:1:3:1:1. Con luz de tubo fluorescente o con la ganancia
   * alta de una cámara de teléfono barata, eso pasa en cada fotograma.
   *
   * Dos pasadas de tres sumas por píxel: es barato incluso a diez fotogramas
   * por segundo, y sin esto el lector solo funciona con imágenes de prueba.
   */
  function suavizar(gris, ancho, alto) {
    var tmp = new Uint8Array(ancho * alto);
    var salida = new Uint8Array(ancho * alto);
    var x, y, f;

    for (y = 0; y < alto; y++) {
      f = y * ancho;
      for (x = 0; x < ancho; x++) {
        var izq = gris[f + (x > 0 ? x - 1 : 0)];
        var der = gris[f + (x < ancho - 1 ? x + 1 : ancho - 1)];
        tmp[f + x] = (izq + gris[f + x] + der) / 3;
      }
    }

    for (y = 0; y < alto; y++) {
      var arriba = (y > 0 ? y - 1 : 0) * ancho;
      var abajo = (y < alto - 1 ? y + 1 : alto - 1) * ancho;
      f = y * ancho;
      for (x = 0; x < ancho; x++) {
        salida[f + x] = (tmp[arriba + x] + tmp[f + x] + tmp[abajo + x]) / 3;
      }
    }
    return salida;
  }

  var BLOQUE = 8;

  /**
   * Umbral adaptativo por bloques de 8×8.
   *
   * Un umbral global falla en cuanto hay sombra —y siempre la hay: alguien
   * sostiene el carnet bajo una carpa, con el sol de un lado. Cada bloque usa
   * el promedio de su vecindario de 3×3 bloques, que suaviza los saltos entre
   * bloques contiguos.
   */
  function binarizar(gris, ancho, alto) {
    var bx = Math.max(1, Math.ceil(ancho / BLOQUE));
    var by = Math.max(1, Math.ceil(alto / BLOQUE));
    var promedios = new Int32Array(bx * by);
    var x, y, i, j;

    for (j = 0; j < by; j++) {
      for (i = 0; i < bx; i++) {
        var suma = 0, cuantos = 0, min = 255, max = 0;
        var y0 = j * BLOQUE, x0 = i * BLOQUE;
        for (y = y0; y < y0 + BLOQUE && y < alto; y++) {
          for (x = x0; x < x0 + BLOQUE && x < ancho; x++) {
            var v = gris[y * ancho + x];
            suma += v; cuantos++;
            if (v < min) min = v;
            if (v > max) max = v;
          }
        }
        var media = cuantos ? suma / cuantos : 128;
        // Bloque plano: casi seguro es todo papel o todo tinta. Se decide con
        // el vecino de arriba/izquierda en vez de partirlo por la mitad, que
        // es lo que llenaba de ruido los márgenes blancos.
        if (max - min <= 24) {
          media = min / 2;
          if (j > 0 && i > 0) {
            var vecino = (promedios[(j - 1) * bx + i] + 2 * promedios[j * bx + i - 1]
              + promedios[(j - 1) * bx + i - 1]) / 4;
            if (min < vecino) media = vecino;
          }
        }
        promedios[j * bx + i] = media;
      }
    }

    var bits = new Uint8Array(ancho * alto);
    for (j = 0; j < by; j++) {
      for (i = 0; i < bx; i++) {
        var li = Math.min(Math.max(i, 1), bx - 2);
        var lj = Math.min(Math.max(j, 1), by - 2);
        var total = 0;
        for (var dj = -1; dj <= 1; dj++) {
          for (var di = -1; di <= 1; di++) total += promedios[(lj + dj) * bx + (li + di)];
        }
        var umbral = total / 9;

        var yy0 = j * BLOQUE, xx0 = i * BLOQUE;
        for (y = yy0; y < yy0 + BLOQUE && y < alto; y++) {
          for (x = xx0; x < xx0 + BLOQUE && x < ancho; x++) {
            bits[y * ancho + x] = gris[y * ancho + x] <= umbral ? 1 : 0;
          }
        }
      }
    }
    return bits;
  }

  /**
   * Umbral global por el método de Otsu.
   *
   * El de bloques falla en un caso concreto: cuando el código llena el
   * fotograma y cada bloque de 8×8 cae justo dentro de un módulo. Ahí todos los
   * bloques son «planos» y el umbral se decide por vecindad, que con ruido se
   * equivoca. Un umbral global, elegido por el histograma, no tiene ese
   * problema y para una foto con luz pareja es incluso mejor.
   */
  function binarizarGlobal(gris, ancho, alto) {
    var histograma = new Int32Array(256);
    var i;
    for (i = 0; i < gris.length; i++) histograma[gris[i]]++;

    var total = gris.length;
    var suma = 0;
    for (i = 0; i < 256; i++) suma += i * histograma[i];

    var sumaB = 0, pesoB = 0, mejor = 0, umbral = 128;
    for (i = 0; i < 256; i++) {
      pesoB += histograma[i];
      if (pesoB === 0) continue;
      var pesoF = total - pesoB;
      if (pesoF === 0) break;

      sumaB += i * histograma[i];
      var mediaB = sumaB / pesoB;
      var mediaF = (suma - sumaB) / pesoF;
      var entre = pesoB * pesoF * (mediaB - mediaF) * (mediaB - mediaF);
      if (entre > mejor) { mejor = entre; umbral = i; }
    }

    var bits = new Uint8Array(total);
    for (i = 0; i < total; i++) bits[i] = gris[i] <= umbral ? 1 : 0;
    return bits;
  }

  /** ¿Las cinco corridas guardan la proporción 1:1:3:1:1? */
  function proporcionValida(c) {
    var total = c[0] + c[1] + c[2] + c[3] + c[4];
    if (total < 7) return false;
    var modulo = total / 7;
    var margen = modulo / 2;
    return Math.abs(modulo - c[0]) < margen
      && Math.abs(modulo - c[1]) < margen
      && Math.abs(3 * modulo - c[2]) < 3 * margen
      && Math.abs(modulo - c[3]) < margen
      && Math.abs(modulo - c[4]) < margen;
  }

  function centroDe(c, finX) {
    return finX - c[4] - c[3] - c[2] / 2;
  }

  /** Comprobación vertical de un candidato: mismas proporciones en columna. */
  function compruebaVertical(bits, ancho, alto, cx, cy, maxCuenta) {
    var c = [0, 0, 0, 0, 0];
    var y = cy;

    while (y >= 0 && bits[y * ancho + cx]) { c[2]++; y--; }
    if (y < 0) return NaN;
    while (y >= 0 && !bits[y * ancho + cx] && c[1] <= maxCuenta) { c[1]++; y--; }
    if (y < 0 || c[1] > maxCuenta) return NaN;
    while (y >= 0 && bits[y * ancho + cx] && c[0] <= maxCuenta) { c[0]++; y--; }
    if (c[0] > maxCuenta) return NaN;

    y = cy + 1;
    while (y < alto && bits[y * ancho + cx]) { c[2]++; y++; }
    if (y === alto) return NaN;
    while (y < alto && !bits[y * ancho + cx] && c[3] <= maxCuenta) { c[3]++; y++; }
    if (y === alto || c[3] > maxCuenta) return NaN;
    while (y < alto && bits[y * ancho + cx] && c[4] <= maxCuenta) { c[4]++; y++; }
    if (c[4] > maxCuenta) return NaN;

    return proporcionValida(c) ? centroDe(c, y) : NaN;
  }

  /** Y la horizontal, ya centrado en la fila que dio la vertical. */
  function compruebaHorizontal(bits, ancho, cx, cy, maxCuenta) {
    var c = [0, 0, 0, 0, 0];
    var x = cx;

    while (x >= 0 && bits[cy * ancho + x]) { c[2]++; x--; }
    if (x < 0) return NaN;
    while (x >= 0 && !bits[cy * ancho + x] && c[1] <= maxCuenta) { c[1]++; x--; }
    if (x < 0 || c[1] > maxCuenta) return NaN;
    while (x >= 0 && bits[cy * ancho + x] && c[0] <= maxCuenta) { c[0]++; x--; }
    if (c[0] > maxCuenta) return NaN;

    x = cx + 1;
    while (x < ancho && bits[cy * ancho + x]) { c[2]++; x++; }
    if (x === ancho) return NaN;
    while (x < ancho && !bits[cy * ancho + x] && c[3] <= maxCuenta) { c[3]++; x++; }
    if (x === ancho || c[3] > maxCuenta) return NaN;
    while (x < ancho && bits[cy * ancho + x] && c[4] <= maxCuenta) { c[4]++; x++; }
    if (c[4] > maxCuenta) return NaN;

    return proporcionValida(c) ? centroDe(c, x) : NaN;
  }

  /** Los tres cuadrados de las esquinas. */
  function buscarPatrones(bits, ancho, alto) {
    var encontrados = [];

    var anotar = function (x, y, modulo) {
      for (var i = 0; i < encontrados.length; i++) {
        var p = encontrados[i];
        if (Math.abs(p.x - x) <= modulo && Math.abs(p.y - y) <= modulo) {
          // Media móvil: cada confirmación afina el centro.
          p.x = (p.x * p.n + x) / (p.n + 1);
          p.y = (p.y * p.n + y) / (p.n + 1);
          p.modulo = (p.modulo * p.n + modulo) / (p.n + 1);
          p.n++;
          return;
        }
      }
      encontrados.push({ x: x, y: y, modulo: modulo, n: 1 });
    };

    // Se saltan filas: con paso 3 el patrón más pequeño que interesa (7
    // módulos) sigue cayendo en varias, y el barrido cuesta la tercera parte.
    var paso = Math.max(1, Math.floor(alto / 240) * 2 + 1);

    for (var y = paso; y < alto; y += paso) {
      var c = [0, 0, 0, 0, 0];
      var estado = 0;
      var fila = y * ancho;

      for (var x = 0; x < ancho; x++) {
        var negro = bits[fila + x] === 1;

        if (estado % 2 === 1) {          // esperando blanco
          if (negro) { estado++; c[estado]++; } else { c[estado]++; }
        } else {                         // esperando negro
          if (negro) {
            c[estado]++;
          } else {
            if (estado === 4) {
              if (proporcionValida(c)) {
                var modulo = (c[0] + c[1] + c[2] + c[3] + c[4]) / 7;
                var cx = Math.round(centroDe(c, x));
                var cy = compruebaVertical(bits, ancho, alto, cx, y, c[2]);
                if (!isNaN(cy)) {
                  var cx2 = compruebaHorizontal(bits, ancho, Math.round(cx), Math.round(cy), c[2]);
                  if (!isNaN(cx2)) anotar(cx2, cy, modulo);
                }
                c = [c[2], c[3], c[4], 1, 0];
                estado = 3;
              } else {
                c = [c[2], c[3], c[4], 1, 0];
                estado = 3;
              }
            } else {
              estado++;
              c[estado]++;
            }
          }
        }
      }
    }

    return encontrados.filter(function (p) { return p.n >= 2; });
  }

  function distancia(a, b) {
    var dx = a.x - b.x, dy = a.y - b.y;
    return Math.sqrt(dx * dx + dy * dy);
  }

  /**
   * Decide cuál de los tres es la esquina superior izquierda.
   *
   * Es la que está en el vértice del ángulo recto: la que NO forma parte del
   * lado más largo. Después, el signo del producto cruzado dice cuál de las
   * otras dos es la de arriba a la derecha.
   */
  function ordenarPatrones(p) {
    var d01 = distancia(p[0], p[1]);
    var d12 = distancia(p[1], p[2]);
    var d02 = distancia(p[0], p[2]);

    var esquina, a, b;
    if (d12 >= d01 && d12 >= d02) { esquina = p[0]; a = p[1]; b = p[2]; }
    else if (d02 >= d01 && d02 >= d12) { esquina = p[1]; a = p[0]; b = p[2]; }
    else { esquina = p[2]; a = p[0]; b = p[1]; }

    var cruz = (a.x - esquina.x) * (b.y - esquina.y) - (a.y - esquina.y) * (b.x - esquina.x);
    return cruz < 0
      ? { tl: esquina, tr: b, bl: a }
      : { tl: esquina, tr: a, bl: b };
  }

  /**
   * ¿Hay un 1:1:1 oscuro-claro-oscuro centrado verticalmente en (cx, cy)?
   *
   * Sin esta segunda comprobación, el barrido horizontal encontraba «patrones
   * de alineación» dentro de la zona de datos —cualquier módulo oscuro suelto
   * entre dos claros da 1:1:1— y con ese cuarto punto equivocado la
   * transformación de perspectiva salía torcida y no se leía nada.
   */
  function confirmaAlineacion(bits, ancho, alto, cx, cy, modulo) {
    var arriba = 0, abajo = 0, centro = 0;
    var y = cy;
    var tope = Math.ceil(modulo * 2) + 2;

    while (y >= 0 && bits[y * ancho + cx] && centro <= tope) { centro++; y--; }
    if (y < 0 || centro > tope) return NaN;
    while (y >= 0 && !bits[y * ancho + cx] && arriba <= tope) { arriba++; y--; }
    if (arriba === 0 || arriba > tope) return NaN;

    y = cy + 1;
    while (y < alto && bits[y * ancho + cx] && centro <= tope) { centro++; y++; }
    if (y === alto || centro > tope) return NaN;
    while (y < alto && !bits[y * ancho + cx] && abajo <= tope) { abajo++; y++; }
    if (abajo === 0 || abajo > tope) return NaN;

    var m = (arriba + centro + abajo) / 3;
    if (Math.abs(m - modulo) > modulo / 2) return NaN;
    return y - abajo - centro / 2;
  }

  /**
   * Comprueba los 5×5 módulos completos del patrón de alineación.
   *
   * Sin esto sobran los falsos positivos: cualquier módulo oscuro suelto entre
   * dos claros da la proporción 1:1:1 en línea, y la zona de datos está llena.
   * Uno de esos falsos, tomado como cuarto punto, tuerce la transformación y el
   * código deja de leerse aunque estuviera perfectamente enfocado.
   *
   * El patrón real es un cuadrado oscuro de 5×5 con un anillo claro dentro y un
   * módulo oscuro en el centro. Se toleran cuatro fallos en el anillo exterior,
   * que es el que más sufre cuando el tamaño de módulo local no coincide del
   * todo con el promedio de la imagen.
   */
  function verificarAlineacion(bits, ancho, alto, cx, cy, modulo) {
    var centroOscuro = false;
    var interiorMal = 0;
    var exteriorBien = 0;

    for (var dy = -2; dy <= 2; dy++) {
      for (var dx = -2; dx <= 2; dx++) {
        var x = Math.round(cx + dx * modulo);
        var y = Math.round(cy + dy * modulo);
        if (x < 0 || y < 0 || x >= ancho || y >= alto) return false;

        var oscuro = bits[y * ancho + x] === 1;
        var anillo = Math.max(Math.abs(dx), Math.abs(dy));

        if (anillo === 0) centroOscuro = oscuro;
        else if (anillo === 1) { if (oscuro) interiorMal++; }
        else if (oscuro) exteriorBien++;
      }
    }

    return centroOscuro && interiorMal === 0 && exteriorBien >= 12;
  }

  /**
   * Busca el patrón de alineación cerca de donde debería estar, ampliando el
   * radio si no aparece.
   *
   * El «debería estar» sale de suponer que el código es plano, y cuando no lo
   * es —que es el caso para el que existe este patrón— el punto real se corre.
   * Con un radio fijo de tres módulos se quedaba a un par de píxeles de
   * encontrarlo en una foto inclinada de verdad. Se empieza estrecho para no
   * confundirlo con ruido y se abre solo si hizo falta.
   */
  function buscarAlineacion(bits, ancho, alto, cx, cy, modulo) {
    var radios = [modulo * 3, modulo * 6, modulo * 10];
    for (var i = 0; i < radios.length; i++) {
      var p = buscarAlineacionEn(bits, ancho, alto, cx, cy, modulo, radios[i]);
      if (p) return p;
    }
    return null;
  }

  function buscarAlineacionEn(bits, ancho, alto, cx, cy, modulo, radioCrudo) {
    var radio = Math.max(4, Math.ceil(radioCrudo));
    var x0 = Math.max(0, Math.floor(cx - radio));
    var x1 = Math.min(ancho - 1, Math.ceil(cx + radio));
    var y0 = Math.max(0, Math.floor(cy - radio));
    var y1 = Math.min(alto - 1, Math.ceil(cy + radio));
    var mejor = null;
    var mejorDistancia = Infinity;

    // Se busca claro-oscuro-claro, no oscuro-claro-oscuro.
    //
    // El patrón de alineación es un cuadrado oscuro de 5×5 con un anillo claro
    // y un módulo oscuro en el centro; su fila central es O-C-O-C-O. Lo que
    // identifica el centro es la terna claro-OSCURO-claro de en medio. Buscando
    // la contraria, el punto que salía era el del anillo claro: un módulo
    // corrido, y encima con el centro claro, así que la comprobación de los
    // 5×5 lo descartaba siempre y el patrón no se encontraba nunca.
    for (var y = y0; y <= y1; y++) {
      var c = [0, 0, 0];
      var estado = 0;
      for (var x = x0; x <= x1; x++) {
        var negro = bits[y * ancho + x] === 1;
        if (estado === 1 ? !negro : negro) {
          if (estado === 2) {
            var total = c[0] + c[1] + c[2];
            var m = total / 3;
            if (Math.abs(m - c[0]) < m && Math.abs(m - c[1]) < m && Math.abs(m - c[2]) < m
                && Math.abs(m - modulo) < modulo / 2) {
              var px = Math.round(x - c[2] - c[1] / 2);
              var py = confirmaAlineacion(bits, ancho, alto, px, y, modulo);
              // La comprobación de los 5×5 usa el tamaño de módulo medido
              // aquí mismo, no el promedio de la imagen: en una foto inclinada
              // el lado lejano tiene los módulos bastante más pequeños, y con
              // el promedio los cinco puntos del borde caían fuera del patrón.
              if (!isNaN(py) && verificarAlineacion(bits, ancho, alto, px, py, m)) {
                var d = (px - cx) * (px - cx) + (py - cy) * (py - cy);
                if (d < mejorDistancia) { mejorDistancia = d; mejor = { x: px, y: py }; }
              }
            }
            c = [c[2], 1, 0];
            estado = 1;
          } else {
            estado++;
            c[estado]++;
          }
        } else {
          c[estado]++;
        }
      }
    }
    return mejor;
  }

  /**
   * Transformación de perspectiva de cuatro puntos.
   *
   * Devuelve una función (u,v) → {x,y}, de coordenadas de módulo a píxeles.
   * Es la que permite leer un carnet fotografiado de lado, que es como se
   * fotografía siempre.
   */
  function perspectiva(cuadro, destino) {
    // cuadro: 4 puntos {x,y} en la imagen, en orden tl, tr, br, bl
    // destino: 4 puntos {u,v} en módulos, mismo orden
    // Se resuelve el sistema clásico de 8 incógnitas.
    var A = [];
    var B = [];
    for (var i = 0; i < 4; i++) {
      var u = destino[i].u, v = destino[i].v, x = cuadro[i].x, y = cuadro[i].y;
      A.push([u, v, 1, 0, 0, 0, -u * x, -v * x]);
      B.push(x);
      A.push([0, 0, 0, u, v, 1, -u * y, -v * y]);
      B.push(y);
    }

    // Gauss con pivoteo parcial.
    for (var col = 0; col < 8; col++) {
      var pivote = col;
      for (var f = col + 1; f < 8; f++) {
        if (Math.abs(A[f][col]) > Math.abs(A[pivote][col])) pivote = f;
      }
      if (Math.abs(A[pivote][col]) < 1e-9) return null;

      var tmp = A[col]; A[col] = A[pivote]; A[pivote] = tmp;
      var t = B[col]; B[col] = B[pivote]; B[pivote] = t;

      for (var g = 0; g < 8; g++) {
        if (g === col) continue;
        var factor = A[g][col] / A[col][col];
        if (!factor) continue;
        for (var h = col; h < 8; h++) A[g][h] -= factor * A[col][h];
        B[g] -= factor * B[col];
      }
    }

    var c = [];
    for (var k = 0; k < 8; k++) c.push(B[k] / A[k][k]);

    return function (u, v) {
      var w = c[6] * u + c[7] * v + 1;
      if (Math.abs(w) < 1e-9) return null;
      return { x: (c[0] * u + c[1] * v + c[2]) / w, y: (c[3] * u + c[4] * v + c[5]) / w };
    };
  }

  /**
   * ImageData → texto, o null.
   *
   * Se prueban dos binarizaciones, en este orden:
   *
   *   1. directa. Es la buena cuando la imagen está limpia, y también la única
   *      que sirve cuando hay pocos píxeles por módulo —un QR pequeño en la
   *      pantalla de otro teléfono—, porque ahí el suavizado se come el borde
   *      entre módulos vecinos;
   *   2. suavizada. Necesaria en cuanto hay ruido de sensor: un píxel volteado
   *      parte una corrida y la búsqueda de patrones no encuentra nada;
   *   3. suavizada con umbral global. Para cuando el código llena el fotograma
   *      y el umbral por bloques se queda sin contexto.
   *
   * Cada una se paga solo si falló la anterior, que es justo cuando valía la
   * pena pagarla.
   */
  function desdeImagen(imagen) {
    var ancho = imagen.width;
    var alto = imagen.height;
    if (!ancho || !alto) return null;

    var gris = aGrises(imagen);
    var suave = null;
    var texto;

    texto = leerPlano(binarizar(gris, ancho, alto), ancho, alto);
    if (texto !== null && texto !== '') return texto;

    suave = suavizar(gris, ancho, alto);

    texto = leerPlano(binarizar(suave, ancho, alto), ancho, alto);
    if (texto !== null && texto !== '') return texto;

    texto = leerPlano(binarizarGlobal(suave, ancho, alto), ancho, alto);
    if (texto !== null && texto !== '') return texto;

    return null;
  }

  function leerPlano(bits, ancho, alto) {
    var patrones = buscarPatrones(bits, ancho, alto);
    if (patrones.length < 3) return null;

    // Con más de tres candidatos se prueban las combinaciones más prometedoras:
    // las que más veces se confirmaron durante el barrido.
    patrones.sort(function (a, b) { return b.n - a.n; });
    var candidatos = patrones.slice(0, 6);

    for (var i = 0; i < candidatos.length; i++) {
      for (var j = i + 1; j < candidatos.length; j++) {
        for (var k = j + 1; k < candidatos.length; k++) {
          var texto = intentar(bits, ancho, alto,
            [candidatos[i], candidatos[j], candidatos[k]]);
          if (texto !== null && texto !== '') return texto;
        }
      }
    }
    return null;
  }

  function intentar(bits, ancho, alto, tres) {
    var o = ordenarPatrones(tres);
    var modulo = (o.tl.modulo + o.tr.modulo + o.bl.modulo) / 3;
    if (!(modulo > 0.9)) return null;

    // Tamaño estimado. Cada dirección usa el tamaño de módulo de sus dos
    // extremos: en una foto inclinada el lado que queda más lejos de la cámara
    // tiene los módulos más pequeños, y promediar los tres da un número que no
    // corresponde a ninguna de las dos direcciones.
    var nH = distancia(o.tl, o.tr) / ((o.tl.modulo + o.tr.modulo) / 2);
    var nV = distancia(o.tl, o.bl) / ((o.tl.modulo + o.bl.modulo) / 2);
    var estimado = Math.round((nH + nV) / 2) + 7;

    // El tamaño de un QR siempre es 4v+17, así que solo valen los que dan
    // resto 1 al dividir entre 4. Y como la estimación puede fallar por una
    // versión —sobre todo con perspectiva—, se prueban también las vecinas: la
    // corrección de errores rechaza en el acto la que no es, así que probar de
    // más no puede hacer que se lea algo equivocado.
    var base = estimado - ((estimado - 1) % 4 + 4) % 4;
    var tamanos = [base, base - 4, base + 4, base - 8, base + 8];

    for (var t = 0; t < tamanos.length; t++) {
      var n = tamanos[t];
      if (n < 21 || n > VERSION_MAXIMA * 4 + 17 || (n - 17) % 4 !== 0) continue;

      var texto = conTamano(bits, ancho, alto, o, modulo, n);
      if (texto !== null && texto !== '') return texto;
    }
    return null;
  }

  function conTamano(bits, ancho, alto, o, modulo, n) {
    var version = (n - 17) / 4;

    // Cuarto punto. Se preparan las dos posibilidades y se prueban ambas:
    //
    //   a) el patrón de alineación, que es lo correcto cuando la foto está
    //      tomada de lado —o sea, casi siempre;
    //   b) la esquina estimada por paralelogramo, que basta si la foto está
    //      de frente y es lo único que hay en la versión 1.
    //
    // Probar las dos y quedarse con la que decodifique sale más barato que
    // acertar a la primera: si el cuarto punto está mal, Reed-Solomon lo
    // rechaza y no hay riesgo de leer algo equivocado.
    var esperadoX = o.tr.x + o.bl.x - o.tl.x;
    var esperadoY = o.tr.y + o.bl.y - o.tl.y;

    var intentos = [];

    if (version >= 2) {
      var pos = posicionesAlineacion(version);
      var ultima = pos[pos.length - 1];     // centro del patrón de abajo a la derecha

      // Dónde cae ese centro sobre la diagonal que va del centro del patrón de
      // búsqueda superior izquierdo (módulo 3,5) a la esquina estimada
      // (módulo n-3,5).
      var f = (ultima + 0.5 - 3.5) / (n - 7);
      var alin = buscarAlineacion(bits, ancho, alto,
        o.tl.x + f * (esperadoX - o.tl.x),
        o.tl.y + f * (esperadoY - o.tl.y),
        modulo);

      if (alin) {
        intentos.push({
          cuadro: [o.tl, o.tr, alin, o.bl],
          destino: [
            { u: 3.5, v: 3.5 },
            { u: n - 3.5, v: 3.5 },
            { u: ultima + 0.5, v: ultima + 0.5 },
            { u: 3.5, v: n - 3.5 }
          ]
        });
      }
    }

    intentos.push({
      cuadro: [o.tl, o.tr, { x: esperadoX, y: esperadoY }, o.bl],
      destino: [
        { u: 3.5, v: 3.5 },
        { u: n - 3.5, v: 3.5 },
        { u: n - 3.5, v: n - 3.5 },
        { u: 3.5, v: n - 3.5 }
      ]
    });

    for (var t = 0; t < intentos.length; t++) {
      var texto = muestrear(bits, ancho, alto, n, intentos[t]);
      if (texto !== null && texto !== '') return texto;
    }
    return null;
  }

  /** Muestrea la rejilla con la transformación dada y decodifica. */
  function muestrear(bits, ancho, alto, n, intento) {
    var mapa = perspectiva(intento.cuadro, intento.destino);
    if (!mapa) return null;

    var m = [];
    for (var y = 0; y < n; y++) {
      var fila = [];
      for (var x = 0; x < n; x++) {
        var p = mapa(x + 0.5, y + 0.5);
        if (!p) return null;
        var px = Math.round(p.x), py = Math.round(p.y);
        if (px < 0 || py < 0 || px >= ancho || py >= alto) return null;

        // Voto de cinco píxeles en cruz. Con un solo píxel, un punto de ruido
        // justo en el centro del módulo cambia el bit; con la cruz hacen falta
        // tres. Cuesta cuatro accesos más por módulo y es la diferencia entre
        // leer o no leer una foto tomada con poca luz.
        var votos = bits[py * ancho + px] === 1 ? 1 : 0;
        var vecinos = [[1, 0], [-1, 0], [0, 1], [0, -1]];
        var validos = 1;
        for (var v = 0; v < 4; v++) {
          var vx = px + vecinos[v][0], vy = py + vecinos[v][1];
          if (vx < 0 || vy < 0 || vx >= ancho || vy >= alto) continue;
          validos++;
          if (bits[vy * ancho + vx] === 1) votos++;
        }
        fila.push(votos * 2 > validos);
      }
      m.push(fila);
    }

    try {
      return desdeMatriz(m);
    } catch (e) {
      return null;
    }
  }

  var api = { desdeMatriz: desdeMatriz, desdeImagen: desdeImagen };

  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (typeof window !== 'undefined') window.LectorQr = api;
})();
