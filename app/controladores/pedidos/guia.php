<?php
declare(strict_types=1);

/**
 * LA GUÍA DE REMISIÓN DE UN PEDIDO (5b): la vista previa para corregir y el
 * botón de mandarla. La emiten Almacén (desde «Por entregar») y Facturación.
 * La regla vive en nucleo/guias.php.
 */
$id = (int)(pedir_int('id', 'get') ?: pedir_int('id'));
$p  = $id ? pedido_de($id) : null;
if (!$p) cortar(404, 'Ese pedido no existe');
$u = yo();
if (puede('pagos.verificar')) {
    $equipo_asesor = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
    exigir_ver((int)$p['asesor_id'], (int)$p['pais_id'], $equipo_asesor !== null ? (int)$equipo_asesor : null);
} elseif (!cruza_paises($u) && (int)$p['pais_id'] !== (int)$u['pais_id']) {
    cortar(404, 'Ese pedido no existe');
}
seccion_activa(puede('pedidos.alistar') && !puede('pagos.verificar') ? 'entregar' : 'pagos');
$volver = puede('pedidos.alistar') ? url('/pedidos/por-entregar') : url('/pedidos/facturar?id=' . $id);

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (pedir('accion') === 'consultar') {
        $g0 = guia_de_pedido($id);
        $r = $g0 ? guia_consultar($g0) : ['ok' => false, 'error' => 'Todavía no hay guía.'];
        avisar($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Guía al día.' : $r['error']);
        ir('/pedidos/guia?id=' . $id);
    }
    $r = guia_emitir($id, $_POST);
    if ($r['ok']) {
        avisar('ok', 'Guía de remisión ' . nubefact_nombre($r['cpe'] ?? ['tipo_cpe' => 7, 'serie' => '', 'numero' => 0]) . ' emitida.');
        ir('/pedidos/guia?id=' . $id);
    }
    $errores[] = $r['error'];
}

$guia = guia_de_pedido($id);
$borrador = guia_borrador($p);
/* Si rebotó, lo escrito vuelve tal cual. */
if ($errores && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($borrador as $k => $v) if (!is_array($v) && isset($_POST[$k])) $borrador[$k] = (string)$_POST[$k];
    $it = [];
    foreach ((array)($_POST['i_desc'] ?? []) as $k => $d) $it[] = ['codigo' => (string)($borrador['items'][$k]['codigo'] ?? ''), 'descripcion' => (string)$d, 'cantidad' => (int)($_POST['i_cant'][$k] ?? 0)];
    if ($it) $borrador['items'] = $it;
}

pagina('pedidos/guia', [
    'p' => $p, 'g' => $borrador, 'guia' => $guia, 'errores' => $errores, 'volver' => $volver,
    'aplica' => guia_aplica($p), 'activa' => guia_activa(), 'pendiente' => guia_pendiente($id),
    'serie' => (string)(nubefact_config()['serie_guia'] ?? ''),
    'prueba' => nubefact_ambiente() === 'prueba',
], ['titulo' => 'Guía de remisión ' . $p['codigo'], 'sin_titulo' => true]);
