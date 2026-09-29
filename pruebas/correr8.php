<?php
declare(strict_types=1);
/**
 * PRE VENTA Y LOTES (3h).
 *
 * El formulario del lote (lo principal), los precios del lote, el interruptor
 * con productos ocultos, «Disponible para pre venta», el CORTE DURO del stock
 * (lo que trae el lote menos lo vendido en pedidos vivos), la llegada que
 * pasa los pedidos a «Producto llegó», la barra del barco y el Excel de
 * ingresos de carga como plus. La pantalla de verdad la prueba correr3.
 */
require_once __DIR__ . '/comun.php';
banco_crear(sys_get_temp_dir() . '/waka-pruebas8.sqlite');

$CEO = banco_usuario('direccion');
$ADMIN = banco_usuario('administracion');
$ASESOR = banco_usuario('asesor');
banco_entrar($ADMIN);
$PERU = catalogo_pais();
$MX = insertar('paises', ['nombre' => 'México', 'codigo' => 'MX', 'moneda' => 'MXN', 'simbolo' => '$', 'activo' => 1]);

grupo('3h · la base');
ok('las columnas nuevas están', preventa_lista() && columna_existe('lotes', 'canal') && columna_existe('lotes', 'agencia_ref')
   && columna_existe('lote_lineas', 'nuevo') && columna_existe('pedido_lineas', 'lote_linea_id'));
ok('la lista de agencias de carga existe y nace vacía', (bool) valor("SELECT id FROM listas WHERE clave = 'agencias_carga'") && lista('agencias_carga') === []);
$pr_rol = fn(string $r, string $p) => puede_el(['rol' => $r, 'rol_id' => (int) valor('SELECT id FROM roles WHERE clave = ?', [$r])], $p);
ok('Dirección y Administración llenan lotes; Almacén los ve', $pr_rol('direccion', 'lotes.gestionar') && $pr_rol('administracion', 'lotes.gestionar')
   && $pr_rol('almacen', 'lotes.ver') && !$pr_rol('almacen', 'lotes.gestionar'));
ok('EL ASESOR VE LO DISPONIBLE, NO LOS LOTES ENTEROS', $pr_rol('asesor', 'preventa.ver') && !$pr_rol('asesor', 'lotes.ver') && !$pr_rol('asesor', 'lotes.gestionar'));
/* Un HUB que ya tenía «lotes.ver» en el asesor (módulo 2): la actualización se lo quita, una vez. */
$ra = (int) valor("SELECT id FROM roles WHERE clave = 'asesor'");
sembrar_par('rol_permiso', ['rol_id' => $ra, 'permiso_id' => (int) valor("SELECT id FROM permisos WHERE clave = 'lotes.ver'")]);
q("DELETE FROM ajustes WHERE clave = 'preventa_permiso_asesor'"); ajustes_olvidar(); permisos_del_rol(0, true);
migraciones_tras_semillas();
ok('la actualización le quita «lotes.ver» al asesor', !$pr_rol('asesor', 'lotes.ver'));

grupo('3h · la columna de colores, escrita a mano');
$pc = fn(string $t, int $n) => preventa_partir_colores($t, $n);
es('«/» es Única', 'unica', $pc('/', 22)['estado']);
es('vacío es Única', 'unica', $pc('', 5)['estado']);
es('«12 unidades moradas⏎15 unidades naranjas»', [['modelo' => 'Moradas', 'unidades' => 12], ['modelo' => 'Naranjas', 'unidades' => 15]],
   $pc("12 unidades moradas\n15 unidades naranjas", 27)['partes']);
es('«Negro:1 unidades⏎Rojo: 1 unidades⏎Verde: 1 unidades»', ['listo', ['Negro', 'Rojo', 'Verde']],
   [$pc("Negro:1 unidades\nRojo: 1 unidades\nVerde: 1 unidades", 3)['estado'], array_column($pc("Negro:1 unidades\nRojo: 1 unidades\nVerde: 1 unidades", 3)['partes'], 'modelo')]);
es('«250 rojos, 250 amarillos, 250 azules, 250 verdes»', ['listo', 4], [$pc('250 rojos, 250 amarillos, 250 azules, 250 verdes', 1000)['estado'], count($pc('250 rojos, 250 amarillos, 250 azules, 250 verdes', 1000)['partes'])]);
es('«Amarillo 5 unidades»: el color primero', [['modelo' => 'Amarillo', 'unidades' => 5]], $pc('Amarillo 5 unidades', 5)['partes']);
es('«Marrón» sin cantidad se lleva toda la fila', [['modelo' => 'Marrón', 'unidades' => 20]], $pc('Marrón', 20)['partes']);
es('«333 unidades (300 con 4 monederos)» es Única', 'unica', $pc('333 unidades (300 con 4 monederos)', 333)['estado']);
es('«2 botones, 2 joysticks» en una fila de 1 NO son colores: a revisar', 'revisa', $pc('2 botones, 2 joysticks, 2 cables', 1)['estado']);
es('si los números no suman las unidades, a revisar', 'revisa', $pc('10 rojos, 5 azules', 20)['estado']);

