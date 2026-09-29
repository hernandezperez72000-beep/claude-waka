<?php
declare(strict_types=1);
/**
 * REPUESTOS Y GARANTÍA (3i).
 *
 * El repuesto colgado de su máquina (pieza por pieza), su stock en el HUB
 * (entra al llegar el lote, sale con la venta y con la garantía, vuelve al
 * anular), la vigencia de la garantía (desde la primera salida a despacho más
 * los meses), pedirla desde el pedido, aprobarla o no, y que NO sea una venta:
 * ni meta, ni cashback, ni cobro. La pantalla de verdad la prueba correr3.
 */
require_once __DIR__ . '/comun.php';
banco_crear(sys_get_temp_dir() . '/waka-pruebas9.sqlite');
migraciones_tras_semillas();              // lo mismo que hace el instalador

$CEO    = banco_usuario('direccion');
$ADMIN  = banco_usuario('administracion');
$ASESOR = banco_usuario('asesor');
$OTRO   = banco_usuario('asesor');
$ALMACEN= banco_usuario('almacen');
$FACTU  = banco_usuario('facturacion');
banco_entrar($ADMIN);
$PERU = catalogo_pais();
$pr_rol = fn(string $r, string $p) => puede_el(['rol' => $r, 'rol_id' => (int) valor('SELECT id FROM roles WHERE clave = ?', [$r])], $p);
$pon_control = function (bool $on) {
    q("UPDATE ajustes SET valor = ? WHERE clave = 'control_stock'", [$on ? '1' : '0']);
    ajustes_olvidar();
};

grupo('3i · la base');
ok('las tablas y columnas nuevas están', garantias_listo() && repuestos_listo() && columna_existe('stock_web_cola', 'garantia_id'));
ok('EL ASESOR PIDE, NO APRUEBA', $pr_rol('asesor', 'garantias.pedir') && !$pr_rol('asesor', 'garantias.aprobar'));
ok('ADMINISTRACIÓN APRUEBA', $pr_rol('administracion', 'garantias.aprobar') && $pr_rol('administracion', 'garantias.pedir'));
ok('Dirección las ve pero no las aprueba ni las pide', $pr_rol('direccion', 'garantias.ver') && !$pr_rol('direccion', 'garantias.aprobar') && !$pr_rol('direccion', 'garantias.pedir'));
ok('Almacén y Facturación no piden ni aprueban', !$pr_rol('almacen', 'garantias.pedir') && !$pr_rol('almacen', 'garantias.aprobar') && !$pr_rol('facturacion', 'garantias.pedir'));
$lg = (int) valor("SELECT id FROM listas WHERE clave = 'garantias'");
es('LOS MESES DE CADA GARANTÍA SALEN DEL TEXTO: 12, 6 y 0', [12, 6, 0],
   array_map('intval', array_column(todas('SELECT meses FROM lista_items WHERE lista_id = ? ORDER BY orden', [$lg]), 'meses')));
es('«1 año» son 12, «18 meses» 18, lo que no se entiende null', [12, 18, null, 0], [garantia_meses_de_texto('1 año'), garantia_meses_de_texto('18 meses'),
   garantia_meses_de_texto('De por vida'), garantia_meses_de_texto('Sin garantía')]);
ok('la marca de arranque queda puesta', garantia_arranque() !== '');
es('31/01 + 1 mes es 28/02 (no salta a marzo)', '2027-02-28', fecha_mas_meses('2027-01-31', 1));
es('15/03/2026 + 12 meses', '2027-03-15', fecha_mas_meses('2026-03-15', 12));
es('29/02/2028 + 12 meses es 28/02/2029', '2029-02-28', fecha_mas_meses('2028-02-29', 12));

grupo('3i · el repuesto cuelga de su máquina');
$MAQ = (int) producto_guardar(null, ['nombre' => 'Máquina peluchera clásica', 'sku' => 'PRD-004569', 'activo' => 1])['id'];
precios_guardar($MAQ, null, [['desde' => 1, 'hasta' => '', 'precio' => '2500.00']]);
$MAQ2 = (int) producto_guardar(null, ['nombre' => 'Máquina de boxeo', 'sku' => 'PRD-009001', 'activo' => 1])['id'];
precios_guardar($MAQ2, null, [['desde' => 1, 'hasta' => '', 'precio' => '3500.00']]);
$JOY = (int) producto_guardar(null, ['nombre' => 'Joystick', 'activo' => 1])['id'];
$r = repuesto_colgar($JOY, $MAQ);
ok('se cuelga', $r['ok'] && producto_es_repuesto(producto_de($JOY)) && (int) producto_de($JOY)['padre_id'] === $MAQ, $r['error']);
ok('SU STOCK LO LLEVA EL HUB (no está en la web)', stock_hub_aplica(producto_de($JOY)));
ok('una máquina no', !stock_hub_aplica(producto_de($MAQ)));
$r = repuesto_colgar($MAQ, $JOY);
ok('una máquina con repuestos no puede ser repuesto de otra', !$r['ok'] && str_contains($r['error'], 'propios repuestos'), $r['error']);
$BOT = (int) producto_guardar(null, ['nombre' => 'Botón', 'activo' => 1])['id'];
$r = repuesto_colgar($BOT, $JOY);
ok('ni un repuesto tener repuestos (un solo nivel)', !$r['ok'] && str_contains($r['error'], 'ya es un repuesto'), $r['error']);
$r = repuesto_colgar($JOY, $JOY);
ok('ni de sí mismo', !$r['ok']);
repuesto_colgar($BOT, $MAQ);
es('los repuestos de la máquina, con su máquina', ['Botón', 'Joystick'], array_column(repuestos_de([$MAQ]), 'nombre'));
ok('maquina_por_codigo no devuelve un repuesto', maquina_por_codigo((string) producto_de($JOY)['sku']) === null && (int)(maquina_por_codigo('prd-004569')['id'] ?? 0) === $MAQ);
/* Un repuesto que SÍ está en la web usa el stock de la web. */
$CARC = (int) producto_guardar(null, ['nombre' => 'Carcasa', 'activo' => 1])['id'];
q('UPDATE productos SET woo_id = 777 WHERE id = ?', [$CARC]);
repuesto_colgar($CARC, $MAQ);
ok('UN REPUESTO QUE ESTÁ EN LA WEB USA EL STOCK DE LA WEB', !stock_hub_aplica(producto_de($CARC)));

