<?php
declare(strict_types=1);
/**
 * LA VENTA DESCUENTA EL STOCK DE LA WEB (3e).
 *
 * Corre el plugin «Waka HUB · Conector» DE VERDAD sobre el WordPress de
 * mentira, como correr6. Aquí se prueban las reglas decididas en tarjetas:
 * descontar al registrar, devolver al anular, no vender sin stock, y la cola
 * que reintenta cuando la tienda no responde —también cuando la tienda sí
 * hizo el cambio pero la respuesta se perdió, que es lo que no puede
 * descontar dos veces—.
 *
 * La venta se registra aquí con los mismos tres pasos que el controlador
 * (preparar, guardar, apuntar); la pantalla de verdad la prueba correr3.
 */
require_once __DIR__ . '/comun.php';
banco_crear(sys_get_temp_dir() . '/waka-pruebas7.sqlite');
require_once __DIR__ . '/wp_falso.php';

$ADMIN = banco_usuario('administracion');
banco_entrar($ADMIN);
$PERU = catalogo_pais();
$ASESOR = banco_usuario('asesor');
guardar_ajuste('woo_url', 'https://compraenwaka.test');
guardar_ajuste('woo_key', 'ck_' . str_repeat('a1', 20));
guardar_ajuste('woo_secret', 'cs_' . str_repeat('b2', 20));
guardar_ajuste('woo_pais', (string)$PERU);
$CLAVE = bin2hex(random_bytes(24));
guardar_ajuste('conector_clave', $CLAVE);
update_option(WAKA_HUB_OPCION, $CLAVE);
ajustes_olvidar();
$GLOBALS['__conector_transporte'] = wp_falso_transporte();

/* ── La tienda de mentira ── */
$wp_producto = function (array $d) { $GLOBALS['__wp']['productos'][$d['id']] = (new ProductoFalso($d))->d; };
$wp_producto(['id' => 7001, 'nombre' => 'Silla', 'sku' => 'PRD-700100', 'precio' => '100.00', 'gestiona' => true, 'stock' => 5]);
$wp_producto(['id' => 7002, 'tipo' => 'variable', 'nombre' => 'Mesa', 'sku' => 'PRD-700200']);
$wp_producto(['id' => 70021, 'tipo' => 'variation', 'padre' => 7002, 'sku' => 'PRD-700201', 'gestiona' => true, 'stock' => 2]);
$wp_producto(['id' => 70022, 'tipo' => 'variation', 'padre' => 7002, 'sku' => 'PRD-700202', 'gestiona' => true, 'stock' => 0, 'estado' => 'outofstock']);
$wp_producto(['id' => 7003, 'tipo' => 'variable', 'nombre' => 'Lámpara', 'sku' => 'PRD-700300', 'gestiona' => true, 'stock' => 4]);
$wp_producto(['id' => 70031, 'tipo' => 'variation', 'padre' => 7003, 'sku' => 'PRD-700301']);
$wp_producto(['id' => 70032, 'tipo' => 'variation', 'padre' => 7003, 'sku' => 'PRD-700302']);
$wp_producto(['id' => 7005, 'nombre' => 'Sin inventario', 'sku' => 'PRD-700500', 'precio' => '9.00']);
$wp = fn(int $id) => $GLOBALS['__wp']['productos'][$id]['stock'];
$pon_wp = function (int $id, int $n) { $GLOBALS['__wp']['productos'][$id]['stock'] = $n;
                                       $GLOBALS['__wp']['productos'][$id]['estado'] = $n > 0 ? 'instock' : 'outofstock'; };

/* ── El catálogo del HUB, enlazado ── */
$prod = fn(string $sku, string $nombre, ?int $woo) => insertar('productos', ['sku' => $sku, 'nombre' => $nombre, 'pais_id' => $PERU, 'woo_id' => $woo, 'activo' => 1]);
$var  = fn(int $pid, string $sku, string $color, ?int $woo) => insertar('variantes', ['producto_id' => $pid, 'sku' => $sku, 'color' => $color, 'woo_id' => $woo, 'activo' => 1]);
$SILLA = $prod('PRD-700100', 'Silla', 7001);
$MESA  = $prod('PRD-700200', 'Mesa', 7002);
$MESA_N = $var($MESA, 'PRD-700201', 'Negro', 70021);
$MESA_B = $var($MESA, 'PRD-700202', 'Blanco', 70022);
$MESA_R = $var($MESA, 'PRD-700203', 'Rojo', null);            // no está enlazado
$LAMP  = $prod('PRD-700300', 'Lámpara', 7003);
$LAMP_A = $var($LAMP, 'PRD-700301', 'Azul', 70031);
$LAMP_V = $var($LAMP, 'PRD-700302', 'Verde', 70032);
$LIBRE = $prod('PRD-700400', 'Solo en el HUB', null);
$SININV = $prod('PRD-700500', 'Sin inventario', 7005);

/* La foto del stock, como la deja una lectura. */
$foto = function () use ($SILLA, $MESA, $MESA_N, $MESA_B, $LAMP, $LAMP_A, $LAMP_V, $SININV, $wp) {
    q('DELETE FROM stock_web');
    $f = fn($p, $v, $c, $e = null, $comp = 0) => insertar('stock_web', ['producto_id' => $p, 'variante_id' => $v, 'cantidad' => $c,
        'estado' => $e ?? ($c === null ? 'instock' : ($c > 0 ? 'instock' : 'outofstock')), 'compartido' => $comp, 'leido_en' => date('Y-m-d H:i:s')]);
    $f($SILLA, 0, $wp(7001));
    $f($MESA, $MESA_N, $wp(70021)); $f($MESA, $MESA_B, $wp(70022));
    $f($LAMP, 0, $wp(7003)); $f($LAMP, $LAMP_A, $wp(7003), null, 1); $f($LAMP, $LAMP_V, $wp(7003), null, 1);
    $f($SININV, 0, null);
};
$foto();
$en_foto = fn(int $p, int $v = 0) => valor('SELECT cantidad FROM stock_web WHERE producto_id = ? AND variante_id = ?', [$p, $v]);

/* ── Vender y anular, con los pasos del controlador ── */
$CLIENTE = insertar('clientes', ['pais_id' => $PERU, 'asesor_id' => $ASESOR, 'tipo_doc' => 'DNI', 'documento' => '70636127',
                                 'nombre' => 'Cliente', 'apellidos' => 'De Prueba', 'email' => 'c7@waka.test', 'celular' => '900000000', 'activo' => 1]);
$MOTIVO = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id WHERE l.clave = 'motivos_anulacion' ORDER BY i.id LIMIT 1");
$linea = fn(int $pid, int $vid, int $c) => ['producto_id' => $pid, 'variante_id' => $vid ?: null, 'cantidad' => $c,
                                            'descripcion' => 'x', 'precio_unit_centimos' => 1000, 'total_centimos' => 1000 * $c];
