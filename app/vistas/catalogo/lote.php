<?php
/* UN LOTE DE PRE VENTA (3h). Tres tarjetas en el orden en que se llena: el
   viaje, los productos y sus precios. Arriba, el interruptor de venta y el
   estado de la carga. */
$l = $lote;
$ro = $puedo ? '' : 'disabled';
$v = fn(string $k) => e((string)($_POST[$k] ?? ($l[$k] ?? '')));
$cmp = $l ? lote_completitud((int)$l['id']) : ['con' => 0, 'sin' => 0];
/* Filas vacías (3h.1): UNA si el lote no tiene productos, ninguna si ya
   tiene. Las demás se añaden con el botón, una o varias de golpe. */
$vacias = $l && !$filas ? 1 : 0;
?>
<header class="cabecera">
  <div class="crece">
    <div class="cabecera__sub"><a href="<?= e(url('/stock/lotes')) ?>">Lotes de pre venta</a></div>
    <h1><?= $l ? e((string)$l['nombre']) : 'Nuevo lote' ?>
      <?php if ($l): ?><span class="mini"><?= e((string)$l['codigo']) ?></span><?php endif; ?></h1>
    <?php if ($l): ?>
      <div class="cabecera__sub">
        <span class="chip chip--linea" id="lote-estado"><?= e(lote_estados()[$l['estado']] ?? '') ?></span>
        <span class="chip <?= (int)$l['disponible'] === 1 ? 'chip--verde' : 'chip--gris' ?>" id="lote-venta"><?= (int)$l['disponible'] === 1 ? 'A la venta' : 'Apagado' ?></span>
        <?php if ((int)$l['disponible'] === 1): ?><span class="mini"><?= plural($cmp['con'], 'producto a la venta', 'productos a la venta') ?><?= $cmp['sin'] ? ', ' . $cmp['sin'] . ' oculto' . ($cmp['sin'] === 1 ? '' : 's') : '' ?></span><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php if ($l && $puedo && !in_array($l['estado'], ['recibido', 'cancelado'], true)): ?>
    <div class="cabecera__acciones">
      <form method="post" id="form-interruptor">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="interruptor">
        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
        <input type="hidden" name="encender" value="<?= (int)$l['disponible'] === 1 ? '0' : '1' ?>">
        <button class="btn <?= (int)$l['disponible'] === 1 ? 'btn--linea' : 'btn--amarillo' ?>" type="submit">
          <?= (int)$l['disponible'] === 1 ? 'APAGAR LA VENTA' : 'PONER A LA VENTA' ?></button>
      </form>
    </div>
  <?php endif; ?>
</header>

<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px" id="lote-errores">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>

<?php /* EL SIGUIENTE PASO (3j): una línea arriba y el cuadro que toca
         resaltado (lote_siguiente_paso). Solo para quien llena el lote. */
$paso = $paso ?? ['paso' => '', 'ancla' => '', 'texto' => '']; ?>
<?php if ($puedo && $paso['paso'] !== ''): ?>
  <a class="paso-sig" id="paso-siguiente" href="#<?= e($paso['ancla']) ?>" data-ancla="<?= e($paso['ancla']) ?>">
    <span class="paso-sig__k">Siguiente paso</span>
    <span class="paso-sig__t"><?= e($paso['texto']) ?></span>
    <span class="paso-sig__ir">IR</span>
  </a>
<?php endif; ?>

<?php /* EL ERROR, TAMBIÉN EN UNA VENTANA (3j, usuario 2026-09-28): dice lo
         que pasa y lleva al campo que hay que corregir. */ ?>
<dialog class="emergente" id="error-emergente" aria-labelledby="error-emergente-t">
  <div class="emergente__cab"><?= ico('alerta',20) ?> <strong id="error-emergente-t">Hay algo que corregir</strong></div>
  <p class="emergente__txt" id="error-emergente-txt"><?= $errores ? e(implode(' ', $errores)) : '' ?></p>
  <div class="acciones">
    <button type="button" class="btn btn--amarillo" id="error-emergente-ir">IR AL CAMPO</button>
    <button type="button" class="btn btn--linea" id="error-emergente-cerrar">CERRAR</button>
  </div>
</dialog>
<div id="lote-js" hidden data-abrir="<?= e((string)($abrir ?? '')) ?>" data-hay-error="<?= $errores ? '1' : '0' ?>"></div>

<?php if ($l && (int)$l['disponible'] === 1 && $cmp['sin'] > 0): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px" id="aviso-ocultos">
    <span><?= ico('alerta',17) ?></span>
    <span><strong>Tienes <?= plural($cmp['sin'], 'producto sin precio', 'productos sin precio') ?>: no lo<?= $cmp['sin'] === 1 ? '' : 's' ?> verán los asesores.</strong>
      El lote ya está a la venta con <?= plural($cmp['con'], 'el que tiene', 'los ' . $cmp['con'] . ' que tienen', false) ?> precio. Salen solos en cuanto les pongas el suyo.</span>
  </div>
<?php endif; ?>

