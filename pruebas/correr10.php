<?php
declare(strict_types=1);
/**
 * 3j · LO QUE PIDIÓ EL USUARIO AL PROBAR LA 3i (2026-09-28).
 *
 * El estado lo mueve el HUB (registrado → pagado → en despacho → entregado; la
 * pre venta: reservado → en camino → llegó → listo para entrega → …), la pre
 * venta solo sale con su lote LISTO PARA ENTREGA, «Llega aprox. dd/mm»
 * mientras tanto, ENTREGADO con la foto de la entrega, el costo del envío
 * opcional en la pre venta, el siguiente paso del lote, «Enviados» con sus
 * fotos, las máquinas para la casilla «Es repuesto», los clientes a los que se
 * les puede vender y la caché del esquema. La pantalla la prueba correr3.
 */
require_once __DIR__ . '/comun.php';
banco_crear(sys_get_temp_dir() . '/waka-pruebas10.sqlite');
migraciones_tras_semillas();

$CEO    = banco_usuario('direccion');
$ADMIN  = banco_usuario('administracion');
$ASESOR = banco_usuario('asesor');
$OTRO   = banco_usuario('asesor');
$ALMACEN= banco_usuario('almacen');
$PERU = (int) valor("SELECT id FROM paises WHERE codigo = 'PE'");
$estado = fn(int $id) => (string) valor('SELECT e.clave FROM pedidos p JOIN pedido_estados e ON e.id = p.estado_id WHERE p.id = ?', [$id]);
$efectivo = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id=i.lista_id WHERE l.clave='metodos_pago' AND i.valor='Efectivo'");
$MOTIVO = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id WHERE l.clave = 'motivos_anulacion' ORDER BY i.id LIMIT 1");
@mkdir(HUB_SUBIDAS . '/vouchers', 0755, true);
$FOTO = 'v' . date('Ymd') . '-' . 'abcdef654321.jpg';
file_put_contents(HUB_SUBIDAS . '/vouchers/' . $FOTO, 'x');
$CLI = insertar('clientes', ['pais_id' => $PERU, 'asesor_id' => $ASESOR, 'tipo_doc' => 'DNI', 'documento' => '70636210',
                             'nombre' => 'Rosa', 'apellidos' => 'Quispe', 'email' => 'c10@waka.test', 'celular' => '900000010', 'activo' => 1]);
/* Cobra y confirma (Administración confirma). */
$cobrar = function (int $pid, int $cent) use ($ASESOR, $ADMIN, $efectivo) {
    $yo = (int)($_SESSION['usuario_id'] ?? 0);
    banco_entrar($ASESOR);
    $pg = pago_registrar($pid, ['monto_centimos' => $cent, 'metodo_item_id' => $efectivo, 'fecha' => date('Y-m-d')]);
    banco_entrar($ADMIN);
    $v = pago_validar((int)($pg['id'] ?? 0), 'x');
    if ($yo) banco_entrar($yo);
    return (int)($pg['id'] ?? 0);
};

grupo('3j · la base');
ok('las columnas nuevas están', columna_existe('lotes', 'listo_en') && columna_existe('lotes', 'listo_por')
   && columna_existe('pedidos', 'entregado_en') && columna_existe('pedidos', 'entregado_por') && columna_existe('pedidos', 'entregado_foto'));
/* 5b: «Alistado» entre «En despacho» y «Entregado» (usuario, 2026-09-29). */
es('LOS ESTADOS NUEVOS, EN SU SITIO DEL CAMINO', ['reservado', 'en_camino', 'llego', 'listo', 'pagado', 'en_despacho', 'alistado', 'entregado'],
   array_column(todas("SELECT clave FROM pedido_estados WHERE clave NOT IN ('registrado','anulado') ORDER BY orden"), 'clave'));
ok('ya no hay botones para cambiar el estado: las funciones de antes no existen',
   !function_exists('pedido_cambiar_estado') && !function_exists('estados_siguientes') && !function_exists('transiciones_de'));
ok('la marca de arranque queda puesta, con su fecha', (bool) preg_match('/^\d{4}-\d{2}-\d{2} /', (string) ajuste('entrega_arranque', '')) && estados_auto_desde() === (string) ajuste('entrega_arranque', ''));

grupo('3j · la caché del esquema (velocidad)');

es('una columna que existe', true, columna_existe('pedidos', 'entregado_en'));
es('una que no', false, columna_existe('pedidos', 'no_existe_nunca'));
es('una tabla que existe', true, tabla_existe('lotes'));
es('una que no', false, tabla_existe('tabla_fantasma'));
bd()->exec('CREATE TABLE tabla_fantasma (id INTEGER)');
es('UNA TABLA RECIÉN CREADA SE VE (el «no» no se guarda)', true, tabla_existe('tabla_fantasma'));
es('(la tabla ya está leída de memoria)', true, columna_existe('lotes', 'listo_en'));
bd()->exec('ALTER TABLE lotes ADD COLUMN col_nueva INTEGER NULL');
es('Y UNA COLUMNA RECIÉN AÑADIDA TAMBIÉN', true, columna_existe('lotes', 'col_nueva'));

