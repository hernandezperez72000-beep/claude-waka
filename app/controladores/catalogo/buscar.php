<?php
declare(strict_types=1);

/**
 * El buscador de producto del formulario del pedido. Devuelve JSON, como el de
 * clientes: escribir dos letras y ver lo que hay, sin recargar la pantalla.
 */
header('Content-Type: application/json; charset=utf-8');
/* `control`: con el control de stock encendido, una venta de entrega inmediata
   no puede llevar lo agotado (3e). La pantalla no deja elegirlo; el servidor
   lo frena igual al guardar. */
/* PRE VENTA (3h): con un lote a la venta, se busca en los lotes. */
$en_preventa = pedir('tipo', 'get') === 'preventa' && preventa_con_lotes();
/* Los repuestos no se venden en pre venta (3i): con «Pre venta» marcada no salen. */
echo json_encode(['r' => $en_preventa ? preventa_buscar((string) pedir('q', 'get'), 8)
                                      : catalogo_buscar((string) pedir('q', 'get'), 8, pedir('tipo', 'get') === 'preventa'),
                  'preventa' => $en_preventa,
                  'control' => venta_descuenta_web('inmediata', catalogo_pais())], JSON_UNESCAPED_UNICODE);
