<?php
declare(strict_types=1);
seccion_activa('stock');

/**
 * EL STOCK DE LA WEB QUE ESPERA (3e). Lo que las ventas tenían que descontar
 * o devolver y la tienda no dejó hacer todavía, y lo que se descontó cuando la
 * web ya no tenía. Solo quien la revisa (tienda.cola, Administración).
 *
 * La cola se reintenta sola; aquí se puede empujar («Reintentar») o, si ya se
 * arregló a mano en la web, darla por resuelta con una nota.
 */
if (!tienda_es_de_mi_pais()) cortar(403, 'La tienda la conectó otro país.');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = pedir('accion');
    $id = pedir_int('id');
    if ($accion === 'reintentar') {
        $ids = $id ? [$id] : array_map('intval', array_column(
            todas("SELECT id FROM stock_web_cola WHERE estado = 'pendiente' ORDER BY proximo_en, id LIMIT 30"), 'id'));
        $n = $ids ? stock_cola_procesar(null, STOCK_COLA_TOPE, $ids) : 0;
        $quedan = stock_cola_pendientes_n();
        avisar($quedan === 0 ? 'ok' : 'info', $quedan === 0 ? 'Listo: la web ya está al día.'
            : ($n > 0 ? 'Se movieron ' . $n . '. ' : '') . 'La tienda todavía no deja mover ' . $quedan . '. Se reintenta sola.');
    } elseif ($accion === 'resolver') {
        $como = pedir('como');
        $r = in_array($como, ['a_mano', 'no_hace_falta'], true)
            ? stock_cola_resolver($id, $como === 'a_mano', (string) pedir('nota'))
            : ['ok' => false, 'error' => 'Elige qué pasó.'];
        avisar($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Dado por resuelto.' : $r['error']);
    } elseif ($accion === 'visto') {
        stock_cola_visto($id);
        avisar('ok', 'Hecho.');
    }
    contadores_olvidar();
    ir('/stock/pendientes');
}

$cols = "c.*, p.codigo AS pedido_codigo";
$pendientes = todas("SELECT $cols FROM stock_web_cola c LEFT JOIN pedidos p ON p.id = c.pedido_id
                      WHERE c.estado = 'pendiente' ORDER BY c.revisar DESC, c.id LIMIT 200");
/* Para revisar: lo ya hecho que dejó algo que mirar (la web en negativo, o la
   tienda lo movió después de darlo por resuelto). Las pendientes que no se
   arreglan solas salen arriba en su propia lista, con su error. */
$revisar = todas("SELECT $cols FROM stock_web_cola c LEFT JOIN pedidos p ON p.id = c.pedido_id
                   WHERE c.revisar = 1 AND c.estado <> 'pendiente' ORDER BY c.id DESC LIMIT 100");

pagina('catalogo/pendientes', [
    'pendientes' => $pendientes,
    'revisar'    => $revisar,
    'control'    => control_stock(),
], ['titulo' => 'Stock por mover en la web', 'migaja' => 'Stock', 'sin_titulo' => true]);
