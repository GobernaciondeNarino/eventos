<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Envío de mensajes cortos por WhatsApp y por SMS.
 *
 * Todo sale por **HTTPS al puerto 443**, el mismo por el que este servidor
 * sirve la web. Eso importa aquí más que en ningún otro sitio: la regla de
 * cortafuegos que rechaza el SMTP saliente no toca el 443, así que estos dos
 * canales funcionan hoy, sin pedirle nada al área de sistemas.
 *
 * Proveedores, elegidos porque la petición es un POST con una credencial en una
 * cabecera y nada más:
 *
 *   WhatsApp · meta    → graph.facebook.com/v21.0/{telefono_id}/messages
 *              twilio  → api.twilio.com … From: whatsapp:+…
 *   SMS      · twilio  → api.twilio.com/2010-04-01/Accounts/{sid}/Messages.json
 *              generico→ cualquier pasarela con API HTTP: se le pasa la URL y
 *                        se sustituyen {telefono} y {texto}
 *
 * El «genérico» está por las pasarelas locales. En Colombia varias operadoras y
 * revendedores ofrecen un HTTP GET/POST simple, y sin esa opción la Gobernación
 * tendría que contratar a un proveedor extranjero para mandar un SMS a Pasto.
 *
 * Igual que el correo, guarda la conversación para poder depurar, y tacha la
 * credencial antes de enseñarla.
 */
final class Mensajeria
{
    public const PROVEEDORES = [
        'whatsapp' => [
            'meta' => [
                'nombre'   => 'WhatsApp Cloud API (Meta)',
                'panel'    => 'developers.facebook.com → tu app → WhatsApp → API Setup',
                'cuenta'   => 'Identificador del número (Phone number ID)',
                'token'    => 'Token permanente del sistema',
                'remitente' => '',
            ],
            'twilio' => [
                'nombre'   => 'Twilio WhatsApp',
                'panel'    => 'console.twilio.com → Messaging → Senders',
                'cuenta'   => 'Account SID (empieza por AC…)',
                'token'    => 'Auth Token',
                'remitente' => 'Número de WhatsApp aprobado, con indicativo',
            ],
        ],
        'sms' => [
            'twilio' => [
                'nombre'   => 'Twilio SMS',
                'panel'    => 'console.twilio.com → Phone Numbers',
                'cuenta'   => 'Account SID (empieza por AC…)',
                'token'    => 'Auth Token',
                'remitente' => 'Número emisor, con indicativo',
            ],
            'generico' => [
                'nombre'   => 'Pasarela SMS propia (HTTP)',
                'panel'    => 'La que dé el proveedor local',
                'cuenta'   => 'URL de la pasarela, con {telefono} y {texto}',
                'token'    => 'Clave o token, se manda como Authorization: Bearer',
                'remitente' => 'Remitente, si la pasarela lo pide',
            ],
        ],
    ];

    /** @var array<int, array{lado: string, texto: string}> */
    private array $conversacion = [];

    private string $error = '';
    private string $codigo = '';

    /**
     * @param string $canal     'whatsapp' o 'sms'
     * @param string $urlBase   Solo para pruebas: apunta a un servidor local.
     */
    public function __construct(
        private string $canal,
        private string $proveedor,
        private string $token,
        private string $remitente = '',
        private string $cuenta = '',
        private int $espera = 15,
        private string $urlBase = '',
    ) {
    }

    public function error(): string
    {
        return $this->error;
    }

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

    public static function conocido(string $canal, string $proveedor): bool
    {
        return isset(self::PROVEEDORES[$canal][$proveedor]);
    }

    public function enviar(string $telefono, string $texto): bool
    {
        $this->error = '';
        $this->codigo = '';

        $telefono = Autenticacion::telefonoInternacional($telefono);
        if ($telefono === '') {
            $this->error = 'El teléfono no tiene un formato utilizable. En Colombia son diez '
                . 'dígitos empezando por 3, o el número completo con indicativo.';
            return false;
        }
        if (!self::conocido($this->canal, $this->proveedor)) {
            $this->error = 'Proveedor desconocido para ' . $this->canal . ': «' . $this->proveedor . '».';
            return false;
        }
        if ($this->token === '') {
            $this->error = 'Falta la credencial del proveedor.';
            return false;
        }

        [$url, $cuerpo, $cabeceras, $tipo] = $this->peticion($telefono, $texto);
        if ($url === '') {
            $this->error = 'Falta configurar ' . ($this->cuenta === '' ? 'la cuenta o la URL' : 'el remitente') . '.';
            return false;
        }

        $this->anotar('cliente', 'POST ' . preg_replace('#//[^@/]+@#', '//[credencial]@', $url));
        foreach ($cabeceras as $cabecera) {
            $this->anotar('cliente', preg_replace(
                '/^(Authorization|Api-Key):.*$/i',
                '$1: [credencial tachada]',
                $cabecera
            ) ?? $cabecera);
        }
        // El cuerpo lleva el teléfono y el texto, no la credencial, así que se
        // puede enseñar: es justo lo que hace falta para depurar un envío.
        $this->anotar('cliente', mb_substr($cuerpo, 0, 300));

        [$estado, $respuesta, $fallo] = $this->hablar($url, $cuerpo, $cabeceras, $tipo);

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

        if ($estado >= 200 && $estado < 300) {
            return true;
        }

        $this->error = 'HTTP ' . $estado . ($respuesta !== '' ? ' · ' . mb_substr($respuesta, 0, 300) : '');
        return false;
    }

