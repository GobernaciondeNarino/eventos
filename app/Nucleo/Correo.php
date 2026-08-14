<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Envío de correo.
 *
 * Dos modos, según lo que haya en config:
 *   'php'      → la función mail() del servidor. En Plesk funciona de fábrica
 *                porque el propio panel monta el servidor de correo.
 *   'registro' → no envía nada; deja el mensaje en almacen/registro. Es lo que
 *                se usa en pruebas y en instalaciones sin correo configurado,
 *                para que la plataforma siga siendo utilizable en vez de
 *                fallar al primer código de acceso.
 *
 * No hay cliente SMTP propio: en Plesk el correo local ya sale firmado con
 * SPF y DKIM del dominio, mientras que enviar desde PHP por SMTP externo suele
 * terminar en la carpeta de no deseado.
 */
final class Correo
{
    public static function enviar(string $destinatario, string $asunto, string $cuerpoHtml, string $cuerpoTexto = ''): bool
    {
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

        // El quinto parámetro de mail() acaba en la línea de órdenes de
        // sendmail. Solo se pasa si el remitente es un correo válido: cualquier
        // otra cosa ahí es la vía conocida para ejecutar órdenes en el servidor.
        $sobre = filter_var($remitente, FILTER_VALIDATE_EMAIL) ? '-f' . $remitente : '';

        $enviado = @mail($destinatario, $asuntoCodificado, $cuerpo, $cabeceras, $sobre);
        if (!$enviado) {
            Registro::error('mail() falló', ['para' => $destinatario, 'asunto' => $asunto]);
        }
        return $enviado;
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
        return (string) Config::obtener('modo_correo', 'php') === 'registro'
            || function_exists('mail');
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