grupo('3i · el kit en el lote, pieza por pieza');
banco_entrar($CEO);
$L = (int) lote_guardar(null, ['nombre' => 'Contenedor 5'])['id'];
$r = lote_filas_guardar($L, [
    ['codigo' => 'PRD-004569', 'nombre' => 'Máquina peluchera clásica', 'modelo' => '', 'unidades' => '10'],
    ['codigo' => '', 'nombre' => 'Kit de repuestos', 'modelo' => '4 botones, 6 joysticks, 2 cables de alimentación', 'unidades' => '12', 'maquina' => 'PRD-004569'],
    ['codigo' => '', 'nombre' => 'Palanca', 'modelo' => '', 'unidades' => '3', 'maquina' => 'PRD-009999'],
]);
ok('se guarda', $r['ok'], $r['error']);
$fl = lote_filas($L);
ok('LAS PIEZAS DEL KIT NACEN «REVISA» (el HUB propone, el CEO confirma)', (int)$fl[1]['resuelto'] === 0 && (int)$fl[2]['resuelto'] === 0
   && (int)$fl[3]['resuelto'] === 0 && str_contains((string)$fl[1]['aviso'], 'kit') && (int)$fl[0]['resuelto'] === 1);
es('UNA FILA POR PIEZA, CADA UNA CON SUS UNIDADES', [['Máquina peluchera clásica', 10, ''], ['Botón', 4, 'PRD-004569'], ['Joystick', 6, 'PRD-004569'],
   ['Cables de alimentación', 2, 'PRD-004569'], ['Palanca', 3, 'PRD-009999']],
   array_map(fn($f) => [(string)$f['producto_nombre'], (int)$f['unidades'], (string)$f['maquina']], $fl));
/* «Botones» y «Joysticks» ya existían bajo la máquina: el nombre en singular no
   es el mismo, así que son productos nuevos… salvo que coincida. */
$cables = (int)($fl[3]['producto_id'] ?? 0);
ok('la pieza nueva queda colgada de su máquina', (int)(producto_de($cables)['padre_id'] ?? 0) === $MAQ);
ok('UNA PIEZA QUE YA EXISTE BAJO ESA MÁQUINA (mismo nombre) ES LA MISMA', (int)$fl[1]['producto_id'] === $BOT && (int)$fl[2]['producto_id'] === $JOY);
ok('LA MÁQUINA QUE NO ESTÁ EN EL CATÁLOGO DEJA LA FILA «REVISA»', (int)$fl[4]['resuelto'] === 0 && str_contains((string)$fl[4]['aviso'], 'PRD-009999')
   && !producto_es_repuesto(producto_de((int)$fl[4]['producto_id'])));
$r = lote_filas_guardar($L, [['codigo' => 'PRD-004569', 'nombre' => 'x', 'modelo' => '', 'unidades' => '1', 'maquina' => 'PRD-004569']]);
ok('el código de la máquina no vale como código del repuesto', !$r['ok'] && str_contains($r['error'], 'es el de la máquina'), $r['error']);
/* Mismo nombre, otra máquina: otro producto. */
$L9 = (int) lote_guardar(null, ['nombre' => 'Contenedor 9'])['id'];
$r = lote_filas_guardar($L9, [
    ['codigo' => 'PRD-009001', 'nombre' => 'Máquina de boxeo', 'modelo' => '', 'unidades' => '1'],
    ['codigo' => '', 'nombre' => 'Joystick', 'modelo' => '', 'unidades' => '2', 'maquina' => 'PRD-009001'],
]);
$fl9 = lote_filas($L9);
ok('EL «JOYSTICK» DE OTRA MÁQUINA ES OTRO PRODUCTO', $r['ok'] && (int)$fl9[1]['producto_id'] !== $JOY && (int) producto_de((int)$fl9[1]['producto_id'])['padre_id'] === $MAQ2, $r['error']);
$JOY2 = (int)$fl9[1]['producto_id'];
/* Un código mal escrito no convierte una máquina en repuesto de otra. */
$r = lote_filas_guardar($L9, array_merge(array_map(fn($f) => ['id' => $f['id'], 'codigo' => (string)$f['codigo'], 'nombre' => (string)$f['producto_nombre'],
       'modelo' => (string)$f['modelo'], 'unidades' => (string)$f['unidades'], 'maquina' => (string)$f['maquina']], $fl9),
       [['codigo' => 'PRD-009001', 'nombre' => 'Máquina de boxeo', 'modelo' => 'Roja', 'unidades' => '1', 'maquina' => 'PRD-004569']]));
$fl9b = lote_filas($L9);
ok('UNA MÁQUINA QUE YA ESTÁ EN EL CATÁLOGO NO SE CUELGA DESDE EL LOTE: queda «Revisa»', $r['ok'] && producto_de($MAQ2)['padre_id'] === null
   && (int)end($fl9b)['resuelto'] === 0 && str_contains((string)end($fl9b)['aviso'], 'desde su ficha'), $r['error']);
q('DELETE FROM lote_lineas WHERE id = ?', [(int)end($fl9b)['id']]);

