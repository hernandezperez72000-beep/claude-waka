<?php /* POR ALISTAR (3g): una tarjeta por pedido, con lo que lleva, cómo sale
         y el botón ALISTADO (foto + quién). Ver nucleo/alistar.php. */
$cuantos   = (int)($cuantos ?? 0);
$equipos   = $equipos ?? [];
$con_error = (int)($con_error ?? 0);
$garantias = $garantias ?? [];
$mostrados = array_merge(array_map(fn($x) => (int)$x['id'], $pedidos), array_map(fn($x) => -(int)$x['id'], $garantias));
$modo = $modo ?? 'alistar';
?>
<?php /* Un error de un pedido que ya no está en la lista (se anuló, otro lo
         alistó) se dice arriba: dentro de su tarjeta no se vería. */ ?>
<?php if (!empty($errores) && !in_array($con_error, $mostrados, true)): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px" id="aviso-alistar-error">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>
<?php if (!($equipos[(int)($mi_pais ?? 0)] ?? [])): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px" id="aviso-sin-equipo">
    <span><?= ico('alerta',17) ?></span>
    <span><strong>Falta la lista del equipo de despacho.</strong>
      Sin ella no se puede marcar quién alistó.
      <?php if (puede('listas.gestionar')): ?>
        <a href="<?= e(url('/configuracion/equipo-despacho')) ?>" style="text-decoration:underline">Ponla aquí</a>.
      <?php else: ?>Pídesela a Administración (Configuración › Equipo de despacho).<?php endif; ?></span>
  </div>
<?php endif; ?>

<?php if ($modo === 'alistar'): ?>
<?php /* LAS GARANTÍAS APROBADAS (3i): salen sin cobrar, con envío gratis, a
         la dirección de su pedido. Van primero: el cliente ya esperó. */ ?>
<?php if ($garantias): ?>
  <div class="alistar" id="alistar-garantias" style="margin-bottom:14px">
  <?php foreach ($garantias as $g): $gid = (int)$g['id']; $gp = $gar_pedidos[$gid] ?? null;
        $equipo = $equipos[(int)$g['pais_id']] ?? []; ?>
    <div class="tarjeta alistar__ped alistar__ped--garantia" id="garantia-<?= $gid ?>">
      <div class="tarjeta__cab">
        <h2><?= e(garantia_codigo($gid)) ?>
          <span class="mini" style="font-weight:600">· <?= e(trim((string)$g['cliente_nombre'] . ' ' . (string)$g['cliente_apellidos'])) ?></span></h2>
        <span class="chip chip--marca">Garantía · sin cobro</span>
      </div>
      <ul class="alistar__lineas">
        <?php foreach ($g['lineas'] as $ln): ?>
          <li><span class="alistar__cant"><?= (int)$ln['cantidad'] ?>×</span>
            <span class="alistar__desc"><?= e((string)$ln['nombre']) ?>
              <?php if (($ln['maquina_nombre'] ?? '') !== ''): ?><span class="alistar__modelo">de <?= e((string)$ln['maquina_nombre']) ?></span><?php endif; ?></span></li>
        <?php endforeach; ?>
      </ul>
      <p class="mini" style="margin:6px 0 0">Del pedido <?= e((string)$g['pedido_codigo']) ?><?= $gp ? ' · ' . e(pedido_como_recibe($gp)) : '' ?>. Sin cobrar nada, tampoco el envío.
        <?php if (trim((string)$g['nota']) !== ''): ?><br>Nota: <?= e((string)$g['nota']) ?><?php endif; ?></p>
      <div class="acciones" style="margin:8px 0 0">
        <a class="btn btn--linea btn--chico alistar__rotulo" href="<?= e(url('/garantias/rotulo?id=' . $gid . '&vista=1')) ?>"><?= ico('impresora',14) ?> RÓTULO</a>
      </div>
      <?php if ($con_error === -$gid && !empty($errores)): ?>
        <div class="aviso aviso--rojo" style="margin-top:10px">
          <span><?= ico('alerta',17) ?></span>
          <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
        </div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="form alistar__form">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="garantia_alistado">
        <input type="hidden" name="id" value="<?= $gid ?>">
        <label>¿Quién la alistó?
          <select name="quien_id" required <?= $equipo ? '' : 'disabled' ?>>
            <option value="">Elige</option>
            <?php foreach ($equipo as $q): ?>
              <option value="<?= (int)$q['id'] ?>" <?= $con_error === -$gid && (int)($quien_elegido ?? 0) === (int)$q['id'] ? 'selected' : '' ?>><?= e((string)$q['valor']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php parte('pagos/foto_campo', ['campo' => 'foto_alistado', 'titulo' => 'Foto de lo alistado',
              'ayuda' => 'Que se vean las piezas.', 'pend' => $pend_foto[-$gid] ?? null,
              'solo_foto' => true, 'id_extra' => 'g' . $gid]); ?>
        <div class="acciones"><button class="btn btn--amarillo" type="submit" <?= $equipo ? '' : 'disabled' ?>>ALISTADO</button></div>
      </form>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!$pedidos && !$garantias): ?>
  <div class="tarjeta">
    <?php parte('inicio/vacio', [
      'ico' => 'OK', 'titulo' => 'Nada por alistar',
      'texto' => 'Cuando el asesor mande una venta a despacho, aparece aquí sola.',
    ]); ?>
  </div>