/** $romper: la transacción revienta DESPUÉS de apuntar (como un fallo de la base). */
$vender = function (array $lineas, string $tipo = 'inmediata', int $pais = 0, bool $romper = false) use ($PERU, $CLIENTE, $ASESOR) {
    $pais = $pais ?: $PERU;
    $prep = stock_venta_preparar($tipo, $pais, $lineas);
    if (!$prep['ok']) return ['ok' => false, 'error' => $prep['error'], 'id' => 0, 'prep' => $prep];
    try {
        $id = en_transaccion(function () use ($lineas, $prep, $tipo, $pais, $CLIENTE, $ASESOR, $romper) {
            $estado = estado_por_clave(estado_inicial($tipo));
            $id = insertar('pedidos', ['codigo' => 'tmp-' . bin2hex(random_bytes(4)), 'pais_id' => $pais, 'cliente_id' => $CLIENTE,
                                       'asesor_id' => $ASESOR, 'tipo' => $tipo, 'estado_id' => (int)$estado['id'],
                                       'fecha' => date('Y-m-d'), 'entrega' => 'recojo']);
            actualizar('pedidos', $id, ['codigo' => pedido_codigo_de_id($id)]);
            foreach ($lineas as $l) insertar('pedido_lineas', $l + ['pedido_id' => $id, 'origen' => pedido_origen_stock($tipo)]);
            pedido_recalcular($id);
            stock_venta_apuntar($id, $prep);
            if ($romper) throw new RuntimeException('la base se cayó');
            return $id;
        });
    } catch (RuntimeException $ex) {
        stock_venta_deshacer($prep, 'La venta no llegó a guardarse');
        return ['ok' => false, 'error' => 'roto', 'id' => 0, 'prep' => $prep];
    }
    return ['ok' => true, 'error' => '', 'id' => $id, 'prep' => $prep];
};
$anular = fn(int $id) => pedido_anular($id, $MOTIVO, 'prueba');
$fila = fn(int $pid, string $tipo = 'descuento') => una('SELECT * FROM stock_web_cola WHERE pedido_id = ? AND tipo = ?', [$pid, $tipo]);
$llamadas = fn() => count(array_filter($GLOBALS['__wp']['llamadas'] ?? [], fn($l) => str_contains($l[1], '/stock/mover')));
$ya = fn() => q('UPDATE stock_web_cola SET proximo_en = ? WHERE estado = ?', [date('Y-m-d H:i:s', time() - 1), 'pendiente']);

grupo('3e · con el control apagado, nada cambia');
ok('la tabla de la cola existe', tabla_existe('stock_web_cola'));
es('el control nace apagado', false, control_stock());
$r = $vender([$linea($SILLA, 0, 2)]);
ok('se vende', $r['ok']);
es('Y LA WEB NO SE TOCA', 5, $wp(7001));
es('ni queda nada en la cola', null, $fila($r['id']));
es('la ficha no dice nada del stock', null, stock_venta_estado($r['id']));
es('anularla tampoco devuelve nada', [true, 5], [$anular($r['id'])['ok'], $wp(7001)]);

guardar_ajuste('control_stock', '1'); ajustes_olvidar();

grupo('3e · qué ventas descuentan');
es('con el control encendido, la entrega inmediata sí', true, venta_descuenta_web('inmediata', $PERU));
es('LA PRE VENTA NO: tiene su propio stock', false, venta_descuenta_web('preventa', $PERU));
es('LA LIQUIDACIÓN TAMPOCO', false, venta_descuenta_web('liquidacion', $PERU));
$r = $vender([$linea($SILLA, 0, 1)], 'preventa');
ok('una pre venta se registra sin tocar la web', $r['ok'] && $wp(7001) === 5 && !$fila($r['id']));
$MEX = (int) valor("SELECT id FROM paises WHERE id <> ? ORDER BY id LIMIT 1", [$PERU]);
if ($MEX) es('UNA VENTA DE OTRO PAÍS NO TOCA LA WEB DE ESTE', false, venta_descuenta_web('inmediata', $MEX));

grupo('3e · al registrar, se descuenta');
$r = $vender([$linea($SILLA, 0, 2)]);
ok('la venta se registra', $r['ok'], $r['error']);
es('LA WEB BAJA DE 5 A 3', 3, $wp(7001));
es('queda apuntado como hecho', 'hecho', $fila($r['id'])['estado'] ?? '');
es('LA FOTO DEL STOCK LO ENSEÑA AL MOMENTO', 3, (int)$en_foto($SILLA));
es('la ficha lo dice', 'Descontado del stock de la web', stock_venta_estado($r['id'])['texto'] ?? '');
$V1 = $r['id'];
es('con la clave de repetición guardada', 1, preg_match('/^v[0-9a-f]{24}$/', (string)$fila($V1)['clave']));

grupo('3e · SIN STOCK NO SE VENDE');
$n0 = (int) valor('SELECT COUNT(*) FROM pedidos');
$r = $vender([$linea($SILLA, 0, 4)]);
ok('pedir 4 con 3 en la web no deja', !$r['ok']);
es('y lo dice con el nombre y los números', 'No hay stock suficiente. «Silla»: quedan 3 y pides 4.', $r['error']);
es('no se guardó la venta', $n0, (int) valor('SELECT COUNT(*) FROM pedidos'));
es('ni se tocó la web', 3, $wp(7001));
$r = $vender([$linea($MESA, $MESA_B, 1)]);
es('UN COLOR AGOTADO LO DICE ASÍ', 'No hay stock suficiente. «Mesa — Blanco» está agotado.', $r['error']);
$r = $vender([$linea($SILLA, 0, 2), $linea($SILLA, 0, 2)]);
ok('DOS LÍNEAS DEL MISMO PRODUCTO SE SUMAN (2 + 2 con 3)', !$r['ok'] && str_contains($r['error'], 'quedan 3 y pides 4'), $r['error']);
$r = $vender([$linea($MESA, $MESA_N, 1), $linea($SILLA, 0, 9)]);
ok('SI FALTA UNO, NO SE DESCUENTA NINGUNO', !$r['ok'] && $wp(70021) === 2 && $wp(7001) === 3);

grupo('3e · colores y productos que no están en la web');
$r = $vender([$linea($LAMP, $LAMP_A, 2), $linea($LAMP, $LAMP_V, 2)]);
ok('DOS COLORES QUE COMPARTEN LAS UNIDADES CUENTAN JUNTOS: 2 + 2 con 4 sí', $r['ok'], $r['error']);
es('y la web queda en 0', 0, $wp(7003));
es('la foto del producto y de sus colores lo dice', [0, 0, 0], [(int)$en_foto($LAMP), (int)$en_foto($LAMP, $LAMP_A), (int)$en_foto($LAMP, $LAMP_V)]);
$r = $vender([$linea($LAMP, $LAMP_V, 1)]);
es('y el siguiente, agotado (el nombre es el del producto: las unidades son suyas)', 'No hay stock suficiente. «Lámpara» está agotado.', $r['error']);
$r = $vender([$linea($LIBRE, 0, 1), $linea($SILLA, 0, 1)]);
ok('UN PRODUCTO QUE SOLO ESTÁ EN EL HUB SE VENDE; EL DE LA WEB SE DESCUENTA', $r['ok'] && $wp(7001) === 2);
es('en la cola solo va el de la web', '1 × Silla', (string)$fila($r['id'])['detalle']);
$r = $vender([$linea($MESA, $MESA_R, 1)]);
ok('UN COLOR SIN ENLAZAR (su producto tiene colores en la web) SE VENDE SIN TOCARLA', $r['ok'] && !$fila($r['id']));
$r = $vender([$linea($SININV, 0, 50)]);
ok('UNO SIN INVENTARIO EN LA WEB NO FRENA', $r['ok'] && $wp(7005) === null);
$r = $vender([['descripcion' => 'Fuera de catálogo', 'cantidad' => 1, 'precio_unit_centimos' => 500, 'total_centimos' => 500]]);
ok('uno fuera de catálogo, tampoco', $r['ok'] && !$fila($r['id']));