grupo('3i · los repuestos no se venden en pre venta');
precios_guardar($MAQ, null, [['desde' => 1, 'hasta' => '', 'precio' => '2300.00']], $L);
precios_guardar($MAQ2, null, [['desde' => 1, 'hasta' => '', 'precio' => '3300.00']], $L9);
es('EL LOTE PIDE PRECIO SOLO DE LA MÁQUINA (no de sus repuestos, tampoco de uno sin máquina encontrada)', [$MAQ], array_map(fn($p) => (int)$p['id'], lote_productos($L)));
q("UPDATE lote_lineas SET resuelto = 1 WHERE lote_id = ?", [$L]);
lote_interruptor($L, true);
$dispo = preventa_disponible();
$ids_dispo = [];
foreach ($dispo as $gd) foreach ($gd['productos'] as $pp) $ids_dispo[] = (int)$pp['id'];
ok('EN «DISPONIBLE PARA PRE VENTA» NO SALE NINGÚN REPUESTO', in_array($MAQ, $ids_dispo, true) && !in_array($JOY, $ids_dispo, true) && !in_array($cables, $ids_dispo, true));
$pvx = preventa_linea((int)$fl[2]['id'], $JOY, 1);
ok('y una venta armada a mano tampoco lo vende', !$pvx['ok'] && str_contains($pvx['error'], 'repuesto'), $pvx['error']);
/* Un repuesto metido en el lote con su propio código, sin «Repuesto de»: sigue siendo un repuesto. */
$L7 = (int) lote_guardar(null, ['nombre' => 'Contenedor 7'])['id'];
lote_filas_guardar($L7, [['codigo' => (string) producto_de($JOY)['sku'], 'nombre' => 'Joystick', 'modelo' => '', 'unidades' => '5']]);
$fl7 = lote_filas($L7);
ok('UN REPUESTO CON SU CÓDIGO Y SIN «REPUESTO DE» SIGUE SIN VENDERSE EN PRE VENTA', $fl7[0]['es_repuesto'] && lote_productos($L7) === []
   && !preventa_linea((int)$fl7[0]['id'], $JOY, 1)['ok']);
q('DELETE FROM lote_lineas WHERE lote_id = ?', [$L7]);
es('el chip de lotes sin precio no cuenta los repuestos', 0, lotes_sin_precio_n($PERU));
q("UPDATE lote_lineas SET maquina = NULL WHERE id = ?", [(int)$fl[4]['id']]);
es('la Palanca sin «Repuesto de» ya es un producto de pre venta: pide precio', 1, lotes_sin_precio_n($PERU));
precios_guardar((int)$fl[4]['producto_id'], null, [['desde' => 1, 'hasta' => '', 'precio' => '10.00']], $L);
es('con la palanca con precio, ninguno', 0, lotes_sin_precio_n($PERU));
es('un repuesto sin precio no cuenta como «sin precio» en el catálogo', 0,
   (int) valor('SELECT COUNT(*) FROM productos p WHERE p.id IN (?, ?) AND p.padre_id IS NULL', [$JOY, $cables]));

grupo('3i · el lote llega: los repuestos entran al almacén');
es('antes de llegar, no hay', [$BOT => 0, $JOY => 0], stock_hub_de([$BOT, $JOY]));
$r = lote_estado_cambiar($L, 'recibido');
ok('llega', $r['ok'], $r['error']);
es('ENTRAN LOS REPUESTOS QUE NO ESTÁN EN LA WEB, con sus unidades', [4, 6, 2], array_values(stock_hub_de([$BOT, $JOY, $cables])));
es('lo dice', 12, (int)$r['repuestos']);
es('la máquina no entra (su stock es el de la web)', 0, (int) valor('SELECT COUNT(*) FROM stock_hub WHERE producto_id = ?', [$MAQ]));
es('con su rastro', 'Llegó el lote Contenedor 5', stock_hub_mov_texto(stock_hub_movimientos($JOY, 1)[0] ?? []));
es('SOLO UNA VEZ', 0, stock_hub_lote_al_dia($L));
$r2 = lote_estado_cambiar($L, 'recibido');
ok('«LLEGÓ» DOS VECES NO METE DOS VECES', stock_hub_de([$JOY])[$JOY] === 6);
/* Lo que se corrige en un lote ya llegado se pone al día; lo que está en «Revisa», no. */
$fl_ll = lote_filas($L);
$f_form = array_map(fn($f) => ['id' => $f['id'], 'codigo' => (string)$f['codigo'], 'nombre' => (string)$f['producto_nombre'],
                               'modelo' => (string)$f['modelo'], 'unidades' => (string)$f['unidades'], 'maquina' => (string)$f['maquina']], $fl_ll);
$f_form[2]['unidades'] = '8';                                           // los joysticks eran 8
$f_form[] = ['codigo' => '', 'nombre' => 'Palanca de mando', 'modelo' => '', 'unidades' => '7', 'maquina' => 'PRD-004569'];
$f_form[] = ['codigo' => '', 'nombre' => 'Motor', 'modelo' => '4 motor 12V, 2 luces', 'unidades' => '6', 'maquina' => 'PRD-004569'];
$r = lote_filas_guardar($L, $f_form);
ok('SE CORRIGE UN LOTE YA LLEGADO', $r['ok'], $r['error']);
$PAL = (int) valor("SELECT id FROM productos WHERE nombre = 'Palanca de mando'");
$MOT = (int) valor("SELECT id FROM productos WHERE nombre = 'Motor 12V'");
es('LA DIFERENCIA ENTRA (6 → 8) Y LA FILA NUEVA ENTRA', [8, 7], [stock_hub_de([$JOY])[$JOY], stock_hub_de([$PAL])[$PAL] ?? -1]);
ok('«Motor 12V» es una pieza (el 12 es del nombre) y nace «Revisa»: NO ENTRA hasta confirmarla', $MOT > 0 && (stock_hub_de([$MOT])[$MOT] ?? -1) === 0);
$fl_ll = lote_filas($L);
$f_form = array_map(fn($f) => ['id' => $f['id'], 'codigo' => (string)$f['codigo'], 'nombre' => (string)$f['producto_nombre'],
                               'modelo' => (string)$f['modelo'], 'unidades' => (string)$f['unidades'], 'maquina' => (string)$f['maquina'],
                               'revisado' => (string)$f['producto_nombre'] === 'Motor 12V'], $fl_ll);
