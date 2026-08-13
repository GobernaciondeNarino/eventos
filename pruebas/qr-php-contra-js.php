<?php
/**
 * Compara el generador de QR de PHP con el de JavaScript, matriz a matriz.
 *
 * El de JavaScript ya está verificado contra la librería de referencia
 * `qrcode` de Python (ver pruebas/qr-contra-referencia.py), así que si ambos
 * coinciden, el de PHP hereda esa verificación.
 *
 * Uso:  php pruebas/qr-php-contra-js.php
 */
declare(strict_types=1);

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', 'pruebas');

require RAIZ . '/app/Nucleo/Qr.php';

use App\Nucleo\Qr;

$casos = [];

// Cargas útiles reales de la plataforma
foreach ([
    'https://tic.narino.gov.co/cumbreAI/c/9f3a2b7d10c4e5a1',
    'https://tic.narino.gov.co/cumbreAI/d/4f9a2cb71e80c36d',
    'https://eventos.narino.gov.co/c/ab12cd34ef567890',
    "BEGIN:VCARD\nVERSION:3.0\nN:Zambrano;María Fernanda\nORG:Gobernación de Nariño\nEND:VCARD",
    'Ñañez Güépez — Secretaría TIC, Nariño',
] as $texto) {
    foreach (['L', 'M', 'Q', 'H'] as $nivel) {
        foreach (range(0, 7) as $mascara) {
            $casos[] = [$texto, $nivel, $mascara];
        }
    }
}

// Longitudes crecientes hasta agotar la versión 20
mt_srand(4242);
$alfabeto = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 :/.-_?=&áéíóúñÑ';
$letras = preg_split('//u', $alfabeto, -1, PREG_SPLIT_NO_EMPTY);
foreach ([1, 5, 13, 27, 55, 96, 170, 260, 380, 500, 620] as $largo) {
    $texto = '';
    for ($i = 0; $i < $largo; $i++) {
        $texto .= $letras[mt_rand(0, count($letras) - 1)];
    }
    foreach (['L', 'M', 'Q', 'H'] as $nivel) {
        $casos[] = [$texto, $nivel, mt_rand(0, 7)];
    }
}

// --- Lo que dice PHP -------------------------------------------------------
$dePhp = [];
$fueraDeCapacidad = 0;
foreach ($casos as $i => [$texto, $nivel, $mascara]) {
    try {
        $r = Qr::construir($texto, $nivel, $mascara);
        $dePhp[$i] = [
            'version' => $r['version'],
            'filas'   => array_map(
                static fn(array $fila): string => implode('', array_map(static fn($m) => $m ? '1' : '0', $fila)),
                $r['modulos']
            ),
        ];
    } catch (\RangeException) {
        $dePhp[$i] = null;
        $fueraDeCapacidad++;
    }
}

// --- Lo que dice JavaScript ------------------------------------------------
$entrada = sys_get_temp_dir() . '/casos-qr-php.json';
file_put_contents($entrada, json_encode(array_map(
    static fn(array $c): array => ['text' => $c[0], 'ecl' => $c[1], 'mask' => $c[2]],
    $casos
)));

$guion = <<<'JS'
const QR = require(process.argv[2] + '/pruebas/qr-referencia.js');
const casos = JSON.parse(require('fs').readFileSync(process.argv[3], 'utf8'));
console.log(JSON.stringify(casos.map(c => {
  try {
    const r = QR.encode(c.text, c.ecl, c.mask);
    return { version: r.version, filas: r.modules.map(f => f.map(v => v ? '1' : '0').join('')) };
  } catch (e) { return null; }
})));
JS;
$archivoGuion = sys_get_temp_dir() . '/qr-comparar.js';
file_put_contents($archivoGuion, $guion);

$salida = shell_exec('node ' . escapeshellarg($archivoGuion) . ' '
    . escapeshellarg(RAIZ) . ' ' . escapeshellarg($entrada) . ' 2>&1');
$deJs = json_decode((string) $salida, true);

if (!is_array($deJs)) {
    fwrite(STDERR, "No se pudo ejecutar el generador de JavaScript:\n" . $salida . "\n");
    exit(1);
}

// --- Comparación -----------------------------------------------------------
$fallos = [];
foreach ($casos as $i => [$texto, $nivel, $mascara]) {
    $php = $dePhp[$i];
    $js = $deJs[$i] ?? null;

    if ($php === null && $js === null) {
        continue;                       // ambos coinciden en que no cabe
    }
    if ($php === null || $js === null) {
        $fallos[] = sprintf('%s/%d largo %d: uno lo genera y el otro no', $nivel, $mascara, mb_strlen($texto));
        continue;
    }
    if ($php['version'] !== $js['version']) {
        $fallos[] = sprintf('%s/%d largo %d: versión php=%d js=%d',
            $nivel, $mascara, mb_strlen($texto), $php['version'], $js['version']);
        continue;
    }
    if ($php['filas'] !== $js['filas']) {
        $distintos = 0;
        foreach ($php['filas'] as $f => $fila) {
            for ($c = 0; $c < strlen($fila); $c++) {
                if ($fila[$c] !== $js['filas'][$f][$c]) {
                    $distintos++;
                }
            }
        }
        $fallos[] = sprintf('%s/%d largo %d: %d módulos distintos (v%d)',
            $nivel, $mascara, mb_strlen($texto), $distintos, $php['version']);
    }
}

printf(
    "casos comparados: %d   fuera de capacidad en ambos: %d   fallos: %d\n",
    count($casos) - $fueraDeCapacidad,
    $fueraDeCapacidad,
    count($fallos)
);
foreach (array_slice($fallos, 0, 12) as $f) {
    echo "  FALLO $f\n";
}

@unlink($entrada);
@unlink($archivoGuion);
exit($fallos ? 1 : 0);
