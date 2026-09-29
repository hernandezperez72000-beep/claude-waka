<?php /* DISPONIBLE PARA PRE VENTA (3h): por lote, los productos con su precio
         por cantidad y lo que queda de cada color, en la misma pantalla. */ ?>
<form method="get" class="barra-filtros" style="margin-bottom:14px" role="search">
  <label>Buscar
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nombre o código" autocomplete="off">
  </label>
  <button class="btn btn--negro" type="submit">BUSCAR</button>
  <?php if ($vende): ?><a class="btn btn--amarillo" href="<?= e(url('/pedidos/nuevo?tipo=preventa')) ?>" id="btn-vender-pv">NUEVA VENTA DE PRE VENTA</a><?php endif; ?>
</form>

<?php if (!$grupos): ?>
  <div class="tarjeta">
    <?php parte('inicio/vacio', ['ico' => 'PV',
      'titulo' => $q !== '' ? 'Nada con «' . $q . '»' : 'No hay nada en pre venta ahora',
      'texto'  => $q !== '' ? 'Prueba con otra palabra o con el código.' : 'Cuando se ponga a la venta un contenedor, sus productos aparecen aquí.']); ?>
  </div>
<?php endif; ?>

<?php foreach ($grupos as $g): $l = $g['lote']; ?>
  <section class="tarjeta pv-lote" id="pv-lote-<?= (int)$l['id'] ?>" style="margin-bottom:14px">
    <div class="tarjeta__cab">
      <h2><?= e((string)$l['nombre']) ?></h2>
      <span class="mini"><?= !empty($l['fecha_llegada_est']) ? 'Llega aprox. el ' . e(fecha_corta((string)$l['fecha_llegada_est'])) : 'Llegada por confirmar' ?></span>
    </div>
    <?php parte('catalogo/barco', ['viaje' => $g['viaje'], 'lote' => $l]); ?>
    <div class="pv-productos">
    <?php foreach ($g['productos'] as $p): ?>
      <article class="pv-prod<?= $p['quedan'] <= 0 ? ' pv-prod--agotado' : '' ?>">
        <div class="pv-prod__cab">
          <strong><?= e($p['nombre']) ?></strong>
          <?php if ($p['nuevo']): ?><span class="chip chip--marca">Nuevo</span><?php endif; ?>
          <span class="mini"><?= e($p['sku']) ?></span>
          <span class="pv-prod__quedan <?= $p['quedan'] <= 0 ? 'rojo' : '' ?>"><?= $p['quedan'] > 0 ? 'Quedan ' . (int)$p['quedan'] : 'Agotado' ?></span>
        </div>
        <div class="pv-prod__precios">
          <?php foreach ($p['tramos'] as $t): ?>
            <span class="pv-tramo"><span class="pv-tramo__k"><?= (int)$t['desde'] ?><?= $t['hasta'] === null ? ' a más' : ($t['hasta'] != $t['desde'] ? '–' . (int)$t['hasta'] : '') ?><?= trim((string)($t['alias'] ?? '')) !== '' ? ' · ' . e((string)$t['alias']) : '' ?></span>
              <strong><?= e(soles((int)$t['precio_centimos'])) ?></strong></span>
          <?php endforeach; ?>
        </div>
        <?php if (count($p['filas']) > 1 || (string)$p['filas'][0]['modelo'] !== ''): ?>
          <div class="pv-prod__colores">
            <?php foreach ($p['filas'] as $f): ?>
              <span class="chip <?= $f['quedan'] > 0 ? 'chip--linea' : 'chip--gris' ?>"><?= e((string)$f['modelo'] !== '' ? (string)$f['modelo'] : 'Única') ?> · <?= $f['quedan'] > 0 ? (int)$f['quedan'] : 'agotado' ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
