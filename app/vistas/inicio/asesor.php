<?php /** Inicio del asesor: su día. */ ?>
<header class="cabecera">
  <div>
    <div class="cabecera__sub"><?= e($fecha) ?></div>
    <h1><?= e($saludo) ?></h1>
    <?php if ($frase): ?><div class="cabecera__sub"><?= e($frase) ?></div><?php endif; ?>
  </div>
  <div class="cabecera__acciones">
    <?php /* LA RACHA, junto al saludo (5a): «x4 · 5 días · ver bonos». Tocarla lleva a Bonos. */ ?>
    <a class="chip racha-chip <?= (int)$racha_hoy >= 4 ? 'chip--negro' : ((int)$racha_hoy >= 2 ? 'chip--marca' : 'chip--linea') ?>" href="<?= e(url('/bonos')) ?>" id="chip-racha">
      <?= ico('rayo', 13) ?> x<?= (int)$racha_hoy ?> · <?= plural((int)$racha['dias'], 'día', 'días') ?> · ver bonos</a>
    <?php /* Llevaba a la LISTA de pedidos, no a crear uno: el botón dominante del
             Inicio obligaba a un clic de más en la acción que más se repite del
             día. Lo reportó el usuario el 2026-09-10. */ ?>
    <a class="btn btn--amarillo" href="<?= e(url('/pedidos/nuevo')) ?>"><?= ico('mas', 16) ?> NUEVO PEDIDO</a>
  </div>
</header>

