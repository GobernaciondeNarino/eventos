<?php
/**
 * El hash de las contraseñas del equipo.
 *
 * Existe por un fallo que tumbó una instalación en producción y que era
 * invisible desde fuera.
 *
 * PHP puede traer Argon2 de dos sitios: la biblioteca libargon2 suelta, o la
 * que va incluida en libsodium. Las dos definen PASSWORD_ARGON2ID, las dos
 * salen igual en phpinfo() y la versión de PHP es la misma. Pero **la de
 * libsodium solo admite un hilo**: pedirle dos no degrada el hash, lanza
 *
 *     ValueError: A thread value other than 1 is not supported by this implementation
 *
 * El código pedía dos. En la máquina de desarrollo —libargon2 suelta— funcionó
 * siempre; en el servidor del despliegue reventó en el paso 4 del asistente,
 * justo al convertir la contraseña del administrador, y dejó la instalación con
 * las dieciséis tablas creadas, la tabla de usuarios vacía y sin ninguna forma
 * de entrar.
 *
 * Estas comprobaciones no dependen de con qué esté compilado el PHP que las
 * ejecuta: revisan los parámetros que se piden, que es donde estaba el error.
 *
 * Uso:  php pruebas/claves.php
 */
declare(strict_types=1);

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', 'pruebas');

spl_autoload_register(static function (string $clase): void {
    if (!str_starts_with($clase, 'App\\')) {
        return;
    }
    $archivo = RAIZ . '/app/' . str_replace('\\', '/', substr($clase, 4)) . '.php';
    if (is_file($archivo)) {
        require $archivo;
    }
});
require RAIZ . '/app/ayudas.php';

use App\Nucleo\Config;
use App\Nucleo\Cripto;

Config::establecerEnMemoria(['llave_cifrado' => str_repeat('a', 64), 'depurar' => false]);

$ok = 0;
$fallos = [];

function comprobar(string $nombre, bool $condicion, string $extra = ''): void
{
    global $ok, $fallos;
    if ($condicion) {
        $ok++;
        echo "  ✓ $nombre\n";
    } else {
        $fallos[] = $nombre;
        echo "  ✗ $nombre" . ($extra !== '' ? "  → $extra" : '') . "\n";
    }
}

echo "\nContraseñas del equipo\n";
echo str_repeat('─', 62) . "\n";

echo "\nCon qué está compilado este PHP\n";
$suelta = true;
try {
    password_hash('x', PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 2]);
} catch (\Throwable) {
    $suelta = false;
}
echo '  · Argon2id: ' . (defined('PASSWORD_ARGON2ID') ? 'disponible' : 'ausente')
    . ' · varios hilos: ' . ($suelta ? 'sí (libargon2 suelta)' : 'no (la de libsodium)') . "\n";
echo "  · Las comprobaciones de abajo valen igual en las dos compilaciones.\n";

/* =====================================================================
   Lo que se pide, que es donde estaba el fallo
   ===================================================================== */

echo "\nParámetros solicitados\n";

[$algoritmo, $opciones] = Cripto::algoritmoDeClave();

if (defined('PASSWORD_ARGON2ID')) {
    comprobar('se pide Argon2id', $algoritmo === PASSWORD_ARGON2ID);
    comprobar('con un solo hilo: la compilación de libsodium no admite más',
        ($opciones['threads'] ?? null) === 1,
        'se pidieron ' . var_export($opciones['threads'] ?? null, true));
    comprobar('con 64 MiB de memoria, que es lo que de verdad protege',
        ($opciones['memory_cost'] ?? 0) === 65536, (string) ($opciones['memory_cost'] ?? 0));
    comprobar('y cuatro pasadas', ($opciones['time_cost'] ?? 0) === 4);
} else {
    comprobar('sin Argon2id se pide bcrypt', $algoritmo === PASSWORD_BCRYPT);
    comprobar('con coste 12', ($opciones['cost'] ?? 0) === 12);
}

/* Ningún sitio del código puede volver a pedir más de un hilo. Es la guarda que
   habría atrapado el fallo antes de que llegara al servidor. */
echo "\nNadie vuelve a pedir más de un hilo\n";

