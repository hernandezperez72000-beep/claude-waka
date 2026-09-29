<?php
/**
 * Una fila de la bandeja de pagos. Vive aparte porque la pintan los dos
 * grupos —sin revisar y en espera— y duplicarla es garantizar que dentro de
 * tres meses una de las dos se quede sin un cambio.
 *
 * LAS TRES SALIDAS ESTÁN AQUÍ (usuario, 2026-09-09). Antes solo se veía
 * «Validar» y las otras dos vivían en la pantalla de facturar, para que el
 * motivo se escribiera con sitio de sobra. El resultado fue que desde la
 * bandeja parecía que confirmar era lo único que se puede hacer con un voucher
 * que no cuadra — y eso es peor que un motivo corto.
 *
 * El arreglo no es meter dos casillas más en la fila: es que al pulsar «En
 * espera» o «Denegar» se ABRA debajo una franja con sitio para escribir. Se ve
 * que existen las tres, y el motivo sigue teniendo dónde caber.
 */
?>
<tr>
  <td class="mini"><?= e(fecha_corta((string)$pg['fecha'])) ?></td>
  <td class="principal">
    <a href="<?= e(url('/pedidos/facturar?id=' . (int)$pg['pedido_id'])) ?>" style="color:inherit">
      <div class="fila__t"><?= e($pg['codigo']) ?>
        <?php /* EL RECLAMO DEL ASESOR. Esta fila ya viene arriba del todo —la
                 bandeja las ordena así—, pero subir sin decir por qué se lee
                 como un orden caprichoso. El número importa: una venta
                 reclamada tres veces no es lo mismo que una reclamada una. */ ?>
        <?php if ((int)($pg['reclamo_veces'] ?? 0) > 0): ?>
          <span class="chip chip--rojo" title="<?= e(!empty($pg['reclamo_en'])
                ? 'El asesor lo reclamó ' . hace((string)$pg['reclamo_en']) : '') ?>">
            Reclamado<?= (int)$pg['reclamo_veces'] > 1
              ? ' ×' . (int)$pg['reclamo_veces'] : '' ?></span>
        <?php endif; ?>
      </div>
      </a>
    <?php /* QUÉ SE ESTÁ VENDIENDO (3f): lo que lleva la venta de este pago. */ ?>
    <?php parte('pedidos/lineas_corto', ['lns' => $lns ?? []]); ?>
  </td>
  <td data-k="Cliente">
    <?= e(trim((string)$pg['cliente_nombre'] . ' ' . (string)$pg['cliente_apellidos'])) ?>
    <?php if ((int)$pg['en_espera'] === 1 && $pg['espera_nota']): ?>
      <div class="fila__s" style="max-width:260px">
        <?= ico('reloj',12) ?> <?= e((string)$pg['espera_nota']) ?>
        · <?= e(hace((string)$pg['espera_en'])) ?>
      </div>
    <?php endif; ?>
  </td>
    <td data-k="Método"><?= e($pg['metodo'] ?: '—') ?>
    <?php /* Qué pago es: facturación ve dos vouchers del mismo pedido y tiene
             que saber cuál es el saldo y cuál el delivery. */ ?>
    <?php if (isset($pg['numero'])): ?><div class="fila__s"><?= e(pago_rotulo($pg)) ?></div><?php endif; ?></td>
  <td data-k="Operación">
    <?php if ($pg['operacion']): ?>
      <span class="mini"><?= e($pg['operacion']) ?></span>
    <?php else: ?>
      <span class="mini muted">lo pones al validar →</span>
    <?php endif; ?>
  </td>
  <td class="der num" data-k="Monto"><strong><?= e(soles((int)$pg['monto_centimos'])) ?></strong></td>
  <td data-k="Asesor"><span class="mini"><?= e(primer_nombre((string)$pg['asesor_nombre'])) ?></span></td>
  <?php /* LAS TRES SALIDAS DEL PAGO, JUNTAS.
           En el celular esta celda se convierte en una fila de la tarjeta y
           envuelve: sin `.acciones-fila` los botones que bajan de renglón se
           separan a los dos extremos —`.tabla td` reparte el hueco— y dejan de
           leerse como un grupo. Con ella se quedan pegados a la derecha, en el
           orden de siempre. */ ?>
  <td class="der acciones-fila">
    <?php /* El resumen de la venta, encima, sin salir de la bandeja (3f). */ ?>
    <?php if (!empty($url_ver)): ?>
      <a class="chip chip--linea" href="<?= e($url_ver) ?>" data-ver-venta>VER VENTA</a>
    <?php endif; ?>
    <?php if ($pg['voucher']): ?>
      <a class="chip chip--linea" target="_blank" rel="noopener"
         href="<?= e(url('/pagos/voucher?id=' . (int)$pg['id'])) ?>">VER VOUCHER</a>
    <?php else: ?>
      <span class="chip chip--ambar">Sin voucher</span>
    <?php endif; ?>
    <?php /* La foto del DNI de quien pagó (3f): la pide el POS. */ ?>
    <?php if (!empty($pg['foto_dni'])): ?>
      <a class="chip chip--linea" target="_blank" rel="noopener" data-ver-dni
         href="<?= e(url('/pagos/voucher?que=dni&id=' . (int)$pg['id'])) ?>">VER DNI</a>
    <?php endif; ?>
    <?php
      /* El N.º de operación es obligatorio donde existe: si el método pide
         voucher, deja número. En efectivo no se pide — obligarlo acabaría con
         un «000» en cada venta y el campo dejaría de servir en las demás. */
      $pide_op = metodo_pide_comprobante($pg['metodo_item_id'] ?? null)
                 && trim((string)$pg['operacion']) === '';
    ?>
    <form method="post" action="<?= e(url('/pagos/validar')) ?>"
          style="display:inline-flex;gap:6px;align-items:center">
      <?= campo_csrf() ?>
      <input type="hidden" name="id" value="<?= (int)$pg['id'] ?>">
      <input type="text" name="operacion" maxlength="60" value="<?= e((string)$pg['operacion']) ?>"
             placeholder="<?= $pide_op ? 'N.º de operación *' : 'N.º de operación' ?>"
             style="width:160px;max-width:100%" <?= $pide_op ? 'required' : '' ?>
             title="Cópialo del extracto del banco: es lo único que después permite cuadrar este pago con el movimiento.">
      <button class="chip chip--verde" type="submit" style="cursor:pointer">Validar</button>
    </form>
    <?php /* Los dos botones que abren la franja de abajo. Son <button> de un
             formulario que no envían nada: sin JavaScript el enlace de más
             abajo sigue llevando a facturar, así que ninguna de las dos salidas
             se pierde nunca. */ ?>
    <button type="button" class="chip chip--linea" data-abre="mas-<?= (int)$pg['id'] ?>"
            style="cursor:pointer">En espera</button>
    <button type="button" class="chip chip--rojo" data-abre="mas-<?= (int)$pg['id'] ?>"
            data-foco="denegar" style="cursor:pointer">Denegar</button>
  </td>
