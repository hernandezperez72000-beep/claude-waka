<?php
/**
 * Cada dato con su botón. El valor viaja en un atributo y no dentro del botón,
 * para poder copiar exactamente lo que hay que pegar —sin el rótulo, sin
 * espacios de más y sin el "S/" cuando es un importe que va a un campo numérico.
 */
$campo = function (string $rotulo, string $valor, string $nota = '') {
    if (trim($valor) === '') return;
    echo '<div class="campo">';
    echo   '<span class="campo__k">' . e($rotulo) . '</span>';
    echo   '<span class="campo__v">' . e($valor);
    if ($nota !== '') echo ' <span class="mini">' . e($nota) . '</span>';
    echo   '</span>';
    echo   '<button type="button" class="campo__b" data-copiar-valor="' . e($valor)
         . '" title="Copiar">Copiar</button>';
    echo '</div>';
};

$pendientes = array_filter($pagos, fn($x) => (int)$x['anulado'] === 0
                                          && (int)$x['verificado'] === 0
                                          && (string)$x['tipo'] === 'cobro');
$nombre_cli = trim((string)$cliente['nombre'] . ' ' . (string)$cliente['apellidos']);
/* QUÉ SE VA A EMITIR — y sale de comprobante_marcado(), no de la ficha del
   cliente. Con la ficha, esta pantalla se contradecía a sí misma: a un cliente
   con RUC que había pedido BOLETA le ponía «[FACTURA]» en el chip más grande
   del título y le ofrecía para copiar el RUC y la razón social, mientras el
   aviso de al lado decía «el asesor pidió Boleta». Quien emite campo por campo
   en el sistema de facturación tenía delante los datos del comprobante que
   nadie pidió. Manda lo mismo que manda el radio de abajo, que es lo que se va
   a guardar. */
$es_factura = (trim((string)($p['comprobante_tipo'] ?? '')) !== ''
    ? (string)$p['comprobante_tipo'] : $marcado['tipo']) === 'factura';
?>
<header class="cabecera">
  <div>
    <div class="cabecera__sub"><a href="<?= e(url('/pagos/por-validar')) ?>">Pagos por validar</a></div>
    <h1>Facturar <?= e($p['codigo']) ?>
      <?php /* Y si ya está anotado «no se emite», el chip lo dice: poner
               «BOLETA» sobre una venta que se decidió sin comprobante es la
               misma mentira, al revés. */ ?>
      <?php $chip_comp = trim((string)($p['comprobante_tipo'] ?? '')) === 'ninguno'
              ? 'NO SE EMITE' : ($es_factura ? 'FACTURA' : 'BOLETA'); ?>
      <span class="chip chip--<?= $chip_comp === 'NO SE EMITE' ? 'gris'
                                  : ($es_factura ? 'ambar' : 'gris') ?>">
        <?= e($chip_comp) ?></span>
    </h1>
    <div class="cabecera__sub">
      <?= e($nombre_cli) ?> ·
      <?= e(fecha_corta((string)($p['fecha'] ?: $p['creado_en']))) ?> ·
      <?= e(trim((string)$p['asesor_nombre'] . ' ' . (string)$p['asesor_apellidos'])) ?>
    </div>
  </div>
  <div class="cabecera__acciones">
    <a class="btn btn--linea" href="<?= e(url('/pedidos/ficha?id=' . (int)$p['id'])) ?>">Ver el pedido</a>
  </div>
</header>

<?php /* La ventana de «qué acaba de pasar con el pago». La misma que sale en
         la bandeja y en la ficha, y desde aquí devuelve AQUÍ: esta es la única
         pantalla que tiene el formulario de serie y número. */ ?>
<?php parte('pagos/resultado', ['resultado' => $resultado ?? [],
                                'cerrar'    => url('/pedidos/facturar?id=' . (int)$p['id']),
                                'vuelve'    => 'facturar']); ?>

<?php /* LO QUE PIDIÓ EL ASESOR, ARRIBA Y CON SU NOMBRE. Esta pantalla es la
         única salida de la cola de «comprobantes solicitados», y no lo decía:
         facturación llegaba desde una fila que ponía «Boleta» y se encontraba
         la pantalla premarcada en FACTURA porque el cliente tiene RUC. Se dice
         qué se pidió, y se dice que decide ella. */ ?>
<?php /* El descuento que espera aprobación (3d): el total todavía puede
         cambiar, así que se dice arriba, con NUBEFACT o sin él. */ ?>