<div class="rejilla rejilla--panel">
  <div>
    <!-- La meta se mide por dinero COBRADO, no por lo vendido -->
    <div class="tarjeta" style="margin-bottom:12px">
      <div class="tarjeta__cab">
        <h2>Tu meta de <?= e(strtolower(mes_actual())) ?></h2>
        <span class="chip <?= $avance >= 100 ? 'chip--verde' : 'chip--gris' ?>"><?= (int)$avance ?>%</span>
      </div>
      <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:10px">
        <span style="font-size:30px;font-weight:800;letter-spacing:-1px;font-variant-numeric:tabular-nums"><?= e(soles($cobrado)) ?></span>
        <span class="mini">cobrado de <?= e(soles($meta)) ?></span>
      </div>
      <?php
        /* La franja clara detrás de la barra es lo registrado y todavía sin
           confirmar. Se dibuja pero NO cuenta: sin ella, un asesor que acaba de
           cobrar ve su barra quieta y lo primero que piensa es que el HUB no le
           contó la venta. Verlo con su nombre convierte un fallo aparente en
           una espera que se entiende. */
        $pendiente = (int)($pendiente ?? 0);
        $an_pend = $meta > 0 ? min(100, (int) round(($cobrado + $pendiente) * 100 / $meta)) : 0;
      ?>
      <div class="pista">
        <?php if ($pendiente > 0): ?>
          <div class="pista__espera" style="width:<?= $an_pend ?>%"></div>
        <?php endif; ?>
        <div class="pista__llena marca" style="width:<?= (int)$avance ?>%"></div>
      </div>
      <p class="mini" style="margin:9px 0 0">
        <?php if ($pendiente > 0): ?>
          <strong><?= e(soles($pendiente)) ?> esperando confirmación.</strong>
          Ya está registrado; suma a tu meta en cuanto facturación lo confirme.<br>
        <?php endif; ?>
        <?php if ($avance >= 100): ?>
          Meta cumplida. Lo que entre desde ahora ya es para el siguiente escalón.
        <?php else: ?>
          Te faltan <?= e(soles($meta - $cobrado)) ?>. Cuenta el dinero que entró, no lo vendido.
        <?php endif; ?>
      </p>
    </div>

    <?php /* El otro lado del freno: si el HUB no deja mandar a despacho hasta
             que facturación confirme, tiene que ser el HUB quien avise cuando
             ya se puede. Sin esto, el asesor choca una vez contra el freno y no
             se entera nunca de que quedó libre. */ ?>
        <?php /* EL SALDO QUE FALTA REGISTRAR (usuario, 2026-09-21). La venta ya se
             entregó y el cobro del repartidor no está en el HUB: no suma a su
             meta hasta que suba el voucher. Con la lista, no solo el número. */ ?>
    <?php if (!empty($saldos)): ?>
      <div class="aviso aviso--amarillo" style="margin-bottom:12px">
        <span><?= ico('tarjeta',17) ?></span>
                <span><strong>Registra el cobro de
            <?= e(plural((int)($saldos_n ?? count($saldos)), 'venta enviada', 'ventas enviadas')) ?>.</strong>
          Sube el voucher del saldo: suma a tu meta cuando facturación lo confirme.
          <ul class="trabados">
                        <?php foreach ($saldos as $sd): ?>
              <li><a href="<?= e(url('/pedidos/ficha?id=' . (int)$sd['id'])) ?>"><?= e($sd['codigo']) ?></a>
                · faltan <?= e(soles((int)$sd['falta'])) ?></li>
            <?php endforeach; ?>
          </ul>
          <?php if ((int)($saldos_n ?? 0) > count($saldos)): ?>
            <a href="<?= e(url('/pedidos?v=porregistrar')) ?>" style="text-decoration:underline">Ver
              las <?= (int)$saldos_n ?></a>
          <?php endif; ?></span>
      </div>
    <?php endif; ?>

    <?php if (!empty($por_despachar)): ?>
      <div class="aviso aviso--amarillo" style="margin-bottom:12px">
        <span><?= ico('camion',17) ?></span>
        <span><strong>Pago confirmado ·
          <?= e(plural((int)$por_despachar, 'venta lista para despacho', 'ventas listas para despacho')) ?>.</strong>
          Mándalas y Almacén las ve en «Por alistar».
          <a href="<?= e(url('/pedidos/por-despachar')) ?>" style="text-decoration:underline">Verlas</a>.</span>
      </div>
    <?php endif; ?>

    <?php /* La contrapartida del otro freno: facturación puede dejar un pago en
             espera o denegarlo, y hasta ahora el asesor no se enteraba nunca.
             La auditoría 14 ya lo había anotado —un texto prometía que «el
             asesor recibirá el motivo» y ese aviso no existía— y se quedó
             escrito sin hacer. Lo volvió a pedir el usuario probando.
             Va con el MOTIVO delante: un aviso que dice «hay un problema» sin
             decir cuál obliga a entrar a buscarlo, y entonces no es un aviso. */ ?>
    <?php if (!empty($trabados)): ?>
      <div class="aviso aviso--rojo" style="margin-bottom:12px">
        <span><?= ico('tarjeta',17) ?></span>
        <span>
          <strong>Facturación paró
            <?= e(plural(count($trabados), 'pago tuyo', 'pagos tuyos')) ?>.</strong>
          <ul class="trabados">
            <?php foreach ($trabados as $t): ?>
              <li>
                <a href="<?= e(url('/pedidos/ficha?id=' . (int)$t['pedido_id'])) ?>"><?= e($t['codigo']) ?></a>
                · <?= e(soles((int)$t['monto_centimos'])) ?>
                · <b><?= (int)$t['denegado'] === 1 ? 'Denegado' : 'En espera' ?></b>
                <?php /* El motivo, venga de donde venga: la denegación lo escribe
                         en anulado_motivo y la espera en espera_nota. Sin esto,
                         un pago denegado salía sin una palabra de por qué. */ ?>
                <?php $t_mot = trim((string)((int)$t['denegado'] === 1
                                             ? $t['anulado_motivo'] : $t['espera_nota'])); ?>
                <?php if ($t_mot !== ''): ?>
                  <span class="trabados__n">«<?= e($t_mot) ?>»</span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </span>
      </div>
    <?php endif; ?>

    <?php /* SUS GARANTÍAS RESUELTAS esta semana (3i): lo que le dirá al cliente. */ ?>
    <?php if (!empty($garantias_res)): ?>
      <div class="aviso aviso--gris" style="margin-bottom:12px" id="aviso-garantias">
        <span><?= ico('escudo',17) ?></span>
        <span><strong>Tus garantías</strong>
          <ul class="trabados">
            <?php foreach ($garantias_res as $gr): ?>
              <li><a href="<?= e(url('/garantias/ver?id=' . (int)$gr['id'])) ?>"><?= e(garantia_codigo((int)$gr['id'])) ?></a>
                · <?= e((string)$gr['cliente_nombre']) ?>
                · <b><?= $gr['estado'] === 'aprobada' ? 'Aprobada: sale del almacén' : 'No aprobada' ?></b>
                <?php if ($gr['estado'] === 'denegada' && trim((string)$gr['nota']) !== ''): ?>
                  <span class="trabados__n">«<?= e((string)$gr['nota']) ?>»</span><?php endif; ?></li>
            <?php endforeach; ?>
          </ul></span>
      </div>
    <?php endif; ?>

    <div class="rejilla rejilla--3" style="margin-bottom:12px">
      <div class="cifra">
        <span class="cifra__k">Por cobrar</span>
        <span class="cifra__v ambar"><?= e(soles_corto($por_cobrar)) ?></span>
        <span class="cifra__d">de tus pedidos abiertos</span>
      </div>
      <div class="cifra">
        <span class="cifra__k">Pedidos de hoy</span>
        <span class="cifra__v"><?= (int)$pedidos_hoy ?></span>
        <span class="cifra__d"><?= $pedidos_hoy ? 'buen ritmo' : 'todavía ninguno' ?></span>
      </div>
      <div class="cifra">
        <span class="cifra__k">Tu cartera</span>
        <span class="cifra__v"><?= (int)$clientes ?></span>
        <span class="cifra__d"><?= plural((int)$clientes, 'cliente', 'clientes', false) ?></span>
      </div>
    </div>

    <div class="tarjeta">
      <div class="tarjeta__cab">
        <h2><a href="<?= e(url('/equipo')) ?>" class="enlace-toque" style="color:inherit" id="lobos-a-team"><?= e($lobos_titulo) ?></a></h2>
        <span class="mini"><?= $mi_puesto['puesto'] ? 'Vas ' . $mi_puesto['puesto'] . '.º de ' . $mi_puesto['de'] : 'Aún sin puesto' ?></span>
      </div>
      <?php $hay_ventas = $podio && (int)($podio[0]['cobrado'] ?? 0) > 0; ?>
      <?php if (!$hay_ventas): ?>
        <?php parte('inicio/vacio', ['ico'=>'01','titulo'=>'Todavía no hay ventas este mes',
              'texto'=>'En cuanto se registre la primera venta, el podio aparece solo.',
              'boton'=>'REGISTRAR UN PEDIDO','ruta'=>'/pedidos','marca'=>true]); ?>
      <?php else: ?>
        <?php $tope = max(1, (int)($podio[0]['cobrado'] ?? 1)); ?>
        <?php foreach ($podio as $i => $p): $yo = (int)$p['id'] === (int)$usuario['id']; ?>
          <div class="fila">
            <span class="mini" style="width:22px;text-align:right"><?= $i+1 ?>.º</span>
            <?= avatar($p, 32, $yo) ?>
            <div class="fila__crece">
              <div class="fila__t" style="<?= $yo ? 'font-weight:800' : '' ?>">
                <?= e(primer_nombre($p['nombre'])) ?><?= $yo ? ' · tú' : '' ?>
              </div>
              <div class="pista pista--fina" style="margin-top:5px">
                <div class="pista__llena <?= $yo ? 'marca' : '' ?>" style="width:<?= (int)round(((int)$p['cobrado'])*100/$tope) ?>%"></div>
              </div>
            </div>
            <span class="fila__t num" style="flex-shrink:0"><?= e(soles_corto((int)$p['cobrado'])) ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <?php if ($linea): ?>
      <div class="tarjeta" style="margin-bottom:12px">
        <div class="tarjeta__cab"><h2>Lo que te espera</h2></div>
        <p style="margin:0;font-size:13px;color:var(--tx-2);line-height:1.55"><?= e($linea) ?></p>
      </div>
    <?php endif; ?>

    <?php /* TU RACHA (5a): la del día (clientes distintos de hoy) y los días
             seguidos. No multiplica dinero: es reconocimiento. */ ?>
    <a class="tarjeta tarjeta--enlace" style="margin-bottom:12px;display:block;color:inherit" href="<?= e(url('/bonos/rachas')) ?>" id="tarjeta-racha">
      <div class="tarjeta__cab"><h2>Tu racha</h2>
        <?php $rn = racha_nombre((int)$racha_hoy); if ($rn !== ''): ?><span class="chip <?= (int)$racha_hoy >= 4 ? 'chip--negro' : 'chip--marca' ?>">x<?= (int)$racha_hoy ?> · <?= e($rn) ?></span><?php endif; ?></div>
      <div class="dato"><span class="dato__k">Hoy</span><span class="dato__t"><?= plural((int)$racha_hoy, 'cliente distinto', 'clientes distintos') ?></span></div>
      <div class="dato"><span class="dato__k">Días seguidos</span><span class="dato__t"><?= (int)$racha['dias'] ?> · tu mejor: <?= (int)$racha['mejor'] ?></span></div>
    </a>

    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Atajos</h2></div>
      <a class="fila" href="<?= e(url('/clientes')) ?>"><?= ico('personas') ?><span class="fila__crece fila__t">Registrar un cliente</span><?= ico('flecha',14) ?></a>
      <a class="fila" href="<?= e(url('/recepcion')) ?>"><?= ico('puerta') ?><span class="fila__crece fila__t">Avisar que llegó un cliente</span><?= ico('flecha',14) ?></a>
      <a class="fila" href="<?= e(url('/reportar-error')) ?>"><?= ico('alerta') ?><span class="fila__crece fila__t">Reportar un error</span><?= ico('flecha',14) ?></a>
    </div>
  </div>
</div>

<?php
function mes_actual(): string {
    $m = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','setiembre','octubre','noviembre','diciembre'];
    return $m[(int)date('n') - 1];
}
?>

<?php parte('bonos/novedades', ['novedades' => $novedades ?? [], 'wakaciones' => $wakaciones ?? null, 'montos_bloqueado' => $montos_bloqueado ?? false]); ?>
