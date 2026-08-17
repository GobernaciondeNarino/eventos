<?php
/**
 * El lector de QR contra el generador de la plataforma.
 *
 * Genera con PHP (app/Nucleo/Qr.php) y decodifica con JavaScript
 * (assets/js/qr-lector.js). Si lo que sale es lo que entró, el lector sirve.
 *
 * Se prueban los dos caminos del lector:
 *
 *   · matriz → texto, que es el decodificador puro: formato, máscara,
 *     desintercalado, Reed-Solomon y segmentos;
 *   · imagen → texto, que es el camino real de la cámara: se dibuja la matriz
 *     como un mapa de bits, se le agregan margen, escala, ruido, desenfoque de
 *     brillo y hasta una inclinación, y se le pide al lector que lo lea.
 *
 * También se comprueba que corrige errores de verdad: se ensucian módulos
 * hasta el límite del nivel de corrección y el texto tiene que salir igual.
 *
 * Uso:  php pruebas/qr-lector.php
 */
declare(strict_types=1);

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', 'pruebas');

require RAIZ . '/app/Nucleo/Qr.php';

use App\Nucleo\Qr;

$ok = 0;
$fallos = [];

function comprobar(string $nombre, bool $condicion, string $extra = ''): void
{
    global $ok, $fallos;
    if (!is_int($ok)) {
        fwrite(STDERR, "El contador se sobrescribió; la cuenta no sirve.\n");
        exit(1);
    }
    if ($condicion) {
        $ok++;
        echo "  ✓ $nombre\n";
    } else {
        $fallos[] = $nombre;
        echo "  ✗ $nombre" . ($extra !== '' ? "  → $extra" : '') . "\n";
    }
}

function titulo(string $texto): void
{
    echo "\n$texto\n" . str_repeat('─', 60) . "\n";
}

echo "Lector de QR · assets/js/qr-lector.js\n";
echo str_repeat('=', 60) . "\n";

/* =========================================================================
   Los casos
   ========================================================================= */

$textos = [
    'https://tic.narino.gov.co/cumbreAI/c/9f3a2b7d10c4e5a1',
    'https://tic.narino.gov.co/cumbreAI/entrar/qr/4f9a2cb71e80c36d5a7b93e102fd4c68',
    'https://tic.narino.gov.co/cumbreAI/d/4f9a2cb71e80c36d',
    'https://eventos.narino.gov.co/c/ab12cd34ef567890',
    'Ñañez Güépez — Secretaría TIC, Nariño',
    'HOLA MUNDO 123',            // cabe en alfanumérico
    '0123456789012345678901',    // numérico puro
    'x',
];

// Uno largo: obliga a varios bloques y a la versión alta.
mt_srand(20260817);
$alfabeto = preg_split('//u', 'abcdefghijklmnopqrstuvwxyz0123456789 :/.-_?=&áéíóúñÑ', -1, PREG_SPLIT_NO_EMPTY);
$largo = '';
for ($i = 0; $i < 420; $i++) {
    $largo .= $alfabeto[mt_rand(0, count($alfabeto) - 1)];
}
$textos[] = $largo;

$casos = [];
foreach ($textos as $texto) {
    foreach (['L', 'M', 'Q', 'H'] as $nivel) {
        foreach ([null, 0, 3, 5, 7] as $mascara) {
            $casos[] = ['texto' => $texto, 'nivel' => $nivel, 'mascara' => $mascara];
        }
    }
}

/* =========================================================================
   Se generan las matrices con PHP
   ========================================================================= */

$paraJs = [];
$saltados = 0;
foreach ($casos as $caso) {
    try {
        $r = Qr::construir($caso['texto'], $caso['nivel'], $caso['mascara']);
    } catch (\RangeException) {
        $saltados++;
        continue;
    }
    $paraJs[] = [
        'esperado' => $caso['texto'],
        'nivel'    => $caso['nivel'],
        'version'  => $r['version'],
        'filas'    => array_map(
            static fn(array $fila): string => implode('', array_map(static fn($m) => $m ? '1' : '0', $fila)),
            $r['modulos']
        ),
    ];
}

