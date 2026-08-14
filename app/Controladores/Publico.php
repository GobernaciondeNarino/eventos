<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Datos;
use App\Modelos\Credencial;
use App\Modelos\Evento;
use App\Modelos\Persona;
use App\Nucleo\App;
use App\Nucleo\Bd;
use App\Nucleo\Correo;
use App\Nucleo\Guardia;
use App\Nucleo\Limite;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Url;

/**
 * Pantallas abiertas: portada, preregistro y agenda.
 */
final class Publico
{
    public function inicio(Peticion $peticion): void
    {
        $evento = App::eventoExigido();

        Respuesta::vista('publico/inicio', [
            'titulo'   => 'Ingreso',
            'pantalla' => 'ingreso',
            'jornadas' => Evento::jornadas((int) $evento['id']),
        ]);
    }

    /* =====================================================================
       Preregistro
       ===================================================================== */

    public function preregistro(Peticion $peticion): void
    {
        $evento = App::eventoExigido();

        $yo = Guardia::personaActual();
        $errores = [];

        // Quien ya está identificado ve sus datos y puede corregirlos.
        $valores = $yo ? [
            'correo'         => $yo['correo'],
            'nombre'         => $yo['nombre'],
            'tipo_documento' => $yo['tipo_documento'],
            'documento'      => Persona::documento($yo),
            'telefono'       => $yo['telefono'],
            'entidad'        => $yo['entidad'],
            'departamento'   => $yo['departamento'],
            'municipio'      => $yo['municipio'],
            'rol'            => $yo['rol'],
        ] + Persona::caracterizacion((int) $yo['id']) : [];

        if ($peticion->esPost()) {
            $valores = $this->valoresEnviados($peticion);
            $errores = $this->validarPreregistro($valores, $peticion, $yo !== null);

            if (!$errores) {
                Limite::exigir('preregistro_ip', $peticion->ip());
                // Se anota el intento aunque salga bien: aquí el abuso consiste
                // en registrar muchas veces con éxito, no en fallar.
                Limite::registrar('preregistro_ip', $peticion->ip());
                try {
                    $resultado = Persona::registrar((int) $evento['id'], $valores);
                    $this->guardarPropuesta((int) $resultado['id'], $valores, $peticion);

                    // Queda con sesión abierta: acaba de demostrar que controla
                    // ese correo solo si venía identificado; si no, el enlace
                    // del carnet le llega al buzón.
                    if ($yo === null) {
                        Sesion::abrir('asistente', (int) $resultado['id']);
                        Correo::carnetEmitido(
                            (string) $valores['correo'],
                            (string) $valores['nombre'],
                            (string) $evento['nombre'],
                            Url::absoluta('/carnet')
                        );
                    }

                    Respuesta::redirigir('/carnet', $resultado['nueva']
                        ? 'Preregistro completo. Este es tu carnet.'
                        : 'Actualizamos tus datos.');
                } catch (\DomainException $e) {
                    $errores['documento'] = $e->getMessage();
                } catch (\Throwable $e) {
                    \App\Nucleo\Registro::excepcion($e);
                    $errores['general'] = 'No se pudo guardar el registro. Inténtalo de nuevo en un momento.';
                }
            }
        }

        Respuesta::vista('publico/preregistro', [
            'titulo'        => 'Preregistro',
            'pantalla'      => 'preregistro',
            'valores'       => $valores,
            'errores'       => $errores,
            'departamentos' => Datos::departamentos(),
            'municipios'    => Datos::municipiosDe((string) ($valores['departamento'] ?? '')),
            'categorias'    => Datos::CATEGORIAS,
            'jornadas'      => Evento::jornadas((int) $evento['id']),
            'yaRegistrado'  => $yo !== null,
        ]);
    }

    private function valoresEnviados(Peticion $peticion): array
    {
        return [
            'correo'         => mb_strtolower($peticion->campo('correo')),
            'nombre'         => $peticion->campo('nombre'),
            'tipo_documento' => $peticion->campo('tipo_documento', 'CC'),
            'documento'      => $peticion->campo('documento'),
            'telefono'       => $peticion->campo('telefono'),
            'rol'            => $peticion->campo('rol', 'participante'),
            'genero'         => $peticion->campo('genero'),
            'discapacidad'   => $peticion->campo('discapacidad'),
            'etnia'          => $peticion->campo('etnia'),
            'entidad'        => $peticion->campo('entidad'),
            'rango_edad'     => $peticion->campo('rango_edad'),
            'departamento'   => $peticion->campo('departamento'),
            'municipio'      => $peticion->campo('municipio'),
            'expositor'      => $peticion->marcado('expositor'),
            'tema'           => $peticion->campo('tema'),
            'categoria'      => $peticion->campo('categoria'),
            'detalle'        => $peticion->campo('detalle'),
            'dia_preferido'  => $peticion->entero('dia_preferido', 1),
            'duracion'       => $peticion->entero('duracion', 40),
            'requerimientos' => $peticion->campo('requerimientos'),
        ];
    }

