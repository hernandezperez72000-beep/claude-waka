<header class="cabecera">
  <div>
    <div class="cabecera__sub"><a href="<?= e(url('/pedidos')) ?>">Pedidos</a></div>
    <h1>Descuentos por aprobar</h1>
    <div class="cabecera__sub">Pasan del <?= (int)$tope_pct ?> % que puede dar el asesor por su cuenta</div>
  </div>
</header>

<?php if (!$filas): ?>
  <div class="tarjeta" id="descuentos-vacio">
    <p style="margin:0">No hay descuentos esperando. Cuando un asesor pida uno que pase del tope, te sonará un aviso.</p>
  </div>
<?php else: ?>
  <div class="tarjeta" id="lista-descuentos">
    <div class="tabla__caja">
      <table class="tabla">
        <thead><tr><th>Venta</th><th>Asesor</th><th class="der">Descuento</th><th>Motivo</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($filas as $f): ?>
          <?php $m = (int)$f['descuento_pedido_centimos']; ?>
          <tr>
            <td class="principal">
              <a href="<?= e(url('/pedidos/ficha?id=' . (int)$f['id'])) ?>" style="color:inherit">
                <div class="fila__t"><?= e($f['codigo']) ?></div>
                <div class="fila__s"><?= e(trim((string)$f['cliente'] . ' ' . (string)$f['cliente_ap'])) ?></div></a>
            </td>
            <td data-k="Asesor"><?= e(trim((string)$f['asesor'] . ' ' . (string)$f['asesor_ap'])) ?></td>
            <td class="der num" data-k="Descuento"><strong><?= e(soles($m)) ?></strong>
              <div class="fila__s"><?= e(descuento_pct_texto($m, (int)$f['subtotal_centimos'])) ?> de <?= e(soles((int)$f['subtotal_centimos'])) ?></div></td>
            <td data-k="Motivo"><?= e((string)$f['descuento_motivo']) ?></td>
            <td class="der">
              <div class="desc-botones">
                <form method="post" action="<?= e(url('/pedidos/descuento')) ?>">
                  <?= campo_csrf() ?>
                  <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                  <input type="hidden" name="accion" value="aprobar">
                  <input type="hidden" name="volver" value="lista">
                  <button class="btn btn--negro btn--chico" type="submit">APROBAR</button>
                </form>
                <form method="post" action="<?= e(url('/pedidos/descuento')) ?>">
                  <?= campo_csrf() ?>
                  <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                  <input type="hidden" name="accion" value="rechazar">
                  <input type="hidden" name="volver" value="lista">
                  <button class="btn btn--linea btn--chico" type="submit">RECHAZAR</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="mini" style="margin:12px 0 0">Para rechazar con una nota para el asesor, abre la venta.</p>
  </div>
<?php endif; ?>
