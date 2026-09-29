<?php
declare(strict_types=1);
/**
 * PONER EL COSTO DEL ENVÍO que quedó pendiente en una pre venta (3j). Desde la
 * ficha del pedido. La regla vive en pedido_flete_poner().
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');
$id = pedir_int('id');
$p  = $id ? pedido_de($id) : null;
if (!$p) cortar(404, 'Ese pedido no existe');
exigir_editar((int)$p['asesor_id'], (int)$p['pais_id']);

$r = pedido_flete_poner($id, a_centimos((string) pedir('flete')) ?? 0);
avisar($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Listo: el costo del envío ya está en el total.' : $r['error']);
ir('/pedidos/ficha?id=' . $id . ($r['ok'] ? '' : '#flete'));