grupo('3j · la venta de stock: el estado lo mueve el HUB');
banco_entrar($ASESOR);
$P = (int) producto_guardar(null, ['nombre' => 'Silla Gamer', 'sku' => 'PRD-100001', 'activo' => 1])['id'];
$nuevo = function (string $tipo, int $total, array $extra = []) use ($PERU, $CLI, $ASESOR) {
    $id = insertar('pedidos', array_merge(['codigo' => 'tmp-' . bin2hex(random_bytes(4)), 'pais_id' => $PERU, 'cliente_id' => $CLI,
        'asesor_id' => $ASESOR, 'tipo' => $tipo, 'estado_id' => (int) estado_por_clave(estado_inicial($tipo))['id'],
        'fecha' => date('Y-m-d'), 'entrega' => 'recojo'], $extra));
    actualizar('pedidos', $id, ['codigo' => pedido_codigo_de_id($id)]);
    insertar('pedido_lineas', ['pedido_id' => $id, 'descripcion' => 'Silla Gamer Negra', 'cantidad' => 1,
        'precio_unit_centimos' => $total, 'total_centimos' => $total, 'origen' => pedido_origen_stock($tipo)]);
    pedido_recalcular($id);
    pedido_estado_auto($id, '', false);
    return $id;
};
$S = $nuevo('inmediata', 50000);
es('nace «Registrado»', 'registrado', $estado($S));
$pg1 = $cobrar($S, 50000);
es('COBRADA AL 100 % PASA SOLA A «PAGADO»', 'pagado', $estado($S));
banco_entrar($ADMIN);
pago_devolver($pg1, "prueba");
es('SI SE REVIERTE EL PAGO, VUELVE A «REGISTRADO»', 'registrado', $estado($S));
$cobrar($S, 50000);
banco_entrar($ASESOR);
$r = pedido_despacho_marcar($S, true);
ok('se manda a despacho', $r['ok'], $r['error'] ?? '');
es('MANDADA A DESPACHO → «EN DESPACHO»', 'en_despacho', $estado($S));
$ev_n = (int) valor("SELECT COUNT(*) FROM pedido_eventos WHERE pedido_id = ? AND tipo = 'estado'", [$S]);
es('VOLVER A MIRARLO NO CAMBIA NADA NI ESCRIBE OTRA LÍNEA', ['', $ev_n], [pedido_estado_auto($S), (int) valor("SELECT COUNT(*) FROM pedido_eventos WHERE pedido_id = ? AND tipo = 'estado'", [$S])]);
ok('y la historia dice el cambio', (bool) valor("SELECT 1 FROM pedido_eventos WHERE pedido_id = ? AND tipo = 'estado' AND texto LIKE '%En despacho%'", [$S]));

grupo('3j · ENTREGADO, con la foto de la entrega');
banco_entrar($ALMACEN);
$r = pedido_entregado_marcar($S, $FOTO);
ok('SIN ALISTAR NO SE ENTREGA', !$r['ok'] && str_contains($r['error'], 'alistar'), $r['error']);
$quien = insertar('lista_items', ['lista_id' => (int) valor("SELECT id FROM listas WHERE clave = 'equipo_despacho'"), 'pais_id' => $PERU, 'valor' => 'Juan', 'orden' => 1]);
ok('se alista', pedido_alistar($S, $quien, $FOTO)['ok']);
es('5b · ALISTADO → LA ETIQUETA DICE «ALISTADO», NO «EN DESPACHO»', 'alistado', $estado($S));
ok('y la historia lo cuenta', (bool) valor("SELECT 1 FROM pedido_eventos WHERE pedido_id = ? AND tipo = 'estado' AND texto LIKE '%Alistado%'", [$S]));
es('queda en «Por entregar»', [1, $S], [pedidos_por_entregar_n(), (int)(pedidos_por_entregar()[0]['id'] ?? 0)]);
$r = pedido_entregado_marcar($S, null);
ok('SIN FOTO NO', !$r['ok'] && str_contains($r['error'], 'foto'), $r['error']);
$r = pedido_entregado_marcar($S, 'v20260101-000000000000.jpg');
ok('con una foto que no existe tampoco', !$r['ok']);
$r = pedido_entregado_marcar($S, $FOTO);
ok('CON LA FOTO, ENTREGADO', $r['ok'], $r['error']);
es('EL ESTADO PASA SOLO A «ENTREGADO»', 'entregado', $estado($S));
ok('queda quién, cuándo y la foto', (int) valor('SELECT entregado_por FROM pedidos WHERE id = ?', [$S]) === $ALMACEN
   && (string) valor('SELECT entregado_foto FROM pedidos WHERE id = ?', [$S]) === $FOTO);
