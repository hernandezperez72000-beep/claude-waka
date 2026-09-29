<?php
/* El stock de la web que espera (3e). */
$que = fn(array $f) => $f['tipo'] === 'devolucion' ? 'Devolver' : 'Descontar';
$venta = function (array $f) {
    /* Una garantía (3i) mueve la web sin pedido propio: va colgada de ella. */
    if (!empty($f['garantia_id'])) return '<a href="' . e(url('/garantias/ver?id=' . (int)$f['garantia_id'])) . '" style="color:inherit"><div class="fila__t">Garantía '
         . e(garantia_codigo((int)$f['garantia_id'])) . '</div></a>';
    if ($f['pedido_id'] === null) return '<span class="fila__s">Venta que no llegó a guardarse</span>';
    return '<a href="' . e(url('/pedidos/ficha?id=' . (int)$f['pedido_id'])) . '" style="color:inherit"><div class="fila__t">'
         . e((string)($f['pedido_codigo'] ?? ('#' . (int)$f['pedido_id']))) . '</div></a>';
};
?>
<header class="cabecera">
  <div>
    <div class="cabecera__sub"><a href="<?= e(url('/stock')) ?>">Stock</a></div>
    <h1>Stock por mover en la web</h1>
    <div class="cabecera__sub">Lo que las ventas tienen que descontar o devolver en la tienda</div>
  </div>
</header>

<?php if ($revisar): ?>
  <div class="tarjeta" id="lista-revisar" style="margin-bottom:14px">
    <div class="tarjeta__cab"><h2>Para revisar</h2><span class="chip chip--rojo"><?= count($revisar) ?></span></div>
    <p class="mini" style="margin:0 0 10px">Se vendió cuando la web ya no tenía y se descontó igual, o la tienda lo movió
      después de darlo por resuelto. Revisa el stock en la ficha del producto.</p>
    <div class="tabla__caja">
      <table class="tabla">
        <thead><tr><th>Venta</th><th>Qué pasó</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($revisar as $f): ?>
          <tr>
            <td class="principal"><?= $venta($f) ?><div class="fila__s"><?= e(fecha_corta((string)$f['hecho_en'])) ?></div></td>
            <td data-k="Qué pasó"><?= e((string)($f['negativo'] ?: $f['nota'])) ?><div class="fila__s"><?= e((string)$f['detalle']) ?></div></td>
            <td class="der">
              <form method="post">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="visto">
                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                <button class="btn btn--linea btn--chico" type="submit">YA LO VI</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if (!$pendientes): ?>
  <div class="tarjeta" id="pendientes-vacio">
    <p style="margin:0">Nada esperando: la web está al día con las ventas.
      <?php if (!$control): ?>El control de stock está apagado en Configuración › Tienda.<?php endif; ?></p>
  </div>
<?php else: ?>
  <div class="tarjeta" id="lista-pendientes">
    <div class="tarjeta__cab"><h2>Esperando a la tienda</h2>
      <form method="post">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="reintentar">
        <button class="btn btn--negro btn--chico" type="submit">REINTENTAR TODO</button>
      </form>
    </div>
    <p class="mini" style="margin:0 0 10px">Se reintenta solo cada pocos minutos. Si ya lo arreglaste a mano en la web,
      dalo por resuelto.</p>
    <div class="tabla__caja">
      <table class="tabla">
        <thead><tr><th>Venta</th><th>Qué</th><th>Por qué espera</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pendientes as $f): ?>
          <tr>
            <td class="principal"><?= $venta($f) ?><div class="fila__s"><?= e(hace((string)$f['creado_en'])) ?></div></td>
            <td data-k="Qué"><strong><?= e($que($f)) ?></strong><div class="fila__s"><?= e((string)$f['detalle']) ?></div></td>
            <td data-k="Por qué espera"><?php if ((int)$f['revisar']): ?><span class="chip chip--rojo">No se arregla solo</span> <?php endif; ?><?= e(stock_cola_error_texto($f)) ?>
              <?php if ((int)$f['intentos'] > 0): ?><div class="fila__s"><?= (int)$f['intentos'] ?> <?= (int)$f['intentos'] === 1 ? 'intento' : 'intentos' ?></div><?php endif; ?></td>
            <td class="der">
              <div class="desc-botones">
                <form method="post">
                  <?= campo_csrf() ?>
                  <input type="hidden" name="accion" value="reintentar">
                  <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                  <button class="btn btn--linea btn--chico" type="submit">REINTENTAR</button>
                </form>
                <details class="resolver">
                  <summary class="btn btn--linea btn--chico">YA LO ARREGLÉ</summary>
                  <form method="post" class="form" style="margin-top:6px">
                    <?= campo_csrf() ?>
                    <input type="hidden" name="accion" value="resolver">
                    <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                    <?php $dev = $f['tipo'] === 'devolucion'; ?>
                    <label class="resolver__op"><input type="radio" name="como" value="a_mano" required>
                      <?= $dev ? 'Lo devolví a mano en la web' : 'Lo desconté a mano en la web' ?></label>
                    <label class="resolver__op"><input type="radio" name="como" value="no_hace_falta">
                      <?= $dev ? 'No hace falta devolverlo' : 'No hace falta descontarlo' ?></label>
                    <input type="text" name="nota" maxlength="150" required placeholder="Qué pasó">
                    <button class="btn btn--negro btn--chico" type="submit" style="margin-top:6px">GUARDAR</button>
                  </form>
                </details>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
