<?php
declare(strict_types=1);

/**
 * Sirve el voucher.
 *
 * Los vouchers NO se sirven por su dirección: viven en uploads/vouchers/, que
 * el .htaccess deja cerrada. Llevan el nombre del cliente, su banco y el monto,
 * y una carpeta pública con nombres adivinables sería un fichero de datos de
 * pago colgando de internet. Se pasa por aquí, y aquí se pregunta primero
 * quién está pidiendo.
 */
$id = pedir_int('id', 'get');
$pg = $id ? una('SELECT * FROM pagos WHERE id = ?', [$id]) : null;
/* La foto del DNI (3f) sale por aquí también, con las mismas preguntas. */
$es_dni = pedir('que', 'get') === 'dni';
$archivo = $pg ? (string)($es_dni ? ($pg['foto_dni'] ?? '') : ($pg['voucher'] ?? '')) : '';
if ($archivo === '') cortar(404, $es_dni ? 'Esa foto del DNI no existe' : 'Ese voucher no existe');

$p = pedido_de((int)$pg['pedido_id']);
if (!$p) cortar(404, 'Ese pedido no existe');

$equipo_asesor = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
exigir_ver((int)$p['asesor_id'], (int)$p['pais_id'],
           $equipo_asesor !== null ? (int)$equipo_asesor : null);

$ruta = ruta_voucher($archivo);
if ($ruta === '') cortar(404, 'Ese archivo ya no está');

$es_pdf = str_ends_with($ruta, '.pdf');
header('Content-Type: ' . ($es_pdf ? 'application/pdf' : 'image/jpeg'));
header('Content-Length: ' . (string)filesize($ruta));
header('Content-Disposition: inline; filename="' . ($es_dni ? 'dni-' : 'voucher-') . $p['codigo'] . ($es_pdf ? '.pdf' : '.jpg') . '"');
header('X-Content-Type-Options: nosniff');
// Es un dato de una persona: no se guarda en ninguna caché compartida.
header('Cache-Control: private, max-age=0, no-store');
readfile($ruta);
exit;