ok('dos veces no', !pedido_entregado_marcar($S, $FOTO)['ok']);
es('sale de «Por entregar» y entra en «Entregados hoy»', [0, $S], [pedidos_por_entregar_n(), (int)(pedidos_entregados_hoy()[0]['id'] ?? 0)]);
$h = array_values(array_filter(pedido_hitos(pedido_de($S)), fn($x) => $x['clave'] === 'entregado'))[0];
ok('EL HITO «ENTREGADO» SALE DE LA ENTREGA, CON QUIÉN', $h['hecho'] && $h['cuando'] !== '' && $h['quien'] !== '');
$r = pedido_alistado_deshacer($S);
ok('LO ENTREGADO NO SE DESARMA: primero se deshace la entrega', !$r['ok'] && str_contains($r['error'], 'entregado'), $r['error']);
banco_entrar($ADMIN);
$pgx = $cobrar($S, 1);   // un pago más después de entregar no pisa el estado
es('UN PAGO DESPUÉS DE ENTREGAR NO LO MUEVE', 'entregado', $estado($S));
banco_entrar($ALMACEN);
ok('deshacer el mismo día', pedido_entregado_deshacer($S)['ok']);
es('Y VUELVE A «ALISTADO» (sigue alistado, solo que no entregado)', 'alistado', $estado($S));
q("UPDATE pedidos SET entregado_en = '2020-01-01 10:00:00', entregado_foto = ? WHERE id = ?", [$FOTO, $S]);
ok('lo de otro día ya no se deshace', !pedido_entregado_deshacer($S)['ok']);
q('UPDATE pedidos SET entregado_en = NULL, entregado_foto = NULL WHERE id = ?', [$S]);
pedido_estado_auto($S);
$MX = insertar('paises', ['nombre' => 'México', 'codigo' => 'MX', 'moneda' => 'MXN', 'simbolo' => '$', 'activo' => 1]);
banco_entrar(banco_usuario('almacen', ['pais_id' => $MX]));
ok('Almacén de otro país no lo marca', !pedido_entregado_marcar($S, $FOTO)['ok']);

