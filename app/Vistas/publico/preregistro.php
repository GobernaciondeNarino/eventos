<?php
/**
 * Formulario de preregistro.
 * @var array $valores @var array $errores @var array $departamentos
 * @var array $municipios @var array $categorias @var array $jornadas @var bool $yaRegistrado
 */
defined('EVENTOS_TIC') || exit;

use App\Datos;

$v = static fn(string $clave, string $porDefecto = ''): string => (string) ($valores[$clave] ?? $porDefecto);
$err = static fn(string $clave): string => (string) ($errores[$clave] ?? '');

// Si el correo vino por la portada, se precarga.
if ($v('correo') === '' && isset($_GET['correo'])) {
    $valores['correo'] = mb_strtolower(trim((string) $_GET['correo']));
}
$hayPropuesta = $v('tema') !== '' || !empty($valores['expositor']);
$guiones = ['preregistro.js'];
?>
<form class="view view--narrow stack stack--4" method="post" action="<?= e(u('/preregistro')) ?>" novalidate>
  <?= testigo() ?>

  <div class="stack stack--2">
    <span class="kicker">Fase 01 · Datos del participante</span>
    <h1><?= $yaRegistrado ? 'Mis datos' : 'Formulario de preregistro' ?></h1>
    <p class="help">
      <?= $yaRegistrado
        ? 'Puedes corregir lo que haga falta; el carnet se actualiza solo.'
        : 'Solo nombre e identificación son obligatorios.' ?>
    </p>
  </div>

  <?php if ($err('general')): ?>
    <div class="notice notice--danger"><span class="notice__icon">▲</span><span><?= e($err('general')) ?></span></div>
  <?php endif; ?>

  <!-- ================= Datos obligatorios ================= -->
  <section class="card">
    <div class="card__head"><span>Datos obligatorios</span></div>
    <div class="card__body stack stack--4">

      <div class="field">
        <label class="label" for="correo">Correo electrónico <span class="req">*</span></label>
        <input class="input<?= $err('correo') ? ' is-invalid' : '' ?>" type="email" id="correo" name="correo"
               value="<?= e($v('correo')) ?>" placeholder="nombre@entidad.gov.co"
               autocomplete="email" inputmode="email" <?= $yaRegistrado ? 'readonly' : 'required' ?>>
        <?php if ($err('correo')): ?><span class="error"><?= e($err('correo')) ?></span><?php endif; ?>
        <?php if ($yaRegistrado): ?><span class="help">El correo no se puede cambiar: es tu forma de entrar.</span><?php endif; ?>
      </div>

      <div class="field">
        <label class="label" for="nombre">Nombre completo <span class="req">*</span></label>
        <input class="input<?= $err('nombre') ? ' is-invalid' : '' ?>" id="nombre" name="nombre"
               value="<?= e($v('nombre')) ?>" autocomplete="name"
               placeholder="Ej. María Fernanda Zambrano" maxlength="160" required>
        <?php if ($err('nombre')): ?><span class="error"><?= e($err('nombre')) ?></span><?php endif; ?>
      </div>

      <div class="grid-2" style="grid-template-columns:190px 1fr">
        <div class="field">
          <label class="label" for="tipo_documento">Tipo de documento <span class="req">*</span></label>
          <select class="select" id="tipo_documento" name="tipo_documento">
            <?php foreach (Datos::TIPOS_DOCUMENTO as $clave => $etiqueta): ?>
              <option value="<?= e($clave) ?>" <?= $v('tipo_documento', 'CC') === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="documento">Número de identificación <span class="req">*</span></label>
          <input class="input input--mono<?= $err('documento') ? ' is-invalid' : '' ?>" id="documento" name="documento"
                 value="<?= e($v('documento')) ?>" inputmode="numeric" placeholder="Sin puntos ni comas" required>
          <?php if ($err('documento')): ?><span class="error"><?= e($err('documento')) ?></span><?php endif; ?>
        </div>
      </div>

      <div class="grid-2">
        <div class="field">
          <label class="label" for="telefono">Teléfono de contacto</label>
          <input class="input input--mono<?= $err('telefono') ? ' is-invalid' : '' ?>" id="telefono" name="telefono"
                 type="tel" value="<?= e($v('telefono')) ?>" autocomplete="tel" placeholder="+57 300 000 0000">
          <?php if ($err('telefono')): ?><span class="error"><?= e($err('telefono')) ?></span><?php endif; ?>
        </div>
        <div class="field">
          <label class="label" for="rol">Perfil de asistencia</label>
          <select class="select" id="rol" name="rol">
            <?php foreach (['participante', 'visitante', 'expositor', 'prensa'] as $rol): ?>
              <option value="<?= e($rol) ?>" <?= $v('rol', 'participante') === $rol ? 'selected' : '' ?>><?= e(etiquetaRol($rol)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

    </div>
  </section>

  <!-- ================= Caracterización ================= -->
  <section class="card">
    <div class="card__head">
      <span>Fase 02 · Caracterización (opcional)</span>
      <button class="btn btn--sm" type="button" data-plegar="bloque-opcional" aria-expanded="true">Ocultar</button>
    </div>
    <div class="card__body stack stack--5" id="bloque-opcional">
      <p class="help">Nos permite reportar cobertura territorial y enfoque diferencial. Puedes omitirla por completo.</p>

      <div class="grid-2">
        <div class="field">
          <label class="label" for="genero">Género</label>
          <select class="select" id="genero" name="genero">
            <?php foreach (Datos::GENEROS as $clave => $etiqueta): ?>
              <option value="<?= e($clave) ?>" <?= $v('genero') === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="discapacidad">¿Tiene alguna discapacidad?</label>
          <select class="select" id="discapacidad" name="discapacidad">
            <?php foreach (Datos::DISCAPACIDADES as $clave => $etiqueta): ?>
              <option value="<?= e($clave) ?>" <?= $v('discapacidad', 'No') === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="etnia">Grupo étnico</label>
          <select class="select" id="etnia" name="etnia">
            <?php foreach (Datos::ETNIAS as $clave => $etiqueta): ?>
              <option value="<?= e($clave) ?>" <?= $v('etnia', 'Ninguno') === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="entidad">Entidad u organización</label>
          <input class="input" id="entidad" name="entidad" value="<?= e($v('entidad')) ?>"
                 autocomplete="organization" placeholder="Ej. Alcaldía de Ipiales" maxlength="160">
        </div>
      </div>

      <div class="field">
        <span class="label" id="rotulo-edad">Rango de edad</span>
        <div class="row" role="group" aria-labelledby="rotulo-edad">
          <?php foreach (Datos::RANGOS_EDAD as $rango): ?>
            <label class="chip<?= $v('rango_edad') === $rango ? ' is-active' : '' ?>">
              <input type="radio" name="rango_edad" value="<?= e($rango) ?>" class="sr-only"
                     <?= $v('rango_edad') === $rango ? 'checked' : '' ?>>
              <?= e($rango) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="grid-2" style="align-items:end">
        <div class="field">
          <label class="label" for="departamento">Departamento</label>
          <select class="select" id="departamento" name="departamento"
                  data-municipios="<?= e(u('/municipios/')) ?>">
            <option value="">Selecciona…</option>
            <?php foreach ($departamentos as $d): ?>
              <option value="<?= e($d) ?>" <?= $v('departamento') === $d ? 'selected' : '' ?>><?= e($d) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <div class="row" style="min-height:15px;gap:9px">
            <label class="label" for="municipio">Municipio</label>
            <span class="kicker hidden" id="mun-cargando" style="font-size:10px"><span class="pulse"></span> consultando…</span>
          </div>
          <select class="select<?= $err('municipio') ? ' is-invalid' : '' ?>" id="municipio" name="municipio"
                  <?= $municipios ? '' : 'disabled' ?>>
            <option value=""><?= $municipios ? 'Selecciona…' : 'Elige primero el departamento' ?></option>
            <?php foreach ($municipios as $m): ?>
              <option value="<?= e($m) ?>" <?= $v('municipio') === $m ? 'selected' : '' ?>><?= e($m) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($err('municipio')): ?><span class="error"><?= e($err('municipio')) ?></span><?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- ================= Perfil expositor ================= -->
  <section class="card">
    <div class="card__head">
      <span>Fase 03 · Perfil expositor</span>
      <label class="row" style="gap:8px;cursor:pointer;text-transform:none;letter-spacing:normal">
        <input type="checkbox" name="expositor" id="expositor" value="1"
               style="width:18px;height:18px;accent-color:var(--c-accent)"
               data-mostrar="bloque-expositor" <?= $hayPropuesta ? 'checked' : '' ?>>
        <span class="help">Voy a exponer</span>
      </label>
    </div>
    <div class="card__body stack stack--4">
      <p class="help">Actívalo si vas a presentar una charla, stand o demostración. La Secretaría revisa cada propuesta y confirma horario y espacio.</p>

      <div class="stack stack--4<?= $hayPropuesta ? '' : ' hidden' ?>" id="bloque-expositor">
        <div class="grid-2" style="grid-template-columns:1.4fr 1fr">
          <div class="field">
            <label class="label" for="tema">Tema de la exposición <span class="req">*</span></label>
            <input class="input<?= $err('tema') ? ' is-invalid' : '' ?>" id="tema" name="tema"
                   value="<?= e($v('tema')) ?>" maxlength="200" placeholder="Ej. Datos abiertos para decidir mejor">
            <?php if ($err('tema')): ?><span class="error"><?= e($err('tema')) ?></span><?php endif; ?>
          </div>
          <div class="field">
            <label class="label" for="categoria">Categoría <span class="req">*</span></label>
            <select class="select<?= $err('categoria') ? ' is-invalid' : '' ?>" id="categoria" name="categoria">
              <option value="">Selecciona…</option>
              <?php foreach ($categorias as $c): ?>
                <option value="<?= e($c) ?>" <?= $v('categoria') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($err('categoria')): ?><span class="error"><?= e($err('categoria')) ?></span><?php endif; ?>
          </div>
        </div>

        <div class="field">
          <label class="label" for="detalle">Detalle de lo que vas a exponer <span class="req">*</span></label>
          <textarea class="textarea<?= $err('detalle') ? ' is-invalid' : '' ?>" id="detalle" name="detalle"
                    rows="4" maxlength="600" data-contador="contador-detalle"
                    placeholder="Resumen que verán los asistentes en la agenda (máx. 600 caracteres)"><?= e($v('detalle')) ?></textarea>
          <div class="row row--between">
            <?php if ($err('detalle')): ?><span class="error"><?= e($err('detalle')) ?></span><?php else: ?><span></span><?php endif; ?>
            <span class="help mono" id="contador-detalle"><?= e((string) mb_strlen($v('detalle'))) ?> / 600</span>
          </div>
        </div>

        <div class="grid-3">
          <div class="field">
            <label class="label" for="dia_preferido">Día preferido</label>
            <select class="select" id="dia_preferido" name="dia_preferido">
              <?php foreach ($jornadas as $j): ?>
                <option value="<?= e((string) $j['numero']) ?>" <?= (int) $v('dia_preferido', '1') === (int) $j['numero'] ? 'selected' : '' ?>>
                  Día <?= e((string) $j['numero']) ?> — <?= e(fecha((string) $j['fecha'])) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="duracion">Duración</label>
            <select class="select" id="duracion" name="duracion">
              <?php foreach ([20 => '20 minutos', 40 => '40 minutos', 60 => '1 hora'] as $min => $etiqueta): ?>
                <option value="<?= $min ?>" <?= (int) $v('duracion', '40') === $min ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="requerimientos">Requerimientos</label>
            <input class="input" id="requerimientos" name="requerimientos"
                   value="<?= e($v('requerimientos')) ?>" maxlength="255" placeholder="HDMI, internet…">
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ================= Autorización ================= -->
  <?php if (!$yaRegistrado): ?>
    <section class="card">
      <div class="card__head"><span>Tratamiento de datos</span></div>
      <div class="card__body stack stack--3">
        <label class="row" style="align-items:flex-start;gap:11px;cursor:pointer">
          <input type="checkbox" id="habeas" name="habeas" value="1" style="margin-top:3px;width:18px;height:18px;accent-color:var(--c-accent)">
          <span class="help" style="flex:1">
            Autorizo el tratamiento de mis datos personales por parte de la Gobernación de
            Nariño para la gestión de este evento, conforme a la Ley 1581 de 2012 y a la
            política de tratamiento de datos de la entidad. <span class="req">*</span>
          </span>
        </label>
        <?php if ($err('habeas')): ?><span class="error"><?= e($err('habeas')) ?></span><?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <div class="row row--between" style="padding-top:4px">
    <span class="help mono">Los campos con <span class="req">*</span> son obligatorios.</span>
    <div class="row">
      <a class="btn" href="<?= e(u($yaRegistrado ? '/carnet' : '/')) ?>">Cancelar</a>
      <button class="btn btn--primary" type="submit"><?= $yaRegistrado ? 'Guardar cambios' : 'Completar preregistro' ?></button>
    </div>
  </div>
</form>
