<?php
declare(strict_types=1);
/**
 * 5b · LO QUE PIDIÓ EL USUARIO AL PROBAR LA 5a (2026-09-29).
 *
 * La pre venta sin «Ya lo revisé», las notificaciones (dentro de la
 * plataforma, en ventana al entrar y por push al celular, con su cifrado de
 * verdad), el pago confirmado que le suena al asesor, el pedido por alistar
 * que le suena a Almacén, la etiqueta «Alistado», el rótulo con sus bultos en
 * hojas A4, la guía de remisión con su vista previa y la boleta corregida
 * antes de mandarla. Las pantallas las prueba correr3.
 */
require_once __DIR__ . '/comun.php';
banco_crear(sys_get_temp_dir() . '/waka-pruebas12.sqlite');
migraciones_tras_semillas();

$CEO     = banco_usuario('direccion');
$ADMIN   = banco_usuario('administracion');
$FACTU   = banco_usuario('facturacion');
$ASESOR  = banco_usuario('asesor');
$OTRO    = banco_usuario('asesor');
$ALMACEN = banco_usuario('almacen');
$MKT     = banco_usuario('marketing');
$PERU = (int) valor("SELECT id FROM paises WHERE codigo = 'PE'");
$LIMA_OF = (int) valor("SELECT id FROM oficinas WHERE pais_id = ? AND nombre = 'Lima'", [$PERU]);
$AQP_OF  = (int) valor("SELECT id FROM oficinas WHERE pais_id = ? AND nombre = 'Arequipa'", [$PERU]);
q('UPDATE usuarios SET oficina_id = ? WHERE id = ?', [$LIMA_OF, $ASESOR]);
q('UPDATE usuarios SET oficina_id = ? WHERE id = ?', [$AQP_OF, $OTRO]);
$estado = fn(int $id) => (string) valor('SELECT e.clave FROM pedidos p JOIN pedido_estados e ON e.id = p.estado_id WHERE p.id = ?', [$id]);
$efectivo = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id=i.lista_id WHERE l.clave='metodos_pago' AND i.valor='Efectivo'");
@mkdir(HUB_SUBIDAS . '/vouchers', 0755, true);
$FOTO = 'v' . date('Ymd') . '-' . 'abcdef121212.jpg';
file_put_contents(HUB_SUBIDAS . '/vouchers/' . $FOTO, 'x');
$CLI = insertar('clientes', ['pais_id' => $PERU, 'asesor_id' => $ASESOR, 'tipo_doc' => 'DNI', 'documento' => '70636212',
                             'nombre' => 'Rosa', 'apellidos' => 'Quispe', 'email' => 'c12@waka.test', 'celular' => '900000012', 'activo' => 1]);
$u_de = fn(int $id) => una('SELECT u.*, r.clave AS rol FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?', [$id]);
$tipos_para = fn(int $uid) => array_column(notif_para_mi($u_de($uid), false, 20, false), 'tipo');
$vaciar = function () { foreach (todas('SELECT id FROM usuarios') as $x) notif_para_mi(una('SELECT u.*, r.clave AS rol FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?', [(int)$x['id']]), false, 100, true); };
$nuevo = function (string $tipo, int $total, array $extra = []) use ($PERU, $CLI, $ASESOR) {
    $id = insertar('pedidos', array_merge(['codigo' => 'tmp-' . bin2hex(random_bytes(4)), 'pais_id' => $PERU, 'cliente_id' => $CLI,
        'asesor_id' => $ASESOR, 'tipo' => $tipo, 'estado_id' => (int) estado_por_clave(estado_inicial($tipo))['id'],
        'fecha' => date('Y-m-d'), 'entrega' => 'recojo'], $extra));
    actualizar('pedidos', $id, ['codigo' => pedido_codigo_de_id($id)]);
    insertar('pedido_lineas', ['pedido_id' => $id, 'descripcion' => 'Silla Gamer Negra', 'cantidad' => 2,
        'precio_unit_centimos' => (int)($total / 2), 'total_centimos' => $total, 'origen' => pedido_origen_stock($tipo)]);
    pedido_recalcular($id);
    pedido_estado_auto($id, '', false);
    return $id;
};
$cobrar = function (int $pid, int $cent) use ($ASESOR, $FACTU, $efectivo) {
    banco_entrar($ASESOR);
    $pg = pago_registrar($pid, ['monto_centimos' => $cent, 'metodo_item_id' => $efectivo, 'fecha' => date('Y-m-d')]);
    banco_entrar($FACTU);
    pago_validar((int)($pg['id'] ?? 0), 'x');
    return (int)($pg['id'] ?? 0);
};
/* El push de mentira: guarda lo que se le manda y contesta los códigos que le digamos. */
$PUSH = ['enviados' => [], 'codigos' => []];
$GLOBALS['__push_transporte'] = function (array $envios) use (&$PUSH) {
    $out = [];
    foreach ($envios as $e) { $PUSH['enviados'][] = $e; $out[] = array_shift($PUSH['codigos']) ?? 201; }
    return $out;
};