grupo('3e · al anular, vuelve');
$wp_antes = $wp(7001);
$a = $anular($V1);
ok('se anula', $a['ok'], $a['error']);
es('LA WEB RECUPERA LAS 2', $wp_antes + 2, $wp(7001));
es('la devolución queda hecha', 'hecho', $fila($V1, 'devolucion')['estado'] ?? '');
es('la ficha lo dice', 'Stock devuelto a la web', stock_venta_estado($V1)['texto'] ?? '');
$c0 = $llamadas();
stock_cola_procesar($V1);
es('volver a pasar la cola no devuelve dos veces', [$wp_antes + 2, $c0], [$wp(7001), $llamadas()]);
stock_venta_devolver($V1);
es('ni apuntar la devolución otra vez', 1, (int) valor("SELECT COUNT(*) FROM stock_web_cola WHERE pedido_id = ? AND tipo = 'devolucion'", [$V1]));

grupo('3e · LA TIENDA NO RESPONDE: se mira el último stock');
$pon_wp(7001, 5); $foto();
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 2)]);
ok('con la foto diciendo que hay, LA VENTA SE REGISTRA', $r['ok'], $r['error']);
es('con un aviso corto para el asesor', 'La tienda no respondió. El stock de la web se descontará solo en unos minutos.', $r['prep']['aviso']);
$VC = $r['id'];
es('la web no cambió (no respondía)', 5, $wp(7001));
es('queda por descontar', 'pendiente', $fila($VC)['estado'] ?? '');
es('LA FOTO SIGUE DICIENDO LO DE LA WEB', 5, (int)$en_foto($SILLA));
es('PERO AL MIRARLA SE RESTA LO QUE ESPERA: quedan 3', 3, stock_venta_falta_segun_foto(stock_venta_movimientos([$linea($SILLA, 0, 4)]))[0]['hay'] ?? -1);
es('la ficha lo dice', 'Stock por descontar de la web: se reintenta solo', stock_venta_estado($VC)['texto'] ?? '');
$r = $vender([$linea($SILLA, 0, 4)]);
ok('CON LA FOTO DICIENDO QUE NO HAY, NO SE VENDE', !$r['ok'] && str_contains($r['error'], 'quedan 3 y pides 4'), $r['error']);
es('(y esa venta frenada deja su pareja en la cola, por si allá sí llegó)', 2,
   (int) valor("SELECT COUNT(*) FROM stock_web_cola WHERE pedido_id IS NULL AND estado = 'pendiente'"));
es('SIN VOLVER A LLAMAR A LA TIENDA MIENTRAS EL ASESOR ESPERA (una sola llamada)', 1,
   count(array_filter($GLOBALS['__wp']['llamadas'] ?? [], fn($l) => str_contains($l[2], (string) valor("SELECT clave FROM stock_web_cola WHERE pedido_id IS NULL AND tipo = 'descuento' ORDER BY id DESC LIMIT 1")))));
$ya();
stock_cola_procesar();
es('con la tienda caída la cola sigue esperando', 'pendiente', $fila($VC)['estado'] ?? '');
ok('con el error dicho para Administración', str_contains((string)$fila($VC)['ultimo_error'], 'No hubo respuesta'));
ok('y el siguiente intento, más tarde', strtotime((string)$fila($VC)['proximo_en']) > time());
$de_vc = fn() => count(array_filter($GLOBALS['__wp']['llamadas'] ?? [], fn($l) => str_contains($l[2], (string)$fila($VC)['clave'])));
$c0 = $de_vc();
stock_cola_procesar();
es('ANTES DE SU HORA NO SE REINTENTA', $c0, $de_vc());
$GLOBALS['__wp']['caida'] = false;
$ya();
stock_cola_procesar();
es('CUANDO LA TIENDA VUELVE, SE DESCUENTA', 3, $wp(7001));
es('y queda hecho', 'hecho', $fila($VC)['estado'] ?? '');
es('LA PAREJA DE LA VENTA FRENADA NO DEJÓ NADA MOVIDO (descontó y devolvió)', [0, 3],
   [(int) valor("SELECT COUNT(*) FROM stock_web_cola WHERE pedido_id IS NULL AND estado = 'pendiente'"), $wp(7001)]);
es('la foto queda con lo que dice la tienda', 3, (int)$en_foto($SILLA));

grupo('3e · LA RESPUESTA SE PIERDE: la tienda lo hizo y el HUB no se enteró');
$GLOBALS['__wp']['corta'] = true;
$r = $vender([$linea($SILLA, 0, 1)]);
ok('la venta se registra (la foto dice que hay)', $r['ok']);
es('allá SÍ se descontó', 2, $wp(7001));
es('aquí queda por descontar', 'pendiente', $fila($r['id'])['estado'] ?? '');
$GLOBALS['__wp']['corta'] = false;
$ya();
stock_cola_procesar();
es('AL REINTENTAR NO SE DESCUENTA DOS VECES', 2, $wp(7001));
es('y queda hecho', 'hecho', $fila($r['id'])['estado'] ?? '');

grupo('3e · LA COLA LLEGA TARDE Y LA WEB YA NO TIENE: descuenta igual y avisa');
$pon_wp(7001, 2); $foto();
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 2)]);
$VT = $r['id'];
ok('se vende con la tienda caída', $r['ok']);
$GLOBALS['__wp']['caida'] = false;
$pon_wp(7001, 1);                                  // mientras tanto se vendió una en la web
$ya();
stock_cola_procesar();
es('SE DESCUENTA IGUAL: LA WEB QUEDA EN -1', -1, $wp(7001));
$f = $fila($VT);
es('queda hecho, para revisar', ['hecho', 1], [$f['estado'], (int)$f['revisar']]);
es('diciendo qué pasó', 'No hay stock suficiente. «Silla»: quedan 1 y pides 2.', (string)$f['negativo']);
es('la ficha lo dice en rojo', 'rojo', stock_venta_estado($VT)['tono'] ?? '');
ok('ADMINISTRACIÓN LO VE CONTADO', stock_cola_atencion() >= 1);
ok('y queda en la bitácora', (bool) valor("SELECT 1 FROM bitacora WHERE accion = 'tienda.stock_negativo'"));
$at = stock_cola_atencion();
stock_cola_visto((int)$f['id']);
es('«ya lo vi» lo quita de la cuenta', $at - 1, stock_cola_atencion());

