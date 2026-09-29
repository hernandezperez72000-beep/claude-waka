<?php
declare(strict_types=1);
/**
 * EL CONECTOR, DE PUNTA A PUNTA (3c).
 *
 * El HUB firma y manda; el plugin «Waka HUB · Conector» (wordpress/…) comprueba
 * y cambia. Aquí corre el plugin DE VERDAD sobre un WordPress de mentira
 * (wp_falso.php): si el HUB y el plugin no firmaran igual, o el plugin dejara
 * pasar algo que no debe, aquí se ve.
 */
require_once __DIR__ . '/comun.php';
banco_crear(sys_get_temp_dir() . '/waka-pruebas6.sqlite');
require_once __DIR__ . '/wp_falso.php';

$ADMIN = banco_usuario('administracion');
banco_entrar($ADMIN);
$PERU = catalogo_pais();
guardar_ajuste('woo_url', 'https://compraenwaka.test');
guardar_ajuste('woo_key', 'ck_' . str_repeat('a1', 20));
guardar_ajuste('woo_secret', 'cs_' . str_repeat('b2', 20));
guardar_ajuste('woo_pais', (string)$PERU);
ajustes_olvidar();
$GLOBALS['__conector_transporte'] = wp_falso_transporte();

/* La tienda de mentira: un producto simple y uno con dos colores. */
$wp_producto = function (array $d) { $GLOBALS['__wp']['productos'][$d['id']] = (new ProductoFalso($d))->d; };
$wp_producto(['id' => 5001, 'nombre' => 'Silla simple', 'sku' => 'PRD-500100', 'precio' => '100.00', 'gestiona' => true, 'stock' => 3]);
$wp_producto(['id' => 5002, 'tipo' => 'variable', 'nombre' => 'Mesa colores', 'sku' => 'PRD-500200']);
$wp_producto(['id' => 50021, 'tipo' => 'variation', 'padre' => 5002, 'sku' => 'PRD-500201', 'precio' => '50.00', 'gestiona' => true, 'stock' => 4]);
$wp_producto(['id' => 50022, 'tipo' => 'variation', 'padre' => 5002, 'sku' => 'PRD-500202', 'precio' => '50.00', 'gestiona' => true, 'stock' => 0, 'estado' => 'outofstock']);
$wp_producto(['id' => 5003, 'nombre' => 'Otro', 'sku' => 'PRD-500300', 'precio' => '9.00']);
$wp = fn(int $id) => $GLOBALS['__wp']['productos'][$id];

grupo('3c · la clave y la firma');
es('sin clave aquí, no se llama', false, conector_config()['listo']);
ok('y lo dice', str_contains(conector_llamar('GET', '/estado')['error'], 'clave del conector'));
$CLAVE = bin2hex(random_bytes(24));
ok('la clave que genera el plugin tiene la forma que se pide aquí', conector_clave_valida($CLAVE));
ok('una con mayúsculas o corta no', !conector_clave_valida(strtoupper($CLAVE)) && !conector_clave_valida('abc'));
guardar_ajuste('conector_clave', $CLAVE); ajustes_olvidar();
$pr = conector_probar();
ok('SIN CLAVE EN WORDPRESS, LA TIENDA NO HACE CASO', !$pr['ok'] && str_contains($pr['texto'], 'no aceptó la clave'), $pr['texto']);
update_option(WAKA_HUB_OPCION, $CLAVE);
$pr = conector_probar();
ok('CON LA MISMA CLAVE EN LOS DOS LADOS, FUNCIONA', $pr['ok'], $pr['texto']);
ok('y dice la versión del conector', str_contains($pr['texto'], WAKA_HUB_VERSION));
update_option(WAKA_HUB_OPCION, bin2hex(random_bytes(24)));
ok('con otra clave en WordPress, no', !conector_probar()['ok']);
update_option(WAKA_HUB_OPCION, $CLAVE);

