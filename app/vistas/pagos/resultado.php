<?php
/**
 * LA VENTANA QUE SALE ENCIMA AL DECIDIR SOBRE UN PAGO.
 *
 * «Al aprobar, dejar en espera o denegar un pago, no quiero que lo lleve a la
 * página de facturar. Que salga igual una ventana como pop up, ahí
 * superpuesta, con el mensaje de la acción que realizó» (usuario, 2026-09-11).
 *
 * Se pinta sobre la pantalla donde se estaba trabajando —la bandeja o la
 * ficha—, así que al cerrarla facturación sigue exactamente donde iba. Antes
 * confirmar un pago te sacaba de la cola y había que volver a buscar por
 * dónde ibas, veinte pagos más esperando detrás.
 *
 * USA LA MISMA `.tapa` QUE «Pedido registrado», no una clase nueva: son la
 * misma figura —una ventana encima de la pantalla— y dos definiciones acaban
 * siendo dos ventanas que se ven distinto sin que nadie lo decidiera.
 *
 * SIN JAVASCRIPT PARA ABRIRLA NI PARA CERRARLA: se abre porque el servidor la
 * manda en el HTML y se cierra con un enlace a la misma pantalla sin el
 * ?hecho. Un <dialog> con showModal() se quedaría invisible el día que un
 * bloqueador corte el script, y lo que hay detrás es dinero ya confirmado.
 */
if (empty($resultado)) return;

$rp    = $resultado['p'];
$hecho = (string)$resultado['hecho'];

/* A dónde vuelve al cerrar: la pantalla que la abrió, que se lo pasa. Se
   recibe y no se adivina de REQUEST_URI porque esta ventana sale encima de dos
   pantallas distintas y cada una tiene que quedarse en la suya. */
$cerrar = $cerrar ?? url('/pagos/por-validar');
/* Y los BOTONES vuelven al mismo sitio que el enlace de cerrar. Estaban
   escritos a mano con «cola», así que quien confirmaba desde la ficha o desde
   la pantalla de facturar pulsaba ESTA NO LLEVA y acababa en la bandeja: la
   ventana existe precisamente para no mover a nadie de sitio. */
$vuelve = $vuelve ?? 'cola';

[$titulo, $icono, $tono] = match ($hecho) {
    'aprobado'    => ['Pago aprobado',    'check',  'ok'],
    'espera'      => ['Pago en espera',   'reloj',  'falta'],
    /* «comprobante» es la vuelta cuando anotar el comprobante FALLÓ: no se
       aprobó nada ahora, así que no se dice que sí. El motivo lo lleva el
       aviso rojo de arriba y los botones vuelven a estar delante. */
    'comprobante' => ['El comprobante',   'alerta', 'falta'],
    default       => ['Pago denegado',    'alerta', 'falta'],
};
$pregunta = in_array($hecho, ['aprobado', 'comprobante'], true);
?>
<div class="tapa" id="tapa-pago">
  <div class="tapa__caja">
    <a class="tapa__x" href="<?= e($cerrar) ?>" title="Cerrar" aria-label="Cerrar">&times;</a>

    <?php /* EL MISMO EJE QUE «Pedido registrado»: el icono ENCIMA del título y
             todo lo demás centrado debajo. Lo pide la clase `.tapa`, no esta
             pantalla — son la misma figura (usuario, 2026-09-14). */ ?>
    <div class="tapa__cab">
      <span class="tapa__ico tapa__ico--<?= e($tono) ?>"><?= ico($icono, 26) ?></span>
      <div class="tapa__t"><?= e($titulo) ?></div>
      <div class="tapa__s"><?= e((string)$rp['codigo']) ?> ·
        <?= e(trim((string)$rp['cliente_nombre'] . ' ' . (string)$rp['cliente_apellidos'])) ?></div>
    </div>

    <?php /* El cashback que acaba de ganar el cliente. Es la única noticia que
             el aviso verde traía y esta ventana tapa. */ ?>
    <?php if ((int)($resultado['cashback'] ?? 0) > 0): ?>
      <p class="mini" style="margin:12px 0 0">El cliente ganó
        <strong><?= e(soles((int)$resultado['cashback'])) ?></strong> de Cashback Waka.</p>
    <?php endif; ?>

<?php /* Y con NUBEFACT, solo si esta venta ya se puede emitir: la misma
         regla que la pantalla de facturar (usuario, 2026-09-22). */