grupo('3e · se anula mientras espera');
$pon_wp(7001, 5); $foto();
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 2)]);
$VA = $r['id'];
$a = $anular($VA);
ok('se anula aunque la tienda esté caída', $a['ok'], $a['error']);
es('quedan el descuento y la devolución esperando', ['pendiente', 'pendiente'], [$fila($VA)['estado'], $fila($VA, 'devolucion')['estado']]);
es('la ficha dice que falta devolver', 'Stock por devolver a la web: se reintenta solo', stock_venta_estado($VA)['texto'] ?? '');
$GLOBALS['__wp']['caida'] = false;
$ya();
$c0 = $llamadas();
q("UPDATE stock_web_cola SET proximo_en = ? WHERE id = ?", [date('Y-m-d H:i:s', time() + 3600), (int)$fila($VA)['id']]);
stock_cola_procesar();
es('LA DEVOLUCIÓN NO SE ADELANTA A SU DESCUENTO (el descuento espera su hora)', [$c0, 'pendiente'], [$llamadas(), $fila($VA, 'devolucion')['estado']]);
stock_cola_procesar($VA);
es('al moverlas, primero descuenta y luego devuelve: la web queda igual', 5, $wp(7001));
es('las dos hechas', ['hecho', 'hecho'], [$fila($VA)['estado'], $fila($VA, 'devolucion')['estado']]);
/* Y si al volver la web ya no tiene: no se fuerza, no hay venta. */
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 2)]);
$VB = $r['id'];
$anular($VB);
$GLOBALS['__wp']['caida'] = false;
$pon_wp(7001, 1);
stock_cola_procesar($VB);
es('ANULADA Y SIN STOCK EN LA WEB: NO SE MUEVE NADA', 1, $wp(7001));
es('las dos canceladas', ['cancelado', 'cancelado'], [$fila($VB)['estado'], $fila($VB, 'devolucion')['estado']]);
es('la ficha lo dice', 'No se movió el stock de la web', stock_venta_estado($VB)['texto'] ?? '');

grupo('3e · la venta no llega a guardarse');
$pon_wp(7001, 5); $foto();
$r = $vender([$linea($SILLA, 0, 2)], 'inmediata', 0, true);
ok('la transacción revienta', !$r['ok'] && $r['error'] === 'roto');
es('LA WEB RECUPERA LO QUE SE DESCONTÓ', 5, $wp(7001));
es('queda la pareja, hecha', ['hecho', 'hecho'], array_column(todas("SELECT estado FROM stock_web_cola WHERE clave = ? OR depende_de = (SELECT id FROM stock_web_cola WHERE clave = ?) ORDER BY id",
   [$r['prep']['clave'], $r['prep']['clave']]), 'estado'));
es('y ningún pedido a medias', 0, (int) valor("SELECT COUNT(*) FROM stock_web_cola WHERE clave = ? AND pedido_id IS NOT NULL", [$r['prep']['clave']]));

grupo('3e · la foto cuando la tienda no responde');
$m = stock_venta_movimientos([$linea($SILLA, 0, 1)]);
q('DELETE FROM stock_web WHERE producto_id = ?', [$SILLA]);
es('SIN FOTO DE ESE PRODUCTO NO SE FRENA (no se sabe)', [], stock_venta_falta_segun_foto($m));
insertar('stock_web', ['producto_id' => $SILLA, 'variante_id' => 0, 'cantidad' => 0, 'estado' => 'onbackorder', 'compartido' => 0, 'leido_en' => date('Y-m-d H:i:s')]);
es('lo que la web vende bajo pedido, tampoco', [], stock_venta_falta_segun_foto($m));
q("UPDATE stock_web SET cantidad = NULL, estado = 'outofstock' WHERE producto_id = ?", [$SILLA]);
es('sin cantidad pero agotado, sí', 1, count(stock_venta_falta_segun_foto($m)));
q("UPDATE stock_web SET cantidad = -2, estado = 'outofstock' WHERE producto_id = ?", [$SILLA]);
es('en negativo cuenta como 0', 0, stock_venta_falta_segun_foto($m)[0]['hay'] ?? -1);
$foto();
es('el buscador: agotado es agotado', true, stock_web_agotado(['cantidad' => 0, 'estado' => 'outofstock']));
es('bajo pedido no', false, stock_web_agotado(['cantidad' => 0, 'estado' => 'onbackorder']));
es('sin saber, no', false, stock_web_agotado(null));

grupo('3e · Administración: reintentar y dar por resuelto');
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 1)]);
$VR = $r['id'];
$GLOBALS['__wp']['caida'] = false;
$idr = (int)$fila($VR)['id'];
es('REINTENTAR UNA FILA NO ESPERA A SU HORA', 1, stock_cola_procesar(null, 10, [$idr]));
es('y se hizo', ['hecho', 4], [$fila($VR)['estado'], $wp(7001)]);
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 1)]);
$VS = $r['id'];
$anular($VS);
$idd = (int)$fila($VS)['id'];
es('dar por resuelto pide qué se hizo', false, stock_cola_resolver($idd, false, '  ')['ok']);
es('«NO HACE FALTA», con nota, sí', true, stock_cola_resolver($idd, false, 'Era de prueba')['ok']);
es('Y SU DEVOLUCIÓN TAMPOCO SE HACE (no se movió nada)', ['cancelado', 'cancelado'], [$fila($VS)['estado'], $fila($VS, 'devolucion')['estado']]);
es('quién lo resolvió', $ADMIN, (int)$fila($VS)['resuelto_por']);
es('lo ya resuelto no se vuelve a resolver', false, stock_cola_resolver($idd, false, 'otra vez')['ok']);
es('LA NOTA DE LA DEVOLUCIÓN CABE EN SU COLUMNA (200) aunque la nota sea larga', true,
   (function () use ($vender, $linea, $SILLA, $anular, $fila) {
       $GLOBALS['__wp']['caida'] = true;
       $r = $vender([$linea($SILLA, 0, 1)]); $anular($r['id']);
       stock_cola_resolver((int)$fila($r['id'])['id'], false, str_repeat('x', 400));
       $GLOBALS['__wp']['caida'] = false;
       return mb_strlen((string)$fila($r['id'], 'devolucion')['nota']) <= 200 && mb_strlen((string)$fila($r['id'])['nota']) <= 200;
   })());
$GLOBALS['__wp']['caida'] = false;

