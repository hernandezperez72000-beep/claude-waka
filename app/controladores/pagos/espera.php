<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');

/**
 * «Lo miré, todavía no está en el banco.»
 * Ver pago_en_espera() en pagos.php para el porqué.
 */
$id = pedir_int('id');
$pg = $id ? una('SELECT * FROM pagos WHERE id = ?', [$id]) : null;
if (!$pg) cortar(404, 'Ese pago no existe');

$p = pedido_de((int)$pg['pedido_id']);
if (!$p) cortar(404, 'Ese pedido no existe');

// La MISMA puerta que confirmar: quien decide sobre un pago tiene que poder verlo.
$equipo_asesor = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
exigir_ver_para_escribir((int)$p['asesor_id'], (int)$p['pais_id'],
                         $equipo_asesor !== null ? (int)$equipo_asesor : null);

$r = pago_en_espera($id, pedir('nota'));
/* Igual que al confirmar: lo bueno lo cuenta la ventana, y repetirlo aquí era
   la misma frase dos veces en la misma pantalla. */
if (!$r['ok']) avisar('error', $r['error']);

/* Se vuelve a la pantalla de origen y la ventana de «qué acaba de pasar» sale
   encima, como al confirmar. Ver resultado_de_pago(). */
$volver = volver_del_pago(pedir('volver'), (int)$pg['pedido_id']);
ir($volver . (str_contains($volver, '?') ? '&' : '?')
   . 'hecho=' . ($r['ok'] ? 'espera' : 'error') . '&ped=' . (int)$pg['pedido_id']);
