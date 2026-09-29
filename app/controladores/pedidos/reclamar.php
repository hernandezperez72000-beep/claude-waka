<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');

/**
 * EL ASESOR RECLAMA QUE LE CONFIRMEN UNA VENTA.
 *
 * «Tiene que haber un botón de reenviar solicitud, pero que se active después
 * de X tiempo, con un contador» (usuario, 2026-09-14).
 *
 * Deja constancia en el HUB —la fila sube en la bandeja de facturación con su
 * contador— y devuelve a la pantalla con el enlace de WhatsApp ya armado, para
 * que el asesor lo mande él: el HUB no puede enviar un WhatsApp solo sin una
 * API, y prometer que lo manda sería mentir.
 */
$id = pedir_int('id');
$p  = $id ? pedido_de($id) : null;
if (!$p) cortar(404, 'Ese pedido no existe');

/* La misma puerta que pedir el comprobante: reclamar por una venta es hablar
   por ella, y eso es de quien la hizo o de Administración. */
exigir_editar((int)$p['asesor_id'], (int)$p['pais_id']);

$r = pedido_reclamar($id);

if (!$r['ok']) {
    avisar('error', $r['error']);
    ir('/pedidos/por-despachar');
}

avisar('ok', 'Reclamo anotado' . ((int)$r['veces'] > 1 ? ' (van ' . (int)$r['veces'] . ')' : '')
    . '. Facturación lo ve arriba en su bandeja.');

/* Con la marca del pedido reclamado: la pantalla pinta ahí el botón de
   WhatsApp, que es el que de verdad le llega a facturación. */
ir('/pedidos/por-despachar?wa=' . $id);