    /**
     * Validación del lado del servidor.
     *
     * La misma que hace el navegador, otra vez. Lo del navegador es comodidad
     * para quien diligencia; esto es lo que de verdad protege la base de datos,
     * porque un envío puede llegar sin pasar por ninguna pantalla.
     */
    private function validarPreregistro(array $v, Peticion $peticion, bool $identificado): array
    {
        $errores = [];

        if (!$identificado && !filter_var($v['correo'], FILTER_VALIDATE_EMAIL)) {
            $errores['correo'] = 'Escribe un correo válido.';
        }
        if (mb_strlen(trim($v['nombre'])) < 5) {
            $errores['nombre'] = 'Escribe el nombre completo.';
        }
        if (mb_strlen($v['nombre']) > 160) {
            $errores['nombre'] = 'El nombre es demasiado largo.';
        }

        $documento = Persona::normalizarDocumento($v['documento']);
        if (strlen($documento) < 5 || strlen($documento) > 12) {
            $errores['documento'] = 'El número de identificación debe tener entre 5 y 12 dígitos.';
        }

        if ($v['telefono'] !== '') {
            $digitos = preg_replace('/\D/', '', $v['telefono']) ?? '';
            if (strlen($digitos) < 7 || strlen($digitos) > 15) {
                $errores['telefono'] = 'Revisa el número de teléfono.';
            }
        }

        if (!in_array($v['tipo_documento'], array_keys(Datos::TIPOS_DOCUMENTO), true)) {
            $errores['tipo_documento'] = 'Tipo de documento no válido.';
        }
        if (!in_array($v['rol'], Persona::ROLES, true)) {
            $errores['rol'] = 'Perfil no válido.';
        }
        if ($v['departamento'] !== '' && !in_array($v['departamento'], Datos::departamentos(), true)) {
            $errores['departamento'] = 'Departamento no válido.';
        }
        if ($v['municipio'] !== '' && !Datos::municipioValido($v['departamento'], $v['municipio'])) {
            $errores['municipio'] = 'Ese municipio no corresponde al departamento elegido.';
        }
        if ($v['rango_edad'] !== '' && !in_array($v['rango_edad'], Datos::RANGOS_EDAD, true)) {
            $errores['rango_edad'] = 'Rango de edad no válido.';
        }

        if ($v['expositor']) {
            if (mb_strlen(trim($v['tema'])) < 5) {
                $errores['tema'] = 'Describe el tema de tu exposición.';
            }
            if (!in_array($v['categoria'], Datos::CATEGORIAS, true)) {
                $errores['categoria'] = 'Elige una categoría de la lista.';
            }
            if (mb_strlen(trim($v['detalle'])) < 30) {
                $errores['detalle'] = 'Amplía el detalle: al menos 30 caracteres.';
            }
            if (mb_strlen($v['detalle']) > 600) {
                $errores['detalle'] = 'El detalle no puede pasar de 600 caracteres.';
            }
        }

        if (!$identificado && !$peticion->marcado('habeas')) {
            $errores['habeas'] = 'Debes autorizar el tratamiento de datos para continuar.';
        }

        return $errores;
    }

    private function guardarPropuesta(int $personaId, array $v, Peticion $peticion): void
    {
        if (empty($v['expositor'])) {
            return;
        }

        $existente = Bd::fila(
            "SELECT id, estado FROM {propuesta} WHERE persona_id = ? ORDER BY id DESC LIMIT 1",
            [$personaId]
        );

        $campos = [
            'titulo'         => mb_substr(trim($v['tema']), 0, 200),
            'categoria'      => mb_substr($v['categoria'], 0, 80),
            'detalle'        => mb_substr(trim($v['detalle']), 0, 600),
            'dia_preferido'  => max(1, min(30, (int) $v['dia_preferido'])),
            'duracion_min'   => in_array((int) $v['duracion'], [20, 40, 60], true) ? (int) $v['duracion'] : 40,
            'requerimientos' => mb_substr($v['requerimientos'], 0, 255),
        ];

        // Una propuesta ya aprobada no se pisa desde el formulario público: el
        // horario y el salón ya están publicados en la agenda.
        if ($existente && $existente['estado'] === 'aprobada') {
            return;
        }

        if ($existente) {
            Bd::actualizar('propuesta', $campos + ['estado' => 'pendiente'], 'id = :id', ['id' => $existente['id']]);
        } else {
            Bd::insertar('propuesta', $campos + ['persona_id' => $personaId]);
        }
    }

