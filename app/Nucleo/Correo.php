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
            $cuerpoTexto = trim(html_entity_decode(strip_tags($cuerpoHtml), ENT_QUOTES, 'UTF-8'));
        }

        $limite = 'lim_' . bin2hex(random_bytes(12));
        $cabeceras = implode("\r\n", [
            'From: "' . $nombreRemitente . '" <' . $remitente . '>',
            'Reply-To: ' . $remitente,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $limite . '"',
            'X-Mailer: Plataforma de Eventos TIC',
            'Auto-Submitted: auto-generated',
        ]);

        $cuerpo = "--$limite\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $cuerpoTexto . "\r\n\r\n"
            . "--$limite\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $cuerpoHtml . "\r\n\r\n"
            . "--$limite--";

        $asuntoCodificado = '=?UTF-8?B?' . base64_encode($asunto) . '?=';

        if ($modo === 'registro') {
            Registro::aviso('Correo no enviado (modo registro)', [
                'para'   => $destinatario,
                'asunto' => $asunto,
                'texto'  => mb_substr($cuerpoTexto, 0, 500),
            ]);
            return true;
        }

        $enviado = @mail($destinatario, $asuntoCodificado, $cuerpo, $cabeceras, '-f' . $remitente);
        if (!$enviado) {
            Registro::error('mail() falló', ['para' => $destinatario, 'asunto' => $asunto]);
        }
        return $enviado;
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
