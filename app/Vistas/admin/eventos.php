<?php
/** Listado de eventos. @var array $eventos */
defined('EVENTOS_TIC') || exit;

$columnas = 'grid-template-columns:1.6fr 1fr .9fr .8fr 1fr';
$estados = [
    'borrador' => ['Borrador', 'tag--mute'],
    'abierto'  => ['Abierto', ''],
    'en_curso' => ['En curso', 'tag--ok'],
    'cerrado'  => ['Cerrado', 'tag--mute'],
];
?>
<div class="view view--wide stack stack--4">

  <div class="row row--between" style="align-items:flex-end">
    <div class="stack stack--2">
      <span class="kicker">Administrador</span>
      <h1>Eventos de la Secretaría</h1>
      <p class="help">
        La plataforma sirve a varios eventos a la vez. Cada uno tiene su propia identidad
        visual, sus jornadas, sus códigos y sus registros.
      </p>
    </div>
    <button class="btn btn--sm btn--primary" type="button" data-abrir-modal="modal-evento">Crear evento</button>
  </div>

  <div class="table">
    <div class="table__scroll">
      <div class="table__grid" style="min-width:760px">
        <div class="table__head" style="<?= $columnas ?>">
          <span>Evento</span><span>Inicio</span><span>Jornadas</span>
          <span>Registros</span><span>Estado</span>
        </div>

        <?php foreach ($eventos as $ev):
          [$etiqueta, $clase] = $estados[$ev['estado']] ?? ['—', '']; ?>
          <div class="table__row" style="<?= $columnas ?>">
            <div class="stack" style="gap:2px;min-width:0">
              <strong style="font-weight:500;color:var(--c-title)"><?= e($ev['nombre']) ?></strong>
              <span class="mono muted" style="font-size:11px">
                <?= (int) $ev['activo'] === 1 ? 'Evento activo · visible para los asistentes' : ($ev['sede'] ?: 'Sin sede definida') ?>
              </span>
            </div>
            <span class="mono" style="font-size:12.5px;color:var(--c-text)"><?= e(fecha((string) $ev['fecha_inicio'])) ?></span>
            <span style="color:var(--c-text)"><?= e((string) $ev['jornadas']) ?> días</span>
            <span class="mono accent"><?= e(numero($ev['registros'])) ?></span>
            <div class="row" style="gap:6px">
              <span class="tag <?= e($clase) ?>"><?= e($etiqueta) ?></span>
              <?php if ((int) $ev['activo'] !== 1): ?>
                <form method="post" action="<?= e(u('/admin/eventos/activar')) ?>">
                  <?= testigo() ?>
                  <input type="hidden" name="evento" value="<?= (int) $ev['id'] ?>">
                  <button class="btn btn--sm" type="submit">Activar</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="table__foot">
      <span><?= e((string) count($eventos)) ?> eventos registrados</span>
      <span>El evento activo es el que ven los asistentes</span>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span>Qué pasa al crear un evento</span></div>
    <div class="card__body grid-3">
      <?php foreach ([
        ['Se crea', ['Sus jornadas, una por día', 'Un código QR distinto por jornada', 'Su identidad visual por defecto']],
        ['Queda aparte', ['Los registros de personas', 'Las asistencias', 'Los contactos intercambiados']],
        ['Se comparte', ['El equipo organizador y sus roles', 'La bitácora de auditoría', 'La configuración del servidor']],
      ] as [$titulo, $items]): ?>
        <div class="stack" style="gap:8px">
          <strong style="font-family:var(--f-display);font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:var(--c-accent)">
            <?= e($titulo) ?>
          </strong>
          <ul style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.75;color:var(--c-text)">
            <?php foreach ($items as $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<div class="modal hidden" id="modal-evento" hidden>
  <div class="modal__panel" role="dialog" aria-modal="true" aria-label="Crear evento">
    <div class="modal__head">
      <span>Nuevo evento</span>
      <button class="modal__close" type="button" data-cerrar-modal aria-label="Cerrar">&times;</button>
    </div>
    <div class="modal__body">
      <h2 style="font-size:22px">Crear evento</h2>
      <form method="post" action="<?= e(u('/admin/eventos/crear')) ?>" class="stack stack--4">
        <?= testigo() ?>

        <div class="field">
          <label class="label" for="ev-nombre">Nombre del evento</label>
          <input class="input" id="ev-nombre" name="nombre" required maxlength="160"
                 placeholder="Ej. Encuentro de Gobierno Digital">
        </div>
        <div class="grid-2">
          <div class="field">
            <label class="label" for="ev-dependencia">Dependencia</label>
            <input class="input" id="ev-dependencia" name="dependencia" maxlength="160"
                   value="Secretaría TIC, Innovación y Gobierno Abierto">
          </div>
          <div class="field">
            <label class="label" for="ev-sede">Sede</label>
            <input class="input" id="ev-sede" name="sede" maxlength="160" placeholder="Centro de Convenciones, Pasto">
          </div>
        </div>
        <div class="grid-2">
          <div class="field">
            <label class="label" for="ev-fecha">Fecha de inicio</label>
            <input class="input" id="ev-fecha" name="fecha_inicio" type="date" required>
          </div>
          <div class="field">
            <label class="label" for="ev-jornadas">Número de jornadas</label>
            <input class="input input--mono" id="ev-jornadas" name="jornadas" type="number"
                   min="1" max="30" value="3">
          </div>
        </div>

        <div class="row row--end">
          <button class="btn" type="button" data-cerrar-modal>Cancelar</button>
          <button class="btn btn--primary" type="submit">Crear</button>
        </div>
      </form>
    </div>
  </div>
</div>