lote_filas_guardar($L, $f_form);
es('AL DARLA POR REVISADA, ENTRA', 4, stock_hub_de([$MOT])[$MOT] ?? -1);
/* Si Almacén ya contó, su conteo manda: el lote no vuelve a mover ese producto. */
stock_hub_poner($PAL, 5, 'Conteo en el almacén');
$fl_ll = lote_filas($L);
$f_form = array_map(fn($f) => ['id' => $f['id'], 'codigo' => (string)$f['codigo'], 'nombre' => (string)$f['producto_nombre'],
                               'modelo' => (string)$f['modelo'], 'unidades' => (string)((string)$f['producto_nombre'] === 'Palanca de mando' ? 9 : $f['unidades']),
                               'maquina' => (string)$f['maquina']], $fl_ll);
lote_filas_guardar($L, $f_form);
es('CONTADO POR ALMACÉN, CORREGIR EL LOTE NO LO CUENTA DOS VECES', 5, stock_hub_de([$PAL])[$PAL]);
stock_hub_poner($JOY, 6, 'Vuelve a como estaba para las pruebas');
/* Un lote que llegó antes de la 3i no mete nada al guardarlo. */
$LV = (int) lote_guardar(null, ['nombre' => 'Contenedor viejo'])['id'];
lote_filas_guardar($LV, [['codigo' => '', 'nombre' => 'Palanca vieja', 'modelo' => '', 'unidades' => '50', 'maquina' => 'PRD-004569']]);
q("UPDATE lote_lineas SET resuelto = 1 WHERE lote_id = ?", [$LV]);
q("UPDATE lotes SET estado = 'recibido', fecha_llegada_real = '2026-01-10' WHERE id = ?", [$LV]);
$flv = lote_filas($LV);
lote_filas_guardar($LV, [['id' => $flv[0]['id'], 'codigo' => '', 'nombre' => 'Palanca antigua', 'modelo' => '', 'unidades' => '50', 'maquina' => 'PRD-004569']]);
es('UN LOTE QUE LLEGÓ ANTES DE LA 3i NO METE STOCK FANTASMA', 0, stock_hub_de([(int)$flv[0]['producto_id']])[(int)$flv[0]['producto_id']]);
es('«12V motor» y «3.5mm jack»: el número pegado es del nombre', ['12V motor', '3.5mm jack'], array_column(repuesto_partir_kit('12V motor, 3.5mm jack', 1), 'nombre'));


grupo('3i · Almacén pone lo que hay');
banco_entrar($ALMACEN);
$r = stock_hub_poner($JOY, 5, '');
ok('sin motivo no', !$r['ok'] && str_contains($r['error'], 'motivo'));
$r = stock_hub_poner($JOY, -2, 'x');
ok('un número negativo no', !$r['ok']);
$r = stock_hub_poner($MAQ, 5, 'conteo');
ok('el de una máquina no se pone aquí', !$r['ok'] && str_contains($r['error'], 'no se lleva aquí'));
$r = stock_hub_poner($JOY, 5, 'Conteo');
ok('se pone', $r['ok'] && (stock_hub_de([$JOY])[$JOY] ?? 0) === 5);
es('queda la diferencia en el rastro', [-1, 5, 'ajuste'], [(int) stock_hub_movimientos($JOY, 1)[0]['cantidad'], (int) stock_hub_movimientos($JOY, 1)[0]['queda'], (string) stock_hub_movimientos($JOY, 1)[0]['motivo']]);

grupo('3i · el buscador de la venta: el repuesto bajo su máquina');
banco_entrar($ASESOR);
precios_guardar($JOY, null, [['desde' => 1, 'hasta' => '', 'precio' => '80.00']]);
$bus = catalogo_buscar('peluchera');
es('BUSCAR LA MÁQUINA TRAE SUS REPUESTOS, DETRÁS DE ELLA (los que tienen precio)', ['Máquina peluchera clásica', 'Joystick'], array_column($bus, 'nombre'));
es('dice de qué máquina es y lo que hay en el almacén', ['Máquina peluchera clásica', '5 en almacén'], [$bus[1]['maquina'] ?? '', $bus[1]['stock']['texto'] ?? '']);
ok('EL REPUESTO SIN PRECIO NO SE OFRECE (solo sale por garantía)', !in_array('Botón', array_column(catalogo_buscar('peluchera'), 'nombre'), true));
es('en una pre venta no sale ningún repuesto', ['Máquina peluchera clásica'], array_column(catalogo_buscar('peluchera', 8, true), 'nombre'));

grupo('3i · la venta del repuesto saca del almacén');
$CLI = insertar('clientes', ['pais_id' => $PERU, 'asesor_id' => $ASESOR, 'tipo_doc' => 'DNI', 'documento' => '70636199', 'nombre' => 'Cliente',
                             'apellidos' => 'Garantía', 'email' => 'c9@waka.test', 'celular' => '900000009', 'activo' => 1]);