$sospechosos = [];
$directorio = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(RAIZ . '/app', FilesystemIterator::SKIP_DOTS)
);
foreach ($directorio as $archivo) {
    if ($archivo->getExtension() !== 'php') {
        continue;
    }
    $contenido = (string) file_get_contents($archivo->getPathname());
    if (preg_match_all("/'threads'\s*=>\s*(\d+)/", $contenido, $m, PREG_SET_ORDER)) {
        foreach ($m as $encontrado) {
            if ((int) $encontrado[1] !== 1) {
                $sospechosos[] = str_replace(RAIZ . '/', '', $archivo->getPathname())
                    . ' pide ' . $encontrado[1];
            }
        }
    }
}
comprobar('ningún archivo de app/ pide threads distinto de 1',
    $sospechosos === [], implode('; ', $sospechosos));

/* =====================================================================
   Que el hash sirva de verdad
   ===================================================================== */

echo "\nEl hash funciona\n";

$clave = 'ClaveDePrueba2026!';
$hash = Cripto::hashClave($clave);

comprobar('hashClave devuelve algo', $hash !== '' && strlen($hash) > 30);
comprobar('la contraseña correcta se verifica', Cripto::verificarClave($clave, $hash));
comprobar('una equivocada no', !Cripto::verificarClave($clave . 'x', $hash));
comprobar('la contraseña no aparece en claro dentro del hash',
    !str_contains($hash, $clave));

if (defined('PASSWORD_ARGON2ID')) {
    comprobar('el hash sale con p=1', str_contains($hash, ',p=1$'), $hash);
    comprobar('y con m=65536,t=4', str_contains($hash, 'm=65536,t=4,'), $hash);
}

comprobar('dos hashes de la misma contraseña son distintos: hay sal',
    Cripto::hashClave($clave) !== $hash);

/* =====================================================================
   Sin reescrituras innecesarias en cada acceso
   ===================================================================== */

echo "\nSin rehash en bucle\n";

comprobar('un hash recién hecho no pide rehacerse',
    !Cripto::claveNecesitaRehash($hash));

$bcrypt = password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]);
comprobar('un bcrypt no se rehace solo en un servidor con Argon2id',
    !Cripto::claveNecesitaRehash($bcrypt),
    'puede venir de una compilación que rechazó Argon2; reescribirlo en cada '
    . 'acceso es trabajo perdido');
comprobar('pero ese bcrypt sigue verificando', Cripto::verificarClave($clave, $bcrypt));

if (defined('PASSWORD_ARGON2ID')) {
    // Un hash con parámetros más flojos que los actuales sí debe rehacerse.
    $flojo = password_hash($clave, PASSWORD_ARGON2ID,
        ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]);
    comprobar('un Argon2id con parámetros viejos sí se rehace',
        Cripto::claveNecesitaRehash($flojo));
}

/* =====================================================================
   Un hash hecho donde solo hay un hilo se verifica aquí, y al revés
   ===================================================================== */

echo "\nCompatible entre las dos compilaciones\n";

if (defined('PASSWORD_ARGON2ID')) {
    $unHilo = password_hash($clave, PASSWORD_ARGON2ID,
        ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]);
    comprobar('un hash de un hilo se verifica', Cripto::verificarClave($clave, $unHilo));
    comprobar('y es exactamente el que produce hashClave',
        substr($unHilo, 0, strpos($unHilo, '$', 10) ?: 20) === substr($hash, 0, strpos($hash, '$', 10) ?: 20));
}

/* Contraseñas con acentos, ñ y emoji: el formulario del asistente las acepta. */
echo "\nContraseñas con caracteres de verdad\n";

foreach ([
    'Contraseña Muy Segura 2026' => 'con ñ y espacios',
    'Añó-Nuevó-2026-Pásto!'      => 'con tildes',
    'clave-con-emoji-🔐-2026'     => 'con emoji',
    'aaaaaaaaaaaa'               => 'el mínimo de 12 caracteres',
] as $prueba => $descripcion) {
    $h = Cripto::hashClave($prueba);
    comprobar($descripcion, Cripto::verificarClave($prueba, $h) && !Cripto::verificarClave($prueba . 'x', $h));
}

echo "\n" . str_repeat('─', 62) . "\n";
printf("%d comprobaciones correctas · %d fallidas\n\n", $ok, count($fallos));
foreach ($fallos as $f) {
    echo "  ✗ $f\n";
}
exit($fallos ? 1 : 0);
