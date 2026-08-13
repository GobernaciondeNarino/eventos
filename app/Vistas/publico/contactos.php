<?php
/** Contactos intercambiados. @var array $contactos @var string $qr @var array $persona */
defined('EVENTOS_TIC') || exit;
?>
<div class="view view--medium split" style="align-items:start">

  <div class="stack stack--4">
    <div class="stack stack--2">
      <span class="kicker">Red del evento</span>
      <h1>Contactos</h1>
      <p class="lead" style="max-width:56ch">
        Al escanear el carnet de otra persona —o cuando ella escanea el tuyo— se
        intercambian cuatro datos: nombre, entidad, correo y teléfono.
      </p>
    </div>

    <div class="stack stack--2">
      <?php if (!$contactos): ?>
        <div class="empty">Todavía no has intercambiado contactos</div>
      <?php else: ?>
        <?php foreach ($contactos as $c): ?>
          <article class="contact">
            <span class="contact__ini" aria-hidden="true"><?= e(iniciales((string) $c['nombre'])) ?></span>
            <div class="stack" style="gap:3px;min-width:0">
              <strong class="contact__name"><?= e($c['nombre']) ?></strong>
              <?php if ($c['entidad'] !== ''): ?>
                <span class="contact__org"><?= e($c['entidad']) ?></span>
              <?php endif; ?>
              <span class="contact__data">
                <?= e($c['correo']) ?><?php if ((int) $c['comparte_telefono'] === 1 && $c['telefono'] !== ''): ?>
                  · <?= e($c['telefono']) ?>
                <?php endif; ?>
              </span>
            </div>
            <span class="contact__when"><?= e(fecha((string) $c['creado_en'])) ?><br><?= e(hora((string) $c['creado_en'])) ?></span>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="row">
      <button class="btn btn--primary" type="button" data-abrir-modal="modal-mi-qr">Mostrar mi código</button>
      <?php if ($contactos): ?>
        <a class="btn" href="<?= e(u('/contactos/exportar')) ?>">Exportar .vcf</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="stack stack--3 is-sticky">
    <form class="card" method="post" action="<?= e(u('/contactos/privacidad')) ?>">
      <?= testigo() ?>
      <div class="card__head"><span>Privacidad</span></div>
      <div class="card__body stack stack--3">
        <p class="help">
          Solo se comparten los cuatro campos visibles. La identificación y la
          caracterización nunca viajan en el QR: quedan guardadas y solo las ve la
          administración del evento.
        </p>
        <hr class="divider">

        <label class="row row--between" style="cursor:pointer">
          <span class="mono" style="font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--c-text)">Compartir teléfono</span>
          <input type="checkbox" name="comparte_telefono" value="1"
                 style="width:20px;height:20px;accent-color:var(--c-accent)"
                 <?= (int) $persona['comparte_telefono'] === 1 ? 'checked' : '' ?>>
        </label>

        <label class="row row--between" style="cursor:pointer">
          <span class="mono" style="font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--c-text)">Aparecer en el directorio</span>
          <input type="checkbox" name="en_directorio" value="1"
                 style="width:20px;height:20px;accent-color:var(--c-accent)"
                 <?= (int) $persona['en_directorio'] === 1 ? 'checked' : '' ?>>
        </label>

        <button class="btn btn--sm btn--block" type="submit">Guardar preferencias</button>
      </div>
    </form>

    <div class="card">
      <div class="card__head"><span>Resumen</span></div>
      <div class="card__body--tight">
        <div class="kv"><span class="kv__k">Contactos</span><strong class="kv__v"><?= e((string) count($contactos)) ?></strong></div>
        <div class="kv">
          <span class="kv__k">Entidades</span>
          <strong class="kv__v"><?= e((string) count(array_unique(array_filter(array_column($contactos, 'entidad'))))) ?></strong>
        </div>
      </div>
    </div>
  </div>

</div>

<div class="modal hidden" id="modal-mi-qr" hidden>
  <div class="modal__panel" role="dialog" aria-modal="true" aria-label="Mi código de contacto">
    <div class="modal__head">
      <span>Intercambio de contacto</span>
      <button class="modal__close" type="button" data-cerrar-modal aria-label="Cerrar">&times;</button>
    </div>
    <div class="modal__body" style="align-items:center">
      <div class="qr-frame" style="width:230px"><?= $qr ?></div>
      <strong style="font-family:var(--f-display);font-size:18px;color:var(--c-title)"><?= e($persona['nombre']) ?></strong>
      <p class="help text-center">
        Quien escanee este código recibirá tu nombre, entidad, correo
        <?= (int) $persona['comparte_telefono'] === 1 ? 'y teléfono.' : '. Tu teléfono no se comparte.' ?>
      </p>
    </div>
  </div>
</div>
