<?php
/** Página de error. @var int $codigo @var string $titulo @var string $mensaje */
defined('EVENTOS_TIC') || exit;
?>
<div class="view view--narrow stack stack--4" style="max-width:560px;margin:auto;padding-top:8vh">
  <span class="kicker">Error <?= e((string) $codigo) ?></span>
  <h1><?= e($titulo) ?></h1>
  <?php if ($mensaje !== ''): ?>
    <p class="lead"><?= e($mensaje) ?></p>
  <?php endif; ?>
  <div class="row">
    <a class="btn btn--primary" href="<?= e(u('/')) ?>">Ir al inicio</a>
    <?php if ($codigo === 419): ?>
      <a class="btn" href="<?= e($_SERVER['HTTP_REFERER'] ?? u('/')) ?>">Volver a intentarlo</a>
    <?php endif; ?>
  </div>
</div>