$MOTIVO = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id WHERE l.clave = 'motivos_anulacion' ORDER BY i.id LIMIT 1");
$G12 = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id WHERE l.clave = 'garantias' AND i.valor = '12 meses'");
$GNO = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id WHERE l.clave = 'garantias' AND i.valor = 'No aplica'");
$vender = function (array $lineas, string $tipo = 'inmediata', int $asesor = 0, int $garantia = 0) use ($PERU, $CLI, $ASESOR, $G12) {
    try {
        return en_transaccion(function () use ($lineas, $tipo, $PERU, $CLI, $ASESOR, $asesor, $garantia, $G12) {
            $id = insertar('pedidos', ['codigo' => 'tmp-' . bin2hex(random_bytes(4)), 'pais_id' => $PERU, 'cliente_id' => $CLI,
                                       'asesor_id' => $asesor ?: $ASESOR, 'tipo' => $tipo, 'estado_id' => (int) estado_por_clave('registrado')['id'],
                                       'fecha' => date('Y-m-d'), 'entrega' => 'recojo', 'garantia_item_id' => $garantia ?: $G12]);
            actualizar('pedidos', $id, ['codigo' => pedido_codigo_de_id($id)]);
            foreach ($lineas as [$pid, $c]) {
                insertar('pedido_lineas', ['pedido_id' => $id, 'descripcion' => (string) producto_de($pid)['nombre'], 'cantidad' => $c,
                                           'precio_unit_centimos' => 8000, 'total_centimos' => 8000 * $c, 'producto_id' => $pid]);
            }
            stock_hub_venta($id, array_map(fn($x) => ['producto_id' => $x[0], 'cantidad' => $x[1]], $lineas), $tipo, $PERU);
            pedido_recalcular($id);
            return $id;
        });
    } catch (StockHubAgotado $ex) { return $ex->getMessage(); }
};
$pon_control(true);
$V1 = $vender([[$JOY, 2], [$MAQ, 1]]);
ok('se vende', is_int($V1));
es('SALEN 2 DEL ALMACÉN', 3, stock_hub_de([$JOY])[$JOY]);
$V2 = $vender([[$JOY, 4]]);
ok('CON EL CONTROL DE STOCK ENCENDIDO, SIN UNIDADES NO SE VENDE', is_string($V2) && str_contains($V2, 'quedan 3'), (string)$V2);
ok('y no queda nada a medias', !valor("SELECT 1 FROM pedidos WHERE codigo LIKE 'tmp-%'") && stock_hub_de([$JOY])[$JOY] === 3);
$V3 = $vender([[$JOY, 1]], 'preventa');
ok('una pre venta no toca el almacén', is_int($V3) && stock_hub_de([$JOY])[$JOY] === 3);
$pon_control(false);
$V4 = $vender([[$JOY, 5]]);
ok('CON EL CONTROL APAGADO SE APUNTA Y NO FRENA (queda en negativo)', is_int($V4) && stock_hub_de([$JOY])[$JOY] === -2);
es('y se dice agotado', 'rojo', stock_hub_texto(-2)['tono']);
banco_entrar($ADMIN);
pedido_anular($V4, $MOTIVO, 'prueba');
es('ANULAR DEVUELVE AL ALMACÉN', 3, stock_hub_de([$JOY])[$JOY]);
es('una vez, aunque se llame dos', 0, stock_hub_devolver('pedido', $V4, 'anulacion'));
es('y sin dejar movimientos de cero unidades en el rastro', 0, (int) valor('SELECT COUNT(*) FROM stock_hub_mov WHERE cantidad = 0'));
$pon_control(true);

grupo('3i · la vigencia de la garantía');
banco_entrar($ASESOR);
$VG = $vender([[$MAQ, 1]]);
$vig = fn(int $id, ?string $hoy = null) => pedido_garantia_vigencia(pedido_de($id), $hoy);
es('SIN SALIR A DESPACHO NO EMPIEZA', 'no_empieza', $vig($VG)['estado']);
ok('y no se puede pedir', str_contains(garantia_se_puede_pedir(pedido_de($VG)), 'salga a despacho'));
/* Sale a despacho (con la puerta del pago abierta: aquí se prueba la fecha). */
q("UPDATE pedidos SET despacho_veces = 1, despacho_en = '2026-03-10 10:00:00', despacho_primero_en = '2026-03-10 10:00:00' WHERE id = ?", [$VG]);
$v = $vig($VG, '2026-09-28');
es('DESDE LA SALIDA A DESPACHO + 12 MESES', ['vigente', '2026-03-10', 'despacho', '2027-03-10'], [$v['estado'], $v['desde'], $v['de'], $v['hasta']]);
ok('lo dice en palabras', str_contains($v['texto'], 'Vigente hasta el 10/03/2027') && str_contains($v['detalle'], 'salió a despacho'), $v['texto'] . ' · ' . $v['detalle']);
es('el último día todavía vale', 'vigente', $vig($VG, '2027-03-10')['estado']);
es('EL DÍA SIGUIENTE, VENCIDA', ['vencida', 'Venció el 10/03/2027'], [$vig($VG, '2027-03-11')['estado'], $vig($VG, '2027-03-11')['texto']]);
/* Volver a mandarlo no la reinicia. */
q("UPDATE pedidos SET despacho_en = '2026-06-01 10:00:00', despacho_veces = 2 WHERE id = ?", [$VG]);
es('VOLVER A MANDARLO A DESPACHO NO LA REINICIA', '2026-03-10', $vig($VG)['desde']);
/* La primera salida la pone mandar a despacho, y solo la primera vez. */
$VD = $vender([[$MAQ, 1]]);
q('UPDATE pedidos SET despacho_veces = 0 WHERE id = ?', [$VD]);
$efectivo = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id=i.lista_id WHERE l.clave='metodos_pago' AND i.valor='Efectivo'");
$pg = pago_registrar($VD, ['monto_centimos' => 8000, 'metodo_item_id' => $efectivo, 'fecha' => date('Y-m-d')]);
banco_entrar($ADMIN);
pago_validar((int)$pg['id'], 'x');
banco_entrar($ASESOR);
$r1 = pedido_despacho_marcar($VD);
$primera = (string) valor('SELECT despacho_primero_en FROM pedidos WHERE id = ?', [$VD]);
q("UPDATE pedidos SET despacho_primero_en = '2026-01-01 09:00:00' WHERE id = ?", [$VD]);
pedido_despacho_marcar($VD);
ok('MANDAR A DESPACHO APUNTA LA PRIMERA SALIDA, Y VOLVER A MANDAR NO LA TOCA', $r1['ok'] && $primera !== ''
   && (string) valor('SELECT despacho_primero_en FROM pedidos WHERE id = ?', [$VD]) === '2026-01-01 09:00:00', json_encode($r1));