</tr>

<tr id="mas-<?= (int)$pg['id'] ?>" class="fila-mas" hidden>
  <td colspan="8">
    <div class="fila-mas__caja">
      <?php /* EN ESPERA: el voucher se miró y todavía no aparece en el banco.
               La nota es obligatoria y la va a leer el asesor. */ ?>
      <form method="post" action="<?= e(url('/pagos/espera')) ?>" class="fila-mas__f">
        <?= campo_csrf() ?>
        <input type="hidden" name="id" value="<?= (int)$pg['id'] ?>">
        <label class="mini"><strong>Dejarlo en espera</strong> — lo miraste y todavía no aparece
          <input type="text" name="nota" maxlength="200" required
                 placeholder="Interbancario, entra mañana"
                 value="<?= e((string)($pg['espera_nota'] ?? '')) ?>"></label>
        <button class="chip chip--ambar" type="submit" style="cursor:pointer">Dejar en espera</button>
      </form>

      <?php /* DENEGAR: no va a aparecer. No anula el pedido — el cliente puede
               mandar el voucher bueno en diez minutos. */ ?>
      <form method="post" action="<?= e(url('/pagos/denegar')) ?>" class="fila-mas__f">
        <?= campo_csrf() ?>
        <input type="hidden" name="id" value="<?= (int)$pg['id'] ?>">
        <label class="mini"><strong>Denegarlo</strong> — no está y no va a estar
          <input type="text" name="motivo" maxlength="200" required
                 placeholder="No aparece en el extracto" data-denegar></label>
        <button class="chip chip--rojo" type="submit" style="cursor:pointer"
                data-confirmar="Denegar este pago. NO anula el pedido: se queda con su saldo por cobrar, por si el cliente manda el voucher bueno. ¿Seguimos?">Denegar</button>
      </form>

      <p class="mini" style="margin:0">El motivo lo va a leer el asesor.
        <a href="<?= e(url('/pedidos/facturar?id=' . (int)$pg['pedido_id'])) ?>"
           style="text-decoration:underline">Abrir la venta entera</a> si necesitas ver el pedido antes.</p>
    </div>
  </td>
</tr>
