<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>

<div class="rejilla rejilla--panel">
  <div class="tarjeta" id="lista-simple" data-lista="<?= e($cfg['clave']) ?>">
    <div class="tarjeta__cab"><h2>Los nombres</h2><span class="mini"><?= e($cfg['uso']) ?></span></div>
    <?php if (!$filas): ?>
      <p class="mini" style="margin:0"><?= e($cfg['vacio']) ?></p>
    <?php endif; ?>
    <?php foreach ($filas as $f): ?>
      <div class="fila">
        <form method="post" class="fila__crece" style="display:flex;gap:8px;align-items:center;min-width:0">
          <?= campo_csrf() ?>
          <input type="hidden" name="accion" value="renombrar">
          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
          <input type="text" name="nombre" maxlength="60" value="<?= e((string)$f['valor']) ?>" aria-label="Nombre" style="min-width:0;flex:1">
          <button class="btn btn--linea btn--chico" type="submit">GUARDAR</button>
        </form>
        <?php if ((int)$f['activo'] === 0): ?><span class="chip chip--gris">Apagado</span><?php endif; ?>
        <form method="post" style="display:inline">
          <?= campo_csrf() ?>
          <input type="hidden" name="accion" value="estado">
          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
          <input type="hidden" name="encender" value="<?= (int)$f['activo'] === 1 ? '0' : '1' ?>">
          <button class="chip chip--suave" type="submit" style="cursor:pointer"><?= (int)$f['activo'] === 1 ? 'Apagar' : 'Encender' ?></button>
        </form>
      </div>
    <?php endforeach; ?>
    <form method="post" class="form" style="margin-top:12px;border-top:1px solid var(--linea);padding-top:12px">
      <?= campo_csrf() ?>
      <input type="hidden" name="accion" value="anadir">
      <label>Añadir a alguien
        <input type="text" name="nombre" maxlength="60" required placeholder="<?= e($cfg['hueco']) ?>">
      </label>
      <div class="acciones"><button class="btn btn--negro" type="submit">AÑADIR</button></div>
    </form>
  </div>
  <div class="tarjeta">
    <div class="tarjeta__cab"><h2>Cómo se usa</h2></div>
    <?php foreach ($cfg['ayuda'] as $ay): ?><p class="mini" style="margin:0 0 8px"><?= e($ay) ?></p><?php endforeach; ?>
    <p class="mini" style="margin:0">No se borra ningún nombre: se apaga. Así lo de antes sigue diciendo el suyo.</p>
  </div>
</div>
