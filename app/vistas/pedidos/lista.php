<?php $puede_crear = puede('pedidos.crear'); ?>
<header class="cabecera">
  <div>
    <h1>Pedidos</h1>
    <div class="cabecera__sub">
      <?= $recortada ? 'Los ' . count($pedidos) . ' más nuevos'
                     : plural(count($pedidos), 'pedido', 'pedidos') ?>
      <?php if ($total_saldo > 0): ?> · <?= e(soles($total_saldo)) ?> por cobrar<?php endif; ?>
      <?php if ($recortada): ?> · <strong>hay más</strong>: busca por código, cliente o documento<?php endif; ?>
    </div>
  </div>
  <div class="cabecera__acciones">
    <?php /* Los descuentos que pasaron del tope y esperan respuesta (3d). */ ?>
    <?php $n_desc = descuentos_pendientes_n(); ?>
    <?php if ($n_desc > 0): ?>
      <a class="chip chip--ambar" id="chip-descuentos" href="<?= e(url('/pedidos/descuentos')) ?>">
        <?= $n_desc ?> descuento<?= $n_desc > 1 ? 's' : '' ?> por aprobar</a>
    <?php endif; ?>
    <?php if (!$puede_crear && puede('pedidos.ver')): ?>
      <span class="chip chip--gris"><?= ico('ojo',13) ?> Solo miras</span>
    <?php endif; ?>
    <?php if ($puede_crear): ?>
      <a class="btn btn--negro" href="<?= e(url('/pedidos/nuevo')) ?>"><?= ico('mas',16) ?> NUEVO PEDIDO</a>
    <?php endif; ?>
  </div>
</header>

<?php /* LO PRIMERO: LO QUE YA SE PUEDE MANDAR.
         Va encima del aviso de pagos por revisar a propósito: aquello es
         esperar, esto es una tarea que se puede terminar ahora. El asesor vive
         en esta pantalla, así que aquí es donde tiene que enterarse de que
         facturación le desbloqueó una venta.
         En el celular el botón baja a su propio renglón y ocupa el ancho: es
         la acción, no un adorno al final de una frase. */ ?>
<?php if (!empty($listas_despacho)): ?>
  <?php /* El rótulo es «VER Y MANDAR» y no «MANDAR A DESPACHO» porque el botón
           NO manda nada: abre la lista, y desde ahí se manda cada una con un
           clic (3h.1). */ ?>
  <div class="aviso aviso--verde aviso--conbtn" style="margin-bottom:14px">
    <span><?= ico('camion',17) ?></span>
    <span><strong><?= plural((int)$listas_despacho, 'venta lista para despacho',
                             'ventas listas para despacho') ?>.</strong>
      Mándalas y Almacén las ve en «Por alistar».</span>
    <a class="btn btn--negro" href="<?= e(url('/pedidos/por-despachar')) ?>">VER Y MANDAR</a>
  </div>
<?php endif; ?>

<?php if ($por_validar): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px">
    <span><?= ico('reloj',17) ?></span>
    <span><strong><?= plural($por_validar,'pago sin revisar','pagos sin revisar') ?>.</strong>
      <a href="<?= e(url('/pagos/por-validar')) ?>">Revisarlos ahora</a>.</span>
  </div>
<?php endif; ?>

<?php if ($ver === 'porregistrar'): ?>
  <?php /* A esta lista se llega desde el aviso de saldos sin registrar. */ ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px">
    <span><?= ico('tarjeta',17) ?></span>
        <span><strong>Ventas enviadas con saldo por registrar.</strong>
      El cobro no cuenta hasta que el asesor suba el voucher.</span>
  </div>
<?php endif; ?>

<?php /* LOS FILTROS, EN DOS PISOS (usuario, 2026-09-23). Arriba, los cuatro
         que se usan todo el día. Lo demás —zona, tipo, estado y asesor— va
         plegado detrás de «Más filtros», que dice cuántos están puestos: once
         píldoras en fila ocupaban media pantalla antes del primer pedido. */ ?>
