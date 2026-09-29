<?php
declare(strict_types=1);
seccion_activa(seccion_de_lotes());

/**
 * STOCK › LOTES DE PRE VENTA (3h): los contenedores, con la barra del barco.
 * Los ve quien tiene «lotes.ver» (el CEO, Administración, Almacén); los llena
 * quien tiene «lotes.gestionar». El asesor tiene su propia pantalla:
 * «Disponible para pre venta» (/preventa).
 */
if (!preventa_lista()) {
    pagina('pedidos/sinmigrar', [], ['titulo' => 'Lotes de pre venta', 'subtitulo' => 'Disponible en cuanto se termine la actualización']);
    return;
}
$lotes = lotes_lista();
foreach ($lotes as &$l) {
    $l['viaje'] = lote_travesia($l);
    $l['vendidas'] = (int) valor('SELECT COALESCE(SUM(' . sql_lote_vendidas('ll.id') . '), 0) FROM lote_lineas ll WHERE ll.lote_id = ?', [(int)$l['id']]);
    $l['completitud'] = lote_completitud((int)$l['id']);
}
unset($l);
pagina('catalogo/lotes', [
    'lotes' => $lotes,
    'puedo' => puede('lotes.gestionar'),
], ['titulo' => 'Lotes de pre venta', 'subtitulo' => 'Los contenedores que vienen en camino', 'migaja' => 'Stock y pre venta']);
