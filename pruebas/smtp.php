<?php
/**
 * El cliente SMTP, contra un servidor de verdad.
 *
 * No se simula la red: se levanta un servidor SMTP en un puerto local —incluido
 * TLS con certificado autofirmado— y se habla con él. Es la única forma de
 * comprobar cosas que solo se rompen sobre el cable: las respuestas de varias
 * líneas, el punto doblado dentro del mensaje, o el segundo EHLO obligatorio
 * después de STARTTLS.
 *
 * Cada escenario reproduce una respuesta que dan de verdad Google o Microsoft,
 * y se comprueba además que la explicación que sale en pantalla sea la que
 * corresponde. De poco sirve detectar el 535 si lo que se le enseña al
 * administrador es «no se pudo enviar».
 *
 * Uso:  php pruebas/smtp.php
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
use App\Nucleo\Correo;
use App\Nucleo\Smtp;

Config::establecerEnMemoria(['llave_cifrado' => str_repeat('b', 64), 'depurar' => false]);
$_SERVER['SERVER_NAME'] = 'pruebas.narino.gov.co';

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
        echo "  ✗ $nombre" . ($extra !== '' ? "  → " . mb_substr($extra, 0, 300) : '') . "\n";
    }
}

function titulo(string $texto): void
{
    echo "\n$texto\n";
}

/* =====================================================================
   Certificado autofirmado para los escenarios con TLS
   ===================================================================== */

$certificado = sys_get_temp_dir() . '/smtp-pruebas-' . getmypid() . '.pem';
exec('openssl req -x509 -newkey rsa:2048 -keyout ' . escapeshellarg($certificado)
    . ' -out ' . escapeshellarg($certificado) . ' -days 2 -nodes -subj "/CN=127.0.0.1" 2>/dev/null',
    $salidaCert, $estadoCert);
$hayTls = $estadoCert === 0 && is_file($certificado);
register_shutdown_function(static function () use ($certificado): void {
    @unlink($certificado);
});

/* =====================================================================
   Arrancar y parar el servidor de mentira
   ===================================================================== */

$puertoLibre = static function (): int {
    $s = stream_socket_server('tcp://127.0.0.1:0', $n, $t);
    $nombre = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr((string) $nombre, strrpos((string) $nombre, ':') + 1);
};