grupo('3h · el viaje del lote, en el formulario');
banco_entrar($CEO);
$r = lote_guardar(null, ['nombre' => '']);
ok('sin nombre no se crea', !$r['ok'] && str_contains($r['error'], 'nombre'));
$r = lote_guardar(null, ['nombre' => 'Contenedor 2', 'fecha_salida' => '32/13/2026']);
ok('una fecha que no existe se dice', !$r['ok'] && str_contains($r['error'], 'fecha de salida'));
$r = lote_guardar(null, ['nombre' => 'Contenedor 2', 'fecha_salida' => '2026-07-16', 'fecha_llegada_est' => '2026-07-01']);
ok('la llegada no puede ser antes de la salida', !$r['ok'] && str_contains($r['error'], 'antes'));
$r = lote_guardar(null, ['nombre' => 'Contenedor 2', 'agencia_item_id' => '999999']);
ok('una agencia que no está en la lista no entra', !$r['ok'] && str_contains($r['error'], 'agencia'));
$lid_ag = (int) valor("SELECT id FROM listas WHERE clave = 'agencias_carga'");
$AG = insertar('lista_items', ['lista_id' => $lid_ag, 'pais_id' => $PERU, 'valor' => 'Agencia Uno', 'orden' => 10]);
$r = lote_guardar(null, ['nombre' => 'Contenedor 9 · a mano', 'agencia_item_id' => $AG, 'agencia_ref' => 'HUB262683 | HBL:NSZEC260794609',
                         'bl' => 'por confirmar', 'factura' => 'AGLpe-C185-26529650', 'fecha_salida' => '16/07/2026',
                         'fecha_llegada_est' => '2026-09-26', 'canal' => 'rojo', 'almacen' => 'POR CONFIRMAR', 'responsable_id' => $CEO,
                         'puerto_origen' => 'Shenzhen', 'puerto_destino' => 'Callao']);
ok('CON LOS DATOS, SE CREA', $r['ok'], $r['error']);
$L1 = (int)$r['id'];
$l1 = lote_de($L1);
es('nace «Por confirmar», apagado y con su código', ['borrador', 0, 'LT-' . str_pad((string)$L1, 5, '0', STR_PAD_LEFT)],
   [(string)$l1['estado'], (int)$l1['disponible'], (string)$l1['codigo']]);
es('«por confirmar» se guarda vacío, no como texto', [null, null], [$l1['bl'], $l1['almacen']]);
es('la fecha dd/mm/aaaa se entiende', '2026-07-16', (string)$l1['fecha_salida']);
es('el canal', 'rojo', (string)$l1['canal']);

grupo('3h · los productos del lote');
/* Un producto de la tienda que ya existe con su código. */
$WEB = (int) producto_guardar(null, ['nombre' => 'Maquina dispensadora WOW', 'sku' => 'PRD-015580', 'activo' => 1])['id'];
precios_guardar($WEB, null, [['desde' => 1, 'hasta' => '', 'precio' => '900.00']]);
$r = lote_filas_guardar($L1, [
    ['codigo' => 'PRD-015580', 'nombre' => 'lo que sea', 'modelo' => '/', 'unidades' => '2'],
    ['codigo' => 'PRD-010748', 'nombre' => 'MAQUINA EXPENDEDORA GOLD SELLER', 'modelo' => '12 unidades moradas, 15 unidades naranjas', 'unidades' => '27'],
    ['codigo' => '', 'nombre' => 'Trampolín de colores', 'modelo' => '', 'unidades' => '10', 'nuevo' => true],
    ['codigo' => '', 'nombre' => '', 'modelo' => '', 'unidades' => ''],
]);
ok('SE GUARDAN', $r['ok'], $r['error']);
$filas = lote_filas($L1);
es('la fila con dos colores se parte en dos; la vacía se ignora', 4, count($filas));
es('el código que ya existe ENLAZA con ese producto', $WEB, (int)$filas[0]['producto_id']);
ok('el código nuevo crea el producto con ese código', (bool) valor("SELECT id FROM productos WHERE sku = 'PRD-010748' AND nombre = 'MAQUINA EXPENDEDORA GOLD SELLER'"));
es('los colores', ['Moradas' => 12, 'Naranjas' => 15], [$filas[1]['modelo'] => (int)$filas[1]['unidades'], $filas[2]['modelo'] => (int)$filas[2]['unidades']]);
ok('sin código se crea con uno del HUB, marcado nuevo', str_starts_with((string)$filas[3]['producto_sku'], 'WK-') && (int)$filas[3]['nuevo'] === 1);
es('el lote cuenta sus unidades', 39, (int) valor('SELECT unidades_totales FROM lotes WHERE id = ?', [$L1]));
$r = lote_filas_guardar($L1, [['codigo' => 'PRD-015580', 'nombre' => 'x', 'modelo' => '', 'unidades' => '1'], ['codigo' => 'PRD-015580', 'nombre' => 'x', 'modelo' => '', 'unidades' => '2']]);
ok('EL MISMO PRODUCTO Y COLOR DOS VECES NO', !$r['ok'] && str_contains($r['error'], 'dos veces'));
$r = lote_filas_guardar($L1, [['codigo' => 'PRD-015580', 'nombre' => 'x', 'modelo' => '', 'unidades' => 'dos']]);
ok('unidades que no son número no', !$r['ok'] && str_contains($r['error'], 'unidades'));
es('y lo de antes sigue igual', 4, count(lote_filas($L1)));
$GOLD = (int)$filas[1]['producto_id'];
$TRAMP = (int)$filas[3]['producto_id'];
$ids_f = array_map(fn($f) => (int)$f['id'], $filas);
$filas_form = fn(array $cambios = []) => array_map(fn($f) => ['id' => $f['id'], 'codigo' => (string)$f['codigo'], 'nombre' => (string)$f['producto_nombre'],
    'modelo' => (string)$f['modelo'], 'unidades' => (string)($cambios[(int)$f['id']] ?? $f['unidades']), 'nuevo' => (int)$f['nuevo'] === 1], lote_filas($L1));

