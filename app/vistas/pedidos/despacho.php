<?php
/** El despacho de un pedido (3h.1): ya sin el mensaje para el WhatsApp. */
$ya = (int)($p['despacho_veces'] ?? 0) > 0;
?>
<div class="rejilla rejilla--panel">
  <div class="tarjeta">
    <div class="tarjeta__cab">
      <h2>Despacho</h2>
      <?php if ($ya): ?>
        <span class="chip chip--verde"><?= ico('check',13) ?> Ya se mandó</span>
      <?php endif; ?>
    </div>

    <?php if ($ya): ?>
      <div class="aviso aviso--verde" style="margin-bottom:12px">
        <span><?= ico('check',17) ?></span>
        <span>Este pedido salió a despacho
          <?= $quien ? 'con <strong>' . e(primer_nombre($quien)) . '</strong>' : '' ?>
          <?= e(hace((string)$p['despacho_en'])) ?><?php if ((int)$p['despacho_veces'] > 1): ?>,
            y se mandó <?= (int)$p['despacho_veces'] ?> veces<?php endif; ?>.
          <?php if (!empty($p['alistado_en'])): ?>Almacén ya lo alistó.<?php else: ?>Almacén lo tiene en «Por alistar».<?php endif; ?>
          Si algo cambió —una dirección, una cantidad— vuelve a mandarlo.</span>
      </div>
    <?php endif; ?>

    <?php
      /* Ya no hay nada que advertir sobre pagos sin confirmar: desde el
         2026-09-09 un pedido con dinero registrado sin confirmar NO llega a
         esta pantalla — la ruta corta antes de armar el texto. Aquí solo queda
         el caso legítimo: el saldo que se cobra al entregar.
         Las dos advertencias que había («facturación lo está revisando» y «el
         pago está sin revisar, manda esto después») decían justo lo contrario
         de la regla nueva: invitaban a mandarlo igual. */
      if ($saldo > 0): ?>
      <div class="aviso aviso--amarillo" style="margin-bottom:12px">
        <span><?= ico('alerta',17) ?></span>
        <span><strong>Quedan <?= e(soles($saldo)) ?> por cobrar al entregar.</strong>
          Almacén lo ve en las indicaciones. Lo que ya pagó el cliente
          está confirmado: por eso este pedido puede salir.</span>
      </div>
    <?php endif; ?>

    <?php /* Corregir la ventana de entrega SIN salir de aquí: es el momento en
             que uno se acuerda de que el cliente reprogramó, y mandar al asesor
             a editar el pedido entero para cambiar una hora es como se pierde
             la hora. Va plegado para no tapar el mensaje, que es lo que se
             viene a hacer. */ ?>
    <?php /* Solo donde entregamos nosotros. A provincia el día y la hora los
             pone la agencia: ofrecer el formulario ahí era invitar a prometer
             una hora que Waka no controla. */
      $entregamos_nosotros = (string)$p['entrega'] === 'envio'
          && ubigeo_es_lima($p['ubigeo_id'] ? (int)$p['ubigeo_id'] : null); ?>
    <?php if (!$entregamos_nosotros): ?>
      <div class="aviso aviso--gris" style="margin-bottom:12px">
        <span><?= ico('reloj',17) ?></span>
        <span><strong><?= e(pedido_como_recibe($p)) ?>.</strong>
          <?= e('El día y la hora los pone '
                . ((string)$p['entrega'] === 'envio' ? 'la agencia' : 'el cliente')
                . ', no nosotros.') ?></span>
      </div>
    <?php else: ?>
    <details class="desplegable" style="margin-bottom:12px" <?= pedido_entrega_texto($p) === '' ? '' : 'open' ?>>
      <summary><?= pedido_entrega_texto($p) === ''
          ? '📅 ¿Quedaron en un día u hora? Anótalo'
          : '📅 Entregar: ' . e(pedido_entrega_texto($p)) . ' · cambiar' ?></summary>
      <form method="post" action="<?= e(url('/pedidos/despacho')) ?>" class="form" style="margin-top:10px">
        <?= campo_csrf() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <input type="hidden" name="accion" value="entrega">
        <div class="form__fila">
                              <?php /* El mínimo es mañana, salvo que este pedido YA tenga un día
                   acordado para hoy o para antes: entonces el mínimo es ese, y
                   despacho puede corregir la hora sin perder la fecha. */ ?>
          <?php $dia_ya = (string)($p['entrega_fecha'] ?? '');
                $min_dia = date('Y-m-d', strtotime('+1 day'));
                if ($dia_ya !== '' && $dia_ya < $min_dia) $min_dia = $dia_ya; ?>
          <label>Día
            <input type="date" name="entrega_fecha"
                   min="<?= e($min_dia) ?>"
                   max="<?= date('Y-m-d', strtotime('+12 months')) ?>"
                   value="<?= e($dia_ya) ?>"></label>
          <label>Desde
            <input type="time" name="entrega_hora_desde"
                   value="<?= e((string)($p['entrega_hora_desde'] ?? '')) ?>"></label>
          <label>Hasta
            <input type="time" name="entrega_hora_hasta"
                   value="<?= e((string)($p['entrega_hora_hasta'] ?? '')) ?>"></label>
        </div>
        <label class="check" style="margin-top:8px">
          <input type="checkbox" name="entrega_recepcion" value="1"
                 <?= !empty($p['entrega_recepcion']) ? 'checked' : '' ?>>
          <span>Se puede dejar en recepción</span>
        </label>
        <button class="btn btn--linea" type="submit" style="margin-top:10px">
          GUARDAR EL DÍA Y LA HORA</button>
      </form>
    </details>
    <?php endif; ?>

    <div class="acciones" style="margin-top:12px">
      <?php if ($puedo_marcar): ?>
        <form method="post" action="<?= e(url('/pedidos/despacho')) ?>" style="display:inline">
          <?= campo_csrf() ?>
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <?php if (!$ya): ?><input type="hidden" name="primera" value="1"><?php endif; ?>
          <button class="btn <?= $ya ? 'btn--negro' : 'btn--amarillo' ?>" type="submit" data-espera="Mandando…">
            <?= $ya ? 'VOLVER A MANDAR A DESPACHO' : 'MANDAR A DESPACHO' ?></button>
        </form>
      <?php endif; ?>
      <?php /* EL RÓTULO PARA LA CAJA (usuario, 2026-09-20). Media A5 con el
               logo: se descarga, se imprime y se pega, y el mismo PDF se puede
               mandar al grupo. */ ?>
      <a class="btn btn--linea" href="<?= e(url('/pedidos/rotulo?id=' . (int)$p['id'])) ?>">
        DESCARGAR EL RÓTULO (PDF)</a>
      <a class="btn btn--linea" href="<?= e(url('/pedidos/ficha?id=' . (int)$p['id'])) ?>">Volver al pedido</a>
    </div>

    <?php /* Y los textos PARA EL CLIENTE. */ ?>
    <?php if (!empty($plantillas)): ?>
      <div class="acciones" style="margin-top:12px;border-top:1px solid var(--linea);padding-top:12px">
        <span class="mini" style="align-self:center">Textos para el cliente:</span>
        <?php foreach ($plantillas as $k_pl => $nombre_pl): ?>
          <a class="chip chip--linea"
             href="<?= e(url('/pedidos/mensaje?id=' . (int)$p['id'] . '&p=' . urlencode((string)$k_pl))) ?>">
            <?= ico('copiar', 14) ?> <?= e($nombre_pl) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</div>
