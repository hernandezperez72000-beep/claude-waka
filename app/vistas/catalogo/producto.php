<?php $puedo_precios = $puedo_precios ?? false; $v = fn(string $k, $d = '') => e((string)($p[$k] ?? $d)); ?>
<header class="cabecera">
  <?php $fu = producto_foto_url($p['foto'] ?? null); ?>
  <?php if ($fu !== ''): ?><img class="foto-prod foto-prod--grande" id="foto-ficha" src="<?= e($fu) ?>" alt="<?= e($p['nombre']) ?>" width="96" height="96"><?php endif; ?>
  <div class="crece">
    <div class="cabecera__sub"><a href="<?= e(url('/stock')) ?>">Stock y pre venta</a></div>
    <h1><?= $p ? e($p['nombre']) : 'Nuevo producto' ?>
      <?php if ($p && (int)$p['activo'] === 0): ?>
        <span class="chip chip--gris">Apagado</span><?php endif; ?>
    </h1>
    <?php if ($p): ?><div class="cabecera__sub"><?= e($p['sku']) ?></div><?php endif; ?>
    <?php if (!empty($maquina)): ?><div class="cabecera__sub" id="es-repuesto-de">Repuesto de
      <a href="<?= e(url('/stock/producto?id=' . (int)$maquina['id'])) ?>" style="text-decoration:underline"><?= e((string)$maquina['nombre']) ?></a></div><?php endif; ?>
  </div>
</header>

<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>