grupo('3h · los precios del lote, aparte de los de la tienda');
es('sin precio, todos ocultos', ['con' => 0, 'sin' => 3], lote_completitud($L1));
$r = precios_guardar($GOLD, null, [['desde' => 1, 'hasta' => '2', 'precio' => '699'], ['desde' => 3, 'hasta' => '', 'precio' => '649']], $L1);
ok('el precio de pre venta se guarda', $r['ok'], $r['error']);
es('por cantidad', [69900, 69900, 64900, 64900], [precio_de_lote($GOLD, $L1, 1), precio_de_lote($GOLD, $L1, 2), precio_de_lote($GOLD, $L1, 3), precio_de_lote($GOLD, $L1, 40)]);
es('NO toca el precio de la tienda', null, producto_precio_base($GOLD));
precios_guardar($WEB, null, [['desde' => 1, 'hasta' => '', 'precio' => '800']], $L1);
es('y el de la tienda de uno que está en los dos sigue siendo el suyo', [90000, 80000], [precio_de_variante($WEB, null, 1), precio_de_lote($WEB, $L1, 1)]);
ok('guardar los de la tienda no borra los del lote', precios_guardar($WEB, null, [['desde' => 1, 'hasta' => '', 'precio' => '950']])['ok'] && precio_de_lote($WEB, $L1, 1) === 80000);
es('ahora dos con precio y uno oculto', ['con' => 2, 'sin' => 1], lote_completitud($L1));

grupo('3h · el interruptor y «Disponible para pre venta»');
banco_entrar($ASESOR);
es('apagado, el asesor no ve nada', [], preventa_disponible());
ok('y la pre venta sigue como antes (no hay lotes a la venta)', !preventa_con_lotes());
banco_entrar($CEO);
$r = lote_interruptor($L1, true);
ok('SE ENCIENDE AUNQUE FALTE UN PRECIO, CON AVISO', $r['ok'] && str_contains($r['aviso'], '1 producto sin precio'), $r['aviso']);
banco_entrar($ASESOR);
$disp = preventa_disponible();
es('EL ASESOR VE LOS DOS CON PRECIO, ORDENADOS (sin mirar mayúsculas), Y NO EL OCULTO', ['Maquina dispensadora WOW', 'MAQUINA EXPENDEDORA GOLD SELLER'],
   array_column($disp[0]['productos'] ?? [], 'nombre'));
ok('con lo que queda de cada color', ($disp[0]['productos'][1]['filas'][0]['quedan'] ?? 0) === 12);
ok('buscar filtra', count(preventa_disponible('gold')[0]['productos'] ?? []) === 1 && preventa_disponible('zzz') === []);
ok('con un lote a la venta, la pre venta sale de lotes', preventa_con_lotes());
$b = preventa_buscar('gold');
es('el buscador del pedido devuelve el producto del lote con sus colores', [1, 2, 'Moradas'],
   [count($b), count($b[0]['variantes'] ?? []), $b[0]['variantes'][0]['nombre'] ?? '']);
es('el de un solo color trae su fila directa', (int) valor('SELECT id FROM lote_lineas WHERE lote_id = ? AND producto_id = ?', [$L1, $WEB]),
   preventa_buscar('WOW')[0]['lote_linea'] ?? 0);
$mx = banco_usuario('asesor', ['pais_id' => $MX]);
banco_entrar($mx);
es('OTRO PAÍS NO LO VE', [], preventa_disponible());
banco_entrar($ASESOR);
ok('el catálogo de la tienda no enseña el producto que solo es de pre venta', !array_filter(catalogo_buscar('GOLD'), fn($x) => (int)$x['id'] === $GOLD));
ok('ni lo cuenta como «sin precio»', !valor('SELECT 1 FROM productos p WHERE p.id = ? AND NOT ' . sql_producto_solo_preventa('p'), [$GOLD]));

grupo('3h · la venta: precio del lote y CORTE DURO');
$MORADA = (int) valor("SELECT id FROM lote_lineas WHERE lote_id = ? AND modelo = 'Moradas'", [$L1]);
$r = preventa_linea($MORADA, $GOLD, 3);
ok('EL PRECIO SALE DEL LOTE, POR LA CANTIDAD', $r['ok'] && $r['linea']['precio_unit_centimos'] === 64900 && $r['linea']['modelo'] === 'Moradas', $r['error']);
ok('con otro producto no', !preventa_linea($MORADA, $WEB, 1)['ok']);
$TR_LN = (int) valor('SELECT id FROM lote_lineas WHERE lote_id = ? AND producto_id = ?', [$L1, $TRAMP]);
ok('lo que no tiene precio no se vende', !preventa_linea($TR_LN, $TRAMP, 1)['ok']);
$CLIENTE = insertar('clientes', ['pais_id' => $PERU, 'asesor_id' => $ASESOR, 'tipo_doc' => 'DNI', 'documento' => '70636128',
                                 'nombre' => 'Cliente', 'apellidos' => 'Pre venta', 'email' => 'c8@waka.test', 'celular' => '900000001', 'activo' => 1]);
