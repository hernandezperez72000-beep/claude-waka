<?php /* EL WHATSAPP, JUSTO DESPUÉS DE RECLAMAR, ARRIBA DEL TODO (3b.5: iba
         debajo de la lista de ventas listas y el asesor no lo veía). El HUB no
         puede mandarlo solo —haría falta una API—, así que deja el mensaje
         escrito y el asesor le da a enviar. */ ?>
<?php $ped_wa = null;
      foreach ($frenados as $f_wa) if ((int)$f_wa['id'] === (int)$reclamado) $ped_wa = $f_wa; ?>
<?php if ($ped_wa && (string)($reclamado_wa ?? '') !== ''): ?>
  <div class="aviso aviso--amarillo aviso--reclamo" id="aviso-reclamo-wa" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><strong>Reclamo enviado.</strong> Facturación ve <?= e((string)$ped_wa['codigo']) ?>
      primero en su bandeja. Si corre prisa, avísale también por WhatsApp:</span>
    <a class="btn btn--negro" target="_blank" rel="noopener"
       href="<?= e((string)$reclamado_wa) ?>" style="margin-left:auto">AVISAR POR WHATSAPP</a>
  </div>
<?php elseif ($ped_wa): ?>
  <div class="aviso aviso--gris" style="margin-bottom:14px">
    <span><?= ico('check',17) ?></span>
    <span><strong>Reclamo enviado.</strong> Facturación ve <?= e((string)$ped_wa['codigo']) ?>
      primero en su bandeja.</span>
  </div>
<?php elseif ((int)($reclamado ?? 0) > 0
              && in_array((int)$reclamado, array_map(fn($x) => (int)$x['id'], $pedidos), true)): ?>
  <?php /* Se reclamó y la venta ya no está en la lista: entre el aviso y esta
           pantalla, facturación la confirmó. Sin esta rama el asesor veía
           «Reclamo anotado» y ninguna explicación de a dónde se fue. */ ?>
  <div class="aviso aviso--verde" style="margin-bottom:14px">
    <span><?= ico('check',17) ?></span>
    <span><strong>Ya está confirmada.</strong> Mientras reclamabas, facturación confirmó el pago:
      ya está en la lista de ventas listas para mandar.</span>
  </div>
<?php endif; ?>


<?php
$cuantos = (int)($cuantos ?? 0);
$faltan  = max(0, $cuantos - count($pedidos));
?>

<?php if (!$pedidos): ?>
  <div class="tarjeta">
    <?php parte('inicio/vacio', [
      'ico' => 'OK', 'titulo' => 'No tienes nada esperando salir',
      'texto' => 'Todas tus ventas con el pago confirmado ya se mandaron a '
               . 'despacho. Cuando facturación confirme un pago nuevo, la venta aparece aquí sola.',
      'boton' => 'VER MIS PEDIDOS', 'ruta' => '/pedidos',
    ]); ?>
  </div>
