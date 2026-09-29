<?php /* EL AVISO DE «X VENTAS SIN COMPROBANTE» SE QUITÓ el 2026-09-11, a
         pedido del usuario: van a existir ventas sin boleta A PROPÓSITO, así
         que un contador de «pendientes» que nunca baja a cero solo ocupa sitio
         encima de la cola de pagos y se aprende a ignorar.
         El REPORTE se queda —Reportes › Pagos ingresados › Ventas sin
         comprobante—: quien quiera repasarlas entra y las ve.

         LO QUE SÍ SALE AQUÍ, desde el 2026-09-12, es lo que un asesor PIDIÓ a
         mano. Es la otra cola y la diferencia es toda: esta la llena una
         persona y se vacía sola en cuanto se emite. Va ARRIBA de los pagos
         porque el rato en que facturación tiene tiempo de emitir es justo
         cuando la bandeja de pagos está vacía. */ ?>
<?php if (!empty($solicitados)): ?>
  <div class="tarjeta" style="margin-bottom:14px;border-color:var(--marca);border-width:2px">
    <div class="tarjeta__cab">
      <?php /* El TOTAL, no lo que cupo en la página: «100» con 122 esperando es
               una cifra que miente, y facturación no podía distinguir 101 de
               1010. Sale de la misma condición que la cola. */ ?>
      <h2><?= ico('tarjeta',18) ?> <?= e(plural((int)$n_solicitados,
            'comprobante solicitado', 'comprobantes solicitados')) ?></h2>
      <span class="mini"><?= !empty($mas_solicitados)
        ? 'Se ven los ' . count($solicitados) . ' más antiguos'
        : 'Lo pidió el asesor · se van de aquí en cuanto los emites' ?></span>
    </div>
    <div class="tabla__caja">
      <table class="tabla">
        <thead><tr><th>Pedido</th><th>Cliente</th><th>Pide</th>
          <th>Asesor</th><th class="der">Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($solicitados as $sl): ?>
          <tr>
            <td class="principal">
              <a href="<?= e(url('/pedidos/ficha?id=' . (int)$sl['id'])) ?>" style="color:inherit">
                <div class="fila__t"><?= e((string)$sl['codigo']) ?></div>
                <?php if (!empty($sl['pide_en'])): ?>
                  <div class="fila__s"><?= e(hace((string)$sl['pide_en'])) ?></div>
                <?php endif; ?>
              </a>
            </td>
            <td data-k="Cliente">
              <div class="recorta"><?= e(trim((string)$sl['cliente_nombre'] . ' '
                                            . (string)$sl['cliente_apellidos'])) ?></div>
              <div class="fila__s"><?= e((string)$sl['tipo_doc']) ?>
                <?= e((string)$sl['documento']) ?></div>
            </td>
            <td data-k="Pide">
              <?php /* El ámbar es el de «hay algo que hacer», el mismo de los
                       pagos en espera: son la misma clase de fila. */ ?>
              <span class="chip chip--ambar"><?= e(comprobante_pide_texto((string)$sl['comprobante_pide'])) ?></span>
            </td>
            <td data-k="Asesor"><span class="mini"><?= e(primer_nombre((string)$sl['asesor_nombre'])) ?></span></td>
            <td class="der num"><?= e(soles((int)$sl['total_centimos'])) ?></td>
            <td class="der">
              <a class="btn btn--linea" href="<?= e(url('/pedidos/facturar?id=' . (int)$sl['id'])) ?>">
                EMITIR COMPROBANTE</a>
              <?php /* Y si todavía no se puede, se dice aquí: si no, la fila se
                       lee como «alguien no hace su trabajo» (auditoría 2w). */ ?>
              <?php if ($freno_sl = pedido_emision_bloqueo($sl)): ?>
                <div class="mini" style="margin-top:6px"><?= e($freno_sl) ?></div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="mini" style="margin:10px 0 0">
      Si una venta no lleva comprobante, márcalo en <strong>Emitir comprobante</strong> y sale de la lista.
      <?php if (!empty($mas_solicitados)): ?>
        <br><strong>Se ven las <?= count($solicitados) ?> más antiguas</strong> de
        <?= (int)$n_solicitados ?>: vacía estas y aparecen las siguientes.
      <?php endif; ?>
    </p>
  </div>
