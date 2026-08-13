<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Modelos\Asistencia;
use App\Modelos\Credencial;
use App\Modelos\Persona;
use App\Nucleo\App;
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
 */
final class Carnet
{
    public function ver(Peticion $peticion): void
    {
        $persona = Guardia::personaActual();
        $evento = App::eventoActivo();
        $credencial = Credencial::asegurar((int) $persona['id']);

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
            'historial'   => Asistencia::historial((int) $persona['id'], (int) $persona['evento_id']),
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
}