<?php else: ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px">
    <span><?= ico('caja',17) ?></span>
    <span><strong><?= plural($cuantos, 'venta lista para mandar', 'ventas listas para mandar') ?>
      a despacho.</strong>
      El pago ya está confirmado: pulsa MANDAR y sale a despacho.
      Las que tienen fecha comprometida van primero.</span>
  </div>

  <?php if ($faltan > 0): ?>
    <div class="aviso aviso--gris" style="margin-bottom:14px">
      <span><?= ico('alerta',17) ?></span>
      <span>Se muestran <?= (int)count($pedidos) ?> de <?= $cuantos ?>. Manda las de arriba
        y las demás suben solas.</span>
    </div>
  <?php endif; ?>

  <div class="tabla__caja">
    <table class="tabla">
      <thead><tr><th>Venta</th><th>Cliente</th><th>Entregar</th>
        <th class="der">Total</th><th>Comprobante</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($pedidos as $p): ?>
        <?php
          $cuando = pedido_entrega_texto($p);
          $vence  = !empty($p['entrega_fecha']) && $p['entrega_fecha'] < date('Y-m-d');
          $hoy    = !empty($p['entrega_fecha']) && $p['entrega_fecha'] === date('Y-m-d');
        ?>
        <tr>
          <td class="principal">
            <a href="<?= e(url('/pedidos/ficha?id=' . (int)$p['id'])) ?>" style="color:inherit">
              <div class="fila__t"><?= e($p['codigo']) ?></div>
              <div class="fila__s"><?= e(fecha_corta((string)$p['fecha'])) ?></div></a>
          </td>
          <td data-k="Cliente"><?= e(trim((string)$p['cliente_nombre'] . ' ' . (string)$p['cliente_apellidos'])) ?></td>
          <td data-k="Entregar">
            <?php if ($cuando === ''): ?>
              <span class="mini">Sin fecha</span>
            <?php else: ?>
              <span class="<?= $vence ? 'chip chip--rojo' : ($hoy ? 'chip chip--ambar' : 'mini') ?>">
                <?= e($cuando) ?></span>
            <?php endif; ?>
          </td>
          <td class="der num" data-k="Total"><?= e(soles((int)$p['total_centimos'])) ?></td>
          <td data-k="Comprobante">
            <?php $ct = (string)($p['comprobante_tipo'] ?? ''); ?>
            <?php if ($ct === '' ): ?><span class="mini">Todavía no</span>
            <?php elseif ($ct === 'ninguno'): ?><span class="mini">No lleva</span>
            <?php else: ?><span class="mini"><?= e(ucfirst($ct)) ?></span><?php endif; ?>
          </td>
          <td class="der">
            <?php parte('pedidos/boton_despacho', ['p' => $p, 'clase' => 'btn btn--amarillo btn--chico', 'html' => 'MANDAR', 'volver' => 'pordespachar']); ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php if ($frenados): ?>
  <?php /* Se enseñan a propósito, con el motivo. Si se ocultaran, el asesor
           contaría sus ventas del día, vería menos de las que hizo y concluiría
           que el HUB perdió una — que es peor que ver una venta esperando. */ ?>
  <div class="tarjeta" style="margin-top:16px">
    <div class="tarjeta__cab">
            <h2>Todavía no pueden salir
        <span class="chip chip--gris"><?= (int)($frenados_total ?? count($frenados)) ?></span></h2>
      <span class="mini">Mira el motivo de cada una</span>
    </div>
    <div class="tabla__caja">
      <table class="tabla">
        <thead><tr><th>Venta</th><th>Cliente</th><th>Por qué no sale</th>
          <th class="der">Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($frenados as $f): ?>
          <tr>
            <td class="principal">
              <a href="<?= e(url('/pedidos/ficha?id=' . (int)$f['id'])) ?>" style="color:inherit">
                <div class="fila__t"><?= e($f['codigo']) ?></div>
                <div class="fila__s"><?= e(fecha_corta((string)$f['fecha'])) ?></div></a>
            </td>
            <td data-k="Cliente"><?= e(trim((string)$f['cliente_nombre'] . ' ' . (string)$f['cliente_apellidos'])) ?></td>
            <?php /* EL MOTIVO, EN LA FILA. Lo dice pedido_despacho_motivo(), la
                     única definición de por qué una venta no sale — y por eso
                     la venta del voucher DENEGADO ya no se queda bajo el
                     cartel «esperando a facturación», que era una respuesta
                     que ya había llegado. */
                  $clave  = (string)($f['reclamo']['clave'] ?? '');
                  $motivo = (string)($f['reclamo']['motivo'] ?? ''); ?>
            <td data-k="Por qué no sale">
              <?php if ($clave === 'denegado'): ?>
                <span class="chip chip--rojo">Pago denegado</span>
                <div class="fila__s">Registra el pago bueno: reclamar no sirve aquí.</div>
              <?php elseif ($clave === 'espera'): ?>
                <span class="chip chip--ambar">Revisando el banco</span>
                <div class="fila__s">Facturación ya lo miró y espera al banco.</div>
                            <?php elseif ($clave === 'sin_revisar'): ?>
                <span class="chip chip--gris">Sin confirmar</span>
              <?php elseif ($clave === 'descuento'): ?>
                <span class="chip chip--ambar">Descuento por aprobar</span>
                <div class="fila__s">Espera que Administración lo apruebe.</div>
              <?php elseif ($clave === 'saldo_provincia'): ?>
                <span class="chip chip--ambar">Falta cobrar el total</span>
                <div class="fila__s">A provincia sale con todo pagado.</div>
                            <?php elseif ($clave === 'preventa'): ?>
                <span class="chip chip--gris">Pre venta</span>
                <div class="fila__s"><?= ico('reloj',12) ?> <?= e($motivo) ?></div>
              <?php elseif ($clave === 'sin_pago'): ?>
                <span class="chip chip--rojo">Sin pago</span>
                <div class="fila__s">Registra el pago.</div>
              <?php else: ?>
                <span class="mini"><?= e($motivo !== '' ? $motivo : 'Sin confirmar') ?></span>
              <?php endif; ?>
            </td>
            <td class="der num" data-k="Total"><?= e(soles((int)$f['total_centimos'])) ?></td>
            <?php /* EL RECLAMO. Nace apagado y se enciende solo pasados los
                     minutos configurados: facturación necesita un rato para
                     mirarlo, y un aviso que suena cuando no toca se aprende a
                     ignorar — y con él los que sí tocan. La cuenta atrás se
                     pinta aquí, pero quien decide es el servidor:
                     pedido_reclamo_estado() lo vuelve a comprobar. */ ?>
            <td class="der">
              <?php if ((int)($f['reclamo_veces'] ?? 0) > 0): ?>
                <span class="chip chip--rojo" title="<?= e(!empty($f['reclamo_en'])
                      ? 'Último: ' . hace((string)$f['reclamo_en']) : '') ?>">
                  Reclamado<?= (int)$f['reclamo_veces'] > 1
                    ? ' ×' . (int)$f['reclamo_veces'] : '' ?></span>
              <?php endif; ?>
              <?php /* Al de solo lectura —el líder que ve las ventas de su
                       equipo— no se le pinta: pulsarlo le daba un 403 a
                       pantalla completa y perdía la lista. Y a lo que NO se
                       puede reclamar nunca (denegado, en revisión) tampoco:
                       antes salía apagado con «faltan 0», y el contador del
                       navegador lo encendía en el primer segundo, justo en los
                       casos que el servidor iba a rechazar. */ ?>
              <?php if (!empty($f['puede_tocar']) && $clave === 'sin_revisar'): ?>
              <form method="post" action="<?= e(url('/pedidos/reclamar')) ?>" style="display:inline">
                <?= campo_csrf() ?>
                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                <button class="btn btn--linea btn--chico reclamar" type="submit"
                        data-faltan="<?= max(0, (int)($f['reclamo']['faltan'] ?? 0)) ?>"
                        <?= empty($f['reclamo']['puede']) ? 'disabled' : '' ?>>
                  <?= empty($f['reclamo']['puede'])
                      ? 'Reenviar en <span class="reclamar__t">–</span>'
                      : ((int)($f['reclamo_veces'] ?? 0) > 0
                         ? 'Reenviar solicitud' : 'Reclamar') ?></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
        <p class="mini" style="margin:10px 0 0">Las que esperan a facturación suben solas a la lista de
      arriba cuando las confirman. Si una tarda más de <?= (int)$minutos_reclamo ?> minutos, pulsa
      <strong>Reclamar</strong>: facturación la verá primero.</p>

    <?php /* LA CUENTA ATRÁS. Sin JavaScript el botón se queda apagado y se
             enciende al recargar: se pierde el contador, no la función. */ ?>
    <script>
    (function () {
      var botones = document.querySelectorAll('.reclamar[disabled]');
      if (!botones.length) return;
      /* EL RELOJ ES ABSOLUTO, no un contador que se resta de uno en uno. En el
         celular, con la pestaña en segundo plano o la pantalla apagada, el
         navegador estrangula el setInterval: el contador se quedaba clavado en
         «2:30» cuando los cinco minutos ya habían pasado. Se mide contra la
         hora de arranque de la página, que no se estrangula. */
      var t0 = Date.now();
      botones.forEach(function (b) {
        b.setAttribute('data-listo',
          b.closest('tr').querySelector('.chip--rojo') ? 'Reenviar solicitud' : 'Reclamar');
        b.setAttribute('data-inicio', b.getAttribute('data-faltan') || '0');
      });
      function pinta() {
        var pasados = Math.floor((Date.now() - t0) / 1000);
        var quedan = 0;
        botones.forEach(function (b) {
          if (!b.disabled) return;
          var f = (parseInt(b.getAttribute('data-inicio') || '0', 10) || 0) - pasados;
          if (f <= 0) {
            b.disabled = false;
            b.textContent = b.getAttribute('data-listo') || 'Reclamar';
            return;
          }
          quedan++;
          var m = Math.floor(f / 60), s = f % 60;
          var t = b.querySelector('.reclamar__t');
          if (t) t.textContent = m + ':' + (s < 10 ? '0' : '') + s;
        });
        if (!quedan) clearInterval(reloj);
      }
      var reloj = setInterval(pinta, 1000);
      pinta();
    })();
    </script>
  </div>
