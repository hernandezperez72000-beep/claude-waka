<?php /* PEDIR GARANTÍA (3i). Ver nucleo/garantias.php. */
$v = $vigencia;
$elegidas = (array)($_POST['pieza'] ?? []); ?>
<header class="cabecera">
  <div class="crece">
    <div class="cabecera__sub"><a href="<?= e(url('/pedidos/ficha?id=' . (int)$p['id'])) ?>"><?= e((string)$p['codigo']) ?></a></div>
    <h1>Pedir garantía</h1>
    <div class="cabecera__sub"><?= e(trim((string)$p['cliente_nombre'] . ' ' . (string)$p['cliente_apellidos'])) ?></div>
  </div>
</header>

<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px" id="garantia-error">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>

<div class="aviso aviso--<?= $v['estado'] === 'vigente' ? 'gris' : 'amarillo' ?>" style="margin-bottom:14px" id="garantia-vigencia">
  <span><?= ico('escudo',17) ?></span>
  <span><strong><?= e($v['texto']) ?>.</strong> <?= e($v['detalle']) ?>
    <?php if ($v['estado'] !== 'vigente'): ?><br>Puedes pedirla igual: Administración decide si la aprueba.<?php endif; ?></span>
</div>

<?php if ($antes): ?>
  <div class="aviso aviso--gris" style="margin-bottom:14px" id="garantias-antes">
    <span><?= ico('reloj',17) ?></span>
    <span><strong>Ya se pidió <?= plural(count($antes), 'vez', 'veces') ?>:</strong>
      <?= e(implode(' · ', array_map(fn($g) => garantia_codigo((int)$g['id']) . ' ' . garantia_piezas_texto($g['lineas']) . ' (' . mb_strtolower(garantia_estados()[$g['estado']] ?? '') . ')', $antes))) ?></span>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="form" id="form-garantia">
  <?= campo_csrf() ?>
  <input type="hidden" name="pedido" value="<?= (int)$p['id'] ?>">
  <div class="rejilla rejilla--panel">
    <div style="display:flex;flex-direction:column;gap:12px">
      <div class="tarjeta">
        <div class="tarjeta__cab"><h2>Qué pieza</h2><span class="mini">De lo que compró en este pedido</span></div>
        <?php if (!$piezas): ?>
          <p class="mini" style="margin:0 0 8px" id="sin-repuestos">Las máquinas de este pedido todavía no tienen repuestos en el catálogo.
            Pídela igual: Administración elige la pieza.</p>
        <?php endif; ?>
        <?php $maq_actual = null; foreach ($piezas as $pz): ?>
          <?php if ($pz['maquina_nombre'] !== $maq_actual): $maq_actual = $pz['maquina_nombre']; ?>
            <div class="mini" style="margin:10px 0 2px;font-weight:700"><?= e((string)$maq_actual) ?></div>
          <?php endif; ?>
          <label class="fila pieza-fila" style="gap:10px">
            <span class="fila__crece"><span class="fila__t"><?= e((string)$pz['nombre']) ?></span>
              <span class="fila__s"><?= e((string)$pz['sku']) ?></span></span>
            <?php if ($pz['stock']['texto'] !== ''): ?><span class="chip chip--<?= e($pz['stock']['tono']) ?>"><?= e($pz['stock']['texto']) ?></span><?php endif; ?>
            <input type="number" name="pieza[<?= (int)$pz['id'] ?>]" min="0" max="20" inputmode="numeric" style="width:70px"
                   value="<?= (int)($elegidas[(int)$pz['id']] ?? 0) ?>" aria-label="Cuántas de <?= e((string)$pz['nombre']) ?>">
          </label>
        <?php endforeach; ?>
        <label class="check" style="margin-top:10px">
          <input type="checkbox" name="sin_pieza" value="1" <?= pedir('sin_pieza') === '1' || !$piezas ? 'checked' : '' ?>> No sé qué pieza es: que la elija Administración
        </label>
      </div>
      <div class="tarjeta">
        <div class="tarjeta__cab"><h2>Qué falla</h2></div>
        <label>Motivo
          <textarea name="motivo" rows="3" maxlength="500" required placeholder="El joystick no responde desde ayer…"><?= e((string)($_POST['motivo'] ?? '')) ?></textarea>
        </label>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:12px">
      <div class="tarjeta" id="fotos-falla">
        <div class="tarjeta__cab"><h2>Fotos de la falla</h2><span class="mini">La primera es obligatoria</span></div>
        <?php for ($k = 1; $k <= GARANTIA_FOTOS_MAX; $k++): $oculta = $k > 1 && empty($pend[$k]) && empty($pend[$k - 1]); ?>
          <div class="foto-falla" data-n="<?= $k ?>"<?= $oculta ? ' hidden' : '' ?>>
            <?php parte('pagos/foto_campo', ['campo' => 'falla_' . $k, 'titulo' => 'Foto ' . $k,
                  'ayuda' => $k === 1 ? 'Que se vea la falla o la pieza rota.' : 'Otra foto, si ayuda.',
                  'pend' => $pend[$k] ?? null, 'solo_foto' => true]); ?>
          </div>
        <?php endfor; ?>
        <button type="button" class="btn btn--linea btn--chico" id="otra-foto"><?= ico('mas',15) ?> Añadir otra foto</button>
      </div>
      <div class="tarjeta">
        <p class="mini" style="margin:0 0 10px">No se cobra nada, tampoco el envío. No suma a la meta ni da cashback. Si se aprueba, sale del almacén como salió el pedido.</p>
        <div class="acciones"><button class="btn btn--amarillo btn--ancho" type="submit">PEDIR GARANTÍA</button></div>
      </div>
    </div>
  </div>
</form>

<script>
/* Una foto más cada vez: se enseña la siguiente escondida. */
(function () {
  var b = document.getElementById('otra-foto');
  if (!b) return;
  function quedan() { return document.querySelectorAll('.foto-falla[hidden]'); }
  if (!quedan().length) b.hidden = true;
  b.addEventListener('click', function () {
    var q = quedan();
    if (q.length) q[0].hidden = false;
    if (quedan().length === 0) b.hidden = true;
  });
})();
</script>