/* =========================================================================
   Se decodifican con el lector
   ========================================================================= */

$guion = <<<'JS'
const Lector = require(process.argv[2] + '/assets/js/qr-lector.js');
const casos = JSON.parse(require('fs').readFileSync(process.argv[3], 'utf8'));

// --- Pintar una matriz como si fuera una foto ---------------------------
function aImagen(filas, opciones) {
  const o = Object.assign({ escala: 6, margen: 4, ruido: 0, sombra: 0, invertirClaro: 255 }, opciones || {});
  const n = filas.length;
  const lado = (n + o.margen * 2) * o.escala;
  const data = new Uint8ClampedArray(lado * lado * 4);

  for (let y = 0; y < lado; y++) {
    for (let x = 0; x < lado; x++) {
      const mx = Math.floor(x / o.escala) - o.margen;
      const my = Math.floor(y / o.escala) - o.margen;
      let oscuro = false;
      if (mx >= 0 && my >= 0 && mx < n && my < n) oscuro = filas[my][mx] === '1';

      // Sombra: un degradado diagonal que baja el brillo de un lado. Es lo
      // que pasa siempre al fotografiar bajo una carpa.
      const caida = o.sombra * ((x + y) / (2 * lado));
      let v = oscuro ? 28 : o.invertirClaro;
      v = Math.max(0, Math.min(255, v - caida));
      if (o.ruido) v += (Math.random() * 2 - 1) * o.ruido;

      const p = (y * lado + x) * 4;
      data[p] = data[p + 1] = data[p + 2] = v;
      data[p + 3] = 255;
    }
  }
  return { data, width: lado, height: lado };
}

const salida = casos.map(c => {
  const filas = c.filas;
  const m = filas.map(f => f.split('').map(v => v === '1'));

  const r = { matriz: null, limpia: null, sucia: null, chica: null };
  try { r.matriz = Lector.desdeMatriz(m); } catch (e) { r.matriz = 'ERROR: ' + e.message; }

  try {
    r.limpia = Lector.desdeImagen(aImagen(filas, { escala: 6, margen: 4 }));
  } catch (e) { r.limpia = 'ERROR: ' + e.message; }

  try {
    r.sucia = Lector.desdeImagen(aImagen(filas, { escala: 8, margen: 5, ruido: 26, sombra: 90 }));
  } catch (e) { r.sucia = 'ERROR: ' + e.message; }

  try {
    r.chica = Lector.desdeImagen(aImagen(filas, { escala: 3, margen: 3 }));
  } catch (e) { r.chica = 'ERROR: ' + e.message; }

  return r;
});

console.log(JSON.stringify(salida));
JS;

$entrada = sys_get_temp_dir() . '/casos-lector-qr.json';
$archivoGuion = sys_get_temp_dir() . '/lector-qr-correr.js';
file_put_contents($entrada, json_encode($paraJs));
file_put_contents($archivoGuion, $guion);

$salida = shell_exec('node ' . escapeshellarg($archivoGuion) . ' '
    . escapeshellarg(RAIZ) . ' ' . escapeshellarg($entrada) . ' 2>&1');
$leido = json_decode((string) $salida, true);

if (!is_array($leido)) {
    fwrite(STDERR, "No se pudo ejecutar el lector:\n" . $salida . "\n");
    exit(1);
}

/* =========================================================================
   Comparación
   ========================================================================= */

titulo('Matriz → texto');

$malMatriz = [];
foreach ($paraJs as $i => $caso) {
    if (($leido[$i]['matriz'] ?? null) !== $caso['esperado']) {
        $malMatriz[] = 'v' . $caso['version'] . $caso['nivel'] . ': '
            . mb_substr((string) ($leido[$i]['matriz'] ?? 'null'), 0, 40);
    }
}
comprobar(count($paraJs) . ' matrices decodificadas sin perder un solo carácter',
    $malMatriz === [], implode(' | ', array_slice($malMatriz, 0, 3)));

titulo('Imagen → texto');