<?php endif; ?>

<?php /* ENVIADOS (3j, usuario 2026-09-28): lo que ya mandó, con filtro de
         fecha, Lima o provincia y un buscador por cliente, producto y DNI. En
         cada envío, todas sus fotos: lo alistado, los vouchers, el rótulo y la
         entrega. */
$env_f = $env_f ?? ['desde' => '', 'hasta' => '', 'z' => '', 'q' => ''];
$enviados = $enviados ?? []; ?>
<div class="tarjeta" style="margin-top:16px" id="enviados">
  <div class="tarjeta__cab"><h2>Enviados <span class="chip chip--gris"><?= count($enviados) ?><?= count($enviados) >= 100 ? '+' : '' ?></span></h2>
    <span class="mini">Lo que ya mandaste, con sus fotos</span></div>
  <form method="get" action="<?= e(url('/pedidos/por-despachar')) ?>#enviados" class="barra-filtros enviados__filtros">
    <label>Desde <input type="date" name="ed" value="<?= e($env_f['desde']) ?>"></label>
    <label>Hasta <input type="date" name="eh" value="<?= e($env_f['hasta']) ?>"></label>
    <?php if (!empty($hay_zona)): ?>
    <label>Zona
      <select name="ez">
        <option value="">Lima y provincia</option>
        <option value="lima" <?= $env_f['z'] === 'lima' ? 'selected' : '' ?>>Lima</option>
        <option value="provincia" <?= $env_f['z'] === 'provincia' ? 'selected' : '' ?>>Provincia</option>
      </select>
    </label>
    <?php endif; ?>
    <label class="enviados__q">Buscar
      <input type="search" name="eq" value="<?= e($env_f['q']) ?>" placeholder="Cliente, DNI o producto"></label>
    <div class="barra-filtros__b"><button class="btn btn--negro" type="submit">VER</button></div>
  </form>
  <?php if (!$enviados): ?>
    <p class="mini" style="margin:6px 0 0">No hay envíos con esos filtros.</p>
  <?php endif; ?>
  <?php foreach ($enviados as $en): $eid = (int)$en['id']; $vch = $env_vouch[$eid] ?? []; ?>
    <div class="enviado" id="enviado-<?= $eid ?>">
      <div class="enviado__cab">
        <a class="fila__t enviado__cod" href="<?= e(url('/pedidos/ficha?id=' . $eid)) ?>"><?= e((string)$en['codigo']) ?></a>
        <span class="chip chip--<?= e((string)($en['estado_color'] ?: 'gris')) ?>"><?= e((string)$en['estado_nombre']) ?></span>
        <span class="mini"><?= e(fecha_corta(substr((string)$en['enviado_en'], 0, 10))) ?></span>
      </div>
      <div class="fila__s"><?= e(trim((string)$en['cliente_nombre'] . ' ' . (string)$en['cliente_apellidos'])) ?>
        <?= (string)$en['documento'] !== '' ? ' · ' . e((string)$en['documento']) : '' ?>
        · <?= e(pedido_como_recibe($en)) ?></div>
      <div class="enviado__lleva"><?php parte('pedidos/lineas_corto', ['lns' => $env_lineas[$eid] ?? []]); ?></div>
      <div class="enviado__fotos">
        <?php if (!empty($en['alistado_foto'])): ?>
          <a class="chip chip--ver" target="_blank" rel="noopener" href="<?= e(url('/pedidos/alistado-foto?id=' . $eid)) ?>"><?= ico('camara',12) ?> Alistado</a>
        <?php else: ?><span class="chip chip--gris"><?= empty($en['alistado_en']) ? 'Por alistar' : 'Alistado sin foto' ?></span><?php endif; ?>
        <?php foreach ($vch as $k => $pg_id): ?>
          <a class="chip chip--ver" target="_blank" rel="noopener" href="<?= e(url('/pagos/voucher?id=' . $pg_id)) ?>">Voucher<?= count($vch) > 1 ? ' ' . ($k + 1) : '' ?></a>
        <?php endforeach; ?>
        <?php if ($en['anulado_en'] === null): ?>
          <a class="chip chip--linea" href="<?= e(url('/pedidos/rotulo?id=' . $eid . '&vista=1')) ?>">Rótulo</a>
        <?php endif; ?>
        <?php if (!empty($en['entregado_foto'])): ?>
          <a class="chip chip--ver" target="_blank" rel="noopener" href="<?= e(url('/pedidos/entrega-foto?id=' . $eid)) ?>"><?= ico('camara',12) ?> Entrega</a>
        <?php elseif (empty($en['entregado_en']) && $en['anulado_en'] === null): ?>
          <span class="chip chip--gris">Sin entregar</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
