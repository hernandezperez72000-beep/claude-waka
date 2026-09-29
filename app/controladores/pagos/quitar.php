<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');

$id = pedir_int('id');
$pg = $id ? una('SELECT * FROM pagos WHERE id = ?', [$id]) : null;
if (!$pg) cortar(404, 'Ese pago no existe');

$p = pedido_de((int)$pg['pedido_id']);
if (!$p) cortar(404, 'Ese pedido no existe');
exigir_editar((int)$p['asesor_id'], (int)$p['pais_id']);

$r = pago_anular($id, pedir('motivo') ?: 'Se registró mal');
avisar($r['ok'] ? 'ok' : 'error',
       $r['ok'] ? 'El pago se quitó. Como no estaba validado, no contaba para nada.' : $r['error']);
ir('/pedidos/ficha?id=' . (int)$pg['pedido_id']);
