<?php if ($puedo): ?>
  <div class="acciones" style="margin-bottom:14px">
    <a class="btn btn--negro" href="<?= e(url('/stock/lote')) ?>" id="btn-lote-nuevo"><?= ico('mas',16) ?> NUEVO LOTE</a>
    <a class="btn btn--linea" href="<?= e(url('/stock/lote/excel')) ?>" id="btn-lote-excel">TRAER DEL EXCEL</a>
  </div>
<?php endif; ?>

<?php if (!$lotes): ?>
  <div class="tarjeta">
    <?php parte('inicio/vacio', ['ico' => 'LT', 'titulo' => 'Todavía no hay lotes',
      'texto' => $puedo ? 'Llena el primero con NUEVO LOTE, o trae los de antes desde el Excel.' : 'Cuando se cargue un contenedor, aparece aquí.']); ?>
  </div>
<?php else: ?>
  <div class="lotes">
  <?php foreach ($lotes as $l): $c = $l['completitud']; ?>
    <a class="tarjeta lote-tarjeta" href="<?= e(url('/stock/lote?id=' . (int)$l['id'])) ?>" id="lote-<?= (int)$l['id'] ?>">
      <div class="tarjeta__cab">
        <h2><?= e((string)$l['nombre']) ?> <span class="mini"><?= e((string)$l['codigo']) ?></span></h2>
        <span class="chip <?= (int)$l['disponible'] === 1 ? 'chip--verde' : 'chip--gris' ?>"><?= (int)$l['disponible'] === 1 ? 'A la venta' : 'Apagado' ?></span>
      </div>
      <?php parte('catalogo/barco', ['viaje' => $l['viaje'], 'lote' => $l]); ?>
      <div class="lote-tarjeta__datos">
        <span class="chip chip--linea"><?= e(lote_estados()[$l['estado']] ?? $l['estado']) ?></span>
        <?php /* 3j: después de «Llegó», si ya está listo para entrega. */ ?>
        <?php if ($l['estado'] === 'recibido' && array_key_exists('listo_en', $l)): ?>
          <span class="chip <?= empty($l['listo_en']) ? 'chip--ambar' : 'chip--verde' ?>"><?= empty($l['listo_en']) ? 'Falta «Listo para entrega»' : 'Listo para entrega' ?></span>
        <?php endif; ?>
        <?php if ((string)$l['canal'] !== ''): ?><span class="chip canal canal--<?= e((string)$l['canal']) ?>">Canal <?= e(lote_canales()[$l['canal']] ?? '') ?></span><?php endif; ?>
        <span class="mini"><?= (int)$l['unidades_totales'] ?> unidades · <?= (int)$l['vendidas'] ?> vendidas</span>
        <?php if ($c['sin'] > 0): ?><span class="chip chip--ambar"><?= plural($c['sin'], 'sin precio', 'sin precio') ?></span><?php endif; ?>
      </div>
    </a>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<p class="mini" style="margin-top:12px">El avance del barco se calcula con las fechas · el estado lo confirma la agencia.</p>