grupo('3e · la espera entre intentos y la cola «de paso»');
es('1 minuto tras el primero', 1, stock_cola_espera(1));
es('60 como mucho', [60, 60], [stock_cola_espera(7), stock_cola_espera(99)]);
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 1)]);
$VP = $r['id'];
$GLOBALS['__wp']['caida'] = false;
$ya();
q("DELETE FROM ajustes WHERE clave = 'stock_cola_ultima'");
stock_cola_de_paso();
es('DE PASO (lista de pedidos) TAMBIÉN SE MUEVE', 'hecho', $fila($VP)['estado'] ?? '');
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 1)]);
$GLOBALS['__wp']['caida'] = false;
$ya();
stock_cola_de_paso();
es('pero no más de una vez cada 2 minutos', 'pendiente', $fila($r['id'])['estado'] ?? '');
stock_cola_procesar(null, 10, [(int)$fila($r['id'])['id']]);
/* Dos pasadas a la vez: solo una la toma. */
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 1)]);
$GLOBALS['__wp']['caida'] = false;
$f = $fila($r['id']);
$c0 = $llamadas();
$GLOBALS['__stock_cola_antes_de_tomar'] = function (int $id) { q('UPDATE stock_web_cola SET intentos = intentos + 1 WHERE id = ?', [$id]); };
es('UNA FILA QUE TOMÓ OTRA PASADA A LA VEZ NO SE MANDA DOS VECES', ['nada', $c0], [stock_cola_uno((int)$f['id']), $llamadas()]);
unset($GLOBALS['__stock_cola_antes_de_tomar']);


grupo('3e · auditoría: lo que se arregló');
/* 1 · Forzada y con la respuesta perdida: el siguiente intento repite LA FORZADA. */
$ya(); stock_cola_procesar(null, 50);                 // lo que quedó de antes, fuera
$pon_wp(7001, 2); $foto();
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 2)]); $VF = $r['id'];
$GLOBALS['__wp']['caida'] = false;
$pon_wp(7001, 1);
/* Se pierde SOLO la respuesta de la forzada. */
$orig = $GLOBALS['__conector_transporte'];
$GLOBALS['__conector_transporte'] = function ($m, $u, $c, $cuerpo) use ($orig) {
    $GLOBALS['__wp']['corta'] = str_contains($cuerpo, '-f"');
    $res = $orig($m, $u, $c, $cuerpo);
    $GLOBALS['__wp']['corta'] = false;
    return $res;
};
$ya(); stock_cola_procesar();
$GLOBALS['__conector_transporte'] = $orig;
es('la forzada llegó (−1) pero la respuesta se perdió', [-1, 'pendiente', 1], [$wp(7001), $fila($VF)['estado'], (int)$fila($VF)['forzado']]);
$pon_wp(7001, $wp(7001) + 11);                     // reponen 11 en la web
$ya(); stock_cola_procesar();
es('AL REINTENTAR NO DESCUENTA DOS VECES aunque hayan repuesto', 10, $wp(7001));
es('y queda para revisar', ['hecho', 1], [$fila($VF)['estado'], (int)$fila($VF)['revisar']]);

/* 2 · «Ya lo arreglé» mientras la petición iba y venía. */
$pon_wp(7001, 5); $foto();
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 2)]); $VX = $r['id'];
$anular($VX);
$GLOBALS['__wp']['caida'] = false;
$idx = (int)$fila($VX)['id'];
$orig = $GLOBALS['__conector_transporte'];
$GLOBALS['__conector_transporte'] = function (...$a) use ($orig, $idx) {
    $res = $orig(...$a);
    if (($GLOBALS['__en_vuelo'] ?? 0) === 0) { $GLOBALS['__en_vuelo'] = 1; stock_cola_resolver($idx, false, 'No hacía falta'); }
    return $res;
};
stock_cola_uno($idx);
$GLOBALS['__conector_transporte'] = $orig; unset($GLOBALS['__en_vuelo']);
es('LA TIENDA SÍ LO MOVIÓ: queda hecho y para revisar, no cancelado', ['hecho', 1], [$fila($VX)['estado'], (int)$fila($VX)['revisar']]);
es('y su devolución vuelve a la cola', 'pendiente', $fila($VX, 'devolucion')['estado']);
stock_cola_procesar($VX);
es('Y LA WEB RECUPERA LAS 2', 5, $wp(7001));

/* 3 · «Lo desconté a mano» y después se anula: vuelve. */
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 1)]); $VM = $r['id'];
$GLOBALS['__wp']['caida'] = false;
$pon_wp(7001, 4);                                   // lo descontaron a mano
es('«lo desconté a mano» lo deja hecho', [true, 'hecho'], [stock_cola_resolver((int)$fila($VM)['id'], true, 'Lo bajé en WooCommerce')['ok'], $fila($VM)['estado']]);
$anular($VM);
es('AL ANULAR, SE DEVUELVE', 5, $wp(7001));
es('la ficha lo dice', 'Stock devuelto a la web', stock_venta_estado($VM)['texto'] ?? '');

/* 4 y 5 · La tienda no deja entrar al conector, o un producto ya no está. */
$foto();
update_option(WAKA_HUB_OPCION, bin2hex(random_bytes(24)));   // la clave de WordPress ya no es la de aquí
$r = $vender([$linea($SILLA, 0, 1)]);
ok('CON LA CLAVE MALA SE VENDE CON LA FOTO', $r['ok']);
es('pero la fila sale ya como «no se arregla sola»', ['pendiente', 1], [$fila($r['id'])['estado'], (int)$fila($r['id'])['revisar']]);
ok('Administración la ve al momento', stock_cola_atencion() >= 1);
$VK = $r['id'];
update_option(WAKA_HUB_OPCION, $CLAVE);
stock_cola_procesar(null, 10, [(int)$fila($VK)['id']]);
es('con la clave arreglada se descuenta', ['hecho', 0], [$fila($VK)['estado'], (int)$fila($VK)['revisar']]);
guardar_ajuste('conector_clave', ''); ajustes_olvidar();
$c0 = $llamadas();
$r = $vender([$linea($SILLA, 0, 1)]);
es('SIN CLAVE AQUÍ NO SE LLAMA A LA TIENDA', $c0, $llamadas());
es('y la fila espera, para revisar', [true, 1], [$r['ok'], (int)$fila($r['id'])['revisar']]);
guardar_ajuste('conector_clave', $CLAVE); ajustes_olvidar();
stock_cola_procesar(null, 10, [(int)$fila($r['id'])['id']]);
$GLOBALS['__wp']['productos'][7001]['status'] = 'trash';
$r = $vender([$linea($SILLA, 0, 1)]);
es('UN PRODUCTO QUE YA NO ESTÁ EN LA WEB NO SE VENDE', 'Uno de los productos ya no está en la web. Avisa a Administración para que lo revise.', $r['error']);
unset($GLOBALS['__wp']['productos'][7001]['status']);
q("UPDATE stock_web_cola SET estado = 'hecho' WHERE estado = 'pendiente'");  // lo de antes, fuera de la cuenta
es('stock_cola_otra_tienda deja lo pendiente para revisar a mano', true, (function () use ($vender, $linea, $SILLA, $fila) {
    $GLOBALS['__wp']['caida'] = true; $r = $vender([$linea($SILLA, 0, 1)]); $GLOBALS['__wp']['caida'] = false;
    stock_cola_otra_tienda();
    return $fila($r['id'])['estado'] === 'cancelado' && (int)$fila($r['id'])['revisar'] === 1;
})());

