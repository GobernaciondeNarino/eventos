<?php
/** Detalle de una exposición. @var array $charla */
defined('EVENTOS_TIC') || exit;
?>
<div class="view view--narrow stack stack--4" style="max-width:680px;margin:auto">

  <a class="mono muted" href="<?= e(u('/agenda', ['dia' => (int) $charla['dia']])) ?>"
     style="font-size:11px;letter-spacing:.12em;text-transform:uppercase">‹ Volver a la agenda</a>

  <div class="stack stack--2">
    <span class="kicker"><?= e($charla['categoria']) ?></span>
    <h1 style="font-size:30px"><?= e($charla['titulo']) ?></h1>
  </div>

  <div class="row">
    <span class="tag">Día <?= e((string) $charla['dia']) ?> · <?= e(fecha((string) $charla['fecha'])) ?> · <?= e(substr((string) $charla['hora_inicio'], 0, 5)) ?></span>
    <span class="tag tag--mute"><?= e((string) $charla['duracion_min']) ?> minutos</span>
    <?php if ($charla['salon'] !== ''): ?>
      <span class="tag tag--mute"><?= e($charla['salon']) ?></span>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card__head"><span>Expositor</span></div>
    <div class="card__body stack stack--2">
      <strong style="font-family:var(--f-display);font-size:18px;color:var(--c-title)"><?= e($charla['expositor']) ?></strong>
      <?php if ($charla['entidad'] !== ''): ?>
        <span class="contact__org"><?= e($charla['entidad']) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <p style="font-size:15px;line-height:1.75;color:var(--c-text)"><?= nl2br(e($charla['detalle'])) ?></p>
</div>
