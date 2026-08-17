<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Modelos\Credencial;
use App\Modelos\Evento;
use App\Nucleo\App;
use App\Nucleo\Guardia;
use App\Nucleo\Peticion;
use App\Nucleo\Qr;
use App\Nucleo\Respuesta;
use App\Nucleo\Tema;
use App\Nucleo\Url;

/**
 * Archivos que no son páginas: logos y códigos QR sueltos.
 *
 * Los archivos subidos nunca se sirven directamente desde el disco. Pasan por
 * aquí para que el tipo de contenido lo decida el servidor y no la extensión
 * del archivo, y para que salgan con una política que impide que un SVG con
 * script dentro se ejecute en el origen del sitio.
 */
final class Medios
{
    public function logo(Peticion $peticion, array $parametros): void
    {
        $eventoId = (int) $parametros['evento'];
        $tema = Tema::del($eventoId);

        if ($tema['logo'] === '') {
            Respuesta::error(404, 'Sin logo', 'Este evento no tiene logo cargado.');
        }

        // basename() corta cualquier intento de subir por el árbol de directorios,
        // aunque el nombre lo pone el servidor y no debería llegar nada raro.
        $ruta = RAIZ . '/almacen/logos/' . basename((string) $tema['logo']);
        $tipo = $tema['logo_tipo'] !== '' ? (string) $tema['logo_tipo'] : 'application/octet-stream';

        Respuesta::archivo($ruta, $tipo, true);
    }

    /**
     * La fotografía de una persona.
     *
     * La ve su dueño y la ve el equipo organizador. Nadie más: el id es un
     * número correlativo, así que sin esta comprobación bastaría con contar
     * desde uno para descargar la cara de todos los asistentes.
     */
    public function foto(Peticion $peticion, array $parametros): void
    {
        $id = (int) $parametros['persona'];

        $yo = Guardia::personaActual();
        $equipo = Guardia::equipoOperativo();
        $esDelEquipo = $equipo !== null && Guardia::tieneRol($equipo, 'consulta');

        if (($yo === null || (int) $yo['id'] !== $id) && !$esDelEquipo) {
            Respuesta::error(404, 'Sin fotografía', 'No hay ninguna imagen en esa dirección.');
        }

        $persona = ($yo !== null && (int) $yo['id'] === $id)
            ? $yo
            : \App\Modelos\Persona::porId($id);

        $archivo = (string) ($persona['foto'] ?? '');
        if ($persona === null || $archivo === '') {
            Respuesta::error(404, 'Sin fotografía', 'Esta persona no tiene foto cargada.');
        }

        $tipo = (string) ($persona['foto_tipo'] ?? '') ?: 'image/jpeg';

        // basename() aunque el nombre lo ponga el servidor: es la barrera que
        // impide que una fila manipulada en la base saque archivos del árbol.
        Respuesta::archivo(RAIZ . '/almacen/fotos/' . basename($archivo), $tipo, true);
    }

    /** QR del carnet propio, como archivo SVG suelto. */
    public function qrCarnet(Peticion $peticion): void
    {
        $persona = Guardia::personaActual();
        $credencial = Credencial::asegurar((int) $persona['id']);

        $svg = Qr::svg(Credencial::urlQr($credencial), [
            'nivel' => 'Q', 'silencio' => 4, 'titulo' => 'Código de mi credencial',
        ]);

        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $svg;
        exit;
    }

    /**
     * El QR personal de acceso, como archivo suelto.
     *
     * Es el que abre la sesión de su dueño, así que sale con «no-store»: no
     * puede quedarse en la caché de un proxy compartido ni en el disco del
     * teléfono como una imagen más.
     */
    public function qrAcceso(Peticion $peticion): void
    {
        if (!\App\Nucleo\Autenticacion::activo('qr')) {
            Respuesta::error(404, 'Sin código de acceso',
                'La organización no tiene encendido el acceso por QR.');
        }

        $persona = Guardia::personaActual();

        $svg = Qr::svg(Credencial::urlAcceso((int) $persona['id']), [
            'nivel' => 'Q', 'silencio' => 4, 'titulo' => 'Mi código de acceso',
        ]);

        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $svg;
        exit;
    }

    /** QR de una jornada, para incrustar o descargar. */
    public function qrDia(Peticion $peticion, array $parametros): void
    {
        $evento = App::eventoExigido();
        $jornada = Evento::jornada((int) $evento['id'], (int) $parametros['numero']);
        if (!$jornada) {
            Respuesta::error(404, 'Jornada no encontrada', 'Ese día no existe en este evento.');
        }

        $svg = Qr::svg(Url::absoluta('/d/' . $jornada['token']), [
            'nivel' => 'M', 'silencio' => 4,
            'titulo' => 'Código de acceso del día ' . $jornada['numero'],
        ]);

        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $svg;
        exit;
    }
}