foreach ([
    'limpia' => 'foto nítida, 6 px por módulo',
    'chica'  => 'foto pequeña, 3 px por módulo',
    'sucia'  => 'con ruido y sombra diagonal',
] as $clave => $descripcion) {
    $mal = [];
    foreach ($paraJs as $i => $caso) {
        if (($leido[$i][$clave] ?? null) !== $caso['esperado']) {
            $mal[] = 'v' . $caso['version'] . $caso['nivel'];
        }
    }
    comprobar($descripcion, $mal === [],
        count($mal) . ' de ' . count($paraJs) . ' fallaron: ' . implode(', ', array_slice($mal, 0, 6)));
}

/* =========================================================================
   Fotografiado de lado
   -------------------------------------------------------------------------
   Nadie sostiene un carnet perfectamente perpendicular a la cámara. Esta es
   la prueba del patrón de alineación y de la transformación de perspectiva:
   sin los dos, un carnet inclinado no se lee.
   ========================================================================= */

titulo('Fotografiado de lado');

$guionPerspectiva = <<<'JS'
const Lector = require(process.argv[2] + '/assets/js/qr-lector.js');
const casos = JSON.parse(require('fs').readFileSync(process.argv[3], 'utf8'));

/**
 * Homografía: resuelve la transformación que lleva cuatro puntos a otros
 * cuatro. Es lo que hace una cámara de verdad al fotografiar un plano; una
 * interpolación bilineal sobre el cuadrilátero se parece pero NO es lo mismo,
 * y con ella la prueba estaría midiendo una deformación que ninguna cámara
 * produce.
 */
function homografia(desde, hacia) {
  const A = [], B = [];
  for (let i = 0; i < 4; i++) {
    const { x: u, y: v } = desde[i];
    const { x, y } = hacia[i];
    A.push([u, v, 1, 0, 0, 0, -u * x, -v * x]); B.push(x);
    A.push([0, 0, 0, u, v, 1, -u * y, -v * y]); B.push(y);
  }
  for (let col = 0; col < 8; col++) {
    let piv = col;
    for (let f = col + 1; f < 8; f++) if (Math.abs(A[f][col]) > Math.abs(A[piv][col])) piv = f;
    [A[col], A[piv]] = [A[piv], A[col]];
    [B[col], B[piv]] = [B[piv], B[col]];
    for (let f = 0; f < 8; f++) {
      if (f === col) continue;
      const k = A[f][col] / A[col][col];
      if (!k) continue;
      for (let h = col; h < 8; h++) A[f][h] -= k * A[col][h];
      B[f] -= k * B[col];
    }
  }
  const c = [];
  for (let i = 0; i < 8; i++) c.push(B[i] / A[i][i]);
  return (x, y) => {
    const w = c[6] * x + c[7] * y + 1;
    return { x: (c[0] * x + c[1] * y + c[2]) / w, y: (c[3] * x + c[4] * y + c[5]) / w };
  };
}

// Pinta la matriz proyectada sobre un cuadrilátero: la foto de alguien que
// sostiene el carnet con una mano mientras con la otra apunta el teléfono.
// Se recorre el destino y se pregunta al revés qué módulo le toca a cada
// píxel, así que no quedan huecos.
function proyectar(filas, esquinas, lado) {
  const n = filas.length;
  const data = new Uint8ClampedArray(lado * lado * 4);

  // Del píxel a la coordenada en módulos (con 4 de margen alrededor).
  const inversa = homografia(esquinas, [
    { x: -4, y: -4 }, { x: n + 4, y: -4 }, { x: n + 4, y: n + 4 }, { x: -4, y: n + 4 }
  ]);

  for (let y = 0; y < lado; y++) {
    for (let x = 0; x < lado; x++) {
      const m = inversa(x, y);
      let valor = 252;
      const mx = Math.floor(m.x), my = Math.floor(m.y);
      if (mx >= -4 && my >= -4 && mx < n + 4 && my < n + 4) {
        const dentro = mx >= 0 && my >= 0 && mx < n && my < n;
        valor = (dentro && filas[my][mx] === '1') ? 30 : 252;
      } else {
        valor = 210;   // fuera del carnet: la mesa
      }
      const p = (y * lado + x) * 4;
      data[p] = data[p + 1] = data[p + 2] = valor;
      data[p + 3] = 255;
    }
  }
  return { data, width: lado, height: lado };
}