/* 6 · Leer la tienda no borra lo que espera en la cola. */
$pon_wp(7001, 3); $foto();
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 2)]);
$GLOBALS['__wp']['caida'] = false;
es('la foto dice lo de la web', 3, (int)$en_foto($SILLA));
stock_web_guardar([['woo_id' => 7001, 'stock' => ['cantidad' => 3, 'estado' => 'instock', 'compartido' => false]]], $PERU, date('Y-m-d H:i:s'));
es('DESPUÉS DE LEER LA TIENDA (que dice 3), AL MIRAR SIGUE RESTANDO LO QUE ESPERA', 1, stock_venta_falta_segun_foto(stock_venta_movimientos([$linea($SILLA, 0, 2)]))[0]['hay'] ?? -1);
$GLOBALS['__wp']['caida'] = true;
$r2 = $vender([$linea($SILLA, 0, 2)]);
$GLOBALS['__wp']['caida'] = false;
ok('así la siguiente venta con la tienda caída no vende de más', !$r2['ok']);
stock_cola_procesar(null, 10, [(int)$fila($r['id'])['id']]);

/* 7 · Una venta a media lectura no tira la lectura entera. */
$foto();
q("UPDATE ajustes SET valor = ? WHERE clave = 'stock_web_lectura'", [date('Y-m-d H:i:s', time() - 3600)]);   // la última lectura entera, hace una hora
q('UPDATE stock_web SET leido_en = ?', [date('Y-m-d H:i:s', time() - 3600)]);
$inicio = date('Y-m-d H:i:s', time() - 30);
stock_web_apuntar_quedan([['id' => 70021, 'stock' => 2, 'estado' => 'instock']]);   // una venta mientras se leía
stock_web_guardar([['woo_id' => 7001, 'stock' => ['cantidad' => 17, 'estado' => 'instock', 'compartido' => false]],
                   ['woo_id' => 7002, 'stock' => null, 'variaciones' => [['woo_id' => 70021, 'stock' => ['cantidad' => 10, 'estado' => 'instock', 'compartido' => false]]]]],
                  $PERU, $inicio);
es('LA LECTURA SE GUARDA (no se tira entera por una venta)', 17, (int)$en_foto($SILLA));
es('y la fila que apuntó la venta después, se queda', 2, (int)$en_foto($MESA, $MESA_N));

/* 8 · Con la tienda caída, anular llama UNA vez y «Reintentar todo» se para en la primera. */
$pon_wp(7001, 5); $foto();
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 1)]);
$c0 = $llamadas();
$anular($r['id']);
es('ANULAR CON LA TIENDA CAÍDA: UNA LLAMADA, NO DOS', $c0 + 1, $llamadas());
$ra = $vender([$linea($SILLA, 0, 1)]);
$rb = $vender([$linea($SILLA, 0, 1)]);
$c0 = $llamadas();
stock_cola_procesar(null, 10, [(int)$fila($ra['id'])['id'], (int)$fila($rb['id'])['id']]);
es('«REINTENTAR TODO» CON LA TIENDA CAÍDA SE PARA EN LA PRIMERA', $c0 + 1, $llamadas());
$GLOBALS['__wp']['caida'] = false;
$ya(); stock_cola_procesar(null, 50);

/* 10 · Una pasada con la lista vieja no vuelve a mandar lo que otra ya tomó. */
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 1)]);
$GLOBALS['__wp']['caida'] = false;
$vieja = $fila($r['id']);
stock_cola_uno((int)$vieja['id']);                  // otra pasada la hizo
$c0 = $llamadas();
es('CON LA FILA VIEJA DE SU LISTA, NO SE MANDA OTRA VEZ', ['nada', $c0], [stock_cola_uno((int)$vieja['id'], $vieja), $llamadas()]);

/* 11 · La pareja de una venta que no se guardó se resuelve en la MISMA pasada. */
$pon_wp(7001, 1); $foto();
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 5)]);               // la foto dice 1: no se vende
$GLOBALS['__wp']['caida'] = false;
$ya(); stock_cola_procesar();
es('LA WEB NO SE QUEDA CON MENOS: descuenta y devuelve en la misma pasada', 1, $wp(7001));

/* Las devoluciones que esperan a su descuento no ocupan el sitio de las que sí tocan. */
$pon_wp(7001, 9); $foto();
$GLOBALS['__wp']['caida'] = true;
$bloq = [];
for ($i = 0; $i < 3; $i++) { $r = $vender([$linea($SILLA, 0, 1)]); $anular($r['id']); $bloq[] = $r['id']; }
$r = $vender([$linea($SILLA, 0, 1)]); $VL = $r['id'];
$GLOBALS['__wp']['caida'] = false;
foreach ($bloq as $b) q('UPDATE stock_web_cola SET proximo_en = ? WHERE pedido_id = ?', [date('Y-m-d H:i:s', time() + 3600), $b]);
q("UPDATE stock_web_cola SET proximo_en = ? WHERE pedido_id IN (" . implode(',', $bloq) . ") AND tipo = 'devolucion'", [date('Y-m-d H:i:s', time() - 60)]);
q('UPDATE stock_web_cola SET proximo_en = ? WHERE pedido_id = ?', [date('Y-m-d H:i:s', time() - 1), $VL]);
stock_cola_procesar(null, 2);
es('CON DOS SITIOS POR PASADA, LA QUE TOCA SE MUEVE', 'hecho', $fila($VL)['estado']);
$ya(); stock_cola_procesar(null, 50);

/* 12 · Una foto vieja no frena en el buscador. */
es('UNA FOTO DE HACE 7 HORAS NO DICE AGOTADO', false,
   stock_web_agotado(['cantidad' => 0, 'estado' => 'outofstock', 'leido_en' => date('Y-m-d H:i:s', time() - 7 * 3600)]));


grupo('3e · verificación: lo que se arregló');
$ya(); stock_cola_procesar(null, 50);
/* V1 · Una venta que sí se descuenta no borra lo que espera. */
$pon_wp(7001, 5); $foto();
$GLOBALS['__wp']['caida'] = true;  $r1 = $vender([$linea($SILLA, 0, 1)]);
$GLOBALS['__wp']['caida'] = false; $r2 = $vender([$linea($SILLA, 0, 1)]);
es('la tienda dice 4 (la que espera todavía no está allá)', [4, 4], [$wp(7001), (int)$en_foto($SILLA)]);
$GLOBALS['__wp']['caida'] = true;  $r3 = $vender([$linea($SILLA, 0, 4)]);
$GLOBALS['__wp']['caida'] = false;
ok('CON LA TIENDA CAÍDA OTRA VEZ, 4 NO PASAN (quedan 3)', !$r3['ok'] && str_contains($r3['error'], 'quedan 3 y pides 4'), $r3['error']);
$ya(); stock_cola_procesar(null, 50);
/* V2 · Una lectura de hace 10 minutos no pisa lo que apuntó una venta después. */
$pon_wp(7001, 3); $foto();
q("UPDATE ajustes SET valor = ? WHERE clave = 'stock_web_lectura'", [date('Y-m-d H:i:s', time() - 7200)]);
q('UPDATE stock_web SET leido_en = ?', [date('Y-m-d H:i:s', time() - 7200)]);
$lectura = date('Y-m-d H:i:s', time() - 600);
$r = $vender([$linea($SILLA, 0, 1)]);
es('la venta apunta 2', 2, (int)$en_foto($SILLA));
stock_web_guardar([['woo_id' => 7001, 'stock' => ['cantidad' => 3, 'estado' => 'instock', 'compartido' => false]],
                   ['woo_id' => 7002, 'stock' => null, 'variaciones' => [['woo_id' => 70021, 'stock' => ['cantidad' => 7, 'estado' => 'instock', 'compartido' => false]]]]],
                  $PERU, $lectura);