<?php endif; ?>

<?php /* LA VENTANA DE «QUÉ ACABA DE PASAR». Va la primera del HTML: si el
         navegador no pinta el CSS, lo que se lee arriba del todo es lo que
         hace falta decidir. */ ?>
<?php /* El resumen de una venta, encima (3f): «VER VENTA». */ ?>
<?php parte('pagos/venta_resumen', ['ver' => $ver ?? null, 'cerrar' => $url_bandeja ?? url('/pagos/por-validar')]); ?>
<?php parte('pagos/resultado', ['resultado' => $resultado,
                                'cerrar'    => url('/pagos/por-validar'),
                                'vuelve'    => 'cola']); ?>

<?php if (!$pagos): ?>
  <div class="tarjeta">
    <?php parte('inicio/vacio', [
      'ico' => 'OK', 'titulo' => 'No hay nada esperando',
      /* El texto decía que Yape, Plin, efectivo y tarjeta no pasaban por aquí.
         Dejó de ser verdad el día que se decidió que TODO pago se confirma
         —hay clientes que mandan vouchers falsos— y una pantalla que explica
         una regla que ya no existe es peor que una pantalla sin explicación. */
      'texto' => 'Todos los pagos registrados están confirmados. Por aquí pasan todos, '
               . 'sin excepción: ningún método suma a una meta hasta que alguien '
               . 'lo mira en el banco.',
      'boton' => 'VOLVER A PEDIDOS', 'ruta' => '/pedidos',
    ]); ?>
  </div>
