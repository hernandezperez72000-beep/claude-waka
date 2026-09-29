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
/* La vista previa con los bultos (5b), como la del pedido. */
if (pedir('vista', 'get') === '1') {
    $bl = rotulo_bloques((int)$g['pedido_id'], (int)$g['id']);
    if ($bl === null) cortar(404, 'Esa garantía no existe');
    seccion_activa('alistar');
    pagina('pedidos/rotulo_vista', ['bloques' => $bl, 'codigo' => garantia_codigo((int)$g['id']),
        'pdf' => url('/garantias/rotulo?id=' . (int)$g['id']),
        'volver' => volver_de_donde_vino(url('/pedidos/por-alistar'))],
        ['titulo' => 'Rótulo ' . garantia_codigo((int)$g['id']), 'sin_titulo' => true]);
    return;
}
$bultos = max(1, min(50, (int) pedir_int('bultos', 'get', 1)));
$pdf = rotulo_de_pedido((int)$g['pedido_id'], (int)$g['id'], $bultos);
if ($pdf === null) cortar(404, 'Esa garantía no existe');
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (pedir('descargar', 'get') === '1' ? 'attachment' : 'inline') . '; filename="rotulo-' . garantia_codigo((int)$g['id']) . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;