es('LA LECTURA DE ANTES NO VUELVE A PONER 3', 2, (int)$en_foto($SILLA));
es('pero lo demás de esa lectura sí entra', 7, (int)$en_foto($MESA, $MESA_N));
/* V3 · Poner el stock desde la ficha con ventas esperando. */
$pon_wp(7001, 5); $foto();
$GLOBALS['__wp']['caida'] = true; $vender([$linea($SILLA, 0, 1)]); $vender([$linea($SILLA, 0, 1)]); $GLOBALS['__wp']['caida'] = false;
es('LA FICHA SABE CUÁNTAS ESPERAN', 2, stock_cola_por_descontar($SILLA));
$pw = tienda_web_poner_stock($SILLA, 0, 5, 'Conteo');
ok('poner 5 en la web', $pw['ok'], $pw['error']);
es('y al mirar, siguen restando las 2 que esperan', 3, stock_venta_falta_segun_foto(stock_venta_movimientos([$linea($SILLA, 0, 9)]))[0]['hay'] ?? -1);
$ya(); stock_cola_procesar(null, 50);
es('la cola las descuenta después', 3, $wp(7001));
/* V4 · «No hace falta» y anulada mientras la petición iba y venía: se devuelve. */
$pon_wp(7001, 5); $foto();
$GLOBALS['__wp']['caida'] = true; $r = $vender([$linea($SILLA, 0, 2)]); $V4 = $r['id']; $GLOBALS['__wp']['caida'] = false;
$id4 = (int)$fila($V4)['id'];
$orig = $GLOBALS['__conector_transporte'];
$GLOBALS['__conector_transporte'] = function (...$a) use ($orig, $id4, $V4, $anular) {
    $res = $orig(...$a);
    if (($GLOBALS['__en_vuelo'] ?? 0) === 0) { $GLOBALS['__en_vuelo'] = 1; stock_cola_resolver($id4, false, 'No hacía falta'); $anular($V4); }
    return $res;
};
stock_cola_uno($id4);
$GLOBALS['__conector_transporte'] = $orig; unset($GLOBALS['__en_vuelo']);
stock_cola_procesar($V4);
es('LA TIENDA LO DESCONTÓ Y LA VENTA SE ANULÓ: SE DEVUELVE', 5, $wp(7001));
es('y la ficha lo dice', 'Stock devuelto a la web', stock_venta_estado($V4)['texto'] ?? '');
/* V5 · Devolución resuelta como «no hace falta». */
$GLOBALS['__wp']['caida'] = false;
$r = $vender([$linea($SILLA, 0, 1)]); $V5 = $r['id'];
$GLOBALS['__wp']['caida'] = true; $anular($V5); $GLOBALS['__wp']['caida'] = false;
stock_cola_resolver((int)$fila($V5, 'devolucion')['id'], false, 'Se quedó con el cliente');
es('LA FICHA NO DICE «NO SE MOVIÓ» si se descontó y no se devolvió', 'No se devolvió a la web: lo resolvió Administración', stock_venta_estado($V5)['texto'] ?? '');
/* V6 · La tienda rechaza al conector: el asesor no lee «no respondió». */
update_option(WAKA_HUB_OPCION, bin2hex(random_bytes(24)));
$r = $vender([$linea($SILLA, 0, 1)]);
update_option(WAKA_HUB_OPCION, $CLAVE);
es('EL AVISO DICE QUE LO REVISA ADMINISTRACIÓN', 'Venta registrada. El stock de la web se descontará cuando Administración revise la conexión con la tienda.', $r['prep']['aviso']);
$ya(); stock_cola_procesar(null, 50);
/* Lo de una tienda no va a otra. */
$GLOBALS['__wp']['caida'] = true; $r = $vender([$linea($SILLA, 0, 1)]); $GLOBALS['__wp']['caida'] = false;
guardar_ajuste('woo_url', 'https://otra.test'); ajustes_olvidar();
$c0 = $llamadas();
stock_cola_procesar(null, 10, [(int)$fila($r['id'])['id']]);
es('UNA FILA DE LA TIENDA DE ANTES NO SE MANDA A LA NUEVA', [$c0, 'cancelado', 1], [$llamadas(), $fila($r['id'])['estado'], (int)$fila($r['id'])['revisar']]);
guardar_ajuste('woo_url', 'https://compraenwaka.test'); ajustes_olvidar();

/* Ronda 2 de la verificación. */
$ya(); stock_cola_procesar(null, 50);
$pon_wp(7001, 5); $foto();
$GLOBALS['__wp']['caida'] = true;
$b1 = $vender([$linea($SILLA, 0, 6)]);
$b2 = $vender([$linea($SILLA, 0, 6)]);
$b3 = $vender([$linea($SILLA, 0, 1)]);
$GLOBALS['__wp']['caida'] = false;
ok('UNA VENTA FRENADA NO DEJA EL PRODUCTO «AGOTADO» PARA LAS DEMÁS', !$b1['ok'] && str_contains($b2['error'], 'quedan 5 y pides 6') && $b3['ok'],
   $b2['error'] . ' | ' . $b3['error']);
$ya(); stock_cola_procesar(null, 50);
$r = $vender([$linea($SILLA, 0, 1)]);
guardar_ajuste('woo_url', 'https://otra.test'); ajustes_olvidar();
$GLOBALS['__wp']['caida'] = true; $anular($r['id']); $GLOBALS['__wp']['caida'] = false;
$c0 = $llamadas();
stock_cola_procesar($r['id']);
es('LA DEVOLUCIÓN DE UNA VENTA DE LA TIENDA DE ANTES NO VA A LA NUEVA', [$c0, 'cancelado', 1],
   [$llamadas(), $fila($r['id'], 'devolucion')['estado'], (int)$fila($r['id'], 'devolucion')['revisar']]);
guardar_ajuste('woo_url', 'https://compraenwaka.test'); ajustes_olvidar();