$vender = function (int $llid, int $c) use ($PERU, $CLIENTE, $ASESOR, $GOLD) {
    $pv = preventa_linea($llid, 0, $c);
    if (!$pv['ok']) return ['ok' => false, 'error' => $pv['error'], 'id' => 0];
    $l = ['descripcion' => $pv['linea']['descripcion'], 'modelo' => $pv['linea']['modelo'], 'cantidad' => $c, 'producto_id' => $pv['linea']['producto_id'],
          'precio_unit_centimos' => $pv['linea']['precio_unit_centimos'], 'total_centimos' => $pv['linea']['precio_unit_centimos'] * $c,
          'lote_linea_id' => $llid, 'origen' => 'preventa'];
    try {
        $id = en_transaccion(function () use ($l, $PERU, $CLIENTE, $ASESOR) {
            $id = insertar('pedidos', ['codigo' => 'tmp-' . bin2hex(random_bytes(4)), 'pais_id' => $PERU, 'cliente_id' => $CLIENTE, 'asesor_id' => $ASESOR,
                                       'tipo' => 'preventa', 'estado_id' => (int) estado_por_clave('reservado')['id'], 'fecha' => date('Y-m-d'), 'entrega' => 'recojo']);
            actualizar('pedidos', $id, ['codigo' => pedido_codigo_de_id($id)]);
            preventa_asegurar([$l]);
            insertar('pedido_lineas', $l + ['pedido_id' => $id]);
            pedido_recalcular($id);
            return $id;
        });
    } catch (PreventaAgotada $ex) { return ['ok' => false, 'error' => $ex->getMessage(), 'id' => 0]; }
    return ['ok' => true, 'error' => '', 'id' => $id];
};
$v1 = $vender($MORADA, 10);
ok('se venden 10 de 12 moradas', $v1['ok'], $v1['error']);
es('quedan 2', 2, (int)(array_values(array_filter(lote_filas($L1), fn($f) => (int)$f['id'] === $MORADA))[0]['quedan'] ?? -1));
$v2 = $vender($MORADA, 3);
ok('NO SE VENDEN 3 SI QUEDAN 2 (corte duro)', !$v2['ok'] && str_contains($v2['error'], 'quedan 2'), $v2['error']);
ok('y no queda nada a medias', !valor("SELECT 1 FROM pedidos WHERE codigo LIKE 'tmp-%'") && (int) valor('SELECT COUNT(*) FROM pedidos') === 1);
$v3 = $vender($MORADA, 2);
ok('las 2 que quedan sí', $v3['ok']);
$v4 = $vender($MORADA, 1);
ok('agotado, lo dice así', !$v4['ok'] && str_contains($v4['error'], 'agotó'), $v4['error']);
ok('el buscador lo enseña agotado', (preventa_buscar('gold')[0]['variantes'][0]['agotado'] ?? false) === true);
/* Con la fila bloqueada, el lote tiene que seguir a la venta: si lo apagaron
   entre que se eligió y se guardó, no se vende. */
q('UPDATE lotes SET disponible = 0 WHERE id = ?', [$L1]);
$apagado = false;
try { preventa_asegurar([['lote_linea_id' => $NAR ?? (int) valor("SELECT id FROM lote_lineas WHERE lote_id = ? AND modelo = 'Naranjas'", [$L1]), 'cantidad' => 1]]); }
catch (PreventaAgotada $ex) { $apagado = true; }
ok('APAGADO MIENTRAS SE LLENABA LA VENTA: NO SE GUARDA', $apagado);
q('UPDATE lotes SET disponible = 1 WHERE id = ?', [$L1]);
$MOTIVO = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id WHERE l.clave = 'motivos_anulacion' ORDER BY i.id LIMIT 1");
pedido_anular($v3['id'], $MOTIVO, 'prueba');
es('ANULAR DEVUELVE LAS UNIDADES, SOLO', 2, (int)(array_values(array_filter(lote_filas($L1), fn($f) => (int)$f['id'] === $MORADA))[0]['quedan'] ?? -1));

es('EL TRAMO VA POR PRODUCTO: 2 moradas junto a 2 naranjas valen al precio de 4', 64900, preventa_linea($MORADA, $GOLD, 2, 4)['linea']['precio_unit_centimos'] ?? 0);

grupo('3h · lo vendido manda en el formulario del lote');
banco_entrar($CEO);
$r = lote_filas_guardar($L1, array_values(array_filter($filas_form(), fn($f) => (int)$f['id'] !== $MORADA)));
ok('UNA FILA CON VENTAS NO SE QUITA', !$r['ok'] && str_contains($r['error'], 'vendidas'), $r['error']);
$r = lote_filas_guardar($L1, $filas_form([$MORADA => 5]));
ok('NI BAJA DE LO VENDIDO', !$r['ok'] && str_contains($r['error'], 'menos'), $r['error']);
$r = lote_filas_guardar($L1, array_map(fn($f) => (int)$f['id'] === $MORADA ? ['modelo' => 'Lila'] + $f : $f, $filas_form()));
ok('NI CAMBIA DE COLOR', !$r['ok'] && str_contains($r['error'], 'ventas'), $r['error']);
$r = lote_filas_guardar($L1, $filas_form([$MORADA => 20]));
ok('subir las unidades sí', $r['ok'] && (int) valor('SELECT unidades FROM lote_lineas WHERE id = ?', [$MORADA]) === 20, $r['error']);
ok('y quitar una sin ventas también', lote_filas_guardar($L1, array_values(array_filter($filas_form(), fn($f) => (int)$f['id'] !== $TR_LN)))['ok']
   && !valor('SELECT 1 FROM lote_lineas WHERE id = ?', [$TR_LN]));