console.log(JSON.stringify(casos.map(c => {
  const lado = 420;
  // Trapecio: el borde derecho más corto, como cuando el carnet se inclina.
  const esquinas = [
    { x: 40, y: 34 }, { x: 372, y: 76 },
    { x: 372, y: 336 }, { x: 40, y: 386 }
  ];
  try {
    return Lector.desdeImagen(proyectar(c.filas, esquinas, lado));
  } catch (e) { return 'ERROR: ' + e.message; }
})));
JS;

$perspectivas = [];
foreach ([
    ['https://tic.narino.gov.co/cumbreAI/c/9f3a2b7d10c4e5a1', 'M'],
    ['https://tic.narino.gov.co/cumbreAI/entrar/qr/4f9a2cb71e80c36d5a7b93e102fd4c68', 'Q'],
    ['https://tic.narino.gov.co/cumbreAI/d/4f9a2cb71e80c36d', 'H'],
    ['Ñañez Güépez — Secretaría TIC', 'L'],
] as [$texto, $nivel]) {
    $r = Qr::construir($texto, $nivel);
    $perspectivas[] = [
        'esperado' => $texto,
        'etiqueta' => 'v' . $r['version'] . $nivel,
        'filas'    => array_map(
            static fn(array $f): string => implode('', array_map(static fn($m) => $m ? '1' : '0', $f)),
            $r['modulos']
        ),
    ];
}

$entradaP = sys_get_temp_dir() . '/casos-lector-perspectiva.json';
$archivoP = sys_get_temp_dir() . '/lector-qr-perspectiva.js';
file_put_contents($entradaP, json_encode($perspectivas));
file_put_contents($archivoP, $guionPerspectiva);

$salidaP = shell_exec('node ' . escapeshellarg($archivoP) . ' '
    . escapeshellarg(RAIZ) . ' ' . escapeshellarg($entradaP) . ' 2>&1');
$leidoP = json_decode((string) $salidaP, true);

if (!is_array($leidoP)) {
    fwrite(STDERR, "No se pudo ejecutar la prueba de perspectiva:\n" . $salidaP . "\n");
    exit(1);
}

foreach ($perspectivas as $i => $caso) {
    comprobar($caso['etiqueta'] . ' inclinado en trapecio',
        ($leidoP[$i] ?? null) === $caso['esperado'],
        'devolvió: ' . mb_substr((string) ($leidoP[$i] ?? 'null'), 0, 50));
}

/* =========================================================================
   Corrección de errores
   -------------------------------------------------------------------------
   Se ensucian módulos de datos al azar y el texto tiene que salir igual. Es
   lo que separa un lector que funciona en la puerta de uno que solo funciona
   con la imagen perfecta.
   ========================================================================= */

titulo('Reed-Solomon: módulos dañados');

$pruebasDeDano = [];
mt_srand(77);
foreach (['L' => 3, 'M' => 6, 'Q' => 10, 'H' => 14] as $nivel => $cuantos) {
    $texto = 'https://tic.narino.gov.co/cumbreAI/entrar/qr/4f9a2cb71e80c36d5a7b93e102fd4c68';
    $r = Qr::construir($texto, $nivel);
    $filas = array_map(
        static fn(array $f): string => implode('', array_map(static fn($m) => $m ? '1' : '0', $f)),
        $r['modulos']
    );
    $n = count($filas);

    // Se dañan módulos de la mitad inferior derecha, que es zona de datos en
    // cualquier versión: tocar los patrones de búsqueda sería otra prueba.
    $tocados = 0;
    while ($tocados < $cuantos) {
        $y = mt_rand((int) ($n * 0.55), $n - 1);
        $x = mt_rand((int) ($n * 0.55), $n - 1);
        $filas[$y][$x] = $filas[$y][$x] === '1' ? '0' : '1';
        $tocados++;
    }

    $pruebasDeDano[] = ['esperado' => $texto, 'nivel' => $nivel, 'cuantos' => $cuantos, 'filas' => $filas];
}

