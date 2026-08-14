<?php
/**
 * El segundo factor, contra los vectores del RFC 6238.
 *
 * Si esto estuviera mal, ningún administrador podría entrar: el segundo factor
 * es obligatorio para ese rol y no hay forma de saltárselo desde el navegador.
 * Los vectores del apéndice B del RFC son la única comprobación que no depende
 * de la propia implementación.
 *
 * Uso:  php pruebas/totp.php
 */
declare(strict_types=1);

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', 'pruebas');

require RAIZ . '/app/Nucleo/Totp.php';

use App\Nucleo\Totp;

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

echo "Segundo factor (TOTP)\n" . str_repeat('=', 52) . "\n\n";

/* ------------------------------------------------------------------------
   Vectores del RFC 6238, apéndice B
   ------------------------------------------------------------------------
   El secreto es la cadena ASCII "12345678901234567890". El RFC publica los
   códigos de ocho dígitos; la plataforma usa seis, que son los seis últimos.
   ------------------------------------------------------------------------ */
echo "Vectores del RFC 6238\n";

$codificar = new ReflectionMethod(Totp::class, 'base32Codificar');
$codificar->setAccessible(true);
$secreto = (string) $codificar->invoke(null, '12345678901234567890');

comprobar('el secreto de prueba se codifica en base32',
    $secreto === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secreto);

foreach ([
    [59,          '287082'],
    [1111111109,  '081804'],
    [1111111111,  '050471'],
    [1234567890,  '005924'],
    [2000000000,  '279037'],
    [20000000000, '353130'],
] as [$momento, $esperado]) {
    $obtenido = Totp::codigoActual($secreto, $momento);
    comprobar(sprintf('T=%-12d → %s', $momento, $esperado), $obtenido === $esperado, $obtenido);
}

/* ------------------------------------------------------------------------
   base32 de ida y vuelta
   ------------------------------------------------------------------------ */
echo "\nCodificación base32\n";

$decodificar = new ReflectionMethod(Totp::class, 'base32Decodificar');
$decodificar->setAccessible(true);

$idaYVuelta = true;
for ($i = 1; $i <= 40; $i++) {
    $crudo = random_bytes($i);
    if ((string) $decodificar->invoke(null, (string) $codificar->invoke(null, $crudo)) !== $crudo) {
        // Con longitudes que no son múltiplo de 5 el base32 lleva relleno; lo
        // que importa es que los bytes originales estén al principio.
        $vuelta = (string) $decodificar->invoke(null, (string) $codificar->invoke(null, $crudo));
        if (!str_starts_with($vuelta, $crudo)) {
            $idaYVuelta = false;
            break;
        }
    }
}
comprobar('ida y vuelta con longitudes de 1 a 40 bytes', $idaYVuelta);

comprobar('un secreto con espacios se lee igual',
    Totp::codigoActual(Totp::formatear($secreto), 59) === '287082');
comprobar('un secreto en minúsculas también',
    Totp::codigoActual(strtolower($secreto), 59) === '287082');
// Un secreto sin una sola letra del alfabeto base32 no da código.
comprobar('un secreto sin nada aprovechable devuelve vacío',
    Totp::codigoActual('¡¡¡ !!! ¿?¿', 59) === '');
// Y uno con basura mezclada no revienta: se queda con lo que sí es base32 y
// simplemente no cuadrará con ninguna aplicación, que es lo correcto.
comprobar('un secreto con basura mezclada no revienta',
    preg_match('/^\d{6}$/', Totp::codigoActual('¡¡¡no-es-base32!!!', 59)) === 1);

/* ------------------------------------------------------------------------
   Verificación
   ------------------------------------------------------------------------ */
echo "\nVerificación\n";

$ahora = Totp::codigoActual($secreto);
comprobar('el código de ahora vale', Totp::verificar($secreto, $ahora));
comprobar('con espacios de por medio también', Totp::verificar($secreto, chunk_split($ahora, 3, ' ')));

$reflexionCodigo = new ReflectionMethod(Totp::class, 'codigo');
$reflexionCodigo->setAccessible(true);
$contador = intdiv(time(), 30);

comprobar('el del intervalo anterior vale (reloj adelantado)',
    Totp::verificar($secreto, (string) $reflexionCodigo->invoke(null, $secreto, $contador - 1)));
comprobar('el del siguiente también (reloj atrasado)',
    Totp::verificar($secreto, (string) $reflexionCodigo->invoke(null, $secreto, $contador + 1)));
comprobar('el de hace tres intervalos ya no',
    !Totp::verificar($secreto, (string) $reflexionCodigo->invoke(null, $secreto, $contador - 3)));

comprobar('un código de cinco dígitos se rechaza', !Totp::verificar($secreto, '12345'));
comprobar('un código vacío se rechaza', !Totp::verificar($secreto, ''));
comprobar('letras se rechazan', !Totp::verificar($secreto, 'abcdef'));
comprobar('el código de otro secreto no vale',
    !Totp::verificar(Totp::generarSecreto(), $ahora));

/* ------------------------------------------------------------------------
   El URI que va en el QR de alta
   ------------------------------------------------------------------------ */
echo "\nURI de alta\n";

$uri = Totp::uri($secreto, 'aerazo@narino.gov.co', 'Cumbre Tecnológica CIOS Nariño');
comprobar('empieza por otpauth://totp/', str_starts_with($uri, 'otpauth://totp/'));
comprobar('lleva el secreto sin transformar', str_contains($uri, 'secret=' . $secreto));
comprobar('lleva el emisor escapado', str_contains($uri, 'issuer=Cumbre%20Tecnol%C3%B3gica%20CIOS%20Nari%C3%B1o'));
comprobar('declara algoritmo, dígitos y periodo',
    str_contains($uri, 'algorithm=SHA1') && str_contains($uri, 'digits=6') && str_contains($uri, 'period=30'));
comprobar('la cuenta va escapada', str_contains($uri, rawurlencode('aerazo@narino.gov.co')));

$secretoNuevo = Totp::generarSecreto();
comprobar('un secreto nuevo tiene 32 caracteres', strlen($secretoNuevo) === 32, (string) strlen($secretoNuevo));
comprobar('y solo usa el alfabeto base32', (bool) preg_match('/^[A-Z2-7]+$/', $secretoNuevo));

echo "\n" . str_repeat('─', 52) . "\n";
printf("%d comprobaciones correctas · %d fallidas\n", $ok, count($fallos));
exit($fallos ? 1 : 0);
