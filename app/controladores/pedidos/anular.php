<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');

$id = pedir_int('id');
$p  = $id ? pedido_de($id) : null;
if (!$p) cortar(404, 'Ese pedido no existe');

exigir_editar((int)$p['asesor_id'], (int)$p['pais_id']);

/* CON COMPROBANTE ELECTRÓNICO, PRIMERO LA NOTA DE CRÉDITO (parche 2u). Es
   todo o nada: si la nota no sale, la venta no se anula — una venta anulada
   con su boleta viva en SUNAT es exactamente el descuadre que hay que evitar.
   Y la nota solo la emite quien emite comprobantes: el asesor que quiere
   anular una venta ya facturada se lo pide a facturación. */
$nota_cred = '';
/* Una boleta que se mandó sin respuesta puede estar viva en SUNAT: primero
   hay que saber si salió. */
if (nubefact_pendiente($id)) {
    avisar('error', 'Esta venta tiene una boleta o factura que se mandó a NUBEFACT sin respuesta. '
                  . 'Facturación tiene que pulsar «Emitir» otra vez para saber si salió; después se puede anular.');
    ir('/pedidos/ficha?id=' . $id);
}
if (nubefact_vigente($id)) {
    if (!puede('pagos.verificar')) {
        avisar('error', 'Esta venta ya tiene boleta o factura emitida. Pídele a Administración que la anule: '
                      . 'al anularla se emite su nota de crédito.');
        ir('/pedidos/ficha?id=' . $id);
    }
    $motivo_id = (int)pedir_int('motivo_item_id');
    if (!lista_valida('motivos_anulacion', $motivo_id, (int)$p['pais_id'])) {
        avisar('error', 'Elige uno de los motivos de la lista.');
        ir('/pedidos/ficha?id=' . $id);
    }
    $nc = nubefact_nota_credito($id, $motivo_id, lista_texto($motivo_id) . ' ' . pedir('nota'));
    if (!$nc['ok']) {
        avisar('error', 'No se anuló: la nota de crédito no salió. ' . $nc['error']);
        ir('/pedidos/ficha?id=' . $id);
    }
    if (!empty($nc['cpe'])) $nota_cred = ' Se emitió la ' . mb_strtolower(nubefact_nombre($nc['cpe'])) . '.';
}

$r = pedido_anular($id, (int)pedir_int('motivo_item_id'), pedir('nota'));

if (!$r['ok']) {
    avisar('error', $r['error']);
} else {
    /* Lo que se le dice al asesor tiene que ser exactamente lo que pasó: se
       revierte lo COBRADO, no el total del pedido. Un pedido de S/2,180 del
       que solo entraron S/500 devuelve S/500 y revierte S/5.00 de cashback. */
        avisar('ok', 'Pedido anulado.' . $nota_cred . ($r['devolver'] > 0
        ? ' Hay que devolverle al cliente ' . soles($r['devolver'])
          . ', que es lo que llegó a pagar. Su cashback de esos pagos también se revirtió.'
        : ' No había pagos cobrados, así que no hay nada que devolver.'));
}
ir('/pedidos/ficha?id=' . $id);
