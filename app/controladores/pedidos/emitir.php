<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');

/**
 * EMITE LA BOLETA O LA FACTURA ELECTRÓNICA DE UNA VENTA, con NUBEFACT.
 * Lo que hace de verdad está en nubefact_emitir(); aquí solo se comprueba quién
 * pide y se vuelve a donde estaba (parche 2u).
 */
$id = pedir_int('id');
$p  = $id ? pedido_de($id) : null;
if (!$p) cortar(404, 'Ese pedido no existe');

$equipo_asesor = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
exigir_ver_para_escribir((int)$p['asesor_id'], (int)$p['pais_id'],
                         $equipo_asesor !== null ? (int)$equipo_asesor : null);

$r = nubefact_emitir($id, (string) pedir('tipo'));

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