<?php
  $tab = $tab ?? 'stock';
  $enlace = function (array $cambios) use ($ver, $tipo, $q, $zona, $asesor_f, $estado_f, $tab, $lote_f, $pv_f) {
      $hoy = ['tab' => $tab === 'preventa' ? 'preventa' : '', 'v' => $ver, 't' => $tipo, 'q' => $q, 'z' => $zona,
              'a' => $asesor_f ?: '', 'es' => $estado_f, 'lo' => $lote_f ?: '', 'pv' => $pv_f];
      $todo = array_filter(array_merge($hoy, $cambios), fn($x) => (string)$x !== '');
      return '?' . http_build_query($todo);
  };
  $mas_puestos = ($zona !== '' ? 1 : 0) + ($tipo !== '' ? 1 : 0)
               + ($estado_f !== '' ? 1 : 0) + ($asesor_f ? 1 : 0)
               + ($lote_f ? 1 : 0) + ($pv_f !== '' ? 1 : 0);
?>
<?php /* DOS PESTAÑAS (3j): la pre venta aparte de la venta de stock, cada una
         con sus filtros. Al cambiar de pestaña se quitan los filtros de la
         otra (tipo, estado, lote), no la búsqueda. */ ?>
<nav class="pestanas" aria-label="Qué ventas ver" id="pestanas-pedidos">
  <?php foreach (['stock' => 'Stock', 'preventa' => 'Pre venta'] as $tk => $tt): ?>
    <a class="pestanas__p <?= $tab === $tk ? 'on' : '' ?>" <?= $tab === $tk ? 'aria-current="page"' : '' ?>
       href="<?= e(url('/pedidos' . $enlace(['tab' => $tk === 'preventa' ? 'preventa' : '', 't' => '', 'es' => '', 'lo' => '', 'pv' => '']))) ?>">
      <?= e($tt) ?> <span class="pestanas__n"><?= (int)($tab_n[$tk] ?? 0) ?></span></a>
  <?php endforeach; ?>
</nav>
<div class="filtros" style="align-items:center">
  <?php foreach ([['todos','Todos'],['abiertos','Con saldo'],['mios','Míos'],['hoy','De hoy'],['anulados','Anulados']] as [$k,$t]): ?>
    <a class="<?= $ver === $k ? 'on' : '' ?>" href="<?= e($enlace(['v' => $k])) ?>"><?= e($t) ?></a>
  <?php endforeach; ?>
  <span class="filtros__sep" aria-hidden="true"></span>
  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?php if ($tab === 'preventa'): ?><input type="hidden" name="tab" value="preventa">
      <input type="hidden" name="lo" value="<?= $lote_f ?: '' ?>"><input type="hidden" name="pv" value="<?= e($pv_f) ?>"><?php endif; ?>
    <input type="hidden" name="v" value="<?= e($ver) ?>">
    <input type="hidden" name="t" value="<?= e($tipo) ?>">
    <input type="hidden" name="z" value="<?= e($zona) ?>">
    <input type="hidden" name="es" value="<?= e($estado_f) ?>">
    <input type="hidden" name="a" value="<?= $asesor_f ?: '' ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Código, cliente o documento"
           style="border:1.5px solid var(--linea);background:var(--tarjeta);border-radius:999px;padding:9px 16px;font-size:12.5px;min-width:220px">
  </form>
</div>