/* Lo que espera para OTRA tienda no cuenta en esta. */
$ya(); stock_cola_procesar(null, 50);
$pon_wp(7001, 5); $foto();
$vieja = stock_cola_nueva(1, 'descuento', 'v-vieja-' . bin2hex(random_bytes(4)), stock_venta_movimientos([$linea($SILLA, 0, 5)]),
                          true, 'pendiente', null, 'x', false, 'https://vieja.test');
q("UPDATE stock_web_cola SET proximo_en = ? WHERE id = ?", [date('Y-m-d H:i:s', time() + 3600), $vieja]);
es('LO QUE ESPERA PARA LA TIENDA DE ANTES NO RESTA EN ESTA', [], stock_venta_falta_segun_foto(stock_venta_movimientos([$linea($SILLA, 0, 5)])));
q('DELETE FROM stock_web_cola WHERE id = ?', [$vieja]);

/* La lectura de cada hora: una venta apuntada mientras se leían las páginas no se pisa. */
require_once __DIR__ . '/tienda_falsa.php';
$T = ['productos' => [['id' => 7001, 'name' => 'Silla', 'sku' => 'PRD-700100', 'type' => 'simple', 'regular_price' => '100',
                       'manage_stock' => true, 'stock_quantity' => 9, 'stock_status' => 'instock', 'categories' => [], 'images' => []]],
      'variaciones' => [], 'key' => 'ck_' . str_repeat('a1', 20), 'secret' => 'cs_' . str_repeat('b2', 20), 'modos' => ['basica']];
$falsa = tienda_falsa($T);
$GLOBALS['__tienda_transporte'] = function (...$a) use ($falsa, $SILLA) {
    $res = $falsa(...$a);
    /* Mientras se lee: una venta apunta 4, con la hora de ahora (un segundo después). */
    sleep(1);
    q('UPDATE stock_web SET cantidad = 4, leido_en = ? WHERE producto_id = ? AND variante_id = 0', [date('Y-m-d H:i:s'), $SILLA]);
    return $res;
};
$rl = stock_web_actualizar($PERU);
unset($GLOBALS['__tienda_transporte']);
ok('la lectura de cada hora funciona', $rl['ok'], $rl['error']);
es('Y NO PISA LA VENTA APUNTADA MIENTRAS SE LEÍA', 4, (int)$en_foto($SILLA));

grupo('3e · Configuración');
es('las pendientes se cuentan', (int) valor("SELECT COUNT(*) FROM stock_web_cola WHERE estado = 'pendiente'"), stock_cola_pendientes_n());
$GLOBALS['__wp']['caida'] = true;
$r = $vender([$linea($SILLA, 0, 1)]);
$GLOBALS['__wp']['caida'] = false;
guardar_ajuste('control_stock', '0'); ajustes_olvidar();
es('APAGADO, LA COLA SIGUE MOVIENDO LO YA VENDIDO', true, stock_cola_procesar(null, 10, [(int)$fila($r['id'])['id']]) === 1 && $fila($r['id'])['estado'] === 'hecho');

grupo('3i · la garantía de un repuesto que está en la web descuenta de la web');
guardar_ajuste('control_stock', '1'); ajustes_olvidar();
migraciones_tras_semillas();
$GLOBALS['__wp']['caida'] = false;
$wp_producto(['id' => 7009, 'nombre' => 'Joystick web', 'sku' => 'PRD-700900', 'precio' => '80.00', 'gestiona' => true, 'stock' => 3]);
$MAQW = $prod('PRD-709000', 'Máquina con repuesto en la web', null);
$REPW = $prod('PRD-700900', 'Joystick web', 7009);
q('UPDATE productos SET padre_id = ? WHERE id = ?', [$MAQW, $REPW]);
insertar('stock_web', ['producto_id' => $REPW, 'variante_id' => 0, 'cantidad' => 3, 'estado' => 'instock', 'compartido' => 0, 'leido_en' => date('Y-m-d H:i:s')]);
$G12w = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id WHERE l.clave = 'garantias' AND i.valor = '12 meses'");
$PW = insertar('pedidos', ['codigo' => 'tmp-w', 'pais_id' => $PERU, 'cliente_id' => $CLIENTE, 'asesor_id' => $ASESOR, 'tipo' => 'inmediata',
                           'estado_id' => (int) estado_por_clave('entregado')['id'], 'fecha' => date('Y-m-d'), 'entrega' => 'recojo', 'garantia_item_id' => $G12w,
                           'despacho_veces' => 1, 'despacho_primero_en' => date('Y-m-d H:i:s')]);
actualizar('pedidos', $PW, ['codigo' => pedido_codigo_de_id($PW)]);
insertar('pedido_lineas', ['pedido_id' => $PW, 'descripcion' => 'Máquina', 'cantidad' => 1, 'precio_unit_centimos' => 1000, 'total_centimos' => 1000, 'producto_id' => $MAQW]);
$fotog = function (): string {
    $nn = 'v' . date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.jpg';
    $dir = HUB_SUBIDAS . '/vouchers'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $img = imagecreatetruecolor(10, 10); imagejpeg($img, $dir . '/' . $nn); imagedestroy($img);
    return $nn;
};
$rg = garantia_pedir($PW, [$REPW => 2], 'No responde', [$fotog()]);
ok('se pide', $rg['ok'], $rg['error']);
$ra = garantia_aprobar((int)$rg['id']);
ok('SE APRUEBA Y LA WEB BAJA', $ra['ok'] && $wp(7009) === 1, $ra['error'] . ' · web ' . $wp(7009));
$cw = una("SELECT * FROM stock_web_cola WHERE garantia_id = ? AND tipo = 'descuento'", [(int)$rg['id']]);
ok('queda en la cola, colgada de la garantía y hecha', $cw && $cw['estado'] === 'hecho' && $cw['pedido_id'] === null);
ok('y el almacén del HUB no se toca (es de la web)', !valor('SELECT 1 FROM stock_hub WHERE producto_id = ?', [$REPW]));
$rn = garantia_anular((int)$rg['id'], 'El cliente lo arregló');
ok('ANULADA, VUELVE A LA WEB', $rn['ok'] && $wp(7009) === 3, $rn['error'] . ' · web ' . $wp(7009));
ok('con su devolución colgada de la garantía', (bool) valor("SELECT 1 FROM stock_web_cola WHERE garantia_id = ? AND tipo = 'devolucion' AND estado = 'hecho'", [(int)$rg['id']]));
$pon_wp(7009, 1);
q('UPDATE stock_web SET cantidad = 1 WHERE producto_id = ?', [$REPW]);
$rg2 = garantia_pedir($PW, [$REPW => 2], 'Otra vez', [$fotog()]);
$ra2 = garantia_aprobar((int)$rg2['id']);
ok('SIN STOCK EN LA WEB NO SE APRUEBA, Y NO SE MUEVE NADA', !$ra2['ok'] && str_contains($ra2['error'], 'No hay stock') && $wp(7009) === 1
   && garantia_de((int)$rg2['id'])['estado'] === 'pedida', $ra2['error']);
guardar_ajuste('control_stock', '0'); ajustes_olvidar();

exit(marcador());