<?php if ($l): ?>
  <div class="tarjeta" style="margin-bottom:12px">
    <?php parte('catalogo/barco', ['viaje' => $viaje, 'lote' => $l]); ?>
    <?php if ($puedo && $l['estado'] !== 'recibido'): ?>
      <form method="post" class="form form--fila" id="form-estado" style="margin-top:10px">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="estado">
        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
        <label>Estado de la carga
          <select name="estado">
            <?php foreach (lote_estados() as $k => $t): ?>
              <option value="<?= e($k) ?>" <?= $l['estado'] === $k ? 'selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn--linea btn--chico" type="submit">CAMBIAR</button>
        <span class="ayuda">Al marcar «Llegó», sus pedidos pasan solos a «Producto llegó» y el lote deja de venderse como pre venta. Ya no se puede cambiar.</span>
      </form>
    <?php endif; ?>
    <?php /* LISTO PARA ENTREGA (3j): después de «Llegó». Hasta que el CEO lo
             marca, los asesores ven «Llega aprox.» en vez de «Mandar». */ ?>
    <?php if ($l['estado'] === 'recibido' && array_key_exists('listo_en', $l)): ?>
      <?php if (empty($l['listo_en'])): ?>
        <?php if ($puedo): ?>
        <form method="post" class="form form--fila" id="form-listo" style="margin-top:10px">
          <?= campo_csrf() ?>
          <input type="hidden" name="accion" value="listo">
          <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <button class="btn btn--amarillo" type="submit">LISTO PARA ENTREGA</button>
          <span class="ayuda">Márcalo cuando el contenedor esté descargado y se pueda repartir. Desde ahí los asesores ya pueden mandar sus pedidos a despacho.</span>
        </form>
        <?php else: ?>
          <p class="mini" style="margin:10px 0 0">Llegó. Falta que lo marquen listo para entrega.</p>
        <?php endif; ?>
      <?php else: ?>
        <div class="form form--fila" id="lote-listo" style="margin-top:10px">
          <span class="chip chip--verde">Listo para entrega</span>
          <span class="mini"><?= e(hace((string)$l['listo_en'])) ?></span>
          <?php if ($puedo): ?>
          <form method="post" style="display:inline">
            <?= campo_csrf() ?>
            <input type="hidden" name="accion" value="listo_deshacer">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button class="chip chip--suave" type="submit" style="cursor:pointer">Deshacer</button>
          </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="tarjeta" id="viaje" style="margin-bottom:12px">
  <div class="tarjeta__cab"><h2>El viaje</h2><span class="mini">Lo que antes iba al pie del Excel</span></div>
  <form method="post" class="form">
    <?= campo_csrf() ?>
    <input type="hidden" name="accion" value="viaje">
    <?php if ($l): ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><?php endif; ?>
    <label>Nombre del lote
      <input type="text" name="nombre" maxlength="140" required value="<?= $v('nombre') ?>" placeholder="Contenedor 2 · 40HQ" <?= $ro ?>>
    </label>
    <div class="form__fila">
      <label>Agencia de carga
        <select name="agencia_item_id" <?= $ro ?>>
          <option value="">Sin elegir</option>
          <?php foreach ($agencias as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= (int)($_POST['agencia_item_id'] ?? ($l['agencia_item_id'] ?? 0)) === (int)$a['id'] ? 'selected' : '' ?>><?= e((string)$a['valor']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$agencias): ?><span class="ayuda">Todavía no hay agencias.
          <?php if (puede('listas.gestionar')): ?><a href="<?= e(url('/configuracion/agencias-carga')) ?>" style="text-decoration:underline">Añádelas aquí</a>.<?php endif; ?></span><?php endif; ?>
      </label>
      <label>Referencia de la agencia
        <input type="text" name="agencia_ref" maxlength="120" value="<?= $v('agencia_ref') ?>" placeholder="HUB262683 | HBL:…" <?= $ro ?>>
      </label>
    </div>
    <div class="form__fila">
      <label>BL <input type="text" name="bl" maxlength="80" value="<?= $v('bl') ?>" placeholder="Por confirmar" <?= $ro ?>></label>
      <label>Factura <input type="text" name="factura" maxlength="80" value="<?= $v('factura') ?>" <?= $ro ?>></label>
    </div>
    <div class="form__fila">
      <label>Fecha de salida <input type="date" name="fecha_salida" value="<?= $v('fecha_salida') ?>" <?= $ro ?>></label>
      <label>Llegada estimada <input type="date" name="fecha_llegada_est" value="<?= $v('fecha_llegada_est') ?>" <?= $ro ?>></label>
    </div>
    <div class="form__fila">
      <label>Puerto de salida <input type="text" name="puerto_origen" maxlength="60" value="<?= $v('puerto_origen') ?>" placeholder="Shenzhen" <?= $ro ?>></label>
      <label>Puerto de llegada <input type="text" name="puerto_destino" maxlength="60" value="<?= $v('puerto_destino') ?>" placeholder="Callao" <?= $ro ?>></label>
    </div>
    <div class="form__fila">
      <label>Canal de aduana
        <select name="canal" <?= $ro ?>>
          <?php foreach (lote_canales() as $k => $t): ?>
            <option value="<?= e($k) ?>" <?= (string)($_POST['canal'] ?? ($l['canal'] ?? '')) === $k ? 'selected' : '' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Responsable
        <select name="responsable_id" <?= $ro ?>>
          <option value="">Sin elegir</option>
          <?php foreach ($responsables as $r): ?>
            <option value="<?= (int)$r['id'] ?>" <?= (int)($_POST['responsable_id'] ?? ($l['responsable_id'] ?? 0)) === (int)$r['id'] ? 'selected' : '' ?>><?= e(trim($r['nombre'] . ' ' . $r['apellidos'])) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="form__fila">
      <label>Almacén <input type="text" name="almacen" maxlength="120" value="<?= $v('almacen') ?>" placeholder="Por confirmar" <?= $ro ?>></label>
      <label>Fecha de almacén <input type="date" name="fecha_almacen" value="<?= $v('fecha_almacen') ?>" <?= $ro ?>></label>
    </div>
    <label>Notas
      <textarea name="notas" maxlength="500" rows="2" placeholder="Sobre estadía, pendientes…" <?= $ro ?>><?= $v('notas') ?></textarea>
    </label>
    <?php if ($puedo): ?>
      <div class="acciones"><button class="btn btn--negro" type="submit"><?= $l ? 'GUARDAR EL VIAJE' : 'CREAR EL LOTE' ?></button></div>
    <?php endif; ?>
  </form>
</div>

<?php if ($l): ?>
<div class="tarjeta" id="productos" style="margin-bottom:12px">
  <div class="tarjeta__cab"><h2>Los productos</h2>
    <span class="mini"><?= (int)$l['unidades_totales'] ?> unidades</span></div>
  <form method="post" class="form">
    <?= campo_csrf() ?>
    <input type="hidden" name="accion" value="filas">
    <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
    <div class="tabla__caja">
      <table class="tabla tabla--form" id="lote-filas">
        <thead><tr><th>Código</th><th>Producto</th><th>Modelo / color</th><th>¿Es repuesto?</th><th class="der">Unidades</th><th>Nuevo</th><th class="der">Vendidas</th><?php if ($puedo): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php $i = 0; foreach ($filas as $f): $bloq = $f['vendidas'] > 0 || !$puedo ? 'readonly' : ''; ?>
          <tr class="<?= (int)$f['resuelto'] === 0 ? 'fila--revisa' : '' ?>" id="fila-<?= (int)$f['id'] ?>">
            <td data-k="Código"><input type="hidden" name="f_id[]" value="<?= (int)$f['id'] ?>">
              <input type="text" name="f_codigo[]" maxlength="60" value="<?= e((string)(($f['codigo'] ?? '') !== '' ? $f['codigo'] : ($f['producto_sku'] ?? ''))) ?>" <?= $bloq ?> style="max-width:130px"></td>
            <td data-k="Producto"><input type="text" name="f_nombre[]" maxlength="180" value="<?= e((string)$f['producto_nombre']) ?>" <?= $bloq ?>>
              <?php if ((int)$f['resuelto'] === 0): ?><div class="fila__s"><span class="chip chip--ambar">Revisa</span> <?= e((string)($f['aviso'] ?? '')) ?>
                <?php if ((string)($f['texto_origen'] ?? '') !== ''): ?>· decía «<?= e((string)$f['texto_origen']) ?>»<?php endif; ?>
                <?php if ($puedo): ?><label class="check"><input type="checkbox" name="f_ok[]" value="<?= $i ?>"> Ya lo revisé</label><?php endif; ?>
                <span class="mini">Hasta entonces el asesor no la ve.</span></div><?php endif; ?></td>
            <td data-k="Modelo"><input type="text" name="f_modelo[]" maxlength="120" value="<?= e((string)$f['modelo']) ?>" placeholder="Única" <?= $bloq ?>></td>
            <td data-k="¿Es repuesto?"><?php parte('catalogo/lote_maquina', ['f' => $f, 'bloq' => $bloq !== '', 'con_repuestos' => $con_repuestos ?? true]); ?></td>
            <td data-k="Unidades" class="der"><input type="text" name="f_unidades[]" inputmode="numeric" value="<?= e((string)$f['unidades']) ?>" style="max-width:90px" <?= $puedo ? '' : 'readonly' ?>></td>
            <td data-k="Nuevo"><label class="check"><input type="checkbox" name="f_nuevo[]" value="<?= $i ?>" <?= (int)$f['nuevo'] === 1 ? 'checked' : '' ?> <?= $ro ?>> Nuevo</label></td>
            <td data-k="Vendidas" class="der num"><?= (int)$f['vendidas'] ?><?= $f['quedan'] === 0 ? ' <span class="chip chip--rojo">Agotado</span>' : '' ?></td>
            <?php if ($puedo): ?><td class="lote-fila__quitar"><?php if ((int)$f['vendidas'] === 0): ?><button type="button" class="btn btn--linea btn--chico quitar-fila" aria-label="Quitar esta fila">Quitar</button><?php else: ?><span class="mini">Tiene ventas</span><?php endif; ?></td><?php endif; ?>
          </tr>
        <?php $i++; endforeach; ?>
        <?php if ($puedo): for ($k = 0; $k < $vacias; $k++): ?>
          <tr class="fila-vacia">
            <td data-k="Código"><input type="hidden" name="f_id[]" value="0"><input type="text" name="f_codigo[]" maxlength="60" placeholder="PRD-000000" style="max-width:130px"></td>
            <td data-k="Producto"><input type="text" name="f_nombre[]" maxlength="180" placeholder="Nombre del producto"></td>
            <td data-k="Modelo"><input type="text" name="f_modelo[]" maxlength="120" placeholder="Única, o «10 rojos, 10 azules»"></td>
            <td data-k="¿Es repuesto?"><?php parte('catalogo/lote_maquina', ['f' => null, 'bloq' => false, 'con_repuestos' => $con_repuestos ?? true]); ?></td>
            <td data-k="Unidades" class="der"><input type="text" name="f_unidades[]" inputmode="numeric" style="max-width:90px"></td>
            <td data-k="Nuevo"><label class="check"><input type="checkbox" name="f_nuevo[]" value="<?= $i ?>"> Nuevo</label></td>
            <td data-k="Vendidas" class="der num">—</td>
            <td class="lote-fila__quitar"><button type="button" class="btn btn--linea btn--chico quitar-fila" aria-label="Quitar esta fila">Quitar</button></td>
          </tr>
        <?php $i++; endfor; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($puedo): ?>
    <template id="fila-molde">
      <tr class="fila-vacia">
        <td data-k="Código"><input type="hidden" name="f_id[]" value="0"><input type="text" name="f_codigo[]" maxlength="60" placeholder="PRD-000000" style="max-width:130px"></td>
        <td data-k="Producto"><input type="text" name="f_nombre[]" maxlength="180" placeholder="Nombre del producto"></td>
        <td data-k="Modelo"><input type="text" name="f_modelo[]" maxlength="120" placeholder="Única, o «10 rojos, 10 azules»"></td>
        <td data-k="¿Es repuesto?"><?php parte('catalogo/lote_maquina', ['f' => null, 'bloq' => false, 'con_repuestos' => $con_repuestos ?? true]); ?></td>
        <td data-k="Unidades" class="der"><input type="text" name="f_unidades[]" inputmode="numeric" style="max-width:90px"></td>
        <td data-k="Nuevo"><label class="check"><input type="checkbox" name="f_nuevo[]" value="0"> Nuevo</label></td>
        <td data-k="Vendidas" class="der num">—</td>
        <td class="lote-fila__quitar"><button type="button" class="btn btn--linea btn--chico quitar-fila" aria-label="Quitar esta fila">Quitar</button></td>
      </tr>
    </template>
    <?php endif; ?>
    <?php if ($puedo): ?>
      <div class="lote-mas" id="lote-mas">
        <button type="button" class="btn btn--linea" id="mas-fila"><?= ico('mas',15) ?> Añadir fila</button>
        <span class="lote-mas__n">o añadir
          <input type="text" id="mas-n" inputmode="numeric" value="5" maxlength="2" aria-label="Cuántas filas añadir">
          <button type="button" class="btn btn--linea btn--chico" id="mas-varias">filas</button></span>
      </div>
      <span class="ayuda">Si el código ya existe, se enlaza con ese producto. Varios colores en una fila: «12 moradas, 15 naranjas»
        (si suman las unidades, se parte en una fila por color). Lo que ya tiene ventas solo cambia en unidades. Las filas en blanco no se guardan.</span>
      <span class="ayuda" id="ayuda-repuesto"><strong>¿Es repuesto?</strong> Márcalo si la fila es una pieza de otra máquina y elige
        de cuál (del lote o del catálogo). Un kit («2 botones, 2 joysticks») se parte en una fila por pieza. Los repuestos no se venden
        en pre venta: entran al almacén cuando el lote llega.</span>
      <?php /* Las máquinas que se pueden elegir, una sola vez en la página:
               cada fila las copia al marcar la casilla. */ ?>
      <template id="maquinas-molde">
        <option value="">Elige la máquina</option>
        <?php if ($maquinas['lote']): ?><optgroup label="De este lote" data-grupo="lote">
          <?php foreach ($maquinas['lote'] as $m): ?><option value="<?= e($m['sku']) ?>"><?= e($m['nombre'] . ' · ' . $m['sku']) ?></option><?php endforeach; ?>
        </optgroup><?php endif; ?>
        <?php if ($maquinas['catalogo']): ?><optgroup label="Del catálogo">
          <?php foreach ($maquinas['catalogo'] as $m): ?><option value="<?= e($m['sku']) ?>"><?= e($m['nombre'] . ' · ' . $m['sku']) ?></option><?php endforeach; ?>
        </optgroup><?php endif; ?>
      </template>
      <span class="ayuda" id="ayuda-nuevo"><strong>Nuevo</strong>: un producto que antes no vendíamos. El asesor lo ve con la etiqueta «Nuevo» en la pre venta.</span>
      <div class="acciones">
        <button class="btn btn--negro" type="submit">GUARDAR LOS PRODUCTOS</button>
      </div>
    <?php endif; ?>
  </form>
</div>

<div class="tarjeta" id="precios" style="margin-bottom:12px">
  <div class="tarjeta__cab"><h2>Precios de pre venta</h2><span class="mini">El precio baja al comprar más</span></div>
  <?php if (!$productos): ?><p class="mini" style="margin:0">Primero pon los productos del lote.</p><?php endif; ?>
  <?php foreach ($productos as $p): $tr = $p['tramos'] ?: [['desde' => 1, 'hasta' => null, 'precio_centimos' => null, 'alias' => '']]; ?>
    <form method="post" class="form lote-precio" id="precio-<?= (int)$p['id'] ?>">
      <?= campo_csrf() ?>
      <input type="hidden" name="accion" value="precios">
      <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
      <input type="hidden" name="producto_id" value="<?= (int)$p['id'] ?>">
      <div class="lote-precio__cab">
        <strong><?= e($p['nombre']) ?></strong> <span class="mini"><?= e($p['sku']) ?> · quedan <?= (int)$p['quedan'] ?> de <?= (int)$p['unidades'] ?></span>
        <?php if (!$p['completo']): ?><span class="chip chip--ambar">Oculto: sin precio</span><?php endif; ?>
      </div>
      <div class="tabla__caja">
        <table class="tabla tabla--form">
          <thead><tr><th>Desde</th><th>Hasta</th><th>Precio por unidad</th><th>Cómo lo llama el asesor</th></tr></thead>
          <tbody class="tramos">
          <?php foreach ($tr as $t): ?>
            <tr>
              <td data-k="Desde"><input type="text" name="t_desde[]" inputmode="numeric" style="max-width:80px" value="<?= (int)$t['desde'] ?>" <?= $ro ?>></td>
              <td data-k="Hasta"><input type="text" name="t_hasta[]" inputmode="numeric" style="max-width:80px" placeholder="a más" value="<?= $t['hasta'] === null ? '' : (int)$t['hasta'] ?>" <?= $ro ?>></td>
              <td data-k="Precio"><input type="text" name="t_precio[]" inputmode="decimal" style="max-width:120px" value="<?= $t['precio_centimos'] === null ? '' : e(soles((int)$t['precio_centimos'], false)) ?>" <?= $ro ?>></td>
              <td data-k="Alias"><input type="text" name="t_alias[]" maxlength="60" placeholder="Por 10 · mayorista" value="<?= e((string)($t['alias'] ?? '')) ?>" <?= $ro ?>></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($puedo): ?>
        <div class="acciones">
          <button type="button" class="btn btn--linea btn--chico mas-tramo"><?= ico('mas',15) ?> Añadir tramo</button>
          <button class="btn btn--negro btn--chico" type="submit">GUARDAR PRECIOS</button>
          <span class="lote-precio__ok" aria-live="polite"></span>
        </div>
      <?php endif; ?>
    </form>
  <?php endforeach; ?>
  <?php if ($productos && $puedo): ?><span class="ayuda">Los tramos van seguidos y el último se queda sin «hasta». Todos los colores del producto llevan el mismo precio.</span>
    <?php /* UN BOTÓN PARA TODOS (3j): guarda los precios de cada producto,
             uno detrás de otro, sin recargar la página. */ ?>
    <div class="acciones lote-precios-todos">
      <button type="button" class="btn btn--amarillo" id="guardar-todos-precios">GUARDAR TODOS LOS PRECIOS</button>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($puedo && $l): ?>
<script>
/* Añadir filas de producto (una o varias), quitarlas, y añadir tramos. */
(function () {
  var t = document.querySelector('#lote-filas tbody'), molde = document.getElementById('fila-molde');
  /* Las casillas «Nuevo» y «Ya lo revisé» dicen a qué fila van por su número:
     al quitar o añadir filas se vuelven a numerar en orden. */
  function numerar() {
    if (!t) return;
    Array.prototype.forEach.call(t.rows, function (r, k) {
      r.querySelectorAll('input[name="f_nuevo[]"], input[name="f_ok[]"]').forEach(function (c) { c.value = String(k); });
    });
  }
  /* La pantalla no salta: lo que estaba en su sitio sigue en su sitio. */
  function quieto(ancla, cambiar) {
    var antes = ancla.getBoundingClientRect().top;
    cambiar();
    window.scrollBy(0, ancla.getBoundingClientRect().top - antes);
  }
  function anadir(n) {
    if (!t || !molde) return;
    n = Math.max(1, Math.min(50, n || 1));
    var primera = null;
    quieto(document.getElementById('lote-mas'), function () {
      for (var k = 0; k < n; k++) {
        var f = molde.content.firstElementChild.cloneNode(true);
        t.appendChild(f); if (!primera) primera = f;
      }
      numerar();
    });
    /* El cursor a la primera fila nueva, sin mover la pantalla. En el celular
       no: abrir el teclado sí la movería. */
    var fino = window.matchMedia && window.matchMedia('(pointer: fine)').matches;
    var c = fino && primera && primera.querySelector('input[type=text]');
    if (c) { try { c.focus({preventScroll: true}); } catch (e) { c.focus(); } }
  }
  var b1 = document.getElementById('mas-fila'), bn = document.getElementById('mas-varias'), cn = document.getElementById('mas-n');
  if (b1) b1.addEventListener('click', function () { anadir(1); });
  if (bn) bn.addEventListener('click', function () { anadir(parseInt(cn.value, 10) || 1); });
  /* Enter en «cuántas» añade las filas; no guarda el formulario. */
  if (cn) cn.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter') { ev.preventDefault(); anadir(parseInt(cn.value, 10) || 1); }
  });
  if (t) t.addEventListener('click', function (ev) {
    var q = ev.target.closest('.quitar-fila'); if (!q) return;
    var fila = q.closest('tr'), sig = fila.nextElementSibling;
    quieto(sig || document.getElementById('lote-mas'), function () { fila.remove(); numerar(); });
  });
  /* ── ¿ES REPUESTO? (3j) ─────────────────────────────────────────
     La casilla enseña la lista de máquinas; lo elegido va al campo
     escondido f_maquina[] de la fila. Las del lote se leen también de las
     filas escritas y todavía sin guardar que ya tienen código. */
  var moldeM = document.getElementById('maquinas-molde');
  function llenarMaquinas(sel) {
    if (!moldeM) return;
    var actual = sel.getAttribute('data-actual') || '', elegido = sel.value || actual;
    sel.innerHTML = '';
    sel.appendChild(moldeM.content.cloneNode(true));
    /* Las máquinas escritas en este lote y aún sin guardar. */
    var ya = {};
    Array.prototype.forEach.call(sel.options, function (o) { ya[o.value] = true; });
    var extra = [];
    if (t) Array.prototype.forEach.call(t.rows, function (r) {
      var es = r.querySelector('.maq-es'), cod = r.querySelector('input[name^=f_codigo]'), nom = r.querySelector('input[name^=f_nombre]');
      if ((es && es.checked) || !cod) return;
      var c = cod.value.trim().toUpperCase();
      if (c && !ya[c]) { ya[c] = true; extra.push([c, (nom && nom.value.trim() ? nom.value.trim() + ' · ' : '') + c]); }
    });
    if (extra.length) {
      var g = sel.querySelector('optgroup[data-grupo="lote"]');
      if (!g) { g = document.createElement('optgroup'); g.label = 'De este lote'; g.setAttribute('data-grupo', 'lote'); sel.insertBefore(g, sel.children[1] || null); }
      extra.forEach(function (x) { var o = document.createElement('option'); o.value = x[0]; o.textContent = x[1]; g.appendChild(o); });
    }
    if (elegido && !ya[elegido]) {
      var o = document.createElement('option'); o.value = elegido;
      o.textContent = (sel.getAttribute('data-actual-nombre') || elegido) + ' · no la encuentro';
      sel.appendChild(o);
    }
    sel.value = elegido;
  }
  function filaMaquina(r) {
    var es = r.querySelector('.maq-es'), sel = r.querySelector('.maq-elige'), val = r.querySelector('.maq-valor');
    if (!es || !sel || !val) return;
    sel.hidden = !es.checked;
    if (es.checked) { if (!sel.options.length) llenarMaquinas(sel); val.value = sel.value; }
    else val.value = '';
  }
  if (t) {
    Array.prototype.forEach.call(t.rows, function (r) { var sel = r.querySelector('.maq-elige'); if (sel && !sel.hidden) llenarMaquinas(sel); });
    t.addEventListener('change', function (ev) {
      var r = ev.target.closest('tr'); if (!r) return;
      if (ev.target.classList.contains('maq-es')) {
        var sel = r.querySelector('.maq-elige');
        if (ev.target.checked && sel) { llenarMaquinas(sel); filaMaquina(r); try { sel.focus(); } catch (e) {} }
        else filaMaquina(r);
      }
      if (ev.target.classList.contains('maq-elige')) filaMaquina(r);
    });
    /* Marcado como repuesto y sin máquina elegida: no se guarda así (se
       guardaría como producto normal sin decir nada). */
    var formF = t.closest('form');
    if (formF) formF.addEventListener('submit', function (ev) {
      var escritas = window.loteFilasEscritas ? window.loteFilasEscritas() : [];
      for (var k = 0; k < escritas.length; k++) {
        var r = escritas[k], es = r.querySelector('.maq-es'), sel = r.querySelector('.maq-elige');
        if (es && es.checked && sel && !sel.value) {
          ev.preventDefault();
          window.loteError && window.loteError('Fila ' + (k + 1) + ': elige de qué máquina es el repuesto.', 'productos');
          return;
        }
      }
    });
    /* Al abrir la lista, se vuelve a leer lo escrito en el lote. */
    t.addEventListener('focusin', function (ev) {
      if (ev.target.classList && ev.target.classList.contains('maq-elige')) llenarMaquinas(ev.target);
    });
  }

  document.querySelectorAll('.mas-tramo').forEach(function (bt) {
    bt.addEventListener('click', function () {
      var c = bt.closest('form').querySelector('.tramos'), u = c.rows[c.rows.length - 1], n = u.cloneNode(true);
      n.querySelectorAll('input').forEach(function (i) { i.value = ''; });
      var h = u.querySelector('input[name="t_hasta[]"]'), d = n.querySelector('input[name="t_desde[]"]');
      if (h && h.value) d.value = (parseInt(h.value, 10) || 0) + 1;
      c.appendChild(n); d.focus();
    });
  });

  /* ── LOS PRECIOS, SIN RECARGAR (3j) ──────────────────────────────
     Guardar un producto no reinicia la página: lo escrito en los demás
     sigue ahí. «Guardar todos» los manda uno detrás de otro y para en el
     primero que tenga un error, con la ventana que lleva al campo. */
  function guardarPrecio(f) {
    var datos = new FormData(f); datos.append('ajax', '1');
    var ok = f.querySelector('.lote-precio__ok');
    if (ok) { ok.textContent = 'Guardando…'; ok.className = 'lote-precio__ok'; }
    return fetch(f.getAttribute('action') || location.href, {method: 'POST', body: datos, credentials: 'same-origin',
                  headers: {'Accept': 'application/json'}})
      .then(function (r) {
        var tipo = r.headers.get('Content-Type') || '';
        if (tipo.indexOf('json') < 0) return {ok: false, error: r.status === 403 || r.status === 419
          ? 'Tu sesión cambió. Recarga la página y vuelve a guardar.' : 'No se pudo guardar. Recarga la página y vuelve a intentarlo.'};
        return r.json();
      })
      .catch(function () { return {ok: false, error: 'No se pudo guardar: revisa tu conexión y vuelve a intentarlo.'}; })
      .then(function (j) {
        if (ok) { ok.textContent = j.ok ? 'Guardado ✓' : ''; ok.className = 'lote-precio__ok' + (j.ok ? ' lote-precio__ok--si' : ''); }
        if (j.ok) {
          f.removeAttribute('data-cambiado');
          var ch = f.querySelector('.lote-precio__cab .chip--ambar'); if (ch) ch.remove();
          if (j.siguiente && window.loteSiguiente) window.loteSiguiente(j.siguiente);
        }
        return j;
      });
  }
  document.querySelectorAll('form.lote-precio').forEach(function (f) {
    f.addEventListener('input', function () {
      f.setAttribute('data-cambiado', '1');
      var ok = f.querySelector('.lote-precio__ok'); if (ok) { ok.textContent = ''; ok.className = 'lote-precio__ok'; }
    });
    f.addEventListener('submit', function (ev) {
      if (!window.fetch || !window.FormData) return;          // sin esto, como antes
      ev.preventDefault();
      guardarPrecio(f).then(function (j) {
        if (j.ok) window.loteAviso && window.loteAviso('Precios guardados.');
        else window.loteError && window.loteError(j.error, f.id, f);
      });
    });
  });
  var todos = document.getElementById('guardar-todos-precios');
  if (todos) todos.addEventListener('click', function () {
    if (!window.fetch || !window.FormData) return;
    /* Solo los que cambiaron: lo que no se tocó no se vuelve a guardar. */
    var forms = Array.prototype.slice.call(document.querySelectorAll('form.lote-precio[data-cambiado]'));
    if (!forms.length) { window.loteAviso && window.loteAviso('No hay precios nuevos por guardar.'); return; }
    var n = 0;
    todos.disabled = true; todos.textContent = 'GUARDANDO…';
    (function siguiente(k) {
      if (k >= forms.length) {
        todos.disabled = false; todos.textContent = 'GUARDAR TODOS LOS PRECIOS';
        window.loteAviso && window.loteAviso(n === 1 ? 'Se guardó 1 producto.' : 'Se guardaron ' + n + ' productos.');
        return;
      }
      guardarPrecio(forms[k]).then(function (j) {
        if (!j.ok) {
          todos.disabled = false; todos.textContent = 'GUARDAR TODOS LOS PRECIOS';
          window.loteError && window.loteError(j.error, forms[k].id, forms[k]);
          return;
        }
        n++; siguiente(k + 1);
      });
    })(0);
  });
})();
</script>
<?php endif; ?>