<details class="desplegable filtros-mas" style="margin-bottom:12px" <?= $mas_puestos ? 'open' : '' ?>>
  <summary><?= ico('filtro',14) ?> Más filtros<?= $mas_puestos ? ' · ' . $mas_puestos . ' puesto' . ($mas_puestos > 1 ? 's' : '') : '' ?></summary>
  <form method="get" class="barra-filtros" style="margin:0;border:0;background:transparent;padding:0 0 12px">
    <?php if ($tab === 'preventa'): ?><input type="hidden" name="tab" value="preventa"><?php endif; ?>
    <input type="hidden" name="v" value="<?= e($ver) ?>">
    <input type="hidden" name="q" value="<?= e($q) ?>">
    <?php if ($tab === 'preventa'): ?>
      <label>Lote
        <select name="lo">
          <option value="">Todos los lotes</option>
          <?php foreach ($lotes_filtro as $lf): ?>
            <option value="<?= (int)$lf['id'] ?>" <?= $lote_f === (int)$lf['id'] ? 'selected' : '' ?>><?= e((string)($lf['nombre'] ?: $lf['codigo'])) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>¿Ya se puede entregar?
        <select name="pv">
          <option value="">Todas</option>
          <option value="listo"  <?= $pv_f === 'listo' ? 'selected' : '' ?>>Listas para entrega (por mandar)</option>
          <option value="camino" <?= $pv_f === 'camino' ? 'selected' : '' ?>>Todavía en camino</option>
        </select>
      </label>
    <?php endif; ?>
    <?php if (!empty($hay_zona)): ?>
      <label>Zona
        <select name="z">
          <option value="">Todo el país</option>
          <option value="lima"      <?= $zona === 'lima' ? 'selected' : '' ?>>Lima</option>
          <option value="provincia" <?= $zona === 'provincia' ? 'selected' : '' ?>>Provincia</option>
        </select>
      </label>
    <?php endif; ?>
    <?php if ($tab !== 'preventa'): ?>
    <label>Tipo de venta
      <select name="t">
        <option value="">Todos los tipos</option>
        <?php foreach (tipos_de_venta() as $tv_k => $tv): if ($tv_k === 'preventa') continue; ?>
          <option value="<?= e($tv_k) ?>" <?= $tipo === $tv_k ? 'selected' : '' ?>><?= e($tv['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <label>Estado
      <select name="es">
        <option value="">Todos los estados</option>
        <?php foreach ($estados_lista as $es_i): ?>
          <option value="<?= e($es_i['clave']) ?>" <?= $estado_f === (string)$es_i['clave'] ? 'selected' : '' ?>>
            <?= e($es_i['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if ($asesores_filtro): ?>
      <label>Asesor
        <select name="a">
          <option value="">Todos los asesores</option>
          <?php foreach ($asesores_filtro as $as_i): ?>
            <option value="<?= (int)$as_i['id'] ?>" <?= $asesor_f === (int)$as_i['id'] ? 'selected' : '' ?>>
              <?= e(trim((string)$as_i['nombre'] . ' ' . (string)$as_i['apellidos'])) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
    <div class="barra-filtros__b">
      <button class="btn btn--negro" type="submit">VER</button>
      <?php if ($mas_puestos): ?>
        <a class="btn btn--linea" href="<?= e(url('/pedidos' . $enlace(['z'=>'', 't'=>'', 'es'=>'', 'a'=>'', 'lo'=>'', 'pv'=>'']))) ?>">QUITAR</a>
      <?php endif; ?>
    </div>
  </form>
</details>

<?php if (!$pedidos): ?>
  <div class="tarjeta">
    <?php parte('inicio/vacio', [
      'ico' => 'PE', 'marca' => $puede_crear,
      /* «Todavía no hay pedidos» con un filtro puesto es mentira: los hay,
         pero no los que se pidieron (auditoría del 3a.1). */
      'titulo' => ($q !== '' || $mas_puestos) ? 'Ningún pedido coincide'
                  : ($ver === 'abiertos' ? 'No hay nada por cobrar' : 'Todavía no hay pedidos'),
      'texto'  => $ver === 'abiertos' && $q === '' && !$mas_puestos
          ? 'Todos los pedidos que ves están cobrados al 100%. Cuando quede un saldo, aparecerá aquí.'
          : 'Un pedido nace con su cliente, sus productos y —si ya pagó algo— su primer pago.',
      'boton'  => $puede_crear ? 'NUEVO PEDIDO' : '',
      'ruta'   => '/pedidos/nuevo',
    ]); ?>
  </div>
<?php else: ?>
  <div class="tabla__caja">
    <table class="tabla">
      <thead>
        <tr><th>Pedido</th><th>Cliente</th><th>Qué lleva</th><th>Tipo</th><th>Estado</th><th>Pago</th><th>Asesor</th>
          <th class="der">Total</th><th class="der">Cobrado</th><th class="der">Saldo</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($pedidos as $p): ?>
        <?php $saldo = (int)$p['total_centimos'] - (int)$p['cobrado_centimos']; ?>
        <tr class="<?= $p['estado'] === 'anulado' ? 'apagada' : '' ?>">
          <td class="principal">
            <a href="<?= e(url('/pedidos/ficha?id=' . (int)$p['id'])) ?>" style="color:inherit">
              <div class="fila__t"><?= e($p['codigo']) ?></div>
              <div class="fila__s"><?= e(fecha_corta((string)($p['fecha'] ?: $p['creado_en']))) ?></div>
            </a>
          </td>
          <td data-k="Cliente">
            <div class="recorta"><?= e(trim((string)$p['cliente_nombre'] . ' ' . (string)$p['cliente_apellidos'])) ?></div>
            <div class="fila__s"><?= e($p['documento']) ?></div>
          </td>
          <?php /* QUÉ LLEVA LA VENTA (usuario, 2026-09-23): hasta ahora había
                   que abrir el pedido para saber qué compró el cliente. Sale
                   la primera línea con su cantidad, y cuántas más hay. */ ?>
          <td data-k="Qué lleva">
            <?php $lns = $lineas_de[(int)$p['id']] ?? []; ?>
            <?php parte('pedidos/lineas_corto', ['lns' => $lns]); ?>
          </td>
          <td data-k="Tipo"><span class="mini"><?= e(tipo_venta_texto((string)$p['tipo'])) ?></span></td>
          <td data-k="Estado">
            <span class="chip chip--<?= e($p['estado_color'] ?: 'gris') ?>"><?= e($p['estado_nombre']) ?></span>
            <?php if (($esperas[(int)$p['id']] ?? '') !== ''): ?>
              <div class="fila__s espera-preventa"><?= ico('reloj',12) ?> <?= e($esperas[(int)$p['id']]) ?></div>
            <?php endif; ?>
          </td>
          <?php /* EL ESTADO DEL PAGO, que es distinto del estado del pedido y
                   hasta ahora no salía en ninguna lista: un pago denegado solo
                   se veía entrando al pedido. Manda el PEOR de los pagos
                   (usuario, 2026-09-11): lo que pide una acción es lo que hay
                   que ver desde fuera. */ ?>
          <td data-k="Pago">
            <?php $pp = estado_pago_pinta((string)($p['estado_pago'] ?? '')); ?>
            <?php if ($pp['texto'] !== ''): ?>
              <span class="chip chip--<?= e($pp['color']) ?>"><?= e($pp['texto']) ?></span>
            <?php else: ?><span class="muted">—</span><?php endif; ?>
          </td>
          <td data-k="Asesor"><span class="mini"><?= e(primer_nombre((string)$p['asesor_nombre'])) ?></span></td>
          <td class="der num" data-k="Total"><?= e(soles((int)$p['total_centimos'])) ?></td>
          <td class="der num" data-k="Cobrado"><?= e(soles((int)$p['cobrado_centimos'])) ?></td>
          <td class="der num" data-k="Saldo">
            <?= $saldo > 0 ? '<strong>' . e(soles($saldo)) . '</strong>' : '<span class="muted">—</span>' ?>
          </td>
          <?php /* EL MENÚ DE LA FILA. Lo que antes obligaba a entrar al pedido
                   y volver. La ESTRELLA marca lo que falta por hacer, y esa
                   línea desaparece en cuanto el pedido sale a despacho: si ya
                   no hay nada que hacer, no ocupa sitio (usuario, 2026-09-11).
                   Tampoco sale mientras el pago no esté confirmado, porque el
                   HUB no deja despachar sin dinero confirmado — ofrecerlo sería
                   un botón que lleva a un error. */ ?>
          <td class="der">
            <details class="menu-chip menu-fila">
              <summary class="chip chip--linea" title="Más"><?= ico('puntos',14) ?></summary>
              <div class="menu-chip__caja">
                <a href="<?= e(url('/pedidos/ficha?id=' . (int)$p['id'])) ?>">Ver el pedido</a>
                <?php /* ESCRIBIRLE AL ASESOR (usuario, 2026-09-25): con el saldo sin
                         registrar, el mensaje ya lo dice. */ ?>
                <?php $wa_as = whatsapp_al_asesor($p, (int)($p['saldo_sin_registrar'] ?? 0)); ?>
                <?php if ($wa_as !== ''): ?>
                  <a href="<?= e($wa_as) ?>" target="_blank" rel="noopener" data-wa-asesor="<?= (int)$p['id'] ?>">
                    <?= (int)($p['saldo_sin_registrar'] ?? 0) > 0 && avisa_saldos_sin_registrar() ? '<span class="estrella">★</span> ' : '' ?>
                    <span>Escribir a <?= e(primer_nombre((string)$p['asesor_nombre'])) ?> por WhatsApp</span></a>
                <?php endif; ?>
                <?php /* «¿Se puede despachar?» sale de sql_despacho_listo(), la
                         MISMA definición que usa la ficha y que corta
                         pedido_despacho_marcar(). Escrita a mano aquí
                         —«estado_pago === confirmado»— la lista y la ficha
                         discrepaban en qué ventas podían salir. Escribir la
                         regla una sola vez es lo que evita que vuelva a pasar. */ ?>
                <?php if ($puede_despachar && $p['estado'] !== 'anulado'
                          && (int)($p['despacho_listo'] ?? 0) === 1
                          && (int)($p['despacho_veces'] ?? 0) === 0
                          && puedo_editar((int)$p['asesor_id'], (int)$p['pais_id'])): ?>
                  <?php parte('pedidos/boton_despacho', ['p' => $p, 'clase' => '', 'html' => '<span class="estrella">★</span> <span>Mandar a despacho</span>', 'volver' => 'lista', 'volver_q' => (string)($_SERVER['QUERY_STRING'] ?? '')]); ?>
                <?php endif; ?>
                <?php /* SOLICITAR EL COMPROBANTE, DESDE AQUÍ (usuario, 2026-09-12).
                         El asesor casi nunca lo sabe mientras teclea la venta;
                         lo sabe después, hablando con el cliente. Es la segunda
                         puerta —la primera es la ventana de «Pedido
                         registrado»—, y las dos mandan al mismo sitio.
                         No se ofrece cuando facturación ya lo resolvió: pedir
                         una boleta de una venta que ya tiene factura no es un
                         aviso, es un lío. */ ?>
                <?php $pide_ya = (string)($p['comprobante_pide'] ?? '');
                      $ya_emitido = trim((string)($p['comprobante_tipo'] ?? '')) !== '';
                      /* Y el PERMISO que exige la ruta, no solo el ámbito: dos
                         formas de cerrar la misma puerta acaban cerrando una. */
                      $puedo_pedir = $hay_pide && !$ya_emitido && $p['estado'] !== 'anulado'
                                     && puede('pedidos.editar')
                                     && puedo_editar((int)$p['asesor_id'], (int)$p['pais_id']); ?>
                <?php if ($puedo_pedir): ?>
                  <?php foreach (comprobantes_que_se_solicitan() as $sc_k => $sc): ?>
                    <?php if ($pide_ya === $sc_k) continue; ?>
                    <form method="post" action="<?= e(url('/pedidos/solicitar-comprobante')) ?>">
                      <?= campo_csrf() ?>
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <input type="hidden" name="tipo" value="<?= e($sc_k) ?>">
                      <input type="hidden" name="volver" value="lista">
                      <?php /* Los TRES filtros, no solo la búsqueda: volver a
                               «Todos» desde «Míos» deja al asesor buscando
                               otra vez dónde iba. */ ?>
                      <input type="hidden" name="q" value="<?= e($q) ?>">
                      <input type="hidden" name="v" value="<?= e($ver) ?>">
                      <input type="hidden" name="t" value="<?= e($tipo) ?>">
                      <input type="hidden" name="z" value="<?= e($zona) ?>">
                      <input type="hidden" name="a" value="<?= $asesor_f ?: '' ?>">
                      <input type="hidden" name="es" value="<?= e($estado_f) ?>">
                      <input type="hidden" name="tab" value="<?= $tab === 'preventa' ? 'preventa' : '' ?>">
                      <input type="hidden" name="lo" value="<?= $lote_f ?: '' ?>">
                      <input type="hidden" name="pv" value="<?= e($pv_f) ?>">
                      <button type="submit"><?= e($pide_ya === '' ? $sc['menu']
                                                 : 'Cambiar a ' . mb_strtolower($sc['nombre'])) ?></button>
                    </form>
                  <?php endforeach; ?>
                  <?php if ($pide_ya !== ''): ?>
                    <form method="post" action="<?= e(url('/pedidos/solicitar-comprobante')) ?>">
                      <?= campo_csrf() ?>
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <input type="hidden" name="tipo" value="">
                      <input type="hidden" name="volver" value="lista">
                      <input type="hidden" name="q" value="<?= e($q) ?>">
                      <input type="hidden" name="v" value="<?= e($ver) ?>">
                      <input type="hidden" name="t" value="<?= e($tipo) ?>">
                      <input type="hidden" name="z" value="<?= e($zona) ?>">
                      <input type="hidden" name="a" value="<?= $asesor_f ?: '' ?>">
                      <input type="hidden" name="es" value="<?= e($estado_f) ?>">
                      <input type="hidden" name="tab" value="<?= $tab === 'preventa' ? 'preventa' : '' ?>">
                      <input type="hidden" name="lo" value="<?= $lote_f ?: '' ?>">
                      <input type="hidden" name="pv" value="<?= e($pv_f) ?>">
                      <button type="submit" class="menu-chip__mal">Retirar la solicitud</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if ($puede_facturar && $p['estado'] !== 'anulado'): ?>
                  <a href="<?= e(url('/pedidos/facturar?id=' . (int)$p['id'])) ?>">Emitir comprobante</a>
                <?php endif; ?>
                <?php /* Y anular solo si de verdad puede: el líder de equipo LEE
                         los pedidos de los suyos y lleva 'pedidos.anular', pero
                         puedo_editar() le dice que no. El menú se lo ofrecía en
                         rojo y la ficha, al llegar, no tenía dónde hacerlo. */ ?>
                <?php if ($puede_anular && $p['estado'] !== 'anulado'
                          && puedo_editar((int)$p['asesor_id'], (int)$p['pais_id'])): ?>
                  <a class="menu-chip__mal" href="<?= e(url('/pedidos/ficha?id=' . (int)$p['id'] . '#anular')) ?>">Anular pedido</a>
                <?php endif; ?>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="mini" style="margin-top:12px">
    <strong>«Cobrado»</strong> es el dinero ya confirmado por facturación, no lo vendido.
    Es lo que cuenta para la meta y el podio.
  </p>
<?php endif; ?>