grupo('3j · la pre venta: solo sale con el lote LISTO PARA ENTREGA');
banco_entrar($CEO);
$GOLD = (int) producto_guardar(null, ['nombre' => 'Silla Gold', 'sku' => 'PRD-200001', 'activo' => 1])['id'];
$L = (int) lote_guardar(null, ['nombre' => 'Contenedor 7', 'fecha_llegada_est' => date('Y-m-d', strtotime('+20 days'))])['id'];
lote_filas_guardar($L, [['codigo' => 'PRD-200001', 'nombre' => 'Silla Gold', 'modelo' => '', 'unidades' => '10']]);
es('SIGUIENTE PASO: el precio', ['precios', 'precio-' . $GOLD], [lote_siguiente_paso(lote_de($L))['paso'], lote_siguiente_paso(lote_de($L))['ancla']]);
precios_guardar($GOLD, null, [['desde' => 1, 'hasta' => '9', 'precio' => '700'], ['desde' => 10, 'hasta' => '', 'precio' => '600']], $L);
es('después, ponerlo a la venta', ['venta', 'form-interruptor'], [lote_siguiente_paso(lote_de($L))['paso'], lote_siguiente_paso(lote_de($L))['ancla']]);
lote_interruptor($L, true);
es('después, marcar que llegó', 'llego', lote_siguiente_paso(lote_de($L))['paso']);
$LL = (int) valor('SELECT id FROM lote_lineas WHERE lote_id = ?', [$L]);
$vender = function (int $llid, int $c) use ($PERU, $CLI, $ASESOR) {
    $pv = preventa_linea($llid, 0, $c);
    $l = ['descripcion' => $pv['linea']['descripcion'], 'modelo' => $pv['linea']['modelo'], 'cantidad' => $c, 'producto_id' => $pv['linea']['producto_id'],
          'precio_unit_centimos' => $pv['linea']['precio_unit_centimos'], 'total_centimos' => $pv['linea']['precio_unit_centimos'] * $c,
          'lote_linea_id' => $llid, 'origen' => 'preventa'];
    return en_transaccion(function () use ($l, $PERU, $CLI, $ASESOR) {
        $id = insertar('pedidos', ['codigo' => 'tmp-' . bin2hex(random_bytes(4)), 'pais_id' => $PERU, 'cliente_id' => $CLI, 'asesor_id' => $ASESOR,
                                   'tipo' => 'preventa', 'estado_id' => (int) estado_por_clave('reservado')['id'], 'fecha' => date('Y-m-d'), 'entrega' => 'recojo']);
        actualizar('pedidos', $id, ['codigo' => pedido_codigo_de_id($id)]);
        preventa_asegurar([$l]);
        insertar('pedido_lineas', $l + ['pedido_id' => $id]);
        pedido_recalcular($id);
        pedido_estado_auto($id, '', false);
        return $id;
    });
};
banco_entrar($ASESOR);
$V = $vender($LL, 2);
es('nace «Reservado» (el lote todavía no viaja)', 'reservado', $estado($V));
$cobrar($V, 140000);
es('COBRADA AL 100 % NO PASA A «PAGADO»: en la pre venta el estado es el del lote', 'reservado', $estado($V));
$m = pedido_despacho_motivo(pedido_de($V));
es('NO SALE: «LLEGA APROX.» CON LA FECHA DEL LOTE', ['preventa', 'Llega aprox. ' . date('d/m', strtotime('+20 days'))], [$m['clave'], $m['texto']]);
ok('Y NO SALE EN «LISTAS PARA DESPACHO» (la misma regla en SQL)', !valor('SELECT 1 FROM pedidos pe WHERE pe.id = ? AND ' . sql_despacho_listo('pe'), [$V]));
es('ni en el número del menú', 0, (int) pedidos_por_despachar_resumen(yo(), true)['n'] + (int) pedidos_por_despachar_resumen(yo())['n']);
$r = pedido_despacho_marcar($V, true);
ok('Y EL SERVIDOR LO FRENA AUNQUE SE FUERCE', !$r['ok'] && str_contains($r['error'], 'Llega aprox.'), $r['error'] ?? '');
q('UPDATE lotes SET fecha_almacen = ? WHERE id = ?', [date('Y-m-d', strtotime('+25 days')), $L]);
es('LA FECHA DEL ALMACÉN MANDA SOBRE LA DE LLEGADA', 'Llega aprox. ' . date('d/m', strtotime('+25 days')), pedido_despacho_motivo(pedido_de($V))['texto']);
banco_entrar($CEO);
$r = lote_listo_marcar($L);
ok('«LISTO PARA ENTREGA» ANTES DE «LLEGÓ», NO', !$r['ok'] && str_contains($r['error'], 'llegó'), $r['error']);
lote_estado_cambiar($L, 'en_camino');
es('EL LOTE VIAJA → SU PEDIDO «EN CAMINO»', 'en_camino', $estado($V));
lote_estado_cambiar($L, 'recibido');
es('LLEGÓ → «PRODUCTO LLEGÓ»', 'llego', $estado($V));
es('el asesor lee «ya llegó», no una fecha', 'Ya llegó · pronto listo para entrega', pedido_despacho_motivo(pedido_de($V))['texto']);
es('SIGUIENTE PASO DEL LOTE: listo para entrega', ['listo', 'form-listo'], [lote_siguiente_paso(lote_de($L))['paso'], lote_siguiente_paso(lote_de($L))['ancla']]);
banco_entrar($ASESOR);
ok('todavía no sale', !pedido_despacho_marcar($V, true)['ok']);
banco_entrar($CEO);
$r = lote_listo_marcar($L);
ok('EL CEO LO MARCA LISTO', $r['ok'] && $r['pedidos'] === 1, $r['error']);
es('SUS PEDIDOS PASAN A «LISTO PARA ENTREGA»', 'listo', $estado($V));
ok('dos veces no', !lote_listo_marcar($L)['ok']);
es('y el lote ya no tiene pasos', '', lote_siguiente_paso(lote_de($L))['paso']);
banco_entrar($ASESOR);
es('AHORA SÍ SALE (sin motivo)', '', pedido_despacho_motivo(pedido_de($V))['clave']);
es('y cuenta en el número del menú', 1, (int) pedidos_por_despachar_resumen(yo(), true)['n'] + (int) pedidos_por_despachar_resumen(yo())['n']);
ok('se manda', pedido_despacho_marcar($V, true)['ok']);
es('→ «En despacho»', 'en_despacho', $estado($V));
banco_entrar($CEO);
$r = lote_listo_deshacer($L);
ok('CON UN PEDIDO YA SALIDO, «LISTO» NO SE DESHACE', !$r['ok'] && str_contains($r['error'], 'salió 1 pedido'), $r['error']);