<?php if (pedido_descuento_pendiente($p)): ?>
  <div class="aviso aviso--amarillo" id="aviso-descuento-espera" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span>El descuento de esta venta espera que Administración lo apruebe: <strong>el total todavía puede
      cambiar</strong>. Espera su respuesta antes de emitir el comprobante.</span>
  </div>
<?php endif; ?>
<?php if ($marcado['porque'] === 'pide' && trim((string)($p['comprobante_tipo'] ?? '')) === ''): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span>El asesor pidió <strong><?= e(comprobante_pide_texto((string)$p['comprobante_pide'])) ?></strong>
      para esta venta<?php if (!empty($p['comprobante_pide_en'])): ?>,
      <?= e(hace((string)$p['comprobante_pide_en'])) ?><?php endif; ?>.
      Es lo que viene marcado abajo. <strong>Decides tú</strong>: si no cuadra con la ficha del
      cliente, cámbialo y emite lo que corresponda.</span>
  </div>
<?php endif; ?>

<div class="rejilla rejilla--panel">
  <div style="display:flex;flex-direction:column;gap:12px">

    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>A quién se le factura</h2></div>
      <?php /* LOS DATOS PARA COPIAR, PLEGADOS (usuario, 2026-09-22). Servían
               para tipearlos en el otro sistema de facturación; con NUBEFACT
               el HUB los manda solo. Se quedan para el día que haya que emitir
               fuera, pero ya no ocupan la primera pantalla. */ ?>
      <p class="mini" style="margin:0 0 8px"><strong><?= e($es_factura
          ? (trim((string)$cliente['razon_social']) !== '' ? (string)$cliente['razon_social'] : $nombre_cli)
          : $nombre_cli) ?></strong>
        · <?= e($es_factura
            ? (trim((string)$cliente['ruc_factura']) !== '' ? (string)$cliente['ruc_factura'] : (string)$cliente['documento'])
            : (string)$cliente['tipo_doc'] . ' ' . (string)$cliente['documento']) ?>
        · <?= e((string)$cliente['email']) ?></p>
      <details class="desplegable">
        <summary>Copiar los datos (si emites fuera)</summary>
      <?php
        /* Para una FACTURA lo que se escribe en Tumifactura es la razón social
           y el RUC; el nombre de pila del contacto no pinta nada ahí. Para una
           BOLETA es al revés. Se enseña lo de cada caso y no las dos cosas:
           salían el nombre tres veces y el RUC dos, y quien copia campo por
           campo acaba pegando el que no era. */
        if ($es_factura) {
            $ruc = trim((string)$cliente['ruc_factura']) !== ''
                 ? (string)$cliente['ruc_factura'] : (string)$cliente['documento'];
            $razon = trim((string)$cliente['razon_social']) !== ''
                   ? (string)$cliente['razon_social'] : $nombre_cli;
            $campo('RUC',          $ruc);
            $campo('Razón social', $razon);
        } else {
            $campo('Nombre completo', $nombre_cli);
            $campo('Nombres',   (string)$cliente['nombre']);
            $campo('Apellidos', (string)$cliente['apellidos']);
            $campo((string)$cliente['tipo_doc'], (string)$cliente['documento']);
        }
        $campo('Correo',  (string)$cliente['email']);
        $campo('Celular', (string)$cliente['celular']);
      ?>
      </details>
      <?php /* EL FRENO DEL RUC salta por lo que se va a emitir, no por lo que
               diga la ficha. Colgaba de la ficha, así que en el caso que más
               lo necesita —cliente con DNI al que el asesor le pidió FACTURA—
               no salía nunca: la pantalla venía premarcada en factura, guardar
               rebotaba con «este cliente no tiene RUC» y el texto que dice qué
               hacer no aparecía en ninguna parte. Callejón sin salida. */ ?>
      <?php if ($es_factura && trim((string)$cliente['ruc_factura']) === ''
                && (string)$cliente['tipo_doc'] !== 'RUC'): ?>
        <div class="aviso aviso--rojo" style="margin-top:10px">
          <span><?= ico('alerta',17) ?></span>
          <span>Va a emitirse una <strong>factura</strong> y este cliente no tiene RUC en su
            ficha. Pídeselo al asesor antes de emitir: una factura sin RUC no se puede rehacer,
            se anula. Si al final va con boleta, márcalo abajo.</span>
        </div>
      <?php endif; ?>
    </div>

    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Qué lleva</h2></div>
      <?php
        /* El tipo de venta y la garantía, arriba del todo: son lo que decide
           qué dice el comprobante, y quien factura no tiene por qué abrir la
           ficha para verlos. $campo() ya salta los valores vacíos. */
        $campo('Tipo de venta', tipo_venta_texto((string)$p['tipo']));
        $campo('Garantía',      pedido_garantia_texto($p));
      ?>
      <?php foreach ($lineas as $i => $l): ?>
        <div class="campo__grupo">
          <div class="campo__titulo">Producto <?= $i + 1 ?></div>
          <?php
            $campo('Descripción', (string)$l['descripcion']);
            if ($l['sku']) $campo('Código', (string)$l['sku']);
            $campo('Cantidad', (string)(int)$l['cantidad']);
            $campo('Precio unitario', soles((int)$l['precio_unit_centimos'], false),
                   '· sin el S/, listo para pegar');
            $campo('Importe', soles((int)$l['total_centimos'], false));
          ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ((string)$p['entrega'] === 'envio'): ?>
      <div class="tarjeta">
        <div class="tarjeta__cab"><h2>A dónde va</h2></div>
        <?php
          /* Lo primero, cómo lo recibe: la tarjeta se pintaba por «es un
             envío» y no por tener datos, así que en un recojo en agencia
             salía la cabecera con tres campos y ninguna dirección, sin decir
             por qué falta. */
          $campo('Cómo lo recibe', pedido_como_recibe($p));
          $campo('Dirección',  (string)$p['direccion_txt']);
          $campo('Referencia', (string)$p['referencia_txt']);
          $campo('Distrito',   (string)($ubi['distrito'] ?? ''));
          $campo('Provincia',  (string)($ubi['provincia'] ?? ''));
          $campo('Departamento', (string)($ubi['departamento'] ?? ''));
          $campo('Agencia',    (string)$agencia);
          $campo('Sucursal',   (string)$p['sucursal']);
          $campo('Recibe',     (string)$p['recibe']);
        ?>
        <?php /* 5b · LA GUÍA DE REMISIÓN de un envío a provincia: la emite
                 Almacén desde «Por entregar»; aquí se ve y se puede corregir. */ ?>
        <?php if (function_exists('guia_aplica') && guia_aplica($p)): $gr = guia_de_pedido((int)$p['id']); ?>
          <div class="acciones" style="margin-top:10px">
            <a class="btn <?= $gr ? 'btn--linea' : 'btn--negro' ?> btn--chico" href="<?= e(url('/pedidos/guia?id=' . (int)$p['id'])) ?>">
              <?= ico('doc', 14) ?> <?= $gr ? 'GUÍA ' . e($gr['serie'] . '-' . (int)$gr['numero']) : 'GUÍA DE REMISIÓN' ?></a>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php
      /* EL COMPROBANTE. Aparte del pago a propósito: son dos decisiones
         distintas y no siempre pasan a la vez — un adelanto de pre venta se
         confirma hoy y la factura se emite cuando llega el contenedor.
         Es UNO POR PEDIDO (decisión del usuario, 2026-09-09). */
      $comp     = (string)($p['comprobante_tipo'] ?? '');
      $ya_emit  = $comp !== '';
      /* De pedido_comprobante_numero(), que ya lo define: escrito a mano era la
         tercera copia de la misma frase. */
      $num_comp = pedido_comprobante_numero($p);
    ?>
    <div class="tarjeta">
      <div class="tarjeta__cab">
        <h2>El comprobante</h2>
        <?php if ($ya_emit): ?>
          <span class="chip chip--verde"><?= ico('check',13) ?> Anotado</span>
        <?php endif; ?>
      </div>

            <?php /* ── CON NUBEFACT (parche 2u) ─────────────────────────────
               Si hay uno electrónico vigente, se enseña con su PDF y no hay
               nada que corregir aquí: lo que corrige un comprobante
               electrónico es su nota de crédito, al anular la venta. */
            $cpe_vig = nubefact_vigente((int)$p['id']);
            $nf_activo = nubefact_activo(); ?>
      <?php if ($cpe_vig): ?>
        <div class="dato">
          <span class="dato__k">Se emitió</span>
          <span class="dato__t"><?= e(nubefact_nombre($cpe_vig)) ?>
            <?php if ((string)$cpe_vig['ambiente'] === 'prueba'): ?>
              <span class="chip chip--ambar">Prueba</span><?php endif; ?></span>
        </div>
        <?php if (!empty($cpe_vig['enlace_pdf'])): ?>
          <div class="acciones" style="margin-top:8px">
            <a class="btn btn--linea" target="_blank" rel="noopener"
               href="<?= e((string)$cpe_vig['enlace_pdf']) ?>">VER EL PDF</a>
          </div>
        <?php endif; ?>
        <p class="mini" style="margin:8px 0 0">Para cambiarlo, anula la venta: se emite su nota de crédito.</p>
      <?php elseif ($nf_activo && !$ya_emit): ?>
        <?php /* Cuándo se puede emitir lo dice pedido_emision_bloqueo(), la
                 misma función que usa el botón al pulsarlo (usuario,
                 2026-09-22: con el total cobrado, salvo contraentrega). */ ?>
        <?php $freno_emitir = pedido_emision_bloqueo($p); ?>
        <?php if ($freno_emitir !== ''): ?>
          <p class="mini" style="margin:0"><?= e($freno_emitir) ?></p>
        <?php else: ?>
          <p class="mini" style="margin:0 0 10px">
            <?= e(ucfirst($marcado['tipo'])) ?> marcada: <?= e($marcado['texto']) ?>.
            Le llega al cliente a su correo.</p>
          <div class="acciones">
            <?php /* 5b: EMITIR abre la vista previa, y desde ahí se manda. */ ?>
            <?php foreach (comprobantes_que_se_solicitan() as $tv => $tc): ?>
              <a class="btn <?= $marcado['tipo'] === $tv ? 'btn--amarillo' : 'btn--linea' ?>"
                 href="<?= e(url('/pedidos/emitir?id=' . (int)$p['id'] . '&tipo=' . $tv . '&volver=facturar')) ?>">
                EMITIR <?= e(mb_strtoupper($tc['nombre'])) ?></a>
            <?php endforeach; ?>
          </div>
          <?php if ($pend = nubefact_pendiente((int)$p['id'])): ?>
            <p class="mini" style="margin:10px 0 0"><strong>La <?= e(mb_strtolower(nubefact_nombre($pend))) ?>
              se mandó y no tuvo respuesta.</strong> Pulsa «Emitir <?= (int)$pend['tipo_cpe'] === 1 ? 'factura' : 'boleta' ?>»
              otra vez: se comprueba si salió, sin duplicarla.</p>
          <?php endif; ?>
        <?php endif; ?>
        <details class="desplegable" style="margin-top:12px">
          <summary>No lleva comprobante, o se emitió fuera</summary>
          <?php parte('pedidos/comprobante_form', ['p'=>$p, 'marcado'=>$marcado, 'comp'=>$comp]); ?>
        </details>
      <?php elseif ($ya_emit): ?>
        <div class="dato">
          <span class="dato__k"><?= $comp === 'ninguno' ? 'Esta venta' : 'Se emitió' ?></span>
          <span class="dato__t">
            <?= $comp === 'ninguno' ? 'no lleva comprobante' : e(ucfirst($comp)) ?>
            <?= $num_comp !== '' ? e($num_comp) : '' ?></span>
        </div>
        <?php if ($p['comprobante_en']): ?>
          <p class="mini" style="margin:6px 0 0">Anotado <?= e(hace((string)$p['comprobante_en'])) ?>.</p>
        <?php endif; ?>
        <details class="desplegable" style="margin-top:10px">
          <summary>Corregirlo</summary>
          <?php parte('pedidos/comprobante_form', ['p'=>$p, 'marcado'=>$marcado, 'comp'=>$comp]); ?>
        </details>
      <?php else: ?>
                <p class="mini" style="margin:0 0 10px">
          Emítelo en tu sistema de facturación y anótalo aquí, para que ninguna venta se quede sin comprobante.
        </p>
        <?php parte('pedidos/comprobante_form', ['p'=>$p, 'marcado'=>$marcado, 'comp'=>$comp]); ?>
      <?php endif; ?>
    </div>

  </div>

  <div style="display:flex;flex-direction:column;gap:12px">

    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Los importes</h2></div>
      <?php
        $campo('Subtotal', soles((int)$p['subtotal_centimos'], false));
        if ((int)$p['cashback_usado_centimos'] > 0) {
            $campo('Descuento Cashback', soles((int)$p['cashback_usado_centimos'], false));
        }
        /* El descuento del asesor (3d): va aparte del cashback, como en la
           boleta de NUBEFACT, donde los dos suman el descuento global. */
        if ((int)($p['descuento_centimos'] ?? 0) > 0) {
            $campo('Descuento', soles((int)$p['descuento_centimos'], false));
        }
        /* EL FLETE, cuando lo cobra Waka y por tanto va DENTRO del total. Sin
           esta línea, Subtotal y Total no cuadraban y quien copia a Tumifactura
           no sabía de dónde salía la diferencia. Sale de pedido_flete_dentro(),
           que es la misma función que decide si entra en el total. */
        $flete_fac = pedido_flete_dentro($p);
        if ($flete_fac > 0) $campo('Envío', soles($flete_fac, false));
        $campo('Total', soles((int)$p['total_centimos'], false));
        $campo('Base imponible', soles((int)$igv['base'], false));
        /* El porcentaje sale del ajuste, como el cálculo: con el 18 escrito a
           mano, cambiar el ajuste dejaba el rótulo mintiendo. */
        $campo('IGV (' . (int) ajuste('igv_porcentaje', 18) . '%)', soles((int)$igv['igv'], false));
        $campo('Código del pedido', (string)$p['codigo']);
        $campo('Fecha del pedido', fecha_corta((string)($p['fecha'] ?: $p['creado_en'])));
      ?>
      <p class="mini" style="margin:10px 0 0">
        Los precios ya incluyen IGV: base + IGV = total.
      </p>
    </div>

    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Confirmar el pago</h2></div>
      <?php if (!$pendientes): ?>
        <p class="mini" style="margin:0">No queda ningún pago por confirmar en este pedido.</p>
      <?php else: foreach ($pendientes as $pg): ?>
        <?php
          $mes_pago = date('Y-m', strtotime((string)$pg['fecha']));
          $otro_mes = $mes_pago !== date('Y-m');
        ?>
        <div class="campo__grupo">
          <div class="dato">
            <span class="dato__k">Monto</span>
            <span class="dato__t"><?= e(soles((int)$pg['monto_centimos'])) ?></span>
          </div>
          <div class="dato">
            <span class="dato__k">Método</span>
            <span class="dato__t"><?= e($pg['metodo'] ?: '—') ?></span>
          </div>
          <div class="dato">
            <span class="dato__k">Fecha del pago</span>
            <span class="dato__t"><?= e(fecha_corta((string)$pg['fecha'])) ?></span>
          </div>

          <?php if ($otro_mes): ?>
            <div class="aviso aviso--gris" style="margin:10px 0">
              <span><?= ico('reloj',17) ?></span>
              <span>Este pago es del <strong><?= e(fecha_corta((string)$pg['fecha'])) ?></strong>.
                Lo confirmas hoy, pero el dinero <strong>suma al mes de su fecha</strong>, no al de
                hoy. Manda la fecha en que el cliente pagó.</span>
            </div>
          <?php endif; ?>

          <div class="acciones" style="margin:10px 0">
            <?php if ($pg['voucher']): ?>
              <a class="btn btn--linea" target="_blank" rel="noopener"
                 href="<?= e(url('/pagos/voucher?id=' . (int)$pg['id'])) ?>">
                <?= ico('ojo',15) ?> Ver el voucher</a>
            <?php else: ?>
              <span class="chip chip--ambar">Sin voucher</span>
            <?php endif; ?>
            <?php /* La foto del DNI de quien pagó (3f). */ ?>
            <?php if (!empty($pg['foto_dni'])): ?>
              <a class="btn btn--linea" target="_blank" rel="noopener" data-ver-dni
                 href="<?= e(url('/pagos/voucher?que=dni&id=' . (int)$pg['id'])) ?>">
                <?= ico('ojo',15) ?> Ver el DNI</a>
            <?php endif; ?>
          </div>

          <?php if ((int)$pg['en_espera'] === 1): ?>
            <div class="aviso aviso--gris" style="margin:10px 0">
              <span><?= ico('reloj',17) ?></span>
              <span><strong>En espera</strong> desde <?= e(hace((string)$pg['espera_en'])) ?>:
                «<?= e((string)$pg['espera_nota']) ?>»</span>
            </div>
          <?php endif; ?>

          <form method="post" action="<?= e(url('/pagos/validar')) ?>" class="form">
            <?= campo_csrf() ?>
            <input type="hidden" name="id" value="<?= (int)$pg['id'] ?>">
            <input type="hidden" name="volver" value="facturar">
            <?php /* OBLIGATORIO CUANDO EL MÉTODO PIDE COMPROBANTE, que son seis
                     de los siete: la ayuda decía «Opcional» y pago_validar() lo
                     rechazaba, así que facturación pulsaba CONFIRMAR y le
                     rebotaba. La regla sale de metodo_pide_comprobante(), la
                     misma que aplica el servidor y la misma que usa la fila de
                     la bandeja: escrita a mano aquí, ya decía lo contrario. */ ?>
            <?php $op_obliga = metodo_pide_comprobante((int)$pg['metodo_item_id']); ?>
            <label>N.º de operación<?= $op_obliga ? ' *' : '' ?>
              <input type="text" name="operacion" maxlength="60"
                     value="<?= e((string)$pg['operacion']) ?>"
                     placeholder="Cópialo del extracto del banco">
              <span class="ayuda"><?= $op_obliga
                ? 'Hace falta para confirmar este pago: es lo que permite cuadrarlo con el banco.'
                : 'Opcional en efectivo: no hay extracto que cuadrar.' ?></span>
            </label>
            <button class="btn btn--negro btn--ancho" type="submit" style="margin-top:10px"
                    data-confirmar="Confirmar que este dinero entró. Desde ahora suma a la meta del asesor y le da cashback al cliente. ¿Seguimos?">
              CONFIRMAR EL PAGO</button>
          </form>

          <?php
            /* Las otras dos salidas. Van DEBAJO y sin color de acción: lo
               normal es confirmar, y un botón rojo del mismo tamaño al lado
               del negro invita a pulsarlo por error justo donde hay dinero. */
          ?>
          <details class="desplegable" style="margin-top:10px">
            <summary>No lo puedo confirmar todavía</summary>

            <form method="post" action="<?= e(url('/pagos/espera')) ?>" class="form" style="margin-top:10px">
              <?= campo_csrf() ?>
              <input type="hidden" name="id" value="<?= (int)$pg['id'] ?>">
              <input type="hidden" name="volver" value="facturar">
              <label>Dejarlo EN ESPERA · por qué
                <input type="text" name="nota" maxlength="200" required
                       placeholder="Interbancario, entra mañana">
                <span class="ayuda">Lo verá el asesor. Sale de «sin revisar» y no suma a nada
                  hasta que lo confirmes.</span>
              </label>
              <button class="btn btn--linea btn--ancho" type="submit" style="margin-top:8px">
                DEJAR EN ESPERA</button>
            </form>

            <form method="post" action="<?= e(url('/pagos/denegar')) ?>" class="form" style="margin-top:14px">
              <?= campo_csrf() ?>
              <input type="hidden" name="id" value="<?= (int)$pg['id'] ?>">
              <input type="hidden" name="volver" value="facturar">
              <label>DENEGAR el pago · motivo
                <input type="text" name="motivo" maxlength="200" required
                       placeholder="El voucher no aparece en el banco">
                <span class="ayuda">El pedido NO se anula: se queda con su saldo por cobrar.
                  Si el cliente manda el voucher bueno, el asesor lo registra otra vez.</span>
              </label>
              <button class="btn btn--rojo btn--ancho" type="submit" style="margin-top:8px"
                      data-confirmar="Denegar este pago. El asesor recibirá el motivo y el pedido volverá a quedar con su saldo por cobrar. ¿Seguimos?">
                DENEGAR EL PAGO</button>
            </form>
          </details>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="aviso aviso--gris">
      <span><?= ico('alerta',17) ?></span>
      <span><strong>Confirma solo si el dinero ya está en el banco.</strong> Desde ese momento suma a la
        meta del asesor y el cliente gana su cashback. Si no cuadra, usa <strong>No lo puedo confirmar todavía</strong>.</span>
    </div>
  </div>
</div>

<script>
/* Copiar campo por campo. El valor va en el atributo, así que se copia
   exactamente lo que hay que pegar: sin el rótulo y sin el "S/". */
document.addEventListener('click', function (ev) {
  var b = ev.target.closest('[data-copiar-valor]');
  if (!b) return;
  var texto = b.getAttribute('data-copiar-valor');
  var antes = b.textContent;
  function fin(m) { b.textContent = m; setTimeout(function () { b.textContent = antes; }, 1400); }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(texto).then(function () { fin('Copiado'); })
      .catch(function () { fin('Cópialo a mano'); });
  } else { fin('Cópialo a mano'); }
});
</script>