/* La firma, contra el plugin directamente. */
$pedir = function (string $metodo, string $ruta, string $cuerpo, int $fecha, ?string $clave = null) use ($CLAVE) {
    $f = conector_firma($clave ?? $CLAVE, (string)$fecha, $metodo, $ruta, $cuerpo);
    return new WP_REST_Request($metodo, $ruta, ['x-waka-fecha' => (string)$fecha, 'x-waka-firma' => $f], $cuerpo);
};
ok('una petición bien firmada pasa', waka_hub_firma_valida($pedir('POST', '/waka-hub/v1/producto', '{"a":1}', time())));
ok('UNA PETICIÓN DE HACE 10 MINUTOS NO', !waka_hub_firma_valida($pedir('POST', '/waka-hub/v1/producto', '{"a":1}', time() - 600)));
ok('ni una del futuro', !waka_hub_firma_valida($pedir('POST', '/waka-hub/v1/producto', '{"a":1}', time() + 600)));
$r0 = $pedir('POST', '/waka-hub/v1/producto', '{"a":1}', time());
ok('SI ALGUIEN CAMBIA EL CUERPO POR EL CAMINO, NO', !waka_hub_firma_valida(new WP_REST_Request('POST', '/waka-hub/v1/producto',
   ['x-waka-fecha' => $r0->get_header('x-waka-fecha'), 'x-waka-firma' => $r0->get_header('x-waka-firma')], '{"a":2}')));
ok('ni si la manda a otra ruta', !waka_hub_firma_valida(new WP_REST_Request('POST', '/waka-hub/v1/stock/mover',
   ['x-waka-fecha' => $r0->get_header('x-waka-fecha'), 'x-waka-firma' => $r0->get_header('x-waka-firma')], '{"a":1}')));
ok('ni sin firma', !waka_hub_firma_valida(new WP_REST_Request('POST', '/waka-hub/v1/producto', [], '{"a":1}')));
delete_option(WAKA_HUB_OPCION);
ok('SIN CLAVE EN WORDPRESS, NI FIRMANDO CON UNA VACÍA', !waka_hub_firma_valida($pedir('POST', '/waka-hub/v1/producto', '{}', time(), '')));
update_option(WAKA_HUB_OPCION, $CLAVE);
es('la tienda sin el plugin: lo dice', 'En el WordPress de la tienda falta activar o actualizar «Waka HUB · Conector».',
   conector_error_texto(404, ['code' => 'rest_no_route']));

grupo('3c · lo que el plugin deja cambiar, y lo que no');
es('nombre y código', true, tienda_web_mandar_datos(5001, 'Silla simple NUEVA', 'PRD-500100X')['ok']);
es('la web los tiene', ['Silla simple NUEVA', 'PRD-500100X'], [$wp(5001)['nombre'], $wp(5001)['sku']]);
$dup = tienda_web_mandar_datos(5001, 'Silla', 'PRD-500300');
ok('UN CÓDIGO QUE YA TIENE OTRO PRODUCTO DE LA WEB, NO', !$dup['ok'] && str_contains($dup['error'], 'ya lo tiene otro producto'), $dup['error']);
es('y no se cambió nada', ['Silla simple NUEVA', 'PRD-500100X'], [$wp(5001)['nombre'], $wp(5001)['sku']]);
$r = conector_llamar('POST', '/producto', ['woo_id' => 5001, 'variacion_id' => 50021, 'stock' => 9]);
ok('UN COLOR QUE NO ES DE ESE PRODUCTO, NO', !$r['ok'] && $r['http'] === 404, $r['error']);
es('y su stock sigue', 4, $wp(50021)['stock']);
ok('un precio raro no', !conector_llamar('POST', '/producto', ['woo_id' => 5001, 'precio' => '12,50'])['ok']);
ok('ni stock negativo', !conector_llamar('POST', '/producto', ['woo_id' => 5001, 'stock' => -1])['ok']);
ok('ni el precio en el producto con colores (va en cada color)', !conector_llamar('POST', '/producto', ['woo_id' => 5002, 'precio' => '10.00'])['ok']);
ok('ni el nombre en un color', !conector_llamar('POST', '/producto', ['woo_id' => 5002, 'variacion_id' => 50021, 'nombre' => 'X'])['ok']);
ok('ni un código con espacios', !conector_llamar('POST', '/producto', ['woo_id' => 5001, 'sku' => 'PRD 1'])['ok']);
ok('un producto que no existe, no', conector_llamar('POST', '/producto', ['woo_id' => 999, 'stock' => 1])['http'] === 404);
ok('el stock de un color sí', conector_llamar('POST', '/producto', ['woo_id' => 5002, 'variacion_id' => 50022, 'stock' => 6])['ok']
   && $wp(50022)['stock'] === 6 && $wp(50022)['estado'] === 'instock');

