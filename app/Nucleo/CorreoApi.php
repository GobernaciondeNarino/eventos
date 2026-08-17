<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Envío de correo por API HTTPS.
 *
 * Existe por el cortafuegos. En este despliegue hay una regla que rechaza el
 * tráfico SMTP saliente del usuario con el que corre PHP —la regla anti-spam de
 * Plesk, ampliada a los puertos 465 y 587—, y por eso `Connection refused`
 * aparece al instante mientras root sí conecta. Levantar esa regla es lo
 * correcto y son dos líneas, pero en una entidad pública puede tardar semanas
 * en aprobarse.
 *
 * Estos proveedores entregan por **HTTPS al puerto 443**, que ninguna regla de
 * correo bloquea porque es el mismo puerto por el que el servidor sirve la web.
 * No hay que tocar el cortafuegos ni pedirle nada a nadie: hace falta una clave
 * de API y el dominio verificado en el proveedor.
 *
 * Tres proveedores, elegidos porque la petición es un solo POST con una clave en
 * una cabecera —sin OAuth, sin refresh tokens, sin biblioteca—:
 *
 *   brevo     · api.brevo.com/v3/smtp/email      · cabecera «api-key»
 *   sendgrid  · api.sendgrid.com/v3/mail/send    · «Authorization: Bearer»
 *   resend    · api.resend.com/emails            · «Authorization: Bearer»
 *
 * La API de Gmail también saldría por 443, pero exige OAuth2 con proyecto en
 * Google Cloud, client secret y refresh token que caduca: mucho más aparato
 * para el mismo resultado, y con más piezas que se rompen solas.
 *
 * Igual que el cliente SMTP, esta clase guarda la conversación —petición y
 * respuesta— para que la pantalla pueda enseñar qué pasó. La clave se tacha.
 */
final class CorreoApi
{
    public const PROVEEDORES = [
        'brevo' => [
            'nombre'   => 'Brevo',
            'url'      => 'https://api.brevo.com/v3/smtp/email',
            'panel'    => 'app.brevo.com → SMTP & API → API Keys',
            'gratis'   => '300 correos al día',
        ],
        'sendgrid' => [
            'nombre'   => 'SendGrid',
            'url'      => 'https://api.sendgrid.com/v3/mail/send',
            'panel'    => 'app.sendgrid.com → Settings → API Keys',
            'gratis'   => '100 correos al día',
        ],
        'resend' => [
            'nombre'   => 'Resend',
            'url'      => 'https://api.resend.com/emails',
            'panel'    => 'resend.com/api-keys',
            'gratis'   => '100 correos al día, 3.000 al mes',
        ],
    ];

    /** @var array<int, array{lado: string, texto: string}> */
    private array $conversacion = [];

    private string $error = '';
    private string $codigo = '';

    /**
     * @param string $urlBase Solo para las pruebas: permite apuntar a un
     *                        servidor local en vez de al proveedor de verdad.
     */
    public function __construct(
        private string $proveedor,
        private string $clave,
        private int $espera = 15,
        private string $urlBase = '',
    ) {
    }

    public function error(): string
    {
        return $this->error;
    }

    /** El código HTTP de la respuesta. Vacío si no se llegó a recibir una. */
    public function codigo(): string
    {
        return $this->codigo;
    }

    public function transcripcion(): string
    {
        $lineas = [];
        foreach ($this->conversacion as $paso) {
            $lineas[] = ($paso['lado'] === 'cliente' ? '→ ' : '← ') . $paso['texto'];
        }
        return implode("\n", $lineas);
    }

    /** ¿Está soportado este proveedor? */
    public static function conocido(string $proveedor): bool
    {
        return isset(self::PROVEEDORES[$proveedor]);
    }

    /**
     * Manda un mensaje.
     *
     * @param array{correo: string, nombre: string} $de
     */
    public function enviar(array $de, string $para, string $asunto, string $html, string $texto): bool
    {
        $this->error = '';
        $this->codigo = '';

        if (!self::conocido($this->proveedor)) {
            $this->error = 'Proveedor de API desconocido: «' . $this->proveedor . '».';
            return false;
        }
        if ($this->clave === '') {
            $this->error = 'Falta la clave de API del proveedor.';
            return false;
        }
        if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
            $this->error = 'Este PHP no tiene cURL ni allow_url_fopen, así que no puede hacer '
                . 'peticiones HTTPS. Actívale una de las dos.';
            return false;
        }