<?php else: ?>
  <?php
    /* La cifra de la cabecera es la de TODO lo que espera, no la de lo que cupo
       en la página. Cuando no cabe todo se dice, con su número: una lista que
       se corta en silencio es peor que una lista larga. */
    $cuantos = (int)($cuantos ?? count($pagos));
    $tope    = (int)($tope ?? 0);
    $faltan  = max(0, $cuantos - count($pagos));
  ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px">
    <span><?= ico('reloj',17) ?></span>
    <span><strong><?= plural((int)$n_sin_revisar,'pago sin revisar','pagos sin revisar') ?><?php
        if ((int)$n_en_espera > 0): ?> y <?= plural((int)$n_en_espera,'en espera','en espera') ?><?php
        endif; ?> · <?= e(soles($total)) ?> en total.</strong>
      Confirma en el banco antes de validar. Al validar, el pago suma a la meta
      <strong>del mes de su fecha</strong> —no del día de hoy— y el cliente gana su cashback.</span>
  </div>

  <?php if ($faltan > 0): ?>
    <?php /* La nota decía «están todos en el reporte de pagos». No era verdad:
             el reporte también corta, y corta por lo mismo —los más nuevos
             primero—, así que los que sobran aquí tampoco salen allí. Lo único
             que los trae de verdad es el Excel. Una salida que no existe es
             peor que decir que no hay salida. */ ?>
    <div class="aviso aviso--gris" style="margin-bottom:14px">
      <span><?= ico('alerta',17) ?></span>
      <span>Se muestran los <?= (int)count($pagos) ?> más nuevos y quedan
        <?= (int)$faltan ?> más abajo, que son los más viejos. Para verlos todos,
        <a href="<?= e(url('/reportes/pagos?excel=1&e=pendientes&desde=2000-01-01&hasta=' . date('Y-m-d'))) ?>"
           style="text-decoration:underline">bájalos a Excel</a>.</span>
    </div>
  <?php endif; ?>

  <?php
    /* Una tabla por grupo. La cabecera se repite, y está bien que se repita:
       son dos listas con significados distintos y quien mira tiene que saber
       en cuál está sin volver a subir. */
    $tabla = function (array $filas, string $titulo, string $sub, string $chip = '') use ($lineas_de, $url_ver) {
        if (!$filas) return;
        echo '<div class="tarjeta__cab" style="margin:18px 0 8px">';
        echo   '<h2 style="font-size:14px">' . e($titulo);
        if ($chip) echo ' <span class="chip ' . e($chip) . '">' . count($filas) . '</span>';
        echo   '</h2>';
        echo   '<span class="mini">' . e($sub) . '</span>';
        echo '</div>';
        echo '<div class="tabla__caja"><table class="tabla"><thead><tr>'
           . '<th>Fecha</th><th>Pedido</th><th>Cliente</th><th>Método</th><th>Operación</th>'
           . '<th class="der">Monto</th><th>Asesor</th><th class="der"></th></tr></thead><tbody>';
        foreach ($filas as $pg) parte('pagos/fila', ['pg' => $pg, 'lns' => $lineas_de[(int)$pg['pedido_id']] ?? [],
                                                     'url_ver' => $url_ver((int)$pg['pedido_id'])]);
        echo '</tbody></table></div>';
    };

    /* Los colores salen de pago_chip(), no escritos aquí: el mismo estado tenía
       un color en esta bandeja y otro en la ficha. Y «Sin revisar» iba en el
       amarillo de marca, que tiene 1.36:1 de contraste y está reservado para
       botones y selección — ver paleta-datos. */
    $tabla($sin_revisar, 'Sin revisar', 'Lo que espera una mirada. Los últimos en entrar, arriba.',
           pago_chip(['verificado' => 0])[0]);
    $tabla($en_espera,   'En espera',   'Ya los miraste: falta que el dinero aparezca en el banco.',
           pago_chip(['verificado' => 0, 'en_espera' => 1])[0]);
  ?>

  <p class="mini" style="margin-top:12px">
    <?php /* Se recortó el 2026-09-09. Antes había aquí once líneas explicando
             POR QUÉ existe la pantalla, escritas para quien la construyó y no
             para quien la usa. Quien entra aquí quiere saber qué hacer con la
             fila que tiene delante; el porqué ya lo sabe, es su trabajo. */ ?>
    <strong>Validar</strong> cuando el pago aparezca en el banco, con el N.º de operación
    del voucher o del extracto.
    <br><strong>¿No cuadra?</strong> <strong>En espera</strong> si aún no aparece;
    <strong>Denegar</strong> si no va a aparecer. El asesor lee el motivo. Denegar no anula el pedido.
  </p>

<?php endif; ?>

<?php /* ── LO QUE YA SE VALIDÓ (usuario, 2026-09-23) ────────────────────
         Va DEBAJO de lo que falta por mirar, nunca encima: el trabajo del día
         es lo de arriba. El pago recién confirmado sale en verde un momento —
         sin ninguna palabra: «aquí está lo que validaste». */ ?>