grupo('3c · mover el stock (para vender, en la 3c.2)');
$mov = fn(string $clave, array $movs, bool $exigir = true) => conector_llamar('POST', '/stock/mover',
    ['clave' => $clave, 'movimientos' => $movs, 'exigir' => $exigir]);
$m1 = $mov('venta-1', [['woo_id' => 5001, 'cantidad' => -2], ['woo_id' => 5002, 'variacion_id' => 50021, 'cantidad' => -1]]);
ok('una venta descuenta', $m1['ok'] && $wp(5001)['stock'] === 1 && $wp(50021)['stock'] === 3, $m1['error']);
$c0 = $GLOBALS['__wp']['candado'];
$m1b = $mov('venta-1', [['woo_id' => 5001, 'cantidad' => -2], ['woo_id' => 5002, 'variacion_id' => 50021, 'cantidad' => -1]]);
ok('LA MISMA VENTA DOS VECES (se cortó la conexión) NO DESCUENTA DOS VECES', $m1b['ok'] && !empty($m1b['datos']['repetida'])
   && $wp(5001)['stock'] === 1 && $wp(50021)['stock'] === 3);
$m2 = $mov('venta-2', [['woo_id' => 5002, 'variacion_id' => 50021, 'cantidad' => -1], ['woo_id' => 5001, 'cantidad' => -5]]);
ok('SIN STOCK SUFICIENTE, NO DESCUENTA NADA (ni lo que sí había)', !$m2['ok'] && $m2['http'] === 409
   && $wp(50021)['stock'] === 3 && $wp(5001)['stock'] === 1, json_encode($m2['datos']));
es('y dice cuál falta, cuánto hay y cuánto se pedía', [['id' => 5001, 'hay' => 1, 'pide' => 5]], $m2['datos']['faltan'] ?? null);
ok('con candado: dos ventas a la vez no se llevan la misma unidad', $GLOBALS['__wp']['candado'] > $c0);
$m3 = $mov('anula-1', [['woo_id' => 5001, 'cantidad' => 2]]);
ok('una anulación devuelve', $m3['ok'] && $wp(5001)['stock'] === 3);
$m4 = $mov('venta-3', [['woo_id' => 5003, 'cantidad' => -1]]);
ok('un producto sin inventario en la web no se toca y no frena', $m4['ok'] && $wp(5003)['stock'] === null);
$GLOBALS['__wp']['sin_candado'] = true;
$m5 = $mov('venta-4', [['woo_id' => 5001, 'cantidad' => -1]]);
ok('si no consigue el candado, no toca nada y lo dice', !$m5['ok'] && $m5['http'] === 503 && $wp(5001)['stock'] === 3);
unset($GLOBALS['__wp']['sin_candado']);
ok('una petición sin clave de repetición no vale', !conector_llamar('POST', '/stock/mover', ['movimientos' => []])['ok']);

