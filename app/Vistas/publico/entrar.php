<?php
/** Acceso del asistente, paso 1. @var string $correo @var string $destino @var array $errores */
defined('EVENTOS_TIC') || exit;
?>
<div class="view view--narrow stack stack--5" style="max-width:480px;margin:auto">

  <div class="stack stack--2">
    <span class="kicker">Acceso de asistentes</span>
    <h1>Entra con tu correo</h1>
    <p class="lead">
      Te enviamos un código de seis dígitos. No hace falta contraseña: para un evento de
      pocos días, un código de un solo uso es más seguro y más rápido.
    </p>
  </div>

  <form class="card" method="post" action="<?= e(u('/entrar')) ?>" novalidate>
    <?= testigo() ?>
    <input type="hidden" name="destino" value="<?= e($destino) ?>">

    <div class="card__head"><span>Identifícate</span></div>
    <div class="card__body stack stack--4">
      <div class="field">
        <label class="label" for="correo">Correo con el que te preregistraste</label>
        <input class="input<?= isset($errores['correo']) ? ' is-invalid' : '' ?>" type="email"
               id="correo" name="correo" value="<?= e($correo) ?>" autocomplete="email"
               inputmode="email" placeholder="nombre@entidad.gov.co" autofocus required>
        <?php if (isset($errores['correo'])): ?><span class="error"><?= e($errores['correo']) ?></span><?php endif; ?>
      </div>

      <button class="btn btn--primary btn--block btn--lg" type="submit">Enviarme el código</button>

      <p class="help">
        ¿Todavía no te has registrado?
        <a href="<?= e(u('/preregistro')) ?>">Haz tu preregistro</a>, toma dos minutos.
      </p>
    </div>
  </form>

  <p class="text-center">
    <a href="<?= e(u('/admin/entrar')) ?>" class="mono"
       style="font-size:11px;letter-spacing:.12em;text-transform:uppercase">Soy del equipo organizador ›</a>
  </p>
</div>
