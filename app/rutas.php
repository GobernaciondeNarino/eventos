<?php
declare(strict_types=1);

/**
 * Tabla de rutas.
 *
 * Toda la superficie que la plataforma expone, en un solo archivo y con el
 * guardia de cada ruta a la vista. Está escrito así a propósito: para revisar
 * qué puede tocar cada rol basta leer esta columna, sin ir clase por clase.
 *
 * Guardias:
 *   (ninguno)              público
 *   'asistente'            exige sesión de asistente
 *   'admin:consulta'       equipo, cualquier rol
 *   'admin:operador'       operador o administrador
 *   'admin:administrador'  solo administrador
 *   'instalar'             solo mientras no haya instalación terminada
 *
 * @var App\Nucleo\Enrutador $enrutador
 */

defined('EVENTOS_TIC') || exit;

use App\Controladores\Acceso;
use App\Controladores\Admin;
use App\Controladores\Carnet;
use App\Controladores\Contactos;
use App\Controladores\Escaneo;
use App\Controladores\Instalador;
use App\Controladores\Medios;
use App\Controladores\Publico;

/* =========================================================================
   Instalación
   ========================================================================= */
$enrutador->ambos('/instalar', [Instalador::class, 'asistente'], 'instalar');

// El resumen final queda accesible aunque la instalación ya esté cerrada: solo
// muestra lo que se hizo y los siguientes pasos, sin ejecutar nada.
$enrutador->get('/instalar/listo', [Instalador::class, 'terminado']);

/* =========================================================================
   Público — no exige identificarse
   ========================================================================= */
$enrutador->get('/', [Publico::class, 'inicio']);
$enrutador->ambos('/preregistro', [Publico::class, 'preregistro']);
$enrutador->get('/agenda', [Publico::class, 'agenda']);
$enrutador->get('/agenda/{id:num}', [Publico::class, 'charla']);
$enrutador->get('/municipios/{departamento:texto}', [Publico::class, 'municipios']);

/* =========================================================================
   Acceso del asistente — correo y código de un solo uso
   ========================================================================= */
$enrutador->ambos('/entrar', [Acceso::class, 'asistente']);
$enrutador->ambos('/entrar/codigo', [Acceso::class, 'codigo']);
$enrutador->post('/salir', [Acceso::class, 'salirAsistente']);

/* =========================================================================
   Códigos QR
   -------------------------------------------------------------------------
   Estas dos rutas son las que se abren desde la cámara del teléfono, sin
   ningún contexto previo. Si no hay sesión no devuelven un error: llevan al
   acceso que corresponde y, al terminar, continúan con lo que la persona
   venía a hacer.
   ========================================================================= */

// Código de la jornada, pegado en la entrada. Registra el ingreso propio.
$enrutador->get('/d/{token:token}', [Escaneo::class, 'codigoDelDia'], 'asistente');

// Carnet de una persona. Quién lo escanea decide qué pasa:
// el equipo sella su ingreso, otro asistente intercambia contacto.
$enrutador->get('/c/{token:token}', [Escaneo::class, 'carnetAjeno']);
$enrutador->post('/c/{token:token}/contacto', [Escaneo::class, 'guardarContacto'], 'asistente');
$enrutador->post('/c/{token:token}/asistencia', [Escaneo::class, 'sellarAsistencia'], 'admin:operador');

/* =========================================================================
   Asistente identificado
   ========================================================================= */
$enrutador->get('/carnet', [Carnet::class, 'ver'], 'asistente');
$enrutador->get('/carnet/imprimir', [Carnet::class, 'imprimir'], 'asistente');
$enrutador->get('/checkin', [Escaneo::class, 'pantallaCheckin'], 'asistente');
$enrutador->get('/contactos', [Contactos::class, 'listar'], 'asistente');
$enrutador->post('/contactos/privacidad', [Contactos::class, 'privacidad'], 'asistente');
$enrutador->get('/contactos/exportar', [Contactos::class, 'exportar'], 'asistente');

/* =========================================================================
   Equipo organizador
   ========================================================================= */
$enrutador->ambos('/admin/entrar', [Acceso::class, 'equipo']);
$enrutador->ambos('/admin/verificar', [Acceso::class, 'verificarSegundoFactor']);
$enrutador->ambos('/admin/activar-2fa', [Acceso::class, 'activarSegundoFactor']);
$enrutador->post('/admin/salir', [Acceso::class, 'salirEquipo']);

$enrutador->get('/admin', [Admin::class, 'panel'], 'admin:consulta');

$enrutador->get('/admin/escaner', [Admin::class, 'escaner'], 'admin:operador');
$enrutador->post('/admin/escaner/buscar', [Admin::class, 'buscarPersona'], 'admin:operador');

$enrutador->get('/admin/registros', [Admin::class, 'registros'], 'admin:consulta');
$enrutador->get('/admin/registros/exportar', [Admin::class, 'exportar'], 'admin:consulta');

$enrutador->get('/admin/qr-dias', [Admin::class, 'codigosDia'], 'admin:operador');
$enrutador->get('/admin/qr-dias/{numero:num}/imprimir', [Admin::class, 'imprimirCodigo'], 'admin:operador');
$enrutador->post('/admin/qr-dias/rotar', [Admin::class, 'rotarCodigo'], 'admin:administrador');

$enrutador->get('/admin/expositores', [Admin::class, 'expositores'], 'admin:administrador');
$enrutador->post('/admin/expositores/decidir', [Admin::class, 'decidirPropuesta'], 'admin:administrador');

$enrutador->get('/admin/organizadores', [Admin::class, 'organizadores'], 'admin:administrador');
$enrutador->post('/admin/organizadores/crear', [Admin::class, 'crearUsuario'], 'admin:administrador');
$enrutador->post('/admin/organizadores/estado', [Admin::class, 'cambiarEstadoUsuario'], 'admin:administrador');

$enrutador->get('/admin/eventos', [Admin::class, 'eventos'], 'admin:administrador');
$enrutador->post('/admin/eventos/crear', [Admin::class, 'crearEvento'], 'admin:administrador');
$enrutador->post('/admin/eventos/activar', [Admin::class, 'activarEvento'], 'admin:administrador');

$enrutador->get('/admin/identidad', [Admin::class, 'identidad'], 'admin:administrador');
$enrutador->post('/admin/identidad', [Admin::class, 'guardarIdentidad'], 'admin:administrador');

/* =========================================================================
   Archivos subidos
   -------------------------------------------------------------------------
   Nunca se sirven directamente desde el disco: pasan por PHP para que el tipo
   lo decida el servidor y no la extensión del archivo. Ver docs/SEGURIDAD.md.
   ========================================================================= */
$enrutador->get('/medios/logo/{evento:num}', [Medios::class, 'logo']);
$enrutador->get('/medios/qr/carnet.svg', [Medios::class, 'qrCarnet'], 'asistente');
$enrutador->get('/medios/qr/dia/{numero:num}.svg', [Medios::class, 'qrDia'], 'admin:operador');