/* Lo que encontró la auditoría del 3c. */
$GLOBALS['__wp']['productos'][5001]['stock'] = 3;
$ma = $mov('venta-5', [['woo_id' => 5001, 'cantidad' => -2], ['woo_id' => 5001, 'cantidad' => -2]]);
ok('DOS LÍNEAS DEL MISMO PRODUCTO SE SUMAN: 2 + 2 con 3 no pasa', !$ma['ok'] && $wp(5001)['stock'] === 3, json_encode($ma['datos']));
/* Dos colores que usan las unidades del producto. */
$wp_producto(['id' => 5004, 'tipo' => 'variable', 'nombre' => 'Compartido', 'sku' => 'PRD-500400', 'gestiona' => true, 'stock' => 5]);
$wp_producto(['id' => 50041, 'tipo' => 'variation', 'padre' => 5004, 'sku' => 'PRD-500401', 'precio' => '10.00']);
$wp_producto(['id' => 50042, 'tipo' => 'variation', 'padre' => 5004, 'sku' => 'PRD-500402', 'precio' => '10.00']);
$mb = $mov('venta-6', [['woo_id' => 5004, 'variacion_id' => 50041, 'cantidad' => -3], ['woo_id' => 5004, 'variacion_id' => 50042, 'cantidad' => -3]]);
ok('DOS COLORES QUE COMPARTEN 5 UNIDADES: 3 + 3 NO PASA', !$mb['ok'] && $wp(5004)['stock'] === 5, json_encode($mb['datos']));
$mc = $mov('venta-7', [['woo_id' => 5004, 'variacion_id' => 50041, 'cantidad' => -3], ['woo_id' => 5004, 'variacion_id' => 50042, 'cantidad' => -2]]);
ok('3 + 2 sí, y se descuenta del producto', $mc['ok'] && $wp(5004)['stock'] === 0, json_encode($mc['datos']));
$md = $mov('venta-7', [['woo_id' => 5001, 'cantidad' => 50]]);
ok('LA MISMA CLAVE CON OTROS DATOS SE NIEGA (no se traga el cambio)', !$md['ok'] && ($md['datos']['codigo'] ?? '') === 'clave_usada' && $wp(5001)['stock'] === 3);
$GLOBALS['__wp']['transitorios'] = [];
$me = $mov('venta-1', [['woo_id' => 5001, 'cantidad' => -2], ['woo_id' => 5002, 'variacion_id' => 50021, 'cantidad' => -1]]);
ok('LO YA HECHO NO SE OLVIDA AUNQUE SE VACÍE LA CACHÉ DE WORDPRESS', $me['ok'] && !empty($me['datos']['repetida']) && $wp(5001)['stock'] === 3);
es('CADA CANDADO SE SUELTA, también cuando se niega', $GLOBALS['__wp']['candado'], $GLOBALS['__wp']['soltado'] ?? 0);
$mf = $mov('venta-8', [['woo_id' => 5001, 'cantidad' => -1000000]]);
ok('una cantidad sin sentido no vale', !$mf['ok']);

/* El lote: todos o ninguno. */
$lo = conector_llamar('POST', '/lote', ['cambios' => [['woo_id' => 5002, 'variacion_id' => 50021, 'precio' => '71.00'],
                                                      ['woo_id' => 5002, 'variacion_id' => 50022, 'precio' => '0.00']]]);
ok('UN LOTE CON UN PRECIO QUE NO VALE NO CAMBIA NINGUNO', !$lo['ok'] && $wp(50021)['precio'] === '50.00');
ok('ni con precio cero', str_contains($lo['error'], 'precio'));
$lo2 = conector_llamar('POST', '/lote', ['cambios' => [['woo_id' => 5002, 'variacion_id' => 50021, 'precio' => '71.00'],
                                                       ['woo_id' => 5002, 'variacion_id' => 50022, 'precio' => '72.00']]]);