/* Una venta de antes de la 3i, sin fecha de despacho: desde la venta. */
$VV = $vender([[$MAQ, 1]]);
q("UPDATE pedidos SET creado_en = '2026-01-15 10:00:00', fecha = '2026-01-15' WHERE id = ?", [$VV]);
$v = $vig($VV, '2026-09-28');
es('UNA VENTA VIEJA SIN FECHA DE DESPACHO CUENTA DESDE LA VENTA, Y LO DICE', ['vigente', '2026-01-15', 'venta'], [$v['estado'], $v['desde'], $v['de']]);
ok('«no tiene fecha de despacho»', str_contains($v['detalle'], 'no tiene fecha de despacho'), $v['detalle']);
$VE = $vender([[$MAQ, 1]]);
q("UPDATE pedidos SET estado_id = ? WHERE id = ?", [(int) estado_por_clave('entregado')['id'], $VE]);
es('una entregada sin despacho, también desde la venta', 'venta', $vig($VE)['de']);
$VN = $vender([[$MAQ, 1]], 'inmediata', 0, $GNO);
q("UPDATE pedidos SET despacho_veces = 1, despacho_primero_en = NOW() WHERE id = ?", [$VN]);
es('«NO APLICA» NO TIENE GARANTÍA', 'sin', $vig($VN)['estado']);
q('UPDATE pedidos SET garantia_item_id = NULL WHERE id = ?', [$VN]);
es('sin garantía registrada, tampoco', ['sin', 'Sin garantía registrada'], [$vig($VN)['estado'], $vig($VN)['texto']]);
/* Los meses los manda Configuración. */
q('UPDATE lista_items SET meses = 6 WHERE id = ?', [$G12]);
es('LOS MESES LOS MANDA EL NÚMERO DE CONFIGURACIÓN', '2026-09-10', $vig($VG)['hasta']);
q('UPDATE lista_items SET meses = 12 WHERE id = ?', [$G12]);

grupo('3i · pedir la garantía');
banco_entrar($ASESOR);
es('LAS PIEZAS QUE SE PUEDEN PEDIR: LAS DE SU MÁQUINA', ['Botón', 'Cables de alimentación', 'Carcasa', 'Joystick', 'Luces', 'Motor 12V', 'Palanca antigua', 'Palanca de mando'],
   array_column(pedido_garantia_piezas($VG), 'nombre'));
$foto = function (): string {
    $n = 'v' . date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.jpg';
    $dir = HUB_SUBIDAS . '/vouchers';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $img = imagecreatetruecolor(20, 20); imagejpeg($img, $dir . '/' . $n); imagedestroy($img);
    return $n;
};
$F1 = $foto(); $F2 = $foto();
$r = garantia_pedir($VG, [$JOY2 => 1], 'No responde el joystick', [$F1]);
ok('UNA PIEZA DE OTRA MÁQUINA NO', !$r['ok'] && str_contains($r['error'], 'no es de las máquinas'), $r['error']);
$r = garantia_pedir($VG, [], 'No responde el joystick', [$F1]);
ok('sin pieza y sin «no sé cuál», no', !$r['ok'] && str_contains($r['error'], 'Elige la pieza'), $r['error']);
$r = garantia_pedir($VG, [$JOY => 1], 'no', [$F1]);
ok('sin contar qué falla, no', !$r['ok'] && str_contains($r['error'], 'qué falla'));
$r = garantia_pedir($VG, [$JOY => 1], 'No responde el joystick', []);
ok('SIN FOTO DE LA FALLA, NO', !$r['ok'] && str_contains($r['error'], 'foto'));
$r = garantia_pedir($VG, [$JOY => 21], 'No responde el joystick', [$F1]);
ok('más de 20 unidades, no', !$r['ok']);
$r = garantia_pedir($VG, [$JOY => 1, $BOT => 2], 'No responde el joystick y se cayó un botón', [$F1, $F2]);
ok('SE PIDE CON VARIAS FOTOS', $r['ok'], $r['error']);
$GA = (int)$r['id'];
$ga = garantia_de($GA);
es('queda por aprobar, en plazo, del dueño del pedido', ['pedida', 0, $ASESOR, 2], [$ga['estado'], (int)$ga['fuera_plazo'], (int)$ga['asesor_id'], count(garantia_fotos($GA))]);
es('con sus piezas', '1 × Joystick · 2 × Botón', garantia_piezas_texto(garantia_lineas($GA)));
ok('y queda en la historia del pedido', (bool) valor("SELECT 1 FROM pedido_eventos WHERE pedido_id = ? AND tipo = 'garantia' AND texto LIKE ?", [$VG, '%' . garantia_codigo($GA) . '%']));
banco_entrar($OTRO);
$r = garantia_pedir($VG, [$JOY => 1], 'No responde el joystick', [$F1]);
ok('OTRO ASESOR NO PIDE GARANTÍA DE UN PEDIDO AJENO', !$r['ok']);
banco_entrar($CEO);
ok('Dirección tampoco la pide', garantia_se_puede_pedir(pedido_de($VG)) !== '');
banco_entrar($ASESOR);
/* Vencida también se puede pedir: se marca y decide Administración. */
q("UPDATE pedidos SET despacho_primero_en = '2024-01-01 10:00:00' WHERE id = ?", [$VD]);
$F3 = $foto();
$r = garantia_pedir($VD, [], 'Dejó de prender', [$F3], true);
ok('VENCIDA SE PUEDE PEDIR, Y QUEDA «FUERA DE PLAZO»', $r['ok'] && (int) garantia_de((int)$r['id'])['fuera_plazo'] === 1
   && (string) garantia_de((int)$r['id'])['vence_en'] === '2025-01-01', $r['error']);
$GV = (int)$r['id'];
ok('«no sé qué pieza es» va sin piezas', garantia_lineas($GV) === [] && (int) garantia_de($GV)['sin_pieza'] === 1);
ok('ANULADO NO', str_contains(garantia_se_puede_pedir(array_merge(pedido_de($VG), ['estado' => 'anulado'])), 'anulado'));

