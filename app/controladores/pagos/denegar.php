<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');

/**
 * Facturación deniega un pago: el voucher no es bueno.
 * EL PEDIDO NO SE ANULA — ver pago_denegar() en pagos.php.
 */
$id = pedir_int('id');
$pg = $id ? una('SELECT * FROM pagos WHERE id = ?', [$id]) : null;
if (!$pg) cortar(404, 'Ese pago no existe');

$p = pedido_de((int)$pg['pedido_id']);
if (!$p) cortar(404, 'Ese pedido no existe');

$equipo_asesor = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
exigir_ver_para_escribir((int)$p['asesor_id'], (int)$p['pais_id'],
                         $equipo_asesor !== null ? (int)$equipo_asesor : null);

$r = pago_denegar($id, pedir('motivo'));
/* Igual que al confirmar: lo cuenta la ventana. */
if (!$r['ok']) avisar('error', $r['error']);

/* Igual que confirmar y que dejar en espera: se vuelve a donde se estaba y la
   ventana sale encima. Ver resultado_de_pago(). */
$volver = volver_del_pago(pedir('volver'), (int)$pg['pedido_id']);
ir($volver . (str_contains($volver, '?') ? '&' : '?')
   . 'hecho=' . ($r['ok'] ? 'denegado' : 'error') . '&ped=' . (int)$pg['pedido_id']);
