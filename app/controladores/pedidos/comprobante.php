<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');

/**
 * Anota la boleta o la factura de una VENTA. Uno por pedido.
 * Ver pedido_comprobante() en pedidos.php.
 */
$id = pedir_int('id');
$p  = $id ? pedido_de($id) : null;
if (!$p) cortar(404, 'Ese pedido no existe');

$equipo_asesor = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
exigir_ver_para_escribir((int)$p['asesor_id'], (int)$p['pais_id'],
                         $equipo_asesor !== null ? (int)$equipo_asesor : null);

$r = pedido_comprobante($id, pedir('tipo'), pedir('serie'), pedir('numero'));

/* El PDF, si lo subieron. Va DESPUÉS de anotar y solo si aquello salió bien:
   guardar el archivo de un comprobante que se rechazó —una factura sin RUC—
   dejaría un PDF colgado sin nada que lo reclame. */
$aviso_pdf = '';
if ($r['ok'] && ($_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $ra = comprobante_guardar_archivo($_FILES['archivo']);
    if ($ra['ok']) {
        actualizar('pedidos', $id, ['comprobante_archivo' => $ra['archivo']]);
        $aviso_pdf = ' El archivo quedó guardado: se abre desde la ficha.';
    } else {
        $aviso_pdf = ' Ojo: el comprobante se anotó, pero el archivo no — ' . $ra['error'];
    }
}

avisar($r['ok'] ? 'ok' : 'error', $r['ok']
    ? 'Comprobante anotado en el pedido.' . $aviso_pdf
    : $r['error']);

/* SI NO SE PUDO ANOTAR, NO SE SUELTA A NADIE. Antes se volvía igual: la
   factura de un cliente sin RUC se rechazaba, la persona acababa en la bandeja
   y esa venta se quedaba sin comprobante y sin nada que la reclamara —el aviso
   de «X ventas sin comprobante» se quitó de la bandeja el mismo día—. Ahora se
   vuelve con la ventana puesta, que lleva el error escrito arriba y los
   botones otra vez delante. */
if (!$r['ok']) {
    $destino = volver_del_pago(pedir('volver'), $id);
    ir($destino . (str_contains($destino, '?') ? '&' : '?') . 'hecho=comprobante&ped=' . $id);
}

/* «cola» es la ventana que sale encima al confirmar un pago: desde ahí se
   vuelve a la bandeja, que es donde se estaba trabajando. «Cuando se presione
   en "esta no lleva" debe de mandarte a la pantalla principal de confirmación
   de pago, osea a la cola de si hay pagos pendientes o no» (usuario,
   2026-09-11) — y vale igual para boleta y factura: lo que se emite se emite
   en el sistema de facturación, aquí solo queda la constancia. */
ir(volver_del_pago(pedir('volver'), $id));
