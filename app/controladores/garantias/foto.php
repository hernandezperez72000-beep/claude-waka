<?php
declare(strict_types=1);

/**
 * LAS FOTOS DE UNA GARANTÍA (3i): la de la falla (?id= de la foto) y la de lo
 * alistado (?g=&alistado=1). Como los vouchers, no se sirven por su dirección:
 * se pregunta antes quién las pide. Almacén, las de su país; el resto, si
 * puede ver la garantía.
 */
if (!garantias_listo()) cortar(404, 'Esa foto no existe');
$fid = pedir_int('id', 'get');
$gid = pedir_int('g', 'get');
$archivo = '';
if ($fid) {
    $f = una('SELECT * FROM garantia_fotos WHERE id = ?', [$fid]);
    if (!$f) cortar(404, 'Esa foto no existe');
    $gid = (int)$f['garantia_id'];
    $archivo = (string)$f['archivo'];
}
$g = $gid ? garantia_de($gid) : null;
if (!$g) cortar(404, 'Esa foto no existe');
if (!$fid) {
    if (pedir('alistado', 'get') !== '1' || (string)($g['alistado_foto'] ?? '') === '') cortar(404, 'Esa foto no existe');
    $archivo = (string)$g['alistado_foto'];
}

$u = yo();
if (puede('pedidos.alistar') && (cruza_paises($u) || (int)$g['pais_id'] === (int)$u['pais_id'])) {
    // Almacén (o Administración) de ese país: puede.
} else {
    if (!puede('garantias.ver')) cortar(403, 'Esto no es para tu perfil');
    exigir_ver((int)$g['asesor_id'], (int)$g['pais_id'], $g['asesor_equipo'] !== null ? (int)$g['asesor_equipo'] : null);
}

$ruta = ruta_voucher($archivo);
if ($ruta === '') cortar(404, 'Ese archivo ya no está');
header('Content-Type: image/jpeg');
header('Content-Length: ' . (string)filesize($ruta));
header('Content-Disposition: inline; filename="' . garantia_codigo($gid) . ($fid ? '-falla' : '-alistado') . '.jpg"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store');
readfile($ruta);
exit;
