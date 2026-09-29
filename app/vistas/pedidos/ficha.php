<?php
$saldo    = pedido_saldo($p);
$anulado  = (string)$p['estado'] === 'anulado';
$cliente  = trim((string)$p['cliente_nombre'] . ' ' . (string)$p['cliente_apellidos']);
$flete_dentro = pedido_flete_dentro($p) > 0;

/* POR REGISTRAR, no «por cobrar». Son dos cifras distintas y esta pantalla
   usaba la que no era: `pedido_saldo()` solo descuenta el dinero CONFIRMADO,
   así que un pedido pagado del todo y esperando a facturación seguía
   enseñando el formulario de cobro CON EL MONTO ENTERO. Invitaba a cobrar dos
   veces, y lo encontró el usuario en su primera venta de prueba.
   `pedido_por_registrar()` sí descuenta lo registrado y sin confirmar — es la
   misma cifra contra la que pago_registrar() rechaza un pago de más. */
$por_registrar = pedido_por_registrar($p);
$puedo_pagar   = $puedo_tocar && !$anulado && $por_registrar > 0 && puede('pagos.registrar');

/* Todo registrado pero nadie lo ha mirado todavía. No es lo mismo que estar
   cobrado, y al asesor hay que decírselo con esas palabras en vez de dejarle
   un formulario que parece pedirle el dinero otra vez. */
$esperando = !$anulado && $por_registrar === 0 && $saldo > 0;
/* Dinero registrado que nadie ha mirado todavía. No es lo mismo que
   `$esperando`: una pre venta con su 10% de adelanto —el caso normal— tiene
   dinero esperando y además saldo por registrar, y también hay que decirlo. */
$pendiente = pedido_pendiente_confirmar($p);
/* SALDO SIN REGISTRAR y el WhatsApp al asesor (usuario, 2026-09-25): quien
   llega aquí desde el aviso de Administración tiene que poder preguntarle. */
$sin_registrar = $anulado ? 0 : pedido_saldo_sin_registrar((int)$p['id']);
$wa_asesor     = whatsapp_al_asesor($p, $sin_registrar);
/* En liquidación no hay cashback en ninguna de las dos direcciones, así que
   las frases que lo prometen no salen: la pantalla no puede decirle al asesor
   que su cliente «gana su cashback» en una venta que nunca se lo va a dar. */
$da_cashback = venta_da_cashback((string)$p['tipo']);
/* EL PAGO QUE PIDE UNA ACCIÓN. Miraba solo «en espera», así que un pago
   DENEGADO —el caso urgente, el que se anula solo en 48 horas— no pintaba
   nada: el asesor entraba al pedido y veía una ficha normal. Lo encontró el
   usuario el 2026-09-11. Ahora el estado sale de pedido_estado_pago(), la
   misma cifra que pinta el chip de la lista. */
$estado_pago = pedido_estado_pago($p);
$pinta_pago  = estado_pago_pinta($estado_pago);
$trabado     = null;
if ($estado_pago === 'espera') {
    foreach ($pagos as $pg_x) {
        if ((int)$pg_x['anulado'] === 0 && (int)($pg_x['en_espera'] ?? 0) === 1) { $trabado = $pg_x; break; }
    }
} elseif ($estado_pago === 'denegado') {
    /* LA ÚLTIMA DENEGACIÓN, NO LA PRIMERA. `pagos_del_pedido()` viene ordenado
       de más viejo a más nuevo, así que cogiendo el primero la banda enseñaba
       el motivo ANTIGUO —el que ya se resolvió— y, peor, el plazo de las 48
       horas contado desde aquella fecha: al asesor le decía en negrita «el
       pedido se anula solo el 08/09» tres días después del 08/09, sobre un
       pedido que sigue vivo. Las 48 horas se cuentan desde la última
       denegación, y así está escrito en pedidos_anular_denegados(): son la
       misma fecha y tienen que salir del mismo sitio. */
    foreach (array_reverse($pagos) as $pg_x) {
        if ((int)($pg_x['denegado'] ?? 0) === 1) { $trabado = $pg_x; break; }
    }
}
$wa_facturacion = whatsapp_facturacion_url($p);
?>
<header class="cabecera">
  <div>
    <div class="cabecera__sub"><a href="<?= e(url('/pedidos')) ?>">Pedidos</a></div>
    <h1><?= e($p['codigo']) ?>
      <span class="chip chip--<?= e($p['estado_color'] ?: 'gris') ?>"><?= e($p['estado_nombre']) ?></span>
    </h1>
    <div class="cabecera__sub">
      <?= e(tipo_venta_texto((string)$p['tipo'])) ?> ·
      <a href="<?= e(url('/clientes/ficha?id=' . (int)$p['cliente_id'])) ?>"><?= e($cliente) ?></a> ·
      <?= e(fecha_corta((string)($p['fecha'] ?: $p['creado_en']))) ?> ·
      <?= e(trim((string)$p['asesor_nombre'] . ' ' . (string)$p['asesor_apellidos'])) ?>
    </div>
  </div>
  <div class="cabecera__acciones">
    <?php if ($wa_asesor !== '' && $sin_registrar === 0): ?>
      <a class="chip chip--linea" id="wa-asesor" href="<?= e($wa_asesor) ?>" target="_blank" rel="noopener">
        <?= ico('chat',14) ?> Escribir a <?= e(primer_nombre((string)$p['asesor_nombre'])) ?></a>
    <?php endif; ?>
    <?php /* Despacho va primero y en amarillo: es lo que el asesor tiene que
             hacer justo después de vender, y era lo que hasta ahora escribía a
             mano en el grupo. El chip cambia cuando ya se mandó, para que no se
             mande dos veces por si acaso. */ ?>
    <?php /* El chip no ofrece lo que la ruta va a rechazar: un botón que lleva a
             una pantalla de error se lee como que el HUB está roto, no como una
             regla. Sale del mismo pedido_despacho_bloqueo() que la ruta. */ ?>
    <?php $bloqueo_desp = pedido_despacho_bloqueo($p);
          /* Y el PERMISO, que faltaba: mandar al grupo es tarea del asesor, y
             la ruta pide 'pedidos.despachar'. A Facturación y a Dirección el
             chip les salía por llevar 'pedidos.ver' y les contestaba 403. */
          $puedo_despachar = puede('pedidos.despachar'); ?>
    <?php /* LO QUE YA SALIÓ, SALIÓ. Una venta ya mandada que después queda
             frenada —el asesor sube el saldo y espera confirmación— no puede
             pasar a decir «en espera del pago»: la caja ya está en la calle
             (auditoría del 2s). */ ?>
    <?php $ya_salio = (int)($p['despacho_veces'] ?? 0) > 0; ?>
    <?php if ($puedo_despachar && (string)$p['estado'] !== 'anulado' && $ya_salio && $bloqueo_desp !== ''): ?>
      <span class="chip chip--verde">Ya salió a despacho</span>
    <?php elseif ($puedo_despachar && (string)$p['estado'] !== 'anulado' && $bloqueo_desp === '' && $ya_salio): ?>
      <a class="chip chip--verde" href="<?= e(url('/pedidos/despacho?id=' . (int)$p['id'])) ?>">Ya salió a despacho</a>
    <?php elseif ($puedo_despachar && (string)$p['estado'] !== 'anulado' && $bloqueo_desp === ''
                  && puedo_editar((int)$p['asesor_id'], (int)$p['pais_id'])): ?>
      <?php parte('pedidos/boton_despacho', ['p' => $p, 'clase' => 'chip chip--marca', 'html' => 'Mandar a despacho', 'volver' => 'ficha']); ?>
    <?php elseif ($puedo_despachar && (string)$p['estado'] !== 'anulado' && $bloqueo_desp === ''): ?>
      <?php /* El líder mira la venta de uno de los suyos: lista, pero no la manda él. */ ?>
      <a class="chip chip--linea" href="<?= e(url('/pedidos/despacho?id=' . (int)$p['id'])) ?>">Lista para despacho</a>
    <?php elseif ($puedo_despachar && (string)$p['estado'] !== 'anulado'): ?>
      <?php $mot_desp = pedido_despacho_motivo($p)['clave']; ?>
      <span class="chip chip--gris" title="<?= e($bloqueo_desp) ?>"><?= e($mot_desp === 'preventa' ? $bloqueo_desp
            : ($mot_desp === 'descuento' ? 'Despacho en espera del descuento' : 'Despacho en espera del pago')) ?></span>
    <?php endif; ?>
    <?php /* LOS TEXTOS PARA EL CLIENTE YA NO VIVEN AQUÍ (usuario,
             2026-09-22). Estaban en la cabecera compitiendo con las dos
             acciones del equipo. Ahora salen donde se usan: el de los datos
             para el pago, en la tarjeta de Pagos mientras quede saldo, y todos
             juntos en la pantalla de «Mandar a despacho». */ ?>
  </div>
