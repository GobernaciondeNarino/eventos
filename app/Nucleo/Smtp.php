<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Cliente SMTP propio.
 *
 * Sin biblioteca externa, como el resto de la plataforma: son unas trescientas
 * líneas y evita arrastrar Composer a un servidor donde nadie va a poder
 * ejecutar `composer install`.
 *
 * Existe porque el correo del dominio está en Google Workspace. La función
 * mail() de PHP entrega al servidor de correo local del servidor web, que en
 * este despliegue no es el que gestiona narino.gov.co: los mensajes salen sin
 * SPF ni DKIM válidos para el dominio y terminan en no deseado, cuando no
 * rechazados de plano. Hablando SMTP directamente con Google, el mensaje sale
 * autenticado como la cuenta institucional y llega.
 *
 * Guarda la conversación completa con el servidor. Es lo que hace depurable el
 * correo: cuando algo falla, el código y el texto que devuelve el servidor
 * dicen exactamente qué pasa, y esta clase los enseña en pantalla en vez de
 * dejar un «no se pudo enviar». Las líneas de autenticación se tachan antes de
 * guardarse: llevan la contraseña en base64, que es texto plano con un paso
 * más.
 */
final class Smtp
{
    /** @var resource|null */
    private $conexion = null;

    /** @var array<int, array{lado: string, texto: string}> */
    private array $conversacion = [];

    private string $error = '';
    private string $codigo = '';

    /** Lo que el servidor dijo saber hacer, tras EHLO. */
    private array $capacidades = [];

    public function __construct(
        private string $host,
        private int $puerto = 587,
        private string $seguridad = 'tls',      // 'tls' (STARTTLS) | 'ssl' (directo) | 'ninguna'
        private string $usuario = '',
        private string $clave = '',
        private int $espera = 15,
        private bool $verificarCertificado = true,
    ) {
    }

    /* =====================================================================
       Lo que se usa desde fuera
       ===================================================================== */

    /**
     * Entrega un mensaje ya armado.
     *
     * $cabeceras y $cuerpo son los mismos que se le pasarían a mail(): esta
     * clase no arma correos, solo los entrega.
     */
    public function enviar(string $de, string $para, string $cabeceras, string $cuerpo): bool
    {
        try {
            if (!$this->abrir()) {
                return false;
            }

            if (!$this->orden('MAIL FROM:<' . $de . '>', [250])) {
                return false;
            }
            if (!$this->orden('RCPT TO:<' . $para . '>', [250, 251])) {
                return false;
            }
            if (!$this->orden('DATA', [354])) {
                return false;
            }

            // El punto solo en una línea termina el mensaje, así que una línea
            // del cuerpo que empiece por punto tiene que ir doblada (RFC 5321,
            // §4.5.2). Sin esto, un mensaje se corta por la mitad y el resto se
            // interpreta como órdenes SMTP.
            $mensaje = $cabeceras . "\r\n\r\n" . $cuerpo;
            $mensaje = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $mensaje)) ?? $mensaje;
            $mensaje = str_replace("\n", "\r\n", $mensaje);

            // El final es exactamente CRLF, punto, CRLF. Sin el salto de línea
            // del final, el servidor se queda esperando a que la línea del punto
            // termine, y la entrega muere en un tiempo de espera agotado con el
            // mensaje ya subido entero.
            $this->escribir($mensaje . "\r\n.\r\n");
            $this->anotar('cliente', '[mensaje: ' . strlen($mensaje) . ' bytes] .');

            if (!$this->leerEsperando([250])) {
                return false;
            }

