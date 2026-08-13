<?php
/**
 * Plantilla general.
 *
 * Las variables de color del evento se imprimen aquí, en línea, antes de
 * cualquier contenido: así no hay un destello con la paleta por defecto antes
 * de que llegue la del evento.
 *
 * @var string      $contenido
 * @var array|null  $evento
 * @var array       $tema
 * @var array|null  $persona   asistente con sesión abierta
 * @var array|null  $usuario   miembro del equipo con sesión abierta
 * @var array|null  $aviso
 * @var string      $titulo
 * @var string      $pantalla
 */

defined('EVENTOS_TIC') || exit;

use App\Nucleo\Tema;

$sinPlantilla = $sinPlantilla ?? false;
$pantalla = $pantalla ?? '';
$nombreEvento = $evento['nombre'] ?? 'Plataforma de Eventos TIC';
?><!DOCTYPE html>
<html lang="es" data-preset="<?= e($tema['preset']) ?>" data-tipografia="<?= e($tema['tipografia']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark light">
<?php if (!empty($noIndexar) || $usuario !== null): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<title><?= e(($titulo ?? '') !== '' ? $titulo . ' · ' . $nombreEvento : $nombreEvento) ?></title>
<link rel="icon" href="<?= e(recurso('assets/img/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(recurso('assets/fonts/fuentes.css')) ?>">
<link rel="stylesheet" href="<?= e(recurso('assets/css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(recurso('assets/css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(recurso('assets/css/components.css')) ?>">
<link rel="stylesheet" href="<?= e(recurso('assets/css/print.css')) ?>">
<style><?= Tema::estilo($tema) /* solo hexadecimales validados */ ?></style>
</head>
<body data-pantalla="<?= e($pantalla) ?>" data-base="<?= e(u('/')) ?>">

<a class="skip-link" href="#contenido">Saltar al contenido</a>

<?php if ($sinPlantilla): ?>
  <?= $contenido /* la vista trae su propio armazón */ ?>
<?php else: ?>
<div class="app">
  <?php require __DIR__ . '/parciales/barra-lateral.php'; ?>

  <div class="app__body">
    <?php require __DIR__ . '/parciales/barra-superior.php'; ?>

    <main class="main" id="contenido">
      <?= $contenido ?>
    </main>
  </div>
</div>

<?php require __DIR__ . '/parciales/nav-movil.php'; ?>
<?php endif; ?>

<?php if (!empty($aviso)): ?>
<div class="toast toast--<?= e($aviso['tipo']) ?>" role="status" aria-live="polite" data-autocerrar="5000">
  <?= e($aviso['mensaje']) ?>
</div>
<?php endif; ?>

<script src="<?= e(recurso('assets/js/app.js')) ?>" defer></script>
<?php foreach (($guiones ?? []) as $guion): ?>
<script src="<?= e(recurso('assets/js/' . $guion)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