grupo('3i · Administración la aprueba (o no)');
$pedidos_antes = (int) valor('SELECT COUNT(*) FROM pedidos');
$cobrado_antes = (int) valor('SELECT COALESCE(SUM(cobrado_centimos),0) FROM pedidos');
$cb_antes = (int) valor('SELECT COUNT(*) FROM cashback_movimientos');
banco_entrar($ASESOR);
$r = garantia_aprobar($GA);
ok('EL ASESOR NO LA APRUEBA', !$r['ok']);
banco_entrar($ADMIN);
es('le sale en su chip', 2, garantias_por_aprobar_n());
contadores_olvidar();
es('y en el menú', 2, contador_de('garantias'));
$r = garantia_aprobar($GV);
ok('sin piezas no se aprueba', !$r['ok'] && str_contains($r['error'], 'Añade la pieza'));
$r = garantia_anadir_pieza($GV, $MAQ, 1);
ok('SOLO SE AÑADEN REPUESTOS (no una máquina)', !$r['ok']);
$r = garantia_anadir_pieza($GV, $JOY2, 1);
ok('ADMINISTRACIÓN AÑADE OTRA PIEZA (de cualquier máquina)', $r['ok'] && (int) garantia_lineas($GV)[0]['anadida'] === 1);
/* El Botón: hay 4, se piden 2. El Joystick: hay 3, se pide 1. */
$r = garantia_aprobar($GA, 'Va con cinta');
ok('SE APRUEBA', $r['ok'], $r['error']);
es('LAS PIEZAS SALEN DEL ALMACÉN', [2, 2], [stock_hub_de([$BOT])[$BOT], stock_hub_de([$JOY])[$JOY]]);
es('con su rastro', 'Garantía ' . garantia_codigo($GA), stock_hub_mov_texto(stock_hub_movimientos($BOT, 1)[0]));
ok('NO ES UNA VENTA: NI UN PEDIDO MÁS, NI COBRO, NI CASHBACK', (int) valor('SELECT COUNT(*) FROM pedidos') === $pedidos_antes
   && (int) valor('SELECT COALESCE(SUM(cobrado_centimos),0) FROM pedidos') === $cobrado_antes
   && (int) valor('SELECT COUNT(*) FROM cashback_movimientos') === $cb_antes);
$r = garantia_aprobar($GA);
ok('dos veces no', !$r['ok'] && stock_hub_de([$BOT])[$BOT] === 2);
/* Sin stock (control encendido): no se aprueba y no sale nada. */
stock_hub_poner($JOY2, 0, 'Conteo');
$r = garantia_aprobar($GV);
ok('SIN STOCK NO SE APRUEBA, Y NO SALE NADA', !$r['ok'] && str_contains($r['error'], 'no queda') && garantia_de($GV)['estado'] === 'pedida', $r['error']);
$r = garantia_denegar($GV, '');
ok('no aprobarla pide el motivo', !$r['ok']);
$r = garantia_denegar($GV, 'Fuera de plazo y con golpe');
ok('SE DICE QUE NO, CON SU MOTIVO', $r['ok'] && garantia_de($GV)['estado'] === 'denegada');
banco_entrar($ASESOR);
$res = garantias_resueltas_recientes(yo());
es('EL ASESOR LAS VE EN SU INICIO: la aprobada y la que no', ['denegada', 'aprobada'], array_column($res, 'estado'));

grupo('3i · sale por «Por alistar»');
banco_entrar($ALMACEN);
es('LA APROBADA ESTÁ EN «POR ALISTAR»', [$GA], array_map(fn($g) => (int)$g['id'], garantias_por_alistar()));
ok('y suma al chip de Almacén', alistar_pendientes_n() === pedidos_por_alistar_n() + 1);
$lid_eq = (int) valor("SELECT id FROM listas WHERE clave = 'equipo_despacho'");
$QUIEN = insertar('lista_items', ['lista_id' => $lid_eq, 'pais_id' => $PERU, 'valor' => 'Juan del almacén', 'orden' => 10]);
$r = garantia_alistar($GA, $QUIEN, null);
ok('sin foto no', !$r['ok'] && str_contains($r['error'], 'foto'));
$r = garantia_alistar($GA, 0, $foto());
ok('sin quién no', !$r['ok']);
$r = garantia_alistar($GA, $QUIEN, $foto());
ok('SE ALISTA CON FOTO Y QUIÉN', $r['ok'] && garantias_por_alistar() === [], $r['error']);
es('sale en «alistadas hoy»', [$GA], array_map(fn($g) => (int)$g['id'], garantias_alistadas_hoy()));
ok('el rótulo lleva sus piezas y dice que no se cobra', ($pdf = rotulo_de_pedido($VG, $GA)) !== null && str_contains($pdf, 'SIN COBRO') && str_contains($pdf, 'Joystick'));
banco_entrar($ADMIN);
$r = garantia_anular($GA, 'Error');
ok('YA ALISTADA NO SE ANULA (la pieza salió)', !$r['ok'] && str_contains($r['error'], 'alistó'));
banco_entrar($ALMACEN);
ok('deshacer el alistado, el mismo día', garantia_alistado_deshacer($GA)['ok'] && count(garantias_por_alistar()) === 1);
banco_entrar($ADMIN);
$r = garantia_anular($GA, '');
ok('anular pide el motivo', !$r['ok']);
$r = garantia_anular($GA, 'El cliente ya lo arregló');
ok('ANULADA ANTES DE SALIR: LAS PIEZAS VUELVEN AL ALMACÉN', $r['ok'] && stock_hub_de([$BOT])[$BOT] === 4 && stock_hub_de([$JOY])[$JOY] === 3, $r['error']);
ok('y sale de «Por alistar»', garantias_por_alistar() === []);
es('volver a anular no devuelve dos veces', 0, stock_hub_devolver('garantia', $GA, 'garantia_vuelve'));

