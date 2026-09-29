<?php /* CONFIGURACIÓN › METAS (módulo 5). */ ?>
<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>
<div class="rejilla rejilla--panel">
  <div class="tarjeta" id="metas-asesores">
    <div class="tarjeta__cab"><h2>Cada asesor</h2><span class="mini">Vacío = la meta general</span></div>
    <?php if (!$filas): ?><p class="mini" style="margin:0">Todavía no hay asesores.</p><?php endif; ?>
    <?php foreach ($filas as $f): ?>
      <form method="post" class="fila" id="meta-<?= (int)$f['id'] ?>" style="flex-wrap:wrap">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="asesor">
        <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
        <span class="fila__crece" style="min-width:140px">
          <span class="fila__t"><?= e(trim((string)$f['nombre'] . ' ' . (string)$f['apellidos'])) ?></span>
          <span class="fila__s">Lleva <?= e(soles_corto((int)$f['cobrado'])) ?> de <?= e(soles_corto((int)$f['meta'])) ?><?= $f['propia'] === null ? ' · la general' : '' ?></span>
        </span>
        <input type="text" name="monto" inputmode="decimal" placeholder="General" aria-label="Meta de <?= e((string)$f['nombre']) ?>"
               value="<?= $f['propia'] === null ? '' : e(soles((int)$f['propia'], false)) ?>" style="width:120px">
        <button class="btn btn--linea btn--chico" type="submit">GUARDAR</button>
      </form>
    <?php endforeach; ?>
  </div>
  <div class="tarjeta" id="meta-general">
    <div class="tarjeta__cab"><h2>La meta general</h2></div>
    <form method="post" class="form">
      <?= campo_csrf() ?>
      <input type="hidden" name="accion" value="general">
      <label>Meta de cada asesor al mes (S/)
        <input type="text" name="monto" inputmode="decimal" required value="<?= e(soles((int)$general, false)) ?>"></label>
      <div class="acciones"><button class="btn btn--negro" type="submit">GUARDAR</button></div>
    </form>
    <p class="mini" style="margin:8px 0 0">Vale desde este mes. Los meses pasados siguen con la que tenían. Se mide por lo cobrado, no por lo vendido, y no depende del nivel de cuota.</p>
  </div>
</div>
