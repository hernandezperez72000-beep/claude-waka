<?php
$qs = fn(array $extra = []) => '?' . http_build_query(array_merge([
    'desde' => $desde, 'hasta' => $hasta, 'g' => $agrupar,
    'e' => $estado, 'm' => $metodo ?: '', 'a' => $asesor ?: '',
    // El país solo viaja para quien puede elegirlo; para los demás lo pone el ámbito.
    'p' => !empty($paises_reporte) ? (int)$pais_reporte : '',
], $extra));
$sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
$max = 0; foreach ($grupos as $g) $max = max($max, (int)$g['monto']);
?>
<?php /* LOS FILTROS, EN UNA TIRA (usuario, 2026-09-22): «que se vea más como
         filtro que como un campo grande, para que no tenga que bajar tanto
         para ver el detalle». Son los mismos filtros de siempre, con los
         rótulos en pequeño encima y las ayudas recogidas en una sola línea
         debajo. */ ?>
<form method="get" class="barra-filtros">
  <label>Desde <input type="date" name="desde" value="<?= e($desde) ?>"></label>
  <label>Hasta <input type="date" name="hasta" value="<?= e($hasta) ?>"></label>
  <label>Agrupar
    <select name="g">
      <option value="dia"    <?= $sel($agrupar,'dia') ?>>Día</option>
      <option value="semana" <?= $sel($agrupar,'semana') ?>>Semana</option>
      <option value="mes"    <?= $sel($agrupar,'mes') ?>>Mes</option>
    </select>
  </label>
  <?php if (!empty($paises_reporte)): ?>
    <?php /* Solo sale para quien cruza países. El dinero de dos países no se
             suma: soles y pesos bajo un solo símbolo no son ninguna cifra. */ ?>
    <label>País
      <select name="p">
        <?php foreach ($paises_reporte as $pp): ?>
          <option value="<?= (int)$pp['id'] ?>" <?= $sel($pais_reporte, $pp['id']) ?>><?= e($pp['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>
  <label>Estado
    <select name="e">
      <option value="validados"  <?= $sel($estado,'validados') ?>>Solo validados</option>
      <option value="pendientes" <?= $sel($estado,'pendientes') ?>>Solo pendientes</option>
      <option value="denegados"  <?= $sel($estado,'denegados') ?>>Solo denegados</option>
      <option value="rechazados" <?= $sel($estado,'rechazados') ?>>Denegados y quitados</option>
      <option value="todos"      <?= $sel($estado,'todos') ?>>Todos</option>
    </select>
  </label>
  <label>Banco
    <select name="m">
      <option value="">Todos</option>
      <?php foreach ($metodos as $m): ?>
        <option value="<?= (int)$m['id'] ?>" <?= $sel($metodo, $m['id']) ?>><?= e($m['valor']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Asesor
    <select name="a">
      <option value="">Todos</option>
      <?php foreach ($asesores as $a): ?>
        <option value="<?= (int)$a['id'] ?>" <?= $sel($asesor, $a['id']) ?>>
          <?= e(trim($a['nombre'] . ' ' . (string)$a['apellidos'])) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="barra-filtros__b">
    <button class="btn btn--negro" type="submit">VER</button>
    <?php if (puede('reportes.exportar')): ?>
      <a class="btn btn--linea" href="<?= e(url('/reportes/pagos') . $qs(['excel' => 1])) ?>">EXCEL</a>
    <?php endif; ?>
  </div>
</form>
<p class="mini" style="margin:-6px 0 12px">Solo los validados son dinero confirmado: es lo que mide la meta<?php
  if (!empty($paises_reporte)): ?> · un país a la vez, cada uno cobra en su moneda<?php endif; ?>.</p>

<?php if (!empty($recortado)): ?>
  <?php /* El detalle corta en REPORTE_PAGOS_TOPE, así que el desglose por día
           y por método está calculado sobre una parte. Las cifras grandes de
           arriba sí son del total —salen de un agregado—, pero decirlo importa:
           un desglose incompleto sin avisar es peor que no tenerlo. */ ?>
  <div class="aviso aviso--gris" style="margin-bottom:12px">
    <span><?= ico('alerta',17) ?></span>
    <span>Hay <?= (int)$todas_las_filas ?> pagos en este rango y el desglose por día y
      por método se calculó con los <?= (int)$traidas ?> más recientes. Las tres cifras de
      abajo sí cuentan todos. Acorta el rango o filtra para ver el desglose completo.</span>
  </div>
<?php endif; ?>

<div class="rejilla rejilla--3" style="margin-bottom:12px">
  <div class="tarjeta cifra cifra--compacta">
    <span class="cifra__k">Ingresado</span>
    <span class="cifra__v"><?= e(soles_corto($total)) ?></span>
    <?php /* Los pagos que componen ESA cifra —los confirmados—, no los que
             pide el filtro de estado: eran dos poblaciones en la misma
             tarjeta. */ ?>
    <span class="cifra__d"><?= plural($n_real, 'pago', 'pagos') ?>
      del <?= e(fecha_corta($desde)) ?> al <?= e(fecha_corta($hasta)) ?></span>
  </div>
  <div class="tarjeta cifra cifra--compacta">
    <span class="cifra__k">Esperando validación</span>
    <span class="cifra__v"><?= e(soles_corto($pendiente)) ?></span>
    <span class="cifra__d"><?= $pendiente > 0
        ? 'todavía no suma a ninguna meta · sin contar el filtro de estado'
        : 'nada pendiente en este periodo' ?></span>
  </div>
  <div class="tarjeta cifra cifra--compacta">
    <span class="cifra__k">Devuelto</span>
    <span class="cifra__v"><?= e(soles_corto($devuelto)) ?></span>
    <span class="cifra__d">reversiones anotadas en este periodo</span>
  </div>
</div>

<div class="rejilla rejilla--panel">
  <div class="tarjeta">
    <div class="tarjeta__cab"><h2>Por <?= e($agrupar === 'dia' ? 'día' : $agrupar) ?></h2>
      <span class="mini">Solo dinero confirmado</span></div>
    <?php if (!$grupos): ?>
      <p class="mini">No entró ningún pago confirmado en ese periodo.</p>
    <?php else: ?>
      <?php /* Solo los diez primeros a la vista (usuario, 2026-09-22): un mes
               entero eran treinta líneas y el detalle quedaba a dos pantallas
               de aquí. El resto sigue estando, plegado. */ ?>
      <?php $g_tope = 10; $g_i = 0; ?>
      <?php foreach ($grupos as $g): $g_i++; ?>
        <?php if ($g_i === $g_tope + 1): ?>
          <details class="desplegable" style="margin-top:8px">
            <summary>Ver los otros <?= count($grupos) - $g_tope ?></summary>
        <?php endif; ?>
        <div class="dato">
          <span class="dato__crece" style="min-width:0;flex:1">
            <span class="dato__t"><?= e($g['etiqueta']) ?></span>
            <span class="pista pista--fina">
              <span class="pista__llena" style="width:<?= $max > 0 ? round((int)$g['monto'] * 100 / $max) : 0 ?>%"></span>
            </span>
          </span>
          <span class="dato__k" style="text-align:right">
            <strong><?= e(soles((int)$g['monto'])) ?></strong><br>
            <?= plural((int)$g['n'], 'pago', 'pagos') ?>
          </span>
        </div>
      <?php endforeach; ?>
      <?php if (count($grupos) > $g_tope): ?></details><?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="tarjeta">
    <div class="tarjeta__cab"><h2>Por banco / método</h2>
      <span class="mini">Solo dinero confirmado</span></div>
    <?php if (!$por_metodo): ?>
      <p class="mini">Sin dinero confirmado en el periodo.</p>
    <?php else: ?>
      <?php foreach ($por_metodo as $nombre => $monto): ?>
        <div class="dato">
          <span class="dato__k"><?= e($nombre) ?></span>
          <span class="dato__t"><?= e(soles((int)$monto)) ?></span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php $n_sin_comprobante_todo = (int)($n_sin_comprobante_todo ?? $n_sin_comprobante); ?>
<?php if ($n_sin_comprobante > 0 || $n_sin_comprobante_todo > 0): ?>
  <?php /* Se cuenta sobre PEDIDOS, no sobre pagos: una pre venta que todavía no
           adelantó nada no tiene ni un pago y aun así es una venta sin
           comprobante. Es la razón por la que el HUB anota la boleta. */ ?>
  <div class="tarjeta" style="margin-top:12px">
    <div class="tarjeta__cab">
      <h2>Ventas sin comprobante <span class="chip chip--ambar"><?= (int)$n_sin_comprobante ?></span></h2>
      <span class="mini">Del <?= e(fecha_corta($desde)) ?> al <?= e(fecha_corta($hasta)) ?>,
        por la fecha de la venta</span>
    </div>
    <?php if (!$sin_comprobante): ?>
      <p class="mini" style="margin:0">Ninguna en este rango.</p>
    <?php else: ?>
    <div class="tabla__caja">
      <table class="tabla">
        <thead><tr><th>Fecha</th><th>Pedido</th><th>Cliente</th>
          <th class="der">Total</th><th>Asesor</th></tr></thead>
        <tbody>
        <?php /* Cinco a la vista; el resto, en el enlace de abajo. La tabla
                 entera empujaba «Denegados por asesor» fuera de la pantalla. */ ?>
        <?php foreach (array_slice($sin_comprobante, 0, 5) as $sc): ?>
          <tr>
            <td class="mini"><?= e(fecha_corta((string)$sc['fecha'])) ?></td>
            <td class="principal">
              <a href="<?= e(url('/pedidos/facturar?id=' . (int)$sc['id'])) ?>" style="color:inherit">
                <div class="fila__t"><?= e($sc['codigo']) ?></div>
                <div class="fila__s">emitir y anotar</div></a>
            </td>
            <td data-k="Cliente"><?= e(trim((string)$sc['cliente_nombre'] . ' ' . (string)$sc['cliente_apellidos'])) ?></td>
            <td class="der num" data-k="Total"><?= e(soles((int)$sc['total_centimos'])) ?></td>
            <td data-k="Asesor"><span class="mini"><?= e(primer_nombre((string)$sc['asesor_nombre'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php if ($n_sin_comprobante > 5): ?>
      <p class="mini" style="margin:10px 0 0">Se muestran 5 de
        <?= (int)$n_sin_comprobante ?>, las más antiguas primero. Filtra por asesor o
        acorta el rango para ver las que buscas, o descarga el Excel.</p>
    <?php endif; ?>
    <?php /* El rango nace en el mes en curso, así que una venta de hace tres
             meses que se dejó en «omitir por ahora» no aparecía aquí y la
             tarjeta se leía como «ya está todo emitido». Es la misma cuenta,
             una con rango y otra sin él, y se dice cuál es cuál. */ ?>
    <?php if ($n_sin_comprobante_todo > $n_sin_comprobante): ?>
      <p class="mini" style="margin:10px 0 0">Fuera de este rango quedan
        <strong><?= (int)($n_sin_comprobante_todo - $n_sin_comprobante) ?></strong> más
        (<?= (int)$n_sin_comprobante_todo ?> sin comprobante en total).
        <a href="<?= e(url('/reportes/pagos?desde=2000-01-01&hasta=' . date('Y-m-d')
                          . ($asesor ? '&a=' . (int)$asesor : '')
                          . (!empty($paises_reporte) ? '&p=' . (int)$pais_reporte : ''))) ?>"
           style="text-decoration:underline">Verlas todas</a>.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php /* QUIÉN ACUMULA DENEGADOS (usuario, 2026-09-11): «serviría para saber si
         hay un asesor con muchos pagos denegados y podríamos estar más
         pendientes de ese asesor». Va ARRIBA del detalle porque la pregunta es
         «¿hay alguien?», no «¿cuál fue cada pago?».
         Se enseña «2 de 34», no un porcentaje: con seis pagos un porcentaje
         suena a estadística y no lo es. */ ?>
<?php if (!empty($denegados_por_asesor)): ?>
  <div class="tarjeta" style="margin-top:12px">
    <div class="tarjeta__cab">
      <h2>Denegados por asesor</h2>
      <span class="mini">En el rango que estás mirando</span>
    </div>
    <div class="tabla__caja">
      <table class="tabla">
        <thead><tr><th>Asesor</th><th class="der">Denegados</th>
          <th class="der">De cuántos</th><th class="der">Monto denegado</th></tr></thead>
        <tbody>
        <?php foreach ($denegados_por_asesor as $d): ?>
          <tr>
            <td class="principal"><?= e(trim((string)$d['nombre'] . ' ' . (string)$d['apellidos'])) ?: '—' ?></td>
            <td class="der num"><strong><?= (int)$d['denegados'] ?></strong></td>
            <td class="der num"><?= (int)$d['registrados'] ?></td>
            <td class="der num"><?= e(soles((int)$d['monto'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="mini" style="margin:10px 0 0">
      Un pago denegado <strong>no es una falta</strong>: un voucher puede tardar en aparecer en el
      banco. Lo que dice algo es que se repita en la misma persona.
    </p>
  </div>
<?php endif; ?>

<?php if ($filas): ?>
  <div class="tarjeta" style="margin-top:12px">
    <div class="tarjeta__cab">
      <h2>El detalle<?= $estado !== 'todos' ? ' · ' . match($estado) {
          'validados' => 'solo validados',
          'pendientes' => 'solo pendientes',
          'denegados'  => 'solo denegados por facturación',
          'rechazados' => 'denegados y quitados',
          default => '' } : '' ?></h2>
      <span class="mini"><?= $todas_las_filas > count($filas)
        ? 'Se muestran ' . count($filas) . ' de ' . $todas_las_filas
          . ' · el Excel trae hasta ' . number_format(REPORTE_PAGOS_TOPE, 0, ',', ' ')
        : plural($todas_las_filas, 'pago', 'pagos') ?></span>
    </div>
    <div class="tabla__caja">
      <table class="tabla">
        <thead><tr><th>Fecha</th><th>Pedido</th><th>Cliente</th><th>Banco / método</th>
          <th>Operación</th><th class="der">Monto</th><th>Estado</th><th>Comprobante</th><th>Asesor</th></tr></thead>
        <tbody>
        <?php foreach ($filas as $f): ?>
          <tr>
            <td class="mini"><?= e(fecha_corta((string)$f['fecha'])) ?></td>
            <td class="principal">
              <a href="<?= e(url('/pedidos/ficha?id=' . (int)$f['pedido_id'])) ?>" style="color:inherit">
                <?= e($f['codigo']) ?></a>
            </td>
            <td data-k="Cliente"><span class="recorta"><?= e(trim((string)$f['cliente_nombre'] . ' ' . (string)$f['cliente_apellidos'])) ?></span></td>
            <td data-k="Banco"><?= e($f['metodo'] ?: '—') ?></td>
            <td data-k="Operación"><span class="mini"><?= e($f['operacion'] ?: '—') ?></span></td>
            <td class="der num" data-k="Monto">
              <?= (string)$f['tipo'] === 'devolucion' ? '− ' : '' ?><?= e(soles(abs((int)$f['monto_centimos']))) ?>
            </td>
            <td data-k="Estado">
              <?php /* El mismo pago tiene que leerse igual aquí que en la ficha:
                       una sola definición, en pago_chip(). */ ?>
              <?php [$k_cls, $k_txt, $k_ico, $k_nota] = pago_chip($f); ?>
              <span class="chip <?= e($k_cls) ?>">
                <?= $k_ico ? ico($k_ico, 12) : '' ?><?= e($k_txt) ?></span>
              <?php if ($k_nota !== ''): ?>
                <div class="fila__s" style="max-width:220px"><?= e($k_nota) ?></div>
              <?php endif; ?>
            </td>
            <td data-k="Comprobante">
              <?php $ct = pedido_comprobante_texto($f); ?>
              <?php if ($ct !== ''): ?>
                <span class="mini"><?= e($ct) ?></span>
              <?php elseif ((string)($f['comprobante_tipo'] ?? '') === 'ninguno'): ?>
                <span class="mini muted">no lleva</span>
              <?php else: ?>
                <span class="chip chip--ambar">Sin emitir</span>
              <?php endif; ?>
            </td>
            <td data-k="Asesor"><span class="mini"><?= e(primer_nombre((string)$f['asesor_nombre'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<p class="mini" style="margin-top:12px">
  <strong>Este reporte no es el de ventas:</strong> muestra el dinero que entró, en la fecha en
  que entró. Una pre venta de S/ 20,000 separada con S/ 2,000 suma S/ 2,000 ese mes y S/ 18,000
  el mes en que se completa. Las devoluciones van con la fecha en que se hicieron.
</p>