ok('un lote bueno cambia todos', $lo2['ok'] && $wp(50021)['precio'] === '71.00' && $wp(50022)['precio'] === '72.00');
/* Un producto en la papelera no se toca. */
$wp_producto(['id' => 5005, 'nombre' => 'Borrado', 'sku' => 'PRD-500500', 'status' => 'trash']);
ok('uno en la papelera, no', conector_llamar('POST', '/producto', ['woo_id' => 5005, 'stock' => 1])['http'] === 404);
/* El nombre como lo guarda la web. */
$nd = tienda_web_mandar_datos(5003, 'Otro   con  espacios', null);
es('LA WEB LIMPIA EL NOMBRE Y SE SABE CÓMO QUEDÓ', 'Otro con espacios', $nd['nombre']);
es('y no toca el código si no se pide', 'PRD-500300', $wp(5003)['sku']);
$nd2 = tienda_web_mandar_datos(5003, 'Mesa & Silla', null);
es('«&» VUELVE COMO «&» Y NO COMO «&amp;»', 'Mesa & Silla', $nd2['nombre']);
ok('(la web lo guarda como «&amp;»)', $wp(5003)['nombre'] === 'Mesa &amp; Silla');
$lo3 = conector_llamar('POST', '/lote', ['cambios' => [['woo_id' => 5001, 'sku' => 'PRD-IGUAL'], ['woo_id' => 5003, 'sku' => 'PRD-IGUAL']]]);
ok('UN LOTE QUE PONE EL MISMO CÓDIGO A DOS SE NIEGA ANTES DE CAMBIAR NADA', !$lo3['ok'] && $wp(5001)['sku'] === 'PRD-500100X');
ok('y el mismo producto dos veces en un lote, también', !conector_llamar('POST', '/lote', ['cambios' => [['woo_id' => 5001, 'stock' => 1], ['woo_id' => 5001, 'stock' => 2]]])['ok']);
/* Un conector viejo. */
$v_ok = WAKA_HUB_VERSION;
$GLOBALS['__conector_transporte_real'] = $GLOBALS['__conector_transporte'];
$GLOBALS['__conector_transporte'] = fn($m, $u, $c, $b) => ['http' => 200, 'cuerpo' => json_encode(['ok' => true, 'version' => '1.0.0']), 'red' => ''];
$pv0 = conector_probar();
ok('UN CONECTOR VIEJO SE DICE: hay que actualizarlo', !$pv0['ok'] && str_contains($pv0['texto'], 'es viejo'), $pv0['texto']);
$GLOBALS['__conector_transporte'] = $GLOBALS['__conector_transporte_real'];
ok('el que se descarga es el de ahora', conector_probar()['ok']);
/* EL ZIP QUE SE DESCARGA ES EL PLUGIN DE AHORA, no uno viejo. */
$zip = dirname(__DIR__) . '/assets/descargas/waka-hub-conector.zip';
$dentro = class_exists('ZipArchive') ? (function () use ($zip) { $z = new ZipArchive(); $z->open($zip); $t = $z->getFromName('waka-hub-conector/waka-hub-conector.php'); $z->close(); return $t; })()
                                      : (string) shell_exec('unzip -p ' . escapeshellarg($zip) . ' waka-hub-conector/waka-hub-conector.php');
/* (Las pruebas de mutación cambian el plugin a propósito: ahí esta no cuenta,
   o cazaría TODAS las mutaciones del plugin sin probar nada de lo demás.) */
if (!getenv('WAKA_MUTANDO')) {
    ok('EL ZIP PARA DESCARGAR LLEVA EL PLUGIN DE AHORA', $dentro === file_get_contents(dirname(__DIR__) . '/wordpress/waka-hub-conector/waka-hub-conector.php'),
       'hay que volver a armar assets/descargas/waka-hub-conector.zip');
}
es('una 401 que no es de la clave dice otra cosa', true,
   str_contains(conector_error_texto(401, ['code' => 'rest_not_logged_in']), 'complemento de seguridad'));
es('una firma mala sí habla de la clave y de la hora', true,
   str_contains(conector_error_texto(401, ['code' => 'waka_firma']), 'hora de la tienda'));