</header>

<?php /* LO QUE HAY QUE HACER AHORA, EN GRANDE Y ARRIBA DEL TODO (usuario,
         2026-09-23). Sale de pedido_accion_pendiente(), que decide según lo
         que ESTA persona puede hacer: al asesor se le dice que la mande a
         despacho y a facturación que confirme el pago. Si no hay nada
         pendiente no se pinta nada: un cartel permanente se deja de leer. */ ?>
<?php $accion = pedido_accion_pendiente($p); ?>
<?php if ($accion): ?>
  <div class="accion-ahora accion-ahora--<?= e($accion['tono']) ?>">
    <span class="accion-ahora__ico"><?= ico($accion['tono'] === 'gris' ? 'reloj' : 'alerta', 22) ?></span>
    <span class="accion-ahora__t"><?= e($accion['texto']) ?></span>
    <?php if (!empty($accion['descuento'])): ?>
      <?php /* APROBAR O RECHAZAR EL DESCUENTO (3d). Rechazar pide una nota
               opcional: es lo que lee el asesor para explicárselo al cliente. */ ?>
      <div class="accion-ahora__desc" id="desc-resolver">
        <form method="post" action="<?= e(url('/pedidos/descuento')) ?>" style="display:inline">
          <?= campo_csrf() ?>
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="accion" value="aprobar">
          <button class="btn btn--negro" type="submit">APROBAR</button>
        </form>
        <form method="post" action="<?= e(url('/pedidos/descuento')) ?>" class="desc-rechazo">
          <?= campo_csrf() ?>
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="accion" value="rechazar">
          <input type="text" name="nota" maxlength="<?= (int) DESCUENTO_MOTIVO_MAX ?>"
                 placeholder="Por qué no (lo lee el asesor)" aria-label="Por qué se rechaza">
          <button class="btn btn--linea" type="submit">RECHAZAR</button>
        </form>
      </div>
    <?php elseif ($accion['boton'] !== ''): ?>
      <?php if (!empty($accion['externo'])): ?>
        <a class="btn btn--negro" id="accion-wa" href="<?= e($accion['ruta']) ?>" target="_blank" rel="noopener"><?= ico('chat',16) ?> <?= e($accion['boton']) ?></a>
      <?php else: ?>
        <?php if (!empty($accion['despacho'])): ?>
          <?php parte('pedidos/boton_despacho', ['p' => $p, 'clase' => 'btn btn--negro', 'html' => e($accion['boton']), 'volver' => 'ficha']); ?>
        <?php else: ?>
        <a class="btn btn--negro" href="<?= e(url($accion['ruta'])) ?>"><?= e($accion['boton']) ?></a>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php /* La línea de tiempo, lo primero que se ve. Contesta de un vistazo las
         cuatro preguntas que se hacen de verdad sobre una venta: cuándo entró,
         si ya le confirmaron el pago, si ya salió y si llegó. */ ?>
<?php parte('pedidos/hitos', ['p' => $p]); ?>

<?php /* Al registrar, un aviso encima del pedido y no una línea más entre
         otras. Es lo primero que ve el asesor después de cerrar una venta:
         tiene que decirle que salió bien, cuánto queda y qué hace ahora.
         Antes soltaba en la ficha con una franja verde que se leía igual que
         el resto de la pantalla. Lo pidió el usuario el 2026-09-11. */ ?>
<?php /* Y no en un pedido anulado: la marca viaja en la URL, así que volver
         atrás en el navegador enseñaba «Pedido registrado» encima del aviso
         rojo de anulado. */ ?>
