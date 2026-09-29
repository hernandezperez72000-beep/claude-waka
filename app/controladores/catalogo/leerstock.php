<?php
declare(strict_types=1);
seccion_activa('stock');

/**
 * ACTUALIZAR STOCK (3b.5): lee la tienda y guarda SOLO el stock. Vuelve al
 * catálogo, que es donde está el botón.
 *
 * Ruta propia desde la 3g: vivía dentro de «Traer de la tienda» y solo la
 * podía pulsar quien trae el catálogo (Marketing), no quien lleva el stock
 * (Almacén). Leer no cambia nada en la web.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') ir('/stock');     // el testigo ya lo comprobó index.php
try {
    $r = stock_web_actualizar();
} catch (Throwable $ex) {
    error_log('[HUB stock web] ' . get_class($ex) . ': ' . $ex->getMessage());
    $r = ['ok' => false, 'error' => 'No se pudo leer el stock. Vuelve a intentarlo en un momento.', 'productos' => 0];
}
avisar($r['ok'] ? 'ok' : 'error', $r['ok']
    ? 'Stock actualizado: ' . plural((int)$r['productos'], 'producto', 'productos') . ' con stock de la web.'
    : $r['error']);
ir('/stock');
