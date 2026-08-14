<?php
/**
 * Instalación desde la consola.
 *
 * El asistente del navegador es lo normal, pero depende de seis pasos, de una
 * cookie y de que nada se interrumpa por el camino. Cuando eso falla, el sitio
 * entero queda redirigiendo al asistente y no hay forma de salir desde fuera.
 * Esto hace lo mismo de una sola vez, con las mismas clases —no hay una segunda
 * implementación que pueda divergir— y contando en voz alta lo que va pasando.
 *
 * En Plesk, si no hay acceso SSH: Sitios web y dominios → Tareas programadas →
 * «Ejecutar un script PHP», con la ruta cumbreAI/herramientas/instalar.php y
 * los argumentos en el campo correspondiente. Ejecutar una vez y borrar la
 * tarea.
 *
 * Uso:
 *   php herramientas/instalar.php \
 *       --bd-nombre=eventos_tic --bd-usuario=eventos_app --bd-clave=… \
 *       --admin-correo=alguien@narino.gov.co --admin-nombre="Nombre Apellido" \
 *       --evento="Cumbre Tecnológica CIOS Nariño" --inicio=2026-09-01 --dias=3 \
 *       --url=https://tic.narino.gov.co/cumbreAI
 *
 * Opciones:
 *   --bd-host=localhost  --bd-puerto=3306  --bd-prefijo=evt_
 *   --admin-clave=…      si no se pasa, se genera y se muestra una sola vez
 *   --modo=actualizar    limpio | actualizar | anexar. Por defecto actualizar
 *                        si ya hay tablas, y limpio si la base está vacía.
 *   --sin-2fa            no exigir segundo factor al administrador
 *   --reparar            no instala: solo corrige la marca «instalado» cuando
 *                        la base ya está completa
 *   --forzar             permite reinstalar sobre una instalación terminada
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', '2.0.0');

spl_autoload_register(static function (string $clase): void {
    if (!str_starts_with($clase, 'App\\')) {
        return;
    }
    $relativa = str_replace('\\', '/', substr($clase, 4)) . '.php';
    if (str_contains($relativa, '..')) {
        return;
    }
    $archivo = RAIZ . '/app/' . $relativa;
    if (is_file($archivo)) {
        require $archivo;
    }
});

require RAIZ . '/app/ayudas.php';

use App\Esquema;
use App\Modelos\Evento;
use App\Modelos\Usuario;
use App\Nucleo\Bd;
use App\Nucleo\Config;
use App\Nucleo\Cripto;
use App\Nucleo\Instalacion;

/* =========================================================================
   Consola
   ========================================================================= */

$color = static function (string $texto, string $cual): string {
    $codigos = ['ok' => '0;32', 'mal' => '0;31', 'aviso' => '0;33', 'tenue' => '0;90', 'fuerte' => '1;37'];
    if (!stream_isatty(STDOUT) || !isset($codigos[$cual])) {
        return $texto;
    }
    return "\033[" . $codigos[$cual] . 'm' . $texto . "\033[0m";
};
$linea = static function (string $texto = ''): void { fwrite(STDOUT, $texto . PHP_EOL); };
$paso  = static function (string $texto) use ($linea, $color): void { $linea($color('· ' . $texto, 'tenue')); };
$bien  = static function (string $texto) use ($linea, $color): void { $linea($color('✓ ' . $texto, 'ok')); };
$morir = static function (string $texto) use ($color): never {
    fwrite(STDERR, $color('✕ ' . $texto, 'mal') . PHP_EOL);
    exit(1);
};

$opcion = static function (string $nombre, ?string $porDefecto = null) use ($argv): ?string {
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, "--$nombre=")) {
            return substr($arg, strlen($nombre) + 3);
        }
    }
    return $porDefecto;
};
$bandera = static fn(string $nombre): bool => in_array("--$nombre", array_slice($argv, 1), true);