<?php if ($nuevo && !$anulado):
  /* LA CIFRA DEL CLIENTE, no la de registro. `pedido_por_registrar()` cuenta
     como pagado el dinero que facturación dejó EN ESPERA —el banco dice que no
     llegó—, así que la tapa decía «Pagó ahora S/ 379 · Restante S/ 0.00» y la
     tarjeta de debajo, en la misma pantalla, «Cobrado S/ 0.00 · Saldo S/ 379».
     pedido_pagado_cliente() es la que ya usa el mensaje al cliente por este
     mismo motivo: es lo que pagó y nadie ha puesto en duda. */
  $pagado_ya = pedido_pagado_cliente($p);
  $falta_ya  = pedido_por_cobrar_cliente($p); ?>
  <div class="tapa" id="tapa-nuevo">
    <?php /* LA RACHA (5a), superpuesta sobre la confirmación y sin
             reemplazarla: el mensaje de WhatsApp y el voucher siguen ahí.
             x2 suave, x3 amarillo, x4 negro, x5+ más grande. */ ?>
    <?php if (!empty($racha_venta) && (int)$racha_venta['n'] >= 2): $rn = (int)$racha_venta['n']; ?>
      <div class="racha-pop racha-pop--<?= min(5, $rn) ?>" id="racha-pop" role="status">
        <img class="racha-pop__logo" src="<?= e(url('assets/img/isotipo-waka.png')) ?>" alt="">
        <strong class="racha-pop__x">x<?= $rn ?></strong>
        <span class="racha-pop__n"><?= e((string)$racha_venta['nombre']) ?><?= !empty($racha_venta['record']) ? ' · ¡récord!' : '' ?></span>
        <span class="racha-pop__d"><?= plural($rn, 'cliente distinto hoy', 'clientes distintos hoy') ?> · <?= plural((int)$racha_venta['dias'], 'día seguido', 'días seguidos') ?></span>
        <button type="button" class="racha-pop__x2" aria-label="Cerrar" onclick="this.parentNode.remove()">×</button>
      </div>
    <?php endif; ?>
    <div class="tapa__caja">
      <div class="tapa__cab">
        <span class="tapa__ico"><?= ico('check', 26) ?></span>
        <div class="tapa__t">Pedido registrado</div>
        <div class="tapa__s"><?= e($p['codigo']) ?> · <?= e($cliente) ?></div>
      </div>

      <?php /* Los rótulos son los que escribió el usuario en el dibujo
               (2026-09-11). El tercero CAMBIA con lo que hay: «restante» a
               cero no es una deuda y no tiene por qué leerse como una; con
               saldo sí, y entonces dice lo que de verdad falta hacer. */ ?>
      <div class="tapa__cifras">
        <div><span>Total</span><strong><?= e(soles((int)$p['total_centimos'])) ?></strong></div>
        <div><span>Pagó ahora</span><strong><?= e(soles($pagado_ya)) ?></strong></div>
        <div><span><?= $falta_ya > 0 ? 'Queda por cobrar' : 'Restante' ?></span>
          <strong class="<?= $falta_ya > 0 ? 'tapa__falta' : 'tapa__ok' ?>">
          <?= e(soles($falta_ya)) ?></strong></div>
      </div>

      <?php /* Cuando hay dinero esperando, aunque no sea todo: la pre venta con
               su 10% de adelanto es el caso normal, y ahí el asesor también
               tiene que saber que ese adelanto no suma a su meta hasta que
               facturación lo mire. Con un método que contara al instante
               —el interruptor existe, hoy apagado— no hay nada pendiente y
               esto no sale. */ ?>
      <?php if ($pendiente > 0): ?>
        <?php /* La frase es la del usuario, palabra por palabra (2026-09-11).
                 Lo que NO se dice ya: «no suma a tu meta y no puede salir a
                 despacho». Es verdad, pero el asesor acaba de hacer bien su
                 trabajo y lo primero que leía era lo que todavía no tiene. */ ?>
        <div class="aviso aviso--amarillo" style="margin-top:14px">
          <span><?= ico('reloj',17) ?></span>
          <span>Esperando confirmación por parte de facturación.
            <strong>Te notificaremos cuando el pago esté aprobado.</strong></span>
        </div>
      <?php endif; ?>

      <?php /* LA SOLICITUD DEL COMPROBANTE, AQUÍ MISMO (usuario, 2026-09-12).
               Es el momento en que el asesor acaba de hablar con el cliente y
               sabe si quiere boleta, factura o nada — no cuando estaba
               tecleando el formulario. Viene destacado lo que dice la ficha
               del cliente (DNI → boleta, RUC → factura), pero manda él.
               NO PEDIR NADA ES UNA RESPUESTA: cerrar la ventana sin pulsar
               deja la venta sin comprobante, que aquí es lo normal. */ ?>
      <?php $pedido_ya = (string)($p['comprobante_pide'] ?? '');
            /* Y NO si facturación ya lo resolvió mientras esta ventana estaba
               abierta —una venta al contado se confirma y se emite en el mismo
               minuto—: el botón contestaría con un error rojo. */
            $puedo_pedir_tapa = $puedo_tocar && puede('pedidos.editar')
                                && columna_existe('pedidos', 'comprobante_pide')
                                && trim((string)($p['comprobante_tipo'] ?? '')) === ''; ?>
      <?php if ($puedo_pedir_tapa): ?>
        <div class="tapa__pide">
          <?php if ($pedido_ya === ''): ?>
            <div class="tapa__pide__t">¿El cliente pide comprobante?</div>
            <div class="acciones">
              <?php foreach (comprobantes_que_se_solicitan() as $sc_k => $sc): ?>
                <form method="post" action="<?= e(url('/pedidos/solicitar-comprobante')) ?>">
                  <?= campo_csrf() ?>
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <input type="hidden" name="tipo" value="<?= e($sc_k) ?>">
                  <?php /* AL INICIO (usuario, 2026-09-14). Pedir el comprobante
                           es lo último que hace el asesor con esta venta: desde
                           aquí ya no queda nada que mirar en ella, y quedarse
                           en la ficha le obliga a un clic más para volver a
                           trabajar. Es el mismo destino que «ESPERAR
                           CONFIRMACIÓN», que es el botón de al lado. */ ?>
                  <input type="hidden" name="volver" value="inicio">
                  <button class="btn <?= ($comprobante_ini ?? '') === $sc_k ? 'btn--negro' : 'btn--linea' ?>"
                          type="submit"><?= e($sc['boton']) ?></button>
                </form>
              <?php endforeach; ?>
            </div>
            <p class="mini" style="margin:8px 0 0">Si no pide ninguno, no pulses nada: esta venta
              va sin comprobante. Lo puedes pedir después desde el menú ⋮ de Pedidos.</p>
          <?php else: ?>
            <div class="tapa__pide__t"><?= ico('check',15) ?>
              <?= e(comprobante_pide_texto($pedido_ya)) ?> solicitada</div>
            <p class="mini" style="margin:6px 0 0">Le sale a facturación en su bandeja. Si el
              cliente cambia de idea, se cambia desde el menú ⋮ de Pedidos.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="tapa__franja">
      <div class="acciones">
        <?php /* «ESPERAR CONFIRMACIÓN» y al Inicio (usuario, 2026-09-11). Era
                 «MENSAJE PARA EL CLIENTE», y ahí estaba el problema: invitaba a
                 escribirle al cliente ANTES de que facturación mirara el
                 voucher. El mensaje no se pierde — se copia desde la ficha, en
                 «Textos rápidos», que es donde se mira cuando ya hay algo que
                 contar. */ ?>
        <a class="btn btn--amarillo" href="<?= e(url('/inicio')) ?>">ESPERAR CONFIRMACIÓN</a>
        <?php /* Un ENLACE y no un botón: sin JavaScript la tapa taparía la ficha
                 entera y no habría forma de salir. Así siempre se puede. */ ?>
        <a class="btn btn--linea" id="tapa-cerrar"
           href="<?= e(url('/pedidos/ficha?id=' . (int)$p['id'])) ?>">VER EL PEDIDO</a>
        <a class="btn btn--linea" href="<?= e(url('/pedidos/nuevo')) ?>">OTRA VENTA</a>
      </div>
      <p class="mini" style="margin:12px 0 0">El pedido ya está guardado.</p>
      </div>
    </div>
  </div>
  <script>
  (function () {
    var t = document.getElementById('tapa-nuevo');
    if (!t) return;
    function cerrar() { t.remove(); }
    document.getElementById('tapa-cerrar').addEventListener('click', function (ev) {
      ev.preventDefault();   // con JS no hace falta recargar: basta con quitarla
      cerrar();
    });
    t.addEventListener('click', function (ev) { if (ev.target === t) cerrar(); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') cerrar(); });
  })();
  </script>
<?php endif; ?>

<?php if ($anulado): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><strong>Pedido anulado</strong> el <?= e(fecha_corta((string)$p['anulado_en'])) ?> ·
      <?= e(lista_texto($p['anulado_motivo_item_id'] ? (int)$p['anulado_motivo_item_id'] : null)) ?>
      <?= $p['anulado_nota'] ? ' · ' . e((string)$p['anulado_nota']) : '' ?>
      <?php if ((int)$p['por_devolver_centimos'] > 0): ?>
        <br>Hay que devolverle al cliente <strong><?= e(soles((int)$p['por_devolver_centimos'])) ?></strong>,
        que es lo que llegó a pagar. El flete de provincia no entra: ese se lo pagó a la agencia.
      <?php endif; ?>
    </span>
  </div>
<?php endif; ?>

<?php /* La ventana de «qué acaba de pasar con el pago», cuando se confirmó,
         se dejó en espera o se denegó desde esta misma ficha. */ ?>
<?php parte('pagos/resultado', ['resultado' => $resultado ?? [],
                                'cerrar'    => url('/pedidos/ficha?id=' . (int)$p['id']),
                                'vuelve'    => 'pedido']); ?>

<?php /* LA BANDA QUE NO SE PUEDE PASAR POR ALTO.
         Antes esto era un aviso gris a mitad de la página, debajo de la tabla
         de pagos, y el asesor no lo veía: «no es muy notorio y puede pasar
         desapercibido» (usuario, 2026-09-11). Ahora va ARRIBA DEL TODO, con el
         motivo que escribió facturación, el plazo real y los dos botones.
         Solo a quien puede hacer algo: al que solo mira, un botón de acción
         que le va a dar 403 se lee como que el HUB está roto. */ ?>
<?php if ($pinta_pago['accion'] && $puedo_tocar && !$anulado): ?>
  <div class="aviso aviso--<?= $estado_pago === 'denegado' ? 'rojo' : 'amarillo' ?> aviso--accion">
    <span><?= ico('alerta',20) ?></span>
    <span>
      <strong style="font-size:15px">
        <?= $estado_pago === 'denegado'
            ? 'Facturación DENEGÓ este pago. Revisa el pedido ahora.'
            : 'Facturación dejó este pago en espera. Revisa el pedido.' ?></strong>
      <?php $nota_tr = trim((string)($trabado['espera_nota'] ?? $trabado['anulado_motivo'] ?? '')); ?>
      <?php if ($nota_tr !== ''): ?>
        <br>«<?= e($nota_tr) ?>»
      <?php endif; ?>
      <?php /* El plazo, solo cuando lo hay: un denegado sin arreglar anula el
               pedido a las 48 horas. Decirlo en «en espera», donde no se anula
               nada, sería asustar con algo que no va a pasar. */ ?>
      <?php /* Y SOLO SI DE VERDAD SE VA A ANULAR: pedido_se_anula_solo() aplica
               la misma condición que la tarea, que exige además que no quede
               ningún pago vivo. Una pre venta con el adelanto confirmado y el
               saldo denegado leía en negrita una fecha que nunca iba a llegar,
               y una amenaza que no se cumple enseña a no creerse las demás. */ ?>
      <?php if ($estado_pago === 'denegado' && !empty($trabado['anulado_en'])
                && pedido_se_anula_solo($p)): ?>
        <br>Si no se realiza alguna acción, <strong>el pedido se anula solo el
          <?php /* El plazo sale de HORAS_PARA_ARREGLAR_DENEGADO, que es el mismo
                   que aplica pedidos_anular_denegados(): escrito a mano aquí,
                   el asesor leía en negrita una fecha que la tarea automática
                   no respetaba. */ ?>
          <?= e(fecha_corta(date('Y-m-d H:i:s', strtotime((string)$trabado['anulado_en']
               . ' +' . HORAS_PARA_ARREGLAR_DENEGADO . ' hours')))) ?></strong>.
      <?php endif; ?>
      <span class="acciones" style="margin-top:12px">
        <?php /* CORREGIR PAGO hace lo que hasta ahora eran dos pasos que nadie
                 encontraba: un «Quitar» diminuto en la fila del pago y una
                 frase que decía «vuelve a registrarlo».
                 · En ESPERA el pago sigue vivo, así que primero hay que
                   quitarlo: va por POST, con su testigo, a la misma ruta que
                   el botón «Quitar» de siempre. Al volver, el formulario de
                   pago ya está abierto porque sube lo que falta por registrar.
                 · DENEGADO ya está anulado por facturación: no hay nada que
                   quitar y basta con bajar al formulario. */ ?>
        <?php if ($estado_pago === 'espera' && !empty($trabado['id'])): ?>
          <form method="post" action="<?= e(url('/pagos/quitar')) ?>" style="display:inline">
            <?= campo_csrf() ?>
            <input type="hidden" name="id" value="<?= (int)$trabado['id'] ?>">
            <input type="hidden" name="motivo" value="Se corrige: facturación lo dejó en espera">
            <button class="btn btn--negro" type="submit">CORREGIR PAGO</button>
          </form>
        <?php else: ?>
          <a class="btn btn--negro" href="#pagos">CORREGIR PAGO</a>
        <?php endif; ?>
        <?php if ($wa_facturacion !== ''): ?>
          <a class="btn btn--linea" href="<?= e($wa_facturacion) ?>" target="_blank" rel="noopener">
HABLAR CON FACTURACIÓN</a>
        <?php endif; ?>
      </span>
    </span>
  </div>
<?php endif; ?>

<div class="rejilla rejilla--panel">
  <div style="display:flex;flex-direction:column;gap:12px">

    <!-- Productos ─────────────────────────────────────────────── -->
    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Qué lleva</h2></div>
      <div class="tabla__caja">
        <table class="tabla">
          <thead><tr><th>Producto</th><th class="der">Cant.</th>
            <th class="der">Precio</th><th class="der">Total</th></tr></thead>
          <tbody>
          <?php foreach ($lineas as $l): ?>
            <tr>
              <td class="principal">
                <?php $fu = producto_foto_url($fotos_lineas[(int)($l['producto_id'] ?? 0)] ?? null); ?>
                <?php if ($fu !== ''): ?><img class="foto-prod foto-prod--linea" src="<?= e($fu) ?>" alt="" width="40" height="40" loading="lazy"><?php endif; ?>
                <div class="fila__t"><?= e($l['descripcion']) ?></div>
                <?php /* El modelo y el color se prometieron al cliente y viajan
                         al grupo y al rótulo: aquí es donde se comprueba lo que
                         se prometió cuando llama preguntando. */ ?>
                <?php $mod_l = trim((string)($l['modelo'] ?? '')); ?>
                <?php if ($mod_l !== ''): ?><div class="fila__s"><?= e($mod_l) ?></div><?php endif; ?>
                <?php if ($l['sku']): ?><div class="fila__s"><?= e($l['sku']) ?></div><?php endif; ?>
              </td>
              <td class="der num" data-k="Cant."><?= (int)$l['cantidad'] ?></td>
              <td class="der num" data-k="Precio"><?= e(soles((int)$l['precio_unit_centimos'])) ?></td>
              <td class="der num" data-k="Total"><?= e(soles((int)$l['total_centimos'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php /* EL STOCK DE LA WEB de esta venta (3e): descontado, por
               descontar, devuelto. Solo si la venta tocó la web. */ ?>
      <?php if (!empty($stock_web)): ?>
        <p class="mini stock-venta stock-venta--<?= e($stock_web['tono']) ?>" id="stock-venta"><?= ico('caja',14) ?>
          <?= e($stock_web['texto']) ?></p>
      <?php endif; ?>

      <div class="dato"><span class="dato__k">Subtotal</span>
        <span class="dato__t"><?= e(soles((int)$p['subtotal_centimos'])) ?></span></div>
      <?php if ((int)$p['cashback_usado_centimos'] > 0): ?>
        <div class="dato"><span class="dato__k">Cashback Waka usado</span>
          <span class="dato__t">− <?= e(soles((int)$p['cashback_usado_centimos'])) ?></span></div>
      <?php endif; ?>
      <?php /* EL DESCUENTO (3d): el que resta, o el que se pidió y se
               rechazó, para que el rastro no desaparezca. */ ?>
      <?php $d_est = (string)($p['descuento_estado'] ?? ''); ?>
      <?php if ((int)$p['descuento_centimos'] > 0 || $d_est === 'rechazado'): ?>
        <div class="dato" id="dato-descuento"><span class="dato__k">Descuento
            <?php if ($d_est === 'pendiente' && !$anulado): ?><span class="chip chip--ambar">Por aprobar</span>
            <?php elseif ($d_est === 'rechazado'): ?><span class="chip chip--rojo">Rechazado</span><?php endif; ?></span>
          <span class="dato__t"><?php if ($d_est === 'rechazado'): ?>
              <s><?= e(soles((int)($p['descuento_pedido_centimos'] ?? 0))) ?></s>
            <?php else: ?>− <?= e(soles((int)$p['descuento_centimos'])) ?>
              <span class="mini">(<?= e(descuento_pct_texto((int)$p['descuento_centimos'], (int)$p['subtotal_centimos'])) ?>)</span>
            <?php endif; ?></span></div>
        <?php if (trim((string)($p['descuento_motivo'] ?? '')) !== ''): ?>
          <p class="mini" style="margin:-4px 0 6px">Motivo: <?= e((string)$p['descuento_motivo']) ?>
            <?php if ($d_est === 'rechazado' && trim((string)($p['descuento_nota'] ?? '')) !== ''): ?>
              · No se aprobó: <?= e((string)$p['descuento_nota']) ?><?php endif; ?></p>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (pedido_flete_falta($p)): ?>
        <div class="dato"><span class="dato__k">Envío</span>
          <span class="dato__t"><span class="chip chip--ambar">Por poner</span>
            <span class="mini">· todavía no suma al total</span></span></div>
      <?php endif; ?>
      <?php if ((int)$p['flete_centimos'] > 0): ?>
        <div class="dato"><span class="dato__k">Flete</span>
          <span class="dato__t"><?= e(soles((int)$p['flete_centimos'])) ?>
            <span class="mini"><?= $flete_dentro ? '· lo cobra Waka' : '· lo paga el cliente en destino, fuera del total' ?></span>
          </span></div>
      <?php endif; ?>
      <div class="dato"><span class="dato__k"><strong>Total</strong></span>
        <span class="dato__t"><strong><?= e(soles((int)$p['total_centimos'])) ?></strong></span></div>
      <div class="dato"><span class="dato__k">Cobrado</span>
        <span class="dato__t"><?= e(soles((int)$p['cobrado_centimos'])) ?></span></div>
      <?php /* EL SALDO, RESALTADO CUANDO HAY SALDO (usuario, 2026-09-22): es
               la cifra por la que se vuelve a esta pantalla. A cero no se
               resalta: cero no es una deuda. */ ?>
      <div class="dato"><span class="dato__k">Saldo por cobrar</span>
        <span class="dato__t"><?php if ($saldo > 0 && !$anulado): ?>
            <span class="saldo-cifra"><?= e(soles($saldo)) ?></span>
          <?php else: ?><strong><?= e(soles(0)) ?></strong><?php endif; ?></span></div>
    </div>

    <!-- Pagos ─────────────────────────────────────────────────── -->
    <div class="tarjeta" id="pagos">
      <div class="tarjeta__cab"><h2>Pagos</h2>
        <?php if (!$anulado && $saldo <= 0): ?>
          <span class="chip chip--verde"><?= ico('check',13) ?> Pagado del todo</span>
        <?php elseif ($esperando): ?>
          <span class="chip chip--ambar">Esperando confirmación</span>
        <?php elseif ($por_registrar > 0 && $pagos): ?>
          <span class="chip chip--ambar">Falta cobrar <?= e(soles($por_registrar)) ?></span>
        <?php endif; ?>
      </div>
      <?php if (!$pagos): ?>
        <p class="mini">Todavía no ha pagado nada.<?php if ($da_cashback): ?> El cashback
          nace de cada pago, así que este pedido aún no le ha dado saldo al cliente.<?php endif; ?></p>
      <?php else: ?>
        <div class="tabla__caja">
          <table class="tabla">
            <thead><tr><th>Fecha</th><th>Método</th><th>Operación</th>
              <th class="der">Monto</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pagos as $pg): ?>
              <tr class="<?= (int)$pg['anulado'] === 1 ? 'apagada' : '' ?>">
                <td class="mini"><?= e(fecha_corta((string)$pg['fecha'])) ?></td>
                                <td data-k="Método"><?= e($pg['metodo'] ?: '—') ?>
                  <?php /* «1.er pago», «2.º pago · Saldo contraentrega»: el número se
                           cuenta y el concepto lo escribió el asesor. */ ?>
                  <div class="fila__s"><?= e(pago_rotulo($pg)) ?></div></td>
                <td data-k="Operación"><span class="mini"><?= e($pg['operacion'] ?: '—') ?></span></td>
                <td class="der num" data-k="Monto">
                  <?= (string)$pg['tipo'] === 'devolucion' ? '− ' : '' ?><?= e(soles(abs((int)$pg['monto_centimos']))) ?>
                </td>
                <td data-k="Estado">
                  <?php [$k_cls, $k_txt, $k_ico, $k_nota] = pago_chip($pg); ?>
                  <span class="chip <?= e($k_cls) ?>">
                    <?= $k_ico ? ico($k_ico, 12) : '' ?><?= e($k_txt) ?></span>
                  <?php if ($k_nota !== ''): ?>
                    <div class="fila__s" style="max-width:260px"><?= e($k_nota) ?></div>
                  <?php endif; ?>
                </td>
                <td class="der">
                  <?php if ($pg['voucher']): ?>
                    <a class="chip chip--linea" target="_blank" rel="noopener"
                       href="<?= e(url('/pagos/voucher?id=' . (int)$pg['id'])) ?>">Ver voucher</a>
                  <?php endif; ?>
                  <?php if (!empty($pg['foto_dni'])): ?>
                    <a class="chip chip--linea" target="_blank" rel="noopener" data-ver-dni
                       href="<?= e(url('/pagos/voucher?que=dni&id=' . (int)$pg['id'])) ?>">Ver DNI</a>
                  <?php endif; ?>
                  <?php if ((int)$pg['anulado'] === 0 && (string)$pg['tipo'] === 'cobro'): ?>
                    <?php if ((int)$pg['verificado'] === 0): ?>
                      <?php if (puede('pagos.verificar')): ?>
                        <form method="post" action="<?= e(url('/pagos/validar')) ?>"
                              style="display:inline-flex;gap:6px;align-items:center">
                          <?= campo_csrf() ?>
                          <input type="hidden" name="id" value="<?= (int)$pg['id'] ?>">
                          <input type="hidden" name="volver" value="pedido">
                          <input type="text" name="operacion" maxlength="60" value="<?= e((string)$pg['operacion']) ?>"
                                 placeholder="N.º de operación" style="width:130px"
                                 title="Cópialo del extracto del banco antes de validar.">
                          <button class="chip chip--verde" type="submit" style="cursor:pointer">Validar</button>
                        </form>
                      <?php endif; ?>
                      <?php if ($puedo_tocar && puede('pagos.registrar')): ?>
                        <?php /* El asesor también quita su propio pago sin confirmar, y con
                                 motivo: si el cliente le mandó un voucher falso, lo normal es
                                 que se dé cuenta él primero. Queda anotado en la ficha y sale
                                 en Reportes › Pagos ingresados › «Denegados y quitados». */ ?>
                        <form method="post" action="<?= e(url('/pagos/quitar')) ?>"
                              style="display:inline-flex;gap:6px;align-items:center">
                          <?= campo_csrf() ?>
                          <input type="hidden" name="id" value="<?= (int)$pg['id'] ?>">
                          <input type="text" name="motivo" maxlength="200" style="width:150px"
                                 placeholder="Motivo · p. ej. voucher falso"
                                 title="Queda escrito en la ficha y en el reporte.">
                          <button class="chip chip--linea" type="submit" style="cursor:pointer"
                                  data-confirmar="¿Quitar este pago sin confirmar? Todavía no contaba para nada, y el motivo queda anotado.">Quitar</button>
                        </form>
                      <?php endif; ?>
                    <?php elseif (puede('pagos.anular') && !$anulado && empty($pg['ya_revertido'])): ?>
                      <?php /* DOS botones, no uno. La aritmética es la misma —una fila
                               negativa con la fecha de hoy— pero lo que pasó no lo es, y
                               «Devolver» a secas mezclaba las dos cosas: quien lee el
                               movimiento después no sabía si salió plata de la caja. */ ?>
                      <form method="post" action="<?= e(url('/pagos/devolver')) ?>" style="display:inline">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="id" value="<?= (int)$pg['id'] ?>">
                        <input type="hidden" name="clase" value="error">
                        <button class="chip chip--linea" type="submit" style="cursor:pointer"
                                title="Este dinero nunca entró: se confirmó por equivocación. No salió nada de la caja."
                                data-confirmar="Deshacer esta confirmación: se confirmó por error y ese dinero nunca entró. El pedido vuelve a quedar con su saldo por cobrar y la meta del asesor baja ESTE mes. ¿Seguimos?">Se confirmó por error</button>
                      </form>
                      <form method="post" action="<?= e(url('/pagos/devolver')) ?>" style="display:inline">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="id" value="<?= (int)$pg['id'] ?>">
                        <input type="hidden" name="clase" value="cliente">
                        <button class="chip chip--linea" type="submit" style="cursor:pointer"
                                title="El dinero se le regresó al cliente de verdad."
                                data-confirmar="Anotar que se le devolvió el dinero al cliente. Se anota una fila negativa con la fecha de hoy: la meta del mes en que entró NO cambia, baja la de este mes. ¿Seguimos?">Devolver al cliente</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php /* Ni el formulario ni el silencio: cuando está todo registrado y
               esperando, se dice qué pasa y qué se traba mientras tanto. */ ?>
      <?php if ($esperando && $puedo_tocar): ?>
        <div class="aviso aviso--amarillo" style="margin-top:14px">
          <span><?= ico('reloj',17) ?></span>
          <?php if ($trabado): ?>
            <span><strong>Facturación dejó este pago en espera.</strong>
              <?= $trabado['espera_nota'] ? '«' . e((string)$trabado['espera_nota']) . '».' : '' ?>
              No registres el mismo dinero otra vez: quita ese pago de arriba y vuelve a
              registrarlo con lo que te piden.</span>
          <?php else: ?>
            <span><strong>Ya está todo registrado. Facturación lo está revisando.</strong>
              Cuando lo confirmen, suma a tu meta<?= $da_cashback ? ', el cliente gana su cashback' : '' ?>
              y podrás mandarlo a despacho. Te avisamos aquí — no hace falta que le escribas a nadie.</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php /* LOS TEXTOS PARA EL CLIENTE, donde se usan: junto al dinero.
               Estaban arriba, en la cabecera, compitiendo con las acciones del
               equipo (usuario, 2026-09-22). El de los datos para el pago solo
               mientras quede saldo; el resto, siempre que haya algo que
               mandar. */ ?>
      <?php if ($plantillas && !$anulado): ?>
        <div class="acciones" style="margin-top:10px">
          <span class="mini" style="align-self:center">Textos para el cliente:</span>
          <?php foreach ($plantillas as $k_pl => $nom_pl): ?>
            <?php if ($k_pl === 'datos_pago' && $saldo <= 0) continue; ?>
            <a class="chip chip--linea"
               href="<?= e(url('/pedidos/mensaje?id=' . (int)$p['id'] . '&p=' . urlencode((string)$k_pl))) ?>">
              <?= ico('copiar', 14) ?> <?= e($nom_pl) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($puedo_pagar): ?>
        <form method="post" action="<?= e(url('/pagos/registrar')) ?>" enctype="multipart/form-data"
              class="form" style="margin-top:14px;border-top:1px solid var(--linea);padding-top:14px">
          <?php /* CON SU TÍTULO (usuario, 2026-09-22): el formulario aparecía
                   pelado debajo de la tabla y no decía que era para AÑADIR
                   otro pago. */ ?>
          <h3 style="font-size:14px;margin:0 0 10px"><?= $pagos ? 'Añadir otro pago' : 'Registrar el primer pago' ?></h3>
          <?= campo_csrf() ?>
          <input type="hidden" name="pedido_id" value="<?= (int)$p['id'] ?>">
          <div class="form__fila">
            <label>Monto
              <input type="text" name="monto" inputmode="decimal" required
                     placeholder="<?= e(soles($por_registrar, false)) ?>">
              <?php /* Lo que falta por REGISTRAR, no por cobrar: es la cifra
                       contra la que el servidor rechaza el pago. Con el saldo
                       la pantalla decía «como mucho S/ 500» y el HUB contestaba
                       «es mayor que lo que falta (S/ 300), ya hay S/ 200
                       esperando confirmación». */ ?>
              <span class="ayuda">Como mucho <?= e(soles($por_registrar)) ?>, que es lo que falta
                por registrar.</span>
            </label>
            <label>Método
              <select name="metodo_item_id" required id="ficha-metodo" data-metodo-fotos>
                <option value="">Elige el método</option>
                <?php foreach ($metodos as $m): ?>
                  <option value="<?= (int)$m['id'] ?>" data-instante="<?= (int)($m['al_instante'] ?? 0) ?>"
                          data-dni="<?= metodo_pide_dni((int)$m['id']) ? 1 : 0 ?>"
                          <?= (int)($_GET['metodo'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
                    <?= e($m['valor']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div class="form__fila">
            <label>Fecha
                                          <input type="date" name="fecha" value="<?= date('Y-m-d') ?>"
                     <?= puede('pagos.verificar') ? '' : 'min="' . date('Y-m-d', strtotime('-30 days')) . '"' ?>
                     max="<?= date('Y-m-d') ?>">
              <span class="ayuda">Decide a qué mes suma el dinero.</span>
            </label>
            <?php if (puede('pagos.verificar')): ?>
              <label>N.º de operación
                <input type="text" name="operacion" maxlength="60">
                <span class="ayuda">Cópialo del voucher o del extracto.</span>
              </label>
            <?php else: ?>
              <?php /* Al asesor NO se le pide: ese número solo sirve para
                       cuadrar contra el extracto del banco, y él lo copiaría de
                       una captura con el cliente esperando, que es donde se
                       cuela un dígito cambiado. Lo pone quien valida, que tiene
                       el extracto delante. Lo decidió el usuario el 2026-09-08,
                       y aquí seguía pedido: dos pantallas del mismo HUB
                       diciendo cosas contrarias. */ ?>
              <div></div>
            <?php endif; ?>
          </div>
                    <?php /* EL CONCEPTO, desde el segundo pago (usuario, 2026-09-21): el
                   primero se registró con la venta; los siguientes tienen que
                   decir qué son. El servidor lo exige igual. */ ?>
          <?php $hay_cobro_previo = false;
                foreach ($pagos as $pg_c) if ((string)($pg_c['tipo'] ?? 'cobro') === 'cobro') $hay_cobro_previo = true; ?>
          <?php if ($hay_cobro_previo && columna_existe('pagos', 'concepto')): ?>
            <label>Concepto
              <input type="text" name="concepto" maxlength="80" required
                                          placeholder="Saldo contraentrega, segundo adelanto…">
              <span class="ayuda">Será el <?= e(pago_ordinal(count(array_filter($pagos,
                    fn($x) => (string)($x['tipo'] ?? 'cobro') === 'cobro')) + 1)) ?>.</span>
            </label>
          <?php endif; ?>
          <?php /* El voucher y, si el método la pide, la foto del DNI (3f). */ ?>
          <?php parte('pagos/foto_campo', ['campo' => 'voucher', 'titulo' => 'Voucher',
                'ayuda' => 'JPG, PNG o PDF, hasta 5 MB.', 'pend' => voucher_pendiente('pedido:' . (int)$p['id'])]); ?>
          <?php parte('pagos/foto_campo', ['campo' => 'foto_dni', 'titulo' => 'Foto del DNI de quien paga',
                'ayuda' => 'Este método de pago la pide.',
                /* Al volver de un intento (?metodo=), sale a la vista si ese
                   método la pide: sin JavaScript también se puede pagar. */
                'oculto' => !metodo_pide_dni((int)($_GET['metodo'] ?? 0)),
                'pend' => voucher_pendiente(voucher_contexto('pedido:' . (int)$p['id'], 'foto_dni'))]); ?>
          <div class="acciones" style="margin-top:10px">
            <button class="btn btn--negro" type="submit">REGISTRAR EL PAGO</button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <!-- Historia ──────────────────────────────────────────────── -->
    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Qué ha pasado</h2></div>
      <?php foreach ($eventos as $ev): ?>
        <div class="dato">
          <span class="dato__t"><?= e($ev['texto']) ?></span>
          <span class="dato__k"><?= e(fecha_hora((string)$ev['creado_en'])) ?>
            <?= $ev['nombre'] ? ' · ' . e(primer_nombre((string)$ev['nombre'])) : '' ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (!$eventos): ?><p class="mini">Sin movimientos todavía.</p><?php endif; ?>
    </div>
  </div>

  <!-- Columna derecha ───────────────────────────────────────────── -->
  <div style="display:flex;flex-direction:column;gap:12px">

    <?php /* EL COSTO DEL ENVÍO QUE QUEDÓ PENDIENTE (3j): la pre venta con
             envío con costo puede venderse sin él; se pone aquí o, como muy
             tarde, al mandarla a despacho. El estado ya no se cambia a mano:
             lo mueve el HUB. */ ?>
    <?php if ($puedo_tocar && !$anulado && pedido_flete_falta($p)): ?>
      <div class="tarjeta" id="flete">
        <div class="tarjeta__cab"><h2>Costo del envío</h2><span class="chip chip--ambar">Por poner</span></div>
        <p class="mini" style="margin:0 0 8px">Se vendió sin el costo del envío. Ponlo cuando lo sepas; si no, te lo pedimos al mandarlo a despacho.</p>
        <form method="post" action="<?= e(url('/pedidos/flete')) ?>" class="form">
          <?= campo_csrf() ?>
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <label>¿Cuánto se le cobra al cliente? (S/)
            <input type="text" name="flete" inputmode="decimal" required placeholder="0.00" autocomplete="off">
          </label>
          <div class="acciones"><button class="btn btn--amarillo" type="submit">PONER EL COSTO DEL ENVÍO</button></div>
        </form>
      </div>
    <?php endif; ?>

    <?php
      /* COMPROBANTE Y DESPACHO. Van juntos y en la ficha del pedido porque son
         las dos preguntas que se hacen sobre una venta cuando llama el
         cliente: «¿me emitieron la boleta?» y «¿ya salió?». Antes había que
         preguntarle a alguien por WhatsApp. */
      $comp     = (string)($p['comprobante_tipo'] ?? '');
      $num_comp = pedido_comprobante_numero($p);   // su función, no una copia
      $desp     = (int)($p['despacho_veces'] ?? 0);
    ?>
    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Comprobante y despacho</h2></div>
      <div class="dato">
        <span class="dato__k">Comprobante</span>
        <span class="dato__t">
          <?php if ($comp === 'ninguno'): ?>
            <span class="chip chip--gris">No lleva</span>
          <?php elseif ($comp !== ''): ?>
            <span class="chip chip--verde"><?= e(ucfirst($comp)) ?></span>
            <?= $num_comp !== '' ? ' ' . e($num_comp) : '<span class="mini"> · sin número</span>' ?>
                        <?php if (!empty($p['comprobante_archivo'])): ?>
              <a class="chip chip--linea" target="_blank" rel="noopener"
                 href="<?= e(url('/pedidos/comprobante-archivo?id=' . (int)$p['id'])) ?>">Ver</a>
            <?php endif; ?>
            <?php /* Los electrónicos, con su PDF de NUBEFACT (parche 2u): el
                     comprobante y, si la venta se anuló, su nota de crédito. */ ?>
            <?php foreach (nubefact_del_pedido((int)$p['id']) as $cpe_f): ?>
              <?php if (!empty($cpe_f['enlace_pdf'])): ?>
                <a class="chip chip--linea" target="_blank" rel="noopener"
                   href="<?= e((string)$cpe_f['enlace_pdf']) ?>"><?= (int)$cpe_f['tipo_cpe'] === 3 ? 'Nota de crédito' : 'PDF' ?></a>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="chip chip--ambar">Todavía no</span>
            <span class="mini">· lo emite facturación</span>
            <?php /* Y desde aquí se emite, para quien puede: a esta ficha se
                     llega también desde los reportes, y sin este enlace había
                     que volver a la bandeja a buscar la misma venta. */ ?>
            <?php if (puede('pagos.verificar') && !$anulado): ?>
              <a class="chip chip--linea" href="<?= e(url('/pedidos/facturar?id=' . (int)$p['id'])) ?>">
                Emitir comprobante</a>
            <?php endif; ?>
          <?php endif; ?>
        </span>
      </div>
      <?php $cuando_ent = pedido_entrega_texto($p); ?>
      <?php if ($cuando_ent !== ''): ?>
        <div class="dato">
          <span class="dato__k">Entregar</span>
          <span class="dato__t"><?= e($cuando_ent) ?></span>
        </div>
      <?php endif; ?>
      <div class="dato">
        <span class="dato__k">Despacho</span>
        <span class="dato__t">
          <?php if ($desp > 0): ?>
            <span class="chip chip--verde">Ya salió</span>
            <span class="mini">· <?= e(hace((string)$p['despacho_en'])) ?><?= $desp > 1 ? ' · ' . $desp . ' envíos' : '' ?></span>
          <?php else: ?>
            <span class="chip chip--gris">Sin mandar</span>
          <?php endif; ?>
        </span>
      </div>
      <?php /* LO QUE HIZO ALMACÉN (3g): quién lo alistó y la foto. */ ?>
      <?php /* Lo que salió antes de la 3g queda alistado sin foto: no se dice nada. */ ?>
      <?php if ($desp > 0 && alistado_listo() && $p['anulado_en'] === null
                && (empty($p['alistado_en']) || !empty($p['alistado_foto']))): ?>
        <div class="dato" id="dato-alistado">
          <span class="dato__k">Almacén</span>
          <span class="dato__t">
            <?php if (!empty($p['alistado_en'])): ?>
              <span class="chip chip--verde">Alistado</span>
              <span class="mini">· <?= e(pedido_alistado_detalle($p)) ?></span>
              <a class="chip chip--ver" target="_blank" rel="noopener" href="<?= e(url('/pedidos/alistado-foto?id=' . (int)$p['id'])) ?>">VER FOTO</a>
            <?php else: ?>
              <span class="chip chip--ambar">Por alistar</span>
            <?php endif; ?>
          </span>
        </div>
      <?php endif; ?>
      <?php /* LA ENTREGA (3j): la marca Almacén con la foto. */ ?>
      <?php if ($desp > 0 && entrega_lista() && $p['anulado_en'] === null): ?>
        <div class="dato" id="dato-entrega">
          <span class="dato__k">Entrega</span>
          <span class="dato__t">
            <?php if (!empty($p['entregado_en'])): ?>
              <span class="chip chip--verde">Entregado</span>
              <span class="mini">· <?= e(hace((string)$p['entregado_en'])) ?></span>
              <?php if (!empty($p['entregado_foto'])): ?>
                <a class="chip chip--ver" target="_blank" rel="noopener" href="<?= e(url('/pedidos/entrega-foto?id=' . (int)$p['id'])) ?>">VER FOTO</a>
              <?php endif; ?>
            <?php else: ?>
              <span class="chip chip--ambar">En camino al cliente</span>
            <?php endif; ?>
          </span>
        </div>
      <?php endif; ?>
      <?php if ($puedo_despachar && (string)$p['estado'] !== 'anulado' && $bloqueo_desp === ''): ?>
        <div class="acciones" style="margin-top:10px">
                    <?php /* RESALTADO cuando toca hacerlo (usuario, 2026-09-21): es la
                   acción que el asesor viene a buscar a esta ficha. Una vez
                   mandado, vuelve a ser un botón normal. */ ?>
          <?php if ($desp > 0 || !puedo_editar((int)$p['asesor_id'], (int)$p['pais_id'])): ?>
          <a class="btn btn--linea" href="<?= e(url('/pedidos/despacho?id=' . (int)$p['id'])) ?>">VER EL DESPACHO</a>
          <?php else: ?>
          <?php parte('pedidos/boton_despacho', ['p' => $p, 'clase' => 'btn btn--amarillo', 'html' => 'MANDAR A DESPACHO', 'volver' => 'ficha']); ?>
          <?php endif; ?>
          <a class="btn btn--linea" href="<?= e(url('/pedidos/rotulo?id=' . (int)$p['id'])) ?>">RÓTULO (PDF)</a>
        </div>
      <?php elseif ((string)$p['estado'] === 'anulado'): ?>
        <?php /* EL ANULADO SE PREGUNTA POR SÍ MISMO, no por descarte. Estaba
                 en el `else`, que recogía TAMBIÉN a quien no puede despachar:
                 a Facturación y a Dirección la ficha de un pedido vivo les
                 decía «Este pedido está anulado», y encima dentro de la misma
                 tarjeta donde leen «Comprobante: todavía no · lo anota
                 facturación». Justo la pantalla que van a usar todo el día. */ ?>
        <p class="mini" style="margin:10px 0 0">Este pedido está anulado: no se despacha
          ni lleva comprobante.</p>
      <?php elseif ($puedo_despachar): ?>
        <?php /* Se dice el motivo con todas las letras, no solo que no se puede:
                 quien lee esto es el asesor que acaba de vender y quiere saber
                 qué le falta, y la respuesta es «que facturación confirme». */ ?>
        <div class="aviso aviso--gris" style="margin-top:10px">
          <span><?= ico('reloj',17) ?></span>
          <span><strong>Todavía no sale a despacho.</strong> <?= e($bloqueo_desp) ?></span>
        </div>
      <?php endif; ?>
    </div>

    <?php /* LOS DATOS DEL CLIENTE, SIN SALIR DEL PEDIDO.
             La ficha solo pintaba su NOMBRE, enlazado: para ver el celular
             había que salir del pedido, entrar al cliente y volver. Lo
             encontró el usuario el 2026-09-11. Todo esto ya venía en la
             consulta —pedido_de() trae documento, celular y correo—, solo que
             no se pintaba. */ ?>
    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Cliente</h2>
        <a class="mini" href="<?= e(url('/clientes/ficha?id=' . (int)$p['cliente_id'])) ?>">Ver su ficha</a>
      </div>
      <div class="dato"><span class="dato__k"><?= e($cliente) ?></span></div>
      <?php if ($p['cliente_documento']): ?>
        <div class="dato"><span class="dato__k"><?= e((string)$p['cliente_tipo_doc']) ?></span>
          <span class="dato__t"><?= e((string)$p['cliente_documento']) ?></span></div>
      <?php endif; ?>
      <?php if ($p['cliente_celular']): ?>
        <div class="dato"><span class="dato__k">Celular</span>
          <span class="dato__t"><?= e((string)$p['cliente_celular']) ?>
            <button type="button" class="chip chip--linea" data-copiar-valor="<?= e((string)$p['cliente_celular']) ?>"
                    style="margin-left:6px">Copiar</button></span></div>
      <?php endif; ?>
      <?php if ($p['cliente_email']): ?>
        <div class="dato"><span class="dato__k">Correo</span>
          <span class="dato__t"><?= e((string)$p['cliente_email']) ?></span></div>
      <?php endif; ?>
      <?php /* Lo que el cliente PIDIÓ, que no es lo que se emitió: eso está en
               la tarjeta del comprobante y lo decide facturación.
               Y SE PUEDE PEDIR DESDE AQUÍ. Los dos sitios que pidió el usuario
               son la ventana de «Pedido registrado» y el menú ⋮ de la lista;
               esto es la misma ficha donde ya se enseñaba el dato, y es donde
               está mirando el asesor cuando el cliente le llama para decirle
               que al final quiere factura. Va como una línea más de la tarjeta
               del cliente, no como una tarjeta nueva: no es una decisión
               grande, es un aviso. */ ?>
      <?php $pide = comprobante_pide_texto($p['comprobante_pide'] ?? null);
            /* El PERMISO que exige la ruta, además del ámbito de $puedo_tocar. */
            $puedo_pedir_aqui = $puedo_tocar && !$anulado && puede('pedidos.editar')
                                && columna_existe('pedidos', 'comprobante_pide')
                                && trim((string)($p['comprobante_tipo'] ?? '')) === ''; ?>
      <?php if ($pide !== ''): ?>
        <div class="dato"><span class="dato__k">Pidió</span>
          <span class="dato__t"><?= e($pide) ?>
            <?php if (!empty($p['comprobante_pide_en'])): ?>
              <span class="mini">· <?= e(hace((string)$p['comprobante_pide_en'])) ?></span>
            <?php endif; ?></span></div>
      <?php endif; ?>
      <?php if ($puedo_pedir_aqui): ?>
        <div class="pide-fila">
          <?php foreach (comprobantes_que_se_solicitan() as $sc_k => $sc): ?>
            <?php if ($pide !== '' && (string)$p['comprobante_pide'] === $sc_k) continue; ?>
            <form method="post" action="<?= e(url('/pedidos/solicitar-comprobante')) ?>">
              <?= campo_csrf() ?>
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="tipo" value="<?= e($sc_k) ?>">
                            <?php /* Resaltados mientras no pidió nada; para cambiar lo ya
                       pedido basta un botón normal. */ ?>
              <button class="btn <?= $pide === '' ? 'btn--amarillo' : 'btn--linea' ?>" type="submit"><?= e($pide === ''
                ? $sc['menu'] : 'Cambiar a ' . mb_strtolower($sc['nombre'])) ?></button>
            </form>
          <?php endforeach; ?>
          <?php if ($pide !== ''): ?>
            <form method="post" action="<?= e(url('/pedidos/solicitar-comprobante')) ?>">
              <?= campo_csrf() ?>
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <input type="hidden" name="tipo" value="">
              <button class="btn btn--linea" type="submit">Retirar</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Entrega</h2></div>
      <?php /* La garantía es lo primero que pregunta el cliente cuando algo
               falla, y hasta ahora no estaba escrita en ningún sitio: se
               pactaba por WhatsApp. Los pedidos de antes de esta versión no
               la tienen, y entonces la fila no sale: inventarles doce meses
               sería peor que no decir nada. */ ?>
      <?php $garantia_txt = pedido_garantia_texto($p); if ($garantia_txt !== '' && empty($vigencia)): ?>
        <div class="dato"><span class="dato__k">Garantía</span>
          <span class="dato__t"><?= e($garantia_txt) ?></span></div>
      <?php endif; ?>
      <div class="dato"><span class="dato__k">Cómo</span>
        <span class="dato__t"><?= e(pedido_como_recibe($p)) ?></span></div>
      <?php if ($ubigeo): ?>
        <div class="dato"><span class="dato__k">Dónde</span><span class="dato__t"><?= e($ubigeo) ?></span></div>
      <?php endif; ?>
      <?php if ($p['direccion_txt']): ?>
        <div class="dato"><span class="dato__k">Dirección</span><span class="dato__t"><?= e((string)$p['direccion_txt']) ?></span></div>
      <?php endif; ?>
      <?php if ($p['referencia_txt']): ?>
        <div class="dato"><span class="dato__k">Referencia</span><span class="dato__t"><?= e((string)$p['referencia_txt']) ?></span></div>
      <?php endif; ?>
      <?php /* El mapa. Se pedía en el formulario y no salía en ninguna
               pantalla ni en el mensaje a despacho: el enlace se escribía y se
               perdía. Se abre en otra pestaña, que es lo que hace quien va a
               entregar. */ ?>
      <?php if (!empty($p['gps'])): ?>
        <div class="dato"><span class="dato__k">Mapa</span>
          <span class="dato__t"><a href="<?= e((string)$p['gps']) ?>" target="_blank" rel="noopener nofollow">Abrir en Maps</a></span></div>
      <?php endif; ?>
      <?php /* Solo en un envío: los pedidos guardados por la versión anterior
               podían llevar una agencia pegada a un recojo en oficina. */ ?>
      <?php $agencia_txt = (string)$p['entrega'] === 'envio' ? pedido_agencia_texto($p) : '';
            if ($agencia_txt !== ''): ?>
        <div class="dato"><span class="dato__k">Agencia</span>
          <span class="dato__t"><?= e($agencia_txt) ?>
            <?= $p['sucursal'] ? ' · ' . e((string)$p['sucursal']) : '' ?></span></div>
      <?php endif; ?>
      <?php if ($p['guia']): ?>
        <div class="dato"><span class="dato__k">Guía</span><span class="dato__t"><?= e((string)$p['guia']) ?></span></div>
      <?php endif; ?>
      <?php if ((int)$p['envio_armado'] === 1): ?>
        <div class="dato"><span class="dato__k">Nota de despacho</span><span class="dato__t">Enviar armado</span></div>
      <?php endif; ?>
      <div class="dato"><span class="dato__k">Canal de venta</span>
        <span class="dato__t"><?= e(lista_texto($p['canal_item_id'] ? (int)$p['canal_item_id'] : null) ?: '—') ?></span></div>
      <?php if ((int)$p['comision_centimos'] > 0): ?>
        <div class="dato"><span class="dato__k">Comisión de pasarela</span>
          <span class="dato__t"><?= e(soles((int)$p['comision_centimos'])) ?>
            <span class="mini">· costo de Waka, fuera del total</span></span></div>
      <?php endif; ?>
      <?php if ($p['nota']): ?>
        <div class="dato"><span class="dato__k">Nota</span><span class="dato__t"><?= e((string)$p['nota']) ?></span></div>
      <?php endif; ?>
    </div>

    <?php /* LA GARANTÍA (3i): hasta cuándo vale y las que ya se pidieron
             («a esta máquina ya le cambiamos el joystick dos veces»). */ ?>
    <?php if (!empty($vigencia)): ?>
      <div class="tarjeta" id="tarjeta-garantia">
        <div class="tarjeta__cab"><h2>Garantía</h2>
          <span class="chip chip--<?= e(garantia_vigencia_tono($vigencia['estado'])) ?>" id="vigencia"><?= e($vigencia['texto']) ?></span></div>
        <?php if ($garantia_txt !== ''): ?><div class="dato"><span class="dato__k">Prometida</span><span class="dato__t"><?= e($garantia_txt) ?></span></div><?php endif; ?>
        <?php if ($vigencia['detalle'] !== ''): ?><p class="mini" style="margin:0 0 8px"><?= e($vigencia['detalle']) ?></p><?php endif; ?>
        <?php $gar_ver = puede('garantias.ver'); ?>
        <?php foreach ($garantias_ped as $gp): $tag_g = $gar_ver ? 'a' : 'div'; ?>
          <<?= $tag_g ?> class="fila"<?= $gar_ver ? ' href="' . e(url('/garantias/ver?id=' . (int)$gp['id'])) . '"' : '' ?>>
            <span class="fila__crece"><span class="fila__t"><?= e(garantia_codigo((int)$gp['id'])) ?> · <?= e(garantia_piezas_texto($gp['lineas'])) ?></span>
              <span class="fila__s"><?= e(fecha_corta((string)$gp['pedida_en'])) ?></span></span>
            <span class="chip chip--<?= $gp['estado'] === 'aprobada' ? 'verde' : ($gp['estado'] === 'pedida' ? 'ambar' : ($gp['estado'] === 'denegada' ? 'rojo' : 'gris')) ?>"><?= e(garantia_estados()[$gp['estado']] ?? '') ?></span>
          </<?= $tag_g ?>>
        <?php endforeach; ?>
        <?php if (($gar_no ?? 'no') === ''): ?>
          <div class="acciones" style="margin-top:10px">
            <a class="btn btn--linea btn--ancho" href="<?= e(url('/garantias/pedir?pedido=' . (int)$p['id'])) ?>" id="btn-pedir-garantia"><?= ico('escudo',16) ?> PEDIR GARANTÍA</a>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="tarjeta">
            <div class="tarjeta__cab"><h2>Cashback del cliente</h2></div>
      <?php /* LA CIFRA, GRANDE (usuario, 2026-09-21): es lo que el asesor le
               dice al cliente para cerrar la siguiente venta. */ ?>
      <div class="cashback-cifra">
        <span class="cashback-cifra__v"><?= e(soles($cashback)) ?></span>
        <span class="cashback-cifra__k">disponible para su próxima compra</span>
      </div>
      <p class="mini" style="margin:0">
        <?php if (!$da_cashback): ?>
          Esta venta no genera cashback ni se puede pagar con él.
        <?php endif; ?>
        <?php if ((int)$p['cashback_usado_centimos'] > 0): ?>
          En este pedido usó <?= e(soles((int)$p['cashback_usado_centimos'])) ?>.
        <?php endif; ?>
      </p>
    </div>

    <?php if ($puedo_tocar && !$anulado && puede('pedidos.anular')): ?>
      <?php /* Con su ancla: el menú de la lista enlaza a «#anular» y sin esto
               dejaba a la persona arriba del todo, con la tarjeta al final de
               la otra columna. */ ?>
      <div class="tarjeta" id="anular">
        <div class="tarjeta__cab"><h2>Anular el pedido</h2></div>
        <form method="post" action="<?= e(url('/pedidos/anular')) ?>" class="form">
          <?= campo_csrf() ?>
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <label>Motivo
            <select name="motivo_item_id" required>
              <option value="">Elige el motivo</option>
              <?php foreach ($motivos as $m): ?>
                <option value="<?= (int)$m['id'] ?>"><?= e($m['valor']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Detalle
            <input type="text" name="nota" placeholder="Opcional, pero ayuda al que lo lea después">
          </label>
          <p class="mini" style="margin:8px 0">
            Se revierte <strong>lo cobrado</strong> (hoy <?= e(soles((int)$p['cobrado_centimos'])) ?>),
            no el total. Se quita el cashback que ganó y vuelve a su cuenta el que usó.
          </p>
          <button class="btn btn--rojo btn--ancho" type="submit"
                  data-confirmar="Anular este pedido. Se revierte lo cobrado y el cashback de cada pago. ¿Seguimos?">
            ANULAR</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>