grupo('3j · una pre venta de DOS lotes espera al más lento');
$L2 = (int) lote_guardar(null, ['nombre' => 'Contenedor 8', 'fecha_llegada_est' => date('Y-m-d', strtotime('+40 days'))])['id'];
$L3 = (int) lote_guardar(null, ['nombre' => 'Contenedor 9', 'fecha_llegada_est' => date('Y-m-d', strtotime('+10 days'))])['id'];
foreach ([$L2, $L3] as $lx) {
    lote_filas_guardar($lx, [['codigo' => 'PRD-200001', 'nombre' => 'Silla Gold', 'modelo' => 'Roja', 'unidades' => '5']]);
    precios_guardar($GOLD, null, [['desde' => 1, 'hasta' => '', 'precio' => '700']], $lx);
    lote_interruptor($lx, true);
}
$l2 = (int) valor('SELECT id FROM lote_lineas WHERE lote_id = ?', [$L2]);
$l3 = (int) valor('SELECT id FROM lote_lineas WHERE lote_id = ?', [$L3]);
banco_entrar($ASESOR);
$V2 = $vender($l2, 1);
$pv3 = preventa_linea($l3, 0, 1);
insertar('pedido_lineas', ['pedido_id' => $V2, 'descripcion' => 'Silla Gold', 'modelo' => 'Roja', 'cantidad' => 1, 'producto_id' => $GOLD,
    'precio_unit_centimos' => $pv3['linea']['precio_unit_centimos'], 'total_centimos' => $pv3['linea']['precio_unit_centimos'], 'lote_linea_id' => $l3, 'origen' => 'preventa']);
pedido_recalcular($V2);
es('LA FECHA QUE SE DICE ES LA DEL LOTE QUE MÁS TARDA', 'Llega aprox. ' . date('d/m', strtotime('+40 days')), pedido_preventa_espera_texto(pedido_de($V2)));
banco_entrar($CEO);
lote_estado_cambiar($L3, 'recibido'); lote_listo_marcar($L3);
lote_estado_cambiar($L2, 'en_camino');
es('UNO LISTO Y OTRO EN CAMINO: «EN CAMINO»', 'en_camino', $estado($V2));
ok('y no sale', !valor('SELECT 1 FROM pedidos pe WHERE pe.id = ? AND ' . sql_preventa_lista('pe'), [$V2]));
lote_estado_cambiar($L2, 'recibido');
es('los dos llegaron, uno listo: «Producto llegó»', 'llego', $estado($V2));
lote_listo_marcar($L2);
es('LOS DOS LISTOS: «LISTO PARA ENTREGA»', 'listo', $estado($V2));
$r = lote_listo_deshacer($L2);
ok('SIN PEDIDOS SALIDOS, SE DESHACE', $r['ok'], $r['error']);
es('y su pedido vuelve a «Producto llegó»', 'llego', $estado($V2));
lote_listo_marcar($L2);

grupo('3j · la pre venta con envío con costo: el costo es opcional');
$LIMA_COSTO = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id WHERE l.clave = 'tipos_envio' AND i.cobra_flete = 1 ORDER BY i.id LIMIT 1");
ok('hay un tipo de envío con costo', $LIMA_COSTO > 0);
$lima = (int) valor("SELECT ud.id FROM ubigeo ud JOIN ubigeo up ON up.id = ud.padre_id WHERE LOWER(up.nombre) = 'lima' ORDER BY ud.id LIMIT 1");
q("UPDATE pedidos SET entrega = 'envio', tipo_envio_item_id = ?, ubigeo_id = ?, flete_lo_paga = 'incluido', flete_centimos = 0 WHERE id = ?", [$LIMA_COSTO, $lima ?: null, $V2]);
ok('SIN EL COSTO, EL PEDIDO LO DICE', pedido_flete_falta(pedido_de($V2)));
ok('y la boleta todavía no se emite: el total va a cambiar', str_contains(pedido_emision_bloqueo(pedido_de($V2)), 'costo del envío'));
$cobrar($V2, 140000);
banco_entrar($ASESOR);
$r = pedido_despacho_marcar($V2, true);
ok('AL MANDARLO A DESPACHO, SE PIDE', !$r['ok'] && ($r['campo'] ?? '') === 'flete' && str_contains($r['error'], 'costo del envío'), json_encode($r));
$r = pedido_despacho_marcar($V2, true, 0);
ok('un costo de 0 no vale: lo pide igual', !$r['ok'] && str_contains($r['error'], 'Falta el costo del envío'), $r['error']);
$r = pedido_despacho_marcar($V2, true, 1500);
ok('CON EL COSTO, SALE', $r['ok'], $r['error'] ?? '');
$p2 = pedido_de($V2);
es('EL COSTO ENTRA EN EL TOTAL', [1500, 141500], [(int)$p2['flete_centimos'], (int)$p2['total_centimos']]);
ok('y ya no falta', !pedido_flete_falta($p2));
/* Desde la ficha, antes de mandar. */
banco_entrar($CEO);
$L5 = (int) lote_guardar(null, ['nombre' => 'Contenedor 11'])['id'];
lote_filas_guardar($L5, [['codigo' => 'PRD-200001', 'nombre' => 'Silla Gold', 'modelo' => 'Azul', 'unidades' => '5']]);
precios_guardar($GOLD, null, [['desde' => 1, 'hasta' => '', 'precio' => '700']], $L5);
lote_interruptor($L5, true);
$V3 = $vender((int) valor('SELECT id FROM lote_lineas WHERE lote_id = ?', [$L5]), 1);
q("UPDATE pedidos SET entrega = 'envio', tipo_envio_item_id = ?, ubigeo_id = ?, flete_lo_paga = 'incluido', flete_centimos = 0 WHERE id = ?", [$LIMA_COSTO, $lima ?: null, $V3]);
banco_entrar($OTRO);
ok('OTRO ASESOR NO PONE EL COSTO', !pedido_flete_poner($V3, 1000)['ok']);
banco_entrar($ASESOR);
$r = pedido_flete_poner($V3, 1000);
ok('SU ASESOR SÍ, DESDE LA FICHA', $r['ok'] && (int) pedido_de($V3)['flete_centimos'] === 1000, $r['error']);
ok('dos veces no', !pedido_flete_poner($V3, 2000)['ok']);
ok('queda en la historia', (bool) valor("SELECT 1 FROM pedido_eventos WHERE pedido_id = ? AND tipo = 'flete'", [$V3]));

