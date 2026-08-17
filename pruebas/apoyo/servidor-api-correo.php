<?php
/**
 * Proveedor de correo por API, de mentira.
 *
 * Habla HTTP en un puerto local y responde como responden de verdad Brevo,
 * SendGrid y Resend. Sirve para comprobar que la petición que se les manda
 * tiene la forma que esperan —cada uno la quiere distinta— sin gastar cupo de
 * nadie ni necesitar una cuenta.
 *
 * Guarda la petición recibida en un archivo para que la prueba pueda mirarla.
 *
 * Uso:  php pruebas/apoyo/servidor-api-correo.php <puerto> <escenario>
 *
 * Escenarios:
 *   ok-201   · aceptado, como Brevo y Resend
 *   ok-202   · aceptado, como SendGrid
 *   401      · clave inválida
 *   400-from · remitente no autorizado
 *   422      · dominio sin verificar
 *   429      · cupo agotado
 *   500      · fallo del proveedor
 *   mudo     · acepta y no responde, para probar la espera
 */
declare(strict_types=1);

$puerto = (int) ($argv[1] ?? 0);
$escenario = (string) ($argv[2] ?? 'ok-201');

$servidor = @stream_socket_server('tcp://127.0.0.1:' . $puerto, $numero, $texto);
if (!$servidor) {
    fwrite(STDERR, "No se pudo escuchar en $puerto: $texto\n");
    exit(1);
}

fwrite(STDOUT, "listo\n");
fflush(STDOUT);

$cliente = @stream_socket_accept($servidor, 20);
if (!$cliente) {
    exit(0);
}
stream_set_timeout($cliente, 10);

// Cabeceras hasta la línea en blanco.
$cabeceras = '';
while (($linea = fgets($cliente, 4096)) !== false) {
    $cabeceras .= $linea;
    if (rtrim($linea, "\r\n") === '') {
        break;
    }
}

// Cuerpo, según Content-Length.
$largo = 0;
if (preg_match('/^Content-Length:\s*(\d+)/mi', $cabeceras, $m)) {
    $largo = (int) $m[1];
}
$cuerpo = '';
while ($largo > 0 && strlen($cuerpo) < $largo) {
    $trozo = fread($cliente, min(8192, $largo - strlen($cuerpo)));
    if ($trozo === false || $trozo === '') {
        break;
    }
    $cuerpo .= $trozo;
}

@file_put_contents(
    sys_get_temp_dir() . '/api-correo-' . $puerto . '.txt',
    $cabeceras . "\n" . $cuerpo
);

if ($escenario === 'mudo') {
    sleep(30);
    exit(0);
}

[$estado, $json] = match ($escenario) {
    'ok-202'   => [202, ''],
    '401'      => [401, '{"message":"Key not found","code":"unauthorized"}'],
    '400-from' => [400, '{"message":"Sender email is not valid: sender not authorized","code":"invalid_parameter"}'],
    '422'      => [422, '{"message":"The domain narino.gov.co is not verified","name":"validation_error"}'],
    '429'      => [429, '{"message":"You have exceeded your daily quota"}'],
    '500'      => [500, '{"message":"Internal server error"}'],
    default    => [201, '{"messageId":"<202608170000.1@mentira>"}'],
};

$respuesta = "HTTP/1.1 $estado " . ($estado < 300 ? 'OK' : 'Error') . "\r\n"
    . "Content-Type: application/json\r\n"
    . 'Content-Length: ' . strlen($json) . "\r\n"
    . "Connection: close\r\n\r\n"
    . $json;

fwrite($cliente, $respuesta);
fclose($cliente);
fclose($servidor);