$guionDano = <<<'JS'
const Lector = require(process.argv[2] + '/assets/js/qr-lector.js');
const casos = JSON.parse(require('fs').readFileSync(process.argv[3], 'utf8'));
console.log(JSON.stringify(casos.map(c => {
  try { return Lector.desdeMatriz(c.filas.map(f => f.split('').map(v => v === '1'))); }
  catch (e) { return 'ERROR: ' + e.message; }
})));
JS;

$entradaDano = sys_get_temp_dir() . '/casos-lector-dano.json';
$archivoDano = sys_get_temp_dir() . '/lector-qr-dano.js';
file_put_contents($entradaDano, json_encode($pruebasDeDano));
file_put_contents($archivoDano, $guionDano);

$salidaDano = shell_exec('node ' . escapeshellarg($archivoDano) . ' '
    . escapeshellarg(RAIZ) . ' ' . escapeshellarg($entradaDano) . ' 2>&1');
$leidoDano = json_decode((string) $salidaDano, true);

if (!is_array($leidoDano)) {
    fwrite(STDERR, "No se pudo ejecutar la prueba de daño:\n" . $salidaDano . "\n");
    exit(1);
}

foreach ($pruebasDeDano as $i => $caso) {
    comprobar('nivel ' . $caso['nivel'] . ' aguanta ' . $caso['cuantos'] . ' módulos dañados',
        ($leidoDano[$i] ?? null) === $caso['esperado'],
        'devolvió: ' . mb_substr((string) ($leidoDano[$i] ?? 'null'), 0, 50));
}

/* =========================================================================
   Basura: lo que NO tiene que decodificar
   -------------------------------------------------------------------------
   Un lector que devuelve cualquier cosa ante ruido es peor que uno que se
   calla: en la puerta abriría una dirección inventada.
   ========================================================================= */

titulo('Ruido: no puede inventarse un contenido');

$guionRuido = <<<'JS'
const Lector = require(process.argv[2] + '/assets/js/qr-lector.js');
let inventados = 0;
let intentos = 0;
// Semilla fija para que la prueba sea la misma en cada ejecución.
let semilla = 987654321;
const azar = () => {
  semilla = (semilla * 1103515245 + 12345) & 0x7fffffff;
  return semilla / 0x7fffffff;
};
for (let k = 0; k < 60; k++) {
  const n = 21 + 4 * (k % 6);
  const m = [];
  for (let y = 0; y < n; y++) {
    const fila = [];
    for (let x = 0; x < n; x++) fila.push(azar() < 0.5);
    m.push(fila);
  }
  intentos++;
  let r = null;
  try { r = Lector.desdeMatriz(m); } catch (e) { r = null; }
  if (r !== null && r !== '') inventados++;
}
console.log(JSON.stringify({ intentos, inventados }));
JS;

$archivoRuido = sys_get_temp_dir() . '/lector-qr-ruido.js';
file_put_contents($archivoRuido, $guionRuido);
$salidaRuido = shell_exec('node ' . escapeshellarg($archivoRuido) . ' ' . escapeshellarg(RAIZ) . ' 2>&1');
$ruido = json_decode((string) $salidaRuido, true);

if (!is_array($ruido)) {
    fwrite(STDERR, "No se pudo ejecutar la prueba de ruido:\n" . $salidaRuido . "\n");
    exit(1);
}

comprobar('60 matrices al azar y ninguna se decodifica',
    ($ruido['inventados'] ?? 1) === 0,
    ($ruido['inventados'] ?? '?') . ' devolvieron texto');

/* =========================================================================
   Resumen
   ========================================================================= */

echo "\n" . str_repeat('─', 60) . "\n";
echo $ok . ' comprobaciones correctas · ' . count($fallos) . " fallidas\n";
if ($saltados > 0) {
    echo "($saltados casos no cabían en la versión 20 y se omitieron)\n";
}
foreach ($fallos as $f) {
    echo "  ✗ $f\n";
}
exit($fallos === [] ? 0 : 1);