grupo('3h · lo que está por revisar no se vende');
$NAR = (int) valor("SELECT id FROM lote_lineas WHERE lote_id = ? AND modelo = 'Naranjas'", [$L1]);
q("UPDATE lote_lineas SET resuelto = 0, aviso = 'Los colores no suman las unidades' WHERE id = ?", [$NAR]);
banco_entrar($ASESOR);
ok('UNA FILA «REVISA» NO LA VE EL ASESOR', !array_filter(preventa_disponible()[0]['productos'][1]['filas'] ?? [], fn($f) => (int)$f['id'] === $NAR)
   && !preventa_linea($NAR, $GOLD, 1)['ok']);
banco_entrar($CEO);
ok('guardar sin tocarla la deja por revisar', lote_filas_guardar($L1, $filas_form())['ok'] && (int) valor('SELECT resuelto FROM lote_lineas WHERE id = ?', [$NAR]) === 0);
ok('«Ya lo revisé» la suelta', lote_filas_guardar($L1, array_map(fn($f) => (int)$f['id'] === $NAR ? ['revisado' => true] + $f : $f, $filas_form()))['ok']
   && (int) valor('SELECT resuelto FROM lote_lineas WHERE id = ?', [$NAR]) === 1);
ok('el aviso del interruptor cuenta lo que falta revisar', (function () use ($L1, $NAR) {
    q('UPDATE lote_lineas SET resuelto = 0 WHERE id = ?', [$NAR]);
    $r = lote_interruptor($L1, true);
    q('UPDATE lote_lineas SET resuelto = 1 WHERE id = ?', [$NAR]);
    return str_contains($r['aviso'], '1 fila por confirmar');
})());

grupo('3h · cada país con lo suyo');
$PMX = insertar('productos', ['sku' => 'PRD-MX0001', 'nombre' => 'Solo México', 'pais_id' => $MX, 'activo' => 1]);
$r = lote_filas_guardar($L1, array_merge($filas_form(), [['codigo' => 'PRD-MX0001', 'nombre' => 'x', 'modelo' => '', 'unidades' => '1']]));
ok('UN CÓDIGO DE OTRO PAÍS NO SE ENLAZA', !$r['ok'] && str_contains($r['error'], 'otro país'), $r['error']);
banco_entrar(banco_usuario('administracion', ['pais_id' => $MX]));
$LMX = (int) lote_guardar(null, ['nombre' => 'Contenedor México'])['id'];
lote_filas_guardar($LMX, [['codigo' => 'PRD-MX0001', 'nombre' => 'x', 'modelo' => '', 'unidades' => '5']]);
banco_entrar($CEO);
ok('DIRECCIÓN (que ve todos los países) PONE PRECIO A UN LOTE DE MÉXICO', precios_guardar($PMX, null, [['desde' => 1, 'hasta' => '', 'precio' => '10']], $LMX)['ok']);
banco_entrar($ADMIN);
ok('Administración de Perú no abre el lote de México', lote_de($LMX) === null);
banco_entrar($CEO);

grupo('3h · el estado de la carga');
$r = lote_estado_cambiar($L1, 'cancelado');
ok('CON VENTAS NO SE CANCELA', !$r['ok'] && str_contains($r['error'], 'ventas'));
lote_estado_cambiar($L1, 'en_camino');
$r = lote_estado_cambiar($L1, 'recibido');
ok('AL LLEGAR, SUS PEDIDOS PASAN A «PRODUCTO LLEGÓ»', $r['ok'] && $r['pedidos'] === 1 && (string) valor('SELECT e.clave FROM pedidos p JOIN pedido_estados e ON e.id = p.estado_id WHERE p.id = ?', [$v1['id']]) === 'llego');
ok('el anulado no', (string) valor('SELECT e.clave FROM pedidos p JOIN pedido_estados e ON e.id = p.estado_id WHERE p.id = ?', [$v3['id']]) === 'anulado');
es('y queda la fecha real', date('Y-m-d'), (string) valor('SELECT fecha_llegada_real FROM lotes WHERE id = ?', [$L1]));
ok('LLEGADO, DEJA DE VENDERSE COMO PRE VENTA', (int) valor('SELECT disponible FROM lotes WHERE id = ?', [$L1]) === 0 && !lote_interruptor($L1, true)['ok']);
ok('Y «LLEGÓ» YA NO CAMBIA', !lote_estado_cambiar($L1, 'en_camino')['ok']);
ok('lo que sobra de uno llegado vuelve a necesitar precio de tienda', (bool) valor('SELECT 1 FROM productos p WHERE p.id = ? AND NOT ' . sql_producto_solo_preventa('p'), [$GOLD]));
ok('un estado que no existe no', !lote_estado_cambiar($LMX, 'volando')['ok']);
es('los lotes en camino con algo sin precio, contados', 0, lotes_sin_precio_n($PERU));
lote_filas_guardar($LMX, [['codigo' => 'PRD-MX0001', 'nombre' => 'x', 'modelo' => '', 'unidades' => '5'], ['codigo' => '', 'nombre' => 'Sin precio MX', 'modelo' => '', 'unidades' => '2']]);
es('(uno en México)', 1, lotes_sin_precio_n($MX));
q("UPDATE lotes SET estado = 'recibido' WHERE id = ?", [$LMX]);
es('uno que ya llegó ya no cuenta como «esperando precios»', 0, lotes_sin_precio_n($MX));
es('LA LLEGADA SIN AÑO QUE CAE ANTES DE LA SALIDA ES DEL AÑO SIGUIENTE', '2027-02-15', lote_excel_fecha('15/02', '2026-12-10'));