            $this->orden('QUIT', [221]);
            return true;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        } finally {
            $this->cerrar();
        }
    }

    /** Solo saluda y se autentica, sin enviar nada. Para el botón de probar. */
    public function comprobar(): bool
    {
        try {
            $ok = $this->abrir();
            if ($ok) {
                $this->orden('QUIT', [221]);
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        } finally {
            $this->cerrar();
        }
    }

    public function error(): string
    {
        return $this->error;
    }

    /** El código SMTP del fallo: 535, 534, 421… Vacío si no llegó a haber uno. */
    public function codigo(): string
    {
        return $this->codigo;
    }

    /** @return array<int, array{lado: string, texto: string}> */
    public function conversacion(): array
    {
        return $this->conversacion;
    }

    /** La conversación en texto, lista para copiar y pegar. */
    public function transcripcion(): string
    {
        $lineas = [];
        foreach ($this->conversacion as $paso) {
            $lineas[] = ($paso['lado'] === 'cliente' ? '→ ' : '← ') . $paso['texto'];
        }
        return implode("\n", $lineas);
    }

    /* =====================================================================
       La conversación
       ===================================================================== */

    private function abrir(): bool
    {
        $destino = ($this->seguridad === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->puerto;

        $contexto = stream_context_create(['ssl' => [
            'verify_peer'       => $this->verificarCertificado,
            'verify_peer_name'  => $this->verificarCertificado,
            'allow_self_signed' => !$this->verificarCertificado,
            'SNI_enabled'       => true,
            'peer_name'         => $this->host,
        ]]);

        $numero = 0;
        $texto = '';
        $this->anotar('cliente', 'conectando a ' . $destino);

        $conexion = @stream_socket_client(
            $destino,
            $numero,
            $texto,
            $this->espera,
            STREAM_CLIENT_CONNECT,
            $contexto
        );

        if (!is_resource($conexion)) {
            $this->error = $texto !== '' ? $texto : 'No se pudo abrir la conexión.';
            $this->anotar('servidor', 'ERROR DE CONEXIÓN: ' . $this->error);
            return false;
        }

        $this->conexion = $conexion;
        stream_set_timeout($this->conexion, $this->espera);

        // Saludo del servidor.
        if (!$this->leerEsperando([220])) {
            return false;
        }

        $nombre = $this->nombreDelCliente();
        if (!$this->ehlo($nombre)) {
            return false;
        }

        if ($this->seguridad === 'tls') {
            if (!isset($this->capacidades['STARTTLS'])) {
                $this->error = 'El servidor no ofrece STARTTLS en este puerto. '
                    . 'Prueba el puerto 465 con «SSL directo», o el 587 con STARTTLS.';
                $this->anotar('servidor', 'ERROR: ' . $this->error);
                return false;
            }
            if (!$this->orden('STARTTLS', [220])) {
                return false;
            }

            $metodo = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $metodo |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $metodo |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }

            if (@stream_socket_enable_crypto($this->conexion, true, $metodo) !== true) {
                $this->error = 'El cifrado TLS no se pudo establecer. Suele ser un certificado '
                    . 'que el servidor no puede verificar, o una versión de TLS demasiado vieja.';
                $this->anotar('servidor', 'ERROR TLS: ' . $this->error);
                return false;
            }
            $this->anotar('cliente', '[canal cifrado con TLS]');

            // Después de STARTTLS hay que volver a saludar: las capacidades de
            // antes del cifrado no valen, y AUTH solo suele ofrecerse ya cifrado.
            if (!$this->ehlo($nombre)) {
                return false;
            }
        }

        if ($this->usuario !== '') {
            return $this->autenticar();
        }
        return true;
    }

    private function ehlo(string $nombre): bool
    {
        if (!$this->orden('EHLO ' . $nombre, [250])) {
            // Servidores viejos que no entienden EHLO. Sin capacidades no hay
            // STARTTLS ni AUTH, pero para un relé interno sin autenticación vale.
            $this->error = '';
            $this->codigo = '';
            return $this->orden('HELO ' . $nombre, [250]);
        }
        return true;
    }

    private function autenticar(): bool
    {
        $metodos = strtoupper((string) ($this->capacidades['AUTH'] ?? ''));

        if (str_contains($metodos, 'LOGIN')) {
            if (!$this->orden('AUTH LOGIN', [334])) {
                return false;
            }
            if (!$this->orden(base64_encode($this->usuario), [334], '[usuario en base64]')) {
                return false;
            }
            return $this->orden(base64_encode($this->clave), [235], '[contraseña en base64]');
        }

        if (str_contains($metodos, 'PLAIN') || $metodos === '') {
            $carga = base64_encode("\0" . $this->usuario . "\0" . $this->clave);
            return $this->orden('AUTH PLAIN ' . $carga, [235], 'AUTH PLAIN [credenciales en base64]');
        }

        $this->error = 'El servidor no ofrece ningún método de autenticación compatible. '
            . 'Ofrece: ' . ($metodos !== '' ? $metodos : 'ninguno')
            . '. Si el servidor no pide usuario, deja el campo de usuario vacío.';
        $this->anotar('servidor', 'ERROR: ' . $this->error);
        return false;
    }

    /**
     * Manda una orden y comprueba el código de respuesta.
     *
     * $comoSeAnota permite que la transcripción enseñe «[contraseña en base64]»
     * en lugar de la contraseña.
     */
    private function orden(string $texto, array $esperados, string $comoSeAnota = ''): bool
    {
        $this->escribir($texto . "\r\n");
        $this->anotar('cliente', $comoSeAnota !== '' ? $comoSeAnota : $texto);
        return $this->leerEsperando($esperados);
    }

    private function escribir(string $texto): void
    {
        if (!is_resource($this->conexion)) {
            throw new \RuntimeException('La conexión con el servidor de correo se cerró.');
        }
        if (@fwrite($this->conexion, $texto) === false) {
            throw new \RuntimeException('No se pudo escribir en la conexión con el servidor de correo.');
        }
    }

    /** @param array<int, int> $esperados */
    private function leerEsperando(array $esperados): bool
    {
        $respuesta = $this->leer();
        if ($respuesta === null) {
            return false;
        }

        [$codigo, $texto] = $respuesta;
        if (in_array($codigo, $esperados, true)) {
            return true;
        }

        $this->codigo = (string) $codigo;
        $this->error = $codigo . ' ' . $texto;
        return false;
    }

    /** @return array{0: int, 1: string}|null */
    private function leer(): ?array
    {
        if (!is_resource($this->conexion)) {
            $this->error = 'La conexión con el servidor de correo se cerró.';
            return null;
        }

        $lineas = [];
        $codigo = 0;

        // Las respuestas pueden ocupar varias líneas: «250-ALGO» continúa y
        // «250 ALGO» cierra. Leer solo la primera deja el resto en el búfer y
        // descuadra todas las órdenes siguientes.
        do {
            $linea = @fgets($this->conexion, 1024);

            if ($linea === false) {
                $estado = stream_get_meta_data($this->conexion);
                $this->error = !empty($estado['timed_out'])
                    ? 'El servidor no respondió en ' . $this->espera . ' segundos.'
                    : 'El servidor cerró la conexión sin responder.';
                $this->anotar('servidor', 'ERROR: ' . $this->error);
                return null;
            }

            $linea = rtrim($linea, "\r\n");
            $lineas[] = $linea;
            $this->anotar('servidor', $linea);

            $codigo = (int) substr($linea, 0, 3);
            $continua = isset($linea[3]) && $linea[3] === '-';
        } while ($continua);

        // Capacidades anunciadas tras EHLO: «250-STARTTLS», «250-AUTH LOGIN PLAIN».
        $textos = [];
        foreach ($lineas as $linea) {
            $resto = trim(substr($linea, 4));
            if ($resto === '') {
                continue;
            }
            $textos[] = $resto;
            $partes = explode(' ', $resto, 2);
            $this->capacidades[strtoupper($partes[0])] = $partes[1] ?? '';
        }

        // El código extendido —«5.7.8»— viene repetido en cada línea de la
        // respuesta. Repetirlo en el mensaje solo estorba al leerlo.
        for ($i = 1, $n = count($textos); $i < $n; $i++) {
            $textos[$i] = (string) preg_replace('/^\d\.\d+\.\d+\s+/', '', $textos[$i]);
        }

        // El texto son TODAS las líneas, no la última.
        //
        // Google parte sus rechazos en dos: la primera dice qué pasó
        // —«Username and Password not accepted»— y la segunda es solo el enlace
        // a su página de ayuda. Quedándose con la última, al administrador le
        // salía en pantalla «535 5.7.8 https://support.google.com/…» y ninguna
        // pista de qué había que arreglar.
        return [$codigo, implode(' ', $textos)];
    }

    private function anotar(string $lado, string $texto): void
    {
        // Un servidor que se ponga a hablar sin parar no puede llenar la memoria.
        if (count($this->conversacion) < 200) {
            $this->conversacion[] = ['lado' => $lado, 'texto' => mb_substr($texto, 0, 500)];
        }
    }

    private function cerrar(): void
    {
        if (is_resource($this->conexion)) {
            @fclose($this->conexion);
        }
        $this->conexion = null;
    }

    /**
     * El nombre con el que la aplicación se presenta.
     *
     * Google no lo mira, pero otros servidores rechazan un EHLO cuyo argumento
     * no parezca un nombre de máquina.
     */
    private function nombreDelCliente(): string
    {
        $host = (string) ($_SERVER['SERVER_NAME'] ?? '');
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+$/', $host) || !str_contains($host, '.')) {
            $host = 'localhost';
        }
        return $host;
    }
}