/** @return array{0: resource, 1: array, 2: int} proceso, tuberías y puerto */
$levantar = static function (string $escenario) use ($puertoLibre, $certificado): array {
    $puerto = $puertoLibre();
    $orden = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(RAIZ . '/pruebas/apoyo/servidor-smtp.php')
        . ' ' . $puerto . ' ' . escapeshellarg($escenario) . ' ' . escapeshellarg($certificado);

    $tuberias = [];
    $proceso = proc_open($orden, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
    if (!is_resource($proceso)) {
        throw new RuntimeException('No se pudo lanzar el servidor de prueba.');
    }

    // Esperar a que diga «listo». Sin esto la prueba corre antes de que el
    // servidor esté escuchando y falla por una carrera, no por el código.
    stream_set_blocking($tuberias[1], false);
    $espera = microtime(true) + 10;
    $anuncio = '';
    while (microtime(true) < $espera && !str_contains($anuncio, 'listo')) {
        $anuncio .= (string) fread($tuberias[1], 64);
        usleep(20000);
    }

    return [$proceso, $tuberias, $puerto];
};

$bajar = static function (array $lanzado): void {
    [$proceso, $tuberias] = $lanzado;
    foreach ($tuberias as $tuberia) {
        if (is_resource($tuberia)) {
            fclose($tuberia);
        }
    }
    if (is_resource($proceso)) {
        proc_terminate($proceso);
        proc_close($proceso);
    }
};

echo "\nCliente SMTP\n";
echo str_repeat('─', 62) . "\n";
echo '  · TLS en las pruebas: ' . ($hayTls ? 'sí, con certificado autofirmado' : 'no (falta openssl en consola)') . "\n";

/* =====================================================================
   Envío correcto
   ===================================================================== */

titulo('Un envío que sale bien');

$lanzado = $levantar('sin-starttls');   // ofrece AUTH sin exigir cifrado
[$proceso, $tuberias, $puerto] = $lanzado;

// Dieciséis letras, como las que genera Google, pero inventadas. Una
// contraseña de verdad no entra en el repositorio ni siquiera como dato de
// prueba: lo que se sube a un repositorio se queda ahí para siempre.
$claveDeMentira = 'abcdefghijklmnop';

$cliente = new Smtp('127.0.0.1', $puerto, 'ninguna', 'hosting@narino.gov.co', $claveDeMentira, 10, false);
$enviado = $cliente->enviar(
    'hosting@narino.gov.co',
    'alguien@narino.gov.co',
    "From: Eventos TIC <hosting@narino.gov.co>\r\nSubject: Prueba",
    "Hola.\n.punto al principio\nFin."
);
comprobar('el mensaje se entrega', $enviado, $cliente->error());
comprobar('sin código de error', $cliente->codigo() === '', $cliente->codigo());

$transcripcion = $cliente->transcripcion();
comprobar('la transcripción registra el saludo', str_contains($transcripcion, '220 mentira.example'));
comprobar('y el EHLO', str_contains($transcripcion, 'EHLO pruebas.narino.gov.co'));
comprobar('la contraseña NO aparece en la transcripción',
    !str_contains($transcripcion, $claveDeMentira)
    && !str_contains($transcripcion, base64_encode($claveDeMentira)),
    $transcripcion);
comprobar('en su lugar se anota que iba ahí',
    str_contains($transcripcion, '[contraseña en base64]'), $transcripcion);

$recibido = @file_get_contents(sys_get_temp_dir() . '/smtp-recibido-' . $puerto . '.txt');
comprobar('el servidor recibió el mensaje', is_string($recibido) && $recibido !== '');
comprobar('con la cabecera de asunto', is_string($recibido) && str_contains($recibido, 'Subject: Prueba'));
comprobar('la línea que empieza por punto llegó entera',
    is_string($recibido) && str_contains($recibido, "\n.punto al principio"),
    'el cliente tiene que doblar ese punto; si no, el servidor lo lee como fin de mensaje');
comprobar('y el mensaje no se cortó ahí',
    is_string($recibido) && str_contains($recibido, 'Fin.'),
    'sin doblar el punto, todo lo que va detrás se pierde');
@unlink(sys_get_temp_dir() . '/smtp-recibido-' . $puerto . '.txt');
$bajar($lanzado);

/* =====================================================================
   Credenciales rechazadas: el caso más frecuente
   ===================================================================== */

titulo('Contraseña rechazada (535-5.7.8)');

$lanzado = $levantar('auth-535');
[$proceso, $tuberias, $puerto] = $lanzado;

$cliente = new Smtp('127.0.0.1', $puerto, 'ninguna', 'hosting@narino.gov.co', 'la-normal-no-vale', 10, false);
comprobar('no se autentica', !$cliente->comprobar());
comprobar('el código es 535', $cliente->codigo() === '535', $cliente->codigo());
comprobar('el error trae el texto del servidor',
    str_contains($cliente->error(), 'Username and Password not accepted'), $cliente->error());

$pistas = implode(' ', Correo::explicar($cliente->codigo(), $cliente->error()));
comprobar('la explicación habla de la contraseña de aplicación',
    str_contains($pistas, 'contraseña de aplicación'), $pistas);
comprobar('y de que el usuario lleve el dominio',
    str_contains($pistas, 'dirección completa'), $pistas);
comprobar('y avisa de que Workspace puede tenerlas bloqueadas',
    str_contains($pistas, 'consola de administración'), $pistas);
$bajar($lanzado);

/* =====================================================================
   Google pidiendo contraseña de aplicación
   ===================================================================== */

titulo('Google pide contraseña de aplicación (534-5.7.9)');

$lanzado = $levantar('auth-534');
[$proceso, $tuberias, $puerto] = $lanzado;

$cliente = new Smtp('127.0.0.1', $puerto, 'ninguna', 'hosting@narino.gov.co', 'x', 10, false);
comprobar('falla', !$cliente->comprobar());
comprobar('el código es 534', $cliente->codigo() === '534', $cliente->codigo());
comprobar('la respuesta de varias líneas se lee entera',
    str_contains($cliente->transcripcion(), 'InvalidSecondFactor'), $cliente->transcripcion());

$pistas = implode(' ', Correo::explicar($cliente->codigo(), $cliente->error()));
comprobar('la explicación menciona la verificación en dos pasos',
    str_contains($pistas, 'verificación en dos pasos'), $pistas);
$bajar($lanzado);

/* =====================================================================
   Remitente no permitido
   ===================================================================== */

titulo('Remitente no permitido (550-5.7.1)');

$lanzado = $levantar('relay-550');
[$proceso, $tuberias, $puerto] = $lanzado;

$cliente = new Smtp('127.0.0.1', $puerto, 'ninguna', 'hosting@narino.gov.co', 'x', 10, false);
$enviado = $cliente->enviar('otro@ajeno.example', 'alguien@narino.gov.co', 'Subject: x', 'cuerpo');
comprobar('no se envía', !$enviado);
comprobar('el código es 550', $cliente->codigo() === '550', $cliente->codigo());

$pistas = implode(' ', Correo::explicar($cliente->codigo(), $cliente->error()));
comprobar('la explicación habla del alias verificado',
    str_contains($pistas, 'alias verificado'), $pistas);
$bajar($lanzado);

/* =====================================================================
   STARTTLS pedido pero no ofrecido
   ===================================================================== */

titulo('Se pide STARTTLS y el servidor no lo ofrece');

$lanzado = $levantar('sin-starttls');
[$proceso, $tuberias, $puerto] = $lanzado;

$cliente = new Smtp('127.0.0.1', $puerto, 'tls', 'hosting@narino.gov.co', 'x', 10, false);
comprobar('no se conecta', !$cliente->comprobar());
comprobar('y lo dice con las dos alternativas de puerto',
    str_contains($cliente->error(), '465') && str_contains($cliente->error(), '587'),
    $cliente->error());
$bajar($lanzado);

/* =====================================================================
   STARTTLS de verdad
   ===================================================================== */

if ($hayTls) {
    titulo('STARTTLS con cifrado real');

    $lanzado = $levantar('starttls');
    [$proceso, $tuberias, $puerto] = $lanzado;

    $cliente = new Smtp('127.0.0.1', $puerto, 'tls', 'hosting@narino.gov.co', 'clave', 10, false);
    comprobar('se conecta, cifra y se autentica', $cliente->comprobar(), $cliente->error());

    $t = $cliente->transcripcion();
    comprobar('el canal quedó cifrado', str_contains($t, '[canal cifrado con TLS]'), $t);
    comprobar('se vuelve a saludar después de cifrar',
        substr_count($t, 'EHLO pruebas.narino.gov.co') === 2,
        'sin el segundo EHLO, AUTH no está anunciado y la autenticación falla');
    $bajar($lanzado);

    titulo('Un certificado que no se puede verificar');

    $lanzado = $levantar('starttls');
    [$proceso, $tuberias, $puerto] = $lanzado;

    // Ahora exigiendo verificación: el certificado es autofirmado, debe fallar.
    $cliente = new Smtp('127.0.0.1', $puerto, 'tls', 'hosting@narino.gov.co', 'clave', 10, true);
    comprobar('con verificación activa, se rechaza', !$cliente->comprobar());
    $pistas = implode(' ', Correo::explicar('', $cliente->error()));
    comprobar('y se explica que se puede desactivar para un servidor interno',
        str_contains($pistas, 'certificado propio'), $pistas);
    $bajar($lanzado);

    titulo('SSL directo, como el puerto 465');

    $lanzado = $levantar('ssl');
    [$proceso, $tuberias, $puerto] = $lanzado;

    $cliente = new Smtp('127.0.0.1', $puerto, 'ssl', 'hosting@narino.gov.co', 'clave', 10, false);
    comprobar('se conecta cifrando desde el primer byte', $cliente->comprobar(), $cliente->error());
    $bajar($lanzado);
}

/* =====================================================================
   Un servidor que no responde
   ===================================================================== */

titulo('Un servidor que se queda callado');

$lanzado = $levantar('mudo');
[$proceso, $tuberias, $puerto] = $lanzado;

$comienzo = microtime(true);
$cliente = new Smtp('127.0.0.1', $puerto, 'ninguna', '', '', 2, false);
comprobar('falla en vez de colgarse', !$cliente->comprobar());
$tardanza = microtime(true) - $comienzo;
comprobar('respeta la espera configurada', $tardanza < 8, sprintf('tardó %.1f s', $tardanza));
comprobar('y dice que no respondió',
    str_contains($cliente->error(), 'no respondió') || str_contains($cliente->error(), 'cerró'),
    $cliente->error());
$bajar($lanzado);

/* =====================================================================
   Puerto cerrado
   ===================================================================== */

titulo('Puerto cerrado');

$cliente = new Smtp('127.0.0.1', $puertoLibre(), 'ninguna', '', '', 3, false);
comprobar('no se conecta', !$cliente->comprobar());
$pistas = implode(' ', Correo::explicar('', $cliente->error()));
comprobar('la explicación sugiere probar el otro puerto',
    str_contains($pistas, '465') || str_contains($pistas, '587'), $pistas);
comprobar('y menciona el cortafuegos de salida',
    str_contains($pistas, 'cortafuegos') || str_contains($pistas, 'cerrada'), $pistas);

/* =====================================================================
   La explicación siempre dice algo útil
   ===================================================================== */

titulo('Nunca se responde con un «no se pudo enviar» a secas');

foreach ([
    ['535', 'Username and Password not accepted'],
    ['534', '5.7.9 Application-specific password required'],
    ['', '5.7.14 Please log in via your web browser'],
    ['550', '5.4.5 Daily user sending limit exceeded'],
    ['421', '4.7.0 Try again later'],
    ['', 'Connection refused'],
    ['', 'un fallo que nadie ha visto nunca'],
] as [$codigo, $mensaje]) {
    $pistas = Correo::explicar($codigo, $mensaje);
    comprobar('«' . mb_substr($mensaje, 0, 42) . '…» tiene explicación', count($pistas) >= 2);
}

$pistas = implode(' ', Correo::explicar('', 'lo que sea'));
comprobar('y todas recuerdan lo del SPF del dominio', str_contains($pistas, 'SPF'), $pistas);

/* =====================================================================
   Varias direcciones: IPv4 primero, IPv6 de reserva
   ===================================================================== */

titulo('Cuando el nombre resuelve a varias direcciones');

// Un nombre que resuelve a IPv4 y a IPv6 a la vez. localhost lo hace en
// cualquier sistema: 127.0.0.1 y ::1.
$lanzado = $levantar('sin-starttls');
[$proceso, $tuberias, $puerto] = $lanzado;

$cliente = new Smtp('localhost', $puerto, 'ninguna', '', '', 5, false);
$abierto = $cliente->comprobar();
$t = $cliente->transcripcion();

comprobar('se conecta aunque una de las direcciones no sirva', $abierto, $cliente->error());
comprobar('la transcripción dice a qué dirección se conectó',
    str_contains($t, 'conectando a tcp://127.0.0.1:') || str_contains($t, 'conectando a tcp://[::1]:'),
    $t);
comprobar('y deja ver el nombre entre paréntesis, para no perderse',
    str_contains($t, '(localhost)'), $t);
$bajar($lanzado);

// El servidor de mentira escucha solo en 127.0.0.1, así que ::1 falla. Si el
// cliente probara IPv6 primero y se rindiera, esto no abriría.
titulo('El primer intento falla y se prueba el siguiente');

$lanzado = $levantar('sin-starttls');
[$proceso, $tuberias, $puerto] = $lanzado;

$cliente = new Smtp('localhost', $puerto, 'ninguna', '', '', 5, false);
comprobar('no se rinde con la primera dirección que falle', $cliente->comprobar(), $cliente->error());
$bajar($lanzado);

/* =====================================================================
   «Network is unreachable»: el fallo que se confunde con un puerto cerrado
   ===================================================================== */

titulo('Network is unreachable');

$pistas = implode(' ', Correo::explicar('', 'stream_socket_client(): Network is unreachable'));
comprobar('se distingue de un puerto cerrado',
    str_contains($pistas, 'no es un puerto cerrado') || str_contains($pistas, 'no encontró ruta'),
    $pistas);
comprobar('se apunta a IPv6 como causa habitual', str_contains($pistas, 'IPv6'), $pistas);
comprobar('se ofrece la casilla de solo IPv4', str_contains($pistas, 'solo IPv4'), $pistas);
comprobar('se remite al diagnóstico de red', str_contains($pistas, 'salida de red'), $pistas);
comprobar('y se recuerda que mail() local es la salida de emergencia',
    str_contains($pistas, 'mail()'), $pistas);

comprobar('«No route to host» se trata igual',
    str_contains(implode(' ', Correo::explicar('', 'No route to host')), 'IPv6'));

/* =====================================================================
   Diagnóstico de red
   ===================================================================== */

titulo('Diagnóstico de la salida de red');

$puerto = $puertoLibre();
$lanzado = $levantar('sin-starttls');
[$proceso, $tuberias, $puertoVivo] = $lanzado;

$red = Correo::diagnosticoDeRed('localhost', [$puertoVivo, $puerto]);

comprobar('resuelve el nombre', $red['ipv4'] !== [] || $red['ipv6'] !== [],
    json_encode($red['ipv4']) . ' / ' . json_encode($red['ipv6']));
comprobar('prueba los puertos que se le piden', count($red['intentos']) >= 2, (string) count($red['intentos']));

$abre = array_values(array_filter($red['intentos'],
    static fn(array $i): bool => $i['ok'] && $i['puerto'] === $puertoVivo));
comprobar('el puerto con servidor detrás sale como abierto', $abre !== []);

$cierra = array_values(array_filter($red['intentos'],
    static fn(array $i): bool => !$i['ok'] && $i['puerto'] === $puerto));
comprobar('el puerto sin nada detrás sale como cerrado', $cierra !== []);
comprobar('con el motivo que dio el sistema', ($cierra[0]['error'] ?? '') !== '');
comprobar('y se mide cuánto tardó', ($abre[0]['ms'] ?? -1) >= 0);

comprobar('el resumen dice algo accionable', mb_strlen($red['resumen']) > 40, $red['resumen']);
comprobar('se informa del estado de mail() en el servidor',
    isset($red['local']['mail()']) && $red['local']['mail()'] !== '');
comprobar('y de sendmail_path', isset($red['local']['sendmail_path']));
$bajar($lanzado);

titulo('Un nombre que no existe');

$red = Correo::diagnosticoDeRed('no-existe-de-verdad.invalid', [587]);
comprobar('no revienta', is_array($red));
comprobar('no encuentra direcciones', $red['ipv4'] === [] && $red['ipv6'] === []);
comprobar('y lo dice en el resumen',
    str_contains($red['resumen'], 'no se pudo resolver'), $red['resumen']);

/* =====================================================================
   El relé de la propia máquina
   -------------------------------------------------------------------------
   Es la salida cuando el proveedor bloquea la salida SMTP: 127.0.0.1 no es
   tráfico saliente. En el servidor de la Gobernación los tres puertos hacia
   internet responden «rechazado» al instante y WordPress sigue enviando desde
   ahí, lo que solo puede ser por el correo local.
   ===================================================================== */

titulo('Servidor de correo de la propia máquina');

$lanzado = $levantar('sin-starttls');
[$proceso, $tuberias, $puertoVivo] = $lanzado;

// El diagnóstico prueba 127.0.0.1 en 25, 587 y 465. Ninguno estará vivo aquí,
// pero la estructura tiene que salir igual.
$red = Correo::diagnosticoDeRed('localhost', [$puertoVivo]);

comprobar('se prueba el relé local', !empty($red['relayLocal']));
comprobar('en los tres puertos de correo',
    count($red['relayLocal'] ?? []) === 3, (string) count($red['relayLocal'] ?? []));
comprobar('cada uno con su resultado',
    isset($red['relayLocal'][0]['ok'], $red['relayLocal'][0]['puerto'], $red['relayLocal'][0]['error']));
$puertosLocales = array_column($red['relayLocal'], 'puerto');
comprobar('los puertos son 25, 587 y 465', $puertosLocales === [25, 587, 465],
    implode(', ', $puertosLocales));
$bajar($lanzado);

// Ahora con un servidor de mentira escuchando de verdad en 127.0.0.1, para
// comprobar que se detecta y que se lee su saludo.
titulo('Con el relé local respondiendo');

$falso = stream_socket_server('tcp://127.0.0.1:0', $n, $t);
$nombreFalso = stream_socket_get_name($falso, false);
$puertoFalso = (int) substr((string) $nombreFalso, strrpos((string) $nombreFalso, ':') + 1);
fclose($falso);

$lanzadoLocal = $levantar('sin-starttls');
[$procesoLocal, $tuberiasLocal, $puertoLocal] = $lanzadoLocal;

// diagnosticoDeRed prueba puertos fijos, así que aquí se comprueba la pieza
// suelta: que un puerto con servidor de correo detrás devuelva su saludo.
$numero = 0;
$texto = '';
$socket = @stream_socket_client('tcp://127.0.0.1:' . $puertoLocal, $numero, $texto, 3);
comprobar('el relé de prueba acepta la conexión', is_resource($socket), $texto);
if (is_resource($socket)) {
    stream_set_timeout($socket, 3);
    $saludo = trim((string) fgets($socket, 512));
    comprobar('y saluda como un servidor de correo',
        str_starts_with($saludo, '220'), $saludo);
    fclose($socket);
}
$bajar($lanzadoLocal);

titulo('Un bloqueo de salida se explica como tal');

$intentos = [
    ['destino' => '74.125.197.109', 'familia' => 'v4', 'puerto' => 587, 'ok' => false, 'ms' => 1, 'error' => 'Connection refused'],
    ['destino' => '2607:f8b0::1',   'familia' => 'v6', 'puerto' => 587, 'ok' => false, 'ms' => 1, 'error' => 'Network is unreachable'],
    ['destino' => '74.125.197.109', 'familia' => 'v4', 'puerto' => 465, 'ok' => false, 'ms' => 1, 'error' => 'Connection refused'],
    ['destino' => '74.125.197.109', 'familia' => 'v4', 'puerto' => 25,  'ok' => false, 'ms' => 1, 'error' => 'Connection refused'],
];
$metodo = new ReflectionMethod(Correo::class, 'resumirRed');
$metodo->setAccessible(true);

$sinLocal = $metodo->invoke(null, $intentos, ['74.125.197.109'], ['2607:f8b0::1'], [
    ['puerto' => 25, 'ok' => false, 'ms' => 1, 'saludo' => '', 'error' => 'Connection refused'],
]);
comprobar('se dice que el bloqueo es a propósito',
    str_contains($sinLocal, 'a propósito') || str_contains($sinLocal, 'cortafuegos'), $sinLocal);
comprobar('y se distingue de «sin ruta» y de «filtrado»',
    str_contains($sinLocal, 'unreachable') && str_contains($sinLocal, 'filtrado'), $sinLocal);

$conLocal = $metodo->invoke(null, $intentos, ['74.125.197.109'], ['2607:f8b0::1'], [
    ['puerto' => 25, 'ok' => true, 'ms' => 1, 'saludo' => '220 servidor listo', 'error' => ''],
]);
comprobar('con relé local, se propone usarlo', str_contains($conLocal, '127.0.0.1:25'), $conLocal);
comprobar('explicando que no es tráfico saliente',
    str_contains($conLocal, 'no es tráfico saliente'), $conLocal);
comprobar('y recordando lo del dominio del remitente',
    str_contains($conLocal, 'remitente'), $conLocal);

echo "\n" . str_repeat('─', 62) . "\n";
printf("%d comprobaciones correctas · %d fallidas\n\n", $ok, count($fallos));
foreach ($fallos as $f) {
    echo "  ✗ $f\n";
}
exit($fallos ? 1 : 0);