grupo('3h · la barra del barco');
$b = fn(array $x) => lote_travesia($x + ['estado' => 'en_camino', 'fecha_salida' => '2026-07-16', 'fecha_llegada_est' => '2026-09-26', 'fecha_llegada_real' => null], '2026-08-20');
es('en camino: día y lo que falta', ['Día 35 de 72 · faltan 37 días', 49], [$b([])['texto'], $b([])['pct']]);
es('sin zarpar', 'Sin zarpar · sale el 16/07', lote_travesia(['estado' => 'borrador', 'fecha_salida' => '2026-07-16', 'fecha_llegada_est' => null], '2026-07-01')['texto']);
es('atrasado, al 92%', [92, 'mostaza'], [lote_travesia(['estado' => 'en_camino', 'fecha_salida' => '2026-07-16', 'fecha_llegada_est' => '2026-09-26'], '2026-10-01')['pct'],
                                          lote_travesia(['estado' => 'en_camino', 'fecha_salida' => '2026-07-16', 'fecha_llegada_est' => '2026-09-26'], '2026-10-01')['tono']]);
es('en aduana, lleno', 100, lote_travesia(['estado' => 'en_aduana'])['pct']);
es('llegó, verde', 'verde', lote_travesia(['estado' => 'recibido', 'fecha_llegada_real' => '2026-09-27'])['tono']);

grupo('3h · traer del Excel (el plus)');
$csv = "d,,,,\n"
     . "1,INFORMACIÓN DE PRODUCTOS | X1 CTN 40HQ,,,\n"
     . "CODIGO,,PRODUCTO,Cantidad,Colores / Modelo / Detalle\n"
     . "PRD-004569,,MAQUINA TIPO PELUCHERA CLASICA,14,/\n"
     . "PRD-004569,,piezas de repuesto,1,\"2 botones, 2 joysticks, 2 cables de alimentación\"\n"
     . "PRD-014127,,KIDDIE ELEVADOR SHAKER MODELO AUTO,20,\"10 rojos, 10 azules\"\n"
     . "PRD-018011,,Lavacabezas portátil | NUEVO,12,\"8 unidades blancas, 4 unidades marrones\"\n"
     . ",,,47,\n"
     . "AGENCIA DETALLE,HUB262683 | HBL:NSZEC260794609,PENDIENTE CANAL,,\n"
     . "BL,por confirmar,,,\n"
     . "FECHA DE SALIDA,16/07/2026,POR CONFIRMAR,,\n"
     . "FACTURA,AGLpe-C185-2662665,SOBRE ESTADIA,,\n"
     . "FECHA DE LLEGADA PERU,19/09,,,\n"
     . "Fecha Almacen,POR CONFIRMAR,,,\n"
     . "Almacen,POR CONFIRMAR,,,\n"
     . "Encargados,POR CONFIRMAR,,,\n"
     . ",,,,\n"
     . "2,INFORMACIÓN DE PRODUCTOS | X1 CTN 40HQ,,,\n"
     . "CODIGO,,PRODUCTO,Cantidad,Colores / Modelo / Detalle\n"
     . "PRD-006855,,MAQUINA TIPO PELUCHERA LUXURY,22,/\n"
     . "PRD-015532,,SILLA GAMER PRO DYNAMO,3,\"Negro:1 unidades\nRojo: 1 unidades\nVerde: 1 unidades\"\n"
     . "PRD-014267,,Piezas de repuesto - carcasa,1,/\n"
     . "\"PRD-007368\nPRD-007369\",,Accesorios,18,\"3 cabezas de ratón, 1 caja de alimentación\"\n"
     . "PRD-017193,,Bisagras para sillón de masaje | ACCESORIO,23,/\n"
     . "PRD-010440,,CAMILLA HEAD CAPSULE PRO,20,Marrón\n"
     . ",,,2177,\n"
     . "AGENCIA DETALLE,HUB262683 | HBL:NSZEC260794609,CANAL ROJO,,\n"
     . "BL,por confirmar,,,\n"
     . "FECHA DE SALIDA,16/07/2026,POR CONFIRMAR,por confirmar,\n"
     . "FACTURA,AGLpe-C185-26529650,SOBRE ESTADIA,,\n"
     . "FECHA DE LLEGADA PERU,26/09,,,\n"
     . "Fecha Almacen,POR CONFIRMAR,,,\n"
     . "Almacen,POR CONFIRMAR,,,\n"
     . "Encargados,POR CONFIRMAR,,,\n";
