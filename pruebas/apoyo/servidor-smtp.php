<?php
/**
 * Servidor SMTP de mentira, para las pruebas.
 *
 * Atiende una sola conexión y se va. Cada escenario reproduce una respuesta que
 * dan de verdad Google o Microsoft, para poder comprobar que el cliente la
 * entiende y que la pantalla de correo explica el arreglo correcto.
 *
 * Uso:  php pruebas/apoyo/servidor-smtp.php <puerto> <escenario> [certificado.pem]
 *
 * Escenarios:
 *   ok          · saludo, EHLO, AUTH LOGIN correcto, mensaje aceptado
 *   auth-535    · credenciales rechazadas, como una contraseña que no es de aplicación
 *   auth-534    · Google pidiendo contraseña de aplicación
 *   sin-starttls· el servidor no ofrece STARTTLS aunque se le pida
 *   starttls    · STARTTLS de verdad, con certificado autofirmado
 *   ssl         · TLS desde el primer byte, puerto 465
 *   relay-550   · autenticación correcta pero remitente no permitido
 *   mudo        · acepta la conexión y no dice nada, para probar la espera
 */
declare(strict_types=1);

$puerto = (int) ($argv[1] ?? 0);
$escenario = (string) ($argv[2] ?? 'ok');
$certificado = (string) ($argv[3] ?? '');

$contexto = stream_context_create(['socket' => ['backlog' => 8]]);
if ($escenario === 'ssl') {
    $contexto = stream_context_create([
        'socket' => ['backlog' => 8],
        'ssl' => [
            'local_cert'        => $certificado,
            'allow_self_signed' => true,
            'verify_peer'       => false,
        ],
    ]);
}

$protocolo = $escenario === 'ssl' ? 'ssl://' : 'tcp://';
$servidor = @stream_socket_server(
    $protocolo . '127.0.0.1:' . $puerto,
    $numero,
    $texto,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $contexto
);

if (!$servidor) {
    fwrite(STDERR, "No se pudo escuchar en $puerto: $texto\n");
    exit(1);
}

// El que lanza la prueba espera esta línea para saber que ya se puede conectar.
fwrite(STDOUT, "listo\n");
fflush(STDOUT);

$cliente = @stream_socket_accept($servidor, 20);
if (!$cliente) {
    exit(0);
}
stream_set_timeout($cliente, 10);

$decir = static function (string $texto) use ($cliente): void {
    fwrite($cliente, $texto . "\r\n");
};
$oir = static function () use ($cliente): string {
    $linea = fgets($cliente, 2048);
    return $linea === false ? '' : rtrim($linea, "\r\n");
};

if ($escenario === 'mudo') {
    sleep(30);
    exit(0);
}

$decir('220 mentira.example ESMTP listo');

$cifrado = $escenario === 'ssl';
$autenticado = false;

while (($linea = $oir()) !== '') {
    $orden = strtoupper(strtok($linea, ' ') ?: '');

    if ($orden === 'EHLO') {
        $decir('250-mentira.example te saluda');
        $decir('250-SIZE 35882577');
        $decir('250-8BITMIME');
        if ($escenario !== 'sin-starttls' && !$cifrado && $escenario !== 'ssl') {
            $decir('250-STARTTLS');
        }
        if ($cifrado || !in_array($escenario, ['starttls', 'ssl'], true)) {
            $decir('250-AUTH LOGIN PLAIN');
        }
        $decir('250 ENHANCEDSTATUSCODES');
        continue;
    }

    if ($orden === 'HELO') {
        $decir('250 mentira.example');
        continue;
    }

    if ($orden === 'STARTTLS') {
        $decir('220 adelante con TLS');
        stream_context_set_option($cliente, 'ssl', 'local_cert', $certificado);
        stream_context_set_option($cliente, 'ssl', 'allow_self_signed', true);
        stream_context_set_option($cliente, 'ssl', 'verify_peer', false);
        $ok = @stream_socket_enable_crypto(
            $cliente,
            true,
            STREAM_CRYPTO_METHOD_TLS_SERVER,
        );
        if ($ok !== true) {
            exit(0);
        }
        $cifrado = true;
        continue;
    }

    if ($orden === 'AUTH') {
        if ($escenario === 'auth-534') {
            $decir('534-5.7.9 Application-specific password required. Learn more at');
            $decir('534 5.7.9 https://support.google.com/mail/?p=InvalidSecondFactor');
            continue;
        }
        if (str_contains(strtoupper($linea), 'PLAIN ')) {
            $decir($escenario === 'auth-535'
                ? '535 5.7.8 Username and Password not accepted.'
                : '235 2.7.0 Aceptado');
            $autenticado = $escenario !== 'auth-535';
            continue;
        }
        // AUTH LOGIN: dos rondas.
        $decir('334 VXNlcm5hbWU6');
        $oir();
        $decir('334 UGFzc3dvcmQ6');
        $oir();
        if ($escenario === 'auth-535') {
            $decir('535-5.7.8 Username and Password not accepted. For more information, go to');
            $decir('535 5.7.8 https://support.google.com/mail/?p=BadCredentials');
            continue;
        }
        $decir('235 2.7.0 Aceptado');
        $autenticado = true;
        continue;
    }

    if ($orden === 'MAIL') {
        if ($escenario === 'relay-550') {
            $decir('550-5.7.1 No permitido enviar como esa dirección. Consulta');
            $decir('550 5.7.1 https://support.google.com/a/answer/6596');
            continue;
        }
        $decir('250 2.1.0 Remitente aceptado');
        continue;
    }

    if ($orden === 'RCPT') {
        $decir('250 2.1.5 Destinatario aceptado');
        continue;
    }

    if ($orden === 'DATA') {
        $decir('354 Adelante, termina con un punto solo');
        $cuerpo = '';
        while (($fila = $oir()) !== '.') {
            // Deshacer el punto doblado, como hace cualquier servidor real.
            if (str_starts_with($fila, '..')) {
                $fila = substr($fila, 1);
            }
            $cuerpo .= $fila . "\n";
            if (strlen($cuerpo) > 2_000_000) {
                break;
            }
        }
        // El mensaje recibido se deja donde la prueba pueda leerlo.
        $destino = sys_get_temp_dir() . '/smtp-recibido-' . $puerto . '.txt';
        @file_put_contents($destino, $cuerpo);
        $decir('250 2.0.0 Aceptado para entrega');
        continue;
    }

    if ($orden === 'QUIT') {
        $decir('221 2.0.0 Hasta luego');
        break;
    }

    $decir('502 5.5.2 No entiendo esa orden');
}

fclose($cliente);
fclose($servidor);