grupo('5b · la base');
ok('las tablas nuevas están', tabla_existe('notificaciones') && tabla_existe('notificacion_vistas') && tabla_existe('push_suscripciones'));
ok('y la columna del código INEI', columna_existe('ubigeo', 'codigo_inei'));
ok('«Alistado» existe, entre «En despacho» y «Entregado»', (int) estado_por_clave('alistado')['orden'] > (int) estado_por_clave('en_despacho')['orden']
   && (int) estado_por_clave('alistado')['orden'] < (int) estado_por_clave('entregado')['orden']);
$pr = fn(string $r, string $p) => puede_el(['rol' => $r, 'rol_id' => (int) valor('SELECT id FROM roles WHERE clave = ?', [$r])], $p);
ok('EL AVISO GENERAL LO MANDAN DIRECCIÓN Y ADMINISTRACIÓN', $pr('direccion', 'avisos.enviar') && $pr('administracion', 'avisos.enviar'));
ok('y nadie más', !$pr('asesor', 'avisos.enviar') && !$pr('facturacion', 'avisos.enviar') && !$pr('almacen', 'avisos.enviar'));
ok('LA CACERÍA LA LANZAN EL CEO Y TAMBIÉN ADMINISTRACIÓN', $pr('direccion', 'bonos.lanzar') && $pr('administracion', 'bonos.lanzar'));

