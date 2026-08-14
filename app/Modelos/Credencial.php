<?php
declare(strict_types=1);

namespace App\Modelos;

defined('EVENTOS_TIC') || exit;

use App\Nucleo\Bd;
use App\Nucleo\Cripto;
use App\Nucleo\Url;

/**
 * El carnet.
 *
 * Lo único que viaja en el código QR es el token: 128 bits aleatorios. Nada de
 * nombre, documento ni correo. Quien fotografíe un carnet ajeno —cosa que pasa
 * todo el tiempo en un evento— no obtiene ningún dato por sí mismo: tiene que
 * preguntarle al servidor, y el servidor decide qué entrega según quién esté
 * identificado al otro lado.
 */
final class Credencial
{
    public static function dePersona(int $personaId): ?array
    {
        return Bd::fila('SELECT * FROM {credencial} WHERE persona_id = ?', [$personaId]);
    }

    public static function porToken(string $token): ?array
    {
        return Bd::fila(
            'SELECT c.*, p.*, c.id AS credencial_id, p.id AS persona_id
               FROM {credencial} c
               JOIN {persona} p ON p.id = c.persona_id
              WHERE c.token = ? AND c.revocada_en IS NULL',
            [$token]
        );
    }

    /** Crea la credencial si la persona todavía no tiene. */
    public static function asegurar(int $personaId): array
    {
        $existente = self::dePersona($personaId);
        if ($existente) {
            return $existente;
        }

        $anio = date('Y');

        // El número visible es correlativo por año; el token es lo aleatorio.
        // Se separan porque el correlativo se dicta por teléfono cuando algo
        // falla, y un token de 32 caracteres no se dicta.
        //
        // Se toma el mayor emitido y no COUNT(*)+1: contar da un número que ya
        // existe en cuanto se borra una credencial —basta con que se elimine una
        // persona— y entonces choca con la llave única y el preregistro
        // responde un 500. Aun así puede haber una carrera entre dos altas
        // simultáneas, así que se reintenta.
        for ($intento = 0; $intento < 5; $intento++) {
            $ultimo = (string) (Bd::valor(
                "SELECT MAX(codigo) FROM {credencial} WHERE codigo LIKE ?",
                ['STIC-' . $anio . '-%']
            ) ?? '');
            $siguiente = $ultimo === '' ? 1 : ((int) substr($ultimo, -6)) + 1;

            try {
                Bd::insertar('credencial', [
                    'persona_id' => $personaId,
                    'codigo'     => sprintf('STIC-%s-%06d', $anio, $siguiente + $intento),
                    'token'      => Cripto::token(16),
                ]);
                break;
            } catch (\PDOException $e) {
                // 23000 es la violación de una restricción de unicidad: otra
                // alta se llevó ese número. Se prueba con el siguiente.
                if ($e->getCode() !== '23000' || $intento === 4) {
                    throw $e;
                }
            }
        }

        return self::dePersona($personaId) ?? [];
    }

    /** Contenido del QR del carnet: una URL absoluta con el token. */
    public static function urlQr(array $credencial): string
    {
        return Url::absoluta('/c/' . $credencial['token']);
    }

    public static function revocar(int $personaId): void
    {
        Bd::ejecutar(
            'UPDATE {credencial} SET revocada_en = NOW() WHERE persona_id = ? AND revocada_en IS NULL',
            [$personaId]
        );
    }

    /**
     * Vuelve a emitir el carnet con un token nuevo.
     * Para cuando alguien pierde el teléfono o publica su carnet por error.
     */
    public static function reemitir(int $personaId): array
    {
        Bd::ejecutar('UPDATE {credencial} SET token = ? WHERE persona_id = ?', [
            Cripto::token(16),
            $personaId,
        ]);
        return self::dePersona($personaId) ?? [];
    }

    /**
     * Los cuatro datos que se comparten al intercambiar contacto.
     *
     * Deliberadamente cortos: nombre, entidad, correo y —solo si la persona lo
     * autorizó— teléfono. La identificación y la caracterización no salen de
     * aquí nunca.
     */
    public static function datosDeContacto(array $persona): array
    {
        return [
            'nombre'   => (string) $persona['nombre'],
            'entidad'  => (string) $persona['entidad'],
            'correo'   => (string) $persona['correo'],
            'telefono' => ((int) $persona['comparte_telefono'] === 1) ? (string) $persona['telefono'] : '',
        ];
    }
}
