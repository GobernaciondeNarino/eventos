<?php
/**
 * Agenda pública.
 * @var array $jornadas @var int $dia @var array $charlas @var array $categorias
 * @var string $q @var string $categoria
 */
defined('EVENTOS_TIC') || exit;
?>
<div class="view view--medium stack stack--4">

  <div class="row row--between" style="align-items:flex-end">
    <div class="stack stack--2">
      <span class="kicker">Programación</span>
      <h1>Agenda de exposiciones</h1>
    </div>
    <div class="row" role="group" aria-label="Día de la agenda">
      <?php foreach ($jornadas as $j): ?>
        <a class="chip chip--stacked<?= (int) $j['numero'] === $dia ? ' is-active' : '' ?>"
           href="<?= e(u('/agenda', array_filter(['dia' => (int) $j['numero'], 'q' => $q, 'categoria' => $categoria]))) ?>">
          Día <?= e((string) $j['numero']) ?>
          <small><?= e(fecha((string) $j['fecha'])) ?></small>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <form class="card" method="get" action="<?= e(u('/agenda')) ?>">
    <input type="hidden" name="dia" value="<?= e((string) $dia) ?>">
    <div class="card__head">
      <span>Filtrar</span>
      <span class="muted"><?= e((string) count($charlas)) ?> exposiciones</span>
    </div>
    <div class="card__body row">
      <label class="sr-only" for="q">Buscar en la agenda</label>
      <input class="input" id="q" name="q" value="<?= e($q) ?>" style="flex:1;min-width:220px"
             placeholder="Buscar por título, expositor o entidad…">
      <label class="sr-only" for="categoria">Categoría</label>
      <select class="select" id="categoria" name="categoria" style="width:auto;min-width:200px">
        <option value="">Todas las categorías</option>
        <?php foreach ($categorias as $c): ?>
          <option value="<?= e($c) ?>" <?= $categoria === $c ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn--sm" type="submit">Filtrar</button>
      <?php if ($q !== '' || $categoria !== ''): ?>
        <a class="btn btn--sm btn--dashed" href="<?= e(u('/agenda', ['dia' => $dia])) ?>">Limpiar</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="stack stack--2">
    <?php if (!$charlas): ?>
      <div class="empty">
        <?= $q !== '' || $categoria !== ''
          ? 'No hay exposiciones que coincidan con el filtro'
          : 'Todavía no hay exposiciones publicadas para esta jornada' ?>
      </div>
    <?php else: ?>
      <?php foreach ($charlas as $c): ?>
        <a class="talk" href="<?= e(u('/agenda/' . (int) $c['id'])) ?>">
          <span class="talk__time">
            <span class="talk__hour"><?= e(substr((string) $c['hora_inicio'], 0, 5)) ?></span>
            <span class="talk__dur"><?= e((string) $c['duracion_min']) ?> min</span>
          </span>
          <span class="stack" style="gap:6px">
            <strong class="talk__title"><?= e($c['titulo']) ?></strong>
            <span class="talk__by"><?= e($c['expositor']) ?><?= $c['entidad'] !== '' ? ' · ' . e($c['entidad']) : '' ?></span>
          </span>
          <span class="talk__meta">
            <span class="tag"><?= e($c['categoria']) ?></span>
            <?php if ($c['salon'] !== ''): ?>
              <span class="talk__room"><?= e($c['salon']) ?></span>
            <?php endif; ?>
          </span>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>
