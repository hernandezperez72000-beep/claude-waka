<?php /* LAS GARANTÍAS (3i). Ver nucleo/garantias.php. */
$chip_estado = fn(string $e) => match ($e) { 'pedida' => 'ambar', 'aprobada' => 'verde', 'denegada' => 'rojo', default => 'gris' }; ?>
<header class="cabecera">
  <div>
    <h1>Garantías</h1>
    <div class="cabecera__sub">Se piden desde la ficha del pedido<?= $resuelvo ? ' · las apruebas tú' : ' · las aprueba Administración' ?></div>
  </div>
</header>

<div class="tarjeta" id="sigue-en-garantia" style="margin-bottom:14px">
  <div class="tarjeta__cab"><h2>¿Sigue en garantía?</h2><span class="mini">Busca la compra del cliente</span></div>
  <form method="get" class="form">
    <?php if ($estado !== ''): ?><input type="hidden" name="e" value="<?= e($estado) ?>"><?php endif; ?>
    <label>Pedido, DNI, nombre o celular
      <input type="search" name="v" value="<?= e($busca) ?>" minlength="3" placeholder="P-00123 · 45678912 · María">
    </label>
    <div class="acciones"><button class="btn btn--negro btn--chico" type="submit"><?= ico('lupa',15) ?> BUSCAR</button></div>
  </form>
  <?php if ($busca !== '' && mb_strlen($busca) < 3): ?>
    <p class="mini" style="margin:8px 0 0">Escribe al menos 3 letras o números.</p>
  <?php elseif ($busca !== '' && !$hallados): ?>
    <p class="mini" style="margin:8px 0 0" id="vigencia-nada">No encontré ninguna compra con «<?= e($busca) ?>».</p>
  <?php endif; ?>
  <?php foreach ($hallados as $h): $hp = $h['p']; $hv = $h['v']; ?>
    <div class="fila vigencia-fila" style="border-top:1px solid var(--linea);flex-wrap:wrap">
      <span class="fila__crece">
        <a class="fila__t" href="<?= e(url('/pedidos/ficha?id=' . (int)$hp['id'])) ?>" style="color:inherit"><?= e((string)$hp['codigo']) ?>
          · <?= e(trim((string)$hp['cliente_nombre'] . ' ' . (string)$hp['cliente_apellidos'])) ?></a>
        <span class="fila__s"><?= e(implode(' · ', array_map(fn($l) => (int)$l['cantidad'] . ' × ' . $l['descripcion'], array_slice($h['lineas'], 0, 3)))) ?></span>
        <span class="fila__s"><?= e($hv['detalle']) ?></span>
      </span>
      <span class="chip chip--<?= e(garantia_vigencia_tono($hv['estado'])) ?>"><?= e($hv['texto']) ?></span>
      <?php if (garantia_se_puede_pedir($hp) === ''): ?>
        <a class="chip chip--linea" href="<?= e(url('/garantias/pedir?pedido=' . (int)$hp['id'])) ?>">Pedir garantía</a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<form method="get" class="filtros" style="align-items:center">
  <?php foreach (['' => 'Todas', 'pedida' => 'Por aprobar', 'aprobada' => 'Aprobadas', 'denegada' => 'No aprobadas', 'anulada' => 'Anuladas'] as $k => $t): ?>
    <a class="<?= $estado === $k ? 'on' : '' ?>" href="<?= e(url('/garantias' . ($k !== '' ? '?e=' . $k : ''))) ?>"><?= e($t) ?>
      <?php if ($k === 'pedida' && $por_aprobar > 0 && $resuelvo): ?> <span class="chip chip--marca"><?= (int)$por_aprobar ?></span><?php endif; ?></a>
  <?php endforeach; ?>
  <span class="filtros__sep" aria-hidden="true"></span>
  <?php if ($estado !== ''): ?><input type="hidden" name="e" value="<?= e($estado) ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Pedido o cliente"
         style="border:1.5px solid var(--linea);background:var(--tarjeta);border-radius:999px;padding:9px 16px;font-size:12.5px;min-width:0;max-width:100%">
</form>

<?php if (!$filas): ?>
  <div class="tarjeta" id="garantias-vacio">
    <?php parte('inicio/vacio', [
      'ico' => 'OK', 'titulo' => $estado === 'pedida' ? 'Nada por aprobar' : 'Sin garantías',
      'texto' => 'Una garantía se pide desde la ficha del pedido: la pieza, el motivo y la foto de la falla.',
    ]); ?>
  </div>
<?php else: ?>
  <div class="tarjeta" id="lista-garantias">
    <?php foreach ($filas as $g): ?>
      <a class="fila" href="<?= e(url('/garantias/ver?id=' . (int)$g['id'])) ?>">
        <span class="fila__crece">
          <span class="fila__t"><?= e(garantia_codigo((int)$g['id'])) ?> · <?= e(trim((string)$g['cliente_nombre'] . ' ' . (string)$g['cliente_apellidos'])) ?></span>
          <span class="fila__s"><?= e((string)$g['pedido_codigo']) ?> · <?= e(garantia_piezas_texto($g['lineas'])) ?></span>
          <span class="fila__s"><?= e(fecha_hora((string)$g['pedida_en'])) ?> · <?= e(primer_nombre((string)$g['asesor_nombre'])) ?></span>
        </span>
        <?php if ((int)$g['fuera_plazo'] === 1): ?><span class="chip chip--rojo">Fuera de plazo</span><?php endif; ?>
        <?php if ($g['estado'] === 'aprobada'): ?>
          <span class="chip chip--<?= $g['alistado_en'] ? 'verde' : 'ambar' ?>"><?= $g['alistado_en'] ? 'Salió' : 'Por alistar' ?></span>
        <?php else: ?>
          <span class="chip chip--<?= e($chip_estado((string)$g['estado'])) ?>"><?= e(garantia_estados()[$g['estado']] ?? '') ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