grupo('3i · el pedido se anula: sus garantías también');
banco_entrar($ASESOR);
$VX = $vender([[$MAQ, 1]]);
q("UPDATE pedidos SET despacho_veces = 1, despacho_primero_en = ? WHERE id = ?", [date('Y-m-d H:i:s'), $VX]);
$gx1 = garantia_pedir($VX, [$BOT => 1], 'Se cayó el botón', [$foto()]);
$gx2 = garantia_pedir($VX, [$BOT => 1], 'Otro botón', [$foto()]);
banco_entrar($ADMIN);
$bot_antes = stock_hub_de([$BOT])[$BOT];
ok('se aprueba una', garantia_aprobar((int)$gx1['id'])['ok'] && stock_hub_de([$BOT])[$BOT] === $bot_antes - 1);
pedido_anular($VX, $MOTIVO, 'prueba');
es('AL ANULAR EL PEDIDO, LA APROBADA SE ANULA Y SU PIEZA VUELVE', ['anulada', $bot_antes],
   [(string) garantia_de((int)$gx1['id'])['estado'], stock_hub_de([$BOT])[$BOT]]);
es('la que esperaba, también', 'anulada', (string) garantia_de((int)$gx2['id'])['estado']);
ok('y no queda en «Por alistar»', !in_array((int)$gx1['id'], array_map(fn($g) => (int)$g['id'], garantias_por_alistar()), true));
q("UPDATE garantias SET estado = 'pedida' WHERE id = ?", [(int)$gx2['id']]);
$r = garantia_aprobar((int)$gx2['id']);
ok('UNA GARANTÍA DE UN PEDIDO ANULADO NO SE APRUEBA', !$r['ok'] && str_contains($r['error'], 'se anuló'), $r['error']);

/* Alistada, se anula el pedido, y Almacén deshace: no queda escondida. */
banco_entrar($ASESOR);
$VY = $vender([[$MAQ, 1]]);
q("UPDATE pedidos SET despacho_veces = 1, despacho_primero_en = ? WHERE id = ?", [date('Y-m-d H:i:s'), $VY]);
$gy = garantia_pedir($VY, [$BOT => 1], 'Se cayó', [$foto()]);
banco_entrar($ADMIN);
garantia_aprobar((int)$gy['id']);
$lid_eq0 = (int) valor("SELECT id FROM listas WHERE clave = 'equipo_despacho'");
$QY = insertar('lista_items', ['lista_id' => $lid_eq0, 'pais_id' => $PERU, 'valor' => 'Ana del almacén', 'orden' => 20]);
garantia_alistar((int)$gy['id'], $QY, $foto());
$bot_y = stock_hub_de([$BOT])[$BOT];
pedido_anular($VY, $MOTIVO, 'prueba');
es('alistada, anular el pedido no la toca (ya salió)', 'aprobada', (string) garantia_de((int)$gy['id'])['estado']);
$ry = garantia_alistado_deshacer((int)$gy['id']);
ok('DESHACER CON EL PEDIDO ANULADO LA ANULA Y DEVUELVE LA PIEZA', $ry['ok'] && garantia_de((int)$gy['id'])['estado'] === 'anulada' && stock_hub_de([$BOT])[$BOT] === $bot_y + 1);

grupo('3i · el Excel: «Botones» y «Botón» de la misma máquina');
banco_entrar($CEO);
$bx = ['nombre' => 'Contenedor X', 'viaje' => ['nombre' => 'Contenedor X', 'fecha_salida' => '', 'fecha_llegada_est' => ''], 'filas' => [
    ['codigo' => '', 'nombre' => 'Botones', 'modelo' => '', 'unidades' => 2, 'nuevo' => 0, 'revisa' => true, 'aviso' => 'Repuesto', 'texto' => '', 'maquina' => 'PRD-004569'],
    ['codigo' => '', 'nombre' => 'Botón', 'modelo' => '', 'unidades' => 3, 'nuevo' => 0, 'revisa' => true, 'aviso' => 'Repuesto', 'texto' => '', 'maquina' => 'PRD-004569'],
]];
$rx = lote_excel_crear($bx);
ok('EL CONTENEDOR ENTRA: se suman en una fila', $rx['ok'] && count(lote_filas((int)$rx['id'])) === 1 && (int) lote_filas((int)$rx['id'])[0]['unidades'] === 5
   && (int) lote_filas((int)$rx['id'])[0]['producto_id'] === $BOT, $rx['error']);

grupo('3i · lo que ve cada uno');
banco_entrar($ASESOR);
es('EL ASESOR VE LAS SUYAS', 5, count(garantias_lista()));
banco_entrar($OTRO);
es('otro asesor, ninguna', 0, count(garantias_lista()));
banco_entrar($CEO);
es('Dirección, todas', 5, count(garantias_lista()));
banco_entrar($ASESOR);
$h = garantia_buscar_pedidos('70636199');
ok('«¿SIGUE EN GARANTÍA?» ENCUENTRA SUS COMPRAS POR EL DNI, CON SU VIGENCIA', count($h) >= 5 && isset($h[0]['v']['estado']));
ok('por el código del pedido', (int)(garantia_buscar_pedidos((string) pedido_de($VG)['codigo'])[0]['p']['id'] ?? 0) === $VG);
banco_entrar($OTRO);
es('otro asesor no encuentra las ajenas', [], garantia_buscar_pedidos('70636199'));

grupo('3i · Facturación tiene «Más»');
banco_entrar($FACTU);
es('Inicio · Pagos · Pedidos · Clientes · Más', ['inicio', 'pagos', 'pedidos', 'clientes', 'mas'], array_column(barra_movil(), 'clave'));

grupo('3i · el borrón de datos de prueba');
require_once HUB_APP . '/nucleo/limpieza.php';
ok('las garantías se van con el movimiento', !array_diff(['garantias', 'garantia_lineas', 'garantia_fotos', 'stock_hub_mov'], limpieza_tablas(false)));
ok('el stock del almacén, con el catálogo', in_array('stock_hub', limpieza_tablas(true), true) && !in_array('stock_hub', limpieza_tablas(false), true));

exit(marcador());