grupo('3j · lo que encontró la auditoría');
banco_entrar($ASESOR);
$SINL = $nuevo('preventa', 30000);
es('UNA PRE VENTA SIN LOTE (de cuando se vendía del catálogo) ES «RESERVADO», NO «LISTO»', 'reservado', $estado($SINL));
$cobrar($SINL, 30000);
es('y sale como antes: no hay contenedor que esperar', '', pedido_despacho_motivo(pedido_de($SINL))['clave']);
ok('la misma regla en SQL', (bool) valor('SELECT 1 FROM pedidos pe WHERE pe.id = ? AND ' . sql_despacho_listo('pe'), [$SINL]));
banco_entrar($CEO);
$L6 = (int) lote_guardar(null, ['nombre' => 'Contenedor 12', 'fecha_llegada_est' => date('Y-m-d', strtotime('+30 days'))])['id'];
lote_filas_guardar($L6, [['codigo' => 'PRD-200001', 'nombre' => 'Silla Gold', 'modelo' => 'Verde', 'unidades' => '5']]);
precios_guardar($GOLD, null, [['desde' => 1, 'hasta' => '', 'precio' => '700']], $L6);
lote_interruptor($L6, true);
banco_entrar($ASESOR);
$V6 = $vender((int) valor('SELECT id FROM lote_lineas WHERE lote_id = ?', [$L6]), 1);
$pg6 = pago_registrar($V6, ['monto_centimos' => 7000, 'metodo_item_id' => $efectivo, 'fecha' => date('Y-m-d')]);
es('UN PAGO SIN CONFIRMAR SE DICE (y se puede reclamar) AUNQUE EL LOTE SIGA EN EL MAR', 'sin_revisar', pedido_despacho_motivo(pedido_de($V6))['clave']);
banco_entrar($ADMIN);
pago_denegar((int)$pg6['id'], 'no aparece');
es('UN PAGO DENEGADO TAMBIÉN, NO «LLEGA APROX.»', 'denegado', pedido_despacho_motivo(pedido_de($V6))['clave']);
/* La tarea de las 48 horas: en los estados nuevos, solo una denegación de después de actualizar. */
lote_estado_cambiar($L6, 'en_camino');
es('su pedido va «En camino»', 'en_camino', $estado($V6));
q("UPDATE pagos SET anulado_en = ? WHERE id = ?", [date('Y-m-d H:i:s', strtotime('-3 days')), (int)$pg6['id']]);
$desde_antes = (string) ajuste('entrega_arranque', '');
q("UPDATE ajustes SET valor = ? WHERE clave = 'entrega_arranque'", [date('Y-m-d H:i:s', strtotime('-1 day'))]); ajustes_olvidar();
pedidos_anular_denegados();
ok('DENEGADO ANTES DE ACTUALIZAR: «EN CAMINO» NO SE ANULA SOLO', valor('SELECT anulado_en FROM pedidos WHERE id = ?', [$V6]) === null && !pedido_se_anula_solo(pedido_de($V6)));
q("UPDATE ajustes SET valor = ? WHERE clave = 'entrega_arranque'", [date('Y-m-d H:i:s', strtotime('-5 days'))]); ajustes_olvidar();
ok('la ficha y la tarea dicen lo mismo', pedido_se_anula_solo(pedido_de($V6)));
pedidos_anular_denegados();
ok('DENEGADO DESPUÉS: SÍ, COMO ANTES CON «RESERVADO»', valor('SELECT anulado_en FROM pedidos WHERE id = ?', [$V6]) !== null);
q("UPDATE ajustes SET valor = ? WHERE clave = 'entrega_arranque'", [$desde_antes]); ajustes_olvidar();
$SF = $nuevo('inmediata', 1000, ['entrega' => 'envio', 'flete_lo_paga' => 'incluido', 'flete_centimos' => 0]);
ok('UNA VENTA DE STOCK NUNCA «DEBE» EL COSTO DEL ENVÍO (aunque cambien la lista)', !pedido_flete_falta(pedido_de($SF)));
ok('«Enviados» sabe si recoge en la agencia', array_key_exists('recojo_agencia', pedidos_enviados(enviados_filtros([]), null, 1)[0] ?? ['recojo_agencia' => 0]));