grupo('5b · a quién le llega cada notificación');
banco_entrar($ADMIN);
$n1 = notificar(['pais_id' => $PERU, 'para_rol' => 'asesor', 'tipo' => 'general', 'titulo' => 'Solo asesores', 'texto' => 'x', 'sin_push' => 1]);
ok('a los asesores sí', in_array('general', $tipos_para($ASESOR), true) && in_array('general', $tipos_para($OTRO), true));
ok('a Almacén no', !in_array('general', $tipos_para($ALMACEN), true));
ok('A QUIEN LO MANDÓ NO LE SALE', !in_array('general', $tipos_para($ADMIN), true));
$n2 = notificar(['pais_id' => $PERU, 'para_oficina_id' => $AQP_OF, 'tipo' => 'general', 'titulo' => 'Solo Arequipa', 'texto' => 'x', 'sin_push' => 1]);
$tit = fn(int $uid) => array_column(notif_para_mi($u_de($uid), false, 20, false), 'titulo');
ok('POR OFICINA: solo a la suya', in_array('Solo Arequipa', $tit($OTRO), true) && !in_array('Solo Arequipa', $tit($ASESOR), true));
notificar(['pais_id' => $PERU, 'para_permiso' => 'pedidos.crear', 'tipo' => 'general', 'titulo' => 'Quien vende', 'texto' => 'x', 'sin_push' => 1]);
ok('POR PERMISO: a quien vende', in_array('Quien vende', $tit($ASESOR), true) && !in_array('Quien vende', $tit($ALMACEN), true) && !in_array('Quien vende', $tit($FACTU), true));
notificar(['pais_id' => $PERU, 'para_usuario_id' => $OTRO, 'tipo' => 'pago_ok', 'titulo' => 'Solo a ti', 'texto' => 'x', 'sin_push' => 1]);
ok('A UNA PERSONA: solo a ella', in_array('Solo a ti', $tit($OTRO), true) && !in_array('Solo a ti', $tit($ASESOR), true));
$l = notif_para_mi($u_de($ASESOR));
ok('lo que se entrega queda visto', count($l) >= 2);
es('Y NO VUELVE A SALIR (tampoco en otro equipo)', [], notif_para_mi($u_de($ASESOR)));
ok('lo visto queda en el servidor', (bool) valor('SELECT 1 FROM notificacion_vistas WHERE notificacion_id = ? AND usuario_id = ?', [$n1, $ASESOR]));
q("UPDATE notificaciones SET creado_en = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 5 * 86400), $n2]);
ok('lo viejo ya no sale', !in_array('Solo Arequipa', $tit($OTRO), true));
$nx = notificar(['pais_id' => $PERU, 'para_rol' => 'asesor', 'tipo' => 'general', 'titulo' => 'Emergente', 'texto' => 'x', 'sin_push' => 1]);
$em = notif_para_mi($u_de($OTRO), true, 5, false);
ok('AL ENTRAR SALE LO EMERGENTE (el aviso general)', in_array('Emergente', array_column($em, 'titulo'), true) && !in_array('Solo a ti', array_column($em, 'titulo'), true));
q('UPDATE notificaciones SET creado_en = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 120), $nx]);
$cuenta_nueva = banco_usuario('asesor');
ok('a una cuenta creada después no le llega lo de antes', !in_array('Emergente', $tit($cuenta_nueva), true));
$vaciar();

grupo('5b · pre venta: activarla avisa a quien vende');
banco_entrar($CEO);
$L = (int) lote_guardar(null, ['nombre' => 'Contenedor 12'])['id'];
lote_filas_guardar($L, [['codigo' => 'PRD-212121', 'nombre' => 'Silla Gold', 'modelo' => '', 'unidades' => '10']]);
$pid_l = (int) valor('SELECT producto_id FROM lote_lineas WHERE lote_id = ?', [$L]);
precios_guardar($pid_l, null, [['desde' => '1', 'hasta' => '', 'precio' => '100', 'alias' => '']], $L);
$r = lote_interruptor($L, true);
ok('se enciende', $r['ok'] && (int)($r['avisados'] ?? 0) === 1, json_encode($r));
$l = notif_para_mi($u_de($ASESOR), false, 20, false);
$pv = array_values(array_filter($l, fn($n) => $n['tipo'] === 'preventa'));
ok('AL ASESOR LE LLEGA «NUEVA PRE VENTA DISPONIBLE»', $pv && $pv[0]['titulo'] === 'Nueva pre venta disponible' && str_contains($pv[0]['texto'], 'Contenedor 12'));
ok('y sale en ventana al entrar', $pv && $pv[0]['emergente'] === 1);
ok('a Almacén no (no vende)', !in_array('preventa', $tipos_para($ALMACEN), true));
lote_interruptor($L, false);
$r = lote_interruptor($L, true);
es('APAGAR Y VOLVER A ENCENDER EN LA HORA NO AVISA DOS VECES', 0, (int)($r['avisados'] ?? -1));
$vaciar();

grupo('5b · «Por confirmar» en vez de «Ya lo revisé»');
lote_filas_guardar($L, [['id' => (int) valor('SELECT id FROM lote_lineas WHERE lote_id = ?', [$L]), 'codigo' => 'PRD-212121', 'nombre' => 'Silla Gold', 'modelo' => '', 'unidades' => '10'],
                        ['codigo' => '', 'nombre' => 'Kit', 'modelo' => '2 botones, 2 joysticks', 'unidades' => '4', 'maquina' => 'PRD-212121']]);
$dudas = (int) valor('SELECT COUNT(*) FROM lote_lineas WHERE lote_id = ? AND resuelto = 0', [$L]);
ok('las piezas de un kit nacen «por confirmar»', $dudas === 2, (string)$dudas);
/* Lo que hace la pantalla al guardar: cada fila que ya estaba va «revisada». */
$form = array_map(fn($f) => ['id' => (int)$f['id'], 'codigo' => (string)($f['codigo'] ?: $f['producto_sku']), 'nombre' => (string)$f['producto_nombre'],
                             'modelo' => (string)$f['modelo'], 'unidades' => (string)$f['unidades'], 'maquina' => (string)$f['maquina'],
                             'revisado' => true], lote_filas($L));
ok('se guarda', lote_filas_guardar($L, $form)['ok']);
es('GUARDAR LAS CONFIRMA (ya no hace falta la casilla)', 0, (int) valor('SELECT COUNT(*) FROM lote_lineas WHERE lote_id = ? AND resuelto = 0', [$L]));
$maq = (int) valor("SELECT id FROM lote_lineas WHERE lote_id = ? AND (maquina IS NULL OR maquina = '')", [$L]);
es('el nombre del repuesto sigue siendo el suyo (no el de la máquina)', ['Botones', 'Joysticks'],
   array_values(array_filter(array_map(fn($f) => (string)$f['producto_nombre'], lote_filas($L)), fn($x) => $x !== 'Silla Gold')));
lote_filas_guardar($L, array_merge($form, [['codigo' => 'PRD-XX1', 'nombre' => 'Motor', 'modelo' => '', 'unidades' => '1', 'maquina' => 'PRD-NOEXISTE', 'revisado' => false]]));
ok('una máquina que no está sigue «por confirmar» aunque se guarde', (int) valor("SELECT resuelto FROM lote_lineas WHERE lote_id = ? AND maquina = 'PRD-NOEXISTE'", [$L]) === 0);

grupo('5b · pago confirmado: al asesor, en el momento');
banco_entrar($ASESOR);
$S = $nuevo('inmediata', 50000);
$cobrar($S, 50000);
$pok = array_values(array_filter(notif_para_mi($u_de($ASESOR), false, 20, false), fn($n) => $n['tipo'] === 'pago_ok'));
ok('LE LLEGA «PAGO CONFIRMADO · DESPACHAR»', $pok && $pok[0]['titulo'] === 'Pago confirmado · despachar', json_encode($pok));
ok('con el código y el monto', $pok && str_contains($pok[0]['texto'], (string) valor('SELECT codigo FROM pedidos WHERE id = ?', [$S])) && str_contains($pok[0]['texto'], '500'));
ok('y lleva a «Por despachar»', $pok && str_contains($pok[0]['url'], 'por-despachar'));
ok('con su sonido', $pok && $pok[0]['sonido'] === 'despacho');
ok('a otro asesor no', !in_array('pago_ok', $tipos_para($OTRO), true));
$PV = $nuevo('preventa', 80000);
$cobrar($PV, 8000);
$pok = array_values(array_filter(notif_para_mi($u_de($ASESOR), false, 20, false), fn($n) => $n['tipo'] === 'pago_ok' && str_contains($n['texto'], (string) valor('SELECT codigo FROM pedidos WHERE id = ?', [$PV]))));
ok('LA PRE VENTA: «PAGO PRE VENTA · CONFIRMADO»', $pok && $pok[0]['titulo'] === 'Pago pre venta · confirmado', json_encode($pok));
ok('A FACTURACIÓN LE LLEGA EL PAGO NUEVO POR PUSH (no como barra: ya la tiene)', (bool) valor("SELECT 1 FROM notificaciones WHERE tipo = 'pago_nuevo' AND para_rol = 'facturacion'")
   && !in_array('pago_nuevo', $tipos_para($FACTU), true));
$vaciar();

grupo('5b · Almacén: suena el pedido por alistar y la etiqueta dice «Alistado»');
banco_entrar($ASESOR);
$r = pedido_despacho_marcar($S, true);
ok('se manda a despacho', $r['ok'], $r['error'] ?? '');
$al = array_values(array_filter(notif_para_mi($u_de($ALMACEN), false, 20, false), fn($n) => $n['tipo'] === 'alistar'));
ok('A ALMACÉN LE LLEGA «NUEVO PEDIDO POR ALISTAR»', $al && $al[0]['titulo'] === 'Nuevo pedido por alistar' && $al[0]['sonido'] === 'almacen');
ok('al asesor no', !in_array('alistar', $tipos_para($ASESOR), true));
es('«En despacho»', 'en_despacho', $estado($S));
banco_entrar($ALMACEN);
$quien = insertar('lista_items', ['lista_id' => (int) valor("SELECT id FROM listas WHERE clave = 'equipo_despacho'"), 'pais_id' => $PERU, 'valor' => 'Juan', 'orden' => 1]);
ok('se alista', pedido_alistar($S, $quien, $FOTO)['ok']);
es('LA ETIQUETA PASA A «ALISTADO»', 'alistado', $estado($S));
es('y queda en «Por entregar»', [$S], array_map(fn($x) => (int)$x['id'], pedidos_por_entregar()));
ok('deshacer el alistado', pedido_alistado_deshacer($S)['ok']);
es('VUELVE A «EN DESPACHO»', 'en_despacho', $estado($S));
pedido_alistar($S, $quien, $FOTO);
ok('entregado', pedido_entregado_marcar($S, $FOTO)['ok']);
es('«Entregado»', 'entregado', $estado($S));
$en = array_values(array_filter(notif_para_mi($u_de($ASESOR), false, 20, false), fn($n) => $n['tipo'] === 'entregado'));
ok('AL ASESOR LE LLEGA «PEDIDO ENTREGADO»', $en && str_starts_with($en[0]['titulo'], 'Pedido entregado'));
ok('el menú de Almacén tiene «Por entregar»', in_array('entregar', array_column(menu_de($u_de($ALMACEN)), 'clave'), true));
/* La migración: lo alistado de antes pasa a «Alistado» una sola vez. */
$V = $nuevo('inmediata', 20000);
$cobrar($V, 20000);
banco_entrar($ASESOR); pedido_despacho_marcar($V, true);
q("UPDATE pedidos SET alistado_en = ?, alistado_foto = ? WHERE id = ?", [date('Y-m-d H:i:s'), $FOTO, $V]);
q("DELETE FROM ajustes WHERE clave = 'alistado_estado_arranque'"); ajustes_olvidar();
migraciones_tras_semillas();
es('LA ACTUALIZACIÓN PASA LO YA ALISTADO A «ALISTADO»', 'alistado', $estado($V));
$vaciar();

grupo('5b · la Cacería y los ganadores, al celular');
banco_entrar($ADMIN);
q("UPDATE bonos SET activo = 1 WHERE tipo = 'caceria' AND pais_id = ?", [$PERU]);
$r = caceria_lanzar($PERU, ['titulo' => 'Cacería de martes', 'frase' => 'A cazar', 'destino' => 'oficina:' . $AQP_OF,
                            'e_desde' => ['5'], 'e_premio' => ['50']]);
ok('ADMINISTRACIÓN LANZA LA CACERÍA', $r['ok'], $r['error']);
$cac = array_values(array_filter(notif_para_mi($u_de($OTRO), false, 20, false), fn($n) => $n['tipo'] === 'caceria'));
ok('LE LLEGA A QUIEN VA DIRIGIDA', $cac && str_contains($cac[0]['titulo'], 'Cacería de martes'));
ok('no a otra oficina', !in_array('caceria', $tipos_para($ASESOR), true));
ok('no sale en ventana (ya sale en la pila del Inicio)', $cac && $cac[0]['emergente'] === 0);
$vaciar();

grupo('5b · el aviso general (Configuración › Notificaciones)');
banco_entrar($ADMIN);
$r = notif_general_mandar($PERU, 'Hola', 'Reunión mañana', 'todos');
ok('se manda', $r['ok'] && $r['n'] > 3, json_encode($r));
ok('lleva la cuenta de a cuántos', $r['n'] === (int) valor('SELECT COUNT(*) FROM usuarios WHERE activo = 1 AND pais_id = ?', [$PERU]) - 1);
ok('UN TÍTULO MÁS LARGO QUE EL MÁXIMO NO', !notif_general_mandar($PERU, str_repeat('a', NOTIF_TITULO_MAX + 1), 'x', 'todos')['ok']);
ok('NI UN TEXTO MÁS LARGO', !notif_general_mandar($PERU, 'Hola', str_repeat('a', NOTIF_TEXTO_MAX + 1), 'todos')['ok']);
ok('ni sin a quién', !notif_general_mandar($PERU, 'Hola', 'x', 'marte')['ok']);
ok('el enlace es una pantalla de aquí', !notif_general_mandar($PERU, 'Hola', 'x', 'todos', '', 'https://otro.sitio')['ok']);
$r2 = notif_general_mandar($PERU, 'A almacén', 'Inventario', 'rol:almacen');
ok('a un rol', $r2['ok'] && in_array('A almacén', $tit($ALMACEN), true) && !in_array('A almacén', $tit($ASESOR), true));
ok('queda en la bitácora', (bool) valor("SELECT 1 FROM bitacora WHERE accion = 'aviso.general'"));
$vaciar();

grupo('5b · el push: cifrado de verdad (RFC 8291) y firma VAPID (RFC 8292)');
$v = push_vapid();
es('la clave pública VAPID son 65 bytes', 65, strlen(b64u_dec($v['publica'])));
es('y no cambia', $v['publica'], push_vapid()['publica']);
/* El navegador de mentira: sus claves, como las da un celular. */
$ua = ec_nueva();
$ua_pub = ec_punto($ua);
$auth = random_bytes(16);
$carga = '{"t":"Hola","b":"Prueba"}';
$cuerpo = webpush_cifrar($carga, $ua_pub, $auth);
/* Descifrar como lo hace el celular. */
$sal = substr($cuerpo, 0, 16);
$rs = unpack('N', substr($cuerpo, 16, 4))[1];
$idlen = ord($cuerpo[20]);
$as_pub = substr($cuerpo, 21, $idlen);
$cifr = substr($cuerpo, 21 + $idlen);
$secreto = openssl_pkey_derive(openssl_pkey_get_public(ec_publica_pem($as_pub)), $ua, 32);
$prk_key = hash_hmac('sha256', $secreto, $auth, true);
$ikm = hash_hmac('sha256', "WebPush: info\0" . $ua_pub . $as_pub . "\x01", $prk_key, true);
$prk = hash_hmac('sha256', $ikm, $sal, true);
$cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
$nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
$claro = openssl_decrypt(substr($cifr, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cifr, -16));
es('la cabecera: registro de 4096 y la clave del servidor (65)', [4096, 65], [$rs, $idlen]);
es('EL CELULAR LO DESCIFRA Y LEE LO MISMO (con el delimitador del último registro)', $carga . "\x02", $claro);
$jwt = vapid_jwt('https://fcm.googleapis.com/fcm/send/abc', $v['privada']);
[$h, $c, $f] = explode('.', $jwt);
$cl = json_decode(b64u_dec($c), true);
es('EL JWT VA PARA EL SERVICIO DE PUSH', 'https://fcm.googleapis.com', $cl['aud']);
ok('vence en menos de 24 h', $cl['exp'] > time() && $cl['exp'] <= time() + 86400);
$crudo = b64u_dec($f);
es('la firma son 64 bytes (r||s)', 64, strlen($crudo));
$der_int = function (string $x): string { $x = ltrim($x, "\0"); if ($x === '' || ord($x[0]) & 0x80) $x = "\0" . $x; return "\x02" . chr(strlen($x)) . $x; };
$der = $der_int(substr($crudo, 0, 32)) . $der_int(substr($crudo, 32));
$der = "\x30" . chr(strlen($der)) . $der;
es('Y LA FIRMA SE VERIFICA CON LA CLAVE PÚBLICA', 1, openssl_verify($h . '.' . $c, $der, openssl_pkey_get_public(ec_publica_pem(b64u_dec($v['publica']))), OPENSSL_ALGO_SHA256));

grupo('5b · el push: a quién se manda y qué pasa con los equipos dados de baja');
push_vaciar_cola();          // lo de las pruebas de arriba (sin equipos suscritos todavía)
$sub = fn() => [b64u(ec_punto(ec_nueva())), b64u(random_bytes(16))];
[$k1, $a1] = $sub();
ok('se suscribe un equipo', push_suscribir($ASESOR, 'https://fcm.googleapis.com/fcm/send/equipo-1', $k1, $a1)['ok']);
[$k2, $a2] = $sub();
push_suscribir($ASESOR, 'https://updates.push.services.mozilla.com/wpush/v2/equipo-2', $k2, $a2);
ok('una suscripción con claves que no son no', !push_suscribir($ASESOR, 'https://fcm.googleapis.com/x/y/z', 'abc', 'def')['ok']);
ok('ni una dirección que no es https', !push_suscribir($ASESOR, 'http://fcm.googleapis.com/fcm/send/x', $k1, $a1)['ok']);
es('dos equipos', 2, push_de($ASESOR));
push_suscribir($ASESOR, 'https://fcm.googleapis.com/fcm/send/equipo-1', $k1, $a1);
es('suscribir otra vez el mismo no lo duplica', 2, push_de($ASESOR));
$PUSH['enviados'] = []; $PUSH['codigos'] = [201, 410];
notificar(['pais_id' => $PERU, 'para_usuario_id' => $ASESOR, 'tipo' => 'pago_ok', 'titulo' => 'Push', 'texto' => 'de prueba']);
es('NO SALE EN MEDIO DE LA PETICIÓN: espera al final', 0, count($PUSH['enviados']));
es('al final, a sus dos equipos', 1, push_vaciar_cola());
es('se mandaron dos', 2, count($PUSH['enviados']));
ok('cifrado y con su firma VAPID', str_starts_with($PUSH['enviados'][0][1][0], 'Authorization: vapid t=') && in_array('Content-Encoding: aes128gcm', $PUSH['enviados'][0][1], true));
es('EL EQUIPO QUE SE DIO DE BAJA (410) SE BORRA', 1, push_de($ASESOR));
$PUSH['enviados'] = [];
try {
    en_transaccion(function () use ($PERU, $ASESOR) {
        notificar(['pais_id' => $PERU, 'para_usuario_id' => $ASESOR, 'tipo' => 'pago_ok', 'titulo' => 'Deshecha', 'texto' => 'x']);
        throw new RuntimeException('se deshace');
    });
} catch (RuntimeException $ex) { /* a propósito */ }
push_vaciar_cola();
es('LO QUE SE DESHIZO NO SE MANDA', 0, count($PUSH['enviados']));
$PUSH['enviados'] = [];
notificar(['pais_id' => $PERU, 'para_rol' => 'almacen', 'tipo' => 'alistar', 'titulo' => 'Solo almacén', 'texto' => 'x']);
push_vaciar_cola();
es('a quien no le toca, nada', 0, count($PUSH['enviados']));
$vaciar();

grupo('5b · el rótulo: un rótulo por bulto, dos por hoja A4');
$pdf1 = rotulo_de_pedido($S);
$pdf3 = rotulo_de_pedido($S, null, 3);
ok('sale un PDF', str_starts_with((string)$pdf3, '%PDF-') && str_ends_with(trim((string)$pdf3), '%%EOF'));
es('CON 3 BULTOS, 2 HOJAS', 2, preg_match_all('~/Type /Page /~', (string)$pdf3));
ok('CADA UNO DICE SU BULTO', str_contains((string)$pdf3, 'BULTO 1 DE 3') && str_contains((string)$pdf3, 'BULTO 3 DE 3'));
ok('EN A4 HORIZONTAL (842 × 595)', str_contains((string)$pdf3, '/MediaBox [0 0 842 595]'));
ok('con la línea de corte', str_contains((string)$pdf3, '[4 4] 0 d'));
ok('con uno solo, sin «bulto»', !str_contains((string)$pdf1, 'BULTO') && preg_match_all('~/Type /Page /~', (string)$pdf1) === 1);
$off = []; preg_match('~startxref\s+(\d+)~', (string)$pdf3, $off);
ok('la tabla de objetos apunta bien', substr((string)$pdf3, (int)$off[1], 4) === 'xref');
ok('y cada objeto está donde dice', (function () use ($pdf3) {
    preg_match('~xref\s+0 (\d+)\s+(.*?)trailer~s', (string)$pdf3, $m);
    $l = array_slice(preg_split('~\r?\n~', trim($m[2])), 1);
    foreach ($l as $i => $x) if (!str_starts_with(substr((string)$pdf3, (int)substr($x, 0, 10)), ($i + 1) . ' 0 obj')) return false;
    return true;
})());
$b = rotulo_bloques($S);
ok('la vista previa usa los mismos bloques', is_array($b) && $b[0]['tipo'] === 'titulo');

grupo('5b · la guía de remisión de los envíos a provincia');
$ubi_cusco = (int) valor("SELECT d.id FROM ubigeo d JOIN ubigeo p ON p.id = d.padre_id WHERE d.tipo = 'distrito' AND d.nombre = 'Wanchaq' AND p.nombre = 'Cusco'");
$ubi_lima  = (int) valor("SELECT d.id FROM ubigeo d JOIN ubigeo p ON p.id = d.padre_id WHERE d.tipo = 'distrito' AND d.nombre = 'Miraflores' AND p.nombre = 'Lima'");
es('EL CÓDIGO INEI DE WANCHAQ (CUSCO)', '080108', ubigeo_codigo($ubi_cusco));
es('y el de Miraflores (Lima)', '150122', ubigeo_codigo($ubi_lima));
$AG = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id WHERE l.clave = 'agencias' ORDER BY i.id LIMIT 1");
$G = $nuevo('inmediata', 30000, ['entrega' => 'envio', 'ubigeo_id' => $ubi_cusco, 'direccion_txt' => 'Av. Garcilaso 100', 'agencia_item_id' => $AG ?: null]);
$GL = $nuevo('inmediata', 30000, ['entrega' => 'envio', 'ubigeo_id' => $ubi_lima, 'direccion_txt' => 'Calle Lima 1']);
ok('antes de salir no lleva guía', !guia_aplica(pedido_de($G)));
$cobrar($G, 30000); $cobrar($GL, 30000);
banco_entrar($ASESOR); pedido_despacho_marcar($G, true); pedido_despacho_marcar($GL, true);
ok('A PROVINCIA, YA SALIDO: LLEVA GUÍA', guia_aplica(pedido_de($G)));
ok('A LIMA NO', !guia_aplica(pedido_de($GL)));
$bo = guia_borrador(pedido_de($G));
es('EL HUB LA PROPONE: destinatario, llegada, productos', ['Rosa Quispe', '70636212', '080108', 2, 1],
   [$bo['dest_nombre'], $bo['dest_doc'], $bo['llegada_ubigeo'], $bo['items'][0]['cantidad'], $bo['bultos']]);
ok('la dirección de llegada con el lugar', str_contains($bo['llegada_dir'], 'Av. Garcilaso 100') && str_contains($bo['llegada_dir'], 'Cusco'));
/* NUBEFACT de mentira. */
$NF = ['enviados' => []];
$GLOBALS['__nubefact_transporte'] = function (string $ruta, string $token, array $datos, string $auth) use (&$NF) {
    $NF['enviados'][] = $datos;
    return ['http' => 200, 'red' => '', 'cuerpo' => ['tipo_de_comprobante' => $datos['tipo_de_comprobante'] ?? 0, 'serie' => $datos['serie'] ?? '',
            'numero' => $datos['numero'] ?? 0, 'aceptada_por_sunat' => true, 'enlace_del_pdf' => 'https://nubefact.test/' . ($datos['serie'] ?? '') . '-' . ($datos['numero'] ?? 0) . '.pdf']];
};
foreach (['ruta' => 'https://api.nubefact.test/api/v1/abc', 'token' => 'token-de-prueba-0123456789abcdef', 'serie_boleta' => 'BBB1',
          'serie_factura' => 'FFF1', 'serie_nc_boleta' => 'BBB1', 'serie_nc_factura' => 'FFF1'] as $k => $x) guardar_ajuste('nubefact_prueba_' . $k, $x);
ajustes_olvidar();
ok('sin serie de guía, no hay guía', nubefact_activo() && !guia_activa());
guardar_ajuste('nubefact_prueba_serie_guia', 'TTT1');
ajustes_olvidar();
ok('con su serie (empieza con T), sí', guia_activa());
banco_entrar($ALMACEN);
$post = ['dest_nombre' => $bo['dest_nombre'], 'dest_doc' => $bo['dest_doc'], 'fecha_traslado' => date('Y-m-d'), 'motivo' => '01',
         'bultos' => '2', 'peso' => '12,5', 'transp_ruc' => '', 'transp_nombre' => 'Shalom Empresarial SAC',
         'partida_ubigeo' => '150101', 'partida_dir' => 'Jr. Almacén 123, Lima', 'llegada_ubigeo' => $bo['llegada_ubigeo'],
         'llegada_dir' => $bo['llegada_dir'], 'observaciones' => $bo['observaciones'],
         'i_desc' => [0 => 'Silla Gamer Negra (2 cajas)'], 'i_cant' => [0 => '2']];
$r = guia_emitir($G, $post);
ok('SIN EL RUC DE LA AGENCIA NO SALE, Y LO DICE', !$r['ok'] && str_contains($r['error'], 'RUC'), $r['error'] ?? '');
es('sin llamar a NUBEFACT', 0, count($NF['enviados']));
$r = guia_emitir($G, ['fecha_traslado' => date('Y-m-d', strtotime('-2 days'))] + $post);
ok('una fecha pasada no', !$r['ok']);
$post['transp_ruc'] = '20512345678';
$r = guia_emitir($GL, $post);
ok('a Lima no se emite', !$r['ok']);
$r = guia_emitir($G, $post);
ok('SE EMITE', $r['ok'], $r['error'] ?? '');
$d = $NF['enviados'][0] ?? [];
es('ES UNA GUÍA DE REMISIÓN REMITENTE (7, generar_guia, serie T)', [7, 'generar_guia', 'TTT1'], [$d['tipo_de_comprobante'] ?? 0, $d['operacion'] ?? '', $d['serie'] ?? '']);
es('CON LO CORREGIDO EN LA VISTA PREVIA: bultos, peso, descripción', ['2', '12.500', 'Silla Gamer Negra (2 cajas)'],
   [$d['numero_de_bultos'] ?? '', $d['peso_bruto_total'] ?? '', $d['items'][0]['descripcion'] ?? '']);
es('partida y llegada con su ubigeo', ['150101', '080108'], [$d['punto_de_partida_ubigeo'] ?? '', $d['punto_de_llegada_ubigeo'] ?? '']);
es('el transportista es la agencia (RUC)', ['6', '20512345678'], [$d['transportista_documento_tipo'] ?? '', $d['transportista_documento_numero'] ?? '']);
$gr = guia_de_pedido($G);
ok('queda en el pedido con su PDF', $gr && (int)$gr['tipo_cpe'] === 7 && str_contains((string)$gr['enlace_pdf'], 'TTT1'));
ok('Y NO TAPA LA BOLETA (no es «el comprobante» de la venta)', nubefact_vigente($G) === null);
$bit = json_decode((string) valor("SELECT detalle FROM bitacora WHERE accion = 'pedido.guia' ORDER BY id DESC LIMIT 1"), true) ?: [];
ok('LO CORREGIDO QUEDA EN LA BITÁCORA, CAMPO POR CAMPO', isset($bit['cambios']['bultos']) && isset($bit['cambios']['productos']) && ($bit['cambios']['bultos']['a'] ?? '') === '2', json_encode($bit));
ok('dos guías no', !guia_emitir($G, $post)['ok']);
if ($AG) es('LA PRÓXIMA GUÍA CON ESA AGENCIA YA TRAE SU RUC', '20512345678', guia_transportista_de($AG)['ruc']);

grupo('5b · la boleta, corregida en la vista previa');
banco_entrar($FACTU);
$B = $nuevo('inmediata', 40000);
$cobrar($B, 40000);
banco_entrar($FACTU);
$pv = nubefact_previa($B, 'boleta');
ok('la vista previa trae el cliente, las líneas y el total', $pv['ok'] && $pv['cliente']['nombre'] === 'Rosa Quispe' && (int)$pv['montos']['total'] === 40000);
$NF['enviados'] = [];
$r = nubefact_emitir($B, 'boleta', ['nombre' => 'Rosa Quispe Mamani', 'direccion' => 'Jr. Cusco 1', 'email' => 'c12@waka.test',
                                    'observaciones' => 'Pedido con entrega en tienda', 'desc' => [0 => 'Silla gamer negra reclinable']]);
ok('se emite', $r['ok'], $r['error'] ?? '');
$d = $NF['enviados'][0] ?? [];
es('CON EL TEXTO CORREGIDO', ['Rosa Quispe Mamani', 'Jr. Cusco 1', 'Silla gamer negra reclinable', 'Pedido con entrega en tienda'],
   [$d['cliente_denominacion'] ?? '', $d['cliente_direccion'] ?? '', $d['items'][0]['descripcion'] ?? '', $d['observaciones'] ?? '']);
es('LOS MONTOS NO SE TOCAN', '400.00', $d['total'] ?? '');
$bit = json_decode((string) valor("SELECT detalle FROM bitacora WHERE accion = 'pedido.comprobante' ORDER BY id DESC LIMIT 1"), true) ?: [];
ok('Y QUEDA EL REGISTRO DE LO CAMBIADO', isset($bit['cambios']['nombre']) && isset($bit['cambios']['linea_1']), json_encode($bit));
ok('la historia del pedido lo dice', (bool) valor("SELECT 1 FROM pedido_eventos WHERE pedido_id = ? AND texto LIKE '%datos corregidos%'", [$B]));
$B2 = $nuevo('inmediata', 10000);
$cobrar($B2, 10000);
banco_entrar($FACTU);
ok('un correo mal escrito no', !nubefact_emitir($B2, 'boleta', ['email' => 'no-es-correo'])['ok']);
ok('ni el nombre vacío', !nubefact_emitir($B2, 'boleta', ['nombre' => '  '])['ok']);
unset($GLOBALS['__nubefact_transporte'], $GLOBALS['__push_transporte']);

exit(marcador());
