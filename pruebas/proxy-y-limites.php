<?php
/**
 * La dirección del visitante detrás de un proxy.
 *
 * En Plesk casi siempre hay nginx por delante de Apache, y en este despliegue
 * además Cloudflare por delante de todo. Si la aplicación no sabe que hay un
 * proxy, ve siempre la misma dirección y el límite de intentos deja de ser por
 * visitante: pasa a ser uno solo para todo el mundo, y veinte accesos fallidos
 * de cualquiera dejan fuera al equipo entero.
 *
 * Lo contrario es igual de malo: hacer caso a las cabeceras de reenvío sin
 * comprobar de dónde vienen permite a cualquiera falsear su origen y saltarse
 * los bloqueos. Esto comprueba las dos mitades.
 *
 * Uso:  php pruebas/proxy-y-limites.php
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
use App\Nucleo\Peticion;

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
        echo "  ✗ $nombre" . ($extra ? "  → $extra" : '') . "\n";
    }
}

/** Monta un escenario de servidor y devuelve lo que la aplicación deduciría. */
function escenario(array $servidor, array $configuracion = []): array
{
    $_SERVER = $servidor + ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/'];
    Config::establecerEnMemoria($configuracion + ['proxies_confiables' => []]);
    $peticion = new Peticion();
    return [$peticion->ip(), $peticion->detrasDeProxySinConfigurar()];
}

echo "Dirección del visitante y límites\n" . str_repeat('=', 52) . "\n\n";

echo "Sin proxy declarado\n";

[$ip, $aviso] = escenario([
    'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_X_FORWARDED_FOR' => '181.49.20.7',
]);
comprobar('no se hace caso a X-Forwarded-For', $ip === '127.0.0.1', $ip);
comprobar('pero se detecta y se avisa del proxy', $aviso === true);

[$ip, $aviso] = escenario([
    'REMOTE_ADDR' => '181.49.20.7',
    'HTTP_X_FORWARDED_FOR' => '10.0.0.1',
]);
comprobar('una dirección pública no se toma por un proxy', $aviso === false);
comprobar('y se usa tal cual', $ip === '181.49.20.7', $ip);

[$ip, $aviso] = escenario(['REMOTE_ADDR' => '181.49.20.7']);
comprobar('sin cabeceras de reenvío no se avisa de nada', $aviso === false);

echo "\nCon el proxy declarado\n";

$conProxy = ['proxies_confiables' => ['127.0.0.1', '::1']];

[$ip, $aviso] = escenario([
    'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_X_FORWARDED_FOR' => '181.49.20.7',
], $conProxy);
comprobar('ahora sí se usa la del visitante', $ip === '181.49.20.7', $ip);
comprobar('y deja de avisar', $aviso === false);

[$ip] = escenario([
    'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_CF_CONNECTING_IP' => '181.49.20.7',
    'HTTP_X_FORWARDED_FOR' => '181.49.20.7, 172.68.1.1',
], $conProxy);
comprobar('Cloudflare: se prefiere CF-Connecting-IP', $ip === '181.49.20.7', $ip);

[$ip] = escenario([
    'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_X_FORWARDED_FOR' => '181.49.20.7, 172.68.1.1, 10.0.0.5',
], $conProxy);
comprobar('de la cadena se toma el primero, que es el visitante', $ip === '181.49.20.7', $ip);

[$ip] = escenario([
    'REMOTE_ADDR' => '190.85.1.1',
    'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
], $conProxy);
comprobar('quien no es un proxy declarado no puede falsear su origen',
    $ip === '190.85.1.1', $ip);

[$ip] = escenario([
    'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_X_FORWARDED_FOR' => 'no-es-una-ip, 181.49.20.7',
], $conProxy);
comprobar('una cabecera con basura no rompe nada', $ip === '181.49.20.7', $ip);

echo "\nDirecciones internas\n";
foreach ([
    ['127.0.0.1', true], ['::1', true], ['10.0.0.5', true], ['192.168.1.1', true],
    ['172.16.0.1', true], ['181.49.20.7', false], ['8.8.8.8', false], ['no-ip', false],
] as [$candidata, $esperado]) {
    comprobar(
        sprintf('%-14s %s', $candidata, $esperado ? 'es interna' : 'no es interna'),
        Peticion::esDireccionInterna($candidata) === $esperado
    );
}

echo "\n" . str_repeat('─', 52) . "\n";
printf("%d comprobaciones correctas · %d fallidas\n", $ok, count($fallos));
exit($fallos ? 1 : 0);
