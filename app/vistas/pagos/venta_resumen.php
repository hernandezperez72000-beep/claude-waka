<?php
/**
 * EL RESUMEN DE UNA VENTA, ENCIMA DE LA BANDEJA DE PAGOS (3f).
 *
 * «Un botón para que se abra una venta emergente y ver el resumen» (usuario,
 * 2026-09-26). Es la misma `.tapa` que la ventana del resultado del pago: la
 * pinta el servidor y se cierra con un enlace, sin JavaScript. Quien valida ve
 * qué se vendió, a quién, cuánto y qué pagos lleva, sin perder su sitio en la
 * cola.
 */
if (empty($ver)) return;
$vp = $ver['p'];
$saldo = pedido_saldo($vp);
?>
<div class="tapa" id="tapa-venta">
  <div class="tapa__caja tapa__caja--ancha">
    <a class="tapa__x" href="<?= e($cerrar) ?>" title="Cerrar" aria-label="Cerrar">&times;</a>
    <div class="tapa__cab">
      <div class="tapa__t"><?= e((string)$vp['codigo']) ?>
        <span class="chip chip--<?= e($vp['estado_color'] ?: 'gris') ?>"><?= e((string)$vp['estado_nombre']) ?></span></div>
      <div class="tapa__s"><?= e(tipo_venta_texto((string)$vp['tipo'])) ?> ·
        <?= e(trim((string)$vp['cliente_nombre'] . ' ' . (string)$vp['cliente_apellidos'])) ?> ·
        vende <?= e(primer_nombre((string)$vp['asesor_nombre'])) ?></div>
    </div>

    <div class="resumen-venta">
      <?php foreach ($ver['lineas'] as $l): ?>
        <div class="resumen-venta__l">
          <?php $fu = producto_foto_url($ver['fotos'][(int)($l['producto_id'] ?? 0)] ?? null); ?>
          <?php if ($fu !== ''): ?><img class="foto-prod" src="<?= e($fu) ?>" alt="" width="40" height="40" loading="lazy">
          <?php else: ?><span class="foto-prod foto-prod--vacia"></span><?php endif; ?>
          <span class="resumen-venta__d">
            <span class="fila__t"><?= (int)$l['cantidad'] ?>× <?= e((string)$l['descripcion']) ?></span>
            <?php if (trim((string)($l['modelo'] ?? '')) !== ''): ?><span class="fila__s"><?= e((string)$l['modelo']) ?></span><?php endif; ?>
          </span>
          <span class="num"><?= e(soles((int)$l['total_centimos'])) ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (!empty($ver['stock_web'])): ?>
        <p class="mini stock-venta stock-venta--<?= e($ver['stock_web']['tono']) ?>" style="justify-content:flex-start"><?= ico('caja',14) ?> <?= e($ver['stock_web']['texto']) ?></p>
      <?php endif; ?>
    </div>

    <div class="tapa__cifras">
      <div><span>Total</span><strong><?= e(soles((int)$vp['total_centimos'])) ?></strong></div>
      <div><span>Cobrado</span><strong class="tapa__ok"><?= e(soles((int)$vp['cobrado_centimos'])) ?></strong></div>
      <div><span>Por cobrar</span><strong class="<?= $saldo > 0 ? 'tapa__falta' : '' ?>"><?= e(soles($saldo)) ?></strong></div>
    </div>

    <?php if ((int)($vp['descuento_centimos'] ?? 0) > 0 || (int)($vp['cashback_usado_centimos'] ?? 0) > 0): ?>
      <p class="mini" style="margin:10px 0 0">
        <?= (int)($vp['descuento_centimos'] ?? 0) > 0 ? 'Descuento ' . e(soles((int)$vp['descuento_centimos'])) : '' ?>
        <?= (int)($vp['descuento_centimos'] ?? 0) > 0 && (int)($vp['cashback_usado_centimos'] ?? 0) > 0 ? ' · ' : '' ?>
        <?= (int)($vp['cashback_usado_centimos'] ?? 0) > 0 ? 'Cashback usado ' . e(soles((int)$vp['cashback_usado_centimos'])) : '' ?></p>
    <?php endif; ?>

    <?php if ($ver['pagos']): ?>
      <div class="resumen-venta resumen-venta--pagos">
        <?php foreach ($ver['pagos'] as $pg): $ch = pago_chip($pg); ?>
          <div class="resumen-venta__l">
            <span class="resumen-venta__d">
              <span class="fila__t"><?= e(soles((int)$pg['monto_centimos'])) ?> · <?= e((string)($pg['metodo'] ?? '')) ?></span>
              <span class="fila__s"><?= e(fecha_corta((string)$pg['fecha'])) ?></span>
            </span>
            <span class="chip <?= e($ch[0]) ?>"><?= e($ch[1]) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="acciones" style="margin-top:16px">
      <a class="btn btn--negro" href="<?= e(url('/pedidos/facturar?id=' . (int)$vp['id'])) ?>">ABRIR LA VENTA</a>
      <a class="btn btn--linea" href="<?= e($cerrar) ?>">CERRAR</a>
    </div>
  </div>
</div>
