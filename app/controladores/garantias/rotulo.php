<?php
declare(strict_types=1);

/**
 * EL RÓTULO DE UNA GARANTÍA (3i): el del pedido, con sus piezas por contenido.
 * Solo de una garantía aprobada: una caja con rótulo es una caja que sale.
 */
$id = pedir_int('id', 'get');
$g = $id ? garantia_de($id) : null;
if (!$g) cortar(404, 'Esa garantía no existe');
$u = yo();
if (!cruza_paises($u) && (int)$g['pais_id'] !== (int)$u['pais_id']) cortar(404, 'Esa garantía no existe');
if ($g['estado'] !== 'aprobada') cortar(409, 'Esta garantía no está aprobada', 'El rótulo sale cuando Administración la aprueba.');
$pdf = rotulo_de_pedido((int)$g['pedido_id'], (int)$g['id']);
if ($pdf === null) cortar(404, 'Esa garantía no existe');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="rotulo-' . garantia_codigo((int)$g['id']) . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;