$freno_emitir = nubefact_activo() ? pedido_emision_bloqueo($rp) : ''; ?>
    <?php if ($pregunta && empty($resultado['ya_tiene'])): ?>
      <?php /* LA PREGUNTA, EN SU FRANJA, separada por una línea de las salidas
               de abajo. Sin ella eran cinco botones seguidos y nada decía
               cuáles contestaban la pregunta y cuáles cerraban la ventana. */ ?>
      <div class="tapa__franja">
      <?php /* Si la venta todavía no se puede facturar, no se pregunta: la
               única respuesta a mano sería «ESTA NO LLEVA», y dejaría marcada
               como sin comprobante una venta que sí lleva (auditoría 2w). */ ?>
      <div class="tapa__franja__t"><?= $freno_emitir !== ''
        ? ($hecho === 'aprobado' ? 'El dinero entró.' : 'El comprobante')
        : ($hecho === 'aprobado' ? 'El dinero entró. ¿Emites el comprobante?'
                                 : '¿Emites el comprobante?') ?></div>
      <?php if ($freno_emitir !== ''): ?>
        <p class="mini" style="margin:-4px 0 0"><?= e($freno_emitir) ?></p>
      <?php endif; ?>
      <?php if ($freno_emitir === '' && $resultado['pide'] !== ''): ?>
        <p class="mini" style="margin:-4px 0 10px">El asesor indicó
          <strong><?= e(comprobante_pide_texto($resultado['pide'])) ?></strong>.
          Es solo una indicación: lo decides tú.</p>
      <?php endif; ?>

      <div class="acciones">
        <?php /* Los rótulos salen de la lista única, no escritos aquí. */ ?>
                <?php /* Con NUBEFACT configurado, EMITIR emite de verdad; sin él, anota
                 la constancia de uno emitido fuera (parche 2u). */
              $ruta_emitir = nubefact_activo() ? '/pedidos/emitir' : '/pedidos/comprobante'; ?>
        <?php foreach ($freno_emitir === '' ? comprobantes_que_se_solicitan() : [] as $tv => $tc): $tt = 'EMITIR ' . mb_strtoupper($tc['nombre']); ?>
          <form method="post" action="<?= e(url($ruta_emitir)) ?>" style="display:inline">
            <?= campo_csrf() ?>
            <input type="hidden" name="id" value="<?= (int)$rp['id'] ?>">
            <input type="hidden" name="tipo" value="<?= $tv ?>">
            <input type="hidden" name="volver" value="<?= e($vuelve) ?>">
            <button class="btn <?= $resultado['sugerido'] === $tv ? 'btn--negro' : 'btn--linea' ?>"
                    <?= $ruta_emitir === '/pedidos/emitir'
                        ? 'data-confirmar="' . e(nubefact_confirmar_texto($tc['nombre'], (int)$rp['total_centimos'])) . '"' : '' ?>
                    type="submit"><?= $tt ?></button>
          </form>
        <?php endforeach; ?>

        <?php if ($freno_emitir === ''): ?>
        <?php /* «ESTA NO LLEVA» NO PIDE MOTIVO (usuario, 2026-09-11): aquí no
                 siempre se emite comprobante y a veces es a propósito, así que
                 pedir una explicación cada vez sería regañar por lo normal.
                 Queda en la bitácora con quién y cuándo, que es lo que hace
                 falta para responder después. */ ?>
        <form method="post" action="<?= e(url('/pedidos/comprobante')) ?>" style="display:inline">
          <?= campo_csrf() ?>
          <input type="hidden" name="id" value="<?= (int)$rp['id'] ?>">
          <input type="hidden" name="tipo" value="ninguno">
          <input type="hidden" name="volver" value="<?= e($vuelve) ?>">
          <button class="btn btn--linea" type="submit">ESTA NO LLEVA</button>
        </form>
        <?php endif; ?>
      </div>

      <?php if ($freno_emitir !== ''): ?>
        <p class="mini" style="margin:10px 0 0"><?= e($freno_emitir) ?></p>
      <?php endif; ?>

      <?php if ($freno_emitir === ''): ?>
        <p class="mini" style="margin:12px 0 0">
          <?= nubefact_activo()
              ? 'Sale con NUBEFACT y le llega al cliente a su correo.'
              : 'Emítelo en tu sistema de facturación. La serie y el número los anotas después, desde el pedido.' ?>
        </p>
      <?php endif; ?>
      </div>

    <?php elseif ($pregunta): ?>
      <?php if ($hecho === 'aprobado'): ?>
        <p style="margin:16px 0 0;font-size:14.5px;font-weight:700">El dinero entró.</p>
      <?php endif; ?>
      <?php /* comprobante_tipo_texto(), no la del asesor: lo que él INDICA y lo
               que facturación EMITE se dicen con palabras distintas a propósito. */ ?>
      <p class="mini" style="margin:6px 0 0">Esta venta ya tiene su comprobante anotado:
        <?= e(comprobante_tipo_texto((string)$rp['comprobante_tipo'])) ?>.</p>

    <?php elseif ($hecho === 'espera'): ?>
      <p style="margin:16px 0 0;font-size:14.5px;font-weight:700">Queda anotado.</p>
      <p class="mini" style="margin:6px 0 0">El pago sale de «sin revisar» y el asesor ve por qué
        sigue esperando. No suma a ninguna meta hasta que lo confirmes.</p>

    <?php else: ?>
      <p style="margin:16px 0 0;font-size:14.5px;font-weight:700">El pedido no se anula.</p>
      <p class="mini" style="margin:6px 0 0">Se queda con su saldo por cobrar. El asesor recibe
        el motivo y puede registrar el voucher bueno.</p>
    <?php endif; ?>

    <div class="tapa__franja">
    <div class="acciones">
      <a class="btn btn--negro" href="<?= e($cerrar) ?>">
        <?= $pregunta ? 'CERRAR VENTANA' : 'SEGUIR REVISANDO' ?></a>
      <?php if ($hecho === 'aprobado' && puede('reportes.ver')): ?>
        <a class="btn btn--linea"
           href="<?= e(url('/reportes/pagos?desde=' . date('Y-m-d') . '&hasta=' . date('Y-m-d'))) ?>">
          VER INGRESOS DE HOY</a>
      <?php else: ?>
        <a class="btn btn--linea" href="<?= e(url('/pedidos/ficha?id=' . (int)$rp['id'])) ?>">
          VER EL PEDIDO</a>
      <?php endif; ?>
    </div>
    </div>
  </div>
</div>
