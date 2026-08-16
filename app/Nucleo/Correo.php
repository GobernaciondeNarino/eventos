<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Envío de correo.
 *
 * Tres modos, según lo que haya en config:
 *   'smtp'     → se habla SMTP con un servidor de verdad. Es lo que hay que
 *                usar cuando el correo del dominio está en Google Workspace o
 *                en Microsoft 365, que es el caso de narino.gov.co.
 *   'php'      → la función mail() del servidor. Entrega al servidor de correo
 *                local; sirve cuando el dominio tiene su buzón en el mismo
 *                Plesk, y no sirve cuando el dominio está en Google: los
 *                mensajes salen sin SPF ni DKIM del dominio y acaban en no
 *                deseado o rechazados.
 *   'registro' → no envía nada; deja el mensaje en almacen/registro. Es lo que
 *                se usa en pruebas y en instalaciones sin correo configurado,
 *                para que la plataforma siga siendo utilizable en vez de
 *                fallar al primer código de acceso.
 *
 * Se configura desde Administración → Correo, que además comprueba la conexión
 * y explica cada código de error del servidor. Ver docs/config-mail.md.
 */
final class Correo
{
    /** El último fallo, para que la pantalla de correo pueda contarlo. */
    private static string $ultimoError = '';
    private static string $ultimaTranscripcion = '';

    public static function ultimoError(): string
    {
        return self::$ultimoError;
    }

    public static function ultimaTranscripcion(): string
    {
        return self::$ultimaTranscripcion;
    }