$generarClave = static function (): string {
    $silabas = ['ba','ca','da','fa','ga','la','ma','na','pa','ra','sa','ta','be','ce','de','fe',
                'le','me','ne','pe','re','se','te','bi','ci','di','li','mi','ni','pi','ri','si',
                'ti','bo','co','do','lo','mo','no','po','ro','so','to','bu','cu','du','lu','mu'];
    $palabras = [];
    for ($p = 0; $p < 4; $p++) {
        $palabra = '';
        for ($s = 0; $s < 3; $s++) {
            $palabra .= $silabas[random_int(0, count($silabas) - 1)];
        }
        $palabras[] = $palabra;
    }
    return implode('-', $palabras) . '-' . random_int(10, 99);
};

$linea();
$linea($color('Plataforma de Eventos TIC · instalación desde la consola', 'fuerte'));
$linea();

/* =========================================================================
   Reparar: la base está lista y solo falta la marca
   ========================================================================= */

if ($bandera('reparar')) {
    if (!Config::existe()) {
        $morir('No existe config/config.php, así que no hay nada que reparar. '
            . 'Ejecuta la instalación completa (mira --ayuda).');
    }
    Config::cargar();
    try {
        Bd::conectar();
    } catch (\Throwable $e) {
        $morir('No se pudo conectar con los datos de config/config.php: ' . $e->getMessage());
    }

    $d = Instalacion::diagnostico();
    if (!$d['completa']) {
        $morir('La base de datos no está completa, así que reparar la marca no arreglaría nada. '
            . 'Motivo: ' . $d['motivo']);
    }

    if (!Config::escribir(['instalado' => true, 'version' => APP_VERSION] + Config::todo())) {
        $morir('No se pudo escribir config/config.php. Revisa que la carpeta config/ tenga '
            . 'permiso de escritura para el usuario del dominio; sin eso la marca no se corrige, '
            . 'por más que todo lo demás esté bien.');
    }
    $bien('Marca corregida. La plataforma ya no debería redirigir al asistente.');
    $linea();
    exit(0);
}

/* =========================================================================
   Instalación completa
   ========================================================================= */

if ($bandera('ayuda') || $bandera('help')) {
    // La ayuda es el comentario de cabecera de este mismo archivo: así no hay
    // dos textos que puedan contradecirse.
    preg_match('/\/\*\*(.*?)\*\//s', (string) file_get_contents(__FILE__), $m);
    foreach (explode("\n", $m[1] ?? '') as $l) {
        $linea(rtrim((string) preg_replace('/^\s*\*\s?/', '', $l)));
    }
    exit(0);
}

Config::cargar();
if (Config::instalado() && !$bandera('forzar')) {
    $linea($color('La plataforma ya está instalada.', 'aviso'));
    $linea('  Para volver a instalar encima, añade --forzar. Para ver el estado:');
    $linea($color('    php herramientas/cuenta.php estado', 'tenue'));
    $linea();
    exit(0);
}

$bd = [
    'host'    => (string) $opcion('bd-host', 'localhost'),
    'puerto'  => (int) ($opcion('bd-puerto', '3306') ?: 3306),
    'nombre'  => (string) $opcion('bd-nombre', ''),
    'usuario' => (string) $opcion('bd-usuario', ''),
    'clave'   => (string) $opcion('bd-clave', ''),
    'prefijo' => (string) $opcion('bd-prefijo', 'evt_'),
];

$adminCorreo = mb_strtolower(trim((string) $opcion('admin-correo', '')));
$adminNombre = trim((string) $opcion('admin-nombre', ''));
$adminClave  = (string) $opcion('admin-clave', '');
$claveGenerada = $adminClave === '';

$evento   = trim((string) $opcion('evento', ''));
$inicio   = (string) $opcion('inicio', date('Y-m-d'));
$jornadas = max(1, min(30, (int) ($opcion('dias', '3') ?: 3)));
$url      = rtrim((string) $opcion('url', ''), '/');

/* ---- Validación, toda junta y antes de tocar nada --------------------- */

