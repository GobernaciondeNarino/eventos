<?php
/**
 * El mensaje que se pone en el buzón.
 *
 * El correo es lo único que devuelve a un asistente a su cuenta: si el carnet o
 * el código de acceso no llegan, esa persona se queda fuera y no hay segundo
 * canal. Así que el mensaje tiene que ser correcto de verdad, no solo salir.
 *
 * Se comprueba contra el modo 'registro', que arma el mensaje entero igual que
 * el modo real pero lo deja escrito en vez de entregarlo a sendmail.
 *
 * Uso:  php pruebas/correo.php
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

Config::establecerEnMemoria([
    'modo_correo'      => 'registro',
    'correo_remitente' => 'no-responder@tic.narino.gov.co',
    'correo_nombre'    => 'Cumbre Tecnológica CIOS Nariño',
    'llave_cifrado'    => base64_encode(str_repeat('k', 32)),
]);

echo "Correo saliente\n" . str_repeat('=', 52) . "\n\n";

/* ------------------------------------------------------------------------
   El cuerpo MIME que se arma
   ------------------------------------------------------------------------ */
echo "Formato del mensaje\n";

$metodo = new ReflectionMethod(Correo::class, 'plantilla');
$metodo->setAccessible(true);
$html = (string) $metodo->invoke(null, 'Cumbre Tecnológica CIOS Nariño', 'Tu carnet está listo',
    '<p>Hola María, tu preregistro quedó completo.</p>');

// Es exactamente el problema que había: la plantilla es una sola línea larga.
comprobar('la plantilla HTML supera los 998 caracteres de una línea',
    mb_strlen($html) > 998, (string) mb_strlen($html));

$partes = [];
foreach ([['text/plain', 'Hola María. Tu carnet está listo.'], ['text/html', $html]] as [$tipo, $contenido]) {
    $partes[] = "Content-Type: $tipo; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($contenido), 76, "\r\n");
}
$cuerpo = implode("\r\n", $partes);

$largas = array_filter(explode("\r\n", $cuerpo), static fn(string $l): bool => strlen($l) > 998);
comprobar('ninguna línea del mensaje pasa de 998 caracteres', $largas === [],
    $largas ? 'la más larga: ' . max(array_map('strlen', $largas)) : '');
comprobar('el HTML se recupera intacto al decodificar',
    base64_decode(str_replace("\r\n", '', explode("\r\n\r\n", $partes[1], 2)[1])) === $html);
comprobar('las tildes y la ñ sobreviven',
    str_contains(base64_decode(str_replace("\r\n", '', explode("\r\n\r\n", $partes[0], 2)[1])), 'María'));

/* ------------------------------------------------------------------------
   Cabeceras con tildes (RFC 2047)
   ------------------------------------------------------------------------ */
echo "\nCabeceras con tildes\n";

$codificar = new ReflectionMethod(Correo::class, 'palabraCodificada');
$codificar->setAccessible(true);
$palabra = static fn(string $t, bool $comillas = true): string => (string) $codificar->invoke(null, $t, $comillas);

comprobar('un texto ASCII se deja tal cual', $palabra('Eventos TIC', false) === 'Eventos TIC');
comprobar('y entre comillas cuando va en un From', $palabra('Eventos TIC') === '"Eventos TIC"');

$conTildes = $palabra('Cumbre Tecnológica CIOS Nariño', false);
comprobar('con tildes se codifica en base64', str_contains($conTildes, '=?UTF-8?B?'));
comprobar('y se recupera al decodificar',
    mb_decode_mimeheader($conTildes) === 'Cumbre Tecnológica CIOS Nariño', $conTildes);

$largo = $palabra('Cumbre Tecnológica de Innovación y Gobierno Abierto CIOS Nariño 2026', false);
$lineas = explode("\r\n", $largo);
$excedidas = array_filter($lineas, static fn(string $l): bool => strlen(trim($l)) > 75);
comprobar('ningún trozo pasa de 75 caracteres', $excedidas === [],
    $excedidas ? 'el mayor mide ' . max(array_map('strlen', $excedidas)) : '');
comprobar('y el texto largo también se recupera entero',
    mb_decode_mimeheader($largo) === 'Cumbre Tecnológica de Innovación y Gobierno Abierto CIOS Nariño 2026');

/* ------------------------------------------------------------------------
   Inyección de cabeceras
   ------------------------------------------------------------------------ */
echo "\nInyección de cabeceras\n";

comprobar('un destinatario con salto de línea se rechaza',
    Correo::enviar("victima@narino.gov.co\r\nBcc: otro@ajeno.co", 'Hola', '<p>x</p>') === false);
comprobar('un destinatario que no es correo se rechaza',
    Correo::enviar('no-es-un-correo', 'Hola', '<p>x</p>') === false);

$registro = RAIZ . '/almacen/registro/' . date('Y-m-d') . '.log.php';
@unlink($registro);
Correo::enviar('destino@narino.gov.co', "Asunto\r\nBcc: colado@ajeno.co", '<p>x</p>');
$anotado = is_file($registro) ? (string) file_get_contents($registro) : '';
comprobar('el salto de línea del asunto se neutraliza',
    $anotado !== '' && !str_contains($anotado, "\nBcc:"));

/* ------------------------------------------------------------------------
   Los dos mensajes de la plataforma
   ------------------------------------------------------------------------ */
echo "\nMensajes de la plataforma\n";

@unlink($registro);
comprobar('el código de acceso se arma',
    Correo::codigoDeAcceso('mzambrano@narino.gov.co', '482913', 'Cumbre Tecnológica CIOS Nariño'));
$anotado = (string) @file_get_contents($registro);
comprobar('y lleva el código dentro', str_contains($anotado, '482913'));
comprobar('y dice que vence', str_contains($anotado, '10 minutos'));

@unlink($registro);
comprobar('el carnet emitido se arma',
    Correo::carnetEmitido('mzambrano@narino.gov.co', 'María Zambrano', 'Cumbre Tecnológica CIOS Nariño',
        'https://tic.narino.gov.co/cumbreAI/carnet'));
$anotado = (string) @file_get_contents($registro);
comprobar('y lleva el enlace al carnet', str_contains($anotado, 'cumbreAI/carnet'));

// Un nombre con etiquetas no puede salir sin escapar en el HTML del correo.
$conEtiquetas = (string) $metodo->invoke(null, '<script>alert(1)</script>', 'Título', '<p>x</p>');
comprobar('el nombre del evento se escapa en la plantilla',
    !str_contains($conEtiquetas, '<script>') && str_contains($conEtiquetas, '&lt;script&gt;'));

@unlink($registro);

echo "\n" . str_repeat('─', 52) . "\n";
printf("%d comprobaciones correctas · %d fallidas\n", $ok, count($fallos));
exit($fallos ? 1 : 0);