    /** Municipios de un departamento, para el selector dependiente. */
    public function municipios(Peticion $peticion, array $parametros): void
    {
        $departamento = (string) $parametros['departamento'];
        Respuesta::json([
            'departamento' => $departamento,
            'municipios'   => Datos::municipiosDe($departamento),
        ]);
    }

    /* =====================================================================
       Agenda
       ===================================================================== */

    public function agenda(Peticion $peticion): void
    {
        $evento = App::eventoExigido();

        $jornadas = Evento::jornadas((int) $evento['id']);
        $dia = $peticion->entero('dia', (int) ($jornadas[0]['numero'] ?? 1));
        $texto = $peticion->query('q');
        $categoria = $peticion->query('categoria');

        $donde = ['d.evento_id = :evento', 'c.publicada = 1'];
        $parametros = ['evento' => (int) $evento['id']];

        if ($dia > 0) {
            $donde[] = 'd.numero = :dia';
            $parametros['dia'] = $dia;
        }
        if ($texto !== '') {
            $donde[] = '(pr.titulo LIKE :texto OR p.nombre LIKE :texto OR p.entidad LIKE :texto OR pr.categoria LIKE :texto)';
            $parametros['texto'] = '%' . str_replace(['%', '_'], ['\%', '\_'], $texto) . '%';
        }
        if ($categoria !== '') {
            $donde[] = 'pr.categoria = :categoria';
            $parametros['categoria'] = $categoria;
        }

        $charlas = Bd::filas(
            'SELECT c.id, c.hora_inicio, c.salon, d.numero AS dia, d.fecha,
                    pr.titulo, pr.categoria, pr.detalle, pr.duracion_min,
                    p.nombre AS expositor, p.entidad
               FROM {charla} c
               JOIN {propuesta} pr ON pr.id = c.propuesta_id
               JOIN {persona} p ON p.id = pr.persona_id
               JOIN {evento_dia} d ON d.id = c.evento_dia_id
              WHERE ' . implode(' AND ', $donde) . '
           ORDER BY d.numero, c.hora_inicio',
            $parametros
        );

        $categorias = array_column(
            Bd::filas(
                'SELECT DISTINCT pr.categoria
                   FROM {charla} c
                   JOIN {propuesta} pr ON pr.id = c.propuesta_id
                   JOIN {evento_dia} d ON d.id = c.evento_dia_id
                  WHERE d.evento_id = ? AND c.publicada = 1
               ORDER BY pr.categoria',
                [(int) $evento['id']]
            ),
            'categoria'
        );

        Respuesta::vista('publico/agenda', [
            'titulo'     => 'Agenda',
            'pantalla'   => 'agenda',
            'jornadas'   => $jornadas,
            'dia'        => $dia,
            'charlas'    => $charlas,
            'categorias' => $categorias,
            'q'          => $texto,
            'categoria'  => $categoria,
        ]);
    }

    public function charla(Peticion $peticion, array $parametros): void
    {
        $charla = Bd::fila(
            'SELECT c.*, d.numero AS dia, d.fecha, pr.titulo, pr.categoria, pr.detalle,
                    pr.duracion_min, p.nombre AS expositor, p.entidad
               FROM {charla} c
               JOIN {propuesta} pr ON pr.id = c.propuesta_id
               JOIN {persona} p ON p.id = pr.persona_id
               JOIN {evento_dia} d ON d.id = c.evento_dia_id
              WHERE c.id = ? AND c.publicada = 1',
            [(int) $parametros['id']]
        );

        if (!$charla) {
            Respuesta::error(404, 'Exposición no encontrada', 'Puede que se haya retirado de la agenda.');
        }

        Respuesta::vista('publico/charla', [
            'titulo'   => (string) $charla['titulo'],
            'pantalla' => 'agenda',
            'charla'   => $charla,
        ]);
    }
}