$faltan = [];
if ($bd['nombre'] === '')  { $faltan[] = '--bd-nombre'; }
if ($bd['usuario'] === '') { $faltan[] = '--bd-usuario'; }
if (!filter_var($adminCorreo, FILTER_VALIDATE_EMAIL)) { $faltan[] = '--admin-correo (válido)'; }
if (mb_strlen($adminNombre) < 5) { $faltan[] = '--admin-nombre'; }
if (mb_strlen($evento) < 3) { $faltan[] = '--evento'; }
if ($url === '' || !preg_match('#^https?://#', $url)) { $faltan[] = '--url (con http:// o https://)'; }
if (!Evento::fechaValida($inicio)) { $faltan[] = '--inicio (AAAA-MM-DD)'; }
if (!preg_match('/^[a-z][a-z0-9_]{0,15}$/', $bd['prefijo'])) { $faltan[] = '--bd-prefijo (letras minúsculas)'; }
if (!$claveGenerada && mb_strlen($adminClave) < 12) { $faltan[] = '--admin-clave de 12 caracteres o más'; }

if ($faltan) {
    $morir('Faltan datos o son inválidos: ' . implode(', ', $faltan) . '.'
        . PHP_EOL . '  Usa --ayuda para ver un ejemplo completo.');
}

/* ---- Requisitos del servidor ------------------------------------------ */

$paso('Comprobando el servidor');
foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'fileinfo'] as $extension) {
    if (!extension_loaded($extension)) {
        $morir("Falta la extensión de PHP «{$extension}», que es obligatoria.");
    }
}
if (PHP_VERSION_ID < 80100) {
    $morir('Se requiere PHP 8.1 o superior; este es ' . PHP_VERSION . '.');
}
foreach (['config', 'almacen/logos', 'almacen/fotos', 'almacen/respaldos', 'almacen/registro'] as $relativa) {
    $ruta = RAIZ . '/' . $relativa;
    if (!is_dir($ruta)) {
        @mkdir($ruta, 0750, true);
    }
    if (!is_dir($ruta) || !is_writable($ruta)) {
        $morir("La carpeta $relativa/ no existe o no se puede escribir en ella.");
    }
}
$bien('PHP ' . PHP_VERSION . ', extensiones y permisos correctos');

/* ---- Conexión --------------------------------------------------------- */

$paso('Conectando a la base de datos');
[$ok, $mensaje] = Bd::probar($bd);
if (!$ok) {
    $morir($mensaje);
}
Config::establecerEnMemoria([
    'bd_host' => $bd['host'], 'bd_puerto' => $bd['puerto'], 'bd_nombre' => $bd['nombre'],
    'bd_usuario' => $bd['usuario'], 'bd_clave' => $bd['clave'], 'bd_prefijo' => $bd['prefijo'],
]);
Bd::reiniciar();
Bd::conectar();
Bd::establecerPrefijo($bd['prefijo']);
$bien($mensaje);

/* ---- Tablas ----------------------------------------------------------- */

$existentes = Esquema::existentes();
$modo = (string) $opcion('modo', $existentes ? 'actualizar' : 'limpio');
if (!in_array($modo, ['limpio', 'actualizar', 'anexar'], true)) {
    $morir('El modo debe ser limpio, actualizar o anexar.');
}
if ($modo === 'limpio' && $existentes && !$bandera('forzar')) {
    $morir('El modo limpio borraría ' . count($existentes) . ' tablas que ya existen. '
        . 'Usa --modo=actualizar para conservarlas, o añade --forzar si de verdad quieres borrarlas.');
}

$paso('Aplicando el esquema en modo ' . $modo . ' (' . count($existentes) . ' tablas presentes)');
try {
    $hechas = Esquema::aplicar($modo, $existentes);
} catch (\Throwable $e) {
    $morir('No se pudo aplicar el esquema: ' . $e->getMessage());
}
$bien(count($hechas) . ' operaciones sobre el esquema · ' . count(Esquema::nombres()) . ' tablas en total');

/* ---- Configuración en memoria, para que lo demás pueda escribir ------- */

