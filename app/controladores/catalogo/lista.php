<?php
declare(strict_types=1);
seccion_activa('stock');

/**
 * EL CATÁLOGO — la pantalla de la sección «Stock y pre venta» (3a-1).
 *
 * Es lo primero que se construye del módulo 3: sin productos no hay stock que
 * contar ni lote que cargar. La pre venta y los lotes llegan en la siguiente
 * entrega, y esta pantalla lo dice en vez de callárselo.
 */
if (!tabla_existe('productos')) {
    pagina('catalogo/lista', ['productos' => [], 'q' => '', 'ver' => 'todos', 'sin_precio' => 0,
                              'cuantos' => 0, 'puedo_tocar' => false, 'falta_actualizar' => true,
                              'precios' => [], 'variantes' => [], 'stock' => [], 'stock_leido' => '',
                              'tienda_lista' => false],
           ['titulo' => 'Stock y pre venta', 'sin_titulo' => true]);
    return;
}
$q      = trim((string) pedir('q', 'get'));
$ver    = (string) pedir('v', 'get', 'todos');
$con_rep = repuestos_listo();
if (!in_array($ver, ['todos', 'sin_precio', 'repuestos', 'apagados'], true) || ($ver === 'repuestos' && !$con_rep)) $ver = 'todos';

$donde = [];
$par   = [];
$donde[] = sql_producto_de_mi_pais();
if ($q !== '') {
    /* Buscar la máquina trae también sus repuestos (3i). */
    $donde[] = "(p.nombre LIKE ? OR p.sku LIKE ? OR COALESCE(p.categoria,'') LIKE ?"
             . ($con_rep ? ' OR pm.nombre LIKE ? OR pm.sku LIKE ?' : '') . ')';
    $like = '%' . catalogo_escapar_like($q) . '%';
    array_push($par, $like, $like, $like);
    if ($con_rep) array_push($par, $like, $like);
}
if ($ver === 'apagados')   $donde[] = 'p.activo = 0';
else                        $donde[] = 'p.activo = 1';
if ($ver === 'sin_precio') {
    $donde[] = "NOT EXISTS (SELECT 1 FROM precios x WHERE x.producto_id = p.id AND x.activo = 1 AND x.lote_id IS NULL)";
    $donde[] = 'NOT ' . sql_producto_solo_preventa('p');     // su precio es el del lote (3h)
    if ($con_rep) $donde[] = 'p.padre_id IS NULL';           // un repuesto sin precio sale por garantía (3i)
}
if ($ver === 'repuestos') $donde[] = 'p.padre_id IS NOT NULL';

$productos = todas('SELECT p.*' . ($con_rep ? ', pm.nombre AS maquina_nombre' : '') . ' FROM productos p '
                 . ($con_rep ? 'LEFT JOIN productos pm ON pm.id = p.padre_id ' : '')
                 . 'WHERE ' . implode(' AND ', $donde)
                 . ($con_rep && $ver === 'repuestos' ? ' ORDER BY pm.nombre, p.nombre' : ' ORDER BY p.nombre') . ' LIMIT 300', $par);

/* El precio y los modelos de TODA la lista en dos consultas, no en dos por
   fila: con trescientos productos eran novecientas (auditoría del 3a-1). */
$ids = array_map(fn($x) => (int)$x['id'], $productos);
$precios = $variantes = [];
if ($ids) {
    $en = implode(',', array_fill(0, count($ids), '?'));
    foreach (todas("SELECT producto_id, MAX(precio_centimos) AS base FROM precios
                     WHERE activo = 1 AND lote_id IS NULL AND variante_id IS NULL
                       AND producto_id IN ($en) GROUP BY producto_id", $ids) as $f) {
        $precios[(int)$f['producto_id']] = (int)$f['base'];
    }
    foreach (todas("SELECT producto_id, color, medida FROM variantes
                     WHERE activo = 1 AND producto_id IN ($en) ORDER BY id", $ids) as $f) {
        $variantes[(int)$f['producto_id']][] = variante_nombre($f);
    }
}

pagina('catalogo/lista', [
    'productos'   => $productos,
    /* El stock de la web de toda la lista en una consulta (3b.5). */
    'stock'       => stock_web_de($ids),
    /* El de los repuestos que lleva el HUB (3i). */
    'stock_hub'   => $con_rep ? stock_hub_de(array_map(fn($x) => (int)$x['id'], array_filter($productos, 'stock_hub_aplica'))) : [],
    'con_rep'     => $con_rep,
    'stock_leido' => stock_web_leido_en(catalogo_pais()),
    'control'     => control_stock(),
    'cola_atencion' => puede('tienda.cola') ? stock_cola_atencion() : 0,
    'tienda_lista' => tienda_config()['listo'] && tienda_es_de_mi_pais(),
    'precios'     => $precios,
    /* Los que solo son de pre venta: su precio está en el lote (3h). */
    'de_preventa' => $ids ? array_map('intval', array_column(todas('SELECT p.id FROM productos p WHERE p.id IN ('
                        . implode(',', array_map('intval', $ids)) . ') AND ' . sql_producto_solo_preventa('p')), 'id')) : [],
    'variantes'   => $variantes,
    'falta_actualizar' => false,
    'q'           => $q,
    'ver'         => $ver,
    'sin_precio'  => catalogo_sin_precio(),
    'cuantos'     => (int) valor('SELECT COUNT(*) FROM productos WHERE activo = 1'),
    'puedo_tocar' => puede('catalogo.gestionar'),
    'puedo_leer_stock' => puede('stock.ajustar') || puede('catalogo.gestionar'),
], ['titulo' => 'Stock y pre venta', 'sin_titulo' => true]);
