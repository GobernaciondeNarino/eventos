<?php
/**
 * Página de error.
 * @var int $codigo @var string $titulo @var string $mensaje @var array $acciones
 */
defined('EVENTOS_TIC') || exit;

$acciones = $acciones ?? [];
?>
<div class="view view--narrow stack stack--4" style="max-width:560px;margin:auto;padding-top:8vh">
  <span class="kicker">Error <?= e((string) $codigo) ?></span>
  <h1><?= e($titulo) ?></h1>
  <?php if ($mensaje !== ''): ?>
    <p class="lead"><?= e($mensaje) ?></p>
  <?php endif; ?>
  <div class="row">
    <?php if ($acciones): ?>
      <?php foreach ($acciones as $a): ?>
        <a class="btn<?= !empty($a['principal']) ? ' btn--primary' : '' ?>" href="<?= e($a['url']) ?>"><?= e($a['texto']) ?></a>
      <?php endforeach; ?>
    <?php else: ?>
      <a class="btn btn--primary" href="<?= e(u('/')) ?>">Ir al inicio</a>
      <?php /* Antes había aquí un «Volver a intentarlo» apuntando al Referer.
               En un rechazo por testigo inválido, ese Referer es justo la página
               del atacante: el error terminaba invitando a volver a ella. */ ?>
    <?php endif; ?>
  </div>
</div>