<?php elseif ($pedidos): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px">
    <span><?= ico('caja',17) ?></span>
    <span><strong><?= plural($cuantos, 'pedido por alistar', 'pedidos por alistar') ?>.</strong>
      Los que tienen fecha de entrega van primero. Revisa cada producto y su modelo antes de cerrar la caja.</span>
  </div>
  <?php if ($cuantos > count($pedidos)): ?>
    <div class="aviso aviso--gris" style="margin-bottom:14px">
      <span><?= ico('alerta',17) ?></span>
      <span>Se muestran <?= count($pedidos) ?> de <?= $cuantos ?>. Al alistar los de arriba, suben los demás.</span>
    </div>
  <?php endif; ?>

  <div class="alistar">
  <?php foreach ($pedidos as $p): $pid = (int)$p['id']; $lns = $lineas[$pid] ?? [];
        $equipo = $equipos[(int)$p['pais_id']] ?? [];
        $cuando = pedido_entrega_texto($p);
        $vence  = !empty($p['entrega_fecha']) && $p['entrega_fecha'] < date('Y-m-d');
        $hoy    = !empty($p['entrega_fecha']) && $p['entrega_fecha'] === date('Y-m-d'); ?>
    <div class="tarjeta alistar__ped" id="alistar-<?= $pid ?>">
      <div class="tarjeta__cab">
        <h2><?= e((string)$p['codigo']) ?>
          <span class="mini" style="font-weight:600">· <?= e(trim((string)$p['cliente_nombre'] . ' ' . (string)$p['cliente_apellidos'])) ?></span></h2>
        <?php if ($cuando !== ''): ?>
          <span class="<?= $vence ? 'chip chip--rojo' : ($hoy ? 'chip chip--ambar' : 'chip chip--gris') ?>"><?= e($cuando) ?></span>
        <?php endif; ?>
      </div>

      <?php /* TODO lo que lleva, una línea por producto, con el modelo bien a
               la vista: es donde se equivocan. */ ?>
      <ul class="alistar__lineas">
        <?php if (!$lns): ?><li class="muted">Sin productos</li><?php endif; ?>
        <?php foreach ($lns as $ln): ?>
          <li><span class="alistar__cant"><?= (int)$ln['cantidad'] ?>×</span>
            <span class="alistar__desc"><?= e((string)$ln['descripcion']) ?>
              <?php if (trim((string)($ln['modelo'] ?? '')) !== ''): ?>
                <span class="alistar__modelo"><?= e((string)$ln['modelo']) ?></span><?php endif; ?></span></li>
        <?php endforeach; ?>
      </ul>

      <?php /* EL RÓTULO (3h.1): lo pega Almacén en la caja que arma. */ ?>
      <div class="acciones" style="margin:8px 0 0">
        <a class="btn btn--linea btn--chico alistar__rotulo" href="<?= e(url('/pedidos/rotulo?id=' . $pid . '&vista=1')) ?>"><?= ico('impresora',14) ?> RÓTULO</a>
      </div>

      <?php if (($indicaciones[$pid] ?? '') !== ''): ?>
        <details class="alistar__ind">
          <summary>Ver indicaciones del envío</summary>
          <div class="alistar__txt"><?= nl2br(e((string)$indicaciones[$pid])) ?></div>
        </details>
      <?php endif; ?>

      <?php if ($con_error === $pid && !empty($errores)): ?>
        <div class="aviso aviso--rojo" style="margin-top:10px">
          <span><?= ico('alerta',17) ?></span>
          <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
        </div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" class="form alistar__form">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="alistado">
        <input type="hidden" name="id" value="<?= $pid ?>">
        <label>¿Quién lo alistó?
          <select name="quien_id" required <?= $equipo ? '' : 'disabled' ?>>
            <option value="">Elige</option>
            <?php foreach ($equipo as $q): ?>
              <option value="<?= (int)$q['id'] ?>" <?= $con_error === $pid && (int)($quien_elegido ?? 0) === (int)$q['id'] ? 'selected' : '' ?>><?= e((string)$q['valor']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php parte('pagos/foto_campo', ['campo' => 'foto_alistado', 'titulo' => 'Foto de lo alistado',
              'ayuda' => 'Que se vean todos los productos y sus modelos.', 'pend' => $pend_foto[$pid] ?? null,
              'solo_foto' => true, 'id_extra' => (string)$pid]); ?>
        <div class="acciones"><button class="btn btn--amarillo" type="submit" <?= $equipo ? '' : 'disabled' ?>>ALISTADO</button></div>
      </form>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>


<?php /* Lo ya alistado vive en «Por entregar» (5b): aquí, un atajo. */ ?>
<?php if ((int)($por_entregar_n ?? 0) > 0): ?>
  <a class="tarjeta tarjeta--enlace atajo-entregar" href="<?= e(url('/pedidos/por-entregar')) ?>" id="atajo-por-entregar">
    <?= ico('camion', 20) ?>
    <span class="crece"><strong><?= plural((int)$por_entregar_n, 'pedido alistado por entregar', 'pedidos alistados por entregar') ?></strong>
      <span class="mini">Están en «Por entregar»: ahí se marca la entrega con su foto.</span></span>
    <?= ico('flecha', 14) ?>
  </a>
<?php endif; ?>
<?php endif; ?>

<?php if ($modo === 'entregar'): ?>
<?php /* POR ENTREGAR (3j): lo alistado que ya salió. ENTREGADO pide la foto
         de la entrega (o del envío en la agencia) y el estado pasa solo. */
$por_entregar = $por_entregar ?? [];
$con_error_ent = (int)($con_error_ent ?? 0); ?>
<?php if (!empty($errores) && $con_error_ent && !in_array($con_error_ent, array_map(fn($x) => (int)$x['id'], $por_entregar), true)): ?>
  <div class="aviso aviso--rojo" style="margin-top:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>
<?php if (!$por_entregar): ?>
  <div class="tarjeta" id="por-entregar-vacio">
    <?php parte('inicio/vacio', ['ico' => 'OK', 'titulo' => 'Nada por entregar',
          'texto' => 'Cuando se aliste un pedido, aparece aquí para marcar la entrega con su foto.']); ?>
  </div>
<?php else: ?>
  <div id="por-entregar">
    <p class="mini muted" style="margin:0 0 10px">Cuando llegue al cliente (o lo recibe la agencia), sube la foto de la entrega.
      Los envíos a provincia llevan su <strong>guía de remisión</strong>.</p>
    <div class="alistar">
    <?php foreach ($por_entregar as $p): $pid = (int)$p['id']; $lns = ($por_entregar_lineas ?? [])[$pid] ?? [];
          $prov = function_exists('guia_aplica') && guia_aplica($p); $guia = $prov && function_exists('guia_de_pedido') ? guia_de_pedido($pid) : null; ?>
      <div class="tarjeta alistar__ped entregar" id="entregar-<?= $pid ?>">
        <div class="tarjeta__cab">
          <h2><?= e((string)$p['codigo']) ?>
            <span class="mini" style="font-weight:600">· <?= e(trim((string)$p['cliente_nombre'] . ' ' . (string)$p['cliente_apellidos'])) ?></span></h2>
          <span class="chip chip--verde">Alistado</span>
        </div>
        <p class="mini" style="margin:0 0 6px"><?= e(pedido_como_recibe($p)) ?> · alistado <?= e(hace((string)$p['alistado_en'])) ?></p>
        <?php if ($lns): ?>
          <ul class="alistar__lineas">
            <?php foreach ($lns as $ln): ?>
              <li><span class="alistar__cant"><?= (int)$ln['cantidad'] ?>×</span>
                <span class="alistar__desc"><?= e((string)$ln['descripcion']) ?>
                  <?php if (trim((string)($ln['modelo'] ?? '')) !== ''): ?><span class="alistar__modelo"><?= e((string)$ln['modelo']) ?></span><?php endif; ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <div class="acciones" style="margin:8px 0 0">
          <a class="btn btn--linea btn--chico" href="<?= e(url('/pedidos/rotulo?id=' . $pid . '&vista=1')) ?>"><?= ico('impresora',14) ?> RÓTULO</a>
          <?php if ($prov): ?>
            <a class="btn <?= $guia ? 'btn--linea' : 'btn--negro' ?> btn--chico" href="<?= e(url('/pedidos/guia?id=' . $pid)) ?>" id="guia-<?= $pid ?>">
              <?= ico('doc',14) ?> <?= $guia ? 'GUÍA ' . e((string)$guia['serie'] . '-' . (int)$guia['numero']) : 'GUÍA DE REMISIÓN' ?></a>
          <?php endif; ?>
          <a class="btn btn--linea btn--chico" target="_blank" rel="noopener" href="<?= e(url('/pedidos/alistado-foto?id=' . $pid)) ?>"><?= ico('camara',14) ?> FOTO DE LO ALISTADO</a>
        </div>
        <?php if ($con_error_ent === $pid && !empty($errores)): ?>
          <div class="aviso aviso--rojo" style="margin-top:8px">
            <span><?= ico('alerta',17) ?></span>
            <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
          </div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="form entregar__form alistar__form">
          <?= campo_csrf() ?>
          <input type="hidden" name="accion" value="entregado">
          <input type="hidden" name="id" value="<?= $pid ?>">
          <?php parte('pagos/foto_campo', ['campo' => 'foto_entrega', 'titulo' => 'Foto de la entrega',
                'ayuda' => 'El producto entregado, o el comprobante de la agencia.', 'pend' => $pend_entrega[$pid] ?? null,
                'id_extra' => 'e' . $pid]); ?>
          <div class="acciones"><button class="btn btn--amarillo" type="submit">MARCAR ENTREGADO</button></div>
        </form>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($entregados)): ?>
  <div class="tarjeta" style="margin-top:16px" id="entregados-hoy">
    <div class="tarjeta__cab"><h2>Entregados hoy <span class="chip chip--gris"><?= count($entregados) ?></span></h2></div>
    <?php foreach ($entregados as $a): ?>
      <div class="fila">
        <span class="fila__crece">
          <span class="fila__t"><?= e((string)$a['codigo']) ?> · <?= e(trim((string)$a['cliente_nombre'] . ' ' . (string)$a['cliente_apellidos'])) ?></span>
          <span class="fila__s"><?= e(hace((string)$a['entregado_en'])) ?></span>
        </span>
        <a class="chip chip--ver" target="_blank" rel="noopener" href="<?= e(url('/pedidos/entrega-foto?id=' . (int)$a['id'])) ?>">VER FOTO</a>
        <?php if ($a['anulado_en'] === null): ?>
        <form method="post" style="display:inline">
          <?= campo_csrf() ?>
          <input type="hidden" name="accion" value="entregado_deshacer">
          <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <button class="chip chip--suave" type="submit" style="cursor:pointer">Deshacer</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php endif; ?>