grupo('3c · desde la ficha: precio de 1 unidad y stock');
$ps = producto_guardar(null, ['nombre' => 'Silla simple NUEVA', 'sku' => 'PRD-500100X', 'activo' => 1]);
actualizar('productos', (int)$ps['id'], ['woo_id' => 5001, 'pais_id' => $PERU]);
precios_guardar((int)$ps['id'], null, [['desde' => 1, 'hasta' => '2', 'precio' => '120.00'], ['desde' => 3, 'hasta' => '', 'precio' => '110.00']]);
$pv = producto_guardar(null, ['nombre' => 'Mesa colores', 'sku' => 'PRD-500200', 'activo' => 1]);
actualizar('productos', (int)$pv['id'], ['woo_id' => 5002, 'pais_id' => $PERU]);
variante_guardar((int)$pv['id'], ['color' => 'Rojo']);
variante_guardar((int)$pv['id'], ['color' => 'Azul']);
$vids = array_map('intval', array_column(todas('SELECT id FROM variantes WHERE producto_id = ? ORDER BY id', [(int)$pv['id']]), 'id'));
actualizar('variantes', $vids[0], ['woo_id' => 50021]);
actualizar('variantes', $vids[1], ['woo_id' => 50022]);
precios_guardar((int)$pv['id'], null, [['desde' => 1, 'hasta' => '', 'precio' => '55.50']]);
es('el precio que va a la web es el de 1 unidad', [['woo_id' => 5001, 'variacion_id' => 0, 'precio' => 12000]], tienda_web_precios_de((int)$ps['id']));
es('en uno con colores, en cada color', [50021, 50022], array_column(tienda_web_precios_de((int)$pv['id']), 'variacion_id'));
ok('se manda', tienda_web_mandar_precio((int)$ps['id'])['ok'] && $wp(5001)['precio'] === '120.00');
ok('y en cada color', tienda_web_mandar_precio((int)$pv['id'])['ok'] && $wp(50021)['precio'] === '55.50' && $wp(50022)['precio'] === '55.50');
$antes_pw = tienda_web_precios_de((int)$pv['id']);
$n_llam = count($GLOBALS['__wp']['llamadas'] ?? []);
es('SI NO CAMBIÓ EL PRECIO, NO SE LLAMA A LA WEB', 0, tienda_web_mandar_precio((int)$pv['id'], $antes_pw)['cambiados']);
es('ni una petición', $n_llam, count($GLOBALS['__wp']['llamadas'] ?? []));
precios_guardar((int)$pv['id'], null, [['desde' => 1, 'hasta' => '', 'precio' => '60.00']]);
$mp = tienda_web_mandar_precio((int)$pv['id'], $antes_pw);
ok('los colores van en UNA sola petición', $mp['ok'] && $mp['cambiados'] === 2 && count($GLOBALS['__wp']['llamadas']) === $n_llam + 1
   && str_ends_with((string) end($GLOBALS['__wp']['llamadas'])[1], '/lote'));
/* Con todos los colores apagados aquí, no hay a dónde mandar el precio. */
q('UPDATE variantes SET activo = 0 WHERE producto_id = ?', [(int)$pv['id']]);
es('UN PRODUCTO CON COLORES, TODOS APAGADOS AQUÍ: NO SE MANDA AL PRODUCTO', [], tienda_web_precios_de((int)$pv['id']));
q('UPDATE variantes SET activo = 1 WHERE producto_id = ?', [(int)$pv['id']]);

$st1 = tienda_web_poner_stock((int)$ps['id'], 0, 7, 'Conteo');
ok('poner el stock: la web lo tiene', $st1['ok'] && $wp(5001)['stock'] === 7, $st1['error']);
es('y aquí se ve al momento', 7, stock_web_de([(int)$ps['id']])[(int)$ps['id']]['cantidad'] ?? -1);
ok('queda quién, cuándo, el antes, el después y el motivo', (bool) valor(
   "SELECT 1 FROM bitacora WHERE accion = 'tienda.stock' AND entidad_id = ? AND detalle LIKE '%Conteo%' AND detalle LIKE '%\"ahora\":7%' AND usuario_id = ?",
   [(int)$ps['id'], $ADMIN]));
ok('SIN MOTIVO NO', !tienda_web_poner_stock((int)$ps['id'], 0, 5, '  ')['ok'] && $wp(5001)['stock'] === 7);
ok('ni con cantidad negativa', !tienda_web_poner_stock((int)$ps['id'], 0, -1, 'x')['ok']);
ok('el stock de un color', tienda_web_poner_stock((int)$pv['id'], $vids[1], 2, 'Llegaron')['ok'] && $wp(50022)['stock'] === 2);
ok('un color de otro producto, no', !tienda_web_poner_stock((int)$ps['id'], $vids[0], 1, 'x')['ok']);
/* Colores que comparten las unidades del producto: se pone en el producto. */
q("INSERT INTO stock_web (producto_id, variante_id, cantidad, estado, compartido, leido_en) VALUES (?, ?, 4, 'instock', 1, ?)",
  [(int)$pv['id'], $vids[0], date('Y-m-d H:i:s')]);