$llave = (string) Config::obtener('llave_cifrado', '') ?: Cripto::generarLlave();
$configuracion = [
    'instalado'        => true,
    'version'          => APP_VERSION,
    'bd_host'          => $bd['host'],
    'bd_puerto'        => $bd['puerto'],
    'bd_nombre'        => $bd['nombre'],
    'bd_usuario'       => $bd['usuario'],
    'bd_clave'         => $bd['clave'],
    'bd_prefijo'       => $bd['prefijo'],
    'llave_cifrado'    => $llave,
    'zona_horaria'     => 'America/Bogota',
    'url_base'         => $url,
    'correo_remitente' => 'no-responder@' . (parse_url($url, PHP_URL_HOST) ?: 'localhost'),
    'correo_nombre'    => $evento,
    'modo_correo'      => function_exists('mail') ? 'php' : 'registro',
    'exigir_2fa_admin' => !$bandera('sin-2fa'),
    'proxies_confiables' => [],
    'depurar'          => false,
    'instalado_en'     => date('c'),
];
Config::establecerEnMemoria($configuracion);
date_default_timezone_set('America/Bogota');

/* ---- Cuenta y evento, antes de escribir la marca ---------------------- */

$paso('Creando la cuenta administradora');
if ($claveGenerada) {
    $adminClave = $generarClave();
}
try {
    $usuarioId = Usuario::asegurarAdministrador($adminCorreo, $adminNombre, Cripto::hashClave($adminClave));
} catch (\Throwable $e) {
    $morir('No se pudo crear la cuenta: ' . $e->getMessage());
}
$bien('Cuenta ' . $adminCorreo . ' lista, con el id ' . $usuarioId);

$paso('Creando el evento y sus jornadas');
try {
    $eventoId = Evento::crear([
        'nombre'       => $evento,
        'dependencia'  => (string) $opcion('dependencia', 'Secretaría TIC, Innovación y Gobierno Abierto'),
        'sede'         => (string) $opcion('sede', ''),
        'fecha_inicio' => $inicio,
        'jornadas'     => $jornadas,
        'estado'       => 'abierto',
        'activo'       => true,
        'preset'       => (string) $opcion('preset', 'tic-nocturno'),
        'tipografia'   => (string) $opcion('tipografia', 'tecnologica'),
    ]);
} catch (\Throwable $e) {
    $morir('No se pudo crear el evento: ' . $e->getMessage());
}
$bien('Evento «' . $evento . '» con ' . $jornadas . ' jornadas, id ' . $eventoId);

/* ---- Y solo ahora, la marca ------------------------------------------- */

$paso('Escribiendo config/config.php');
if (!Config::escribir($configuracion)) {
    $morir('No se pudo escribir config/config.php. La cuenta y el evento sí quedaron creados: '
        . 'arregla los permisos de config/ y vuelve a ejecutar esto mismo, que no duplicará nada.');
}
@unlink(RAIZ . '/config/instalacion.php');
$bien('Configuración escrita');

/* ---- Resumen ---------------------------------------------------------- */

$linea();
$linea($color('Instalación terminada.', 'fuerte'));
$linea();
$linea('  Panel:      ' . $color($url . '/admin/entrar', 'fuerte'));
$linea('  Usuario:    ' . $color($adminCorreo, 'fuerte'));
if ($claveGenerada) {
    $linea('  Contraseña: ' . $color($adminClave, 'fuerte'));
    $linea($color('              Se muestra una sola vez. Cámbiala al entrar.', 'tenue'));
}
$linea('  Cuentas en: ' . $color($bd['prefijo'] . 'usuario', 'fuerte'));
$linea();
if (!$bandera('sin-2fa')) {
    $linea($color('  Al entrar se pedirá vincular una aplicación de códigos: el segundo', 'tenue'));
    $linea($color('  factor es obligatorio para el rol administrador.', 'tenue'));
    $linea();
}
$linea('  Comprueba el estado cuando quieras con:');
$linea($color('    php herramientas/cuenta.php estado', 'tenue'));
$linea('  O desde el navegador, en ' . $url . '/instalar/diagnostico');
$linea();

exit(0);
