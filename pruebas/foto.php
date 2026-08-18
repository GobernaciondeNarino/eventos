<?php
/**
 * El procesado de la fotografía del carnet.
 *
 * Es la única subida que hace alguien sin cuenta del equipo, así que lo que se
 * comprueba aquí no es solo que recorte bien: es que no se pueda usar el campo
 * para meter algo distinto de una imagen, ni el encuadre para pedir un recorte
 * imposible.
 *
 * Uso:  php pruebas/foto.php
 */
declare(strict_types=1);

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', 'pruebas');

require RAIZ . '/app/Nucleo/Imagen.php';

use App\Nucleo\Imagen;

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

function titulo(string $t): void
{
    echo "\n$t\n" . str_repeat('─', 58) . "\n";
}

echo "Fotografía del carnet · App\\Nucleo\\Imagen\n" . str_repeat('=', 58) . "\n";

if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "Este PHP no tiene GD; la prueba no puede correr.\n");
    exit(1);
}

$temporales = [];

/**
 * Una imagen de prueba con marcas de color en sitios conocidos, para poder
 * afirmar QUÉ trozo quedó dentro del recorte y no solo que el tamaño cuadre.
 */
function imagenDePrueba(int $ancho, int $alto, string $formato = 'jpeg'): string
{
    global $temporales;

    $im = imagecreatetruecolor($ancho, $alto);
    imagefilledrectangle($im, 0, 0, $ancho, $alto, imagecolorallocate($im, 240, 240, 240));

    // Un cuadro rojo arriba a la izquierda y uno azul abajo a la derecha, cada
    // uno de un octavo del lado menor.
    $marca = (int) max(4, min($ancho, $alto) / 8);
    imagefilledrectangle($im, 0, 0, $marca, $marca, imagecolorallocate($im, 220, 30, 30));
    imagefilledrectangle($im, $ancho - $marca, $alto - $marca, $ancho, $alto,
        imagecolorallocate($im, 30, 60, 220));

    $ruta = tempnam(sys_get_temp_dir(), 'foto') . '.' . $formato;
    match ($formato) {
        'png'  => imagepng($im, $ruta),
        'webp' => imagewebp($im, $ruta),
        default => imagejpeg($im, $ruta, 92),
    };
    imagedestroy($im);
    $temporales[] = $ruta;
    return $ruta;
}

/** Imita $_FILES sin pasar por una subida de verdad. */
function subida(string $ruta): array
{
    return [
        'name'     => basename($ruta),
        'type'     => 'image/jpeg',
        'tmp_name' => $ruta,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($ruta),
    ];
}

/**
 * Llama a guardarFoto sin la comprobación de subida.
 *
 * is_uploaded_file() devuelve false para un archivo que no llegó por HTTP, y
 * esa comprobación tiene que seguir estando en producción. Aquí se salta
 * llamando al recorte por reflexión, que es lo que de verdad se quiere probar.
 */
function recortar(string $ruta, ?array $encuadre): array
{
    $imagen = imagecreatefromstring((string) file_get_contents($ruta));
    $metodo = new ReflectionMethod(Imagen::class, 'recortarCuadrado');
    $metodo->setAccessible(true);
    $salida = $metodo->invoke(null, $imagen, $ruta, 'image/jpeg', $encuadre);
    imagedestroy($imagen);
    return [$salida, imagesx($salida), imagesy($salida)];
}

/** El color medio de una zona del resultado, para saber qué quedó dentro. */
function tono(\GdImage $im, float $fx, float $fy): array
{
    $x = (int) round(imagesx($im) * $fx);
    $y = (int) round(imagesy($im) * $fy);
    $rgb = imagecolorat($im, min($x, imagesx($im) - 1), min($y, imagesy($im) - 1));
    return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
}

$esRojo = static fn(array $c): bool => $c[0] > 150 && $c[1] < 110 && $c[2] < 110;
$esAzul = static fn(array $c): bool => $c[2] > 150 && $c[0] < 110;

/* =========================================================================
   Sin encuadre: el recorte de siempre
   ========================================================================= */
titulo('Sin encuadre, se recorta el centro');

$horizontal = imagenDePrueba(1200, 800);
[$im, $w, $h] = recortar($horizontal, null);
comprobar('sale cuadrada de 480 px', $w === 480 && $h === 480, "{$w}×{$h}");
comprobar('y no toma la esquina roja de la imagen ancha',
    !$esRojo(tono($im, 0.03, 0.03)), implode(',', tono($im, 0.03, 0.03)));
imagedestroy($im);

$vertical = imagenDePrueba(700, 1500);
[$im, $w, $h] = recortar($vertical, null);
comprobar('una foto vertical también sale cuadrada', $w === 480 && $h === 480, "{$w}×{$h}");
imagedestroy($im);

$cuadrada = imagenDePrueba(300, 300);
[$im, $w, $h] = recortar($cuadrada, null);
comprobar('una imagen más pequeña que 480 se amplía a 480', $w === 480 && $h === 480, "{$w}×{$h}");
comprobar('conserva sus dos esquinas, porque cabe entera',
    $esRojo(tono($im, 0.03, 0.03)) && $esAzul(tono($im, 0.97, 0.97)));
imagedestroy($im);

/* =========================================================================
   Con encuadre: se respeta lo que eligió la persona
   ========================================================================= */
titulo('Con el encuadre del editor');

// La esquina roja: 1200×800, marca de 100 px. Se pide justo ese cuadrado.
[$im] = recortar($horizontal, [
    'x' => 0, 'y' => 0, 'lado' => 100, 'ancho' => 1200, 'alto' => 800,
]);
comprobar('pidiendo la esquina superior izquierda, sale roja',
    $esRojo(tono($im, 0.5, 0.5)), implode(',', tono($im, 0.5, 0.5)));
