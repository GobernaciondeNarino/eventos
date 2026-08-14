<?php
/**
 * Cambiar la propia contraseña.
 * @var bool $debeCambiar @var array $errores
 */
defined('EVENTOS_TIC') || exit;

$marca = require __DIR__ . '/../parciales/marca.php';
$err = static fn(string $clave): string => (string) ($errores[$clave] ?? '');
?>
<main class="acceso" id="contenido">
  <div class="acceso__inner">

    <div class="acceso__marca">
      <?= $marca('lg') ?>
      <h1><?= e($evento['nombre'] ?? 'Plataforma de Eventos TIC') ?></h1>
      <span class="kicker">Cambiar mi contraseña</span>
    </div>

    <form class="card" method="post" action="<?= e(u('/admin/clave')) ?>" novalidate>
      <?= testigo() ?>

      <div class="card__head"><span>Contraseña nueva</span></div>
      <div class="card__body stack stack--4">

        <?php if ($debeCambiar): ?>
          <div class="notice notice--warn">
            <span class="notice__icon" aria-hidden="true">▲</span>
            <span>
              Tu contraseña la puso otra persona al crear la cuenta. Cámbiala por una que solo
              conozcas tú antes de seguir.
            </span>
          </div>
        <?php endif; ?>

        <div class="field">
          <label class="label" for="actual">Contraseña actual</label>
          <input class="input<?= $err('actual') ? ' is-invalid' : '' ?>" id="actual" name="actual"
                 type="password" autocomplete="current-password" autofocus required>
          <?php if ($err('actual')): ?><span class="error"><?= e($err('actual')) ?></span><?php endif; ?>
        </div>

        <div class="field">
          <label class="label" for="nueva">Contraseña nueva</label>
          <input class="input<?= $err('nueva') ? ' is-invalid' : '' ?>" id="nueva" name="nueva"
                 type="password" autocomplete="new-password" required>
          <span class="help">
            Al menos 12 caracteres. Una frase que puedas recordar es mejor que una palabra
            con símbolos: «la puerta del recinto abre a las siete».
          </span>
          <?php if ($err('nueva')): ?><span class="error"><?= e($err('nueva')) ?></span><?php endif; ?>
        </div>

        <div class="field">
          <label class="label" for="nueva2">Repite la contraseña nueva</label>
          <input class="input<?= $err('nueva2') ? ' is-invalid' : '' ?>" id="nueva2" name="nueva2"
                 type="password" autocomplete="new-password" required>
          <?php if ($err('nueva2')): ?><span class="error"><?= e($err('nueva2')) ?></span><?php endif; ?>
        </div>

        <button class="btn btn--primary btn--block btn--lg" type="submit">Cambiar contraseña</button>

        <p class="help">
          Al cambiarla se cierran las sesiones que tengas abiertas en otros dispositivos.
        </p>
      </div>
    </form>

    <p class="acceso__pie">
      <a href="<?= e(u('/admin')) ?>">Volver al panel</a>
    </p>

  </div>
</main>
