<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Modelos\Credencial;
use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Guardia;
use App\Nucleo\Peticion;
use App\Nucleo\Qr;
use App\Nucleo\Respuesta;

/**
 * Los contactos que el asistente ha intercambiado.
 *
 * Solo se comparten cuatro campos: nombre, entidad, correo y —si la persona lo
 * autorizó— teléfono. Lo demás no sale de aquí. Los intercambios se pueden
 * revocar, que es lo que exige el derecho de supresión.
 */
final class Contactos
{
    public function listar(Peticion $peticion): void
    {
        $yo = Guardia::personaActual();
        $credencial = Credencial::asegurar((int) $yo['id']);

        Respuesta::vista('publico/contactos', [
            'titulo'    => 'Contactos',
            'pantalla'  => 'contactos',
            'contactos' => $this->de((int) $yo['id']),
            'qr'        => Qr::svg(Credencial::urlQr($credencial), [
                'nivel' => 'Q', 'silencio' => 2, 'clase' => 'qr',
                'titulo' => 'Mi código de contacto',
            ]),
        ]);
    }

    private function de(int $personaId): array
    {
        return Bd::filas(
            'SELECT c.creado_en, p.nombre, p.entidad, p.correo, p.telefono, p.comparte_telefono
               FROM {contacto} c
               JOIN {persona} p ON p.id = c.contacto_id
              WHERE c.persona_id = ? AND c.revocado_en IS NULL
           ORDER BY c.creado_en DESC',
            [$personaId]
        );
    }

    public function privacidad(Peticion $peticion): void
    {
        $yo = Guardia::personaActual();

        Bd::actualizar('persona', [
            'comparte_telefono' => $peticion->marcado('comparte_telefono') ? 1 : 0,
            'en_directorio'     => $peticion->marcado('en_directorio') ? 1 : 0,
        ], 'id = :id', ['id' => $yo['id']]);

        Respuesta::redirigir('/contactos', 'Preferencias de privacidad guardadas.');
    }

    /**
     * Exporta en formato vCard, que abren la agenda del teléfono y Outlook.
     *
     * El archivo se arma en el servidor y no en el navegador para que respete
     * la preferencia de teléfono de cada persona, que es un dato que el cliente
     * no debería tener que decidir.
     */
    public function exportar(Peticion $peticion): void
    {
        $yo = Guardia::personaActual();
        $lista = $this->de((int) $yo['id']);

        if (!$lista) {
            Respuesta::redirigir('/contactos', 'Todavía no tienes contactos para exportar.', 'warn');
        }

        $evento = \App\Nucleo\App::eventoActivo();
        $tarjetas = [];
        foreach ($lista as $c) {
            $lineas = [
                'BEGIN:VCARD',
                'VERSION:3.0',
                'FN:' . $this->escapar((string) $c['nombre']),
                'ORG:' . $this->escapar((string) $c['entidad']),
                'EMAIL;TYPE=WORK:' . $this->escapar((string) $c['correo']),
            ];
            if ((int) $c['comparte_telefono'] === 1 && $c['telefono'] !== '') {
                $lineas[] = 'TEL;TYPE=CELL:' . $this->escapar((string) $c['telefono']);
            }
            $lineas[] = 'NOTE:' . $this->escapar('Contacto intercambiado en ' . ($evento['nombre'] ?? 'el evento'));
            $lineas[] = 'END:VCARD';
            $tarjetas[] = implode("\r\n", $lineas);
        }

        Bitacora::registrar('contactos_exportados', 'persona', (int) $yo['id'], ['total' => count($lista)]);

        Respuesta::descarga('contactos-evento.vcf', implode("\r\n", $tarjetas), 'text/vcard');
    }

    /**
     * Escapado de vCard.
     *
     * La barra invertida, el punto y coma y la coma llevan escape. Y cualquier
     * final de línea se convierte en «\n» literal: un retorno de carro suelto
     * dentro de un nombre —que el formulario acepta sin problema— partía la
     * tarjeta en dos y dejaba inyectar propiedades falsas, un TEL o un EMAIL que
     * no son de esa persona, en la agenda de quien la importara.
     */
    private function escapar(string $valor): string
    {
        $valor = str_replace(["\\", ';', ','], ['\\\\', '\;', '\,'], $valor);
        return (string) preg_replace('/\r\n|\r|\n|\x{2028}|\x{2029}/u', '\n', $valor);
    }
}
