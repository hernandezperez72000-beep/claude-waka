<?php
declare(strict_types=1);

/**
 * La foto de la entrega (3j). Como los vouchers, no se sirve por su
 * dirección: se pregunta antes quién la pide. Almacén, la de su país; el
 * resto, si puede ver el pedido.
 */
$id = pedir_int('id', 'get');
$p = $id && entrega_lista() ? pedido_de($id) : null;
if (!$p || (string)($p['entregado_foto'] ?? '') === '') cortar(404, 'Esa foto no existe');

$u = yo();
if (puede('pedidos.alistar') && (cruza_paises($u) || (int)$p['pais_id'] === (int)$u['pais_id'])) {
    // Almacén (o Administración) de ese país: puede.
} else {
    $equipo_asesor = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
    if (!puede('pedidos.ver')) cortar(403, 'Esto no es para tu perfil');
    exigir_ver((int)$p['asesor_id'], (int)$p['pais_id'], $equipo_asesor !== null ? (int)$equipo_asesor : null);
}

$ruta = ruta_voucher((string)$p['entregado_foto']);
if ($ruta === '') cortar(404, 'Ese archivo ya no está');
$es_pdf = str_ends_with($ruta, '.pdf');
header('Content-Type: ' . ($es_pdf ? 'application/pdf' : 'image/jpeg'));
header('Content-Length: ' . (string)filesize($ruta));
header('Content-Disposition: inline; filename="entrega-' . $p['codigo'] . ($es_pdf ? '.pdf' : '.jpg') . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store');
readfile($ruta);
exit;