imagedestroy($im);

[$im] = recortar($horizontal, [
    'x' => 1100, 'y' => 700, 'lado' => 100, 'ancho' => 1200, 'alto' => 800,
]);
comprobar('pidiendo la esquina inferior derecha, sale azul',
    $esAzul(tono($im, 0.5, 0.5)), implode(',', tono($im, 0.5, 0.5)));
imagedestroy($im);

// El navegador vio la imagen a otra escala —una vista previa reducida—: las
// coordenadas se reescalan solas.
[$im] = recortar($horizontal, [
    'x' => 0, 'y' => 0, 'lado' => 25, 'ancho' => 300, 'alto' => 200,
]);
comprobar('las coordenadas se reescalan si el navegador vio otra medida',
    $esRojo(tono($im, 0.5, 0.5)), implode(',', tono($im, 0.5, 0.5)));
imagedestroy($im);

/* =========================================================================
   Encuadres imposibles: nada de esto puede tumbar el preregistro
   ========================================================================= */
titulo('Encuadres manipulados o absurdos');

$casos = [
    'fuera de la imagen por la derecha' => ['x' => 5000, 'y' => 0, 'lado' => 200, 'ancho' => 1200, 'alto' => 800],
    'más grande que la imagen'          => ['x' => 0, 'y' => 0, 'lado' => 99999, 'ancho' => 1200, 'alto' => 800],
    'con lado cero'                     => ['x' => 10, 'y' => 10, 'lado' => 0, 'ancho' => 1200, 'alto' => 800],
    'con números negativos'             => ['x' => -500, 'y' => -500, 'lado' => -10, 'ancho' => 1200, 'alto' => 800],
    'con letras en vez de números'      => ['x' => 'a', 'y' => 'b', 'lado' => 'c', 'ancho' => 'd', 'alto' => 'e'],
    'con medidas de origen en cero'     => ['x' => 0, 'y' => 0, 'lado' => 100, 'ancho' => 0, 'alto' => 0],
    'a medio llegar'                    => ['lado' => 100],
    'con la forma cambiada'             => ['x' => 0, 'y' => 0, 'lado' => 100, 'ancho' => 100, 'alto' => 900],
    'con notación científica'           => ['x' => '1e40', 'y' => '1e40', 'lado' => '1e40', 'ancho' => 1200, 'alto' => 800],
];

foreach ($casos as $nombre => $encuadre) {
    try {
        [$im, $w, $h] = recortar($horizontal, $encuadre);
        comprobar($nombre . ': sale una foto válida', $w === 480 && $h === 480, "{$w}×{$h}");
        imagedestroy($im);
    } catch (\Throwable $e) {
        comprobar($nombre . ': sale una foto válida', false, get_class($e) . ': ' . $e->getMessage());
    }
}

/* =========================================================================
   Lo que no es una imagen
   ========================================================================= */
titulo('Archivos que no son fotos');

$php = tempnam(sys_get_temp_dir(), 'foto') . '.jpg';
file_put_contents($php, "<?php echo 'hola'; ?>\n");
$temporales[] = $php;

try {
    Imagen::guardarFoto(subida($php), 1);
    comprobar('un .php disfrazado de .jpg se rechaza', false, 'lo aceptó');
} catch (\DomainException $e) {
    comprobar('un .php disfrazado de .jpg se rechaza',
        str_contains($e->getMessage(), 'formato') || str_contains($e->getMessage(), 'subida'),
        $e->getMessage());
}

$svg = tempnam(sys_get_temp_dir(), 'foto') . '.svg';
file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
$temporales[] = $svg;

try {
    Imagen::guardarFoto(subida($svg), 1);
    comprobar('un SVG se rechaza: puede llevar guiones dentro', false, 'lo aceptó');
} catch (\DomainException $e) {
    comprobar('un SVG se rechaza: puede llevar guiones dentro', true);
}

/* =========================================================================
   Los metadatos no sobreviven
   ========================================================================= */
titulo('Metadatos');

$conExif = imagenDePrueba(900, 900);
// Un comentario JPEG con algo dentro, para comprobar que no llega al archivo
// final: la imagen se vuelve a generar entera, no se copia.
$bytes = (string) file_get_contents($conExif);
$marcador = "\xFF\xFE" . pack('n', 2 + 24) . 'SECRETO-QUE-NO-DEBE-IR';
$bytes = substr($bytes, 0, 2) . $marcador . substr($bytes, 2);
file_put_contents($conExif, $bytes);

[$im] = recortar($conExif, null);
$salida = tempnam(sys_get_temp_dir(), 'foto') . '.jpg';
imagejpeg($im, $salida, 82);
imagedestroy($im);
$temporales[] = $salida;

comprobar('el comentario incrustado no llega al archivo guardado',
    !str_contains((string) file_get_contents($salida), 'SECRETO-QUE-NO-DEBE-IR'));
comprobar('y lo que se guarda es un JPEG de verdad',
    (new \finfo(FILEINFO_MIME_TYPE))->file($salida) === 'image/jpeg');

/* =========================================================================
   Limpieza
   ========================================================================= */
foreach ($temporales as $t) {
    @unlink($t);
}

echo "\n" . str_repeat('─', 58) . "\n";
echo $ok . ' comprobaciones correctas · ' . count($fallos) . " fallidas\n";
foreach ($fallos as $f) {
    echo "  ✗ $f\n";
}
exit($fallos === [] ? 0 : 1);