$GLOBALS['__wp']['productos'][5002]['gestiona'] = true;
$GLOBALS['__wp']['productos'][5002]['stock'] = 4;
ok('UN COLOR QUE COMPARTE LAS UNIDADES SE PONE EN EL PRODUCTO', tienda_web_poner_stock((int)$pv['id'], $vids[0], 9, 'Conteo')['ok']
   && $wp(5002)['stock'] === 9 && $wp(50021)['stock'] === 3);
es('y el color compartido dice lo mismo aquí', 9, (int) valor('SELECT cantidad FROM stock_web WHERE producto_id = ? AND variante_id = ?', [(int)$pv['id'], $vids[0]]));
/* Un color que la web lleva con las unidades del producto, aunque aquí no se sepa. */
$pc = producto_guardar(null, ['nombre' => 'Compartido', 'sku' => 'PRD-500400', 'activo' => 1]);
actualizar('productos', (int)$pc['id'], ['woo_id' => 5004, 'pais_id' => $PERU]);
variante_guardar((int)$pc['id'], ['color' => 'Uno']);
$vc = (int) valor('SELECT id FROM variantes WHERE producto_id = ?', [(int)$pc['id']]);
actualizar('variantes', $vc, ['woo_id' => 50041]);
q('DELETE FROM stock_web WHERE producto_id = ?', [(int)$pc['id']]);
ok('SI LA WEB DICE QUE ESE COLOR USA LAS DEL PRODUCTO, SE PONE EN EL PRODUCTO', tienda_web_poner_stock((int)$pc['id'], $vc, 6, 'Conteo')['ok']
   && $wp(5004)['stock'] === 6 && !$wp(50041)['gestiona']);
es('y aquí queda en el producto, con el color como compartido', [6, 1],
   [(int) valor('SELECT cantidad FROM stock_web WHERE producto_id = ? AND variante_id = 0', [(int)$pc['id']]),
    (int) valor('SELECT compartido FROM stock_web WHERE producto_id = ? AND variante_id = ?', [(int)$pc['id'], $vc])]);
/* Sin permiso, nada. */
banco_entrar(banco_usuario('direccion'));
ok('DIRECCIÓN NO CAMBIA LA WEB', !tienda_web_puede('datos') && !tienda_web_puede('stock') && !tienda_web_poner_stock((int)$ps['id'], 0, 1, 'x')['ok'] && $wp(5001)['stock'] === 7);
/* 3g: el permiso de la web, repartido por trabajo. */
banco_entrar(banco_usuario('almacen'));
ok('ALMACÉN PONE EL STOCK DE LA WEB', tienda_web_puede('stock') && tienda_web_poner_stock((int)$ps['id'], 0, 8, 'Conteo')['ok'] && $wp(5001)['stock'] === 8);
ok('pero no el nombre, el código ni el precio', !tienda_web_puede('datos'));
banco_entrar(banco_usuario('marketing'));
ok('MARKETING CAMBIA NOMBRE, CÓDIGO Y PRECIO', tienda_web_puede('datos'));
ok('pero no el stock', !tienda_web_puede('stock') && !tienda_web_poner_stock((int)$ps['id'], 0, 1, 'x')['ok'] && $wp(5001)['stock'] === 8);
ok('una palabra que no es ni «stock» ni «datos» no abre nada', !tienda_web_puede('') && !tienda_web_puede('todo'));
banco_entrar($ADMIN);
ok('Administración, las dos', tienda_web_puede('stock') && tienda_web_puede('datos'));
tienda_web_poner_stock((int)$ps['id'], 0, 7, 'Vuelta');
/* La tienda caída. */
$GLOBALS['__wp']['caida'] = true;
$cae = tienda_web_poner_stock((int)$ps['id'], 0, 1, 'x');
ok('con la tienda caída lo dice y no apunta nada', !$cae['ok'] && str_contains($cae['error'], 'No hubo respuesta')
   && (stock_web_de([(int)$ps['id']])[(int)$ps['id']]['cantidad'] ?? -1) === 7);