    public static function enviar(string $destinatario, string $asunto, string $cuerpoHtml, string $cuerpoTexto = ''): bool
    {
        self::$ultimoError = '';
        self::$ultimaTranscripcion = '';

        $destinatario = trim($destinatario);
        if (!filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Cabeceras inyectadas: un salto de línea en el asunto permitiría
        // agregar destinatarios ocultos y convertir la plataforma en un relé.
        $asunto = str_replace(["\r", "\n"], ' ', $asunto);

        $remitente = (string) Config::obtener('correo_remitente', 'no-responder@narino.gov.co');
        $nombreRemitente = (string) Config::obtener('correo_nombre', 'Eventos TIC Nariño');
        $nombreRemitente = str_replace(['"', "\r", "\n"], '', $nombreRemitente);

        $modo = (string) Config::obtener('modo_correo', 'php');

        if ($cuerpoTexto === '') {
            $cuerpoTexto = self::aTextoPlano($cuerpoHtml);
        }

        $limite = 'lim_' . bin2hex(random_bytes(12));
        $cabeceras = implode("\r\n", [
            'From: ' . self::palabraCodificada($nombreRemitente) . ' <' . $remitente . '>',
            'Reply-To: ' . $remitente,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $limite . '"',
            'X-Mailer: Plataforma de Eventos TIC',
            'Auto-Submitted: auto-generated',
        ]);

        // Base64 y no 8bit. El correo electrónico limita cada línea a 998
        // caracteres (RFC 5321), y la plantilla HTML es una sola línea de varios
        // miles: con 8bit hay servidores que la rechazan y otros que la parten
        // por donde les conviene, y el mensaje llega roto. Como el carnet y el
        // código de acceso son lo único que devuelve a un asistente a su cuenta,
        // que un correo no llegue no es un detalle menor.
        $parte = static fn(string $tipo, string $contenido): string =>
            "Content-Type: $tipo; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($contenido), 76, "\r\n");

        $cuerpo = "--$limite\r\n" . $parte('text/plain', $cuerpoTexto) . "\r\n"
            . "--$limite\r\n" . $parte('text/html', $cuerpoHtml) . "\r\n"
            . "--$limite--\r\n";

        $asuntoCodificado = self::palabraCodificada($asunto, false);

        if ($modo === 'registro') {
            Registro::aviso('Correo no enviado (modo registro)', [
                'para'   => $destinatario,
                'asunto' => $asunto,
                'texto'  => mb_substr($cuerpoTexto, 0, 500),
            ]);
            return true;
        }

        if ($modo === 'smtp') {
            // El destinatario va en la cabecera To y también en el RCPT TO. Con
            // mail() lo ponía PHP; hablando SMTP hay que escribirlo.
            $cabeceras = 'To: ' . $destinatario . "\r\n"
                . 'Subject: ' . $asuntoCodificado . "\r\n"
                . 'Date: ' . date('r') . "\r\n"
                . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::dominioDelRemitente($remitente) . ">\r\n"
                . $cabeceras;

            $cliente = self::cliente();
            $enviado = $cliente->enviar($remitente, $destinatario, $cabeceras, $cuerpo);

            self::$ultimaTranscripcion = $cliente->transcripcion();
            if (!$enviado) {
                self::$ultimoError = $cliente->error();
                Registro::error('SMTP falló', [
                    'para'    => $destinatario,
                    'asunto'  => $asunto,
                    'codigo'  => $cliente->codigo(),
                    'detalle' => $cliente->error(),
                ]);
            }
            return $enviado;
        }

        // El quinto parámetro de mail() acaba en la línea de órdenes de
        // sendmail. Solo se pasa si el remitente es un correo válido: cualquier
        // otra cosa ahí es la vía conocida para ejecutar órdenes en el servidor.
        $sobre = filter_var($remitente, FILTER_VALIDATE_EMAIL) ? '-f' . $remitente : '';

        if (!function_exists('mail')) {
            self::$ultimoError = 'La función mail() está desactivada en este servidor '
                . '(disable_functions). Configura el modo SMTP.';
            Registro::error(self::$ultimoError, ['para' => $destinatario]);
            return false;
        }

        $enviado = @mail($destinatario, $asuntoCodificado, $cuerpo, $cabeceras, $sobre);
        if (!$enviado) {
            self::$ultimoError = 'mail() devolvió falso: el servidor de correo local rechazó '
                . 'el mensaje o no está configurado.';
            Registro::error('mail() falló', ['para' => $destinatario, 'asunto' => $asunto]);
        }
        return $enviado;
    }

    /** El cliente SMTP armado con lo que hay en la configuración. */
    private static function cliente(): Smtp
    {
        return new Smtp(
            (string) Config::obtener('smtp_host', 'smtp.gmail.com'),
            (int) Config::obtener('smtp_puerto', 587),
            (string) Config::obtener('smtp_seguridad', 'tls'),
            (string) Config::obtener('smtp_usuario', ''),
            (string) Config::obtener('smtp_clave', ''),
            (int) Config::obtener('smtp_espera', 15),
            (bool) Config::obtener('smtp_verificar_certificado', true),
        );
    }

    private static function dominioDelRemitente(string $remitente): string
    {
        $partes = explode('@', $remitente);
        $dominio = end($partes);
        return preg_match('/^[A-Za-z0-9.\-]+$/', $dominio) ? $dominio : 'localhost';
    }

    /**
     * Texto listo para ir en una cabecera de correo (RFC 2047).
     *
     * El nombre del evento lleva tildes y ñ. Metido tal cual en From, la
     * cabecera queda con bytes de 8 bits y hay receptores estrictos —Exchange y
     * Office 365, entre otros— que rechazan el mensaje entero. Y un solo
     * encoded-word no puede pasar de 75 caracteres, así que si hace falta se
     * parte en varios, que es lo que dice la norma.
     *
     * Si el texto es ASCII puro no se toca: así los asuntos normales siguen
     * leyéndose tal cual en cualquier cliente.
     */
    private static function palabraCodificada(string $texto, bool $entreComillas = true): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $texto)) {
            return $entreComillas ? '"' . $texto . '"' : $texto;
        }

        // 45 bytes por trozo: en base64 son 60 caracteres, y con el envoltorio
        // «=?UTF-8?B?…?=» el encoded-word queda por debajo de los 75 del RFC.
        $trozos = [];
        foreach (mb_str_split($texto, 15, 'UTF-8') as $parte) {
            $trozos[] = '=?UTF-8?B?' . base64_encode($parte) . '?=';
        }
        return implode("\r\n ", $trozos);
    }

    /**
     * La versión en texto del mensaje.
     *
     * strip_tags() a secas se lleva por delante los enlaces: «Ver mi carnet»
     * sobrevive, la dirección no. Quien lea el correo en texto plano —o cuyo
     * cliente bloquee el HTML— se queda con un botón que no existe y sin ninguna
     * dirección a la que ir. Aquí el enlace se escribe entero antes de quitar
     * las etiquetas.
     */
    private static function aTextoPlano(string $html): string
    {
        $texto = preg_replace_callback(
            '#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $destino = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
                $rotulo = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES, 'UTF-8'));
                return $rotulo === '' || $rotulo === $destino ? $destino : $rotulo . ': ' . $destino;
            },
            $html
        ) ?? $html;

        // Los saltos de párrafo se conservan para que el texto sea legible.
        $texto = preg_replace('#<\s*/\s*(p|div|tr|h[1-6])\s*>#i', "\n\n", $texto) ?? $texto;
        $texto = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $texto) ?? $texto;
        $texto = html_entity_decode(strip_tags($texto), ENT_QUOTES, 'UTF-8');
        $texto = preg_replace('/[ \t]+/', ' ', $texto) ?? $texto;
        $texto = preg_replace('/\n{3,}/', "\n\n", $texto) ?? $texto;

        return trim($texto);
    }

    /** ¿Está el correo listo para usarse? Lo consulta el instalador. */
    public static function disponible(): bool
    {
        $modo = (string) Config::obtener('modo_correo', 'php');
        if ($modo === 'registro') {
            return true;
        }
        if ($modo === 'smtp') {
            return (string) Config::obtener('smtp_host', '') !== '';
        }
        return function_exists('mail');
    }

    /* =====================================================================
       Revisión de la configuración
       -------------------------------------------------------------------------
       Todo esto se mira sin tocar la red, para que la pantalla de correo cargue
       rápido. La prueba con el servidor está en probar(), detrás de un botón.
       ===================================================================== */

    /**
     * Qué está bien y qué no, con el arreglo concreto de cada cosa.
     *
     * @return array<int, array{estado: string, titulo: string, detalle: string, arreglo: string}>
     */
    public static function revision(): array
    {
        $modo = (string) Config::obtener('modo_correo', 'php');
        $remitente = trim((string) Config::obtener('correo_remitente', ''));
        $lista = [];

        $anotar = static function (string $estado, string $titulo, string $detalle, string $arreglo = '') use (&$lista): void {
            $lista[] = compact('estado', 'titulo', 'detalle', 'arreglo');
        };

        /* ---- El modo ---- */

        if ($modo === 'registro') {
            $anotar('fail', 'No se está enviando ningún correo',
                'El modo es «solo registrar»: los mensajes se escriben en almacen/registro/ y '
                . 'nadie los recibe. Quien pierda la sesión no podrá volver a entrar, porque el '
                . 'código de acceso es lo único que lo identifica.',
                'Cambia el modo a «Servidor SMTP» y completa los datos de abajo.');
        } elseif ($modo === 'php') {
            $anotar('warn', 'Se está usando la función mail() del servidor',
                'Entrega al servidor de correo local de este Plesk. Si el buzón del dominio está '
                . 'en Google Workspace o en Microsoft 365 —como narino.gov.co—, los mensajes salen '
                . 'sin SPF ni DKIM válidos y acaban en no deseado, o rechazados.',
                'Usa «Servidor SMTP» con la cuenta institucional.');
            if (!function_exists('mail')) {
                $anotar('fail', 'La función mail() está desactivada',
                    'Este PHP tiene mail() en disable_functions, así que el modo actual no puede '
                    . 'enviar absolutamente nada.',
                    'Cambia a «Servidor SMTP».');
            }
        } else {
            $anotar('ok', 'Envío por servidor SMTP',
                'Los mensajes salen autenticados como la cuenta institucional, que es lo que hace '
                . 'que lleguen a la bandeja de entrada y no a no deseado.');
        }

        /* ---- Lo que hace falta en el servidor ---- */

        if (!extension_loaded('openssl')) {
            $anotar('fail', 'Falta la extensión openssl',
                'Sin ella no hay TLS, y ni Google ni Microsoft aceptan conexiones sin cifrar.',
                'Actívala en Plesk → Dominio → Configuración de PHP.');
        }
        if (!function_exists('stream_socket_client')) {
            $anotar('fail', 'stream_socket_client está desactivada',
                'Es la función con la que se abre la conexión al servidor de correo.',
                'Quítala de disable_functions en la configuración de PHP.');
        }

        /* ---- El remitente ---- */

        if (!filter_var($remitente, FILTER_VALIDATE_EMAIL)) {
            $anotar('fail', 'El remitente no es una dirección válida',
                'Está vacío o mal escrito: «' . ($remitente !== '' ? $remitente : '(vacío)') . '».',
                'Escribe la dirección institucional desde la que saldrán los mensajes.');
        }

        if ($modo !== 'smtp') {
            return $lista;
        }

        /* ---- Los datos del SMTP ---- */

        $host = trim((string) Config::obtener('smtp_host', ''));
        $puerto = (int) Config::obtener('smtp_puerto', 0);
        $seguridad = (string) Config::obtener('smtp_seguridad', 'tls');
        $usuario = trim((string) Config::obtener('smtp_usuario', ''));
        $clave = (string) Config::obtener('smtp_clave', '');

        if ($host === '') {
            $anotar('fail', 'Falta el servidor SMTP',
                'Sin servidor no hay a dónde conectarse.',
                'Para el correo institucional en Google: smtp.gmail.com');
        }
        if ($puerto <= 0 || $puerto > 65535) {
            $anotar('fail', 'El puerto no es válido', 'Se leyó «' . $puerto . '».',
                '587 con STARTTLS, o 465 con SSL directo.');
        }
        if ($puerto === 587 && $seguridad !== 'tls') {
            $anotar('warn', 'El puerto 587 espera STARTTLS',
                'Está configurado como «' . $seguridad . '». El servidor rechazará la conexión o '
                . 'la dejará sin cifrar.',
                'Cambia la seguridad a STARTTLS, o usa el puerto 465 con SSL directo.');
        }
        if ($puerto === 465 && $seguridad !== 'ssl') {
            $anotar('warn', 'El puerto 465 espera SSL directo',
                'Está configurado como «' . $seguridad . '». En ese puerto el cifrado empieza '
                . 'antes del saludo, no con STARTTLS.',
                'Cambia la seguridad a «SSL directo», o usa el puerto 587 con STARTTLS.');
        }
        if ($puerto === 25) {
            $anotar('warn', 'El puerto 25 suele estar bloqueado',
                'Casi todos los proveedores —OVH incluido— cierran la salida por el 25 para '
                . 'frenar el envío masivo. Además Google no acepta autenticación por ahí.',
                'Usa el 587 con STARTTLS.');
        }
        if ($usuario === '') {
            $anotar('warn', 'Sin usuario: se conectará sin autenticarse',
                'Solo sirve con un relé interno que permita enviar sin credenciales. Google y '
                . 'Microsoft siempre las piden.',
                'Escribe la dirección completa de la cuenta: hosting@narino.gov.co');
        }
        if ($usuario !== '' && $clave === '') {
            $anotar('fail', 'Falta la contraseña',
                'Hay usuario pero no contraseña, así que la autenticación fallará con 535.',
                'En Google no sirve la contraseña normal: hace falta una contraseña de aplicación '
                . 'de 16 caracteres.');
        }

        /* ---- Google, que es el caso de esta instalación ---- */

        $esGoogle = str_contains(strtolower($host), 'gmail')
            || str_contains(strtolower($host), 'google');

        if ($esGoogle) {
            if ($clave !== '' && strlen(preg_replace('/\s+/', '', $clave) ?? '') !== 16) {
                $anotar('warn', 'La contraseña no parece una contraseña de aplicación',
                    'Google genera contraseñas de aplicación de exactamente 16 letras. Esta tiene '
                    . strlen(preg_replace('/\s+/', '', $clave) ?? '') . '. Si es la contraseña normal '
                    . 'de la cuenta, Google la rechazará con 534-5.7.9.',
                    'Cuenta de Google → Seguridad → Verificación en dos pasos → Contraseñas de '
                    . 'aplicación. Copia las 16 letras; los espacios dan igual.');
            }
            if ($usuario !== '' && $remitente !== '' && strcasecmp($usuario, $remitente) !== 0) {
                $anotar('warn', 'El remitente no es la cuenta que se autentica',
                    'Se autentica «' . $usuario . '» pero los mensajes dicen venir de «' . $remitente . '». '
                    . 'Google solo lo permite si esa dirección está dada de alta como alias '
                    . 'verificado; si no, reescribe el remitente o rechaza con 550-5.7.1.',
                    'Pon el mismo correo en los dos campos, o da de alta el alias en Gmail → '
                    . 'Configuración → Cuentas → Enviar como.');
            }
        }

        if (!(bool) Config::obtener('smtp_verificar_certificado', true)) {
            $anotar('warn', 'No se verifica el certificado del servidor',
                'La conexión va cifrada, pero nadie comprueba que el servidor sea quien dice ser: '
                . 'alguien en medio de la red podría leer las credenciales.',
                'Actívalo salvo que el servidor de correo sea interno y use un certificado propio.');
        }

        return $lista;
    }

    /** ¿Hay algún problema que impida enviar? */
    public static function hayBloqueantes(): bool
    {
        foreach (self::revision() as $punto) {
            if ($punto['estado'] === 'fail') {
                return true;
            }
        }
        return false;
    }

    /**
     * Prueba de verdad: se conecta, se autentica y —si se pide— envía.
     *
     * @return array{ok: bool, resumen: string, error: string, codigo: string,
     *               transcripcion: string, pistas: array<int, string>}
     */
    public static function probar(string $destinatario = ''): array
    {
        $modo = (string) Config::obtener('modo_correo', 'php');
        $vacio = ['ok' => false, 'resumen' => '', 'error' => '', 'codigo' => '',
                  'transcripcion' => '', 'pistas' => []];

        if ($modo === 'registro') {
            return ['resumen' => 'El modo es «solo registrar»: no se intentó enviar nada.',
                'pistas' => ['Cambia el modo a «Servidor SMTP» y vuelve a probar.']] + $vacio;
        }

        if ($modo === 'php') {
            if ($destinatario === '') {
                return ['ok' => false, 'resumen' => 'Escribe una dirección de prueba.'] + $vacio;
            }
            $ok = self::mensajeDePrueba($destinatario);
            return [
                'ok' => $ok,
                'resumen' => $ok
                    ? 'mail() aceptó el mensaje. Eso no garantiza que llegue: revisa la bandeja '
                        . 'de entrada y también la de no deseado.'
                    : 'mail() devolvió falso.',
                'error' => self::$ultimoError,
                'codigo' => '',
                'transcripcion' => '',
                'pistas' => $ok ? [] : [
                    'Revisa que el dominio tenga buzón en este mismo servidor (Plesk → Correo).',
                    'Si el buzón está en Google o Microsoft, mail() no es el camino: usa SMTP.',
                ],
            ];
        }

        /* ---- SMTP ---- */

        $cliente = self::cliente();

        if ($destinatario === '') {
            $ok = $cliente->comprobar();
            return [
                'ok' => $ok,
                'resumen' => $ok
                    ? 'Conexión y autenticación correctas. No se envió ningún mensaje.'
                    : 'No se pudo conectar o autenticar.',
                'error' => $cliente->error(),
                'codigo' => $cliente->codigo(),
                'transcripcion' => $cliente->transcripcion(),
                'pistas' => $ok ? [] : self::explicar($cliente->codigo(), $cliente->error()),
            ];
        }

        $ok = self::mensajeDePrueba($destinatario);
        return [
            'ok' => $ok,
            'resumen' => $ok
                ? 'Mensaje entregado al servidor de correo. Revisa ' . $destinatario
                    . ' —también la carpeta de no deseado— en el próximo minuto.'
                : 'El servidor de correo rechazó el mensaje.',
            'error' => self::$ultimoError,
            'codigo' => '',
            'transcripcion' => self::$ultimaTranscripcion,
            'pistas' => $ok ? [] : self::explicar('', self::$ultimoError),
        ];
    }

    private static function mensajeDePrueba(string $destinatario): bool
    {
        $evento = (string) Config::obtener('correo_nombre', 'Plataforma de Eventos TIC');
        $cuando = date('Y-m-d H:i:s');

        return self::enviar(
            $destinatario,
            'Prueba de configuración de correo · ' . $cuando,
            self::plantilla($evento, 'La configuración de correo funciona',
                '<p style="margin:0 0 16px">Si estás leyendo esto, la plataforma puede enviar '
                . 'correo: los códigos de acceso y los carnets van a llegar.</p>'
                . '<p style="margin:0;font-size:14px;color:#556">Enviado el ' . htmlspecialchars($cuando)
                . ' desde Administración → Correo.</p>'),
            "La configuración de correo funciona. Enviado el $cuando."
        );
    }

    /**
     * Qué significa el fallo y qué hacer.
     *
     * La lista está escrita a partir de lo que devuelven de verdad Google
     * Workspace y Microsoft 365, que son los dos casos que se dan aquí. Un
     * «no se pudo enviar» a secas no le sirve a nadie: lo que hace falta es
     * saber si sobra un espacio en la contraseña, si falta un alias o si el
     * proveedor tiene cerrado el puerto.
     *
     * @return array<int, string>
     */
    public static function explicar(string $codigo, string $mensaje): array
    {
        $m = mb_strtolower($mensaje);
        $pistas = [];

        $contiene = static fn(string ...$agujas): bool => (bool) array_filter(
            $agujas,
            static fn(string $a): bool => str_contains($m, $a)
        );

        // --- Antes de llegar a hablar SMTP ---
        if ($contiene('connection refused', 'conexión', 'no se pudo abrir', 'timed out', 'no respondió')) {
            $pistas[] = 'La conexión no llegó a abrirse. Lo más común: el proveedor tiene cerrada '
                . 'la salida a ese puerto. Prueba el 465 con SSL directo si el 587 no responde, y '
                . 'al revés.';
            $pistas[] = 'En Plesk, comprueba que el cortafuegos permita la salida a smtp.gmail.com '
                . 'por los puertos 587 y 465. Desde SSH: nc -vz smtp.gmail.com 587';
            $pistas[] = 'Si el servidor está detrás de un proxy de salida, PHP no lo usa para SMTP: '
                . 'hay que abrir el puerto de verdad.';
        }
        if ($contiene('tls', 'certificado', 'ssl')) {
            $pistas[] = 'El cifrado no se pudo establecer. Revisa que la extensión openssl esté '
                . 'activa y que el reloj del servidor esté en hora: un reloj desfasado invalida '
                . 'cualquier certificado.';
            $pistas[] = 'Si el servidor de correo es interno y usa un certificado propio, desactiva '
                . 'la verificación del certificado en esta misma pantalla.';
        }

        // --- Códigos de autenticación ---
        if ($codigo === '535' || $contiene('username and password not accepted', '5.7.8')) {
            $pistas[] = 'Usuario o contraseña rechazados (535-5.7.8). En Google **no sirve la '
                . 'contraseña normal de la cuenta**: hay que generar una contraseña de aplicación '
                . 'de 16 letras en Cuenta de Google → Seguridad → Verificación en dos pasos → '
                . 'Contraseñas de aplicación.';
            $pistas[] = 'El usuario tiene que ser la dirección completa, con dominio: '
                . 'hosting@narino.gov.co, no «hosting».';
            $pistas[] = 'Si la cuenta es de Google Workspace, el administrador del dominio puede '
                . 'tener bloqueadas las contraseñas de aplicación en la consola de administración '
                . '(Seguridad → Menos seguras / Acceso de aplicaciones).';
        }
        if ($codigo === '534' || $contiene('5.7.9', 'application-specific password')) {
            $pistas[] = 'Google pide una contraseña de aplicación (534-5.7.9). La cuenta tiene la '
                . 'verificación en dos pasos activa, que es lo correcto, y por eso la contraseña '
                . 'normal no vale aquí.';
        }
        if ($contiene('5.7.14', 'log in via your web browser', 'websignin')) {
            $pistas[] = 'Google bloqueó el intento por venir de un servidor desconocido '
                . '(534-5.7.14). Entra una vez a https://accounts.google.com/DisplayUnlockCaptcha '
                . 'con esa cuenta y vuelve a probar en los diez minutos siguientes.';
        }
        if ($contiene('too many login attempts', '4.7.0') || $codigo === '454') {
            $pistas[] = 'Demasiados intentos seguidos. Google bloquea temporalmente: espera unos '
                . 'minutos antes de volver a probar.';
        }
        if ($codigo === '530' || $contiene('5.5.1 authentication required')) {
            $pistas[] = 'El servidor exige autenticarse y no se le dieron credenciales, o se '
                . 'intentó autenticar antes de cifrar. Revisa que estén el usuario y la contraseña '
                . 'y que la seguridad sea STARTTLS en el 587.';
        }

        // --- Códigos de entrega ---
        if ($contiene('5.7.1', 'relay', 'not allowed to send as', 'sender address rejected')) {
            $pistas[] = 'El servidor no deja enviar con ese remitente (550-5.7.1). La dirección del '
                . 'campo «Remitente» tiene que ser la misma que la cuenta autenticada, o un alias '
                . 'verificado en Gmail → Configuración → Cuentas → Enviar como.';
        }
        if ($contiene('5.4.5', 'daily user sending', 'quota exceeded', 'sending limit')) {
            $pistas[] = 'Se agotó el cupo diario de la cuenta. Google Workspace permite unos 2.000 '
                . 'mensajes al día; una cuenta gratuita, 500. Para un evento con muchos asistentes '
                . 'hay que repartir los envíos o usar un servicio de envío masivo.';
        }
        if ($contiene('5.1.1', 'user unknown', "recipient address rejected", 'no such user')) {
            $pistas[] = 'La dirección de destino no existe. Revisa que esté bien escrita.';
        }
        if ($contiene('5.1.8', '5.1.2', 'domain of sender address', 'does not exist')) {
            $pistas[] = 'El dominio del remitente no se resuelve. Revisa que el correo del campo '
                . '«Remitente» tenga un dominio real y con registros MX.';
        }
        if ($codigo === '552' || $contiene('message too large', '5.3.4')) {
            $pistas[] = 'El mensaje supera el tamaño permitido.';
        }
        if ($codigo === '421' || str_starts_with($codigo, '4')) {
            $pistas[] = 'Es un fallo temporal (código 4xx): el servidor pide reintentar más tarde. '
                . 'Si se repite siempre, suele ser límite de frecuencia.';
        }

        if ($pistas === []) {
            $pistas[] = 'Copia la conversación completa de abajo: el código y el texto del servidor '
                . 'dicen exactamente qué rechazó.';
            $pistas[] = 'Revisa docs/config-mail.md, que lista los códigos habituales y su arreglo.';
        }

        // Recordatorio que aplica a casi todo lo anterior.
        $pistas[] = 'Para que los mensajes no acaben en no deseado, el dominio narino.gov.co debe '
            . 'tener SPF con include:_spf.google.com y DKIM activo en Google Workspace.';

        return $pistas;
    }

    /* =====================================================================
       Mensajes de la plataforma
       ===================================================================== */

    public static function codigoDeAcceso(string $destinatario, string $codigo, string $evento): bool
    {
        $html = self::plantilla(
            $evento,
            'Tu código de acceso',
            '<p style="margin:0 0 16px">Escribe este código en la plataforma para continuar:</p>'
            . '<p style="margin:0 0 16px;font-size:34px;letter-spacing:.3em;font-weight:700;'
            . 'font-family:monospace;color:#0C2E3C">' . htmlspecialchars($codigo) . '</p>'
            . '<p style="margin:0;font-size:14px;color:#556">Vence en 10 minutos y sirve una sola vez. '
            . 'Si no lo pediste, ignora este mensaje: nadie entró a tu cuenta.</p>'
        );

        return self::enviar(
            $destinatario,
            'Tu código de acceso: ' . $codigo,
            $html,
            "Tu código de acceso es $codigo. Vence en 10 minutos y sirve una sola vez."
        );
    }

    public static function carnetEmitido(string $destinatario, string $nombre, string $evento, string $enlace): bool
    {
        $html = self::plantilla(
            $evento,
            'Tu carnet está listo',
            '<p style="margin:0 0 16px">Hola ' . htmlspecialchars($nombre) . ',</p>'
            . '<p style="margin:0 0 16px">Tu preregistro quedó completo. Desde este enlace puedes ver '
            . 'tu carnet digital con el código QR:</p>'
            . '<p style="margin:0 0 22px"><a href="' . htmlspecialchars($enlace) . '" '
            . 'style="background:#0C2E3C;color:#fff;padding:13px 22px;text-decoration:none;'
            . 'display:inline-block;font-weight:600">Ver mi carnet</a></p>'
            . '<p style="margin:0;font-size:14px;color:#556">No hace falta imprimirlo: basta con mostrarlo '
            . 'desde el celular. Cada día del evento se registra el ingreso escaneando el código de la entrada.</p>'
        );

        return self::enviar($destinatario, 'Tu carnet para ' . $evento, $html);
    }

    private static function plantilla(string $evento, string $titulo, string $contenido): string
    {
        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;background:#f4f8fb;font-family:-apple-system,BlinkMacSystemFont,'
            . '\'Segoe UI\',sans-serif;color:#0B2534">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:28px 12px">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="max-width:560px;background:#fff;border:1px solid #C9DCE8">'
            . '<tr><td style="background:#0C2E3C;padding:18px 24px;color:#E4F7FD;font-size:14px;'
            . 'letter-spacing:.12em;text-transform:uppercase;font-weight:700">'
            . htmlspecialchars($evento) . '</td></tr>'
            . '<tr><td style="padding:28px 24px">'
            . '<h1 style="margin:0 0 18px;font-size:22px;color:#0B2534">' . htmlspecialchars($titulo) . '</h1>'
            . $contenido
            . '</td></tr>'
            . '<tr><td style="padding:16px 24px;border-top:1px solid #E3EDF3;font-size:12px;color:#6E8A9C">'
            . 'Secretaría TIC, Innovación y Gobierno Abierto · Gobernación de Nariño<br>'
            . 'Mensaje automático, no respondas a este correo.'
            . '</td></tr></table></td></tr></table></body></html>';
    }
}
