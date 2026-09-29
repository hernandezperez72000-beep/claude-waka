<?php
declare(strict_types=1);
seccion_activa('preventa');

/**
 * DISPONIBLE PARA PRE VENTA (3h): lo que el asesor puede vender de los lotes
 * encendidos, ordenado, con precios y lo que queda en la misma pantalla.
 * Lo que no tiene precio no sale (el CEO lo ve avisado en el lote).
 */
if (!preventa_lista()) {
    pagina('pedidos/sinmigrar', [], ['titulo' => 'Pre venta', 'subtitulo' => 'Disponible en cuanto se termine la actualización']);
    return;
}
$q = trim((string) pedir('q', 'get'));
$grupos = preventa_disponible($q !== '' ? $q : null);
foreach ($grupos as &$g) $g['viaje'] = lote_travesia($g['lote']);
unset($g);
pagina('catalogo/preventa', [
    'grupos' => $grupos,
    'q'      => $q,
    'vende'  => puede('pedidos.crear'),
], ['titulo' => 'Disponible para pre venta', 'subtitulo' => 'Lo que viene en camino y ya se puede vender']);
