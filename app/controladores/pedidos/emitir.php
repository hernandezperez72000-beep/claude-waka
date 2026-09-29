<?php
declare(strict_types=1);

/**
 * EMITE LA BOLETA O LA FACTURA ELECTRÓNICA DE UNA VENTA, con NUBEFACT.
 * Lo que hace de verdad está en nubefact_emitir(); aquí solo se comprueba quién
 * pide y se vuelve a donde estaba (parche 2u).
 */
$id = (int)(pedir_int('id') ?: pedir_int('id', 'get'));
$p  = $id ? pedido_de($id) : null;
if (!$p) cortar(404, 'Ese pedido no existe');

$equipo_asesor = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
exigir_ver_para_escribir((int)$p['asesor_id'], (int)$p['pais_id'],
                         $equipo_asesor !== null ? (int)$equipo_asesor : null);

/* LA VISTA PREVIA (5b, usuario 2026-09-29): antes de mandar, se ve el
   comprobante como va a salir y se corrige el texto si hace falta. */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $tipo = (string) pedir('tipo', 'get');
    $pv = nubefact_previa($id, $tipo);
    if (!$pv['ok'] && !isset($pv['p'])) {
        avisar('error', $pv['error']);
        ir(volver_del_pago(pedir('volver', 'get'), $id));
    }
    seccion_activa('pagos');
    pagina('pedidos/emitir_vista', ['pv' => $pv, 'tipo' => $tipo, 'volver' => (string) pedir('volver', 'get'),
        'url_volver' => url(volver_del_pago(pedir('volver', 'get'), $id))],
        ['titulo' => 'Emitir ' . $tipo . ' · ' . $p['codigo'], 'sin_titulo' => true]);
    return;
}

/* Lo corregido en la vista previa (si vino de ella). */
$ed = [];
if (pedir('previa') === '1') {
    $ed = ['nombre' => pedir('c_nombre'), 'direccion' => pedir('c_direccion'), 'email' => pedir('c_email'),
           'observaciones' => pedir('observaciones'), 'desc' => (array)($_POST['i_desc'] ?? [])];
}
$r = nubefact_emitir($id, (string) pedir('tipo'), $ed);

if ($r['ok']) {
    $cpe = $r['cpe'] ?? null;
    avisar('ok', ($cpe ? nubefact_nombre($cpe) : 'Comprobante') . ' emitido.'
        . (!empty($cpe['enlace_pdf']) ? ' El PDF se abre desde el pedido.' : ''));
    ir(volver_del_pago(pedir('volver'), $id));
}

/* Si no salió, se vuelve a la MISMA pantalla con el motivo, y con la ventana
   puesta si venía de ella: la venta no puede quedarse sin comprobante y sin
   nada que lo reclame. */
avisar('error', $r['error']);
$destino = volver_del_pago(pedir('volver'), $id);
if (pedir('volver') !== 'facturar') {
    $destino .= (str_contains($destino, '?') ? '&' : '?') . 'hecho=comprobante&ped=' . $id;
}
ir($destino);
