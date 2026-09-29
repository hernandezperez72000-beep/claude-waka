<header class="cabecera">
  <div>
    <h1>Stock y pre venta</h1>
    <div class="cabecera__sub">
      <?= (int)$cuantos ?> <?= $cuantos === 1 ? 'producto' : 'productos' ?> en el catálogo</div>
  </div>
  <?php /* LA PRE VENTA (3h.1): un botón con su menú, junto a TRAER DE LA
           TIENDA. Como aviso gris pasaba por notificación y nadie lo abría. */
        $pv_lotes = puede('lotes.ver'); $pv_gestion = puede('lotes.gestionar');
        $pv_ver = puede('preventa.ver'); ?>
  <?php if ($puedo_tocar || $pv_lotes || $pv_ver): ?>
    <div class="cabecera__acciones">
      <?php if ($pv_lotes || $pv_ver): ?>
        <details class="menu-chip menu-preventa" id="menu-preventa">
          <summary class="btn btn--amarillo"><?= ico('barco',16) ?> PRE VENTA <span aria-hidden="true">▾</span></summary>
          <div class="menu-chip__caja">
            <?php if ($pv_lotes): ?><a href="<?= e(url('/stock/lotes')) ?>" id="btn-lotes"><?= ico('barco',15) ?> Lotes en camino</a><?php endif; ?>
            <?php if ($pv_gestion): ?><a href="<?= e(url('/stock/lote')) ?>"><?= ico('mas',15) ?> Nuevo lote</a>
              <a href="<?= e(url('/stock/lote/excel')) ?>"><?= ico('flecha',15) ?> Traer lotes del Excel</a><?php endif; ?>
            <?php if ($pv_ver): ?><a href="<?= e(url('/preventa')) ?>"><?= ico('caja',15) ?> Disponible para pre venta</a><?php endif; ?>
          </div>
        </details>
      <?php endif; ?>
      <?php if ($puedo_tocar): ?>
      <a class="btn btn--linea" href="<?= e(url('/stock/tienda')) ?>" id="btn-tienda">
        <?= ico('flecha',16) ?> TRAER DE LA TIENDA</a>
      <a class="btn btn--negro" href="<?= e(url('/stock/producto')) ?>">
        <?= ico('mas',16) ?> NUEVO PRODUCTO</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</header>

<?php if (!empty($falta_actualizar)): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:12px">
    <span><?= ico('alerta',17) ?></span>
    <span>Falta terminar la actualización para usar esta sección.</span>
  </div>
<?php endif; ?>

<?php /* EL STOCK DE LA WEB (3b.5): con su hora, para que nadie confunda «hay 5»
         con «había 5 hace tres horas». */ ?>
<?php if (!empty($tienda_lista)): ?>
  <div class="aviso aviso--gris aviso--conbtn" style="margin-bottom:12px" id="aviso-stock">
    <span><?= ico('caja',17) ?></span>
    <span><?php if ($stock_leido !== ''): ?>Stock de la web leído <?= e(hace($stock_leido)) ?>.
      <?php else: ?>El stock de la web todavía no se ha leído.<?php endif; ?>
      <?= !empty($control) ? 'Las ventas lo descuentan.' : 'Las ventas no lo descuentan (control de stock apagado).' ?></span>
    <?php if (!empty($puedo_leer_stock)): ?>
      <form method="post" action="<?= e(url('/stock/leer')) ?>" style="margin-left:auto" id="form-stock">
        <?= campo_csrf() ?>
        <button class="btn btn--linea btn--chico" type="submit" data-espera="Leyendo el stock…">ACTUALIZAR STOCK</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php /* Lo que las ventas tienen que mover en la web y espera, o que dejó la
         web en negativo (3e). */ ?>
<?php if (!empty($cola_atencion)): ?>
  <div class="aviso aviso--amarillo aviso--conbtn" style="margin-bottom:12px" id="aviso-cola">
    <span><?= ico('alerta',17) ?></span>
    <span><strong><?= (int)$cola_atencion ?>
      <?= $cola_atencion === 1 ? 'venta necesita' : 'ventas necesitan' ?> que mires el stock de la web.</strong></span>
    <a class="btn btn--linea btn--chico" href="<?= e(url('/stock/pendientes')) ?>" style="margin-left:auto">VER</a>
  </div>
<?php endif; ?>

<?php if ($sin_precio > 0 && $puedo_tocar): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:12px">
    <span><?= ico('alerta',17) ?></span>
    <span><strong><?= (int)$sin_precio ?>
      <?= $sin_precio === 1 ? 'producto no tiene precio' : 'productos no tienen precio' ?>.</strong>
      Sin precio no se pueden vender: el asesor no los ve al registrar la venta.
      <a href="<?= e(url('/stock?v=sin_precio')) ?>" style="text-decoration:underline">Verlos</a>.</span>
  </div>
<?php endif; ?>

