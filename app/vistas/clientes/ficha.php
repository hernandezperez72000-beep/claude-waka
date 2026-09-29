<?php
$nombre = cliente_nombre($c);
$nuevo  = pedir('nuevo', 'get') === '1';
$tienda = TIENDA_ESTADOS[(string)$c['estado_tienda']] ?? 'Sin cuenta en la tienda';
?>
<header class="cabecera">
  <div>
    <div class="cabecera__sub"><a href="<?= e(url('/clientes')) ?>">Clientes</a></div>
    <h1><?= e($nombre) ?></h1>
    <div class="cabecera__sub">
      <?= e($c['tipo_doc']) ?> <?= e($c['documento']) ?>
      <?= $c['celular'] ? ' · ' . e($c['celular']) : '' ?>
      <?= $c['canal'] ? ' · llegó por ' . e($c['canal']) : '' ?>
    </div>
  </div>
  <div class="cabecera__acciones">
    <?php if ($puedo_editar): ?>
      <a class="btn btn--linea" href="<?= e(url('/clientes/editar?id=' . (int)$c['id'])) ?>">
        <?= ico('lapiz',15) ?> Editar</a>
    <?php endif; ?>
    <?php if ($puedo_venderle): ?>
      <a class="btn btn--negro" href="<?= e(url('/pedidos/nuevo?cliente=' . (int)$c['id'])) ?>">
        <?= ico('mas',16) ?> NUEVO PEDIDO</a>
    <?php endif; ?>
  </div>
</header>

<?php /* EL CLIENTE DE OTRO ASESOR (3j): se dice de quién es y qué hacer,
         en vez de dejar que choque con la venta. */ ?>
<?php if (!$puedo_venderle && puede('pedidos.crear') && $c['asesor_id'] !== null && (int)$c['asesor_id'] !== (int)(yo()['id'] ?? 0)): ?>
  <div class="aviso aviso--gris" style="margin-bottom:14px" id="aviso-otro-asesor">
    <span><?= ico('candado',17) ?></span>
    <span><strong>Este cliente es de <?= e(trim((string)$c['asesor_nombre'] . ' ' . (string)$c['asesor_apellidos']) ?: 'otro asesor') ?>.</strong>
      Para venderle, pide a Administración que lo pase a tu cartera.</span>
  </div>
<?php endif; ?>

<?php if ($nuevo): ?>
  <div class="aviso aviso--verde" style="margin-bottom:14px">
    <span><?= ico('check',17) ?></span>
    <span><strong>Ficha creada.</strong> Ya puedes registrarle un pedido.
      Su cuenta de compraenwaka queda en cola: se crea sola cuando esté puesto el
      enlace con la tienda, y si ya había comprado antes por la web se enlaza esa.</span>
  </div>
<?php endif; ?>

