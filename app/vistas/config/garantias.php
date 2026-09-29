<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>

<div class="rejilla rejilla--panel">
  <div class="tarjeta" id="lista-garantias-config">
    <div class="tarjeta__cab"><h2>Las garantías</h2><span class="mini">La primera viene marcada en la venta</span></div>
    <?php $primera = null; foreach ($filas as $f) { if ((int)$f['activo'] === 1) { $primera = (int)$f['id']; break; } } ?>
    <?php foreach ($filas as $f): $usada = (int)$f['usos'] > 0; ?>
      <div class="fila" style="flex-wrap:wrap">
        <form method="post" class="fila__crece" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;min-width:0">
          <?= campo_csrf() ?>
          <input type="hidden" name="accion" value="guardar">
          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
          <input type="text" name="nombre" maxlength="60" value="<?= e((string)$f['valor']) ?>" aria-label="Nombre" style="min-width:0;flex:1 1 140px" <?= $usada ? 'readonly' : '' ?>>
          <label style="display:flex;gap:6px;align-items:center;margin:0">
            <input type="number" name="meses" min="0" max="120" inputmode="numeric" value="<?= $f['meses'] === null ? '' : (int)$f['meses'] ?>" style="width:74px" <?= $usada && $f['meses'] !== null ? 'readonly' : '' ?> aria-label="Meses"> meses</label>
          <?php if (!$usada || $f['meses'] === null): ?><button class="btn btn--linea btn--chico" type="submit">GUARDAR</button><?php endif; ?>
        </form>
        <?php if ((int)$f['id'] === $primera): ?><span class="chip chip--marca">Viene marcada</span><?php endif; ?>
        <?php if ((string)($f['extra'] ?? '') === 'liquidacion'): ?><span class="chip chip--linea">Liquidación</span><?php endif; ?>
        <?php if ($usada): ?><span class="chip chip--gris" title="Ya se prometió en ventas">En <?= (int)$f['usos'] ?> <?= (int)$f['usos'] === 1 ? 'venta' : 'ventas' ?></span><?php endif; ?>
        <?php if ((int)$f['activo'] === 0): ?><span class="chip chip--gris">Apagada</span><?php endif; ?>
        <?php if ((int)$f['activo'] === 1 && (int)$f['id'] !== $primera): ?>
          <form method="post" style="display:inline">
            <?= campo_csrf() ?>
            <input type="hidden" name="accion" value="primera">
            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <button class="chip chip--suave" type="submit" style="cursor:pointer">Que venga marcada</button>
          </form>
        <?php endif; ?>
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
      <div class="form__fila">
        <label>Nueva garantía <input type="text" name="nombre" maxlength="60" required placeholder="18 meses"></label>
        <label>Meses <input type="number" name="meses" min="0" max="120" inputmode="numeric" required style="max-width:100px"></label>
      </div>
      <div class="acciones"><button class="btn btn--negro" type="submit">AÑADIR</button></div>
    </form>
  </div>
  <div class="tarjeta">
    <div class="tarjeta__cab"><h2>Cómo se usa</h2></div>
    <p class="mini" style="margin:0 0 8px">Cada producto lleva su garantía y la venta la hereda. La vigencia cuenta desde que el pedido sale a despacho, más estos meses.</p>
    <p class="mini" style="margin:0 0 8px">0 meses = sin garantía (la de liquidación).</p>
    <p class="mini" style="margin:0">Una garantía que ya se prometió en ventas no cambia: esas ventas siguen con lo que se les dijo. Crea otra y apaga esta.</p>
  </div>
</div>