$arch = sys_get_temp_dir() . '/waka-carga.csv';
file_put_contents($arch, $csv);
$bl = lote_excel_bloques(lote_excel_filas($arch, 'carga.csv'));
es('ENCUENTRA LOS DOS CONTENEDORES', ['Contenedor 1 · X1 CTN 40HQ', 'Contenedor 2 · X1 CTN 40HQ'], array_column($bl, 'nombre'));
es('la llegada sin año se completa (después de la salida) y se avisa', ['2026-09-26', true],
   [$bl[1]['viaje']['fecha_llegada_est'], (bool) array_filter($bl[1]['avisos'], fn($a) => str_contains($a, 'sin año'))]);
es('«CANAL ROJO» es el canal', 'rojo', $bl[1]['viaje']['canal'] ?? '');
ok('«SOBRE ESTADIA» y «PENDIENTE CANAL» van a notas', str_contains((string)($bl[0]['viaje']['notas'] ?? ''), 'SOBRE ESTADIA')
   && str_contains((string)($bl[0]['viaje']['notas'] ?? ''), 'PENDIENTE CANAL'));
ok('el total que no cuadra se avisa', (bool) array_filter($bl[1]['avisos'], fn($a) => str_contains($a, '2177')));
ok('el que cuadra, no', !array_filter($bl[0]['avisos'], fn($a) => str_contains($a, 'total')));
$f0 = $bl[0]['filas'];
es('los colores se parten', [['Rojos', 10], ['Azules', 10]], array_map(fn($f) => [$f['modelo'], $f['unidades']], array_values(array_filter($f0, fn($f) => $f['codigo'] === 'PRD-014127'))));
$lava = array_values(array_filter($f0, fn($f) => $f['codigo'] === 'PRD-018011'));
ok('«| NUEVO» sale del nombre y queda como marca', ($lava[0]['nombre'] ?? '') === 'Lavacabezas portátil' && ($lava[0]['nuevo'] ?? 0) === 1);
/* 3i: el repuesto NO se enlaza por código con su máquina (sería la máquina):
   el código pasa a «Repuesto de» y el kit se parte pieza por pieza, a revisar. */
$rep = array_values(array_filter($f0, fn($f) => ($f['maquina'] ?? '') === 'PRD-004569'));
es('EL KIT SE PARTE PIEZA POR PIEZA, colgado de su máquina (3i)', [['Botones', 2], ['Joysticks', 2], ['Cables de alimentación', 2]],
   array_map(fn($f) => [$f['nombre'], $f['unidades']], $rep));
ok('sin código propio y a revisar', !array_filter($rep, fn($f) => $f['codigo'] !== '' || !$f['revisa']));
$acc = array_values(array_filter($bl[1]['filas'], fn($f) => $f['nombre'] === 'Cabezas de ratón'));
ok('dos códigos en una celda: sin código y a revisar', ($acc[0]['codigo'] ?? 'x') === '' && str_contains($acc[0]['aviso'] ?? '', 'dos códigos'));
es('«Marrón» se lleva la fila', [['Marrón', 20]], array_map(fn($f) => [$f['modelo'], $f['unidades']], array_values(array_filter($bl[1]['filas'], fn($f) => $f['codigo'] === 'PRD-010440'))));
$r = lote_excel_crear($bl[1]);
ok('TRAER EL CONTENEDOR 2 CREA SU LOTE', $r['ok'], $r['error']);
$L2 = (int)$r['id'];
$l2 = lote_de($L2);
es('apagado, por confirmar, con su viaje', ['borrador', 0, 'rojo', 'AGLpe-C185-26529650', '2026-09-26'],
   [$l2['estado'], (int)$l2['disponible'], $l2['canal'], $l2['factura'], $l2['fecha_llegada_est']]);
$f2 = lote_filas($L2);
es('con sus filas (la silla en tres colores, los accesorios en dos piezas)', 9, count($f2));
ok('lo dudoso queda marcado para revisar, con lo que decía el Excel', count(array_filter($f2, fn($f) => (int)$f['resuelto'] === 0)) === 4
   && (bool) array_filter($f2, fn($f) => str_contains((string)$f['texto_origen'], 'cabezas de ratón')));
ok('traerlo otra vez no lo duplica', !lote_excel_crear($bl[1])['ok']);
$acc2 = array_values(array_filter($f2, fn($f) => (string)$f['producto_nombre'] === 'Cabezas de ratón'));
ok('EL AVISO CAE EN SU FILA (no en la de al lado)', str_contains((string)($acc2[0]['aviso'] ?? ''), 'dos códigos')
   && !array_filter($f2, fn($f) => $f['producto_nombre'] === 'SILLA GAMER PRO DYNAMO' && (int)$f['resuelto'] === 0));
es('«12.0» son 12 unidades, «1,000» son mil', [12, 1000], array_map(fn($c) => lote_excel_bloques([['1', 'INFORMACIÓN DE PRODUCTOS | X'], ['CODIGO', '', 'PRODUCTO', 'Cantidad', 'Colores'],
   ['PRD-555001', '', 'Algo', $c, '/']])[0]['unidades'] ?? 0, ['12.0', '1,000']));
$dup = lote_excel_bloques([['1', 'INFORMACIÓN DE PRODUCTOS | X'], ['CODIGO', '', 'PRODUCTO', 'Cantidad', 'Colores'],
   ['PRD-555002', '', 'Uno', '3', '/'], ['PRD-555002', '', 'Uno bis', '2', '/']]);