<?php if ($modo === 'alistar'): ?>
<?php if (!empty($garantias_hoy)): ?>
  <div class="tarjeta" style="margin-top:16px" id="garantias-alistadas-hoy">
    <div class="tarjeta__cab"><h2>Garantías alistadas hoy <span class="chip chip--gris"><?= count($garantias_hoy) ?></span></h2></div>
    <?php foreach ($garantias_hoy as $a): ?>
      <div class="fila">
        <span class="fila__crece">
          <span class="fila__t"><?= e(garantia_codigo((int)$a['id'])) ?> · <?= e(trim((string)$a['cliente_nombre'] . ' ' . (string)$a['cliente_apellidos'])) ?></span>
          <span class="fila__s"><?= e('por ' . lista_texto((int)$a['alistado_quien_id']) . ' · ' . hace((string)$a['alistado_en'])) ?></span>
        </span>
        <a class="chip chip--ver" target="_blank" rel="noopener" href="<?= e(url('/garantias/foto?g=' . (int)$a['id'] . '&alistado=1')) ?>">VER FOTO</a>
        <form method="post" style="display:inline">
          <?= campo_csrf() ?>
          <input type="hidden" name="accion" value="garantia_deshacer">
          <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <button class="chip chip--suave" type="submit" style="cursor:pointer">Deshacer</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!empty($alistados)): ?>
  <div class="tarjeta" style="margin-top:16px" id="alistados-hoy">
    <div class="tarjeta__cab"><h2>Alistados hoy <span class="chip chip--gris"><?= count($alistados) ?></span></h2></div>
    <?php foreach ($alistados as $a): ?>
      <div class="fila">
        <span class="fila__crece">
          <span class="fila__t"><?= e((string)$a['codigo']) ?> · <?= e(trim((string)$a['cliente_nombre'] . ' ' . (string)$a['cliente_apellidos'])) ?></span>
          <span class="fila__s"><?= e(pedido_alistado_detalle($a)) ?></span>
        </span>
        <?php if ($a['anulado_en'] !== null): ?>
          <span class="chip chip--rojo">Se anuló: desarma la caja</span>
        <?php elseif (!empty($a['despacho_en']) && $a['despacho_en'] > $a['alistado_en']): ?>
          <span class="chip chip--ambar">Se volvió a mandar: revisa las indicaciones</span>
        <?php endif; ?>
        <a class="chip chip--ver" target="_blank" rel="noopener" href="<?= e(url('/pedidos/alistado-foto?id=' . (int)$a['id'])) ?>">VER FOTO</a>
        <?php if ($a['anulado_en'] === null): ?>
        <a class="chip chip--linea" href="<?= e(url('/pedidos/rotulo?id=' . (int)$a['id'] . '&vista=1')) ?>">Rótulo</a>
        <form method="post" style="display:inline">
          <?= campo_csrf() ?>
          <input type="hidden" name="accion" value="deshacer">
          <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <button class="chip chip--suave" type="submit" style="cursor:pointer">Deshacer</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php endif; ?>
