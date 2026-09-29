<?php
declare(strict_types=1);

/**
 * Sirve la boleta o la factura ya emitida.
 *
 * Mismo trato que el voucher, y por la misma razón: un comprobante lleva el
 * nombre, el documento y la dirección del cliente y el detalle de lo que
 * compró. Vive en una carpeta cerrada y se pasa por aquí, donde se pregunta
 * primero quién está pidiendo.
 */
$id = pedir_int('id', 'get');
$p  = $id ? pedido_de($id) : null;
if (!$p || empty($p['comprobante_archivo'])) cortar(404, 'Ese comprobante no existe');

$equipo_asesor = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
exigir_ver((int)$p['asesor_id'], (int)$p['pais_id'],
           $equipo_asesor !== null ? (int)$equipo_asesor : null);

/* El nombre se comprueba contra un patrón cerrado y NUNCA se concatena crudo:
   es el mismo cuidado que con los vouchers — un «../../config.php» guardado en
   esa columna serviría el archivo de configuración. */
$nombre = (string)$p['comprobante_archivo'];
if (!preg_match('/^c\d{8}-[a-f0-9]{1,16}\.(pdf|jpg|png)$/', $nombre)) {
    cortar(404, 'Ese comprobante no existe');
}
$ruta = HUB_SUBIDAS . '/comprobantes/' . $nombre;
if (!is_file($ruta)) cortar(404, 'Ese archivo ya no está');

$ext  = pathinfo($nombre, PATHINFO_EXTENSION);
$tipo = $ext === 'pdf' ? 'application/pdf' : ($ext === 'png' ? 'image/png' : 'image/jpeg');

$como = trim((string)($p['comprobante_tipo'] ?? 'comprobante')) ?: 'comprobante';
header('Content-Type: ' . $tipo);
header('Content-Length: ' . (string)filesize($ruta));
header('Content-Disposition: inline; filename="' . $como . '-' . $p['codigo'] . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store');
readfile($ruta);
exit;