<form method="get" class="filtros" style="align-items:center">
  <?php foreach (array_merge([['todos','Todos'],['sin_precio','Sin precio']], !empty($con_rep) ? [['repuestos','Repuestos']] : [], [['apagados','Apagados']]) as [$k,$t]): ?>
    <a class="<?= $ver === $k ? 'on' : '' ?>"
       href="<?= e(url('/stock?v=' . $k . ($q !== '' ? '&q=' . urlencode($q) : ''))) ?>"><?= e($t) ?></a>
  <?php endforeach; ?>
  <span class="filtros__sep" aria-hidden="true"></span>
  <input type="hidden" name="v" value="<?= e($ver) ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nombre, código o categoría"
         style="border:1.5px solid var(--linea);background:var(--tarjeta);border-radius:999px;padding:9px 16px;font-size:12.5px;min-width:230px">
</form>

<?php if (!$productos): ?>
  <div class="tarjeta">
    <?php parte('inicio/vacio', [
      'ico' => 'CT', 'marca' => $puedo_tocar,
      'titulo' => $q !== '' ? 'Ningún producto coincide' : 'Todavía no hay productos',
      'texto'  => 'Un producto lleva su nombre, sus modelos o colores y su precio por cantidad. '
                . 'Con eso el asesor lo elige al vender y el precio sale solo.',
      'boton'  => $puedo_tocar ? 'NUEVO PRODUCTO' : '',
      'ruta'   => '/stock/producto',
    ]); ?>
  </div>
<?php else: ?>
  <div class="tabla__caja">
    <table class="tabla tabla--envuelve">
      <thead><tr><th>Producto</th><th>Modelos y colores</th><th>Stock web</th><th>Garantía</th>
        <th class="der">Precio</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($productos as $pr): ?>
        <?php $base = $precios[(int)$pr['id']] ?? null;
              $vars = $variantes[(int)$pr['id']] ?? []; ?>
        <tr class="<?= (int)$pr['activo'] === 1 ? '' : 'apagada' ?>">
          <td class="principal">
            <a href="<?= e(url('/stock/producto?id=' . (int)$pr['id'])) ?>" style="color:inherit" class="con-foto">
              <?php $fu = producto_foto_url($pr['foto'] ?? null); ?>
              <?php if ($fu !== ''): ?><img class="foto-prod" src="<?= e($fu) ?>" alt="" width="40" height="40" loading="lazy">
              <?php else: ?><span class="foto-prod foto-prod--vacia" aria-hidden="true"></span><?php endif; ?>
              <span class="con-foto__txt">
              <div class="fila__t"><?= e($pr['nombre']) ?></div>
              <div class="fila__s"><?= e($pr['sku']) ?><?= $pr['categoria'] ? ' · ' . e($pr['categoria']) : '' ?><?= !empty($pr['woo_id']) ? ' · en la tienda' : '' ?></div>
              <?php if (($pr['maquina_nombre'] ?? '') !== ''): ?><div class="fila__s">Repuesto de <?= e((string)$pr['maquina_nombre']) ?></div><?php endif; ?>
              </span>
            </a>
          </td>
          <td data-k="Modelos">
            <?php if (!$vars): ?><span class="mini">Única</span>
            <?php else: ?>
              <span class="mini"><?= e(implode(' · ', array_slice($vars, 0, 3))) ?>
                <?= count($vars) > 3 ? ' +' . (count($vars) - 3) : '' ?></span>
            <?php endif; ?>
          </td>
          <td data-k="Stock web">
            <?php $stx = !empty($con_rep) && stock_hub_aplica($pr) ? stock_hub_texto((int)($stock_hub[(int)$pr['id']] ?? 0))
                                                                   : stock_web_texto($stock[(int)$pr['id']] ?? null); ?>
            <?php if ($stx['texto'] === ''): ?><span class="mini">—</span>
            <?php else: ?><span class="chip chip--<?= e(['verde' => 'verde', 'ambar' => 'ambar', 'rojo' => 'rojo'][$stx['tono']] ?? 'gris') ?>" data-stock="<?= (int)$pr['id'] ?>"><?= e($stx['texto']) ?></span><?php endif; ?>
          </td>
          <td data-k="Garantía"><span class="mini">
            <?= e(lista_texto((int)($pr['garantia_item_id'] ?? 0)) ?: '—') ?></span></td>
          <td class="der num" data-k="Precio">
            <?php if ($base === null && in_array((int)$pr['id'], $de_preventa ?? [], true)): ?>
              <span class="chip chip--linea">Pre venta</span>
            <?php elseif ($base === null && !empty($pr['padre_id'])): ?>
              <span class="chip chip--gris">Solo garantía</span>
            <?php elseif ($base === null): ?>
              <span class="chip chip--ambar">Sin precio</span>
            <?php else: ?><?= e(soles($base)) ?><?php endif; ?>
          </td>
          <td class="der">
            <a class="chip chip--linea" href="<?= e(url('/stock/producto?id=' . (int)$pr['id'])) ?>">
              <?= $puedo_tocar ? 'Editar' : 'Ver' ?></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
