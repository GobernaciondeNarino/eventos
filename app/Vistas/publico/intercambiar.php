<?php
/**
 * Confirmación de intercambio de contacto entre dos asistentes.
 * @var array $otra @var string $token @var bool $yaEs
 */
defined('EVENTOS_TIC') || exit;
?>
<div class="view view--narrow stack stack--5" style="max-width:520px;margin:auto">

  <div class="stack stack--2">
    <span class="kicker">Red del evento</span>
    <h1><?= $yaEs ? 'Ya tienes este contacto' : 'Intercambiar contacto' ?></h1>
  </div>

  <div class="card">
    <div class="card__head"><span>Datos que se comparten</span></div>
    <div class="card__body stack stack--4">

      <article class="contact" style="border:0;padding:0;background:none">
        <span class="contact__ini" aria-hidden="true"><?= e(iniciales($otra['nombre'])) ?></span>
        <div class="stack" style="gap:3px;min-width:0">
          <strong class="contact__name"><?= e($otra['nombre']) ?></strong>
          <?php if ($otra['entidad'] !== ''): ?>
            <span class="contact__org"><?= e($otra['entidad']) ?></span>
          <?php endif; ?>
          <span class="contact__data"><?= e($otra['correo']) ?></span>
          <?php if ($otra['telefono'] !== ''): ?>
            <span class="contact__data"><?= e($otra['telefono']) ?></span>
          <?php endif; ?>
        </div>
      </article>

      <div class="notice">
        <span class="notice__icon" aria-hidden="true">◆</span>
        <span>
          El intercambio es en las dos direcciones: esta persona también recibirá tus
          datos. Se comparten cuatro campos —nombre, entidad, correo y teléfono si lo
          autorizaste—; la identificación y la caracterización nunca.
        </span>
      </div>

      <?php if ($yaEs): ?>
        <div class="row">
          <a class="btn btn--primary" href="<?= e(u('/contactos')) ?>">Ver mis contactos</a>
        </div>
      <?php else: ?>
        <form method="post" action="<?= e(u('/c/' . $token . '/contacto')) ?>" class="row">
          <?= testigo() ?>
          <button class="btn btn--primary" type="submit">Agregar a mis contactos</button>
          <a class="btn" href="<?= e(u('/contactos')) ?>">Ahora no</a>
        </form>
      <?php endif; ?>

    </div>
  </div>
</div>
