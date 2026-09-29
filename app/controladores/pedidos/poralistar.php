<?php
declare(strict_types=1);
/* 5b (usuario, 2026-09-29): «Por entregar» es otra opción del menú de
   Almacén, con lo que ya está alistado. Es este mismo controlador: las
   acciones (alistar, entregar, deshacer) son las mismas y viven en un sitio. */
$modo = (($ruta ?? '') === '/pedidos/por-entregar') ? 'entregar' : 'alistar';
seccion_activa($modo);

/**
 * POR ALISTAR (3g): lo que el asesor ya mandó a despacho y Almacén tiene que
 * preparar. Cada pedido con TODO lo que lleva y las indicaciones del envío.
 * ALISTADO pide la foto de lo alistado y quién lo alistó. La regla entera
 * vive en nucleo/alistar.php.
 */
if (!alistado_listo()) {
    pagina('pedidos/sinmigrar', [], ['titulo' => 'Por alistar',
        'subtitulo' => 'Disponible en cuanto se termine la actualización']);
    return;
}

$errores = [];
$con_error = 0;          // el pedido cuyo formulario rebotó: se abre con su aviso
$con_error_ent = 0;      // lo mismo, en «Por entregar» (3j)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) pedir_int('id');
    $accion = pedir('accion');
    if ($accion === 'alistado' && $id) {
        $ctx = 'alistar-' . $id;
        $foto = voucher_del_formulario($ctx, 'foto_alistado');
        if (!$foto['ok']) {
            $errores[] = (string)$foto['error'];
        } else {
            $r = pedido_alistar($id, pedir_int('quien_id'), $foto['archivo']);
            if ($r['ok']) {
                voucher_pendiente_confirmar(voucher_contexto($ctx, 'foto_alistado'));
                avisar('ok', 'Listo: ' . (string) valor('SELECT codigo FROM pedidos WHERE id = ?', [$id]) . ' quedó alistado. Ya está en «Por entregar».');
                ir('/pedidos/por-alistar');
            }
            $errores[] = $r['error'];
        }
        $con_error = $id;
    }
    /* LAS GARANTÍAS APROBADAS (3i): igual que un pedido, con foto y quién. */
    if ($accion === 'garantia_alistado' && $id) {
        $ctx = 'alistar-g' . $id;
        $foto = voucher_del_formulario($ctx, 'foto_alistado');
        if (!$foto['ok']) {
            $errores[] = (string)$foto['error'];
        } else {
            $r = garantia_alistar($id, pedir_int('quien_id'), $foto['archivo']);
            if ($r['ok']) {
                voucher_pendiente_confirmar(voucher_contexto($ctx, 'foto_alistado'));
                avisar('ok', 'Listo: la garantía ' . garantia_codigo($id) . ' quedó alistada.');
                ir('/pedidos/por-alistar');
            }
            $errores[] = $r['error'];
        }
        $con_error = -$id;
    }
    if ($accion === 'garantia_deshacer' && $id) {
        $r = garantia_alistado_deshacer($id);
        avisar($r['ok'] ? 'ok' : 'error', $r['ok'] ? (($r['aviso'] ?? '') !== '' ? $r['aviso'] : 'Vuelve a la lista de por alistar.') : $r['error']);
        ir('/pedidos/por-alistar');
    }
    /* ENTREGADO (3j): con la foto de la entrega. El estado pasa solo. */
    if ($accion === 'entregado' && $id) {
        $ctx = 'entregar-' . $id;
        $foto = voucher_del_formulario($ctx, 'foto_entrega');
        if (!$foto['ok']) {
            $errores[] = (string)$foto['error'];
        } else {
            $r = pedido_entregado_marcar($id, $foto['archivo']);
            if ($r['ok']) {
                voucher_pendiente_confirmar(voucher_contexto($ctx, 'foto_entrega'));
                avisar('ok', 'Listo: ' . (string) valor('SELECT codigo FROM pedidos WHERE id = ?', [$id]) . ' quedó entregado.');
                ir('/pedidos/por-entregar');
            }
            $errores[] = $r['error'];
        }
        $con_error_ent = $id;
    }
    if ($accion === 'entregado_deshacer' && $id) {
        $r = pedido_entregado_deshacer($id);
        avisar($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Vuelve a «Por entregar».' : $r['error']);
        ir('/pedidos/por-entregar');
    }
    if ($accion === 'deshacer' && $id) {
        $r = pedido_alistado_deshacer($id);
        avisar($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Vuelve a la lista de por alistar.' : $r['error']);
        ir('/pedidos/por-alistar');
    }
}

$u = yo();
$tope = 60;
$pedidos = pedidos_por_alistar($u, $tope);
$lineas  = pedidos_lineas_de(array_map(fn($x) => (int)$x['id'], $pedidos));
/* LAS INDICACIONES son el mismo mensaje que el asesor pegó en el grupo de
   despacho: una sola definición de «cómo sale este pedido». */
$indicaciones = [];
foreach ($pedidos as $p0) {
    $indicaciones[(int)$p0['id']] = mensaje_de_pedido((int)$p0['id'], plantilla_de_despacho($p0));
}
$garantias = garantias_por_alistar($u, 60);
$pendientes_foto = [];
foreach ($garantias as $g0) {
    $pendientes_foto[-(int)$g0['id']] = voucher_pendiente(voucher_contexto('alistar-g' . (int)$g0['id'], 'foto_alistado'));
}
foreach ($pedidos as $p0) {
    $pendientes_foto[(int)$p0['id']] = voucher_pendiente(voucher_contexto('alistar-' . (int)$p0['id'], 'foto_alistado'));
}
$por_entregar = pedidos_por_entregar($u, 60);
$pend_entrega = [];
foreach ($por_entregar as $p0) {
    $pend_entrega[(int)$p0['id']] = voucher_pendiente(voucher_contexto('entregar-' . (int)$p0['id'], 'foto_entrega'));
}

$n_alistar = alistar_pendientes_n($u);
$n_entregar = pedidos_por_entregar_n($u);
/* EL INDICADOR (usuario, 2026-09-29): cuántas solicitudes hay, arriba y
   grande. Lo refresca el sondeo de avisos sin recargar la página. */
$indicador = '<a class="cuenta-grande' . ($modo === 'alistar' ? ' cuenta-grande--on' : '') . '" href="' . e(url('/pedidos/por-alistar')) . '">'
           . '<span class="cuenta-grande__n" id="alistar-cuantos">' . $n_alistar . '</span><span class="cuenta-grande__k">por alistar</span></a>'
           . '<a class="cuenta-grande' . ($modo === 'entregar' ? ' cuenta-grande--on' : '') . '" href="' . e(url('/pedidos/por-entregar')) . '">'
           . '<span class="cuenta-grande__n" id="entregar-cuantos">' . $n_entregar . '</span><span class="cuenta-grande__k">por entregar</span></a>';

pagina('pedidos/poralistar', [
    'modo'         => $modo,
    'por_entregar_lineas' => $modo === 'entregar' ? pedidos_lineas_de(array_map(fn($x) => (int)$x['id'], $por_entregar)) : [],
    'pedidos'      => $pedidos,
    'cuantos'      => pedidos_por_alistar_n($u),
    'tope'         => $tope,
    'lineas'       => $lineas,
    'indicaciones' => $indicaciones,
    /* El equipo del PAÍS DEL PEDIDO: quien cruza países (Dirección,
       Desarrollador) alista pedidos de otro con la gente de allá. */
    'equipos'      => (function () use ($pedidos, $u) {
        $m = [(int)$u['pais_id'] => lista('equipo_despacho', (int)$u['pais_id'])];
        foreach ($pedidos as $p0) $m[(int)$p0['pais_id']] ??= lista('equipo_despacho', (int)$p0['pais_id']);
        foreach ($garantias as $g0) $m[(int)$g0['pais_id']] ??= lista('equipo_despacho', (int)$g0['pais_id']);
        return $m;
    })(),
    'garantias'    => $garantias,
    'garantias_hoy'=> garantias_alistadas_hoy($u),
    /* Cómo sale cada garantía: el pedido original dice a dónde. */
    'gar_pedidos'  => (function () use ($garantias) {
        $m = [];
        foreach ($garantias as $g0) $m[(int)$g0['id']] = pedido_de((int)$g0['pedido_id']);
        return $m;
    })(),
    'mi_pais'      => (int)$u['pais_id'],
    'alistados'    => pedidos_alistados_hoy($u),
    'por_entregar' => $por_entregar,
    'por_entregar_n' => pedidos_por_entregar_n($u),
    'entregados'   => pedidos_entregados_hoy($u),
    'pend_entrega' => $pend_entrega,
    'con_error_ent'=> $con_error_ent,
    'errores'      => $errores,
    'con_error'    => $con_error,
    'pend_foto'    => $pendientes_foto,
    'quien_elegido'=> (int) pedir_int('quien_id'),
], $modo === 'entregar'
    ? ['titulo' => 'Por entregar', 'subtitulo' => 'Lo alistado que falta entregar al cliente o a la agencia', 'acciones' => $indicador]
    : ['titulo' => 'Por alistar', 'subtitulo' => 'Lo que ya se mandó a despacho y hay que preparar', 'acciones' => $indicador]);