        $url = $this->urlBase !== '' ? $this->urlBase : self::PROVEEDORES[$this->proveedor]['url'];
        [$cuerpo, $cabeceras] = $this->peticion($de, $para, $asunto, $html, $texto);

        $this->anotar('cliente', 'POST ' . $url);
        foreach ($cabeceras as $cabecera) {
            // La clave nunca entra en la transcripción, que se enseña en pantalla
            // y se puede pegar en un correo sin pensarlo.
            $this->anotar('cliente', preg_replace(
                '/^(api-key|Authorization):.*$/i',
                '$1: [clave tachada]',
                $cabecera
            ) ?? $cabecera);
        }
        $this->anotar('cliente', '[cuerpo JSON: ' . strlen($cuerpo) . ' bytes]');

        [$estado, $respuesta, $fallo] = $this->hablar($url, $cuerpo, $cabeceras);

        if ($fallo !== '') {
            $this->error = $fallo;
            $this->anotar('servidor', 'ERROR DE CONEXIÓN: ' . $fallo);
            return false;
        }

        $this->codigo = (string) $estado;
        $this->anotar('servidor', 'HTTP ' . $estado);
        if ($respuesta !== '') {
            $this->anotar('servidor', mb_substr($respuesta, 0, 400));
        }

        // Los tres proveedores responden 2xx cuando aceptan el mensaje: 201 en
        // Brevo y Resend, 202 en SendGrid.
        if ($estado >= 200 && $estado < 300) {
            return true;
        }

