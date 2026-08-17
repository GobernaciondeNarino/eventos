<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Modelos\Asistencia;
use App\Modelos\Credencial;
use App\Modelos\Persona;
use App\Nucleo\App;
use App\Nucleo\Autenticacion;
use App\Nucleo\Dispositivo;
use App\Nucleo\Guardia;
use App\Nucleo\Peticion;
use App\Nucleo\Qr;
use App\Nucleo\Respuesta;

/**
 * El carnet digital de quien tiene la sesión abierta.
 *
 * Solo se muestra el propio: no hay ninguna ruta que permita ver el carnet
 * completo de otra persona. El equipo, al escanear, ve la ficha de
 * acreditación, que es otra cosa y queda registrada en la bitácora.
 *
 * En el carnet hay dos códigos y no uno, y la diferencia importa:
 *
 *   · el de contacto (/c/…) es el que se enseña. Escanearlo no identifica a
 *     nadie: intercambia datos, o acredita si quien mira es del equipo.
 *   · el de acceso (/entrar/qr/…) abre la sesión de su dueño en el teléfono que
 *     lo escanee. Es una llave, y así se rotula.
 */
final class Carnet
{
    public function ver(Peticion $peticion): void
    {
        $persona = Guardia::personaActual();
        $evento = App::eventoActivo();
        $credencial = Credencial::asegurar((int) $persona['id']);

        // El QR de acceso solo se pinta si ese método está encendido: si no,
        // escanearlo llevaría a un 403 y la persona no entendería por qué.
        $conAcceso = Autenticacion::activo('qr');
        $urlAcceso = $conAcceso ? Credencial::urlAcceso((int) $persona['id']) : '';

        Respuesta::vista('publico/carnet', [
            'titulo'     => 'Mi carnet',
            'pantalla'   => 'carnet',
            'credencial' => $credencial,
            'documento'  => Persona::documento($persona),
            'qr'         => Qr::svg(Credencial::urlQr($credencial), [
                // Nivel alto: el carnet se doblará, se rayará y se fotografiará
                // en la puerta con poca luz.
                'nivel'    => 'Q',
                'silencio' => 2,
                'oscuro'   => '#08151F',
                'clase'    => 'qr',
                'titulo'   => 'Código de mi credencial',
            ]),
            'contenidoQr' => Credencial::urlQr($credencial),
            'qrAcceso'    => $conAcceso ? Qr::svg($urlAcceso, [
                'nivel'    => 'Q',
                'silencio' => 2,
                'oscuro'   => '#08151F',
                'clase'    => 'qr',
                'titulo'   => 'Mi código de acceso',
            ]) : '',
            'urlAcceso'    => $urlAcceso,
            'historial'    => Asistencia::historial((int) $persona['id'], (int) $persona['evento_id']),
            'dispositivos' => Dispositivo::de((int) $persona['id']),
        ]);
    }

    /** Versión para imprimir: las dos caras, sin navegación. */
    public function imprimir(Peticion $peticion): void
    {
        $persona = Guardia::personaActual();
        $credencial = Credencial::asegurar((int) $persona['id']);

        Respuesta::vista('publico/carnet-imprimir', [
            'titulo'       => 'Carnet para imprimir',
            'credencial'   => $credencial,
            'documento'    => Persona::documento($persona),
            'qr'           => Qr::svg(Credencial::urlQr($credencial), [
                'nivel' => 'Q', 'silencio' => 2, 'clase' => 'qr',
                'titulo' => 'Código de la credencial',
            ]),
            'sinPlantilla' => true,
        ]);
    }

    /**
     * Cierra la sesión en todos los teléfonos recordados.
     *
     * Es el botón que hace falta cuando alguien pierde el teléfono: la marca de
     * dispositivo dura seis meses y sin esto no habría forma de retirarla.
     * Además se cambia el token del QR de acceso, porque quien tenga una foto
     * del carnet podría seguir entrando con él.
     */
    public function cerrarDispositivos(Peticion $peticion): void
    {
        $persona = Guardia::personaActual();
        $id = (int) $persona['id'];

        $cuantos = Dispositivo::olvidarTodos($id);
        Persona::regenerarTokenDeAcceso($id);
        \App\Nucleo\Sesion::cerrarTodasDe('asistente', $id);
        \App\Nucleo\Bitacora::registrar('dispositivos_cerrados', 'persona', $id, [
            'cuantos' => $cuantos,
        ]);

        Respuesta::redirigir('/entrar',
            'Se cerró la sesión en ' . $cuantos . ' dispositivo' . ($cuantos === 1 ? '' : 's')
            . ' y tu QR de acceso anterior dejó de servir.');
    }
}
