<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Construcción de URLs.
 *
 * Ninguna plantilla escribe rutas a mano. Todas pasan por aquí, y aquí se
 * antepone la subcarpeta donde esté instalada la aplicación. Es lo que permite
 * mover la plataforma de https://eventos.narino.gov.co/ a
 * https://tic.narino.gov.co/cumbreAI/ sin cambiar una línea.
 */
final class Url
{
    /** Ruta interna de la aplicación: Url::a('/carnet') → /cumbreAI/carnet */
    public static function a(string $ruta = '/', array $consulta = []): string
    {
        $base = App::peticion()->base();
        $ruta = '/' . ltrim($ruta, '/');
        if ($ruta === '/') {
            $ruta = '';
        }
        $url = $base . $ruta;
        if ($url === '') {
            $url = '/';
        }
        if ($consulta) {
            $url .= '?' . http_build_query($consulta);
        }
        return $url;
    }

    /**
     * URL absoluta. Solo para lo que sale del navegador: los correos y el
     * contenido de los códigos QR, que se leen desde una cámara y no tienen
     * ninguna página de referencia.
     */
    public static function absoluta(string $ruta = '/', array $consulta = []): string
    {
        $configurada = trim((string) Config::obtener('url_base', ''));
        if ($configurada !== '') {
            $url = rtrim($configurada, '/') . '/' . ltrim($ruta, '/');
            return $consulta ? $url . '?' . http_build_query($consulta) : $url;
        }
        return App::peticion()->origen() . self::a($ruta, $consulta);
    }

    /**
     * Recurso estático con marca de versión.
     *
     * El sufijo ?v= viene de la fecha del archivo: al publicar un cambio de CSS
     * el navegador lo pide de nuevo, sin obligar a nadie a vaciar la caché.
     */
    public static function recurso(string $ruta): string
    {
        $ruta = ltrim($ruta, '/');
        $absoluta = RAIZ . '/' . $ruta;
        $version = is_file($absoluta) ? substr((string) filemtime($absoluta), -6) : APP_VERSION;
        return self::a('/' . $ruta) . '?v=' . $version;
    }

    /**
     * Comprueba que un destino de redirección sea interno.
     *
     * Después de identificarse se vuelve a donde la persona quería ir, y ese
     * destino llega por la cadena de consulta. Sin esta comprobación, un enlace
     * como /entrar?destino=https://sitio-falso/ convertiría la plataforma en un
     * trampolín creíble para robar credenciales.
     */
    public static function destinoSeguro(string $destino, string $porDefecto = '/'): string
    {
        $destino = trim($destino);

        if ($destino === '' || $destino[0] !== '/') {
            return $porDefecto;
        }
        // //otro-sitio.com y /\otro-sitio.com son URLs protocolo-relativas.
        if (str_starts_with($destino, '//') || str_starts_with($destino, '/\\')) {
            return $porDefecto;
        }
        if (str_contains($destino, "\n") || str_contains($destino, "\r")) {
            return $porDefecto;
        }

        // Llega con la subcarpeta incluida; se guarda la ruta interna.
        $base = App::peticion()->base();
        if ($base !== '' && str_starts_with($destino, $base . '/')) {
            $destino = substr($destino, strlen($base));
        }
        return $destino === '' ? $porDefecto : $destino;
    }
}