<div class="rejilla rejilla--panel">
  <div style="display:flex;flex-direction:column;gap:12px">

    <div class="rejilla rejilla--3">
      <div class="tarjeta cifra">
        <span class="cifra__k">Comprado</span>
        <span class="cifra__v"><?= e(soles_corto((int)$resumen['comprado'])) ?></span>
        <span class="cifra__d"><?= plural(count($pedidos), 'pedido', 'pedidos') ?></span>
      </div>
      <div class="tarjeta cifra">
        <span class="cifra__k">Saldo por cobrar</span>
        <span class="cifra__v"><?= e(soles_corto((int)$resumen['saldo'])) ?></span>
        <span class="cifra__d"><?= (int)$resumen['saldo'] > 0 ? 'pendiente' : 'al día' ?></span>
      </div>
      <div class="tarjeta cifra">
        <span class="cifra__k">Cashback Waka</span>
        <span class="cifra__v"><?= e(soles($cashback)) ?></span>
        <span class="cifra__d">
          <?php if ($por_vencer > 0): ?>
            <?= e(soles($por_vencer)) ?> vence en 30 días
          <?php elseif ($cashback < cashback_minimo()): ?>
            se usa desde <?= e(soles(cashback_minimo())) ?>
          <?php else: ?>
            para su próxima compra
          <?php endif; ?>
        </span>
      </div>
    </div>

    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Sus pedidos</h2></div>
      <?php if (!$pedidos): ?>
        <?php parte('inicio/vacio', $puedo_venderle ? [
          'ico' => 'PE', 'titulo' => 'Todavía no le has vendido nada',
          'texto' => 'Cuando le registres un pedido aparecerá aquí con su estado y su saldo.',
          'boton' => $puedo_venderle ? 'NUEVO PEDIDO' : '',
          'ruta'  => '/pedidos/nuevo?cliente=' . (int)$c['id'],
        ] : ['ico' => 'PE', 'titulo' => 'Todavía no ha comprado',
               'texto' => 'Cuando compre, sus pedidos aparecen aquí.']); ?>
      <?php else: ?>
        <div class="tabla__caja">
          <table class="tabla">
            <thead><tr><th>Pedido</th><th>Tipo</th><th>Estado</th>
              <th class="der">Total</th><th class="der">Saldo</th></tr></thead>
            <tbody>
            <?php foreach ($pedidos as $p): ?>
              <tr>
                <td class="principal">
                  <?php /* Marketing (3g) ve al cliente y sus compras, pero no abre pedidos. */ ?>
                  <?php if (puede('pedidos.ver')): ?>
                  <a href="<?= e(url('/pedidos/ficha?id=' . (int)$p['id'])) ?>" style="color:inherit">
                    <div class="fila__t"><?= e($p['codigo']) ?></div>
                    <div class="fila__s"><?= e(fecha_corta((string)($p['fecha'] ?: $p['creado_en']))) ?></div>
                  </a>
                  <?php else: ?>
                    <div class="fila__t"><?= e($p['codigo']) ?></div>
                    <div class="fila__s"><?= e(fecha_corta((string)($p['fecha'] ?: $p['creado_en']))) ?></div>
                  <?php endif; ?>
                </td>
                <td data-k="Tipo"><span class="mini"><?= e(tipo_venta_texto((string)$p['tipo'])) ?></span></td>
                <td data-k="Estado">
                  <span class="chip chip--<?= e($p['estado_color'] ?: 'gris') ?>"><?= e($p['estado_nombre']) ?></span>
                </td>
                <td class="der num" data-k="Total"><?= e(soles((int)$p['total_centimos'])) ?></td>
                <td class="der num" data-k="Saldo">
                  <?php $s = (int)$p['total_centimos'] - (int)$p['cobrado_centimos']; ?>
                  <?= $s > 0 ? '<strong>' . e(soles($s)) . '</strong>' : '<span class="muted">—</span>' ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Movimientos de Cashback Waka</h2></div>
      <?php if (!$movimientos): ?>
        <p class="mini">Todavía no tiene movimientos. El cashback nace de cada
          <strong>pago</strong>, no del pedido: si te adelanta el 10% de una pre venta,
          gana el 1% de ese adelanto hoy, y el resto el día que complete.</p>
      <?php else: ?>
        <div class="tabla__caja">
          <table class="tabla">
            <thead><tr><th>Fecha</th><th>Movimiento</th><th>Pedido</th>
              <th class="der">Monto</th><th class="der">Vence</th></tr></thead>
            <tbody>
            <?php
              $nombres = ['acredita'=>'Ganó','usa'=>'Usó','vence'=>'Venció',
                          'revierte'=>'Se revirtió','ajuste'=>'Ajuste'];
            ?>
            <?php foreach ($movimientos as $m): ?>
              <tr>
                <td class="mini"><?= e(fecha_corta((string)$m['creado_en'])) ?></td>
                <td class="principal">
                  <div class="fila__t"><?= e($nombres[$m['tipo']] ?? $m['tipo']) ?></div>
                  <?php if ($m['motivo']): ?><div class="fila__s"><?= e($m['motivo']) ?></div><?php endif; ?>
                </td>
                <td data-k="Pedido"><span class="mini"><?= e($m['pedido_codigo'] ?: '—') ?></span></td>
                <td class="der num" data-k="Monto">
                  <?= (int)$m['monto_centimos'] >= 0 ? '+' : '−' ?><?= e(soles(abs((int)$m['monto_centimos']))) ?>
                </td>
                <td class="der mini" data-k="Vence"><?= $m['vence_en'] ? e(fecha_corta((string)$m['vence_en'])) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:12px">
    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Sus datos</h2></div>
      <div class="dato"><span class="dato__k">Correo</span>
        <span class="dato__t"><?= $c['email'] ? e($c['email']) : '<span class="chip chip--ambar">Falta</span>' ?></span></div>
      <div class="dato"><span class="dato__k">Celular</span><span class="dato__t"><?= e($c['celular'] ?: '—') ?></span></div>
      <?php if ($c['telefono_alt']): ?>
        <div class="dato"><span class="dato__k">Otro teléfono</span><span class="dato__t"><?= e($c['telefono_alt']) ?></span></div>
      <?php endif; ?>
      <div class="dato"><span class="dato__k">Comprobante</span>
        <span class="dato__t"><?= $c['tipo_comprobante'] === 'factura'
            ? 'Factura · RUC ' . e((string)$c['ruc_factura']) : 'Boleta' ?></span></div>
      <?php if ($c['rubro']): ?>
        <div class="dato"><span class="dato__k">Rubro</span><span class="dato__t"><?= e($c['rubro']) ?></span></div>
      <?php endif; ?>
      <div class="dato"><span class="dato__k">Asesor</span>
        <span class="dato__t"><?= e(trim((string)$c['asesor_nombre'] . ' ' . (string)$c['asesor_apellidos']) ?: '—') ?></span></div>
      <div class="dato"><span class="dato__k">Registrado</span>
        <span class="dato__t"><?= e(fecha_corta((string)$c['creado_en'])) ?></span></div>
    </div>

    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Cuenta en compraenwaka</h2></div>
      <p class="mini" style="margin:0 0 8px">
        <span class="chip chip--<?= in_array($c['estado_tienda'], ['creada','enlazada'], true) ? 'verde' : 'ambar' ?>">
          <?= e($tienda) ?></span>
      </p>
      <p class="mini" style="margin:0">
        El cliente entra a <strong>compraenwaka</strong> con su número de documento: ahí ve el
        catálogo y su Cashback Waka.
      </p>
    </div>

    <?php if ($direcciones): ?>
      <div class="tarjeta">
        <div class="tarjeta__cab"><h2>Dónde le hemos enviado</h2></div>
        <?php foreach ($direcciones as $d): ?>
          <div class="dato">
            <span class="dato__t"><?= e($d['direccion']) ?></span>
            <span class="dato__k"><?= e(trim((string)$d['distrito'] . ' · ' . (string)$d['provincia'], ' ·')) ?></span>
          </div>
        <?php endforeach; ?>
        <p class="mini" style="margin:8px 0 0">La dirección es del pedido, no del cliente.
          Estas son las que ya usó; al hacerle un pedido nuevo se precarga la última.</p>
      </div>
    <?php endif; ?>

    <?php if ($c['notas']): ?>
      <div class="tarjeta">
        <div class="tarjeta__cab"><h2>Notas</h2></div>
        <p class="mini" style="margin:0;white-space:pre-wrap"><?= e($c['notas']) ?></p>
      </div>
    <?php endif; ?>
  </div>
</div>