<div class="rejilla rejilla--panel">
  <div style="display:flex;flex-direction:column;gap:12px">

    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Qué es</h2></div>
      <form method="post" class="form">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="producto">
        <?php if ($id): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
        <label>Nombre
          <input type="text" name="nombre" value="<?= $v('nombre') ?>" required maxlength="180"
                 <?= $puedo_tocar ? '' : 'disabled' ?>>
          <?php /* MANDA LA TIENDA en el nombre y la categoría (usuario,
                   2026-09-24): lo que se cambie aquí se pisa la próxima vez
                   que se traiga el catálogo. Se dice donde se escribe. */ ?>
          <?php if ($p && !empty($p['woo_id']) && !empty($web)): ?>
            <span class="ayuda" id="nota-tienda">Está en la tienda: al guardar, el nombre y el código también cambian allá.</span>
          <?php elseif ($p && !empty($p['woo_id']) && conector_listo()): ?>
            <span class="ayuda" id="nota-tienda">Está en la tienda: tu cuenta no cambia allá el nombre ni el código.</span>
          <?php elseif ($p && !empty($p['woo_id'])): ?>
            <span class="ayuda" id="nota-tienda">Viene de la tienda: el nombre y la categoría se cambian allá.</span>
          <?php endif; ?>
        </label>
        <div class="form__fila">
          <label>Categoría
            <?php /* DE LA LISTA DE LA WEB (3f): no se escribe, se elige. En uno
                     que viene de la web, la manda la web: aquí solo se ve. */
                  /* Si el formulario rebotó, la que eligió; si no, la guardada. */
                  $cat_act = (string)(isset($_POST['categoria']) && !($p && !empty($p['woo_id']))
                                      ? $_POST['categoria'] : ($p['categoria'] ?? ''));
                  $cats = categorias_del_catalogo();
                  $cat_web = $p && !empty($p['woo_id']); ?>
            <select name="categoria" id="campo-categoria" <?= $puedo_tocar && !$cat_web ? '' : 'disabled' ?>>
              <option value="">Sin categoría</option>
              <?php foreach ($cats as $cn): ?>
                <option value="<?= e($cn) ?>" <?= $cn === $cat_act ? 'selected' : '' ?>><?= e($cn) ?></option>
              <?php endforeach; ?>
              <?php if ($cat_act !== '' && !in_array($cat_act, $cats, true)): ?>
                <option value="<?= e($cat_act) ?>" selected><?= e($cat_act) ?> (no está en la web)</option>
              <?php endif; ?>
            </select>
            <?php if ($cat_web): ?>
              <?php /* disabled no viaja: se manda la de ahora para no borrarla. */ ?>
              <input type="hidden" name="categoria" value="<?= e($cat_act) ?>">
              <span class="ayuda">La categoría se cambia en la web.</span>
            <?php elseif (!$cats): ?>
              <span class="ayuda">Las categorías salen de la web: se llenan al traer la tienda.</span>
            <?php endif; ?>
          </label>
          <label>Garantía
            <?php /* LA GARANTÍA VIVE EN EL PRODUCTO (usuario, 2026-09-20): los
                     carritos eléctricos llevan 6 meses y hasta ahora había que
                     acordarse en cada venta. El pedido la hereda de aquí. */ ?>
            <select name="garantia_item_id" <?= $puedo_tocar ? '' : 'disabled' ?>>
              <option value="">Sin garantía</option>
              <?php foreach ($garantias as $g): ?>
                <option value="<?= (int)$g['id'] ?>"
                  <?= (int)($p['garantia_item_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>>
                  <?= e($g['valor']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="ayuda">La venta la hereda sola. El asesor puede cambiarla en su pedido.</span>
          </label>
        </div>
        <?php if ($id && !empty($web)): ?>
          <label>Código
            <input type="text" name="sku" maxlength="60" value="<?= $v('sku') ?>" id="sku-web">
            <span class="ayuda">Cambia también en la web.</span>
          </label>
        <?php endif; ?>
        <?php if (!$id): ?>
          <label>Código
            <input type="text" name="sku" maxlength="60" placeholder="Se genera solo: WK-000001">
            <span class="ayuda">Si ya tienes el código del Excel de cargas, pégalo aquí. No se puede cambiar después.</span>
          </label>
        <?php endif; ?>
        <label class="check">
          <input type="checkbox" name="activo" value="1"
                 <?= (!$p || (int)$p['activo'] === 1) ? 'checked' : '' ?> <?= $puedo_tocar ? '' : 'disabled' ?>>
          Se puede vender
        </label>
        <?php if ($puedo_tocar): ?>
          <div class="acciones">
            <button class="btn btn--negro" type="submit"><?= $id ? 'GUARDAR' : 'CREAR PRODUCTO' ?></button>
            <a class="btn btn--linea" href="<?= e(url('/stock')) ?>">Cancelar</a>
          </div>
        <?php endif; ?>
      </form>
    </div>

    <?php /* REPUESTO DE (3i): el código de su máquina. Un repuesto sale en el
             buscador de la venta bajo su máquina, y se pide por garantía
             desde los pedidos de esa máquina. */ ?>
    <?php if ($id && repuestos_listo() && ($puedo_tocar || !empty($maquina))): ?>
      <div class="tarjeta" id="tarjeta-repuesto">
        <div class="tarjeta__cab"><h2>Repuesto</h2><span class="mini">De qué máquina es</span></div>
        <?php if (!empty($repuestos)): ?>
          <p class="mini" style="margin:0">Es una máquina con <?= plural(count($repuestos), 'repuesto', 'repuestos') ?>: no puede ser repuesto de otra.</p>
        <?php elseif ($puedo_tocar): ?>
          <form method="post" class="form">
            <?= campo_csrf() ?>
            <input type="hidden" name="accion" value="repuesto">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <label>Código de la máquina
              <input type="text" name="maquina" maxlength="60" value="<?= e((string)($maquina['sku'] ?? '')) ?>" placeholder="PRD-000000">
              <span class="ayuda">Vacío = no es un repuesto. Sin precio, solo sale por garantía.</span>
            </label>
            <div class="acciones"><button class="btn btn--linea btn--chico" type="submit">GUARDAR</button></div>
          </form>
        <?php else: ?>
          <p class="mini" style="margin:0">Repuesto de <?= e((string)$maquina['nombre']) ?> (<?= e((string)$maquina['sku']) ?>).</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php /* EL STOCK DEL ALMACÉN (3i): de un repuesto que no está en la web.
             Entra solo cuando llega su lote; Almacén pone lo que hay. */ ?>
    <?php if ($id && !empty($en_hub)): $sth = stock_hub_texto((int)$hub_hay); ?>
      <div class="tarjeta" id="tarjeta-stock-hub">
        <div class="tarjeta__cab"><h2>Stock en el almacén</h2>
          <span class="chip chip--<?= e($sth['tono']) ?>" id="stock-hub"><?= e($sth['texto']) ?></span></div>
        <p class="mini" style="margin:0 0 8px">No está en la web: su stock se lleva aquí. Entra cuando llega su lote y sale con cada venta y cada garantía.</p>
        <?php if (!empty($puedo_hub)): ?>
          <form method="post" class="form" style="border-top:1px solid var(--linea);padding-top:10px">
            <?= campo_csrf() ?>
            <input type="hidden" name="accion" value="hub_stock">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <div class="form__fila">
              <label>Hay <input type="number" name="cantidad" min="0" max="999999" inputmode="numeric" required style="max-width:120px" value="<?= max(0, (int)$hub_hay) ?>"></label>
              <label>Motivo <input type="text" name="motivo" maxlength="120" required placeholder="Conteo, dañados…"></label>
            </div>
            <div class="acciones"><button class="btn btn--linea btn--chico" type="submit">GUARDAR</button></div>
          </form>
        <?php endif; ?>
        <?php if (!empty($hub_movs)): ?>
          <div style="margin-top:10px">
            <?php foreach ($hub_movs as $mv): ?>
              <div class="dato"><span class="dato__t"><?= e(stock_hub_mov_texto($mv)) ?></span>
                <span class="dato__k"><?= (int)$mv['cantidad'] > 0 ? '+' : '' ?><?= (int)$mv['cantidad'] ?> · quedan <?= (int)$mv['queda'] ?> · <?= e(fecha_hora((string)$mv['creado_en'])) ?></span></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($id): ?>
      <div class="tarjeta">
        <div class="tarjeta__cab"><h2>Precio por cantidad</h2>
          <span class="mini">El precio baja al comprar más</span></div>
        <?php /* El último tramo NO lleva «hasta»: vale de ahí para arriba, y
                 así no hay hueco cuando alguien pide 31 de un «hasta 30». */ ?>
        <form method="post" class="form">
          <?= campo_csrf() ?>
          <input type="hidden" name="accion" value="precios">
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <div class="tabla__caja" style="margin-bottom:10px">
            <table class="tabla">
              <thead><tr><th>Desde</th><th>Hasta</th><th>Precio por unidad</th><th>Cómo lo llama el asesor</th></tr></thead>
              <tbody id="tramos">
                <?php $filas = $tramos ?: [['desde'=>1,'hasta'=>null,'precio_centimos'=>null,'alias'=>'']]; ?>
                <?php foreach ($filas as $t): ?>
                  <tr>
                    <td data-k="Desde"><input type="text" name="t_desde[]" inputmode="numeric" style="max-width:90px"
                           value="<?= (int)$t['desde'] ?>" <?= $puedo_precios ? '' : 'disabled' ?>></td>
                    <td data-k="Hasta"><input type="text" name="t_hasta[]" inputmode="numeric" style="max-width:90px"
                           placeholder="a más" value="<?= $t['hasta'] === null ? '' : (int)$t['hasta'] ?>"
                           <?= $puedo_precios ? '' : 'disabled' ?>></td>
                    <td data-k="Precio"><input type="text" name="t_precio[]" inputmode="decimal" style="max-width:130px"
                           value="<?= $t['precio_centimos'] === null ? '' : e(soles((int)$t['precio_centimos'], false)) ?>"
                           <?= $puedo_precios ? '' : 'disabled' ?>></td>
                    <td data-k="Alias"><input type="text" name="t_alias[]" maxlength="60" placeholder="Por 10 · mayorista"
                           value="<?= e((string)($t['alias'] ?? '')) ?>" <?= $puedo_precios ? '' : 'disabled' ?>></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if ($puedo_precios): ?>
            <div class="acciones">
              <button type="button" class="btn btn--linea" id="mas-tramo"><?= ico('mas',15) ?> Añadir tramo</button>
              <button class="btn btn--negro" type="submit">GUARDAR LOS PRECIOS</button>
            </div>
            <span class="ayuda">Ejemplo: 1 a 2 → S/699 · 3 a 9 → S/649 · 10 a más → S/589.
              Los tramos van seguidos y el último se queda sin «hasta».
              <?php if (!empty($web)): ?><strong id="nota-precio-web">El precio de 1 unidad también cambia en la web.</strong><?php endif; ?></span>
          <?php endif; ?>
        </form>
      </div>

      <div class="tarjeta">
        <div class="tarjeta__cab"><h2>Modelos y colores</h2>
          <span class="mini">Lo que el asesor elige al vender</span></div>
        <?php if (!$variantes): ?>
          <p class="mini" style="margin:0 0 10px">Este producto se vende como <strong>Única</strong>:
            no hay que elegir color ni medida.</p>
        <?php else: ?>
          <?php foreach ($variantes as $va): ?>
            <div class="fila">
              <span class="fila__crece">
                <span class="fila__t"><?= e(variante_nombre($va)) ?></span>
                <span class="fila__s"><?= e($va['sku']) ?></span>
              </span>
              <?php $stv = stock_web_texto($stock['variantes'][(int)$va['id']] ?? null); ?>
              <?php if ($stv['texto'] !== ''): ?><span class="chip chip--<?= e($stv['tono']) ?>" data-stock-var="<?= (int)$va['id'] ?>"><?= e($stv['texto']) ?></span><?php endif; ?>
              <?php if ((int)$va['activo'] === 0): ?><span class="chip chip--gris">Apagado</span><?php endif; ?>
              <?php if ($puedo_tocar): ?>
                <form method="post" style="display:inline">
                  <?= campo_csrf() ?>
                  <input type="hidden" name="accion" value="variante_estado">
                  <input type="hidden" name="id" value="<?= (int)$id ?>">
                  <input type="hidden" name="variante_id" value="<?= (int)$va['id'] ?>">
                  <input type="hidden" name="encender" value="<?= (int)$va['activo'] === 1 ? '0' : '1' ?>">
                  <button class="chip chip--suave" type="submit" style="cursor:pointer">
                    <?= (int)$va['activo'] === 1 ? 'Apagar' : 'Encender' ?></button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <?php /* Los modelos y colores se crean en la web (3f): aquí se ven, con
                 su stock, y se pueden apagar para que no se vendan. */ ?>
        <p class="mini" style="margin:12px 0 0;border-top:1px solid var(--linea);padding-top:12px" id="nota-colores-web">
          <?php if (puede('catalogo.gestionar')): ?>Los modelos y colores se crean en la web y llegan al pulsar
          <a href="<?= e(url('/stock/tienda')) ?>" style="text-decoration:underline">Traer de la tienda</a>.<?php
          else: ?>Los modelos y colores se crean en la web y llegan cuando se trae la tienda.<?php endif; ?></p>
      </div>
    <?php endif; ?>

    <?php /* EL STOCK EN LA WEB (3c): Almacén o Administración ponen la cantidad que hay de
             verdad, con un motivo. Queda quién y cuándo. */ ?>
    <?php if ($id && !empty($puedo_stock)): ?>
      <?php $filas_st = [];
            $vs_web = array_values(array_filter($variantes, fn($x) => (int)$x['activo'] === 1 && !empty($x['woo_id'])));
            $compartidos = tabla_existe('stock_web') ? array_map('intval', array_column(
                todas('SELECT variante_id FROM stock_web WHERE producto_id = ? AND compartido = 1', [(int)$id]), 'variante_id')) : [];
            $colores_web = (bool) valor('SELECT 1 FROM variantes WHERE producto_id = ? AND woo_id IS NOT NULL', [(int)$id]);
            if (!$colores_web || $compartidos) {
                $filas_st[] = ['vid' => 0, 'nombre' => $vs_web ? 'Todos los colores (comparten las unidades)' : 'El producto',
                               'st' => $vs_web ? ($stock['variantes'][$compartidos[0]] ?? null) : $stock];
            }
            foreach ($vs_web as $vw) {
                if (in_array((int)$vw['id'], $compartidos, true)) continue;
                $filas_st[] = ['vid' => (int)$vw['id'], 'nombre' => variante_nombre($vw), 'st' => $stock['variantes'][(int)$vw['id']] ?? null];
            } ?>
      <div class="tarjeta" id="tarjeta-stock-web">
        <div class="tarjeta__cab"><h2>Stock en la web</h2>
          <span class="mini">Pon cuántas hay de verdad</span></div>
        <?php /* 3e: lo vendido que todavía no se descontó de la web se le
                 descuenta DESPUÉS a lo que pongas aquí. */ ?>
        <?php $por_desc = stock_cola_por_descontar((int)$id); ?>
        <?php if ($por_desc > 0): ?>
          <div class="aviso aviso--amarillo" style="margin:8px 0 0" id="aviso-por-descontar">
            <span><?= ico('alerta',17) ?></span>
            <span><?= $por_desc ?> <?= $por_desc === 1 ? 'unidad vendida espera' : 'unidades vendidas esperan' ?> para
              descontarse de la web: se descontarán de lo que pongas aquí. Si ya salieron del almacén, súmalas.</span>
          </div>
        <?php endif; ?>
        <?php foreach ($filas_st as $fs): $stx = stock_web_texto($fs['st']); ?>
          <form method="post" class="form stock-web" style="border-top:1px solid var(--linea);padding-top:10px;margin-top:10px">
            <?= campo_csrf() ?>
            <input type="hidden" name="accion" value="web_stock">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <input type="hidden" name="variante_id" value="<?= (int)$fs['vid'] ?>">
            <div class="fila__t"><?= e($fs['nombre']) ?>
              <?php if ($stx['texto'] !== ''): ?><span class="chip chip--<?= e($stx['tono']) ?>" style="margin-left:6px"><?= e($stx['texto']) ?></span><?php endif; ?></div>
            <div class="form__fila">
              <label>Hay <input type="number" name="cantidad" min="0" max="999999" inputmode="numeric" required style="max-width:120px"
                     value="<?= isset($fs['st']['cantidad']) && $fs['st']['cantidad'] !== null ? (int)$fs['st']['cantidad'] : '' ?>"></label>
              <label>Motivo <input type="text" name="motivo" maxlength="120" required placeholder="Conteo, llegó mercadería, dañados…"></label>
            </div>
            <div class="acciones"><button class="btn btn--linea btn--chico" type="submit">GUARDAR EN LA WEB</button></div>
          </form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div style="display:flex;flex-direction:column;gap:12px">
    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Cómo se usa</h2></div>
      <p class="mini" style="margin:0 0 8px">Al registrar una venta, el asesor escribe el nombre y
        elige el producto de esta lista. El precio sale del tramo que le toque a la cantidad.</p>
      <p class="mini" style="margin:0 0 8px">Sin precio, el producto no aparece: no se puede vender
        algo cuyo precio nadie autorizó.</p>
      <p class="mini" style="margin:0">Los precios se congelan en la venta: cambiarlos aquí no
        cambia lo que ya se vendió.</p>
    </div>
    <?php if (!empty($repuestos)): ?>
      <div class="tarjeta" id="sus-repuestos">
        <div class="tarjeta__cab"><h2>Sus repuestos</h2><span class="chip chip--gris"><?= count($repuestos) ?></span></div>
        <?php foreach ($repuestos as $rp): ?>
          <a class="fila" href="<?= e(url('/stock/producto?id=' . (int)$rp['id'])) ?>">
            <span class="fila__crece"><span class="fila__t"><?= e((string)$rp['nombre']) ?></span>
              <span class="fila__s"><?= e((string)$rp['sku']) ?><?= producto_completo((int)$rp['id']) ? '' : ' · sin precio: solo garantía' ?></span></span>
            <?php if ($rp['stock']['texto'] !== ''): ?><span class="chip chip--<?= e($rp['stock']['tono']) ?>"><?= e($rp['stock']['texto']) ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($id): ?>
      <div class="tarjeta">
        <div class="tarjeta__cab"><h2>Se vende</h2></div>
        <div class="dato"><span class="dato__k">Estado</span>
          <span class="dato__t"><?= producto_completo((int)$id)
              ? '<span class="chip chip--verde">Listo para vender</span>'
              : (!empty($maquina) ? '<span class="chip chip--gris">Sin precio: solo garantía</span>'
                                  : '<span class="chip chip--ambar">Falta el precio</span>') ?></span></div>
        <div class="dato"><span class="dato__k">Modelos</span>
          <span class="dato__t"><?= count(array_filter($variantes, fn($x) => (int)$x['activo'] === 1)) ?: 'Única' ?></span></div>
        <?php $stp = stock_web_texto($stock); ?>
        <?php if ($stp['texto'] !== ''): ?>
          <div class="dato" id="stock-ficha"><span class="dato__k">Stock web</span>
            <span class="dato__t"><span class="chip chip--<?= e($stp['tono']) ?>"><?= e($stp['texto']) ?></span>
              <span class="mini"><?= e(hace($stock['leido_en'] ?? null)) ?></span></span></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($id && !empty($puedo_precios)): ?>
<script>
/* Añadir un tramo es clonar la última fila y vaciarla: sin JS la pantalla
   sigue sirviendo, solo que con los tramos que ya hay. */
(function () {
  var b = document.getElementById('mas-tramo'), cuerpo = document.getElementById('tramos');
  if (!b || !cuerpo) return;
  b.addEventListener('click', function () {
    var ult = cuerpo.rows[cuerpo.rows.length - 1];
    var fila = ult.cloneNode(true);
    fila.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    var hasta = ult.querySelector('input[name="t_hasta[]"]');
    var desde = fila.querySelector('input[name="t_desde[]"]');
    if (hasta && hasta.value) desde.value = (parseInt(hasta.value, 10) || 0) + 1;
    cuerpo.appendChild(fila);
    desde.focus();
  });
})();
</script>
<?php endif; ?>