unset($GLOBALS['__wp']['caida']);

grupo('3c · al leer la tienda, con el conector manda el HUB');
$fila = fn(int $id, string $nombre, string $sku, ?int $precio, array $vars = []) => [
    'woo_id' => $id, 'nombre' => $nombre, 'categoria' => '', 'sku_crudo' => $sku, 'sku' => $sku,
    'tipo' => $vars ? 'variable' : 'simple', 'precio' => $precio, 'foto' => null, 'stock' => null, 'variaciones' => $vars];
$vfila = fn(int $id, string $sku, string $color, ?int $precio) => ['woo_id' => $id, 'sku_crudo' => $sku, 'sku' => $sku,
    'color' => $color, 'medida' => '', 'precio' => $precio, 'stock' => null];
$filas = [$fila(5001, 'Silla MARKETING', 'PRD-500100X', 9900),
          $fila(5002, 'Mesa colores', 'PRD-500200', null, [$vfila(50021, 'PRD-500201', 'Rojo', 5550), $vfila(50022, 'PRD-500202', 'Azul', 6000)])];
$plan = tienda_plan($filas, $PERU);
$it = fn(array $plan, int $woo) => array_values(array_filter($plan['productos'], fn($x) => (int)$x['fila']['woo_id'] === $woo))[0] ?? null;
es('UN NOMBRE CAMBIADO EN LA WEB NO SE APLICA: SE ENSEÑA', ['Silla simple NUEVA', 'Silla MARKETING'], $it($plan, 5001)['difiere']['nombre'] ?? null);
es('y no cuenta como «se actualiza»', 'igual', $it($plan, 5001)['accion'] ?? '');
es('el precio de la web distinto, también se enseña', [12000, 9900, 1], $it($plan, 5001)['difiere']['precio'] ?? null);
es('en uno con colores, cuántos colores difieren', [6000, 5550, 1], $it($plan, 5002)['difiere']['precio'] ?? null);
q('UPDATE variantes SET activo = 0 WHERE id = ?', [$vids[0]]);
es('UN COLOR APAGADO AQUÍ NO CUENTA COMO DISTINTO', null, $it(tienda_plan($filas, $PERU), 5002)['difiere']['precio'] ?? null);
q('UPDATE variantes SET activo = 1 WHERE id = ?', [$vids[0]]);
es('las cifras lo cuentan', 2, $plan['resumen']['difieren']);
$filas[0]['sku'] = $filas[0]['sku_crudo'] = 'PRD-OTRO';
$plan2 = tienda_plan($filas, $PERU);
es('UN CÓDIGO CAMBIADO EN LA WEB TAMPOCO: se enseña, no queda fuera', ['PRD-500100X', 'PRD-OTRO'], $it($plan2, 5001)['difiere']['codigo'] ?? null);
$filas[0]['sku'] = $filas[0]['sku_crudo'] = 'PRD-500100X';
$gl = tienda_importar(tienda_lectura_nueva($filas, $PERU));
ok('guardar la lectura', $gl['ok'], (string)$gl['error']);
es('NO CAMBIA EL NOMBRE DE AQUÍ', 'Silla simple NUEVA', (string) valor('SELECT nombre FROM productos WHERE id = ?', [(int)$ps['id']]));
es('ni el precio', 12000, precio_de_variante((int)$ps['id'], null, 1));
/* Sin el conector, manda la tienda, como antes. */
guardar_ajuste('conector_clave', ''); ajustes_olvidar();
$plan3 = tienda_plan($filas, $PERU);
es('SIN CONECTOR, MANDA LA TIENDA (como antes)', ['Silla simple NUEVA', 'Silla MARKETING'], $it($plan3, 5001)['cambios']['nombre'] ?? null);
es('y no enseña diferencias', [], $it($plan3, 5001)['difiere'] ?? null);
guardar_ajuste('conector_clave', $CLAVE); ajustes_olvidar();

exit(marcador());