    /**
     * URL, cuerpo, cabeceras y tipo de contenido de cada proveedor.
     *
     * @return array{0: string, 1: string, 2: array<int, string>, 3: string}
     */
    private function peticion(string $telefono, string $texto): array
    {
        $sinMas = ltrim($telefono, '+');

        if ($this->canal === 'whatsapp' && $this->proveedor === 'meta') {
            if ($this->cuenta === '') {
                return ['', '', [], ''];
            }
            $url = $this->urlBase !== ''
                ? $this->urlBase
                : 'https://graph.facebook.com/v21.0/' . rawurlencode($this->cuenta) . '/messages';

            return [
                $url,
                (string) json_encode([
                    'messaging_product' => 'whatsapp',
                    'to'                => $sinMas,
                    'type'              => 'text',
                    'text'              => ['body' => $texto],
                ], JSON_UNESCAPED_UNICODE),
                ['Content-Type: application/json', 'Authorization: Bearer ' . $this->token],
                'json',
            ];
        }

        if ($this->proveedor === 'twilio') {
            if ($this->cuenta === '' || $this->remitente === '') {
                return ['', '', [], ''];
            }
            $url = $this->urlBase !== ''
                ? $this->urlBase
                : 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($this->cuenta) . '/Messages.json';

            $prefijo = $this->canal === 'whatsapp' ? 'whatsapp:' : '';

            return [
                $url,
                http_build_query([
                    'To'   => $prefijo . $telefono,
                    'From' => $prefijo . $this->remitente,
                    'Body' => $texto,
                ]),
                [
                    'Content-Type: application/x-www-form-urlencoded',
                    // Twilio autentica con HTTP básico: SID como usuario y el
                    // token como contraseña.
                    'Authorization: Basic ' . base64_encode($this->cuenta . ':' . $this->token),
                ],
                'form',
            ];
        }

        // Pasarela propia: la URL la da el proveedor local y lleva marcadores.
        if ($this->cuenta === '') {
            return ['', '', [], ''];
        }
        $url = $this->urlBase !== '' ? $this->urlBase : $this->cuenta;
        $url = str_replace(
            ['{telefono}', '{texto}', '{remitente}'],
            [rawurlencode($telefono), rawurlencode($texto), rawurlencode($this->remitente)],
            $url
        );

        return [
            $url,
            http_build_query(['to' => $telefono, 'text' => $texto, 'from' => $this->remitente]),
            ['Content-Type: application/x-www-form-urlencoded', 'Authorization: Bearer ' . $this->token],
            'form',
        ];
    }

    /**
     * @param array<int, string> $cabeceras
     * @return array{0: int, 1: string, 2: string}
     */
    private function hablar(string $url, string $cuerpo, array $cabeceras, string $tipo): array
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
                // Una redirección llevaría la credencial a otro servidor.
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
                'method'          => 'POST',
                'header'          => implode("\r\n", $cabeceras),
                'content'         => $cuerpo,
                'timeout'         => $this->espera,
                'ignore_errors'   => true,
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

    /** @return array<int, string> */
    public static function explicar(string $codigo, string $mensaje, string $canal, string $proveedor): array
    {
        $m = mb_strtolower($mensaje);
        $pistas = [];
        $panel = self::PROVEEDORES[$canal][$proveedor]['panel'] ?? 'el panel del proveedor';

        if ($codigo === '401' || $codigo === '403' || str_contains($m, 'unauthorized')
            || str_contains($m, 'authenticate')) {
            $pistas[] = 'La credencial no vale. Genera otra en ' . $panel
                . ' y comprueba que la cuenta —el SID o el identificador del número— sea la que va '
                . 'con ese token.';
        }
        if ($codigo === '400' || $codigo === '404') {
            $pistas[] = 'El proveedor rechazó los datos. Lo más común: el número emisor no está '
                . 'aprobado, el identificador del número es de otra app, o el destino no tiene el '
                . 'formato internacional (+57…).';
        }
        if ($canal === 'whatsapp' && ($codigo === '400' || str_contains($m, 'template')
            || str_contains($m, '24') || str_contains($m, 'window'))) {
            $pistas[] = 'WhatsApp solo deja mandar texto libre dentro de las 24 horas siguientes a '
                . 'que la persona escriba. Para iniciar una conversación hay que usar una '
                . 'plantilla aprobada por Meta. Para códigos de acceso existe la categoría '
                . '«Authentication», que se aprueba en horas: créala en el panel y pide que la '
                . 'plataforma la use.';
        }
        if ($codigo === '429') {
            $pistas[] = 'Se superó el límite de envío del proveedor. Espera, o sube el plan.';
        }
        if (str_starts_with($codigo, '5')) {
            $pistas[] = 'El fallo es del proveedor (5xx). Reintenta en unos minutos.';
        }
        if ($codigo === '' || str_contains($m, 'ssl') || str_contains($m, 'resolve')
            || str_contains($m, 'timed out')) {
            $pistas[] = 'No se llegó a hablar con el proveedor. Estos envíos salen por HTTPS al '
                . '443; si eso tampoco funciona, el servidor no tiene salida a internet.';
        }

        if ($pistas === []) {
            $pistas[] = 'Copia la respuesta de abajo: el proveedor dice en el cuerpo qué rechazó.';
        }

        $pistas[] = 'Cada mensaje cuesta. Con el límite de intentos de la plataforma —cuatro por '
            . 'hora y destinatario— el gasto está acotado, pero conviene revisar el consumo en '
            . $panel . ' durante el evento.';

        return $pistas;
    }
}