$rd = lote_excel_crear($dup[0]);
$b2 = lote_excel_bloques([['1', 'INFORMACIÓN DE PRODUCTOS | Y'], ['CODIGO', '', 'PRODUCTO', 'Cantidad', 'Colores'],
   ['PRD-555003', '', 'Foo', '5', '/'], ['', '', 'foo', '3', '/'], ["PRD-1\nPRD-2", '', '', '4', '/']]);
$rb2 = lote_excel_crear($b2[0]);
ok('EL MISMO PRODUCTO CON Y SIN CÓDIGO SE JUNTA; DOS CÓDIGOS SIN NOMBRE QUEDA A REVISAR (el contenedor entra)', $rb2['ok']
   && (int) valor("SELECT unidades FROM lote_lineas ll JOIN productos p ON p.id = ll.producto_id WHERE ll.lote_id = ? AND p.sku = 'PRD-555003'", [$rb2['id']]) === 8
   && (bool) valor("SELECT 1 FROM lote_lineas ll JOIN productos p ON p.id = ll.producto_id WHERE ll.lote_id = ? AND p.nombre LIKE 'Sin nombre%' AND ll.resuelto = 0", [$rb2['id']]), $rb2['error']);
ok('EL MISMO CÓDIGO EN DOS FILAS SE SUMA (no tumba el contenedor), marcado para revisar', $rd['ok']
   && (int) valor("SELECT unidades FROM lote_lineas WHERE lote_id = ? AND resuelto = 0", [$rd['id']]) === 5, $rd['error']);
ok('lo que ya existía por código se enlaza (PRD-006855 no se duplica)', (int) valor("SELECT COUNT(*) FROM productos WHERE sku = 'PRD-006855'") === 1);
ok('un archivo que no es el de cargas no trae nada', lote_excel_bloques([['hola', 'mundo'], ['1', '2']]) === []);

/* Un .xlsx de verdad, armado aquí. */
$xl = sys_get_temp_dir() . '/waka-carga.xlsx';
@unlink($xl);
$z = new ZipArchive();
$z->open($xl, ZipArchive::CREATE);
$z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
$z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Carga" sheetId="1" r:id="rId1"/></sheets></workbook>');
$z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/hoja.xml" Type="x"/></Relationships>');
$z->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<si><t>INFORMACIÓN DE PRODUCTOS | X1 CTN 40HQ</t></si><si><t>CODIGO</t></si><si><t>PRODUCTO</t></si><si><t>Cantidad</t></si>'
    . '<si><t>Colores / Modelo / Detalle</t></si><si><t>PRD-777001</t></si><si><t>Silla de prueba</t></si><si><r><t>5 rojas, </t></r><r><t>5 azules</t></r></si>'
    . '<si><t>FECHA DE SALIDA</t></si></sst>');
$z->addFromString('xl/worksheets/hoja.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
    . '<row r="1"><c r="A1"><v>3</v></c><c r="B1" t="s"><v>0</v></c></row>'
    . '<row r="2"><c r="A2" t="s"><v>1</v></c><c r="C2" t="s"><v>2</v></c><c r="D2" t="s"><v>3</v></c><c r="E2" t="s"><v>4</v></c></row>'
    . '<row r="3"><c r="A3" t="s"><v>5</v></c><c r="C3" t="s"><v>6</v></c><c r="D3"><v>10</v></c><c r="E3" t="s"><v>7</v></c></row>'
    . '<row r="4"><c r="A4" t="s"><v>8</v></c><c r="B4"><v>46219</v></c></row>'
    . '</sheetData></worksheet>');
$z->close();
$bx = lote_excel_bloques(lote_excel_filas($xl, 'carga.xlsx'));
es('UN .XLSX TAMBIÉN SE LEE (con su hoja por su relación, textos en trozos y la fecha como número)',
   ['Contenedor 3 · X1 CTN 40HQ', 2, 'Rojas', '2026-07-16'],
   [$bx[0]['nombre'] ?? '', count($bx[0]['filas'] ?? []), $bx[0]['filas'][0]['modelo'] ?? '', $bx[0]['viaje']['fecha_salida'] ?? '']);

grupo('3h.1 · las filas en blanco del lote');
$rb = lote_guardar(null, ['nombre' => 'Contenedor filas en blanco']);
$LB = (int)$rb['id'];
$r = lote_filas_guardar($LB, [
    ['codigo' => '', 'nombre' => 'Silla en blanco', 'modelo' => '', 'unidades' => '3'],
    ['codigo' => '', 'nombre' => '', 'modelo' => '', 'unidades' => '0'],
    ['codigo' => '', 'nombre' => '', 'modelo' => '', 'unidades' => ' '],
    ['codigo' => '', 'nombre' => '', 'modelo' => '', 'unidades' => '', 'nuevo' => true],
]);
ok('UNA FILA EN BLANCO CON «0» UNIDADES NO DA «FALTA EL PRODUCTO»', $r['ok'], $r['error']);
es('y solo se guarda la de verdad', 1, (int) valor('SELECT COUNT(*) FROM lote_lineas WHERE lote_id = ?', [$LB]));
$r = lote_filas_guardar($LB, [['codigo' => '', 'nombre' => '', 'modelo' => 'rojas', 'unidades' => '0']]);
ok('una fila con algo escrito sí se revisa', !$r['ok'] && str_contains($r['error'], 'Fila 1'));

exit(marcador());