        $this->error = 'HTTP ' . $estado . ($respuesta !== '' ? ' · ' . mb_substr($respuesta, 0, 300) : '');
        return false;
    }

    /**
     * El cuerpo y las cabeceras de cada proveedor.
     *
     * @param array{correo: string, nombre: string} $de
     * @return array{0: string, 1: array<int, string>}
     */
    private function peticion(array $de, string $para, string $asunto, string $html, string $texto): array
    {
        $json = static fn(array $datos): string => (string) json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return match ($this->proveedor) {
            'brevo' => [
                $json([
                    'sender'      => ['email' => $de['correo'], 'name' => $de['nombre']],
                    'to'          => [['email' => $para]],
                    'subject'     => $asunto,
                    'htmlContent' => $html,
                    'textContent' => $texto,
                ]),
                ['Content-Type: application/json', 'Accept: application/json', 'api-key: ' . $this->clave],
            ],
            'sendgrid' => [
                $json([
                    'personalizations' => [['to' => [['email' => $para]]]],
                    'from'    => ['email' => $de['correo'], 'name' => $de['nombre']],
                    'subject' => $asunto,
                    'content' => [
                        ['type' => 'text/plain', 'value' => $texto],
                        ['type' => 'text/html', 'value' => $html],
                    ],
                ]),
                ['Content-Type: application/json', 'Authorization: Bearer ' . $this->clave],
            ],
            default => [
                $json([
                    'from'    => $de['nombre'] . ' <' . $de['correo'] . '>',
                    'to'      => [$para],
                    'subject' => $asunto,
                    'html'    => $html,
                    'text'    => $texto,
                ]),
                ['Content-Type: application/json', 'Authorization: Bearer ' . $this->clave],
            ],
        };
    }

    /**
     * La petición, con cURL si está y con los flujos de PHP si no.
     *
     * Las dos vías porque en un Plesk cualquiera de las dos puede estar
     * desactivada, y quedarse sin ninguna sería quedarse sin correo.
     *
     * @param array<int, string> $cabeceras
     * @return array{0: int, 1: string, 2: string} estado, cuerpo y fallo de conexión
     */
    private function hablar(string $url, string $cuerpo, array $cabeceras): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return [0, '', 'No se pudo iniciar cURL.'];
            }
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $cuerpo,
                CURLOPT_HTTPHEADER     => $cabeceras,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->espera,
                CURLOPT_CONNECTTIMEOUT => min(10, $this->espera),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                // Sin esto, una respuesta 3xx llevaría la clave de API a otro
                // servidor que el proveedor no controla.
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $respuesta = curl_exec($ch);
            $estado = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $fallo = $respuesta === false ? (curl_error($ch) ?: 'cURL falló sin decir por qué.') : '';
            curl_close($ch);

            return [$estado, is_string($respuesta) ? $respuesta : '', $fallo];
        }

        $contexto = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $cabeceras),
                'content'       => $cuerpo,
                'timeout'       => $this->espera,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $respuesta = @file_get_contents($url, false, $contexto);
        if ($respuesta === false && ($http_response_header ?? []) === []) {
            $ultimo = error_get_last();
            return [0, '', $ultimo['message'] ?? 'No se pudo abrir la conexión HTTPS.'];
        }

        $estado = 0;
        foreach ($http_response_header ?? [] as $linea) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linea, $m)) {
                $estado = (int) $m[1];
            }
        }
        return [$estado, is_string($respuesta) ? $respuesta : '', ''];
    }

    private function anotar(string $lado, string $texto): void
    {
        if (count($this->conversacion) < 60) {
            $this->conversacion[] = ['lado' => $lado, 'texto' => mb_substr($texto, 0, 500)];
        }
    }

    /**
     * Qué significa cada respuesta que devuelven estos proveedores.
     *
     * @return array<int, string>
     */
    public static function explicar(string $codigo, string $mensaje, string $proveedor = ''): array
    {
        $m = mb_strtolower($mensaje);
        $pistas = [];
        $panel = self::PROVEEDORES[$proveedor]['panel'] ?? 'el panel del proveedor';

        if ($codigo === '401' || $codigo === '403' || str_contains($m, 'unauthorized')) {
            $pistas[] = 'La clave de API no es válida o no tiene permiso para enviar. Genera otra '
                . 'en ' . $panel . ' y comprueba que le diste permiso de envío, no solo de lectura.';
        }
        if ($codigo === '400' && (str_contains($m, 'sender') || str_contains($m, 'from'))) {
            $pistas[] = 'El remitente no está autorizado. Estos servicios exigen verificar el '
                . 'dominio o la dirección antes de dejar enviar en su nombre: hazlo en '
                . $panel . ' y publica los registros DNS que te dé.';
        }
        if ($codigo === '422' || ($codigo === '400' && str_contains($m, 'domain'))) {
            $pistas[] = 'El dominio del remitente no está verificado en el proveedor. Hay que '
                . 'añadir los registros DKIM y de retorno que indique, en el DNS de narino.gov.co.';
        }
        if ($codigo === '429') {
            $pistas[] = 'Se agotó el cupo del plan. Espera o sube de plan; la capa gratuita de '
                . 'estos servicios son unos cientos de correos al día.';
        }
        if (str_starts_with($codigo, '5')) {
            $pistas[] = 'El fallo es del proveedor (5xx). Reintenta en unos minutos.';
        }
        if ($codigo === '' || str_contains($m, 'ssl') || str_contains($m, 'resolve')
            || str_contains($m, 'timed out') || str_contains($m, 'could not')) {
            $pistas[] = 'No se llegó a hablar con el proveedor. Si el servidor tampoco puede salir '
                . 'por HTTPS al 443 —cosa rara, porque es el mismo puerto por el que sirve la '
                . 'web— revisa el cortafuegos y que el certificado raíz esté al día.';
        }

        if ($pistas === []) {
            $pistas[] = 'Copia la respuesta de abajo: el proveedor dice en el cuerpo del mensaje '
                . 'qué rechazó.';
        }

        $pistas[] = 'Enviar por API no exime de configurar el DNS: el proveedor firmará con su '
            . 'DKIM, pero el dominio del remitente tiene que autorizarlo. Sigue sus instrucciones '
            . 'de verificación de dominio.';

        return $pistas;
    }
}