<script>
/* ── EL SIGUIENTE PASO Y LA VENTANA DEL ERROR (3j) ────────────────────
   El cuadro que toca se resalta; y un error, además del aviso de arriba,
   sale en una ventana que dice qué pasa y lleva al campo que hay que
   corregir. */
(function () {
  var marca = null;
  function resaltar(ancla) {
    if (marca) marca.classList.remove('paso-activo');
    marca = ancla ? document.getElementById(ancla) : null;
    if (marca) marca.classList.add('paso-activo');
  }
  var banda = document.getElementById('paso-siguiente');
  if (banda) resaltar(banda.getAttribute('data-ancla'));
  window.loteSiguiente = function (sig) {
    if (!banda) return;
    if (!sig || !sig.paso) { banda.hidden = true; resaltar(''); return; }
    banda.hidden = false;
    banda.setAttribute('href', '#' + sig.ancla); banda.setAttribute('data-ancla', sig.ancla);
    var tx = banda.querySelector('.paso-sig__t'); if (tx) tx.textContent = sig.texto;
    resaltar(sig.ancla);
  };

  /* Un aviso arriba, que se va solo. */
  window.loteAviso = function (txt) {
    var a = document.createElement('div');
    a.className = 'aviso aviso--verde aviso-flota'; a.setAttribute('role', 'status'); a.textContent = txt;
    document.body.appendChild(a);
    setTimeout(function () { a.classList.add('aviso-flota--fuera'); }, 2600);
    setTimeout(function () { a.remove(); }, 3200);
  };

  /* «Fila N» cuenta solo las filas escritas, como el servidor: las que están
     en blanco no cuentan (y no se guardan). */
  window.loteFilasEscritas = function () {
    return Array.prototype.filter.call(document.querySelectorAll('#lote-filas tbody tr'), function (r) {
      return Array.prototype.some.call(r.querySelectorAll('input[type=text]'), function (i) { var v = i.value.trim(); return v !== '' && v !== '0'; });
    });
  };
  /* A qué campo lleva cada error: por lo que dice y por el cuadro donde pasó. */
  function campoDelError(txt, donde, form) {
    txt = (txt || '').toLowerCase();
    var q = function (sel, raiz) { return (raiz || document).querySelector(sel); };
    if (donde === 'productos') {
      var m = /fila (\d+)/.exec(txt), filas = window.loteFilasEscritas();
      var r = m ? filas[parseInt(m[1], 10) - 1] : null;
      if (r) {
        if (txt.indexOf('máquina') >= 0) return q('.maq-elige:not([hidden])', r) || q('.maq-es', r);
        if (txt.indexOf('unidad') >= 0) return q('input[name^=f_unidades]', r);
        if (txt.indexOf('código') >= 0) return q('input[name^=f_codigo]', r);
        if (txt.indexOf('color') >= 0 || txt.indexOf('modelo') >= 0) return q('input[name^=f_modelo]', r);
        return q('input[name^=f_nombre]', r);
      }
      return q('#productos input[type=text]');
    }
    if (donde === 'viaje') {
      var mapa = [['nombre', 'nombre'], ['llegada', 'fecha_llegada_est'], ['salida', 'fecha_salida'],
                  ['almacén', 'fecha_almacen'], ['agencia', 'agencia_item_id'], ['responsable', 'responsable_id']];
      for (var i = 0; i < mapa.length; i++) if (txt.indexOf(mapa[i][0]) >= 0) return q('#viaje [name="' + mapa[i][1] + '"]');
      return q('#viaje input[type=text]');
    }
    form = form || (donde ? document.getElementById(donde) : null);
    if (form && form.classList && form.classList.contains('lote-precio')) {
      var col = txt.indexOf('desde') >= 0 && txt.indexOf('hasta') < 0 ? 't_desde'
              : (txt.indexOf('hasta') >= 0 || txt.indexOf('seguidos') >= 0 || txt.indexOf('terminar') >= 0 ? 't_hasta' : 't_precio');
      var ins = form.querySelectorAll('input[name="' + col + '[]"]');
      for (var k = 0; k < ins.length; k++) if (col !== 't_precio' || !ins[k].value.trim()) return ins[k];
      return ins[0] || q('input', form);
    }
    var caja = donde ? document.getElementById(donde) : null;
    return (caja && q('select, input:not([type=hidden]), button', caja)) || q('#form-estado select') || q('#form-listo button') || q('#form-interruptor button');
  }

  var dlg = document.getElementById('error-emergente'), destino = null;
  window.loteError = function (txt, donde, form) {
    destino = campoDelError(txt, donde, form);
    var p = document.getElementById('error-emergente-txt'); if (p) p.textContent = txt;
    if (dlg && dlg.showModal) { try { dlg.showModal(); return; } catch (e) {} }
    if (destino) irAlCampo();
  };
  function irAlCampo() {
    if (dlg && dlg.open) dlg.close();
    if (!destino) return;
    var d = destino.closest('details'); if (d) d.open = true;
    destino.scrollIntoView({block: 'center'});
    try { destino.focus({preventScroll: true}); } catch (e) { destino.focus(); }
    destino.classList.add('campo-error');
    setTimeout(function () { destino.classList.remove('campo-error'); }, 4000);
  }
  var bIr = document.getElementById('error-emergente-ir'), bNo = document.getElementById('error-emergente-cerrar');
  if (bIr) bIr.addEventListener('click', irAlCampo);
  if (bNo) bNo.addEventListener('click', function () { dlg.close(); });

  var js = document.getElementById('lote-js');
  if (js && js.getAttribute('data-hay-error') === '1') {
    var abrir = js.getAttribute('data-abrir') || '';
    var txt = (document.getElementById('error-emergente-txt') || {}).textContent || '';
    window.loteError(txt, abrir || 'estado', abrir.indexOf('precio-') === 0 ? document.getElementById(abrir) : null);
  }
})();
</script>