grupo('3j · la tarea que anula lo denegado alcanza a la pre venta que no salió');
es('los estados «sin salir»', ['registrado', 'reservado', 'en_camino', 'llego', 'listo'], estados_sin_salir());
ok('«En despacho» no está: lo que salió no se anula solo', !in_array('en_despacho', estados_sin_salir(), true));

grupo('3j · Enviados: lo que el asesor ya mandó, con sus fotos');
banco_entrar($ASESOR);
$fl = enviados_filtros([]);
es('POR DEFECTO, LOS ÚLTIMOS 30 DÍAS', [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')], [$fl['desde'], $fl['hasta']]);
es('fechas al revés se ordenan; basura se ignora', ['2026-01-01', '2026-02-01', '', ''],
   array_values(enviados_filtros(['ed' => '2026-02-01', 'eh' => '2026-01-01', 'ez' => 'marte', 'eq' => '']) ));
$env = array_map(fn($x) => (int)$x['id'], pedidos_enviados($fl));
ok('SALEN LOS SUYOS YA MANDADOS', in_array($S, $env, true) && in_array($V, $env, true) && in_array($V2, $env, true));
ok('no lo que no salió', !in_array($V3, $env, true));
es('BUSCAR POR DNI', true, in_array($S, array_map(fn($x) => (int)$x['id'], pedidos_enviados(['q' => '70636210'] + $fl)), true));
es('POR PRODUCTO', [true, false], [in_array($S, array_map(fn($x) => (int)$x['id'], pedidos_enviados(['q' => 'gamer negra'] + $fl)), true),
                                  in_array($S, array_map(fn($x) => (int)$x['id'], pedidos_enviados(['q' => 'mesa de billar'] + $fl)), true)]);
es('POR NOMBRE', true, in_array($S, array_map(fn($x) => (int)$x['id'], pedidos_enviados(['q' => 'quispe'] + $fl)), true));
es('LIMA / PROVINCIA', [true, false], [in_array($V2, array_map(fn($x) => (int)$x['id'], pedidos_enviados(['z' => 'lima'] + $fl)), true),
                                      in_array($V2, array_map(fn($x) => (int)$x['id'], pedidos_enviados(['z' => 'provincia'] + $fl)), true)]);
es('FUERA DE LAS FECHAS NO', [], pedidos_enviados(['desde' => '2020-01-01', 'hasta' => '2020-01-31'] + $fl));
$uno = array_values(array_filter(pedidos_enviados($fl), fn($x) => (int)$x['id'] === $S))[0];
ok('TRAE LA FOTO DE LO ALISTADO', (string)$uno['alistado_foto'] === $FOTO);
$pgv = insertar('pagos', ['pedido_id' => $S, 'monto_centimos' => 100, 'metodo_item_id' => $efectivo, 'fecha' => date('Y-m-d'), 'voucher' => $FOTO, 'verificado' => 0, 'anulado' => 0]);
ok('Y SUS VOUCHERS', in_array($pgv, pedidos_vouchers_de([$S])[$S] ?? [], true));
banco_entrar($OTRO);
es('OTRO ASESOR NO VE LOS ENVÍOS DE ESTE', [], pedidos_enviados($fl));

grupo('3j · la casilla «Es repuesto»: las máquinas para elegir');
banco_entrar($CEO);
$MAQ = (int) producto_guardar(null, ['nombre' => 'Máquina peluchera', 'sku' => 'PRD-300001', 'activo' => 1])['id'];
$JOY = (int) producto_guardar(null, ['nombre' => 'Joystick', 'sku' => 'PRD-300002', 'activo' => 1])['id'];
repuesto_colgar($JOY, $MAQ);
$L4 = (int) lote_guardar(null, ['nombre' => 'Contenedor 10'])['id'];
lote_filas_guardar($L4, [['codigo' => 'PRD-200001', 'nombre' => 'Silla Gold', 'modelo' => '', 'unidades' => '1'],
                         ['codigo' => '', 'nombre' => 'Botón', 'modelo' => '', 'unidades' => '4', 'maquina' => 'PRD-300001']]);
$mq = maquinas_para_elegir($L4, $PERU);
es('PRIMERO LAS DEL LOTE (no sus repuestos)', ['PRD-200001'], array_column($mq['lote'], 'sku'));
ok('DESPUÉS LAS DEL CATÁLOGO, SIN REPETIR NI REPUESTOS', in_array('PRD-300001', array_column($mq['catalogo'], 'sku'), true)
   && !in_array('PRD-200001', array_column($mq['catalogo'], 'sku'), true) && !in_array('PRD-300002', array_column($mq['catalogo'], 'sku'), true));

grupo('3j · solo se busca a quien se le puede vender');
$LIDER = banco_usuario('asesor');
$EQ = insertar('equipos', ['nombre' => 'Equipo A', 'pais_id' => $PERU, 'lider_usuario_id' => $LIDER]);
q('UPDATE usuarios SET equipo_id = ? WHERE id IN (?, ?)', [$EQ, $LIDER, $ASESOR]);
q("UPDATE usuarios SET ambito = 'equipo' WHERE id = ?", [$LIDER]);
$SIN = insertar('clientes', ['pais_id' => $PERU, 'asesor_id' => null, 'tipo_doc' => 'DNI', 'documento' => '70636299', 'nombre' => 'Sin', 'apellidos' => 'Dueño', 'email' => 'sd@waka.test', 'activo' => 1]);
banco_entrar($LIDER);
[$sq, $pq] = sql_puedo_venderle('c');
$ids = array_map('intval', array_column(todas("SELECT c.id FROM clientes c WHERE $sq", $pq), 'id'));
ok('EL LÍDER NO ENCUENTRA AL CLIENTE DE SU ASESOR (lo vería, pero no le vende)', !in_array($CLI, $ids, true));
ok('sí al que no tiene dueño', in_array($SIN, $ids, true));
ok('LA MISMA REGLA EN PHP Y EN SQL', !puedo_venderle(una('SELECT * FROM clientes WHERE id = ?', [$CLI])) && puedo_venderle(una('SELECT * FROM clientes WHERE id = ?', [$SIN])));
banco_entrar($ASESOR);
[$sq, $pq] = sql_puedo_venderle('c');
ok('su asesor sí lo encuentra', in_array($CLI, array_map('intval', array_column(todas("SELECT c.id FROM clientes c WHERE $sq", $pq), 'id')), true));
banco_entrar($ADMIN);
[$sq, $pq] = sql_puedo_venderle('c');
ok('Administración, todos los de su país', in_array($CLI, array_map('intval', array_column(todas("SELECT c.id FROM clientes c WHERE $sq", $pq), 'id')), true));
banco_entrar($CEO);
ok('Dirección no vende', sql_puedo_venderle('c')[0] === '1 = 0' && !puedo_venderle(una('SELECT * FROM clientes WHERE id = ?', [$SIN])));

grupo('3j · lo que ya había antes de la 3j');
q("DELETE FROM ajustes WHERE clave = 'entrega_arranque'"); ajustes_olvidar();
$VIEJO = $nuevo('inmediata', 1000);
$VIEJO2 = $nuevo('inmediata', 1000);
q("UPDATE pedidos SET despacho_veces = 1, despacho_en = '2026-01-10 10:00:00', estado_id = ? WHERE id = ?", [(int) estado_por_clave('entregado')['id'], $VIEJO]);
q("UPDATE pedidos SET despacho_veces = 1, despacho_en = '2026-01-10 10:00:00' WHERE id = ?", [$VIEJO2]);
$LV = (int) lote_guardar(null, ['nombre' => 'Contenedor viejo'])['id'];
q("UPDATE lotes SET estado = 'recibido', fecha_llegada_real = '2026-01-05', listo_en = NULL WHERE id = ?", [$LV]);
$m = entrega_arranque();
ok('LO QUE ESTABA «ENTREGADO» GUARDA SU FECHA', valor('SELECT entregado_en FROM pedidos WHERE id = ?', [$VIEJO]) !== null && $estado($VIEJO) === 'entregado', $m);
ok('LO QUE SALIÓ HACE MÁS DE UNA SEMANA SE DA POR ENTREGADO (sin foto)', valor('SELECT entregado_en FROM pedidos WHERE id = ?', [$VIEJO2]) !== null
   && valor('SELECT entregado_foto FROM pedidos WHERE id = ?', [$VIEJO2]) === null && $estado($VIEJO2) === 'entregado');
ok('UN LOTE QUE YA HABÍA LLEGADO QUEDA LISTO PARA ENTREGA', valor('SELECT listo_en FROM lotes WHERE id = ?', [$LV]) !== null);
ok('lo que salió esta semana sigue por entregar', valor('SELECT entregado_en FROM pedidos WHERE id = ?', [$V]) === null);
ok('una segunda vez no hace nada', entrega_arranque() === '');

grupo('3j · anular');
banco_entrar($ADMIN);
pedido_anular($V3, $MOTIVO, 'prueba');
es('el anulado no se mueve más', ['anulado', ''], [$estado($V3), pedido_estado_auto($V3)]);
ok('ni pide costo de envío', !pedido_flete_falta(pedido_de($V3)));

exit(marcador());