<div class="tarjeta" style="margin-top:14px">
  <div class="tarjeta__cab">
    <h2>Validados <span class="chip chip--verde"><?= count($validados) ?></span></h2>
    <span class="mini"><?= e(soles((int)$v_total)) ?> en lo que estás mirando</span>
  </div>

  <form method="get" class="barra-filtros">
    <label>Desde <input type="date" name="vd" value="<?= e($v_desde) ?>"></label>
    <label>Hasta <input type="date" name="vh" value="<?= e($v_hasta) ?>"></label>
    <label>Asesor
      <select name="va">
        <option value="">Todos</option>
        <?php foreach ($asesores_v as $av): ?>
          <option value="<?= (int)$av['id'] ?>" <?= (int)$v_asesor === (int)$av['id'] ? 'selected' : '' ?>>
            <?= e(trim((string)$av['nombre'] . ' ' . (string)$av['apellidos'])) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Comprobante
      <select name="vc">
        <option value="">Todos</option>
        <option value="boleta"  <?= $v_comp === 'boleta'  ? 'selected' : '' ?>>Boleta</option>
        <option value="factura" <?= $v_comp === 'factura' ? 'selected' : '' ?>>Factura</option>
        <option value="ninguno" <?= $v_comp === 'ninguno' ? 'selected' : '' ?>>No lleva</option>
        <option value="sin"     <?= $v_comp === 'sin'     ? 'selected' : '' ?>>Todavía sin emitir</option>
      </select>
    </label>
    <div class="barra-filtros__b">
      <button class="btn btn--negro" type="submit">VER</button>
    </div>
  </form>

  <?php if (!$validados): ?>
    <p class="mini" style="margin:0">Ningún pago validado en esas fechas.</p>
  <?php else: ?>
    <div class="tabla__caja">
      <table class="tabla">
        <thead><tr><th>Validado</th><th>Pedido</th><th>Cliente</th><th>Método</th>
          <th class="der">Monto</th><th>Comprobante</th><th>Asesor</th></tr></thead>
        <tbody>
        <?php foreach ($validados as $vg): ?>
          <tr class="<?= (int)$vg['id'] === (int)$recien ? 'fila--recien' : '' ?>">
            <?php /* La fecha que se enseña es la de la VALIDACIÓN, que es por la
                     que filtra el buscador de arriba. La del pago —la que dice
                     a qué mes suma— va debajo, en pequeño: enseñar una y
                     filtrar por la otra era leer «15/01/2024» dentro de un
                     filtro puesto en hoy (auditoría del 3a.1). */ ?>
            <td class="principal">
              <a href="<?= e(url('/pedidos/ficha?id=' . (int)$vg['pedido_id'])) ?>" style="color:inherit">
                <div class="fila__t"><?= e(fecha_corta((string)($vg['verificado_en'] ?: $vg['creado_en']))) ?></div>
                <div class="fila__s">pago del <?= e(fecha_corta((string)$vg['fecha'])) ?>
                  <?= trim((string)($vg['operacion'] ?? '')) !== '' ? ' · ' . e((string)$vg['operacion']) : '' ?></div>
              </a>
            </td>
            <td data-k="Pedido">
              <?php /* Qué se vendió, y la venta encima con «VER VENTA» (3f). */ ?>
              <span class="mini"><?= e((string)$vg['codigo']) ?></span>
              <?php parte('pedidos/lineas_corto', ['lns' => $lineas_de[(int)$vg['pedido_id']] ?? []]); ?>
              <a class="chip chip--linea chip--ver" href="<?= e($url_ver((int)$vg['pedido_id'])) ?>" data-ver-venta>VER VENTA</a>
            </td>
            <td data-k="Cliente">
              <div class="recorta"><?= e(trim((string)$vg['cliente_nombre'] . ' ' . (string)$vg['cliente_apellidos'])) ?></div>
            </td>
            <td data-k="Método"><span class="mini"><?= e((string)($vg['metodo'] ?? '')) ?></span></td>
            <td class="der num" data-k="Monto"><?= e(soles((int)$vg['monto_centimos'])) ?></td>
            <td data-k="Comprobante">
              <?php $ct = trim((string)($vg['comprobante_tipo'] ?? '')); ?>
              <?php if ($ct === '' ): ?>
                <span class="chip chip--ambar">Sin emitir</span>
              <?php elseif ($ct === 'ninguno'): ?>
                <span class="chip chip--gris">No lleva</span>
              <?php else: ?>
                <span class="chip chip--verde"><?= e(ucfirst($ct)) ?></span>
                <span class="mini"><?= e(trim((string)$vg['comprobante_serie'] . '-' . (string)$vg['comprobante_numero'], '-')) ?></span>
              <?php endif; ?>
            </td>
            <td data-k="Asesor"><span class="mini"><?= e(primer_nombre((string)($vg['asesor_nombre'] ?? ''))) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (count($validados) >= 100): ?>
      <p class="mini" style="margin:10px 0 0">Se muestran los 100 últimos en validarse.
        Filtra por asesor o acorta las fechas para ver los que buscas.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>

