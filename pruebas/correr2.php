<?php
declare(strict_types=1);
/**
 * Las reglas del negocio, contra una base de verdad.
 *
 * Aquí viven las dos que ya hicieron tropezar al proyecto y que por eso tienen
 * prueba propia con su nombre y su cifra:
 *   · EL CASHBACK NACE DEL PAGO, NO DEL PEDIDO.
 *   · LA META SE MIDE POR DINERO COBRADO, NO POR VENDIDO.
 */
require_once __DIR__ . '/comun.php';

banco_crear();
$_SESSION = [];

$PAIS   = (int) valor("SELECT id FROM paises WHERE codigo = 'PE'");
$MES    = date('Y-m');
$HOY    = date('Y-m-d');
$MES_PASADO = date('Y-m', strtotime('first day of last month'));
$DIA_PASADO = date('Y-m-15', strtotime('first day of last month'));

$id_metodo = fn(string $v) => (int) valor(
    "SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id
      WHERE l.clave = 'metodos_pago' AND i.valor = ?", [$v]);
$id_lista = fn(string $lista, string $v) => (int) valor(
    'SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id
      WHERE l.clave = ? AND i.valor = ?', [$lista, $v]);

$YAPE  = $id_metodo('Yape');
$EFEC  = $id_metodo('Efectivo');
/* Los métodos que piden comprobante lo piden de verdad: en las pruebas se
   pasa un nombre de archivo como el que devolvería guardar_voucher(). */
$VOU   = 'v20260908-aaaaaaaaaaaa.jpg';
$TRANS = $id_metodo('Transferencia BCP');
$CANAL = $id_lista('canales', 'Referido');

/* ═══════════════════════  SEMILLAS  ═══════════════════════ */
grupo('Semillas · lo que trae el módulo 2');

es('los métodos de pago son 7', 7,
   (int) valor("SELECT COUNT(*) FROM lista_items i JOIN listas l ON l.id=i.lista_id WHERE l.clave='metodos_pago'"));
es('los motivos de anulación son exactamente 5', 5,
   (int) valor("SELECT COUNT(*) FROM lista_items i JOIN listas l ON l.id=i.lista_id WHERE l.clave='motivos_anulacion'"));
es('hay cuatro tipos de envío', 4,
   (int) valor("SELECT COUNT(*) FROM lista_items i JOIN listas l ON l.id=i.lista_id WHERE l.clave='tipos_envio'"));
ok('«Recomendado» pasó a llamarse Referido', $CANAL > 0);
es('no queda ningún «Recomendado»', 0,
   (int) valor("SELECT COUNT(*) FROM lista_items WHERE valor = 'Recomendado'"));
es('los 25 departamentos', 25,
   (int) valor("SELECT COUNT(*) FROM ubigeo WHERE tipo = 'departamento'"));
es('las 196 provincias del Perú', 196,
   (int) valor("SELECT COUNT(*) FROM ubigeo WHERE tipo = 'provincia'"));
/* El padrón entero, no una muestra. Antes eran 79 —Lima, Callao y Arequipa— y
   una venta a Cusco o a Piura no se podía registrar: el distrito es
   obligatorio en todo envío y el buscador no lo encontraba. */
es('los 1.893 distritos del Perú', 1893,
   (int) valor("SELECT COUNT(*) FROM ubigeo WHERE tipo = 'distrito'"));
/* 44 y no 43: Santa María de Huachipa entra a petición del usuario. Es centro
   poblado, no distrito de ley, pero se envía ahí y el cliente lo dice así. */
es('los 44 distritos de la provincia de Lima', 44,
   (int) valor("SELECT COUNT(*) FROM ubigeo d JOIN ubigeo p ON p.id=d.padre_id
                 WHERE d.tipo='distrito' AND p.nombre='Lima'"));
ok('Santa María de Huachipa está', (int) valor(
   "SELECT COUNT(*) FROM ubigeo WHERE tipo='distrito' AND nombre='Santa María de Huachipa'") === 1);
ok('Yape tampoco cuenta al instante', !metodo_al_instante($YAPE));
ok('ni una transferencia',            !metodo_al_instante($TRANS));
ok('Yape pide comprobante',              metodo_pide_comprobante($YAPE));
es('el estado «pagado» ya no dispara el cashback: lo dispara el pago',
   'cobro_total', (string) valor("SELECT dispara FROM pedido_estados WHERE clave='pagado'"));

grupo('Migraciones · se pueden pasar dos veces');
$antes = migraciones_modulo2();
es('la segunda pasada no añade nada', [], $antes);
ok('la columna pagos.tipo existe',        columna_existe('pagos', 'tipo'));
ok('la columna pedidos.flete existe',     columna_existe('pedidos', 'flete_centimos'));
ok('la tabla ubigeo existe',              tabla_existe('ubigeo'));
ok('la tabla cola_tienda existe',         tabla_existe('cola_tienda'));

grupo('El padrón del Perú · se puede sembrar dos veces');

/* Sembrar el padrón otra vez no puede añadir NADA. Si añadiera, cada
   actualización duplicaría el país entero y el reporte por zonas —que agrupa
   por distrito— contaría dos veces cada venta. */
$sitios_antes = (int) valor('SELECT COUNT(*) FROM ubigeo');
$otra = ubigeo_sembrar_padron($PAIS);
es('la segunda siembra no añade ni un sitio', 0, $otra['puestos']);
es('ni corrige ningún nombre',                0, $otra['corregidos']);
es('y el total no se mueve', $sitios_antes, (int) valor('SELECT COUNT(*) FROM ubigeo'));

/* La trampa de verdad: el padrón viene SIN tildes y los 79 distritos que ya
   estaban las tienen. Comparando por el nombre escrito, «Ancon» y «Ancón»
   serían dos distritos de la misma provincia. Se compara por `busca`, que las
   quita. Aquí se simula un HUB donde alguien lo escribió sin tilde. */
$prov_lima = (int) valor("SELECT id FROM ubigeo WHERE tipo='provincia' AND nombre='Lima'");
q("DELETE FROM ubigeo WHERE tipo='distrito' AND padre_id = ? AND nombre = 'Ancón'", [$prov_lima]);
insertar('ubigeo', ['pais_id' => $PAIS, 'tipo' => 'distrito', 'padre_id' => $prov_lima,
                    'nombre' => 'Ancon', 'busca' => 'ancon', 'creado_por' => null]);
$r = ubigeo_sembrar_padron($PAIS);
es('un distrito escrito sin tilde no se duplica', 0, $r['puestos']);
es('sigue habiendo un solo Ancón', 1,
   (int) valor("SELECT COUNT(*) FROM ubigeo WHERE tipo='distrito' AND padre_id = ? AND busca = 'ancon'",
               [$prov_lima]));
/* Y se le devuelve la tilde, sin cambiarle el id: si no, un HUB actualizado y
   uno recién instalado escribirían distinto el mismo distrito. */
es('y se le corrige la grafía', 1, $r['corregidos']);
es('Ancón vuelve a llevar tilde', 'Ancón',
   (string) valor("SELECT nombre FROM ubigeo WHERE tipo='distrito' AND padre_id = ? AND busca='ancon'",
                  [$prov_lima]));

/* «Villa El Salvador» con ele minúscula: lo escribía mal el propio HUB, porque
   ubigeo_titulo() trataba «el» como enlace. Sale en el buscador, en la ficha y
   en el mensaje que se le manda al cliente. */
es('Villa El Salvador se escribe con El', 'Villa El Salvador',
   (string) valor("SELECT nombre FROM ubigeo WHERE busca = 'villa el salvador'"));
es('y San Miguel de El Faique también', 'San Miguel de El Faique',
   (string) valor("SELECT nombre FROM ubigeo WHERE busca = 'san miguel de el faique'"));
es('pero los demás enlaces siguen en minúscula', 'Carmen de la Legua Reynoso',
   (string) valor("SELECT nombre FROM ubigeo WHERE busca = 'carmen de la legua reynoso'"));

/* Y lo que destraba el ensayo: vender fuera de Lima, Callao y Arequipa. */
$WANCHAQ = (int) valor("SELECT d.id FROM ubigeo d
                          JOIN ubigeo p ON p.id = d.padre_id
                         WHERE d.tipo='distrito' AND d.nombre='Wanchaq' AND p.nombre='Cusco'");
ok('Wanchaq, en Cusco, existe',                  $WANCHAQ > 0);
ok('y es un distrito válido para un envío',      ubigeo_distrito_valido($WANCHAQ, $PAIS));
ok('Cusco NO es Lima: lleva agencia y sucursal', !ubigeo_es_lima($WANCHAQ));
$SJL = (int) valor("SELECT id FROM ubigeo WHERE tipo='distrito' AND nombre='San Juan de Lurigancho'");
ok('San Juan de Lurigancho conserva su tilde',   $SJL > 0);
ok('y sigue contando como Lima',                 ubigeo_es_lima($SJL));
$PIURA = (int) valor("SELECT d.id FROM ubigeo d JOIN ubigeo p ON p.id=d.padre_id
                       WHERE d.tipo='distrito' AND d.nombre='Piura' AND p.nombre='Piura'");
ok('Piura también',                              $PIURA > 0 && !ubigeo_es_lima($PIURA));

/* El buscador con el país entero: «san juan» son quince distritos repartidos
   por todo el Perú. Los que EMPIEZAN por lo escrito van primero, si no el
   asesor de Lima tiene que bajar por medio padrón para llegar al suyo. */
$busca = ubigeo_buscar_distritos($PAIS, 'san juan de luri', 25);
ok('buscar «san juan de luri» encuentra el de Lima primero',
   ($busca[0]['distrito'] ?? '') === 'San Juan de Lurigancho');
/* «santa» son 46 distritos: más de los 25 que caben en la lista, que es justo
   el caso en el que la pantalla tiene que avisar de que hay más. */
$muchos = ubigeo_buscar_distritos($PAIS, 'santa', 26);
ok('«santa» devuelve más de las que caben en la lista', count($muchos) > 25);
ok('y los primeros empiezan por «santa»',
   str_starts_with(ubigeo_busca((string)($muchos[0]['distrito'] ?? '')), 'santa'));
es('sin tildes encuentra lo mismo que con tildes',
   count(ubigeo_buscar_distritos($PAIS, 'ancon', 25)),
   count(ubigeo_buscar_distritos($PAIS, 'ancón', 25)));

/* Y lo que hace que el aviso «escribe también la provincia» sirva de algo: el
   buscador busca TAMBIÉN por provincia y departamento. Sin esto, el asesor que
   hace caso al aviso recibe «Nada con ese nombre» y cree que no existe. */
$rosas = ubigeo_buscar_distritos($PAIS, 'santa rosa', 30);
ok('«santa rosa» son muchas en todo el país', count($rosas) > 8);
$rosa_lima = ubigeo_buscar_distritos($PAIS, 'santa rosa lima', 30);
ok('«santa rosa lima» deja solo las del departamento de Lima',
   count($rosa_lima) < count($rosas) && count($rosa_lima) > 0);
es('y la primera es la de la provincia de Lima', 'Lima', (string)($rosa_lima[0]['provincia'] ?? ''));
ok('buscar por la provincia sola trae sus distritos',
   count(ubigeo_buscar_distritos($PAIS, 'wanchaq cusco', 30)) === 1);
ok('y buscar por el departamento también acota',
   count(ubigeo_buscar_distritos($PAIS, 'san juan cusco', 30)) < count(ubigeo_buscar_distritos($PAIS, 'san juan', 30)));

/* La capital de provincia es el caso que más se vende y el que más fácil se
   rompe: «piura piura» son 65 distritos y ninguno se llama «piura piura», así
   que ordenando solo por el texto completo Piura salía en el puesto 44 — fuera
   de la lista de 25. Se ordena quitando palabras por la cola. */
foreach ([['piura piura', 'Piura'], ['lima lima', 'Lima'], ['cusco cusco', 'Cusco'],
          ['tacna tacna', 'Tacna']] as [$escrito, $espera]) {
    $r = ubigeo_buscar_distritos($PAIS, $escrito, 25);
    es("«{$escrito}» pone «{$espera}» el primero", $espera, (string)($r[0]['distrito'] ?? ''));
    es("y en su propia provincia",             $espera, (string)($r[0]['provincia'] ?? ''));
}

/* Y no se tira ninguna palabra en silencio. Con el tope en cuatro, «villa
   maria del triunfo arequipa» perdía «arequipa», devolvía UN resultado —el de
   Lima— y al pulsarlo el formulario se ponía en modo Lima: un pedido de
   provincia registrado sin flete y sin agencia. */
es('un distrito de cuatro palabras con una provincia que no es la suya no devuelve nada',
   0, count(ubigeo_buscar_distritos($PAIS, 'villa maria del triunfo arequipa', 25)));
es('y con la suya sí', 1,
   count(ubigeo_buscar_distritos($PAIS, 'villa maria del triunfo lima', 25)));
es('San Juan de Lurigancho con su provincia también', 1,
   count(ubigeo_buscar_distritos($PAIS, 'san juan de lurigancho lima', 25)));

/* LA CAPITAL. 357 distritos tienen una capital que se llama distinto y la
   gente habla con ese nombre: nadie dice «mándalo a Chanchamayo», dicen «a La
   Merced». Lo encontró el usuario buscando La Merced y no encontrándola — y el
   padrón la traía, en otra columna que no se estaba cargando. */
es('los distritos con capital propia son 357', 357,
   (int) valor("SELECT COUNT(*) FROM ubigeo WHERE tipo='distrito' AND busca_capital IS NOT NULL"));
$merced = ubigeo_buscar_distritos($PAIS, 'la merced', 10);
es('buscar «la merced» encuentra Chanchamayo primero', 'Chanchamayo',
   (string)($merced[0]['distrito'] ?? ''));
es('y lo enseña con su capital al lado', 'La Merced', (string)($merced[0]['capital'] ?? ''));
ok('sin dejar fuera los distritos que SÍ se llaman La Merced', count($merced) >= 3);
es('y la capital no se guarda cuando se llama igual que el distrito', null,
   valor("SELECT busca_capital FROM ubigeo WHERE tipo='distrito' AND busca = 'miraflores' LIMIT 1"));

/* Y la capital NO puede colarse en el orden. `busca_capital` es NULL en los
   1.536 distritos cuya capital se llama igual, y en SQL «0 OR NULL» es NULL:
   con DESC los nulos caen al final y todo distrito con capital adelantaba a
   los que no la tienen. «piura piura» devolvía Bellavista de la Unión. */
foreach ([['piura piura','Piura'], ['lima lima','Lima'], ['cusco cusco','Cusco']] as [$q, $esp]) {
    $r = ubigeo_buscar_distritos($PAIS, $q, 25);
    es("«{$q}» sigue poniendo «{$esp}» el primero", $esp, (string)($r[0]['distrito'] ?? ''));
}

ok('la siembra deja puesto su cerrojo',
   (int) valor("SELECT COUNT(*) FROM ajustes WHERE clave = 'ubigeo_cerrojo'") === 1);

/* ═══════════════════  GENTE Y CLIENTES  ═══════════════════ */
$EQUIPO = insertar('equipos', ['pais_id' => $PAIS, 'nombre' => 'Equipo A', 'activo' => 1]);
$ASESOR = banco_usuario('asesor', ['equipo_id' => $EQUIPO]);
$OTRO   = banco_usuario('asesor');
$LIDER  = banco_usuario('asesor', ['equipo_id' => $EQUIPO, 'ambito' => 'equipo']);
$ADMIN  = banco_usuario('administracion');
$GLOBALS['ADMIN'] = $ADMIN;   // lo usa cobrar() para confirmar

banco_entrar($ASESOR);

function nuevo_cliente(int $pais, int $asesor, string $doc, string $mail): int
{
    return insertar('clientes', [
        'pais_id' => $pais, 'asesor_id' => $asesor, 'tipo_doc' => 'DNI',
        'documento' => $doc, 'nombre' => 'Cliente', 'apellidos' => 'De Prueba',
        'email' => $mail, 'celular' => '900000000', 'activo' => 1,
    ]);
}
$CLIENTE = nuevo_cliente($PAIS, $ASESOR, '70636127', 'c1@waka.test');

/**
 * Registra un pago y lo confirma, devolviendo el cashback que nació al
 * confirmarlo.
 *
 * Desde que NINGÚN método cuenta al instante, un pago recién registrado no
 * suma a nada: eso se prueba aparte, en su propio grupo. Las pruebas cuyo tema
 * es otro —el cashback, la anulación, la meta— quieren el dinero ya confirmado,
 * y sin este atajo cada una tendría que cambiar de usuario dos veces y el
 * archivo se volvería ilegible.
 */
function cobrar(int $pedido_id, int $monto, int $metodo, string $fecha, ?string $voucher = null): array
{
    $quien = $_SESSION['usuario_id'] ?? null;
    $r = pago_registrar($pedido_id, ['monto_centimos' => $monto, 'metodo_item_id' => $metodo,
                                     'fecha' => $fecha, 'voucher' => $voucher]);
    if (!$r['ok']) return $r;

    banco_entrar($GLOBALS['ADMIN']);
    $v = pago_validar($r['id'], 'OP-00901');
    if ($quien) banco_entrar($quien);

    return ['ok' => $v['ok'], 'error' => $v['error'], 'id' => $r['id'],
            'cashback' => (int)($v['cashback'] ?? 0)];
}

function nuevo_pedido(array $d): int
{
    $estado = estado_por_clave(estado_inicial($d['tipo'] ?? 'inmediata'));
    $id = insertar('pedidos', [
        'codigo' => 'tmp-' . bin2hex(random_bytes(4)),
        'pais_id' => $d['pais_id'], 'cliente_id' => $d['cliente_id'],
        'asesor_id' => $d['asesor_id'], 'equipo_id' => $d['equipo_id'] ?? null,
        'tipo' => $d['tipo'] ?? 'inmediata', 'estado_id' => (int)$estado['id'],
        'fecha' => $d['fecha'] ?? date('Y-m-d'),
        'canal_item_id' => $d['canal_item_id'] ?? null,
        'garantia_item_id' => $d['garantia_item_id'] ?? null,
        'flete_centimos' => $d['flete'] ?? 0,
        'flete_lo_paga'  => $d['flete_lo_paga'] ?? 'cliente_destino',
        /* La entrega importa desde que la ventana horaria solo existe donde
           entregamos nosotros: un pedido de fixtura sin distrito es un recojo
           en oficina, y a un recojo no se le pone hora de entrega. */
        'entrega'        => $d['entrega'] ?? 'recojo',
        'ubigeo_id'      => $d['ubigeo_id'] ?? null,
        'recojo_agencia' => $d['recojo_agencia'] ?? 0,
    ]);
    actualizar('pedidos', $id, ['codigo' => pedido_codigo_de_id($id)]);
    insertar('pedido_lineas', [
        'pedido_id' => $id, 'descripcion' => $d['producto'] ?? 'Silla Gamer Comic Duality',
        'cantidad' => 1, 'precio_unit_centimos' => $d['monto'], 'total_centimos' => $d['monto'],
        'origen'   => pedido_origen_stock($d['tipo'] ?? 'inmediata'),
    ]);
    pedido_recalcular($id);
    return $id;
}

/* ══════════  EL CASHBACK NACE DEL PAGO, NO DEL PEDIDO  ══════════ */
grupo('EL CASHBACK NACE DEL PAGO · pre venta de S/20,000 con el 10%');

$PRE = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLIENTE,'asesor_id'=>$ASESOR,
                     'equipo_id'=>$EQUIPO,'tipo'=>'preventa','monto'=>2000000,'canal_item_id'=>$CANAL]);
es('el pedido vale S/20,000', 2000000, (int) valor('SELECT total_centimos FROM pedidos WHERE id=?', [$PRE]));
es('todavía no hay cashback: no ha pagado nada', 0, cashback_saldo($CLIENTE));

$r = cobrar($PRE, 200000, $YAPE, $HOY, $VOU);
ok('el adelanto del 10% se registra y se confirma', $r['ok'], $r['error']);
es('acredita el 1% del PAGO (S/20.00), no del pedido (S/200)', 2000, $r['cashback']);
es('el saldo del cliente es S/20.00', 2000, cashback_saldo($CLIENTE));
es('el pedido queda con S/18,000 por cobrar', 1800000,
   pedido_saldo(pedido_de($PRE)));

$r2 = cobrar($PRE, 1800000, $YAPE, $HOY, $VOU);
ok('el saldo se completa', $r2['ok'], $r2['error']);
es('el segundo pago acredita S/180.00', 18000, $r2['cashback']);
es('en total tiene S/200.00 de cashback', 20000, cashback_saldo($CLIENTE));
/* 3j: en la pre venta el estado dice dónde está la mercadería; el dinero va
   en su chip. Cobrada al 100 % no pasa a «Pagado». */
ok('la pre venta cobrada al 100% NO pasa a «Pagado»: su estado es el del lote',
   (string) pedido_de($PRE)['estado'] !== 'pagado');

/* ══════════  LA META SE MIDE POR COBRADO  ══════════ */
grupo('LA META SE MIDE POR COBRADO · no por vendido');

es('cobrado del mes = los dos pagos', 2000000, cobrado_del_mes($ASESOR, $MES));

$VENDE = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLIENTE,'asesor_id'=>$ASESOR,
                       'equipo_id'=>$EQUIPO,'monto'=>500000,'canal_item_id'=>$CANAL]);
es('vender S/5,000 sin cobrar no mueve la meta', 2000000, cobrado_del_mes($ASESOR, $MES));

/* ══════════  EL PAGO QUE HAY QUE VALIDAR  ══════════ */
grupo('Ningún pago mueve nada hasta que alguien lo confirma');

$r3 = pago_registrar($VENDE, ['monto_centimos'=>100000, 'metodo_item_id'=>$TRANS, 'fecha'=>$HOY, 'voucher'=>$VOU]);
ok('se registra', $r3['ok'], $r3['error']);
ok('queda pendiente', !empty($r3['pendiente']));
es('no acredita cashback todavía', 0, $r3['cashback']);
es('la meta NO se mueve', 2000000, cobrado_del_mes($ASESOR, $MES));
es('el pedido sigue con todo por cobrar', 500000, pedido_saldo(pedido_de($VENDE)));

banco_entrar($ADMIN);
$v = pago_validar($r3['id'], 'OP-00902');
ok('Administración lo valida', $v['ok'], $v['error']);
es('recién ahora acredita S/10.00 de cashback', 1000, $v['cashback']);
es('y recién ahora suma a la meta', 2100000, cobrado_del_mes($ASESOR, $MES));
es('validar dos veces no acredita el doble', false, pago_validar($r3['id'], 'OP-00903')['ok']);
es('el saldo del cliente subió una sola vez', 21000, cashback_saldo($CLIENTE));

/* ══════════  ANULACIÓN  ══════════ */
grupo('ANULAR · se revierte lo COBRADO, no el total del pedido');

banco_entrar($ASESOR);
$CLI2 = nuevo_cliente($PAIS, $ASESOR, '45332647', 'c2@waka.test');
$ANU  = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI2,'asesor_id'=>$ASESOR,
                      'equipo_id'=>$EQUIPO,'monto'=>218000,'canal_item_id'=>$CANAL]);
$p500 = cobrar($ANU, 50000, $YAPE, $HOY, $VOU);
es('de un pedido de S/2,180 solo entraron S/500', 50000,
   (int) valor('SELECT cobrado_centimos FROM pedidos WHERE id=?', [$ANU]));
es('el cashback de ese pago es S/5.00 (1% de 500), no S/21.80', 500, $p500['cashback']);

$MOTIVO = $id_lista('motivos_anulacion', 'El cliente se arrepintió');
$an = pedido_anular($ANU, $MOTIVO, 'prueba');
ok('se anula', $an['ok'], $an['error']);
es('hay que devolverle los S/500 cobrados, no los S/2,180', 50000, $an['devolver']);
es('el pedido queda anulado', 'anulado', (string) pedido_de($ANU)['estado']);
es('y sin nada cobrado', 0, (int) valor('SELECT cobrado_centimos FROM pedidos WHERE id=?', [$ANU]));
es('el cashback del cliente 2 vuelve a cero', 0, cashback_saldo($CLI2));
es('se revirtieron S/5.00, no S/21.80', -500,
   (int) valor("SELECT monto_centimos FROM cashback_movimientos WHERE pedido_id=? AND tipo='revierte'", [$ANU]));
es('anular dos veces no se puede', false, pedido_anular($ANU, $MOTIVO)['ok']);
es('un motivo que no está en la lista se rechaza', false, pedido_anular($VENDE, 999999)['ok']);

grupo('ANULAR · la reversión resta del mes en que ocurre, no del original');

$CLI3 = nuevo_cliente($PAIS, $ASESOR, '10000001', 'c3@waka.test');
$VIEJO = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI3,'asesor_id'=>$ASESOR,
                       'equipo_id'=>$EQUIPO,'monto'=>100000,'canal_item_id'=>$CANAL,
                       'fecha'=>$DIA_PASADO]);
// Un pago del mes pasado lo registra Administración: el asesor tiene tope.
banco_entrar($ADMIN);
cobrar($VIEJO, 100000, $YAPE, $DIA_PASADO, $VOU);
es('el mes pasado cobró S/1,000', 100000, cobrado_del_mes($ASESOR, $MES_PASADO));
$antes_mes = cobrado_del_mes($ASESOR, $MES);

pedido_anular($VIEJO, $MOTIVO, 'devolución tardía');
es('el mes pasado NO cambia', 100000, cobrado_del_mes($ASESOR, $MES_PASADO));
es('baja el mes de la reversión', $antes_mes - 100000, cobrado_del_mes($ASESOR, $MES));
banco_entrar($ASESOR);

/* ══════════  PAGOS: LO QUE NO SE PUEDE HACER  ══════════ */
grupo('Pagos · lo que el HUB no deja hacer');

$P = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLIENTE,'asesor_id'=>$ASESOR,
                   'equipo_id'=>$EQUIPO,'monto'=>100000,'canal_item_id'=>$CANAL]);
ok('no se cobra más que el saldo',
   !pago_registrar($P, ['monto_centimos'=>200000,'metodo_item_id'=>$YAPE,'fecha'=>$HOY,'voucher'=>$VOU])['ok']);
ok('no se acepta un pago con fecha futura',
   !pago_registrar($P, ['monto_centimos'=>1000,'metodo_item_id'=>$YAPE,
                        'fecha'=>date('Y-m-d', strtotime('+2 days')),'voucher'=>$VOU])['ok']);
ok('no se acepta un monto de cero',
   !pago_registrar($P, ['monto_centimos'=>0,'metodo_item_id'=>$YAPE,'fecha'=>$HOY,'voucher'=>$VOU])['ok']);
ok('no se acepta un método que no está en la lista',
   !pago_registrar($P, ['monto_centimos'=>1000,'metodo_item_id'=>999999,'fecha'=>$HOY])['ok']);
$C = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLIENTE,'asesor_id'=>$ASESOR,
                   'equipo_id'=>$EQUIPO,'monto'=>100000,'canal_item_id'=>$CANAL]);
ok('un método que pide comprobante no pasa sin voucher',
   !pago_registrar($C, ['monto_centimos'=>1000,'metodo_item_id'=>$YAPE,'fecha'=>$HOY])['ok']);
ok('el efectivo no pide comprobante y sí pasa',
   pago_registrar($C, ['monto_centimos'=>1000,'metodo_item_id'=>$EFEC,'fecha'=>$HOY])['ok']);
ok('pero tampoco cuenta al instante: nada cuenta hasta que se confirma',
   (int) valor('SELECT verificado FROM pagos WHERE pedido_id = ? ORDER BY id DESC LIMIT 1', [$C]) === 0);

$pg = cobrar($P, 40000, $YAPE, $HOY, $VOU);
ok('un pago YA validado no se puede borrar sin más', !pago_anular($pg['id'])['ok']);
ok('se devuelve, y eso sí se puede',                  pago_devolver($pg['id'], 'prueba')['ok']);
ok('no se devuelve dos veces',                       !pago_devolver($pg['id'], 'otra')['ok']);
es('el pedido vuelve a estar sin cobrar', 0,
   (int) valor('SELECT cobrado_centimos FROM pedidos WHERE id=?', [$P]));

$pen = pago_registrar($P, ['monto_centimos'=>1000,'metodo_item_id'=>$TRANS,'fecha'=>$HOY,'voucher'=>$VOU]);
ok('un pago pendiente sí se quita',           pago_anular($pen['id'], 'me equivoqué')['ok']);
ok('un pago pendiente no se puede devolver',  !pago_devolver($pen['id'])['ok']);

/* ══════════  LO QUE ENCONTRÓ LA AUDITORÍA  ══════════ */
grupo('Auditoría 1 · anular un pedido con un pago YA devuelto no manda devolver dos veces');

banco_entrar($ADMIN);
$CLI6 = nuevo_cliente($PAIS, $ASESOR, '10000004', 'c6@waka.test');
$DOS  = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI6,'asesor_id'=>$ASESOR,
                      'equipo_id'=>$EQUIPO,'monto'=>218000,'canal_item_id'=>$CANAL]);
$pgA = cobrar($DOS, 50000, $YAPE, $HOY, $VOU);
ok('Administración le devuelve los S/500 al cliente', pago_devolver($pgA['id'], 'se arrepintió')['ok']);
es('el pedido queda sin nada cobrado', 0,
   (int) valor('SELECT cobrado_centimos FROM pedidos WHERE id=?', [$DOS]));
$an2 = pedido_anular($DOS, $MOTIVO, 'y además se anula');
ok('se anula', $an2['ok'], $an2['error']);
es('NO manda devolver otra vez un dinero que ya se devolvió', 0, $an2['devolver']);

grupo('Auditoría 3 · un precio imposible no entra');
ok('S/ 10,000,000 sí es un importe razonable', dinero_razonable(DINERO_MAXIMO_CENTIMOS));
ok('un céntimo más, no',                      !dinero_razonable(DINERO_MAXIMO_CENTIMOS + 1));
ok('un pago desmesurado se rechaza',
   !pago_registrar($DOS, ['monto_centimos'=>PHP_INT_MAX,'metodo_item_id'=>$YAPE,
                          'fecha'=>$HOY,'voucher'=>$VOU])['ok']);

grupo('Auditoría 4 · un asesor no fecha un pago en un mes ya cerrado');
banco_entrar($ASESOR);
$CLI7 = nuevo_cliente($PAIS, $ASESOR, '10000005', 'c7@waka.test');
$TARDE = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI7,'asesor_id'=>$ASESOR,
                       'equipo_id'=>$EQUIPO,'monto'=>50000,'canal_item_id'=>$CANAL,
                       'fecha'=>date('Y-m-d', strtotime('-200 days'))]);
$viejo_r = pago_registrar($TARDE, ['monto_centimos'=>10000,'metodo_item_id'=>$YAPE,
                                   'fecha'=>date('Y-m-d', strtotime('-120 days')),'voucher'=>$VOU]);
ok('el asesor no puede fechar un pago de hace cuatro meses', !$viejo_r['ok']);
banco_entrar($ADMIN);
ok('Administración sí, porque su trabajo es corregir',
   pago_registrar($TARDE, ['monto_centimos'=>10000,'metodo_item_id'=>$YAPE,
                           'fecha'=>date('Y-m-d', strtotime('-120 days')),'voucher'=>$VOU])['ok']);
banco_entrar($ASESOR);
ok('y nadie fecha un pago antes que su propio pedido',
   !pago_registrar($TARDE, ['monto_centimos'=>10000,'metodo_item_id'=>$YAPE,
                            'fecha'=>date('Y-m-d', strtotime('-300 days')),'voucher'=>$VOU])['ok']);

grupo('Auditoría 5 · un pago no puede acreditar cashback dos veces');
ok('el índice único está puesto', indice_existe('cashback_movimientos', 'uq_cb_pago_tipo'));
$pgB = cobrar($TARDE, 10000, $YAPE, $HOY, $VOU);
$saldo_antes = cashback_saldo($CLI7);
es('acreditar el mismo pago otra vez no suma nada', 0, cashback_acreditar_pago($pgB['id']));
es('el saldo no se movió', $saldo_antes, cashback_saldo($CLI7));
$choco = false;
try {
    insertar('cashback_movimientos', ['cliente_id'=>$CLI7,'tipo'=>'acredita',
        'monto_centimos'=>999, 'pago_id'=>$pgB['id'], 'vence_en'=>$HOY]);
} catch (PDOException $ex) { $choco = es_choque_de_unico($ex); }
ok('y la base lo impide aunque se salte el código', $choco);

grupo('Auditoría 7 · al anular, el cashback vuelve a SU bloque');
$CLI8 = nuevo_cliente($PAIS, $ASESOR, '10000006', 'c8@waka.test');
$B1 = insertar('cashback_movimientos', ['cliente_id'=>$CLI8,'tipo'=>'acredita',
    'monto_centimos'=>10000, 'vence_en'=>date('Y-m-d', strtotime('+1 month'))]);
$B2 = insertar('cashback_movimientos', ['cliente_id'=>$CLI8,'tipo'=>'acredita',
    'monto_centimos'=>10000, 'vence_en'=>date('Y-m-d', strtotime('+6 months'))]);

$PX = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI8,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>60000,'canal_item_id'=>$CANAL]);
$PY = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI8,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>60000,'canal_item_id'=>$CANAL]);
es('el pedido X gasta el bloque que vence antes', 10000, cashback_usar($CLI8, $PX, 10000));
es('el pedido Y gasta el otro',                   10000, cashback_usar($CLI8, $PY, 10000));
es('B1 quedó gastado', 10000, (int) valor('SELECT consumido_centimos FROM cashback_movimientos WHERE id=?', [$B1]));
es('B2 también',       10000, (int) valor('SELECT consumido_centimos FROM cashback_movimientos WHERE id=?', [$B2]));

actualizar('pedidos', $PX, ['cashback_usado_centimos' => 10000]);
pedido_recalcular($PX);
pedido_anular($PX, $MOTIVO, 'prueba de bloques');
es('al anular X se libera B1, que es el que X gastó', 0,
   (int) valor('SELECT consumido_centimos FROM cashback_movimientos WHERE id=?', [$B1]));
es('y B2 sigue gastado por Y, que no se anuló', 10000,
   (int) valor('SELECT consumido_centimos FROM cashback_movimientos WHERE id=?', [$B2]));

grupo('Auditoría 2ª · vencer, anular y volver a pagar NO deja al cliente sin poder pagar');

/* El candado de «un pago, un movimiento» chocaba con el vencimiento: si un
   bloque vencía, se liberaba al anular un pedido y volvía a vencer, el segundo
   vencimiento reventaba… y como el vencimiento corre al registrar CUALQUIER
   pago de ese cliente, ese cliente no podía volver a pagar nunca más. */
banco_entrar($ASESOR);
$CLI9 = nuevo_cliente($PAIS, $ASESOR, '10000007', 'c9@waka.test');
$PV   = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI9,'asesor_id'=>$ASESOR,
                      'equipo_id'=>$EQUIPO,'monto'=>500000,'canal_item_id'=>$CANAL]);
$pgV = cobrar($PV, 500000, $YAPE, $HOY, $VOU);
es('gana S/50 de cashback', 5000, $pgV['cashback']);

$PU = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI9,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>600000,'canal_item_id'=>$CANAL]);
cashback_usar($CLI9, $PU, 2000);
actualizar('pedidos', $PU, ['cashback_usado_centimos' => 2000]);
pedido_recalcular($PU);

// El bloque vence
q("UPDATE cashback_movimientos SET vence_en = ? WHERE pago_id = ? AND tipo = 'acredita'",
  [date('Y-m-d', strtotime('-1 day')), $pgV['id']]);
es('el bloque vence', 1, cashback_vencer_pendientes($CLI9));

// Y se anula el pedido que lo usaba
pedido_anular($PU, $MOTIVO, 'prueba del vencimiento');

$revento = '';
$PZ = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI9,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>10000,'canal_item_id'=>$CANAL]);
try {
    $r = cobrar($PZ, 10000, $YAPE, $HOY, $VOU);
    ok('el cliente PUEDE volver a pagar', $r['ok'], $r['error']);
} catch (Throwable $ex) { $revento = $ex->getMessage(); }
ok('y no revienta con un error de clave duplicada', $revento === '', $revento);
ok('el saldo del cliente nunca queda en negativo', cashback_saldo($CLI9) >= 0);

grupo('Auditoría 2ª · un cashback que ya venció no se descuenta otra vez al devolver el pago');

$CLI10 = nuevo_cliente($PAIS, $ASESOR, '10000008', 'c10@waka.test');
$PW = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI10,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>100000,'canal_item_id'=>$CANAL]);
$pgW = cobrar($PW, 100000, $YAPE, $HOY, $VOU);
es('gana S/10', 1000, $pgW['cashback']);
q("UPDATE cashback_movimientos SET vence_en = ? WHERE pago_id = ? AND tipo = 'acredita'",
  [date('Y-m-d', strtotime('-1 day')), $pgW['id']]);
cashback_vencer_pendientes($CLI10);
es('tras vencer, el libro queda en cero', 0, cashback_libro($CLI10));

banco_entrar($ADMIN);
pago_devolver($pgW['id'], 'devolución tardía');
es('devolver el pago NO lo descuenta por segunda vez', 0, cashback_libro($CLI10));
es('y el saldo sigue en cero, no en negativo', 0, cashback_saldo($CLI10));
banco_entrar($ASESOR);

grupo('Auditoría 2ª · un uso viejo sin bloque anotado sí devuelve saldo usable');

$CLI11 = nuevo_cliente($PAIS, $ASESOR, '10000009', 'c11@waka.test');
$BV = insertar('cashback_movimientos', ['cliente_id'=>$CLI11,'tipo'=>'acredita',
    'monto_centimos'=>5000, 'consumido_centimos'=>5000,
    'vence_en'=>date('Y-m-d', strtotime('+3 months'))]);
$PVJ = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI11,'asesor_id'=>$ASESOR,
                     'equipo_id'=>$EQUIPO,'monto'=>30000,'canal_item_id'=>$CANAL]);
// Una fila «usa» como las de antes de que existiera bloque_id
insertar('cashback_movimientos', ['cliente_id'=>$CLI11,'tipo'=>'usa',
    'monto_centimos'=>-5000, 'pedido_id'=>$PVJ, 'motivo'=>'uso viejo']);
actualizar('pedidos', $PVJ, ['cashback_usado_centimos' => 5000]);
pedido_recalcular($PVJ);
es('antes de anular no le queda saldo', 0, cashback_saldo($CLI11));
pedido_anular($PVJ, $MOTIVO, 'anulación de un pedido viejo');
es('al anular recupera S/50 y los puede usar', 5000, cashback_saldo($CLI11));

grupo('Auditoría 2ª · anular dos veces no anota dos devoluciones');

$CLI12 = nuevo_cliente($PAIS, $ASESOR, '10000010', 'c12@waka.test');
$PD = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI12,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>50000,'canal_item_id'=>$CANAL]);
cobrar($PD, 50000, $YAPE, $HOY, $VOU);
pedido_anular($PD, $MOTIVO, 'una vez');
$segunda = pedido_anular($PD, $MOTIVO, 'otra vez');
ok('la segunda anulación se rechaza', !$segunda['ok']);
es('y solo hay UNA fila de devolución', 1,
   (int) valor("SELECT COUNT(*) FROM pagos WHERE pedido_id = ? AND tipo = 'devolucion'", [$PD]));
es('el cobrado queda en cero, no en negativo', 0,
   (int) valor('SELECT cobrado_centimos FROM pedidos WHERE id = ?', [$PD]));

grupo('El asesor VE lo que está esperando confirmación');

banco_entrar($ASESOR);
$CLI15 = nuevo_cliente($PAIS, $ASESOR, '10000013', 'c15@waka.test');
$PES = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI15,'asesor_id'=>$ASESOR,
                     'equipo_id'=>$EQUIPO,'monto'=>80000,'canal_item_id'=>$CANAL]);
$antes_cobrado   = cobrado_del_mes($ASESOR, $MES);
$antes_pendiente = pendiente_del_mes($ASESOR, $MES);
$pgE = pago_registrar($PES, ['monto_centimos'=>80000,'metodo_item_id'=>$TRANS,
                             'fecha'=>$HOY,'voucher'=>$VOU]);
es('el pago NO suma a la meta todavía', $antes_cobrado, cobrado_del_mes($ASESOR, $MES));
es('pero sí sale como esperando confirmación', $antes_pendiente + 80000,
   pendiente_del_mes($ASESOR, $MES));

banco_entrar($ADMIN);
pago_validar($pgE['id'], 'OP-00904');
es('al confirmarlo, pasa a la meta', $antes_cobrado + 80000, cobrado_del_mes($ASESOR, $MES));
es('y deja de estar esperando', $antes_pendiente, pendiente_del_mes($ASESOR, $MES));
banco_entrar($ASESOR);

grupo('Ningún método cuenta al instante');
foreach (['Yape','Plin','Efectivo','POS / tarjeta','Transferencia BCP'] as $m) {
    /* Ojo con «{$m}»: PHP 8 admite bytes ≥0x80 en los nombres de variable, así
       que "«{$m}»" se lee como la variable $m» —que no existe— y suelta un aviso
       en vez de fallar. Las llaves cierran el nombre a mano. */
    ok("«{$m}» espera confirmación", !metodo_al_instante($id_metodo($m)));
}

grupo('El N.º de operación lo pone quien valida');

banco_entrar($ASESOR);
$CLI13 = nuevo_cliente($PAIS, $ASESOR, '10000011', 'c13@waka.test');
$POP = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI13,'asesor_id'=>$ASESOR,
                     'equipo_id'=>$EQUIPO,'monto'=>50000,'canal_item_id'=>$CANAL]);
$pgO = pago_registrar($POP, ['monto_centimos'=>50000,'metodo_item_id'=>$TRANS,
                             'fecha'=>$HOY,'voucher'=>$VOU]);
es('el asesor lo deja vacío, y se puede', '',
   (string) valor('SELECT COALESCE(operacion,\'\') FROM pagos WHERE id=?', [$pgO['id']]));
banco_entrar($ADMIN);
pago_validar($pgO['id'], '  0099887766  ');
es('Administración lo escribe al validar, ya recortado', '0099887766',
   (string) valor('SELECT operacion FROM pagos WHERE id=?', [$pgO['id']]));

$pgO2 = pago_registrar($POP, ['monto_centimos'=>0,'metodo_item_id'=>$TRANS,'fecha'=>$HOY]);
$CLI14 = nuevo_cliente($PAIS, $ASESOR, '10000012', 'c14@waka.test');
$POP2 = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI14,'asesor_id'=>$ASESOR,
                      'equipo_id'=>$EQUIPO,'monto'=>50000,'canal_item_id'=>$CANAL]);
$pgO3 = pago_registrar($POP2, ['monto_centimos'=>50000,'metodo_item_id'=>$TRANS,'fecha'=>$HOY,
                               'operacion'=>'ORIGINAL-1','voucher'=>$VOU]);
pago_validar($pgO3['id'], '');
es('si llega vacío NO se pisa el que ya había', 'ORIGINAL-1',
   (string) valor('SELECT operacion FROM pagos WHERE id=?', [$pgO3['id']]));
banco_entrar($ASESOR);

grupo('El POS pasa a validación manual, una sola vez');

q("UPDATE lista_items SET al_instante = 1 WHERE valor = 'POS / tarjeta'
    AND lista_id = (SELECT id FROM listas WHERE clave='metodos_pago')");
q("DELETE FROM ajustes WHERE clave = 'migracion_pos_pendiente'");
migraciones_modulo2();
es('tras actualizar, el POS ya no cuenta al instante', 0,
   (int) valor("SELECT al_instante FROM lista_items WHERE valor = 'POS / tarjeta'"));

q("UPDATE lista_items SET al_instante = 1 WHERE valor = 'POS / tarjeta'");
migraciones_modulo2();
es('y si Administración lo vuelve a cambiar, la actualización NO se lo deshace', 1,
   (int) valor("SELECT al_instante FROM lista_items WHERE valor = 'POS / tarjeta'"));
q("UPDATE lista_items SET al_instante = 0 WHERE valor = 'POS / tarjeta'");

grupo('Auditoría 6 · una migración que no se puede aplicar avisa, no tumba a las demás');

bd()->exec('DROP INDEX uq_cliente_email');
insertar('clientes', ['pais_id'=>$PAIS,'asesor_id'=>$ASESOR,'tipo_doc'=>'DNI',
    'documento'=>'20000001','nombre'=>'Sin','apellidos'=>'Correo','email'=>'','activo'=>1]);
insertar('clientes', ['pais_id'=>$PAIS,'asesor_id'=>$ASESOR,'tipo_doc'=>'DNI',
    'documento'=>'20000002','nombre'=>'Otro','apellidos'=>'Sin Correo','email'=>'','activo'=>1]);

$reventó = false;
$hecho = [];
try { $hecho = migraciones_modulo2(); } catch (Throwable $ex) { $reventó = true; }
ok('con dos fichas sin correo, la migración NO revienta', !$reventó);
ok('y lo dice en vez de callarse',
   (bool) array_filter($hecho, fn($x) => str_contains($x, 'correo único')));
ok('el índice se queda sin poner, que es lo correcto',
   !indice_existe('clientes', 'uq_cliente_email'));

grupo('Auditoría 8 · los comodines de búsqueda se escapan');
es('el guion bajo se escapa', '50\\%\\_a', like_seguro('50%_a'));

/* ══════════  ESTADOS  ══════════ */
grupo('Estados · los mueve el HUB (3j), no una persona');

$E = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLIENTE,'asesor_id'=>$ASESOR,
                   'equipo_id'=>$EQUIPO,'monto'=>100000,'canal_item_id'=>$CANAL]);
es('con saldo pendiente, el estado que le toca es «Registrado»', 'registrado', pedido_estado_calculado(pedido_de($E)));
ok('ya no hay función para cambiarlo a mano', !function_exists('pedido_cambiar_estado') && !function_exists('estados_siguientes'));
q('UPDATE pedidos SET despacho_veces = 1, despacho_en = ? WHERE id = ?', [date('Y-m-d H:i:s'), $E]);
es('mandado a despacho → «En despacho» (aunque no esté pagado: contraentrega)', 'en_despacho', pedido_estado_calculado(pedido_de($E)));
q('UPDATE pedidos SET entregado_en = ? WHERE id = ?', [date('Y-m-d H:i:s'), $E]);
es('con la entrega marcada → «Entregado»', 'entregado', pedido_estado_calculado(pedido_de($E)));
es('pedido_estado_auto lo pone y lo dice', 'entregado', pedido_estado_auto($E));
es('y una segunda vez no hace nada', '', pedido_estado_auto($E));
ok('queda en la historia del pedido', (bool) valor("SELECT COUNT(*) FROM pedido_eventos WHERE pedido_id = ? AND tipo = 'estado' AND texto LIKE '%Entregado%'", [$E]));
es('a un pedido anulado no se le mueve el estado', '', pedido_estado_auto($ANU));
ok('en pre venta el estado inicial es Reservado', estado_inicial('preventa') === 'reservado');
ok('en entrega inmediata es Registrado',          estado_inicial('inmediata') === 'registrado');
$E2 = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLIENTE,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>10000,'canal_item_id'=>$CANAL]);
ok('una entrega inmediata nunca queda «En camino», que es de pre venta',
   pedido_estado_calculado(pedido_de($E2)) !== 'en_camino');
es('el POST viejo de cambiar estado ya no existe como regla: estados_sin_salir incluye los de pre venta',
   ['registrado','reservado','en_camino','llego','listo'], estados_sin_salir());

/* ══════════  CASHBACK: USAR  ══════════ */
grupo('Cashback · mínimo, tercio y FIFO');

$CLI4 = nuevo_cliente($PAIS, $ASESOR, '10000002', 'c4@waka.test');
insertar('cashback_movimientos', ['cliente_id'=>$CLI4,'tipo'=>'acredita','monto_centimos'=>500,
    'vence_en'=>date('Y-m-d', strtotime('+6 months'))]);
es('con S/5 no llega al mínimo de S/10', 0, cashback_aplicable($CLI4, 1000000));

insertar('cashback_movimientos', ['cliente_id'=>$CLI4,'tipo'=>'acredita','monto_centimos'=>4350,
    'vence_en'=>date('Y-m-d', strtotime('+7 months'))]);
es('ahora tiene S/48.50', 4850, cashback_saldo($CLI4));
es('en un pedido de S/100 solo caben S/33.33', 3333, cashback_aplicable($CLI4, 10000));
es('en un pedido de S/145.50 cabe entero', 4850, cashback_aplicable($CLI4, 14550));

$USA = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI4,'asesor_id'=>$ASESOR,
                     'equipo_id'=>$EQUIPO,'monto'=>10000,'canal_item_id'=>$CANAL]);
$usado = cashback_usar($CLI4, $USA, 3333);
es('se aplican S/33.33', 3333, $usado);
es('le quedan S/15.17', 1517, cashback_saldo($CLI4));
es('FIFO: se gastó primero el bloque más viejo', 500,
   (int) valor("SELECT consumido_centimos FROM cashback_movimientos
                 WHERE cliente_id=? AND tipo='acredita' ORDER BY id LIMIT 1", [$CLI4]));

actualizar('pedidos', $USA, ['cashback_usado_centimos' => $usado]);
pedido_recalcular($USA);
es('el total baja a S/66.67', 6667, (int) valor('SELECT total_centimos FROM pedidos WHERE id=?', [$USA]));

$pg2 = cobrar($USA, 6667, $YAPE, $HOY, $VOU);
es('el cashback nuevo sale del PAGO (S/66.67), no del pedido bruto (S/100)', 66, $pg2['cashback']);

grupo('Cashback · el saldo vencido no se puede gastar');

$CLI5 = nuevo_cliente($PAIS, $ASESOR, '10000003', 'c5@waka.test');
insertar('cashback_movimientos', ['cliente_id'=>$CLI5,'tipo'=>'acredita','monto_centimos'=>5000,
    'vence_en'=>date('Y-m-d', strtotime('-1 day'))]);
es('un bloque vencido ayer ya no cuenta', 0, cashback_saldo($CLI5));
es('el proceso de vencimiento lo anota', 1, cashback_vencer_pendientes($CLI5));
es('y el libro queda en cero', 0, cashback_libro($CLI5));
es('pasarlo dos veces no vuelve a anotarlo', 0, cashback_vencer_pendientes($CLI5));

/* ══════════  ÁMBITO Y AISLAMIENTO  ══════════ */
grupo('Ámbito · leer y escribir se comprueban por separado');

banco_entrar($ASESOR);
ok('el asesor ve lo suyo',        puedo_ver($ASESOR, $PAIS, $EQUIPO));
ok('el asesor NO ve lo de otro', !puedo_ver($OTRO, $PAIS, null));
ok('el asesor NO edita lo de otro', !puedo_editar($OTRO, $PAIS));

banco_entrar($LIDER);
ok('el líder VE lo de su equipo',      puedo_ver($ASESOR, $PAIS, $EQUIPO));
ok('el líder NO EDITA lo de su equipo', !puedo_editar($ASESOR, $PAIS));
ok('el líder no ve a uno de fuera del equipo', !puedo_ver($OTRO, $PAIS, null));

banco_entrar($ADMIN);
ok('Administración edita lo de su país', puedo_editar($ASESOR, $PAIS));
ok('Administración NO edita lo de otro país', !puedo_editar($ASESOR, 999));

$DIR = banco_usuario('direccion');
banco_entrar($DIR);
ok('Dirección ve todo',        puedo_ver($ASESOR, $PAIS, $EQUIPO));
ok('Dirección NO toca nada',  !puedo_editar($ASESOR, $PAIS));
ok('Dirección cruza países',   cruza_paises());

grupo('Roles · la jerarquía del módulo 1 sigue en pie');
banco_entrar($ADMIN);
ok('Administración NO puede crear Dirección', !in_array('direccion', roles_que_puedo_dar(), true));
ok('Administración sí puede crear Administración', in_array('administracion', roles_que_puedo_dar(), true));
banco_entrar($ASESOR);
es('un asesor no reparte ningún rol', [], roles_que_puedo_dar());

/* ══════════  EL ROL DE FACTURACIÓN  ══════════ */
grupo('Facturación · confirma el dinero y nada más');

$FACTU = banco_usuario('facturacion');

banco_entrar($FACTU);
ok('puede confirmar pagos',      puede('pagos.verificar'));
ok('puede ver pedidos',          puede('pedidos.ver'));
ok('puede ver clientes',         puede('clientes.ver'));
ok('puede bajar el reporte',     puede('reportes.exportar'));
ok('NO registra pedidos',       !puede('pedidos.crear'));
ok('NO edita pedidos',          !puede('pedidos.editar'));
ok('NO anula pedidos',          !puede('pedidos.anular'));
ok('NO registra pagos',         !puede('pagos.registrar'));
ok('NO devuelve un pago validado', !puede('pagos.anular'));
ok('NO crea clientes',          !puede('clientes.crear'));
ok('NO edita clientes',         !puede('clientes.editar'));
ok('NO gestiona usuarios',      !puede('usuarios.gestionar'));
ok('NO toca la configuración',  !puede('listas.gestionar'));
ok('NO ve nada técnico',        !puede('tecnico.bd'));

ok('NO puede escribir sobre un pedido ajeno', !puedo_editar($ASESOR, $PAIS));
ok('ni sobre uno de su propio id',            !puedo_editar($FACTU, $PAIS));
ok('no cruza países',                         !cruza_paises());
ok('no compite en metas',                     !compite_en_metas());
es('no reparte ningún rol', [], roles_que_puedo_dar());

banco_entrar($ADMIN);
ok('Administración sí puede dar de alta a Facturación',
   in_array('facturacion', roles_que_puedo_dar(), true));
$DIR2 = banco_usuario('direccion');
banco_entrar($DIR2);
ok('Dirección también', in_array('facturacion', roles_que_puedo_dar(), true));

grupo('A quién se le avisa de un pago nuevo');
banco_entrar($FACTU);  ok('a Facturación sí',   le_avisamos_de_pagos());
banco_entrar($ADMIN);  ok('a Administración sí', le_avisamos_de_pagos());
banco_entrar($DIR2);   ok('al CEO NO, aunque pueda confirmar', !le_avisamos_de_pagos());
ok('pero confirmar sí puede',  puede('pagos.verificar'));
banco_entrar($ASESOR); ok('al asesor tampoco',  !le_avisamos_de_pagos());

/* La pregunta «¿a este le avisamos?» se hace sobre OTRA persona: el que la
   hace es el que está mirando la pantalla, el preguntado es cualquiera. Con
   una sola caché de permisos, la segunda respuesta salía con los permisos de
   la primera y el aviso acababa yendo a quien no lo pidió. */
grupo('…y preguntado por OTRO, contesta por ese otro');
$fila_factu  = una('SELECT u.*, r.clave AS rol FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=?', [$FACTU]);
$fila_asesor = una('SELECT u.*, r.clave AS rol FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=?', [$ASESOR]);
$fila_ceo    = una('SELECT u.*, r.clave AS rol FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=?', [$DIR2]);

banco_entrar($ASESOR);
ok('el asesor mirando, preguntando por Facturación: sí',  le_avisamos_de_pagos($fila_factu));
ok('preguntando por sí mismo: no',                       !le_avisamos_de_pagos($fila_asesor));
banco_entrar($FACTU);
ok('Facturación mirando, preguntando por el asesor: no', !le_avisamos_de_pagos($fila_asesor));
ok('y por el CEO: tampoco',                              !le_avisamos_de_pagos($fila_ceo));
ok('pero por sí misma: sí',                               le_avisamos_de_pagos($fila_factu));
ok('puede_el() lee los permisos DEL preguntado',          puede_el($fila_factu, 'pagos.verificar'));
ok('y no los del que mira',                              !puede_el($fila_asesor, 'pagos.verificar'));

/* La regla es una frase: quien confirma el dinero, lo ve. La primera versión
   decía «confirma pagos Y no vende», y por eso dejaba fuera a Administración,
   que confirma tanto como Facturación: el agujero se repetía entero con otro
   rol. La condición correcta es solo la primera. */
grupo('El ámbito de quien confirma pagos no se elige: lo fija el trabajo');
$rol = fn(string $c) => (int) valor('SELECT id FROM roles WHERE clave = ?', [$c]);
foreach (['facturacion','direccion','administracion','desarrollador'] as $r) {
    es("$r confirma dinero, así que lo ve", 'todo', ambito_forzado_de_rol($rol($r)));
    ok("y por eso $r tiene pagos.verificar",
       puede_el(['rol'=>$r,'rol_id'=>$rol($r)], 'pagos.verificar'));
}
ok('el asesor elige el suyo: no confirma nada', ambito_forzado_de_rol($rol('asesor')) === null);

grupo('Facturación confirma, y la fecha que manda es la DEL PAGO');

banco_entrar($ASESOR);
$CLI16 = nuevo_cliente($PAIS, $ASESOR, '10000014', 'c16@waka.test');
$PFA = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI16,'asesor_id'=>$ASESOR,
                     'equipo_id'=>$EQUIPO,'monto'=>120000,'canal_item_id'=>$CANAL,
                     'fecha'=>$DIA_PASADO]);
banco_entrar($ADMIN);
$pgF = pago_registrar($PFA, ['monto_centimos'=>120000,'metodo_item_id'=>$TRANS,
                             'fecha'=>$DIA_PASADO,'voucher'=>$VOU]);
ok('el pago del mes pasado se registra', $pgF['ok'], $pgF['error']);

$antes_pasado = cobrado_del_mes($ASESOR, $MES_PASADO);
$antes_este   = cobrado_del_mes($ASESOR, $MES);

banco_entrar($FACTU);
$vF = pago_validar($pgF['id'], 'OP-FACTURACION-1');
ok('Facturación lo confirma', $vF['ok'], $vF['error']);
es('el dinero suma al mes del PAGO, no al de la confirmación',
   $antes_pasado + 120000, cobrado_del_mes($ASESOR, $MES_PASADO));
es('el mes en que se confirmó no se mueve', $antes_este, cobrado_del_mes($ASESOR, $MES));
es('y el N.º de operación queda escrito', 'OP-FACTURACION-1',
   (string) valor('SELECT operacion FROM pagos WHERE id=?', [$pgF['id']]));

grupo('El resumen que alimenta el aviso');

/* El resumen contesta por QUIEN PREGUNTA: su país y su ámbito, los mismos que
   le lista la bandeja. Por eso se mide desde la cuenta de facturación, que es
   la que mira el aviso. */
banco_entrar($ASESOR);
$CLI17 = nuevo_cliente($PAIS, $ASESOR, '10000015', 'c17@waka.test');
$PN = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI17,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>30000,'canal_item_id'=>$CANAL]);

banco_entrar($FACTU);
$antes_r = pagos_por_validar_resumen();

banco_entrar($ASESOR);
$pgN = pago_registrar($PN, ['monto_centimos'=>30000,'metodo_item_id'=>$YAPE,
                            'fecha'=>$HOY,'voucher'=>$VOU]);

banco_entrar($FACTU);
$ahora_r = pagos_por_validar_resumen();
es('cuenta uno más',                 $antes_r['n'] + 1, $ahora_r['n']);
ok('y el último id sube',            $ahora_r['ultimo'] > $antes_r['ultimo'],
   'antes ' . $antes_r['ultimo'] . ' ahora ' . $ahora_r['ultimo']);
es('y suma su monto',                $antes_r['monto'] + 30000, $ahora_r['monto']);

pago_validar($pgN['id'], 'OP-00905');
$tras = pagos_por_validar_resumen();
es('al confirmarlo, baja el contador', $antes_r['n'], $tras['n']);
ok('pero la marca de agua NO baja: sube con cualquier movimiento',
   $tras['ultimo'] >= $ahora_r['ultimo'],
   'antes ' . $ahora_r['ultimo'] . ' ahora ' . $tras['ultimo']);
banco_entrar($ASESOR);

/* ══════════  UNA SOLA CIFRA DE PAGOS POR CONFIRMAR  ══════════ */
grupo('El chip, el aviso y la bandeja cuentan lo mismo');

/* El menú tenía su propia consulta con el país escrito a mano y el aviso usaba
   pagos_por_validar_resumen(). Para el Desarrollador, que cruza países, eso
   daban dos números distintos de lo mismo en la misma pantalla. */
/* Hace falta un SEGUNDO país con un pago pendiente, o las dos cifras salen
   iguales por casualidad y la prueba no mide nada. */
$MX = insertar('paises', ['nombre'=>'México','codigo'=>'MX','moneda'=>'MXN',
                          'simbolo'=>'$','activo'=>1]);
$ASESOR_MX = banco_usuario('asesor', ['pais_id' => $MX]);
banco_entrar($ASESOR_MX);
$CLI_MX = nuevo_cliente($MX, $ASESOR_MX, '10000031', 'cmx@waka.test');
$PMX = nuevo_pedido(['pais_id'=>$MX,'cliente_id'=>$CLI_MX,'asesor_id'=>$ASESOR_MX,
                     'monto'=>44400,'canal_item_id'=>$CANAL]);
pago_registrar($PMX, ['monto_centimos'=>44400,'metodo_item_id'=>$TRANS,
                      'fecha'=>$HOY,'voucher'=>$VOU]);

foreach (['administracion' => $ADMIN, 'facturacion' => $FACTU] as $quien => $uid) {
    banco_entrar($uid);
    es("el chip y el aviso coinciden · $quien",
       pagos_por_validar_resumen()['n'], contador_de('pagos'));
}
$DEV = banco_usuario('desarrollador');
banco_entrar($DEV);
es('y también para el Desarrollador, que cruza países',
   pagos_por_validar_resumen()['n'], contador_de('pagos'));
ok('que cuenta más de un país', cruza_paises(yo()));

/* Y con el ÁMBITO, no solo con el país: la bandeja lo aplicaba y el chip no,
   así que una cuenta estrecha veía «351» en el menú y «no hay nada esperando»
   al entrar. El chip tiene que contar lo que la bandeja va a listar. */
$FACTU_FLACO = banco_usuario('facturacion', ['ambito' => 'propio']);
banco_entrar($FACTU_FLACO);
es('una cuenta con ámbito estrecho no ve pagos ajenos', 0, pagos_por_validar_resumen()['n']);
es('y su chip dice lo mismo', 0, contador_de('pagos'));
ok('así que no le corre el aviso de algo que no puede abrir',
   pagos_por_validar_resumen()['n'] === 0);
banco_entrar($FACTU);
ok('mientras la cuenta con el ámbito correcto sí los ve',
   pagos_por_validar_resumen()['n'] > 0);
banco_entrar($ADMIN);
$solo_pe = contador_de('pagos');
banco_entrar($DEV);
ok('y por eso ve MÁS que Administración de Perú', contador_de('pagos') > $solo_pe,
   'dev ' . contador_de('pagos') . ' · admin PE ' . $solo_pe);

grupo('El resumen se cachea por petición, pero no miente');
banco_entrar($FACTU);
$r1 = pagos_por_validar_resumen();
$r2 = pagos_por_validar_resumen();
es('dos preguntas seguidas dan lo mismo', $r1, $r2);

banco_entrar($ASESOR);
$CLI_C = nuevo_cliente($PAIS, $ASESOR, '10000032', 'ccache@waka.test');
$PC = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_C,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>15000,'canal_item_id'=>$CANAL]);
pago_registrar($PC, ['monto_centimos'=>15000,'metodo_item_id'=>$TRANS,
                     'fecha'=>$HOY,'voucher'=>$VOU]);
banco_entrar($FACTU);
es('pero entra un pago y la cifra sube', $r1['n'] + 1,
   pagos_por_validar_resumen()['n']);

/* Y el contador del menú tiene que moverse con él: si se vacía una caché y no
   la otra, vuelven a salir dos cifras distintas del mismo dato. */
es('el contador del menú va con la misma cifra',
   pagos_por_validar_resumen()['n'], contador_de('pagos'));

$ult = (int) valor('SELECT MAX(id) FROM pagos WHERE verificado = 0 AND anulado = 0');
pago_validar($ult, 'OP-00906');
es('se confirma uno y baja', $r1['n'], pagos_por_validar_resumen()['n']);
es('y el contador del menú baja con él',
   pagos_por_validar_resumen()['n'], contador_de('pagos'));

/* ══════════  LO QUE NO PUEDE VOLVER A DESCUADRAR  ══════════ */
grupo('Anular un pedido también refresca las cifras de pagos');

/* pedido_anular() apaga los pagos pendientes con un UPDATE directo, sin pasar
   por pago_anular(). Si el pedido no tenía ningún pago validado, nadie tiraba
   las cachés y el chip seguía contando un pago que ya no existía. */
banco_entrar($ASESOR);
$CLI_AN = nuevo_cliente($PAIS, $ASESOR, '10000041', 'canula@waka.test');
$PAN = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_AN,'asesor_id'=>$ASESOR,
                     'equipo_id'=>$EQUIPO,'monto'=>22000,'canal_item_id'=>$CANAL]);
pago_registrar($PAN, ['monto_centimos'=>22000,'metodo_item_id'=>$TRANS,
                      'fecha'=>$HOY,'voucher'=>$VOU]);
banco_entrar($FACTU);
$antes_an = pagos_por_validar_resumen()['n'];
banco_entrar($ASESOR);
$ra = pedido_anular($PAN, $MOTIVO);
ok('el pedido se anula', $ra['ok'], $ra['error'] ?? '');
banco_entrar($FACTU);
es('y la cifra de pagos por confirmar baja al momento', $antes_an - 1,
   pagos_por_validar_resumen()['n']);
es('el chip del menú, también', pagos_por_validar_resumen()['n'], contador_de('pagos'));

grupo('Una devolución NO es un pago nuevo');

/* La marca de agua sube con cualquier fila de pagos, también con la negativa
   de una devolución. Quien decide si hay algo que avisar es «nuevos». */
banco_entrar($ASESOR);
$CLI_DV = nuevo_cliente($PAIS, $ASESOR, '10000042', 'cdev@waka.test');
$PDV = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_DV,'asesor_id'=>$ASESOR,
                     'equipo_id'=>$EQUIPO,'monto'=>18000,'canal_item_id'=>$CANAL]);
$pgD = pago_registrar($PDV, ['monto_centimos'=>18000,'metodo_item_id'=>$TRANS,
                             'fecha'=>$HOY,'voucher'=>$VOU]);
banco_entrar($FACTU);
pago_validar($pgD['id'], 'OP-00907');
$marca = pagos_por_validar_resumen()['ultimo'];

banco_entrar($ADMIN);
$rd = pago_devolver($pgD['id'], 'Prueba de auditoría');
ok('la devolución se anota', $rd['ok'], $rd['error'] ?? '');

banco_entrar($FACTU);
$tras_dev = pagos_por_validar_resumen();
ok('la marca de agua sube', $tras_dev['ultimo'] > $marca,
   'antes ' . $marca . ' ahora ' . $tras_dev['ultimo']);
es('pero no entró NINGÚN pago nuevo por confirmar', 0, pagos_nuevos_desde($marca));

grupo('Ningún método nace contando al instante');

/* Un método nuevo que naciera «al instante» nace validado, acredita cashback y
   suma a la meta sin que nadie lo mire. El valor por defecto tiene que estar
   del lado seguro en los tres sitios donde se decide. */
$LISTA_MP = (int) valor("SELECT id FROM listas WHERE clave = 'metodos_pago'");
$NUEVO_MP = insertar('lista_items', ['lista_id'=>$LISTA_MP, 'valor'=>'Banco Inventado', 'orden'=>999]);
ok('un método creado sin decir nada NO cuenta al instante', !metodo_al_instante($NUEVO_MP));

banco_entrar($ASESOR);
$CLI_MP = nuevo_cliente($PAIS, $ASESOR, '10000043', 'cmp@waka.test');
$PMP = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_MP,'asesor_id'=>$ASESOR,
                     'equipo_id'=>$EQUIPO,'monto'=>9000,'canal_item_id'=>$CANAL]);
$antes_meta = cobrado_del_mes($ASESOR, $MES);
$pgMP = pago_registrar($PMP, ['monto_centimos'=>9000,'metodo_item_id'=>$NUEVO_MP,
                              'fecha'=>$HOY,'voucher'=>$VOU]);
ok('el pago entra', $pgMP['ok'], $pgMP['error']);
es('y NO suma a la meta hasta que lo confirmen', $antes_meta, cobrado_del_mes($ASESOR, $MES));
es('ni genera cashback', 0, (int)($pgMP['cashback'] ?? -1));

/* ══════════  LAS TRES SALIDAS DE UN PAGO  ══════════ */
grupo('En espera · lo miré, todavía no está en el banco');

banco_entrar($ASESOR);
$CLI_E = nuevo_cliente($PAIS, $ASESOR, '10000051', 'cespera@waka.test');
$PE1 = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_E,'asesor_id'=>$ASESOR,
                     'equipo_id'=>$EQUIPO,'monto'=>60000,'canal_item_id'=>$CANAL]);
$pgE1 = pago_registrar($PE1, ['monto_centimos'=>60000,'metodo_item_id'=>$TRANS,
                              'fecha'=>$HOY,'voucher'=>$VOU]);

banco_entrar($FACTU);
$antes = pagos_por_validar_resumen();
$re = pago_en_espera($pgE1['id'], 'Interbancario del viernes, entra el lunes');
ok('se puede dejar en espera', $re['ok'], $re['error']);

$tras = pagos_por_validar_resumen();
es('sale de «sin revisar»',      $antes['sin_revisar'] - 1, $tras['sin_revisar']);
es('y entra en «en espera»',     $antes['en_espera'] + 1,   $tras['en_espera']);
es('pero el dinero pendiente NO se mueve', $antes['n'],     $tras['n']);
es('el chip del menú cuenta lo sin revisar', $tras['sin_revisar'], contador_de('pagos'));

$antes_meta = cobrado_del_mes($ASESOR, $MES);
es('en espera NO suma a la meta', $antes_meta, cobrado_del_mes($ASESOR, $MES));
es('ni genera cashback', 0, (int) valor(
   'SELECT COUNT(*) FROM cashback_movimientos WHERE pago_id = ?', [$pgE1['id']]));
es('la nota queda escrita', 'Interbancario del viernes, entra el lunes',
   (string) valor('SELECT espera_nota FROM pagos WHERE id=?', [$pgE1['id']]));

ok('sin nota no se puede', !pago_en_espera($pgE1['id'], '   ')['ok']);
ok('ni con una nota de 500 caracteres', !pago_en_espera($pgE1['id'], str_repeat('x', 500))['ok']);

/* Y lo que importa: en espera se puede confirmar después, y al confirmarlo la
   marca se apaga — si no, el pago saldría confirmado y esperando a la vez. */
$vE = pago_validar($pgE1['id'], 'OP-DESPUES');
ok('un pago en espera se confirma igual', $vE['ok'], $vE['error']);
es('y deja de estar en espera', 0,
   (int) valor('SELECT en_espera FROM pagos WHERE id=?', [$pgE1['id']]));
es('ahora sí suma a la meta', $antes_meta + 60000, cobrado_del_mes($ASESOR, $MES));

grupo('Denegar · el voucher no es bueno');

banco_entrar($ASESOR);
$CLI_D = nuevo_cliente($PAIS, $ASESOR, '10000052', 'cdeneg@waka.test');
$PD1 = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                     'equipo_id'=>$EQUIPO,'monto'=>45000,'canal_item_id'=>$CANAL]);
$pgD1 = pago_registrar($PD1, ['monto_centimos'=>45000,'metodo_item_id'=>$TRANS,
                              'fecha'=>$HOY,'voucher'=>$VOU]);

banco_entrar($FACTU);
ok('sin motivo no se deniega', !pago_denegar($pgD1['id'], '')['ok']);
$rd = pago_denegar($pgD1['id'], 'El voucher no aparece en el extracto');
ok('con motivo sí', $rd['ok'], $rd['error']);

es('el pago queda apagado', 1, (int) valor('SELECT anulado FROM pagos WHERE id=?', [$pgD1['id']]));
es('y marcado como denegado, no como quitado', 1,
   (int) valor('SELECT denegado FROM pagos WHERE id=?', [$pgD1['id']]));
es('con su motivo, que es lo que verá el asesor', 'El voucher no aparece en el extracto',
   (string) valor('SELECT anulado_motivo FROM pagos WHERE id=?', [$pgD1['id']]));

/* LA REGLA: EL PEDIDO NO SE ANULA. El cliente puede mandar el voucher bueno. */
$ped = pedido_de($PD1);
ok('el pedido NO se anula', (string)$ped['estado'] !== 'anulado');
es('y se queda con todo su saldo por cobrar', 45000, pedido_saldo($ped));
es('el dinero cobrado sigue en cero', 0, (int)$ped['cobrado_centimos']);

banco_entrar($ASESOR);
$pgD2 = pago_registrar($PD1, ['monto_centimos'=>45000,'metodo_item_id'=>$TRANS,
                              'fecha'=>$HOY,'voucher'=>$VOU]);
ok('y el asesor puede registrar el pago bueno', $pgD2['ok'], $pgD2['error']);

/* ══════════  EL COMPROBANTE  ══════════ */
grupo('El comprobante es UNO POR PEDIDO');

banco_entrar($FACTU);
$rc = pedido_comprobante($PD1, 'boleta', 'B001', '00001234');
ok('se anota', $rc['ok'], $rc['error']);
es('con su tipo',   'boleta',   (string) valor('SELECT comprobante_tipo FROM pedidos WHERE id=?', [$PD1]));
es('su serie',      'B001',     (string) valor('SELECT comprobante_serie FROM pedidos WHERE id=?', [$PD1]));
es('y su número',   '00001234', (string) valor('SELECT comprobante_numero FROM pedidos WHERE id=?', [$PD1]));

/* Serie y número son OPCIONALES: obligarlos frenaría a facturación en cada venta. */
$rc2 = pedido_comprobante($PE1, 'boleta');
ok('se puede anotar sin serie ni número', $rc2['ok'], $rc2['error']);
es('y el reporte igual sabe que se emitió', 'Boleta',
   pedido_comprobante_texto((array) una('SELECT * FROM pedidos WHERE id=?', [$PE1])));

ok('un tipo inventado se rechaza',   !pedido_comprobante($PD1, 'recibo')['ok']);
ok('una serie con símbolos también', !pedido_comprobante($PD1, 'boleta', 'B-0/1')['ok']);
ok('y un número con letras',         !pedido_comprobante($PD1, 'boleta', 'B001', '12A4')['ok']);

/* Una factura sin RUC se anula en SUNAT: mejor no dejar anotarla. */
$rf = pedido_comprobante($PD1, 'factura');
ok('una factura a un cliente sin RUC se rechaza', !$rf['ok'], $rf['error']);
ok('y el mensaje dice qué hacer', str_contains($rf['error'], 'RUC'));

$CLI_R = insertar('clientes', ['pais_id'=>$PAIS, 'asesor_id'=>$ASESOR, 'tipo_doc'=>'RUC',
    'documento'=>'20512345678', 'nombre'=>'Empresa Prueba', 'email'=>'ruc@waka.test',
    'celular'=>'900000000', 'activo'=>1, 'tipo_comprobante'=>'factura',
    'razon_social'=>'Empresa Prueba S.A.C.', 'ruc_factura'=>'20512345678']);
banco_entrar($ASESOR);
$PR = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_R,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>90000,'canal_item_id'=>$CANAL]);
banco_entrar($FACTU);
ok('con RUC en la ficha, sí', pedido_comprobante($PR, 'factura', 'F001', '00000045')['ok']);
es('la ficha del cliente decide qué se sugiere', 'factura',
   comprobante_sugerido((array) una('SELECT * FROM clientes WHERE id=?', [$CLI_R])));
es('y para un DNI, boleta', 'boleta',
   comprobante_sugerido((array) una('SELECT * FROM clientes WHERE id=?', [$CLI_D])));

es('«no se emite» es una decisión, no un hueco', 'ninguno',
   pedido_comprobante($PE1, 'ninguno')['ok']
     ? (string) valor('SELECT comprobante_tipo FROM pedidos WHERE id=?', [$PE1]) : 'falló');

/* ══════════  DESPACHO  ══════════ */
grupo('Mandar a despacho deja constancia');

banco_entrar($ASESOR);
es('nace sin mandar', 0, (int) valor('SELECT despacho_veces FROM pedidos WHERE id=?', [$PD1]));

/* LA REGLA DEL USUARIO (2026-09-09): «solo sale un producto a despacho si el
   pago fue confirmado». Este pedido tiene un pago registrado que nadie ha
   mirado, así que todavía no sale — y es justo el caso del voucher falso. */
$rdp = pedido_despacho_marcar($PD1);
ok('con un pago sin confirmar NO se marca', !$rdp['ok'], 'se marcó igual');
ok('y dice por qué', str_contains((string)($rdp['error'] ?? ''), 'confirmado'), $rdp['error'] ?? '');
es('no se contó', 0, (int) valor('SELECT despacho_veces FROM pedidos WHERE id=?', [$PD1]));

banco_entrar($FACTU);
pago_validar($pgD2['id'], 'OP-00908');

banco_entrar($ASESOR);
$rdp = pedido_despacho_marcar($PD1);
ok('confirmado el pago, ya se marca', $rdp['ok'], $rdp['error'] ?? '');
es('y queda contado', 1, (int) valor('SELECT despacho_veces FROM pedidos WHERE id=?', [$PD1]));
es('con quién lo mandó', $ASESOR, (int) valor('SELECT despacho_por FROM pedidos WHERE id=?', [$PD1]));
pedido_despacho_marcar($PD1);
es('mandarlo dos veces se cuenta, no se pisa', 2,
   (int) valor('SELECT despacho_veces FROM pedidos WHERE id=?', [$PD1]));

grupo('El mensaje de despacho dice si hay que cobrar');

$msg = mensaje_de_pedido($PD1, 'despacho');
ok('lleva el código del pedido',  str_contains($msg, 'P-'));
ok('el cliente y su celular',     str_contains($msg, 'Nombre:') && str_contains($msg, 'Número de contacto:'));
ok('los productos',               str_contains($msg, 'Producto:'));
ok('y el asesor',                 str_contains($msg, 'Asesor:'));
/* PARCHE 2R · el grupo de despacho no necesita el precio de cada producto:
   «que no salga el precio del producto puede confundir a despacho» (usuario,
   2026-09-20). La línea del cobro sí se queda en Lima, que es donde hay
   contraentrega. */
ok('pero los productos van SIN su precio', !str_contains($msg, ' — S/ '), $msg);

ok('y como el pago ya está confirmado, dice que está cobrado al 100%',
   str_contains($msg, 'COBRADO AL 100%'), $msg);

banco_entrar($ASESOR);
$CLI_S = nuevo_cliente($PAIS, $ASESOR, '10000053', 'csaldo@waka.test');
$PS = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_S,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>80000,'canal_item_id'=>$CANAL]);
ok('sin ningún pago, dice cuánto cobrar',
   str_contains(mensaje_de_pedido($PS, 'despacho'), 'COBRAR S/ 800.00 AL ENTREGAR'),
   mensaje_de_pedido($PS, 'despacho'));

/* ══════════  AUDITORÍA 14 · LO QUE NO PUEDE VOLVER A PASAR  ══════════ */
grupo('Auditoría 14 · un pedido no se puede cobrar más que su total');

/* El tope miraba el saldo CONFIRMADO, y desde que ningún método cuenta al
   instante ese saldo es el total entero durante horas: el mismo importe se
   podía registrar tres veces y, al confirmarlos todos, el pedido quedaba
   cobrado por el triple, con la meta, el podio y el cashback inflados. */
banco_entrar($ASESOR);
$CLI_X = nuevo_cliente($PAIS, $ASESOR, '10000061', 'cdoble@waka.test');
$PX = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_X,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>37900,'canal_item_id'=>$CANAL]);
$x1 = pago_registrar($PX, ['monto_centimos'=>37900,'metodo_item_id'=>$TRANS,'fecha'=>$HOY,'voucher'=>$VOU]);
ok('el primer pago entra', $x1['ok'], $x1['error']);
$x2 = pago_registrar($PX, ['monto_centimos'=>37900,'metodo_item_id'=>$TRANS,'fecha'=>$HOY,'voucher'=>$VOU]);
ok('el segundo por el total YA no entra', !$x2['ok'], 'entró y no debía');
ok('y el mensaje explica por qué',
   str_contains($x2['error'], 'esperando confirmación'), $x2['error']);

// Un adelanto pequeño sí cabe si de verdad queda saldo.
$PY = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_X,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>50000,'canal_item_id'=>$CANAL]);
ok('un adelanto entra',        pago_registrar($PY, ['monto_centimos'=>20000,'metodo_item_id'=>$TRANS,'fecha'=>$HOY,'voucher'=>$VOU])['ok']);
ok('y el saldo también',       pago_registrar($PY, ['monto_centimos'=>30000,'metodo_item_id'=>$TRANS,'fecha'=>$HOY,'voucher'=>$VOU])['ok']);
ok('pero ni un céntimo más', !pago_registrar($PY, ['monto_centimos'=>100,'metodo_item_id'=>$TRANS,'fecha'=>$HOY,'voucher'=>$VOU])['ok']);

/* Y la segunda red, la que de verdad protege la meta: confirmar. */
banco_entrar($FACTU);
$dup = insertar('pagos', ['pedido_id'=>$PX, 'metodo_item_id'=>$TRANS, 'monto_centimos'=>37900,
                          'fecha'=>$HOY, 'tipo'=>'cobro', 'verificado'=>0, 'anulado'=>0,
                          'registrado_por'=>$ASESOR]);
ok('confirmar el primero, bien', pago_validar($x1['id'], 'OP-00909')['ok']);
$vd = pago_validar($dup, 'OP-00910');
ok('confirmar un duplicado metido a mano se rechaza', !$vd['ok'], 'lo aceptó');
ok('y dice que suele ser el mismo pago dos veces',
   str_contains($vd['error'], 'dos veces'), $vd['error']);
es('el pedido queda cobrado por su total, ni un céntimo más', 37900,
   (int) valor('SELECT cobrado_centimos FROM pedidos WHERE id=?', [$PX]));

grupo('Auditoría 14 · una venta anulada no se despacha ni se factura');

banco_entrar($ADMIN);
$CLI_AN2 = nuevo_cliente($PAIS, $ASESOR, '10000062', 'canul2@waka.test');
$PAN2 = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_AN2,'asesor_id'=>$ASESOR,
                      'equipo_id'=>$EQUIPO,'monto'=>25000,'canal_item_id'=>$CANAL]);
pedido_anular($PAN2, $MOTIVO, 'prueba');
$rc_an = pedido_comprobante($PAN2, 'boleta', 'B001', '00000001');
ok('no se le anota comprobante', !$rc_an['ok'], 'lo aceptó');
ok('y el mensaje dice qué hacer si ya se emitió',
   str_contains($rc_an['error'], 'nota de crédito'), $rc_an['error']);
ok('ni se marca como despachado', !pedido_despacho_marcar($PAN2)['ok']);

grupo('Auditoría 14 · el mensaje de despacho no miente sobre el cobro');

/* Tres estados, tres frases distintas, y las tres deciden si el repartidor
   cobra o no: equivocarse cuesta dinero en las dos direcciones. */
banco_entrar($ASESOR);
$CLI_M = nuevo_cliente($PAIS, $ASESOR, '10000063', 'cmsg@waka.test');
$PM = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_M,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>40000,'canal_item_id'=>$CANAL]);
ok('sin pagos: cobrar', str_contains(mensaje_de_pedido($PM, 'despacho'), 'COBRAR S/ 400.00'));

$pgM = pago_registrar($PM, ['monto_centimos'=>40000,'metodo_item_id'=>$TRANS,'fecha'=>$HOY,'voucher'=>$VOU]);
ok('registrado sin revisar: NO cobrar',
   str_contains(mensaje_de_pedido($PM, 'despacho'), 'NO cobrar al entregar'));

banco_entrar($FACTU);
pago_en_espera($pgM['id'], 'No aparece en el banco todavía');
$msg_esp = mensaje_de_pedido($PM, 'despacho');
ok('EN ESPERA: ya no dice «no cobrar»', !str_contains($msg_esp, 'NO cobrar al entregar'), $msg_esp);
ok('avisa de que facturación lo está revisando',
   str_contains($msg_esp, 'facturación está revisando'), $msg_esp);
ok('y de que no se entregue sin preguntar',
   str_contains($msg_esp, 'NO entregues sin preguntarle'), $msg_esp);

pago_validar($pgM['id'], 'OP-00911');
ok('confirmado: cobrado al 100%',
   str_contains(mensaje_de_pedido($PM, 'despacho'), 'COBRADO AL 100%'));

grupo('Auditoría 17 · solo sale a despacho con el pago confirmado');

/* La regla, dicha por el usuario: «solo sale un producto a despacho si el pago
   fue confirmado». Confirma el dinero que YA ENTRÓ, no el total: la venta con
   adelanto y saldo contraentrega sale igual. */
banco_entrar($ASESOR);
$CLI_D = nuevo_cliente($PAIS, $ASESOR, '10000091', 'cdesp@waka.test');

/* 1) Sin ningún pago: desde el parche 2s NO sale (usuario, 2026-09-21: «no
      puede salir sin confirmación»). Antes era la excepción de la venta 100%
      contraentrega; ya no se puede registrar una venta así, y la que quedara
      tampoco debe salir sin que nadie haya visto dinero. */
$PD_A = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                      'equipo_id'=>$EQUIPO,'monto'=>50000,'canal_item_id'=>$CANAL]);
es('sin ningún pago NO sale', 'sin_pago', pedido_despacho_motivo(pedido_de($PD_A))['clave']);

/* 2) Un pago registrado que nadie ha mirado: NO sale. */
$pgA = pago_registrar($PD_A, ['monto_centimos'=>20000,'metodo_item_id'=>$TRANS,
                              'fecha'=>$HOY,'voucher'=>$VOU]);
$b = pedido_despacho_bloqueo(pedido_de($PD_A));
ok('con un voucher sin revisar NO sale', $b !== '', 'dejó salir');
ok('y el motivo nombra el dinero sin confirmar', str_contains($b, 'S/ 200.00'), $b);

/* 3) En espera: tampoco, y con otra explicación. */
banco_entrar($FACTU);
pago_en_espera($pgA['id'], 'No aparece en el banco');
$b = pedido_despacho_bloqueo(pedido_de($PD_A));
ok('en espera tampoco sale', $b !== '');
ok('y lo explica distinto', str_contains($b, 'revisando'), $b);

/* 4) Confirmado el adelanto: SÍ sale, aunque quede saldo por cobrar. */
pago_validar($pgA['id'], 'OP-00912');
es('con el adelanto confirmado y saldo contraentrega, SÍ sale', '',
   pedido_despacho_bloqueo(pedido_de($PD_A)));
ok('y el mensaje dice cuánto cobrar al entregar',
   str_contains(mensaje_de_pedido($PD_A, 'despacho'), 'COBRAR S/ 300.00 AL ENTREGAR'),
   mensaje_de_pedido($PD_A, 'despacho'));

/* 5) Un segundo pago sin confirmar vuelve a cerrar la puerta: entre que se
      pinta el botón y se pulsa, el asesor pudo registrar otro voucher. */
banco_entrar($ASESOR);
pago_registrar($PD_A, ['monto_centimos'=>10000,'metodo_item_id'=>$TRANS,
                       'fecha'=>$HOY,'voucher'=>$VOU]);
ok('un pago nuevo sin confirmar vuelve a frenarlo',
   pedido_despacho_bloqueo(pedido_de($PD_A)) !== '');
ok('y marcar tampoco pasa', !pedido_despacho_marcar($PD_A)['ok']);

/* 6) Un pago DENEGADO no frena nada: ya no cuenta como pendiente. */
banco_entrar($FACTU);
$pgB = una('SELECT id FROM pagos WHERE pedido_id = ? AND verificado = 0 AND anulado = 0', [$PD_A]);
pago_denegar((int)$pgB['id'], 'Voucher falso');
banco_entrar($ASESOR);
es('denegado el voucher falso, vuelve a salir', '', pedido_despacho_bloqueo(pedido_de($PD_A)));

grupo('Auditoría 18 · la cola de «por despachar» y la ventana de entrega');

/* LA GUARDA MÁS IMPORTANTE DE ESTE BLOQUE: la regla del despacho está escrita
   dos veces —en prosa (pedido_despacho_bloqueo) y en SQL (sql_despacho_listo)— y
   este proyecto ya ha producido seis veces dos versiones de la misma cosa que
   dejaron de coincidir. Se recorren TODOS los pedidos y se comprueba que las
   dos dicen lo mismo de cada uno. */
banco_entrar($ADMIN);

/* Se comparan solo los pedidos VIVOS, que es el trozo que las dos comparten:
   el bloqueo en prosa además corta por anulado, y el SQL de la cola lo filtra
   por su cuenta con `anulado_en IS NULL`. */
$vivos = array_map('intval', array_column(
    todas('SELECT id FROM pedidos WHERE anulado_en IS NULL ORDER BY id'), 'id'));

$por_sql = array_map('intval', array_column(todas(
    'SELECT pe.id FROM pedidos pe
      WHERE pe.anulado_en IS NULL AND ' . sql_despacho_listo() . ' ORDER BY pe.id'), 'id'));

$por_php = [];
foreach ($vivos as $vid) {
    $ped = pedido_de($vid);
    if ($ped && pedido_despacho_bloqueo($ped) === '') $por_php[] = $vid;
}
sort($por_sql); sort($por_php);
es('la regla en SQL y la regla en prosa dicen lo mismo de cada pedido',
   implode(',', $por_php), implode(',', $por_sql));

banco_entrar($ASESOR);
$CLI_E = nuevo_cliente($PAIS, $ASESOR, '10000092', 'centrega@waka.test');
$SJL_ID = (int) valor("SELECT id FROM ubigeo WHERE tipo='distrito' AND nombre='San Juan de Lurigancho'");
$CUS_ID = (int) valor("SELECT d.id FROM ubigeo d JOIN ubigeo p ON p.id=d.padre_id
                        WHERE d.tipo='distrito' AND d.nombre='Wanchaq' AND p.nombre='Cusco'");
/* Un envío A LIMA: es el único caso donde la ventana de entrega existe. */
$PE1 = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_E,'asesor_id'=>$ASESOR,
                     'equipo_id'=>$EQUIPO,'monto'=>30000,'canal_item_id'=>$CANAL,
                     'entrega'=>'envio','ubigeo_id'=>$SJL_ID]);

/* Desde el 2s un pedido sin pagos NO cuenta como listo: nada sale sin dinero
   confirmado. La cola crece al CONFIRMAR, no al registrar. */
$antes = pedidos_por_despachar_resumen(null, true);
$antes = pedidos_por_despachar_resumen();

$pgE = pago_registrar($PE1, ['monto_centimos'=>30000,'metodo_item_id'=>$TRANS,
                             'fecha'=>$HOY,'voucher'=>$VOU]);
despacho_pendiente_olvidar();
$conPago = pedidos_por_despachar_resumen();
es('con un pago registrado sin confirmar, todavía no entra en la cola', $antes['n'], $conPago['n']);

banco_entrar($FACTU);
pago_validar($pgE['id'], 'OP-00913');
banco_entrar($ASESOR);
despacho_pendiente_olvidar();
es('al confirmarlo entra', $antes['n'] + 1, pedidos_por_despachar_resumen()['n']);

pedido_despacho_marcar($PE1);
despacho_pendiente_olvidar();
es('mandado a despacho, ya no cuenta', $antes['n'], pedidos_por_despachar_resumen()['n']);

grupo('El pedido que nadie arregló · se anula a las 48 horas');

/* Un pago denegado es justo el caso que después hace falta poder mirar: el
   cliente dice que pagó, facturación dice que el voucher no cuadra. Por eso se
   ANULA y no se borra. Lo decidió el usuario el 2026-09-11. */
$CLI_D = nuevo_cliente($PAIS, $ASESOR, '10000093', 'cdeneg@waka.test');
$mk_deneg = function (string $hace) use ($PAIS, $CLI_D, $ASESOR, $EQUIPO, $CANAL, $TRANS, $VOU, $ADMIN) {
    $id = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                        'equipo_id'=>$EQUIPO,'monto'=>30000,'canal_item_id'=>$CANAL]);
    banco_entrar($ASESOR);
    $r = pago_registrar($id, ['monto_centimos'=>30000,'metodo_item_id'=>$TRANS,
                              'fecha'=>date('Y-m-d'),'voucher'=>$VOU]);
    banco_entrar($ADMIN);
    pago_denegar($r['id'], 'El voucher es de otro pedido');
    /* Se envejece la denegación a mano: el banco no puede esperar dos días. */
    q('UPDATE pagos SET anulado_en = ? WHERE id = ?', [$hace, $r['id']]);
    banco_entrar($ASESOR);
    return $id;
};

$P_VIEJO  = $mk_deneg(date('Y-m-d H:i:s', strtotime('-50 hours')));
$P_RECIEN = $mk_deneg(date('Y-m-d H:i:s', strtotime('-3 hours')));

es('se anula el que lleva más de 48 horas, y solo ese', 1, pedidos_anular_denegados());
es('ese pedido queda ANULADO, no borrado', 'anulado',
   (string) valor('SELECT e.clave FROM pedidos p JOIN pedido_estados e ON e.id = p.estado_id
                    WHERE p.id = ?', [$P_VIEJO]));
ok('con su motivo escrito', str_contains(
   (string) valor('SELECT anulado_nota FROM pedidos WHERE id = ?', [$P_VIEJO]), 'denegó'));
ok('y el de hace tres horas sigue vivo',
   valor('SELECT anulado_en FROM pedidos WHERE id = ?', [$P_RECIEN]) === null);
es('pasarlo otra vez no anula nada más', 0, pedidos_anular_denegados());

/* Y un pedido con dinero registrado esperando confirmación NO se toca, por
   muchas denegaciones que arrastre: alguien ya lo corrigió. */
$P_ARREGLADO = $mk_deneg(date('Y-m-d H:i:s', strtotime('-50 hours')));
pago_registrar($P_ARREGLADO, ['monto_centimos'=>30000,'metodo_item_id'=>$TRANS,
                              'fecha'=>date('Y-m-d'),'voucher'=>$VOU]);
es('el que ya volvió a pagar no se anula', 0, pedidos_anular_denegados());
ok('y sigue vivo', valor('SELECT anulado_en FROM pedidos WHERE id = ?', [$P_ARREGLADO]) === null);

/* Y un pedido YA ENTREGADO no se anula: la mercadería está en casa del
   cliente y lo que queda es una deuda que cobrar, no una venta que borrar.
   Anularlo la sacaba de «por cobrar» y liberaba su stock. */
$P_ENTREGADO = $mk_deneg(date('Y-m-d H:i:s', strtotime('-50 hours')));
q('UPDATE pedidos SET estado_id = ? WHERE id = ?',
  [(int) valor("SELECT id FROM pedido_estados WHERE clave='entregado'"), $P_ENTREGADO]);
es('un pedido entregado no se anula por un pago denegado', 0, pedidos_anular_denegados());
ok('y sigue entregado', valor('SELECT anulado_en FROM pedidos WHERE id = ?', [$P_ENTREGADO]) === null);

/* EL BARRIDO TIENE QUE CORRER DE VERDAD DESDE tareas_del_hub(). La primera
   versión escribía su marca con sembrar_fila(), que actualiza por `id` — y
   `ajustes` no tiene columna `id`: saltaba una excepción, el catch se la
   tragaba y el barrido NO CORRÍA NUNCA, mientras tareas.php salía diciendo
   que todo bien. Esta prueba mide que anula, no que devuelve cero. */
q('UPDATE pedidos SET estado_id = ? WHERE id = ?',
  [(int) valor("SELECT id FROM pedido_estados WHERE clave='registrado'"), $P_ENTREGADO]);
q("DELETE FROM ajustes WHERE clave = 'tareas_ultima'");
es('tareas_del_hub anula de verdad', 1, tareas_del_hub());
ok('y deja escrita su marca',
   (string) valor("SELECT valor FROM ajustes WHERE clave = 'tareas_ultima'") !== '');

/* El freno: la lista de pedidos dispara esto, y no puede hacerlo en cada
   recarga. */
$P_FRENO = $mk_deneg(date('Y-m-d H:i:s', strtotime('-50 hours')));
es('con la marca recién puesta, el freno corta', 0, tareas_del_hub(true));
ok('y el pedido sigue vivo',
   valor('SELECT anulado_en FROM pedidos WHERE id = ?', [$P_FRENO]) === null);
q("UPDATE ajustes SET valor = ? WHERE clave = 'tareas_ultima'",
  [date('Y-m-d H:i:s', strtotime('-2 hours'))]);
es('pasada la hora, vuelve a correr', 1, tareas_del_hub(true));

/* Y no firma con quien abrió la lista: lo anula el HUB. Se pasa como
   parámetro y NO vaciando $_SESSION: vaciar el superglobal deja una ventana en
   la que un fatal guardaría la sesión sin usuario y el asesor aparecería de
   golpe en la pantalla de entrar. */
es('la anulación automática no lleva firma de nadie', null,
   valor('SELECT anulado_por FROM pedidos WHERE id = ?', [$P_FRENO]));
ok('y la sesión sigue siendo la de antes', (int)($_SESSION['usuario_id'] ?? 0) === $ASESOR);
ok('una anulación a mano SÍ lleva firma', (function () use ($PAIS, $CLI_D, $ASESOR, $EQUIPO, $CANAL) {
    $id = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                        'equipo_id'=>$EQUIPO,'monto'=>1000,'canal_item_id'=>$CANAL]);
    $m = lista_item_por_texto('motivos_anulacion', 'Otro motivo', $PAIS);
    pedido_anular($id, (int)$m['id'], 'a mano');
    return (int) valor('SELECT anulado_por FROM pedidos WHERE id = ?', [$id]) === $ASESOR;
})());

/* Y las tres cifras del dinero, que son tres y no una. La del CLIENTE no
   cuenta como pagado lo que facturación puso EN ESPERA: ahí el banco dice que
   ese dinero no llegó y hay que seguir pidiéndoselo. */
$P_ESP = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                       'equipo_id'=>$EQUIPO,'monto'=>50000,'canal_item_id'=>$CANAL]);
$pg_esp = pago_registrar($P_ESP, ['monto_centimos'=>50000,'metodo_item_id'=>$TRANS,
                                  'fecha'=>date('Y-m-d'),'voucher'=>$VOU]);
$pe = pedido_de($P_ESP);
es('recién registrado, para el cliente está pagado', 50000, pedido_pagado_cliente($pe));
es('y no le queda nada por pagar',                    0,     pedido_por_cobrar_cliente($pe));
es('pero al asesor le sigue faltando la confirmación', 50000, pedido_pendiente_confirmar($pe));

banco_entrar($ADMIN);
pago_en_espera($pg_esp['id'], 'No está en el banco');
banco_entrar($ASESOR);
$pe = pedido_de($P_ESP);
es('en espera, para el cliente vuelve a deber', 50000, pedido_por_cobrar_cliente($pe));
es('y no consta como pagado',                    0,     pedido_pagado_cliente($pe));
es('pero no se le pide registrarlo otra vez',    0,     pedido_por_registrar($pe));

grupo('Auditoría 18 · cuándo hay que entregarlo');

$mal = pedido_entrega_guardar($PE1, ['fecha'=>'2026-13-45']);
ok('una fecha que no existe se rechaza', !$mal['ok'], 'la aceptó');
$mal = pedido_entrega_guardar($PE1, ['hora_desde'=>'25:00']);
ok('una hora imposible también', !$mal['ok']);
$mal = pedido_entrega_guardar($PE1, ['hora_desde'=>'18:00', 'hora_hasta'=>'09:00']);
ok('y un rango al revés se para en vez de guardarse', !$mal['ok'], $mal['error'] ?? '');

/* LA FECHA DE LA PRUEBA ES MAÑANA, no un día escrito a mano: desde el parche
   2r la entrega es a partir del día siguiente, así que una fecha fija de
   septiembre de 2026 empezaría a fallar sola con el calendario. */
$MANANA = date('Y-m-d', strtotime('+1 day'));
$hoy_no = pedido_entrega_guardar($PE1, ['fecha'=>date('Y-m-d'),'hora_desde'=>'15:00']);
ok('el mismo día no se puede prometer', !$hoy_no['ok'], 'lo aceptó');
ok('y el motivo lo dice sin rodeos',
   str_contains((string)($hoy_no['error'] ?? ''), 'a partir de mañana'), $hoy_no['error'] ?? '');
$ayer_no = pedido_entrega_guardar($PE1, ['fecha'=>date('Y-m-d', strtotime('-1 day'))]);
ok('y un día que ya pasó, tampoco', !$ayer_no['ok']);
$otro_ano = pedido_entrega_guardar($PE1, ['fecha'=>(date('Y') + 3) . '-03-10']);
ok('un dedazo en el año se para: nada a más de doce meses', !$otro_ano['ok'],
   'una entrega a tres años no es un acuerdo con nadie');
/* Doce meses y no «este año»: un 31 de diciembre, con el tope de año no
   quedaba NINGUNA fecha posible —mañana ya era del año siguiente— y una pre
   venta de octubre que llega en febrero tampoco podía llevar día. */
$dentro_de_un_ano = pedido_entrega_guardar($PE1, ['fecha'=>date('Y-m-d', strtotime('+90 days'))]);
ok('pero una entrega dentro de tres meses sí vale', $dentro_de_un_ano['ok'],
   $dentro_de_un_ano['error'] ?? '');

/* LO QUE YA ESTABA ACORDADO SE PUEDE SEGUIR TOCANDO. Un pedido acordado ayer
   «para hoy» llega a hoy con esa fecha, y hoy es justo cuando despacho lo abre
   para marcar «dejar en recepción». Antes había que borrar el día. */
q('UPDATE pedidos SET entrega_fecha = ? WHERE id = ?', [date('Y-m-d'), $PE1]);
$mismo = pedido_entrega_guardar($PE1, ['fecha'=>date('Y-m-d'), 'hora_desde'=>'16:00',
                                       'recepcion'=>true]);
ok('la fecha que ya estaba guardada no se vuelve a juzgar', $mismo['ok'], $mismo['error'] ?? '');
es('y el día acordado sigue ahí', date('Y-m-d'),
   (string) valor('SELECT entrega_fecha FROM pedidos WHERE id = ?', [$PE1]));
/* Pero cambiarla a otro día pasado sigue sin poder ser. */
$atras = pedido_entrega_guardar($PE1, ['fecha'=>date('Y-m-d', strtotime('-2 days'))]);
ok('cambiarla a un día que ya pasó se sigue rechazando', !$atras['ok']);

$ok = pedido_entrega_guardar($PE1, ['fecha'=>$MANANA,'hora_desde'=>'15:00',
                                    'hora_hasta'=>'18:00','recepcion'=>true]);
ok('un día con rango sí se guarda', $ok['ok'], $ok['error'] ?? '');
$pe = pedido_de($PE1);
$txt = pedido_entrega_texto($pe);
$dias = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
$tiene_dia = false;
foreach ($dias as $dd) if (str_contains($txt, $dd)) $tiene_dia = true;
ok('y el texto lleva el día de la semana', $tiene_dia, $txt);
ok('el rango', str_contains($txt, 'de 15:00 a 18:00'), $txt);
ok('y lo de recepción, en mayúsculas para que se vea',
   str_contains($txt, 'DEJAR EN RECEPCIÓN'), $txt);
ok('y el mensaje de despacho lo lleva dentro',
   str_contains(mensaje_de_pedido($PE1, 'despacho'), 'de 15:00 a 18:00'),
   mensaje_de_pedido($PE1, 'despacho'));

/* «Hasta las 18:00» sin decir desde cuándo no dice nada: se guarda como hora
   única, que es como lo lee quien reparte. */
pedido_entrega_guardar($PE1, ['hora_hasta'=>'18:00']);
$txt = pedido_entrega_texto(pedido_de($PE1));
/* Y fuera de Lima no se guarda, la pida quien la pida. La regla vive en
   pedido_entrega_guardar(), que es la única función que escribe esos cuatro
   campos: si viviera en la pantalla de registrar, la de despacho seguiría
   dejando ponerle día y hora a un pedido a Cusco. */
$PE_CUS = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_E,'asesor_id'=>$ASESOR,
                        'equipo_id'=>$EQUIPO,'monto'=>30000,'canal_item_id'=>$CANAL,
                        'entrega'=>'envio','ubigeo_id'=>$CUS_ID,'recojo_agencia'=>1]);
$r_cus = pedido_entrega_guardar($PE_CUS, ['fecha'=>$MANANA,'hora_desde'=>'15:00']);
/* Y SE LE DICE. Descartarla en silencio devolviendo «ok» era peor que
   guardarla: el de despacho tecleaba la hora con el cliente al teléfono, leía
   «Anotado, el mensaje ya lo dice» en verde, y ni el mensaje lo decía ni la
   base lo tenía. */
ok('a provincia la ventana de entrega se rechaza', !$r_cus['ok']);
ok('y el motivo dice quién pone la hora',
   str_contains($r_cus['error'], 'agencia'), $r_cus['error']);
es('no se guarda ninguna fecha', null,
   valor('SELECT entrega_fecha FROM pedidos WHERE id = ?', [$PE_CUS]));
ok('pero guardar SIN ventana no da error: no había nada que prometer',
   pedido_entrega_guardar($PE_CUS, [])['ok']);

$PE_OFI = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_E,'asesor_id'=>$ASESOR,
                        'equipo_id'=>$EQUIPO,'monto'=>30000,'canal_item_id'=>$CANAL]);
$r_ofi = pedido_entrega_guardar($PE_OFI, ['fecha'=>'2026-09-11','hora_desde'=>'15:00']);
ok('y en un recojo en oficina también se rechaza', !$r_ofi['ok']);
es('sin guardar nada', null,
   valor('SELECT entrega_fecha FROM pedidos WHERE id = ?', [$PE_OFI]));

ok('«solo hasta» se convierte en hora única', str_contains($txt, 'a las 18:00'), $txt);

/* Y una venta sin nada de esto no ensucia el mensaje con una línea vacía. */
pedido_entrega_guardar($PE1, []);
es('sin fecha ni hora, el texto queda vacío', '', pedido_entrega_texto(pedido_de($PE1)));
ok('y la línea «Entregar:» se cae sola del mensaje',
   !str_contains(mensaje_de_pedido($PE1, 'despacho'), 'Entregar:'),
   mensaje_de_pedido($PE1, 'despacho'));

grupo('Auditoría 18 · el aviso de despacho es solo para quien despacha');

banco_entrar($ASESOR);
ok('al asesor se le avisa', le_avisamos_de_despacho(yo()));
banco_entrar($FACTU);
ok('a facturación no', !le_avisamos_de_despacho(yo()));
banco_entrar($ADMIN);
ok('y a Administración tampoco, aunque pueda mandarlo',
   !le_avisamos_de_despacho(yo()));
ok('pero el permiso sí lo tiene', puede_el(yo(), 'pedidos.despachar'));
banco_entrar($DIR);
ok('Dirección ni puede ni se le avisa',
   !puede_el(yo(), 'pedidos.despachar') && !le_avisamos_de_despacho(yo()));

grupo('Auditoría 14 · denegar es atómico y deja UNA sola historia');

banco_entrar($ASESOR);
$CLI_A = nuevo_cliente($PAIS, $ASESOR, '10000064', 'catom@waka.test');
$PA = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_A,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>15000,'canal_item_id'=>$CANAL]);
$pgA = pago_registrar($PA, ['monto_centimos'=>15000,'metodo_item_id'=>$TRANS,'fecha'=>$HOY,'voucher'=>$VOU]);
banco_entrar($FACTU);
pago_en_espera($pgA['id'], 'lo estoy mirando');
pago_denegar($pgA['id'], 'El voucher es falso');

$fila = una('SELECT * FROM pagos WHERE id = ?', [$pgA['id']]);
es('queda anulado',    1, (int)$fila['anulado']);
es('y denegado',       1, (int)$fila['denegado']);
es('y ya NO en espera', 0, (int)$fila['en_espera']);

$eventos = todas("SELECT texto FROM pedido_eventos WHERE pedido_id = ? AND tipo = 'pago'", [$PA]);
$dice_deneg = 0; $dice_quito = 0;
foreach ($eventos as $ev) {
    if (str_contains((string)$ev['texto'], 'DENEGÓ'))     $dice_deneg++;
    if (str_contains((string)$ev['texto'], 'Se quitó'))   $dice_quito++;
}
es('la ficha cuenta la denegación una vez', 1, $dice_deneg);
es('y no dice además «se quitó»',           0, $dice_quito);
ok('un motivo de 500 caracteres se rechaza, no se recorta',
   !pago_anular($pgA['id'], str_repeat('z', 500))['ok']);

grupo('Deshacer una confirmación NO es lo mismo que devolverle al cliente');

banco_entrar($ASESOR);
$CLI_V = nuevo_cliente($PAIS, $ASESOR, '10000065', 'crev@waka.test');
$PV = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_V,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>30000,'canal_item_id'=>$CANAL]);
$pgV = cobrar($PV, 30000, $TRANS, $HOY, $VOU);
banco_entrar($ADMIN);
ok('se puede deshacer la confirmación', pago_devolver($pgV['id'], 'Me equivoqué', 'error')['ok']);
es('la fila negativa dice de cuál de las dos vino', 'error',
   (string) valor("SELECT reverso_clase FROM pagos WHERE revierte_pago_id = ?", [$pgV['id']]));
es('y el pedido vuelve a tener su saldo', 30000, pedido_saldo(pedido_de($PV)));

$pgV2 = cobrar($PV, 30000, $TRANS, $HOY, $VOU);
banco_entrar($ADMIN);
ok('y devolverle al cliente es la otra', pago_devolver($pgV2['id'], 'Se arrepintió', 'cliente')['ok']);
es('con su propia etiqueta', 'cliente',
   (string) valor("SELECT reverso_clase FROM pagos WHERE revierte_pago_id = ?", [$pgV2['id']]));
ok('una clase inventada cae en «cliente», nunca en otra cosa',
   in_array((string) valor("SELECT reverso_clase FROM pagos WHERE revierte_pago_id = ?", [$pgV2['id']]),
            ['cliente','error'], true));

grupo('Auditoría 15 · revertir devuelve el pedido a su estado real');

banco_entrar($ASESOR);
$CLI_R2 = nuevo_cliente($PAIS, $ASESOR, '10000071', 'cestado@waka.test');
$PR2 = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_R2,'asesor_id'=>$ASESOR,
                     'equipo_id'=>$EQUIPO,'monto'=>50000,'canal_item_id'=>$CANAL]);
cobrar($PR2, 50000, $TRANS, $HOY, $VOU);
es('cobrado al 100% pasa a «pagado»', 'pagado', (string) pedido_de($PR2)['estado']);

banco_entrar($ADMIN);
$pg_r2 = (int) valor("SELECT id FROM pagos WHERE pedido_id = ? AND tipo = 'cobro'", [$PR2]);
pago_devolver($pg_r2, 'Me equivoqué', 'error');
$ped_r2 = pedido_de($PR2);
es('al revertir vuelve a quedar con su saldo', 50000, pedido_saldo($ped_r2));
ok('y NO se queda diciendo «pagado»', (string)$ped_r2['estado'] !== 'pagado',
   'sigue en ' . $ped_r2['estado']);
es('vuelve al estado con el que nace su tipo de venta', 'registrado', (string)$ped_r2['estado']);

grupo('Auditoría 15 · el saldo con la fila reservada cuenta lo pendiente');

/* La comprobación de DENTRO de la transacción es la que serializa dos pestañas
   a la vez, y miraba «total − cobrado», que no ve los pagos pendientes. */
banco_entrar($ASESOR);
$CLI_T = nuevo_cliente($PAIS, $ASESOR, '10000072', 'ctx@waka.test');
$PT = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_T,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>60000,'canal_item_id'=>$CANAL]);
pago_registrar($PT, ['monto_centimos'=>60000,'metodo_item_id'=>$TRANS,'fecha'=>$HOY,'voucher'=>$VOU]);
$ped_t = una('SELECT id, total_centimos, cobrado_centimos FROM pedidos WHERE id = ?', [$PT]);
es('el saldo confirmado sigue siendo el total', 60000,
   (int)$ped_t['total_centimos'] - (int)$ped_t['cobrado_centimos']);
es('pero lo que falta por REGISTRAR es cero', 0, pedido_por_registrar($ped_t));

grupo('Auditoría 15 · una dirección con barra invertida no saca a nadie del HUB');

foreach (['/inicio' => '/inicio',
          '/pedidos?x=1' => '/pedidos?x=1',
          '/\\evil.com' => '/inicio',
          '//evil.com' => '/inicio',
          '\\\\evil.com' => '/inicio',
          'https://evil.com' => '/inicio',
          '' => '/inicio'] as $entra => $sale) {
    es('destino_seguro(' . json_encode($entra) . ')', $sale, destino_seguro($entra));
}

/* ══════════  LA COLA DE LA TIENDA  ══════════ */
grupo('Cola de compraenwaka');
banco_entrar($ASESOR);
tienda_encolar($CLIENTE);
es('el cliente entra en cola', 'en_cola',
   (string) valor('SELECT estado_tienda FROM clientes WHERE id=?', [$CLIENTE]));
tienda_encolar($CLIENTE);
es('encolarlo dos veces no lo duplica', 1,
   (int) valor('SELECT COUNT(*) FROM cola_tienda WHERE cliente_id=?', [$CLIENTE]));

/* ══════════  FLETE  ══════════ */
grupo('El flete de provincia NO entra en el total');

$F = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLIENTE,'asesor_id'=>$ASESOR,
                   'equipo_id'=>$EQUIPO,'monto'=>37900,'canal_item_id'=>$CANAL,
                   'flete'=>3000,'flete_lo_paga'=>'cliente_destino']);
es('el total es el producto, sin el flete', 37900,
   (int) valor('SELECT total_centimos FROM pedidos WHERE id=?', [$F]));

actualizar('pedidos', $F, ['flete_lo_paga' => 'incluido']);
pedido_recalcular($F);
es('si lo cobra Waka, sí entra', 40900,
   (int) valor('SELECT total_centimos FROM pedidos WHERE id=?', [$F]));

/* ══════════  BORRÓN Y CUENTA NUEVA  ══════════
   VA AL FINAL A PROPÓSITO: vacía la base con la que trabajan todas las pruebas
   de arriba, así que cualquier grupo escrito después de aquí correría contra un
   HUB vacío y pasaría por casualidad. */
require_once HUB_APP . '/nucleo/limpieza.php';
grupo('Borrón y cuenta nueva · se va el movimiento, se queda la casa');

banco_entrar($ADMIN);

/* Antes de borrar hay que tener algo que borrar: si estas cifras fueran cero,
   el «después» pasaría sin probar nada. */
ok('había pedidos que borrar',  (int) valor('SELECT COUNT(*) FROM pedidos')  > 0);
ok('y clientes',                (int) valor('SELECT COUNT(*) FROM clientes') > 0);
ok('y pagos',                   (int) valor('SELECT COUNT(*) FROM pagos')    > 0);
ok('y movimientos de cashback', (int) valor('SELECT COUNT(*) FROM cashback_movimientos') > 0);

$usuarios_antes = (int) valor('SELECT COUNT(*) FROM usuarios');
$equipos_antes  = (int) valor('SELECT COUNT(*) FROM equipos');
$listas_antes   = (int) valor('SELECT COUNT(*) FROM lista_items');
$plant_antes    = (int) valor('SELECT COUNT(*) FROM plantillas_whatsapp');
$estados_antes  = (int) valor('SELECT COUNT(*) FROM pedido_estados');
$ubigeo_antes   = (int) valor('SELECT COUNT(*) FROM ubigeo');
$ajustes_antes  = (int) valor('SELECT COUNT(*) FROM ajustes');
$metas_antes    = (int) valor('SELECT COUNT(*) FROM metas');
$lotes_antes    = (int) valor('SELECT COUNT(*) FROM lotes');

$borrado = limpieza_ejecutar(false);

es('no queda ni un pedido',   0, (int) valor('SELECT COUNT(*) FROM pedidos'));
es('ni una línea de pedido',  0, (int) valor('SELECT COUNT(*) FROM pedido_lineas'));
es('ni un evento de pedido',  0, (int) valor('SELECT COUNT(*) FROM pedido_eventos'));
es('ni un pago',              0, (int) valor('SELECT COUNT(*) FROM pagos'));
es('ni un cliente',           0, (int) valor('SELECT COUNT(*) FROM clientes'));
es('ni una dirección suya',   0, (int) valor('SELECT COUNT(*) FROM cliente_direcciones'));
es('ni un movimiento de cashback', 0, (int) valor('SELECT COUNT(*) FROM cashback_movimientos'));
es('ni una racha',            0, (int) valor('SELECT COUNT(*) FROM rachas'));
es('ni una visita',           0, (int) valor('SELECT COUNT(*) FROM visitas'));
es('ni una línea de bitácora',0, (int) valor('SELECT COUNT(*) FROM bitacora'));

/* Y AHORA LA MITAD QUE IMPORTA: que no se haya llevado la casa por delante.
   Un borrado que además se lleva a los 19 asesores no es una limpieza, es
   volver a instalar — y eso el usuario no lo pidió ni lo esperaría. */
es('los usuarios siguen ahí',      $usuarios_antes, (int) valor('SELECT COUNT(*) FROM usuarios'));
es('los equipos también',          $equipos_antes,  (int) valor('SELECT COUNT(*) FROM equipos'));
es('las listas editables',         $listas_antes,   (int) valor('SELECT COUNT(*) FROM lista_items'));
es('las plantillas de WhatsApp',   $plant_antes,    (int) valor('SELECT COUNT(*) FROM plantillas_whatsapp'));
es('los estados del pedido',       $estados_antes,  (int) valor('SELECT COUNT(*) FROM pedido_estados'));
es('el ubigeo entero',             $ubigeo_antes,   (int) valor('SELECT COUNT(*) FROM ubigeo'));
es('los ajustes',                  $ajustes_antes,  (int) valor('SELECT COUNT(*) FROM ajustes'));
es('y las metas ya fijadas',       $metas_antes,    (int) valor('SELECT COUNT(*) FROM metas'));

/* El catálogo va aparte y solo si se pide: borrar la carga de verdad sería la
   sorpresa más cara de todas. */
es('el catálogo NO se toca si no se pide', $lotes_antes, (int) valor('SELECT COUNT(*) FROM lotes'));

grupo('Borrón y cuenta nueva · los archivos del disco');

/* «¿Qué se borra y qué queda?» (usuario, 2026-09-14). Vaciar `pagos` y
   `pedidos` deja sin dueño los archivos que colgaban de esas filas, y no hay
   pantalla que pueda enseñarlos ni borrarlos después: se quedan en el disco
   para siempre. Los vouchers ya se iban; las BOLETAS Y FACTURAS subidas no, y
   llevan dentro el nombre, el documento y lo que compró el cliente.
   Las FOTOS DE PERFIL se quedan: son de los usuarios, y los usuarios no se van. */
$marca_l = 'prueba-limpieza-' . bin2hex(random_bytes(4));
$archivos_l = [
    'vouchers'     => HUB_RAIZ . '/uploads/vouchers/' . $marca_l . '.jpg',
    'comprobantes' => HUB_RAIZ . '/uploads/comprobantes/' . $marca_l . '.pdf',
];
$foto_l = HUB_RAIZ . '/uploads/fotos/' . $marca_l . '.jpg';
foreach ($archivos_l as $ruta_l) { @mkdir(dirname($ruta_l), 0775, true); @file_put_contents($ruta_l, 'x'); }
@mkdir(dirname($foto_l), 0775, true); @file_put_contents($foto_l, 'x');

ok('hay un voucher de prueba en el disco',      is_file($archivos_l['vouchers']));
ok('y una boleta subida',                       is_file($archivos_l['comprobantes']));
ok('y una foto de perfil',                      is_file($foto_l));

limpieza_borrar_vouchers();

ok('el voucher se va',   !is_file($archivos_l['vouchers']));
ok('la boleta también',  !is_file($archivos_l['comprobantes']),
   'queda en el disco el nombre, el documento y lo que compró un cliente, sin dueño');
ok('la foto de perfil NO se toca: el usuario se queda', is_file($foto_l));
ok('y el .htaccess que cierra la carpeta sigue puesto',
   is_file(HUB_RAIZ . '/uploads/vouchers/.htaccess')
   || !is_dir(HUB_RAIZ . '/uploads/vouchers'));
@unlink($foto_l);

grupo('Borrón y cuenta nueva · la numeración vuelve a empezar');

/* El código del pedido sale del id. Sin reiniciar el contador, la primera
   venta de verdad sería P-00238 y el HUB arrancaría con la numeración de las
   pruebas a cuestas — que es justo lo que se quería quitar de encima. */
$CLI_N = nuevo_cliente($PAIS, $ASESOR, '44556677', 'primero@waka.test');
$PN = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_N,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>10000,'canal_item_id'=>$CANAL]);
es('la primera venta después de limpiar es P-00001', 'P-00001',
   (string) valor('SELECT codigo FROM pedidos WHERE id = ?', [$PN]));

/* Y el HUB sigue funcionando después del borrado: registrar, cobrar, validar.
   Un HUB limpio que ya no deja vender es peor que uno sucio. */
$pgN = pago_registrar($PN, ['monto_centimos'=>10000,'metodo_item_id'=>$TRANS,
                            'fecha'=>$HOY,'voucher'=>$VOU]);
ok('se puede registrar un pago en el HUB recién limpiado', $pgN['ok'], $pgN['error'] ?? '');
banco_entrar($FACTU);
ok('y confirmarlo', pago_validar($pgN['id'], 'OP-00914')['ok']);

grupo('Borrón y cuenta nueva · el catálogo, solo cuando se pide');

banco_entrar($ADMIN);
limpieza_ejecutar(true);
es('pedido el catálogo, se va también', 0, (int) valor('SELECT COUNT(*) FROM lotes'));
es('y su stock', 0, (int) valor('SELECT COUNT(*) FROM stock'));

/* ══════════  EL ENVÍO CON COSTO  ══════════
   Lo reportó el usuario probando: elegía «Envío con costo en Lima» y el
   formulario no tenía dónde escribir el monto, porque quien decidía era la
   rama Lima/provincia y no una propiedad de la lista. */
grupo('EL ENVÍO CON COSTO · suma al total, pero no al cashback');

ok('«Envío con costo en Lima» viene marcado como que cobra flete',
   tipo_envio_cobra_flete((int) valor("SELECT i.id FROM lista_items i
                                         JOIN listas l ON l.id = i.lista_id
                                        WHERE l.clave = 'tipos_envio'
                                          AND i.valor = 'Envío con costo en Lima'")));
ok('«Envío gratis Lima» no',
   !tipo_envio_cobra_flete((int) valor("SELECT i.id FROM lista_items i
                                          JOIN listas l ON l.id = i.lista_id
                                         WHERE l.clave = 'tipos_envio'
                                           AND i.valor = 'Envío gratis Lima'")));

es('el flete de provincia NO entra en el total', 0,
   pedido_flete_dentro(['flete_lo_paga' => 'cliente_destino', 'flete_centimos' => 5000]));
es('el que cobra Waka sí',                    5000,
   pedido_flete_dentro(['flete_lo_paga' => 'incluido',        'flete_centimos' => 5000]));

$CLI_F = nuevo_cliente($PAIS, $ASESOR, '71727374', 'conflete@waka.test');
$PF = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_F,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>100000,'canal_item_id'=>$CANAL,
                    'flete'=>5000,'flete_lo_paga'=>'incluido']);
es('producto S/1,000 + envío S/50 = total S/1,050', 105000,
   (int) valor('SELECT total_centimos FROM pedidos WHERE id = ?', [$PF]));
es('y la base del cashback es solo el producto', 100000,
   pedido_base_cashback(pedido_de($PF)));

/* El filo del asunto: el cashback nace del PAGO y el cliente paga producto y
   envío en un solo monto. Con un pago parcial hay que saber qué parte era
   envío, y se reparte en proporción. */
$rf1 = cobrar($PF, 50000, $YAPE, $HOY, $VOU);
ok('el adelanto de S/500 se registra', $rf1['ok'], $rf1['error'] ?? '');
es('acredita S/4.76, no S/5.00: el envío no cuenta', 476, $rf1['cashback']);

$rf2 = cobrar($PF, 55000, $YAPE, $HOY, $VOU);
ok('el saldo se completa', $rf2['ok'], $rf2['error'] ?? '');

/* S/9.99 y no S/10.00, y está bien: cada pago se trunca hacia abajo por
   separado —la regla que ya tenía el HUB— así que dos pagos pueden perder un
   céntimo contra el ideal. Se prefiere eso a que cada pago dependa de los
   demás: así revertir un pago quita EXACTAMENTE lo que ese pago acreditó, que
   es lo que la auditoría 8 dejó escrito con sangre. Lo que nunca puede pasar
   es lo contrario —pagar cashback por el envío— y eso es lo que se comprueba
   con el tope. */
es('al terminar de pagar quedan S/9.99: se trunca hacia abajo, nunca arriba',
   999, cashback_saldo($CLI_F));
ok('y NUNCA pasa del 1% del producto (S/10.00), que sería regalar el envío',
   cashback_saldo($CLI_F) <= 1000, (string) cashback_saldo($CLI_F));

/* Y sin envío nada cambia: el camino de siempre sigue dando el 1% redondo. */
$CLI_S = nuevo_cliente($PAIS, $ASESOR, '81828384', 'sinflete@waka.test');
$PS = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_S,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>100000,'canal_item_id'=>$CANAL]);
$rs = cobrar($PS, 100000, $YAPE, $HOY, $VOU);
es('un pedido sin envío acredita el 1% de todo', 1000, $rs['cashback']);

/* ══════════  EL COLOR DE LOS ESTADOS  ══════════
   «visualmente todo está muy plano y parece lo mismo» (usuario). El color
   estaba escrito tres veces, con colores que no coincidían entre pantallas. */
grupo('EL ESTADO DE UN PAGO · una sola definición, y nunca color a secas');

es('sin revisar es NEUTRO, no una alarma: es el estado normal de toda venta',
   'chip--gris', pago_chip(['verificado'=>0])[0]);
es('en espera es ÁMBAR: alguien lo miró y lo dejó parado',
   'chip--ambar', pago_chip(['verificado'=>0,'en_espera'=>1])[0]);
es('confirmado es VERDE',  'chip--verde', pago_chip(['verificado'=>1])[0]);
es('denegado es ROJO',     'chip--rojo',  pago_chip(['denegado'=>1])[0]);
es('quitado es GRIS: dejó de existir, sin culpa de nadie',
   'chip--gris', pago_chip(['anulado'=>1])[0]);

foreach ([['verificado'=>0], ['verificado'=>0,'en_espera'=>1], ['verificado'=>1],
          ['denegado'=>1], ['anulado'=>1]] as $caso) {
    [$cls, $txt] = pago_chip($caso);
    ok('«' . $txt . '» lleva su palabra, no solo su color', trim($txt) !== '');
}

/* El amarillo de marca no puede ser un estado: 1.36:1 de contraste. */
foreach ([['verificado'=>0], ['verificado'=>0,'en_espera'=>1], ['verificado'=>1],
          ['denegado'=>1], ['anulado'=>1]] as $caso) {
    ok('ningún estado usa el amarillo de marca', pago_chip($caso)[0] !== 'chip--marca');
}

es('la nota de «en espera» viaja con el chip', 'No llegó al banco',
   pago_chip(['verificado'=>0,'en_espera'=>1,'espera_nota'=>'No llegó al banco'])[3]);

/* ══════════  LO QUE ABRIÓ LA PRIMERA CORRECCIÓN  ══════════
   Segunda pasada de auditoría sobre el propio parche. En este proyecto, cuatro
   de cinco veces la segunda pasada encontró algo que abrió la primera. */
grupo('SEGUNDA PASADA · lo que abrieron las correcciones');

/* El flete a provincia NO es obligatorio: ese dinero es de la agencia y el
   asesor muchas veces todavía no sabe cuánto es. Marcarlo como «cobra flete»
   dejaba sin poder guardar toda venta a provincia. */
$id_prov = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id
                         WHERE l.clave = 'tipos_envio' AND i.valor = 'Flete a provincia'");
ok('«Flete a provincia» NO obliga a escribir el monto', !tipo_envio_cobra_flete($id_prov),
   'el flete de la agencia se sabe después; exigirlo bloquea la venta');
ok('«Envío con costo en Lima» sí, porque ahí lo cobra Waka',
   tipo_envio_cobra_flete((int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id=i.lista_id
                                        WHERE l.clave='tipos_envio' AND i.valor='Envío con costo en Lima'")));

/* Y la función se defiende sola, como sus hermanas de métodos de pago. */
ok('un item de OTRA lista no cobra flete',
   !tipo_envio_cobra_flete((int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id=i.lista_id
                                         WHERE l.clave='metodos_pago' LIMIT 1")));
ok('y un id que no existe tampoco', !tipo_envio_cobra_flete(999999));

/* La denegación deja de ser tarea cuando el asesor registra otro pago — no
   cuando el pedido queda saldado. Con el corte por saldo, deshacer una
   confirmación resucitaba una denegación de hace meses como noticia nueva. */
$CLI_D = nuevo_cliente($PAIS, $ASESOR, '91929394', 'denegado@waka.test');
$PD = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                    'equipo_id'=>$EQUIPO,'monto'=>50000,'canal_item_id'=>$CANAL]);
$pg_d = pago_registrar($PD, ['monto_centimos'=>20000,'metodo_item_id'=>$TRANS,
                             'fecha'=>$HOY,'voucher'=>$VOU]);
ok('se registra un pago que luego se deniega', $pg_d['ok'], $pg_d['error'] ?? '');

banco_entrar($FACTU);
ok('facturación lo deniega', pago_denegar($pg_d['id'], 'El voucher no cuadra')['ok']);
banco_entrar($ASESOR);
es('el asesor lo tiene en su lista de trabados', 1, pagos_trabados_resumen(yo())['n']);

/* Rehace el pago: la denegación deja de ser tarea suya. */
$pg_d2 = pago_registrar($PD, ['monto_centimos'=>20000,'metodo_item_id'=>$TRANS,
                              'fecha'=>$HOY,'voucher'=>$VOU]);
ok('el asesor manda el voucher bueno', $pg_d2['ok'], $pg_d2['error'] ?? '');
pagos_trabados_olvidar();
es('y la denegación sale sola de la lista', 0, pagos_trabados_resumen(yo())['n']);

/* La línea del flete en el mensaje tiene que decir lo que el total dice. */
$P_LIMA = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                        'equipo_id'=>$EQUIPO,'monto'=>100000,'canal_item_id'=>$CANAL,
                        'flete'=>5000,'flete_lo_paga'=>'incluido']);
$vars = variables_de_pedido($P_LIMA);
ok('si el envío va DENTRO del total, el mensaje lo dice',
   str_contains((string)$vars['nota_flete'], 'Incluye'), (string)$vars['nota_flete']);

$P_PROV = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                        'equipo_id'=>$EQUIPO,'monto'=>100000,'canal_item_id'=>$CANAL,
                        'flete'=>5000,'flete_lo_paga'=>'cliente_destino']);
$vars2 = variables_de_pedido($P_PROV);
ok('y si lo paga en destino, también, sin pedirle el dinero dos veces',
   str_contains((string)$vars2['nota_flete'], 'destino'), (string)$vars2['nota_flete']);

$P_SIN = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                       'equipo_id'=>$EQUIPO,'monto'=>100000,'canal_item_id'=>$CANAL]);
es('sin flete la línea desaparece sola', '', (string) variables_de_pedido($P_SIN)['nota_flete']);

/* ═══════════════  LA GARANTÍA  ═══════════════ */
grupo('Garantía · va en el pedido, no en el producto');

$G12  = $id_lista('garantias', '12 meses');
$G6   = $id_lista('garantias', '6 meses');
$GNO  = $id_lista('garantias', 'No aplica');
es('la lista trae las tres', 3, count(lista('garantias', $PAIS)));
ok('los tres items existen', $G12 > 0 && $G6 > 0 && $GNO > 0);
es('la que viene marcada es la primera de la lista', $G12, garantia_por_defecto());
es('y a la pre venta le toca la misma', $G12, garantia_por_defecto('preventa'));
/* Sin esto, «Liquidación» habría salido con doce meses puestos y el asesor
   tendría que acordarse de cambiarlo en cada venta. El día que se olvide, Waka
   responde un año por un saldo de almacén. */
es('a la liquidación le toca «No aplica»', $GNO, garantia_por_defecto('liquidacion'));

$P_G = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                     'equipo_id'=>$EQUIPO,'monto'=>50000,'canal_item_id'=>$CANAL,
                     'garantia_item_id'=>$G6]);
es('la ficha dice la garantía pactada', '6 meses', pedido_garantia_texto(pedido_de($P_G)));
es('y el mensaje también', '6 meses', (string) variables_de_pedido($P_G)['garantia']);

/* Los pedidos de antes de esta versión no la tienen. Ni se inventa ni se
   pinta: la línea de la plantilla se cae sola, como todas. */
$P_SG = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                      'equipo_id'=>$EQUIPO,'monto'=>50000,'canal_item_id'=>$CANAL]);
es('un pedido viejo no dice ninguna garantía', '', pedido_garantia_texto(pedido_de($P_SG)));
$msg_g  = mensaje_de_pedido($P_G,  plantilla_de_venta(pedido_de($P_G)));
$msg_sg = mensaje_de_pedido($P_SG, plantilla_de_venta(pedido_de($P_SG)));
ok('el mensaje del que la tiene la lleva', str_contains($msg_g, 'Garantía: 6 meses'), $msg_g);
ok('y el del que no, no lleva la línea vacía', !str_contains($msg_sg, 'Garantía'), $msg_sg);

/* Un valor usado en una venta no se borra: se apaga. La garantía tenía que
   entrar en esa cuenta, o Configuración habría dejado borrar «6 meses» y el
   pedido se quedaba sin nombre de garantía. */
ok('una garantía ya usada cuenta como usada', lista_usos($G6) >= 1);

grupo('Lo que no cabe en la base no se ofrece');

/* En el banco los ENUM son VARCHAR, así que caben los tres. Lo que se mide
   aquí es lo otro: que la función no reordene los radios del formulario y que
   nunca devuelva una lista vacía, que dejaría la pantalla de ventas
   inutilizable en un HUB que antes vendía. */
es('se ofrecen en el orden del formulario',
   array_keys(tipos_de_venta()), array_keys(tipos_de_venta_ofrecidos()));
ok('y nunca se queda sin ninguno', count(tipos_de_venta_ofrecidos()) > 0);
es('una columna VARCHAR no estrecha nada', [],
   enum_falta_admitir('pedidos', 'tipo', "ENUM('inmediata','preventa','liquidacion')"));
es('ni una tabla que no existe', [],
   enum_falta_admitir('tabla_que_no_existe', 'x', "ENUM('a')"));

/* ═══════════════  LIQUIDACIÓN  ═══════════════ */
grupo('Liquidación · tipo de venta propio, stock propio, sin cashback');

es('nace registrada, como la entrega inmediata', 'registrado', estado_inicial('liquidacion'));

$P_LQ = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                      'equipo_id'=>$EQUIPO,'tipo'=>'liquidacion','monto'=>100000,
                      'canal_item_id'=>$CANAL,'garantia_item_id'=>$GNO]);
es('el tipo se guarda tal cual', 'liquidacion',
   (string) valor('SELECT tipo FROM pedidos WHERE id = ?', [$P_LQ]));
es('la línea sale del almacén de liquidación', 'liquidacion',
   (string) valor('SELECT origen FROM pedido_lineas WHERE pedido_id = ?', [$P_LQ]));
es('la ficha lo llama Liquidación', 'Liquidación', tipo_venta_texto((string) pedido_de($P_LQ)['tipo']));
es('y sale sin garantía', 'No aplica', pedido_garantia_texto(pedido_de($P_LQ)));

/* LAS DOS DIRECCIONES DEL CASHBACK. Esta es la que cuesta dinero de verdad:
   el precio de liquidación YA es el descuento, y acreditar encima el 1% es
   descontar dos veces. */
$cb_antes = cashback_saldo($CLI_D);
$r_lq = cobrar($P_LQ, 100000, $YAPE, $HOY, $VOU);
ok('el pago se registra y se confirma igual que cualquiera', $r_lq['ok'], $r_lq['error']);
es('pero no acredita nada de cashback', 0, $r_lq['cashback']);
es('el saldo del cliente no se mueve', $cb_antes, cashback_saldo($CLI_D));

/* Y la misma venta, como entrega inmediata, sí acredita: así la prueba mide la
   regla y no un cashback que estuviera apagado por otro motivo. */
$P_IN = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                      'equipo_id'=>$EQUIPO,'tipo'=>'inmediata','monto'=>100000,
                      'canal_item_id'=>$CANAL]);
$r_in = cobrar($P_IN, 100000, $YAPE, $HOY, $VOU);
es('la misma venta como inmediata sí acredita el 1%', 1000, $r_in['cashback']);

grupo('Parche 2j · el estado del pago manda el PEOR');

/* «Que mande el peor estado, ya que el error es más relevante: es solicitar
   una acción para resolver eso» (usuario, 2026-09-11). Se prueba con la mezcla
   que de verdad se da: una pre venta con el adelanto confirmado y el saldo
   denegado. Sin esta regla ese pedido saldría «Confirmado» en la lista y nadie
   iría a arreglar nada. */
$POS = $id_metodo('POS / tarjeta');
$estado_de = fn(int $id) => pedido_estado_pago(['id' => $id]);

$P_EP = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                      'equipo_id'=>$EQUIPO,'tipo'=>'preventa','monto'=>100000,
                      'canal_item_id'=>$CANAL]);
es('sin ningún pago, el estado está en blanco', '', $estado_de($P_EP));

$pg1 = pago_registrar($P_EP, ['monto_centimos'=>20000,'metodo_item_id'=>$YAPE,
                              'fecha'=>$HOY,'voucher'=>$VOU]);
es('con un pago sin mirar, «sin_revisar»', 'sin_revisar', $estado_de($P_EP));

banco_entrar($GLOBALS['ADMIN']);
pago_validar((int)$pg1['id'], 'OP-1');
banco_entrar($ASESOR);
es('confirmado ese pago, «confirmado»', 'confirmado', $estado_de($P_EP));

$pg2 = pago_registrar($P_EP, ['monto_centimos'=>30000,'metodo_item_id'=>$YAPE,
                              'fecha'=>$HOY,'voucher'=>$VOU]);
banco_entrar($GLOBALS['ADMIN']);
pago_en_espera((int)$pg2['id'], 'Interbancario, entra mañana');
banco_entrar($ASESOR);
es('con uno confirmado y otro en espera, manda el peor', 'espera', $estado_de($P_EP));

$pg3 = pago_registrar($P_EP, ['monto_centimos'=>30000,'metodo_item_id'=>$YAPE,
                              'fecha'=>$HOY,'voucher'=>$VOU]);
banco_entrar($GLOBALS['ADMIN']);
pago_denegar((int)$pg3['id'], 'El voucher no aparece en el banco');
banco_entrar($ASESOR);
es('y con uno denegado, manda el denegado', 'denegado', $estado_de($P_EP));

/* Y DEJA DE MANDAR EN CUANTO EL ASESOR REGISTRA OTRO PAGO — la misma regla que
   PAGO_TRABADO. Sin esto, un pedido arreglado hace tres meses seguiría
   gritando en rojo en la lista para siempre. */
pago_registrar($P_EP, ['monto_centimos'=>30000,'metodo_item_id'=>$YAPE,
                       'fecha'=>$HOY,'voucher'=>$VOU]);
es('registrado el voucher bueno, el denegado deja de mandar', 'espera', $estado_de($P_EP));

/* La consulta de la LISTA y la de la FICHA son la misma frase: si alguien
   cambiara una, aquí saltaría. */
$de_la_lista = (string) valor('SELECT (' . sql_estado_pago('p.id') . ') FROM pedidos p WHERE p.id = ?',
                              [$P_EP]);
es('la lista y la ficha dicen lo mismo del mismo pedido', $estado_de($P_EP), $de_la_lista);

/* Cómo se pinta. Lo que importa de verdad es `accion`: es lo que decide si
   sale la banda roja dentro del pedido. */
foreach ([['denegado', true], ['espera', true], ['sin_revisar', false],
          ['confirmado', false], ['', false]] as [$clave, $pide]) {
    /* Las comillas angulares se pegan al nombre de la variable si no se
       encierra con llaves: «{$clave}» buscaba una variable llamada `clave»`. Ya
       pasó una vez en este proyecto y por eso está escrito así. */
    es("«{$clave}» " . ($pide ? 'pide acción' : 'no pide acción'),
       $pide, estado_pago_pinta($clave)['accion']);
}
es('un estado que nadie conoce no inventa texto', '', estado_pago_pinta('marciano')['texto']);
ok('ni pide una acción que no existe', !estado_pago_pinta('marciano')['accion']);

grupo('Parche 2j · el recargo de la pasarela sale de la lista, no del código');

/* «El pago en POS tiene un cargo del 4% del monto total pagado» (usuario,
   2026-09-11). El 4% vive en la LISTA —400 centésimas— para que el día que el
   banco baje al 3.5% se escriba 350 en Configuración y nadie toque código. */
es('el POS trae su 4% de semilla', 400, metodo_recargo_centesimas($POS));
es('y el efectivo no cobra nada',    0,   metodo_recargo_centesimas($EFEC));
es('ni un método que no existe',     0,   metodo_recargo_centesimas(999999));
es('ni ninguno',                     0,   metodo_recargo_centesimas(null));

es('el 4% de S/ 500.00 son S/ 20.00', 2000, recargo_de_pago(50000, $POS));
es('el 4% de S/ 1.00 es S/ 0.04',        4, recargo_de_pago(100, $POS));
/* Se trunca, no se redondea hacia arriba: la comisión es EDITABLE y de las dos
   formas de equivocarse, pasarse es la que le cuesta dinero a Waka. */
es('y de S/ 0.10 son 0 céntimos, no 1',  0, recargo_de_pago(10, $POS));
es('en efectivo no hay recargo',         0, recargo_de_pago(50000, $EFEC));
es('y un pago de cero no genera nada',   0, recargo_de_pago(0, $POS));

ok('el efectivo se reconoce como efectivo', metodo_es_efectivo($EFEC));
ok('y el POS no lo es',                    !metodo_es_efectivo($POS));
ok('ni un item de otra lista',             !metodo_es_efectivo($CANAL));
ok('ni ninguno',                           !metodo_es_efectivo(null));

grupo('Parche 2j · el voucher denegado NO abre la puerta del despacho');

/* EL AGUJERO MÁS CARO DE TODO EL MÓDULO: denegar un pago lo pone anulado = 1,
   así que el pedido cuyo ÚNICO pago se denegó se quedaba SIN PAGOS VIVOS y
   pasaba por una venta 100% contraentrega. El HUB lo mandaba a «Listas para
   despacho», le ponía la estrella y armaba el mensaje diciendo «lo que ya pagó
   el cliente está confirmado». Con el voucher falso encima de la mesa, y 48
   horas después anulaba el pedido con la mercadería ya fuera. */
banco_entrar($GLOBALS['ADMIN']);
$sale = function (int $id): bool {
    $p = pedido_de($id);
    $sql = (int) valor('SELECT COUNT(*) FROM pedidos pe WHERE pe.id = ? AND '
                       . sql_despacho_listo(), [$id]);
    $prosa = pedido_despacho_bloqueo($p) === '';
    /* Las dos definiciones tienen que decir lo MISMO siempre: si discrepan,
       esta prueba falla aunque la respuesta sea la que se esperaba. */
    ok('el SQL y la prosa coinciden en el pedido ' . $id, ($sql === 1) === $prosa,
       'sql=' . $sql . ' prosa=' . var_export($prosa, true));
    /* Se devuelve lo que dice el SQL, que es lo que gobierna las listas y el
       menú; la prosa queda cubierta por la comprobación de arriba. Así una
       mutación en cualquiera de las dos rompe algo. */
    return $sql === 1;
};

$P_DEN = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                       'equipo_id'=>$EQUIPO,'tipo'=>'inmediata','monto'=>37900,
                       'canal_item_id'=>$CANAL]);
$pg_den = pago_registrar($P_DEN, ['monto_centimos'=>37900,'metodo_item_id'=>$YAPE,
                                  'fecha'=>$HOY,'voucher'=>$VOU]);
ok('con el pago sin revisar NO sale', !$sale($P_DEN));
pago_denegar((int)$pg_den['id'], 'El voucher no aparece en el banco');
ok('y DENEGADO tampoco sale', !$sale($P_DEN),
   'la mercadería saldría con el voucher falso encima de la mesa');
ok('y se dice por qué, sin inventar', str_contains(
   pedido_despacho_bloqueo(pedido_de($P_DEN)), 'no entró dinero'));

/* Lo mismo si el asesor quita su propio pago: es la misma puerta. */
$P_QUI = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                       'equipo_id'=>$EQUIPO,'tipo'=>'inmediata','monto'=>20000,
                       'canal_item_id'=>$CANAL]);
$pg_qui = pago_registrar($P_QUI, ['monto_centimos'=>20000,'metodo_item_id'=>$YAPE,
                                  'fecha'=>$HOY,'voucher'=>$VOU]);
pago_anular((int)$pg_qui['id'], 'Lo anoté mal');
ok('un pago quitado por su dueño tampoco abre la puerta', !$sale($P_QUI));

/* Y en cuanto entra el dinero bueno, SÍ sale: la puerta se cierra por falta de
   dinero, no por haber tenido una denegación en el historial. */
$pg_bueno = pago_registrar($P_DEN, ['monto_centimos'=>37900,'metodo_item_id'=>$YAPE,
                                    'fecha'=>$HOY,'voucher'=>$VOU]);
pago_validar((int)$pg_bueno['id'], 'OP-BUENO');
ok('con el pago bueno confirmado ya sale', $sale($P_DEN));

/* La pre venta con adelanto confirmado y saldo denegado SÍ sale: entró dinero
   de verdad, y el mensaje al grupo dice cuánto cobrar al entregar. */
$P_MIX = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                       'equipo_id'=>$EQUIPO,'tipo'=>'preventa','monto'=>100000,
                       'canal_item_id'=>$CANAL]);
$ade = pago_registrar($P_MIX, ['monto_centimos'=>10000,'metodo_item_id'=>$YAPE,
                               'fecha'=>$HOY,'voucher'=>$VOU]);
pago_validar((int)$ade['id'], 'OP-ADE');
$sal = pago_registrar($P_MIX, ['monto_centimos'=>50000,'metodo_item_id'=>$YAPE,
                               'fecha'=>$HOY,'voucher'=>$VOU]);
pago_denegar((int)$sal['id'], 'El segundo voucher no cuadra');
ok('la pre venta con adelanto confirmado sale igual', $sale($P_MIX),
   'el adelanto entró de verdad: eso no se frena');

/* Y un pedido que NUNCA tuvo un pago —los de antes del parche 2h— ya NO
   sale (parche 2s): «no puede salir sin confirmación» (usuario, 2026-09-21). */
$P_CE = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                      'equipo_id'=>$EQUIPO,'tipo'=>'inmediata','monto'=>15000,
                      'canal_item_id'=>$CANAL]);
ok('la venta sin ningún pago registrado NO sale', !$sale($P_CE));

grupo('Parche 2j · la cola de comprobantes solicitados');

/* La cola tiene tope, y un tope que corta en silencio es una cifra que miente:
   con 120 solicitudes la cabecera decía «100» y las 20 más nuevas no salían en
   ninguna pantalla. Se pide UNA FILA DE MÁS para saber que hay más, igual que
   la lista de pedidos y que el resto de esa misma bandeja. */
banco_entrar($GLOBALS['ADMIN']);
$P_SOL = [];
foreach ([1, 2, 3, 4] as $i) {
    $id = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,
                        'equipo_id'=>$EQUIPO,'tipo'=>'inmediata','monto'=>10000,
                        'canal_item_id'=>$CANAL]);
    $r = pedido_solicitar_comprobante($id, $i % 2 ? 'boleta' : 'factura');
    ok("se solicita el comprobante del pedido $i", $r['ok'], $r['error'] ?? '');
    $P_SOL[] = $id;
}
es('con tope 2 se traen TRES: la de más es el aviso de que hay más', 3,
   count(comprobantes_solicitados(yo(), 2)));
ok('y sin tope estrecho salen las cuatro',
   count(comprobantes_solicitados(yo(), 100)) >= 4);

/* Y se vacía sola: en cuanto facturación resuelve, la fila se va. Esa es la
   diferencia con el aviso viejo de «X ventas sin comprobante», que contaba
   también las que a propósito no llevan y por eso nunca bajaba a cero. */
$antes_cola = count(comprobantes_solicitados(yo(), 100));
pedido_comprobante($P_SOL[0], 'ninguno');
es('marcada como que no lleva, sale de la cola', $antes_cola - 1,
   count(comprobantes_solicitados(yo(), 100)));

/* Un pedido ANULADO tampoco pide nada: no hay comprobante que emitir. */
$r_anu = pedido_solicitar_comprobante($P_SOL[1], 'boleta');
ok('la solicitud del pedido vivo entra', $r_anu['ok'] || !empty($r_anu['sin_cambio']));
pedido_anular($P_SOL[1], (int) valor("SELECT i.id FROM lista_items i JOIN listas l
                                        ON l.id=i.lista_id WHERE l.clave='motivos_anulacion' LIMIT 1"));
$en_cola = array_column(comprobantes_solicitados(yo(), 100), 'id');
ok('y al anular el pedido su solicitud sale de la cola',
   !in_array($P_SOL[1], array_map('intval', $en_cola), true),
   'facturación seguiría viendo la boleta de una venta que ya no existe');

grupo('Parche 2j · lo que el asesor INDICA no es lo que se emite');

es('boleta',      'Boleta',       comprobante_pide_texto('boleta'));
es('factura',     'Factura',      comprobante_pide_texto('factura'));
/* «No necesita» dejó de ser una opción el 2026-09-12: no pedir nada ES no
   necesitar. Si vuelve a existir, esto salta. */
es('«ninguno» ya no es una opción que se pida', '', comprobante_pide_texto('ninguno'));
es('vacío',       '',             comprobante_pide_texto(''));
es('nulo',        '',             comprobante_pide_texto(null));
es('y algo que nadie conoce no se inventa', '', comprobante_pide_texto('marciano'));
es('lo que se puede solicitar son dos cosas', 2, count(comprobantes_que_se_solicitan()));
es('boleta y factura, y nada más', 'boleta,factura',
   implode(',', array_keys(comprobantes_que_se_solicitan())));

grupo('Parche 2k · el reclamo se defiende solo');

/* TODA LA SEGURIDAD DEL RECLAMO VIVÍA EN TRES LÍNEAS DEL CONTROLADOR.
   pedido_reclamar() no comprobaba nada por su cuenta: bastaba un segundo
   llamador —o comentar una línea— para que cualquiera reclamara la venta de
   otro asesor, o de otro país. Las escrituras de este proyecto se defienden
   solas, como pedido_despacho_marcar(), que reconfirma el bloqueo DENTRO del
   candado (auditoría, 2026-09-14). */
banco_entrar($ASESOR);
$P_REC = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLIENTE,'asesor_id'=>$ASESOR,
                       'equipo_id'=>$EQUIPO,'monto'=>50000,'canal_item_id'=>$CANAL]);
$r_pg = pago_registrar($P_REC, ['monto_centimos'=>50000, 'metodo_item_id'=>$YAPE,
                                'fecha'=>$HOY, 'voucher'=>$VOU]);
ok('la venta tiene su pago esperando confirmación', $r_pg['ok'], $r_pg['error'] ?? '');
q('UPDATE pagos SET creado_en = ? WHERE pedido_id = ?',
  [date('Y-m-d H:i:s', strtotime('-60 minutes')), $P_REC]);

/* La única definición de por qué no sale, con nombre. */
es('el motivo se llama por su nombre', 'sin_revisar',
   pedido_despacho_motivo(pedido_de($P_REC))['clave']);
es('y la frase de siempre sale de ahí', pedido_despacho_motivo(pedido_de($P_REC))['texto'],
   pedido_despacho_bloqueo(pedido_de($P_REC)));

/* OTRO ASESOR, llamando a la función directamente. */
banco_entrar($OTRO);
$r_otro = pedido_reclamar($P_REC);
ok('otro asesor no reclama la venta ajena ni llamando por dentro', !$r_otro['ok']);
es('y no queda anotado', 0,
   (int) valor('SELECT reclamo_veces FROM pedidos WHERE id = ?', [$P_REC]));

/* EL LÍDER: ámbito equipo, que es de solo lectura en todo el HUB. */
banco_entrar($LIDER);
$r_lid = pedido_reclamar($P_REC);
ok('el que solo mira tampoco reclama', !$r_lid['ok']);
es('y sigue sin anotarse', 0,
   (int) valor('SELECT reclamo_veces FROM pedidos WHERE id = ?', [$P_REC]));

/* Y SU DUEÑO SÍ. */
banco_entrar($ASESOR);
$r_yo = pedido_reclamar($P_REC);
ok('su dueño sí reclama', $r_yo['ok'], $r_yo['error']);
es('y queda anotado una vez', 1,
   (int) valor('SELECT reclamo_veces FROM pedidos WHERE id = ?', [$P_REC]));
es('con quién: el que pulsó', $ASESOR,
   (int) valor('SELECT reclamo_por FROM pedidos WHERE id = ?', [$P_REC]));

/* UNA VENTA ANULADA NO SE RECLAMA, aunque le quede un cobro colgando. El
   camino normal apaga los pagos al anular, así que sin reabrirlo a mano esta
   guarda no se prueba: sale por otra puerta. */
$P_ANU = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLIENTE,'asesor_id'=>$ASESOR,
                       'equipo_id'=>$EQUIPO,'monto'=>50000,'canal_item_id'=>$CANAL]);
pago_registrar($P_ANU, ['monto_centimos'=>50000, 'metodo_item_id'=>$YAPE,
                        'fecha'=>$HOY, 'voucher'=>$VOU]);
q('UPDATE pagos SET creado_en = ? WHERE pedido_id = ?',
  [date('Y-m-d H:i:s', strtotime('-60 minutes')), $P_ANU]);
pedido_anular($P_ANU, (int) valor("SELECT i.id FROM lista_items i JOIN listas l
                                     ON l.id=i.lista_id WHERE l.clave='motivos_anulacion' LIMIT 1"));
q('UPDATE pagos SET anulado = 0, verificado = 0 WHERE pedido_id = ?', [$P_ANU]);
es('una venta anulada se dice anulada', 'anulado',
   pedido_despacho_motivo(pedido_de($P_ANU))['clave']);
ok('y no se reclama', !pedido_reclamar($P_ANU)['ok']);
es('sin anotar nada', 0,
   (int) valor('SELECT reclamo_veces FROM pedidos WHERE id = ?', [$P_ANU]));

/* ══════════════  PARCHE 2R · LO QUE PIDIÓ EL USUARIO EL 2026-09-20  ══════════════ */
grupo('Parche 2r · buscar distritos como se habla');

/* «Que se pueda buscar por abreviaciones, tipo SJL, y que salgan similares
   cuando se escriba algo incorrecto» (usuario, 2026-09-20). */
$por = fn(string $t) => array_map(fn($f) => (string)$f['distrito'],
                                  ubigeo_buscar_distritos($PAIS, $t, 5));
ok('«sjl» encuentra San Juan de Lurigancho',
   in_array('San Juan de Lurigancho', $por('sjl'), true), implode(' · ', $por('sjl')));
ok('y «S.J.L.», con sus puntos, también',
   in_array('San Juan de Lurigancho', $por('S.J.L.'), true), implode(' · ', $por('S.J.L.')));
ok('«smp» encuentra San Martín de Porres',
   in_array('San Martín de Porres', $por('smp'), true), implode(' · ', $por('smp')));
ok('«vmt» encuentra Villa María del Triunfo',
   in_array('Villa María del Triunfo', $por('vmt'), true), implode(' · ', $por('vmt')));
/* Y una abreviatura que no lo es sigue buscándose tal cual: «smpx» no es nada
   y no puede devolver San Martín de Porres. */
es('«smpx» no encuentra nada', [], $por('smpx'));
/* Y UNA ABREVIATURA NO PUEDE ESTROPEAR UNA BÚSQUEDA QUE YA FUNCIONABA. Con
   «cercado» suelto, «cercado de lima» se convertía en «lima de lima» y el
   distrito Lima —que no lleva «de»— se quedaba fuera: salía Limabamba, en
   Amazonas (auditoría del 2r). */
ok('«cercado de lima» encuentra Lima', in_array('Lima', $por('cercado de lima'), true),
   implode(' · ', $por('cercado de lima')));
ok('y «vitarte» sigue encontrando Ate por su capital',
   in_array('Ate', $por('vitarte'), true), implode(' · ', $por('vitarte')));

grupo('Parche 2r · lo que se parece a lo que se escribió');

$parecidos = fn(string $t) => array_map(fn($f) => (string)$f['distrito'],
                                        ubigeo_buscar_parecidos($PAIS, $t, 8));
ok('«lurigacho» ofrece San Juan de Lurigancho',
   in_array('San Juan de Lurigancho', $parecidos('lurigacho'), true),
   implode(' · ', $parecidos('lurigacho')));
ok('«mirafores» ofrece Miraflores',
   in_array('Miraflores', $parecidos('mirafores'), true), implode(' · ', $parecidos('mirafores')));
/* Y no es un colador: dos letras no se parecen a nada, y una palabra que no
   se parece a ningún distrito no puede inventar uno. */
es('con menos de tres letras no se propone nada', [], $parecidos('li'));
es('y con algo que no se parece a nada, tampoco', [],
   $parecidos('zzzzqqqqxxxx'));
/* LO IMPORTANTE: los parecidos son el plan B. Mientras la búsqueda normal
   devuelva algo, manda ella —el endpoint solo llama a esta función cuando la
   otra vuelve vacía—. */
ok('una búsqueda buena sigue devolviendo lo suyo',
   in_array('Miraflores', $por('miraflores'), true));

grupo('Parche 2r · el mensaje de despacho a provincia');

$CLI_PR = nuevo_cliente($PAIS, $ASESOR, '10000305', 'provincia2r@waka.test');
$P_PROV = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_PR,'asesor_id'=>$ASESOR,
                        'equipo_id'=>$EQUIPO,'monto'=>60000,'canal_item_id'=>$CANAL,
                        'entrega'=>'envio','ubigeo_id'=>$WANCHAQ,'recojo_agencia'=>1]);
es('a provincia toca la plantilla de provincia', 'despacho_provincia',
   plantilla_de_despacho(pedido_de($P_PROV)));
$P_LIM = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_PR,'asesor_id'=>$ASESOR,
                       'equipo_id'=>$EQUIPO,'monto'=>60000,'canal_item_id'=>$CANAL,
                       'entrega'=>'envio','ubigeo_id'=>$SJL,'direccion_txt'=>'Av. Próceres 500']);
es('y en Lima, la de Lima', 'despacho',
   plantilla_de_despacho(pedido_de($P_LIM)));

$msg_pr = mensaje_de_pedido($P_PROV, plantilla_de_despacho(pedido_de($P_PROV)));
ok('el destino va en UNA línea: distrito - provincia - departamento',
   str_contains($msg_pr, 'Wanchaq - Cusco - Cusco'), $msg_pr);
ok('no lleva mapa',            !str_contains($msg_pr, 'Mapa:'), $msg_pr);
ok('no lleva ningún precio',   !str_contains($msg_pr, 'S/'),    $msg_pr);
ok('ni pide cobrar nada',      !str_contains(mb_strtoupper($msg_pr), 'COBRAR'), $msg_pr);
ok('pero sí el número de contacto', str_contains($msg_pr, 'Número de contacto:'), $msg_pr);

grupo('Parche 2r · el rótulo de la caja');

$pdf = rotulo_de_pedido($P_PROV);
ok('sale un PDF',                str_starts_with((string)$pdf, '%PDF-'));
ok('y se cierra bien',           str_ends_with(trim((string)$pdf), '%%EOF'));
ok('lleva el código del pedido', str_contains((string)$pdf, pdf_texto((string)pedido_de($P_PROV)['codigo'])));
ok('y el destino entero',        str_contains((string)$pdf, pdf_texto('Wanchaq - Cusco - Cusco')));
/* Lo que NO lleva: el precio. Un rótulo pegado a una caja lo lee quien la
   carga, quien la recibe en la agencia y a veces el vecino del cliente.
   Se busca el importe exacto y no «S/» a secas: el logo va dentro del PDF como
   imagen, y en sus bytes cabe cualquier par de letras. */
ok('y no lleva el precio', !str_contains((string)$pdf, pdf_texto(soles(60000))),
   'el rótulo no habla de dinero');
es('un pedido que no existe no tiene rótulo', null, rotulo_de_pedido(999999));

/* El partidor de líneas es lo único con cuentas dentro: si se equivoca, el
   rótulo se sale del papel y nadie lo ve hasta que está impreso. */
es('un texto corto es una sola línea', 1, count(pdf_partir('Silla gamer', 300, 12)));
ok('uno largo se parte en varias',     count(pdf_partir(str_repeat('palabra ', 40), 300, 12)) > 1);
es('una cadena vacía no da líneas',    [], pdf_partir('   ', 300, 12));
ok('y una palabra más larga que la línea se corta',
   count(pdf_partir(str_repeat('a', 300), 100, 12)) > 1);

/* ═══════════════  PARCHE 2S · LO QUE PIDIÓ EL USUARIO EL 2026-09-21  ═══════════════ */
grupo('Parche 2s · a provincia solo sale lo que está pagado entero');

banco_entrar($ASESOR);
$CLI_2S = nuevo_cliente($PAIS, $ASESOR, '10000401', 'dosese@waka.test');
$mk2s = fn(array $x) => nuevo_pedido(array_merge(['pais_id'=>$PAIS,'cliente_id'=>$CLI_2S,
            'asesor_id'=>$ASESOR,'equipo_id'=>$EQUIPO,'monto'=>60000,'canal_item_id'=>$CANAL], $x));
$listo2s = fn(int $id) => (bool) valor('SELECT 1 FROM pedidos pe WHERE pe.id = ? AND '
                                       . sql_despacho_listo(), [$id]);

/* 1 · Provincia con la mitad cobrada y confirmada: no sale. */
$PV = $mk2s(['entrega'=>'envio','ubigeo_id'=>$WANCHAQ,'recojo_agencia'=>1]);
cobrar($PV, 30000, $YAPE, $HOY, $VOU);
es('a provincia con saldo, el motivo es el saldo', 'saldo_provincia',
   pedido_despacho_motivo(pedido_de($PV))['clave']);
ok('y dice cuánto falta', str_contains(pedido_despacho_motivo(pedido_de($PV))['texto'], 'S/ 300.00'));
ok('y el SQL dice lo mismo: no sale', !$listo2s($PV));
cobrar($PV, 30000, $YAPE, $HOY, $VOU);
es('cobrado el total, ya sale', '', pedido_despacho_motivo(pedido_de($PV))['clave']);
ok('y el SQL también', $listo2s($PV));

/* 2 · La pre venta a provincia: se separa con el adelanto y NO sale hasta
   completar el 100% (usuario: «cuando llega a Lima completa el pago al 100%
   para que se envíe por agencia»). */
$PPV = $mk2s(['tipo'=>'preventa','entrega'=>'envio','ubigeo_id'=>$WANCHAQ,'recojo_agencia'=>1]);
cobrar($PPV, 6000, $YAPE, $HOY, $VOU);
es('una pre venta a provincia con el adelanto no sale', 'saldo_provincia',
   pedido_despacho_motivo(pedido_de($PPV))['clave']);
ok('ni para el SQL', !$listo2s($PPV));

/* 3 · Lima con adelanto confirmado SÍ sale: allí existe la contraentrega, y el
   mensaje al grupo dice cuánto cobrar. */
$PL = $mk2s(['entrega'=>'envio','ubigeo_id'=>$SJL]);
cobrar($PL, 20000, $YAPE, $HOY, $VOU);
es('Lima con adelanto confirmado sale', '', pedido_despacho_motivo(pedido_de($PL))['clave']);
ok('y el SQL coincide', $listo2s($PL));
ok('y el mensaje dice cuánto cobrar al entregar',
   str_contains(mensaje_de_pedido($PL, 'despacho'), 'COBRAR S/ 400.00'),
   mensaje_de_pedido($PL, 'despacho'));

/* 4 · Un recojo en oficina no es provincia, viva donde viva el cliente. */
$PO = $mk2s([]);
cobrar($PO, 10000, $YAPE, $HOY, $VOU);
es('un recojo en oficina con saldo sale (se cobra en el local)', '',
   pedido_despacho_motivo(pedido_de($PO))['clave']);

/* 5 · Y la regla en SQL y en palabras siguen diciendo lo mismo de TODOS los
   pedidos, también de los nuevos. Es la prueba que ya pilló dos veces una
   discrepancia que mandaba mercadería con el voucher denegado. */
$por_sql2 = array_map('intval', array_column(todas(
    'SELECT pe.id FROM pedidos pe WHERE pe.anulado_en IS NULL AND ' . sql_despacho_listo()
    . ' ORDER BY pe.id'), 'id'));
$por_php2 = [];
foreach (array_column(todas('SELECT id FROM pedidos WHERE anulado_en IS NULL ORDER BY id'), 'id') as $vid) {
    $ped = pedido_de((int)$vid);
    if ($ped && pedido_despacho_bloqueo($ped) === '') $por_php2[] = (int)$vid;
}
es('la regla en SQL y en palabras coinciden en cada pedido', implode(',', $por_php2),
   implode(',', $por_sql2));

grupo('Parche 2s · el número y el concepto de cada pago');

es('1.er pago', '1.er pago', pago_ordinal(1));
es('2.º pago',  '2.º pago',  pago_ordinal(2));
es('3.er pago', '3.er pago', pago_ordinal(3));
es('4.º pago',  '4.º pago',  pago_ordinal(4));

$PN = $mk2s(['entrega'=>'envio','ubigeo_id'=>$SJL]);
cobrar($PN, 20000, $YAPE, $HOY, $VOU);
banco_entrar($ASESOR);
$seg = pago_registrar($PN, ['monto_centimos'=>30000,'metodo_item_id'=>$YAPE,'fecha'=>$HOY,
                            'voucher'=>$VOU,'concepto'=>'Saldo contraentrega']);
ok('el segundo pago se registra', $seg['ok'], $seg['error'] ?? '');
$pgs = pagos_del_pedido($PN);
es('el primero es el 1.er pago', '1.er pago', pago_rotulo($pgs[0]));
es('y el segundo lleva su número y su concepto', '2.º pago · Saldo contraentrega', pago_rotulo($pgs[1]));
/* Un pago denegado no desordena la cuenta: el siguiente sigue siendo el 3.º. */
banco_entrar($ADMIN);
pago_denegar((int)$seg['id'], 'No cuadra');
banco_entrar($ASESOR);
$ter = pago_registrar($PN, ['monto_centimos'=>30000,'metodo_item_id'=>$YAPE,'fecha'=>$HOY,
                            'voucher'=>$VOU,'concepto'=>'Saldo, voucher bueno']);
$pgs = pagos_del_pedido($PN);
es('un pago denegado no cambia el número de los demás', '3.er pago · Saldo, voucher bueno',
   pago_rotulo(end($pgs)));

grupo('Parche 2s · el saldo que nadie registró');

/* Una venta a Lima, con adelanto confirmado, mandada a despacho: el
   repartidor cobra el resto y el asesor tiene que subir ese voucher. */
$PS2 = $mk2s(['entrega'=>'envio','ubigeo_id'=>$SJL]);
cobrar($PS2, 20000, $YAPE, $HOY, $VOU);
banco_entrar($ASESOR);
pedido_despacho_marcar($PS2);
/* El usuario como lo ve yo(), sin quedarse con su sesión puesta. */
$usuario_de = function (int $id): array {
    $antes = $_SESSION['usuario_id'] ?? null;
    banco_entrar($id);
    $u = yo() ?? [];
    if ($antes) banco_entrar((int)$antes);
    return $u;
};
$ids_saldo = fn(int $dias, bool $suyas) => array_map('intval',
    array_column(pedidos_saldo_pendiente($usuario_de($suyas ? $ASESOR : $ADMIN), $dias, 100, $suyas), 'id'));

ok('el mismo día del despacho todavía no se avisa', !in_array($PS2, $ids_saldo(1, true), true));
q('UPDATE pedidos SET despacho_en = ? WHERE id = ?',
  [date('Y-m-d H:i:s', strtotime('-1 day')), $PS2]);
ok('al día siguiente, el asesor lo tiene en su aviso', in_array($PS2, $ids_saldo(1, true), true));
ok('pero Administración todavía no', !in_array($PS2, $ids_saldo(3, false), true));
q('UPDATE pedidos SET despacho_en = ? WHERE id = ?',
  [date('Y-m-d H:i:s', strtotime('-3 days')), $PS2]);
ok('a los tres días lo ve también Administración', in_array($PS2, $ids_saldo(3, false), true));
$fila_s = array_values(array_filter(pedidos_saldo_pendiente($usuario_de($ASESOR), 1, 100, true),
                                    fn($x) => (int)$x['id'] === $PS2))[0] ?? [];
es('y dice cuánto falta', 40000, (int)($fila_s['falta'] ?? 0));

/* En cuanto el asesor sube el voucher, deja de estar «por registrar»: ya hizo
   su parte, aunque facturación todavía no lo haya confirmado. */
pago_registrar($PS2, ['monto_centimos'=>40000,'metodo_item_id'=>$YAPE,'fecha'=>$HOY,
                      'voucher'=>$VOU,'concepto'=>'Saldo contraentrega']);
ok('registrado el saldo, sale del aviso', !in_array($PS2, $ids_saldo(1, true), true));
ok('y de la bandeja de Administración', !in_array($PS2, $ids_saldo(3, false), true));

/* El aviso del asesor es SUYO: el de otro asesor no le sale. */
$OTRO_2S = banco_usuario('asesor', ['equipo_id' => $EQUIPO]);
$PX = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_2S,'asesor_id'=>$OTRO_2S,'equipo_id'=>$EQUIPO,
                    'monto'=>60000,'canal_item_id'=>$CANAL,'entrega'=>'envio','ubigeo_id'=>$SJL]);
q('UPDATE pedidos SET despacho_veces = 1, despacho_en = ? WHERE id = ?',
  [date('Y-m-d H:i:s', strtotime('-2 days')), $PX]);
ok('una venta de otro asesor no sale en mi aviso', !in_array($PX, $ids_saldo(1, true), true));
/* Y TAMPOCO EN EL DE UN LÍDER: su ámbito le deja VER las ventas del equipo,
   pero el aviso dice «tienes…» y habla de lo suyo. Con el asesor normal la
   prueba de arriba pasa por el ámbito solo; esta es la que mide el filtro. */
$LIDER_2S = banco_usuario('asesor', ['ambito' => 'equipo', 'equipo_id' => $EQUIPO]);
es('el otro asesor es del mismo equipo que el líder', (int)$EQUIPO,
   (int) valor('SELECT equipo_id FROM usuarios WHERE id = ?', [$OTRO_2S]));
$del_lider = array_map('intval', array_column(
    pedidos_saldo_pendiente($usuario_de($LIDER_2S), 1, 100, true), 'id'));
ok('el aviso del líder no trae las ventas de su equipo', !in_array($PX, $del_lider, true));
$lo_que_ve = array_map('intval', array_column(
    pedidos_saldo_pendiente($usuario_de($LIDER_2S), 1, 100, false), 'id'));
ok('aunque las pueda ver', in_array($PX, $lo_que_ve, true), 'el ámbito de equipo no las trae');

/* EL CONTEO ES EL DE VERDAD, no el de la lista: la lista lleva tope y el
   primer día del parche aparecen todos los saldos viejos de golpe. */
es('el número cuenta todos, no los que caben en la lista',
   count(pedidos_saldo_pendiente($usuario_de($ADMIN), 1, 1000)),
   pedidos_saldo_pendiente_n($usuario_de($ADMIN), 1));
ok('aunque la lista corte', count(pedidos_saldo_pendiente($usuario_de($ADMIN), 1, 1)) <= 1);

/* EL PLAZO CUENTA DESDE LA ENTREGA SI ES POSTERIOR: una venta mandada hoy para
   entregar el viernes no se ha cobrado mañana. */
$PF = $mk2s(['entrega'=>'envio','ubigeo_id'=>$SJL]);
cobrar($PF, 20000, $YAPE, $HOY, $VOU);
q('UPDATE pedidos SET despacho_veces = 1, despacho_en = ?, entrega_fecha = ? WHERE id = ?',
  [date('Y-m-d H:i:s', strtotime('-2 days')), date('Y-m-d', strtotime('+2 days')), $PF]);
ok('con la entrega todavía por delante, no se avisa', !in_array($PF, $ids_saldo(1, true), true));
q('UPDATE pedidos SET entrega_fecha = ? WHERE id = ?', [date('Y-m-d', strtotime('-1 day')), $PF]);
ok('pasado el día de entrega, sí', in_array($PF, $ids_saldo(1, true), true));

/* UNA DEVOLUCIÓN SACA LA VENTA DEL AVISO: la maneja Administración a mano, y
   el asesor no tiene ningún cobro que registrar. */
$PD2 = $mk2s(['entrega'=>'envio','ubigeo_id'=>$SJL]);
$c1 = cobrar($PD2, 30000, $YAPE, $HOY, $VOU);
$c2 = cobrar($PD2, 30000, $YAPE, $HOY, $VOU);
q('UPDATE pedidos SET despacho_veces = 1, despacho_en = ? WHERE id = ?',
  [date('Y-m-d H:i:s', strtotime('-4 days')), $PD2]);
ok('cobrada entera, no está en el aviso', !in_array($PD2, $ids_saldo(1, false), true));
banco_entrar($ADMIN);
$dev2 = pago_devolver((int)$c2['id'], 'Devolvió un artículo', 'cliente');
banco_entrar($ASESOR);
ok('se devuelve uno de los pagos', $dev2['ok'] ?? false, $dev2['error'] ?? '');
ok('y no aparece a deber en ningún aviso', !in_array($PD2, $ids_saldo(1, false), true));

/* Y EL MENSAJE DE UN ENVÍO SIN DISTRITO es el de Lima, que habla de cobrar:
   la regla de despacho lo trata como Lima, y el mensaje tiene que decir lo
   mismo que la regla. */
$PSD = $mk2s(['entrega'=>'envio']);
es('un envío sin distrito lleva el mensaje que habla de cobrar', 'despacho',
   plantilla_de_despacho(pedido_de($PSD)));
ok('pero sí en la bandeja de Administración', in_array($PX, $ids_saldo(1, false), true));

/* ═══════════════  PARCHE 2U · NUBEFACT  ═══════════════ */
grupo('Parche 2u · las cuentas del comprobante');

/* Una silla de S/ 600: base 508.47 + IGV 91.53, al céntimo. */
$m1 = nubefact_items([['descripcion'=>'Silla Gamer','cantidad'=>1,'precio_unit_centimos'=>60000,
                       'total_centimos'=>60000]], 0, 0, 18);
es('una línea de S/ 600: base', '508.47', $m1['items'][0]['subtotal']);
es('su IGV', '91.53', $m1['items'][0]['igv']);
es('y el total es el de la venta', 60000, $m1['total']);
es('base + IGV = total, sin un céntimo de más', $m1['total'], $m1['total_gravada'] + $m1['total_igv']);

/* Con S/ 20 de cashback: el comprobante dice S/ 580, con el descuento aparte. */
$m2 = nubefact_items([['descripcion'=>'Silla Gamer','cantidad'=>1,'precio_unit_centimos'=>60000,
                       'total_centimos'=>60000]], 0, 2000, 18);
es('con cashback, el total es lo que pagó', 58000, $m2['total']);
es('el descuento global va sin IGV', 1694, $m2['descuento_global']);
es('y base + IGV sigue siendo el total', $m2['total'], $m2['total_gravada'] + $m2['total_igv']);

/* Varias unidades, flete dentro y precios que no dividen exacto. */
$m3 = nubefact_items([['descripcion'=>'Silla','cantidad'=>3,'precio_unit_centimos'=>33333,'total_centimos'=>99999],
                      ['descripcion'=>'Cojín','cantidad'=>2,'precio_unit_centimos'=>1999,'total_centimos'=>3998,
                       'modelo'=>'rojo']], 2500, 0, 18);
es('con el flete, una línea más de servicio', 3, count($m3['items']));
es('el envío va como servicio', 'ZZ', $m3['items'][2]['unidad_de_medida']);
es('y el modelo viaja en la descripción', 'Cojín - rojo', $m3['items'][1]['descripcion']);
es('el total suma todo', 99999 + 3998 + 2500, $m3['total']);
es('base + IGV = total también aquí', $m3['total'], $m3['total_gravada'] + $m3['total_igv']);
ok('el valor unitario × cantidad da la base de la línea',
   abs((float)$m3['items'][0]['valor_unitario'] * 3 - (float)$m3['items'][0]['subtotal']) < 0.01,
   $m3['items'][0]['valor_unitario'] . ' × 3 vs ' . $m3['items'][0]['subtotal']);

es('DNI es el 1 de SUNAT', '1', nubefact_tipo_doc('DNI'));
es('RUC es el 6', '6', nubefact_tipo_doc('RUC'));
es('carné de extranjería es el 4', '4', nubefact_tipo_doc('CE'));
ok('una serie de boleta empieza por B y tiene 4', nubefact_serie_valida('B001', 'B') && !nubefact_serie_valida('F001', 'B')
   && !nubefact_serie_valida('B01', 'B'));
es('el token se enseña tapado', '•••• cdef', nubefact_token_tapado('1234567890abcdef'));

grupo('Parche 2u · emitir con NUBEFACT');

/* NUBEFACT de mentira: guarda lo que se le manda y contesta lo que le digamos. */
$NF = ['enviados' => [], 'respuestas' => []];
$GLOBALS['__nubefact_transporte'] = function (string $ruta, string $token, array $datos, string $auth) use (&$NF) {
    $NF['enviados'][] = ['datos' => $datos, 'auth' => $auth, 'token' => $token];
    $r = array_shift($NF['respuestas']);
    if ($r === null) {
        $r = ['http' => 200, 'red' => '', 'cuerpo' => [
            'tipo_de_comprobante' => $datos['tipo_de_comprobante'] ?? 2, 'serie' => $datos['serie'] ?? '',
            'numero' => $datos['numero'] ?? 0, 'aceptada_por_sunat' => true,
            'enlace_del_pdf' => 'https://nubefact.test/pdf/' . ($datos['serie'] ?? '') . '-' . ($datos['numero'] ?? 0)]];
    }
    return $r;
};

ok('sin configurar, no se emite desde aquí', !nubefact_activo());
guardar_ajuste('nubefact_prueba_ruta', 'https://api.nubefact.test/api/v1/abc');
guardar_ajuste('nubefact_prueba_token', 'token-de-prueba-0123456789abcdef');
guardar_ajuste('nubefact_prueba_serie_boleta', 'BBB1');
guardar_ajuste('nubefact_prueba_serie_factura', 'FFF1');
guardar_ajuste('nubefact_prueba_serie_nc_boleta', 'BBB1');
guardar_ajuste('nubefact_prueba_serie_nc_factura', 'FFF1');
ajustes_olvidar();
ok('con ruta, token y series, sí', nubefact_activo());

banco_entrar($ASESOR);
$CLI_NF = nuevo_cliente($PAIS, $ASESOR, '44556677', 'nubefact@waka.test');
$PNF = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_NF,'asesor_id'=>$ASESOR,'equipo_id'=>$EQUIPO,
                     'monto'=>60000,'canal_item_id'=>$CANAL]);
banco_entrar($FACTU);
$sin_pago = nubefact_emitir($PNF, 'boleta');
ok('sin un pago confirmado no se emite', !$sin_pago['ok'], 'emitió');
es('y no se llamó a NUBEFACT', 0, count($NF['enviados']));

banco_entrar($ASESOR);
cobrar($PNF, 30000, $YAPE, $HOY, $VOU);   // solo un adelanto
banco_entrar($FACTU);
/* LA REGLA DEL 2W (usuario, 2026-09-22): el comprobante se emite con el TOTAL
   cobrado. La excepción —contraentrega en Lima— se prueba más abajo. */
$medio = nubefact_emitir($PNF, 'boleta');
ok('con solo un adelanto no se emite', !$medio['ok'], 'emitió con S/300 de S/600');
ok('y dice cuánto falta cobrar', str_contains((string)$medio['error'], 'Falta cobrar'), $medio['error'] ?? '');
es('sin llamar a NUBEFACT', 0, count($NF['enviados']));

banco_entrar($ASESOR);
cobrar($PNF, 30000, $YAPE, $HOY, $VOU);   // y el resto
banco_entrar($FACTU);
$e1 = nubefact_emitir($PNF, 'boleta');
ok('con el total cobrado, la boleta sale', $e1['ok'], $e1['error'] ?? '');
$env = $NF['enviados'][0]['datos'] ?? [];
es('se pidió una boleta', 2, $env['tipo_de_comprobante'] ?? null);
es('en la serie configurada', 'BBB1', $env['serie'] ?? null);
es('con el número 1', 1, $env['numero'] ?? null);
es('al DNI del cliente', '1', $env['cliente_tipo_de_documento'] ?? null);
es('por el total de la venta', '600.00', $env['total'] ?? null);
ok('y se le manda al correo del cliente', ($env['enviar_automaticamente_al_cliente'] ?? false) === true);
es('en A4', 'A4', $env['formato_de_pdf'] ?? null);
$pnf = pedido_de($PNF);
es('el pedido queda con su boleta', 'boleta', (string)$pnf['comprobante_tipo']);
es('con su serie', 'BBB1', (string)$pnf['comprobante_serie']);
es('y su número', '1', (string)$pnf['comprobante_numero']);
$vig = nubefact_vigente($PNF);
ok('y hay una boleta vigente', (bool)$vig);
es('con el enlace de su PDF', 'https://nubefact.test/pdf/BBB1-1', (string)($vig['enlace_pdf'] ?? ''));
es('el siguiente número de la serie es el 2', 2,
   (int) valor("SELECT siguiente FROM nubefact_correlativos WHERE ambiente='prueba' AND tipo_cpe=2 AND serie='BBB1'"));

$e1b = nubefact_emitir($PNF, 'boleta');
ok('emitirla otra vez no se puede', !$e1b['ok']);
es('y no se volvió a llamar', 1, count($NF['enviados']));
$man = pedido_comprobante($PNF, 'factura', 'F001', '9');
ok('ni anotarle una a mano encima', !$man['ok']);

grupo('Parche 2w · contraentrega: la boleta sale al mandar a despacho');

/* Lima, con adelanto y saldo contraentrega: mientras no salga a despacho no se
   emite; en cuanto sale, sí — el cliente necesita su boleta con la caja. */
banco_entrar($ASESOR);
$P_CE = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_NF,'asesor_id'=>$ASESOR,'equipo_id'=>$EQUIPO,
                      'monto'=>50000,'canal_item_id'=>$CANAL,
                      'entrega'=>'envio','ubigeo_id'=>$SJL,'direccion_txt'=>'Av. Próceres 500']);
cobrar($P_CE, 20000, $YAPE, $HOY, $VOU);
banco_entrar($FACTU);
ok('antes de despacharla no se emite', nubefact_emitir($P_CE, 'boleta')['ok'] === false);
q('UPDATE pedidos SET despacho_veces = 1 WHERE id = ?', [$P_CE]);
$ce = nubefact_emitir($P_CE, 'boleta');
ok('ya mandada a despacho, sí', $ce['ok'], $ce['error'] ?? '');
es('y el comprobante va por el TOTAL de la venta, no por el adelanto', '500.00',
   end($NF['enviados'])['datos']['total']);

/* A provincia no hay contraentrega: ni despachada se emite con saldo. */
banco_entrar($ASESOR);
$P_PV = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_NF,'asesor_id'=>$ASESOR,'equipo_id'=>$EQUIPO,
                      'monto'=>50000,'canal_item_id'=>$CANAL,
                      'entrega'=>'envio','ubigeo_id'=>$WANCHAQ,'recojo_agencia'=>1]);
cobrar($P_PV, 20000, $YAPE, $HOY, $VOU);
q('UPDATE pedidos SET despacho_veces = 1 WHERE id = ?', [$P_PV]);
banco_entrar($FACTU);
$pv = nubefact_emitir($P_PV, 'boleta');
ok('a provincia con saldo no se emite', !$pv['ok']);
ok('y lo dice con la cifra que falta', str_contains((string)$pv['error'], 'Falta cobrar'), $pv['error'] ?? '');

grupo('Parche 2u · la factura necesita RUC y dirección fiscal');

banco_entrar($ASESOR);
$CLI_F = nuevo_cliente($PAIS, $ASESOR, '44556688', 'empresa@waka.test');
$PF2 = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_F,'asesor_id'=>$ASESOR,'equipo_id'=>$EQUIPO,
                     'monto'=>100000,'canal_item_id'=>$CANAL]);
cobrar($PF2, 100000, $YAPE, $HOY, $VOU);
banco_entrar($FACTU);
$nf_antes = count($NF['enviados']);
ok('sin RUC, la factura no sale', !nubefact_emitir($PF2, 'factura')['ok']);
q("UPDATE clientes SET ruc_factura = '20100070970', razon_social = 'Empresa de Prueba SAC' WHERE id = ?", [$CLI_F]);
$sin_dir = nubefact_emitir($PF2, 'factura');
ok('con RUC pero sin dirección fiscal, tampoco', !$sin_dir['ok']);
ok('y dice qué falta', str_contains((string)$sin_dir['error'], 'dirección fiscal'), $sin_dir['error'] ?? '');
es('sin gastar ningún número ni llamar a NUBEFACT', $nf_antes, count($NF['enviados']));
q("UPDATE clientes SET direccion_fiscal = 'Av. Siempre Viva 123, Lima' WHERE id = ?", [$CLI_F]);
$ef = nubefact_emitir($PF2, 'factura');
ok('con todo, la factura sale', $ef['ok'], $ef['error'] ?? '');
$envf = end($NF['enviados'])['datos'];
es('como factura', 1, $envf['tipo_de_comprobante']);
es('al RUC', '6', $envf['cliente_tipo_de_documento']);
es('con la razón social', 'Empresa de Prueba SAC', $envf['cliente_denominacion']);
es('en su serie', 'FFF1', $envf['serie']);
es('que empieza en 1: cada tipo lleva su propia numeración', 1, $envf['numero']);

grupo('Parche 2u · el cashback va como descuento');

banco_entrar($ASESOR);
$PCB = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_NF,'asesor_id'=>$ASESOR,'equipo_id'=>$EQUIPO,
                     'monto'=>60000,'canal_item_id'=>$CANAL]);
q('UPDATE pedidos SET cashback_usado_centimos = 2000 WHERE id = ?', [$PCB]);
pedido_recalcular($PCB);
cobrar($PCB, 58000, $YAPE, $HOY, $VOU);
banco_entrar($FACTU);
$ecb = nubefact_emitir($PCB, 'boleta');
ok('la boleta con cashback sale', $ecb['ok'], $ecb['error'] ?? '');
$envc = end($NF['enviados'])['datos'];
es('por lo que pagó el cliente', '580.00', $envc['total']);
es('con el cashback como descuento, sin IGV', '16.94', $envc['descuento_global']);
ok('es una boleta más de la misma serie', (int)$envc['numero'] > 1, (string)$envc['numero']);

grupo('Parche 2u · si NUBEFACT dice que no, o no contesta');

banco_entrar($ASESOR);
$PR = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_NF,'asesor_id'=>$ASESOR,'equipo_id'=>$EQUIPO,
                    'monto'=>40000,'canal_item_id'=>$CANAL]);
cobrar($PR, 40000, $YAPE, $HOY, $VOU);
banco_entrar($FACTU);

/* 1 · La rechaza: el número queda libre y el siguiente lo reusa. */
$NF['respuestas'][] = ['http' => 400, 'red' => '', 'cuerpo' => ['errors' => 'El campo cliente_denominacion no es válido', 'codigo' => 20]];
$rz = nubefact_emitir($PR, 'boleta');
ok('rechazada, no se da por emitida', !$rz['ok']);
ok('y se dice por qué', str_contains((string)$rz['error'], 'cliente_denominacion'), $rz['error'] ?? '');
ok('la venta sigue sin comprobante', trim((string)pedido_de($PR)['comprobante_tipo']) === '');
$num_rechazado = (int) end($NF['enviados'])['datos']['numero'];
$ok2 = nubefact_emitir($PR, 'boleta');
ok('al reintentar sale', $ok2['ok'], $ok2['error'] ?? '');
es('con el MISMO número que se rechazó: no quedan huecos', $num_rechazado,
   (int) end($NF['enviados'])['datos']['numero']);

/* 2 · No contesta: no se sabe si allá existe. El reintento manda el mismo
   número; NUBEFACT dice que ya existe (23) y se adopta con una consulta. */
banco_entrar($ASESOR);
$PI = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_NF,'asesor_id'=>$ASESOR,'equipo_id'=>$EQUIPO,
                    'monto'=>30000,'canal_item_id'=>$CANAL]);
cobrar($PI, 30000, $YAPE, $HOY, $VOU);
banco_entrar($FACTU);
$NF['respuestas'][] = ['http' => 0, 'red' => 'Operation timed out', 'cuerpo' => null];
$ri = nubefact_emitir($PI, 'boleta');
ok('sin respuesta, no se da por emitida', !$ri['ok']);
ok('y se avisa que se puede reintentar sin duplicar', str_contains((string)$ri['error'], 'no se duplica'),
   $ri['error'] ?? '');
$num_inc = (int) end($NF['enviados'])['datos']['numero'];
es('la fila queda en «incierto»', 'incierto',
   (string) valor("SELECT estado FROM comprobantes_electronicos WHERE pedido_id = ? ORDER BY id DESC LIMIT 1", [$PI]));
$sig_antes = (int) valor("SELECT siguiente FROM nubefact_correlativos WHERE ambiente='prueba' AND tipo_cpe=2 AND serie='BBB1'");
$NF['respuestas'][] = ['http' => 400, 'red' => '', 'cuerpo' => ['errors' => 'Este documento ya existe', 'codigo' => 23]];
$NF['respuestas'][] = ['http' => 200, 'red' => '', 'cuerpo' => ['tipo_de_comprobante' => 2, 'serie' => 'BBB1',
    'numero' => $num_inc, 'aceptada_por_sunat' => false, 'enlace_del_pdf' => 'https://nubefact.test/pdf/inc']];
$ri2 = nubefact_emitir($PI, 'boleta');
ok('al reintentar, se adopta la que sí había llegado', $ri2['ok'], $ri2['error'] ?? '');
$dos = array_slice($NF['enviados'], -2);
es('se reenvió el MISMO número', $num_inc, (int)$dos[0]['datos']['numero']);
es('y después se consultó', 'consultar_comprobante', $dos[1]['datos']['operacion']);
es('sin gastar un número más', $sig_antes,
   (int) valor("SELECT siguiente FROM nubefact_correlativos WHERE ambiente='prueba' AND tipo_cpe=2 AND serie='BBB1'"));
es('y el pedido queda con esa boleta', (string)$num_inc, (string)pedido_de($PI)['comprobante_numero']);
es('una sola boleta para esa venta', 1,
   (int) valor("SELECT COUNT(*) FROM comprobantes_electronicos WHERE pedido_id = ? AND estado = 'emitido'", [$PI]));

grupo('Parche 2u · anular con nota de crédito');

/* Sin la nota, la venta con boleta no se anula. */
$MOT_NC = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id
                        WHERE l.clave = 'motivos_anulacion' LIMIT 1");
$sin_nc = pedido_anular($PNF, $MOT_NC, 'prueba');
ok('con la boleta vigente, anular sin nota de crédito no se puede', !$sin_nc['ok']);
ok('y dice por qué', str_contains((string)$sin_nc['error'], 'nota de crédito'), $sin_nc['error'] ?? '');

/* Si la nota no sale, nada cambia. */
$NF['respuestas'][] = ['http' => 400, 'red' => '', 'cuerpo' => ['errors' => 'Motivo no válido', 'codigo' => 20]];
$nc_mal = nubefact_nota_credito($PNF, $MOT_NC, 'El cliente se arrepintió');
ok('una nota rechazada no se da por emitida', !$nc_mal['ok']);
ok('y la boleta sigue vigente', (bool) nubefact_vigente($PNF));

$nc = nubefact_nota_credito($PNF, $MOT_NC, 'El cliente se arrepintió');
ok('la nota de crédito sale', $nc['ok'], $nc['error'] ?? '');
$envn = end($NF['enviados'])['datos'];
es('es una nota de crédito', 3, $envn['tipo_de_comprobante']);
es('que modifica una boleta', 2, $envn['documento_que_se_modifica_tipo']);
es('la de esta venta', 'BBB1', $envn['documento_que_se_modifica_serie']);
es('con su número', 1, $envn['documento_que_se_modifica_numero']);
es('por anulación de la operación', 1, $envn['tipo_de_nota_de_credito']);
es('por el mismo total', '600.00', $envn['total']);
es('con su propia numeración: la primera nota es la 1', 1, $envn['numero']);
ok('la boleta ya no está vigente', !nubefact_vigente($PNF));
$an = pedido_anular($PNF, $MOT_NC, 'prueba');
ok('y ahora la venta sí se anula', $an['ok'], $an['error'] ?? '');

grupo('Parche 2u · los casos difíciles de la numeración');

guardar_ajuste('nubefact_prueba_serie_nc_boleta', '');
ajustes_olvidar();
ok('sin la serie de las notas de crédito no se activa', !nubefact_activo());
guardar_ajuste('nubefact_prueba_serie_nc_boleta', 'BBB1');
ajustes_olvidar();

/* Un número recién reservado que YA EXISTE en NUBEFACT es de otro
   comprobante (emitido allá a mano): no se adopta. */
banco_entrar($ASESOR);
$P23 = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_NF,'asesor_id'=>$ASESOR,'equipo_id'=>$EQUIPO,
                     'monto'=>70000,'canal_item_id'=>$CANAL]);
cobrar($P23, 70000, $YAPE, $HOY, $VOU);
banco_entrar($FACTU);
$sig23 = (int) valor("SELECT siguiente FROM nubefact_correlativos WHERE ambiente='prueba' AND tipo_cpe=2 AND serie='BBB1'");
$NF['respuestas'][] = ['http' => 400, 'red' => '', 'cuerpo' => ['errors' => 'Este documento ya existe', 'codigo' => 23]];
$r23 = nubefact_emitir($P23, 'boleta');
ok('un número ocupado por otro no se adopta', !$r23['ok']);
ok('y dice que se suba el próximo número', str_contains((string)$r23['error'], 'próximo número'), $r23['error'] ?? '');
es('ni se consultó: se mandó una sola vez', 'generar_comprobante', end($NF['enviados'])['datos']['operacion']);
es('la venta sigue sin comprobante', 0,
   (int) valor("SELECT COUNT(*) FROM comprobantes_electronicos WHERE pedido_id = ? AND estado <> 'conflicto'", [$P23]));
ok('ni lo tiene pendiente', !nubefact_pendiente($P23));
es('y la serie salta ese número', $sig23 + 1,
   (int) valor("SELECT siguiente FROM nubefact_correlativos WHERE ambiente='prueba' AND tipo_cpe=2 AND serie='BBB1'"));
$r23b = nubefact_emitir($P23, 'boleta');
ok('con el siguiente número sale', $r23b['ok'], $r23b['error'] ?? '');
es('que es el que sigue al ocupado', $sig23 + 1, (int) end($NF['enviados'])['datos']['numero']);

/* Una respuesta sin «errors» y sin comprobante (un error de su lado) no es
   un comprobante emitido. */
banco_entrar($ASESOR);
$P500 = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_NF,'asesor_id'=>$ASESOR,'equipo_id'=>$EQUIPO,
                      'monto'=>20000,'canal_item_id'=>$CANAL]);
cobrar($P500, 20000, $YAPE, $HOY, $VOU);
banco_entrar($FACTU);
$NF['respuestas'][] = ['http' => 500, 'red' => '', 'cuerpo' => ['message' => 'Internal Server Error']];
$r500 = nubefact_emitir($P500, 'boleta');
ok('un error de NUBEFACT sin comprobante no se da por emitido', !$r500['ok']);
es('queda como «sin respuesta»', 'incierto',
   (string) valor('SELECT estado FROM comprobantes_electronicos WHERE pedido_id = ?', [$P500]));
ok('y la venta sin comprobante', trim((string)pedido_de($P500)['comprobante_tipo']) === '');
$n_env = count($NF['enviados']);
$rtipo = nubefact_emitir($P500, 'factura');
ok('con una boleta pendiente no se emite una factura', !$rtipo['ok']);
ok('y se dice cuál terminar', str_contains((string)$rtipo['error'], 'Primero hay que terminar la boleta'),
   $rtipo['error'] ?? '');
es('sin llamar a NUBEFACT', $n_env, count($NF['enviados']));
q("UPDATE comprobantes_electronicos SET estado = 'enviando', enviado_en = ? WHERE pedido_id = ?",
  [date('Y-m-d H:i:s'), $P500]);
$rya = nubefact_emitir($P500, 'boleta');
ok('si se está emitiendo ahora mismo, se espera', !$rya['ok'] && str_contains((string)$rya['error'], 'ahora mismo'),
   $rya['error'] ?? '');
es('sin mandarlo otra vez', $n_env, count($NF['enviados']));
q("UPDATE comprobantes_electronicos SET estado = 'incierto', enviado_en = ? WHERE pedido_id = ?",
  [date('Y-m-d H:i:s', time() - 600), $P500]);
$an500 = pedido_anular($P500, $MOT_NC, 'prueba');
ok('con una boleta sin respuesta, la venta no se anula', !$an500['ok']);
ok('ni se anota una a mano', !pedido_comprobante($P500, 'boleta', 'B001', '5')['ok']);
/* El reintento: NUBEFACT dice que ya existe, pero lo que tiene con ese
   número es de otro total y otro cliente. */
$num500 = (int) valor('SELECT numero FROM comprobantes_electronicos WHERE pedido_id = ?', [$P500]);
$NF['respuestas'][] = ['http' => 400, 'red' => '', 'cuerpo' => ['errors' => 'Este documento ya existe', 'codigo' => 23]];
$NF['respuestas'][] = ['http' => 200, 'red' => '', 'cuerpo' => ['tipo_de_comprobante' => 2, 'serie' => 'BBB1',
    'numero' => $num500, 'enlace_del_pdf' => 'https://nubefact.test/pdf/otro',
    'cadena_para_codigo_qr' => '20600000001|03|BBB1|' . $num500 . '|2.29|15.00|' . date('Y-m-d') . '|1|11111111|x']];
$rotro = nubefact_emitir($P500, 'boleta');
ok('si el que existe es de otro cliente, no se adopta', !$rotro['ok']);
ok('la venta sigue sin comprobante', trim((string)pedido_de($P500)['comprobante_tipo']) === '');
$rbien = nubefact_emitir($P500, 'boleta');
ok('y con el número siguiente sale', $rbien['ok'], $rbien['error'] ?? '');

/* El reintento manda LO MISMO que se mandó, aunque entretanto se haya
   corregido el cliente. */
banco_entrar($ASESOR);
$CLI_RE = nuevo_cliente($PAIS, $ASESOR, '44550011', 'reintento@waka.test');
$PRE = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_RE,'asesor_id'=>$ASESOR,'equipo_id'=>$EQUIPO,
                     'monto'=>25000,'canal_item_id'=>$CANAL]);
cobrar($PRE, 25000, $YAPE, $HOY, $VOU);
banco_entrar($FACTU);
$NF['respuestas'][] = ['http' => 0, 'red' => 'Operation timed out', 'cuerpo' => null];
nubefact_emitir($PRE, 'boleta');
q("UPDATE clientes SET documento = '44550099' WHERE id = ?", [$CLI_RE]);
$rre = nubefact_emitir($PRE, 'boleta');
ok('el reintento sale', $rre['ok'], $rre['error'] ?? '');
es('con el documento que se mandó la primera vez', '44550011',
   (string) end($NF['enviados'])['datos']['cliente_numero_de_documento']);

/* Una boleta de PRODUCCIÓN cuenta aunque alguien vuelva a poner el modo prueba. */
q("UPDATE comprobantes_electronicos SET ambiente = 'produccion' WHERE pedido_id = ? AND estado = 'emitido'", [$PRE]);
ok('en prueba, una boleta de producción sigue vigente', (bool) nubefact_vigente($PRE));
ok('y la venta no se anula sin su nota de crédito', !pedido_anular($PRE, $MOT_NC, 'prueba')['ok']);
q("UPDATE comprobantes_electronicos SET ambiente = 'prueba' WHERE pedido_id = ? AND estado = 'emitido'", [$PRE]);

grupo('Parche 2u · la nota de crédito copia lo que se mandó');

$orig_pr = nubefact_vigente($PR);
ok('la venta tiene su boleta', (bool)$orig_pr);
$igv_antes = ajuste('igv_porcentaje', 18);
guardar_ajuste('igv_porcentaje', '10');
ajustes_olvidar();
$ncpr = nubefact_nota_credito($PR, $MOT_NC, 'Cambio de IGV entremedio');
guardar_ajuste('igv_porcentaje', (string)$igv_antes);
ajustes_olvidar();
ok('la nota sale aunque el IGV haya cambiado', $ncpr['ok'], $ncpr['error'] ?? '');
$envpr = end($NF['enviados'])['datos'];
$datos_orig = json_decode((string)$orig_pr['datos_json'], true);
es('con el IGV de la boleta', $datos_orig['total_igv'], $envpr['total_igv']);
es('y sus mismas líneas', json_encode($datos_orig['items']), json_encode($envpr['items']));
es('al mismo cliente', $datos_orig['cliente_numero_de_documento'], $envpr['cliente_numero_de_documento']);

grupo('Parche 2u · las de prueba no tapan las de producción');

ok('en prueba, la venta tiene su boleta', (bool) nubefact_vigente($PI));
guardar_ajuste('nubefact_ambiente', 'produccion');
ajustes_olvidar();
ok('en producción, la de prueba ya no cuenta', !nubefact_vigente($PI));
guardar_ajuste('nubefact_ambiente', 'prueba');
ajustes_olvidar();

grupo('Módulo 3a · el catálogo');

banco_entrar($ADMIN);
/* Las categorías vienen de la web (3f): las que usan estas pruebas. */
guardar_ajuste_tecnico('tienda_categorias', json_encode(['Carritos', 'Viejas', 'Hogar', 'Sillas']));
/* 3f · UN MÉTODO DE FÁBRICA RENOMBRADO NO VUELVE en la siguiente actualización
   (con un solo país, nadie guarda una copia con el nombre viejo). */
$lid_mp = (int) valor("SELECT id FROM listas WHERE clave = 'metodos_pago'");
$plin_id = (int) valor("SELECT id FROM lista_items WHERE lista_id = ? AND valor = 'Plin'", [$lid_mp]);
q("UPDATE lista_items SET valor = 'Plin empresa' WHERE id = ?", [$plin_id]);
metodo_renombrado_apuntar('Plin');
sembrar_listas();
es('RENOMBRADO, LA ACTUALIZACIÓN NO VUELVE A CREAR «Plin»', 0, (int) valor("SELECT COUNT(*) FROM lista_items WHERE lista_id = ? AND valor = 'Plin'", [$lid_mp]));
q("UPDATE lista_items SET valor = 'Plin' WHERE id = ?", [$plin_id]);
$c1 = producto_guardar(null, ['nombre' => 'Carrito eléctrico Waka', 'categoria' => 'Carritos',
                              'garantia_item_id' => $G6, 'activo' => 1]);
ok('un producto se crea', $c1['ok'], $c1['error'] ?? '');
$PROD = (int)$c1['id'];
$prod1 = producto_de($PROD);
/* WK- y no PRD- (usuario, 2026-09-24): los PRD son de marketing y de la
   tienda; uno generado aquí con el mismo número se fundía con el de la web. */
ok('y nace con su código, que no se confunde con los de la tienda',
   (bool) preg_match('/^WK-\d{6}$/', (string)$prod1['sku']), (string)$prod1['sku']);
ok('sin nombre no se crea', !producto_guardar(null, ['nombre' => 'x'])['ok']);
/* 3f · LA CATEGORÍA SE ELIGE DE LA LISTA DE LA WEB. */
$cat_mal = producto_guardar(null, ['nombre' => 'Con categoría inventada', 'categoria' => 'Cosas raras', 'activo' => 1]);
es('UNA CATEGORÍA QUE NO ESTÁ EN LA WEB NO SE GUARDA', [false, 'Elige una categoría de la lista. Las categorías se crean en la web.'],
   [$cat_mal['ok'], $cat_mal['error']]);
$sin_cat = producto_guardar(null, ['nombre' => 'Sin categoría', 'categoria' => '', 'activo' => 1]);
ok('sin categoría, sí', $sin_cat['ok']);
q('DELETE FROM productos WHERE id = ?', [(int)$sin_cat['id']]);
q("UPDATE productos SET categoria = 'Vieja de antes' WHERE id = ?", [(int)$c1['id']]);
ok('la que ya tenía se puede dejar aunque la web ya no la tenga',
   producto_guardar((int)$c1['id'], ['nombre' => 'Carrito eléctrico Waka', 'categoria' => 'Vieja de antes', 'garantia_item_id' => $G6, 'activo' => 1])['ok']);
q("UPDATE productos SET categoria = 'Carritos' WHERE id = ?", [(int)$c1['id']]);
es('LA LISTA SALE DE LA WEB, ordenada', ['Carritos', 'Hogar', 'Sillas', 'Viejas'], categorias_del_catalogo());
categorias_web_guardar([['categorias' => ['Sillas', 'Mesas']], ['categorias' => ['Mesas']]]);
es('y se guarda al leer la tienda (sin repetidas)', ['Mesas', 'Sillas'], categorias_del_catalogo());
guardar_ajuste_tecnico('tienda_categorias', json_encode(['Carritos', 'Viejas', 'Hogar', 'Sillas']));
ok('con una garantía que no existe, tampoco',
   !producto_guardar(null, ['nombre' => 'Otro', 'garantia_item_id' => 999999])['ok']);

/* Sin precio no se puede vender: ni sale en la búsqueda del asesor. */
ok('recién creado, el producto está incompleto', !producto_completo($PROD));
es('y no lo encuentra el asesor', 0, count(catalogo_buscar('Carrito')));
es('el aviso cuenta los que no tienen precio', 1, catalogo_sin_precio());

/* Los tramos: seguidos, empezando en 1 y el último sin «hasta». */
$mal1 = precios_guardar($PROD, null, [['desde'=>3,'hasta'=>'','precio'=>'699']]);
ok('el primer tramo tiene que empezar en 1', !$mal1['ok'], $mal1['error'] ?? '');
$mal2 = precios_guardar($PROD, null, [['desde'=>1,'hasta'=>2,'precio'=>'699'],
                                      ['desde'=>5,'hasta'=>'','precio'=>'649']]);
ok('y no puede haber huecos entre tramos', !$mal2['ok'], $mal2['error'] ?? '');
$mal3 = precios_guardar($PROD, null, [['desde'=>1,'hasta'=>2,'precio'=>'699'],
                                      ['desde'=>3,'hasta'=>9,'precio'=>'0']]);
ok('un tramo sin precio no se guarda', !$mal3['ok'], $mal3['error'] ?? '');
ok('ni una tabla vacía', !precios_guardar($PROD, null, [])['ok']);

$bien = precios_guardar($PROD, null, [['desde'=>1,'hasta'=>2,'precio'=>'699','alias'=>''],
                                      ['desde'=>3,'hasta'=>9,'precio'=>'649','alias'=>'Por 3'],
                                      ['desde'=>10,'hasta'=>29,'precio'=>'589','alias'=>'Por 10 · mayorista'],
                                      ['desde'=>30,'hasta'=>'','precio'=>'545','alias'=>'Por 30']]);
ok('los cuatro tramos se guardan', $bien['ok'], $bien['error'] ?? '');
es('uno cuesta 699', 69900, precio_de_variante($PROD, null, 1));
es('tres, 649', 64900, precio_de_variante($PROD, null, 3));
es('diez, 589', 58900, precio_de_variante($PROD, null, 10));
es('treinta, 545', 54500, precio_de_variante($PROD, null, 30));
es('y doscientos también: el último tramo es «de ahí para arriba»', 54500,
   precio_de_variante($PROD, null, 200));
ok('ahora sí está completo', producto_completo($PROD));
es('y ya no cuenta como sin precio', 0, catalogo_sin_precio());

/* Los modelos y colores. */
ok('se añade un color', variante_guardar($PROD, ['color' => 'Rojo', 'medida' => ''])['ok']);
ok('y otro', variante_guardar($PROD, ['color' => 'Negro', 'medida' => ''])['ok']);
ok('el mismo dos veces, no', !variante_guardar($PROD, ['color' => 'Rojo', 'medida' => ''])['ok']);
ok('ni uno sin nada escrito', !variante_guardar($PROD, ['color' => '', 'medida' => ''])['ok']);
$vars = producto_variantes($PROD);
es('el producto tiene dos modelos', 2, count($vars));
$V_ROJO = (int)$vars[0]['id'];
ok('cada uno con su código, colgado del producto',
   str_starts_with((string)$vars[0]['sku'], (string)$prod1['sku'] . '-'), (string)$vars[0]['sku']);
es('el nombre del modelo se arma solo', 'Rojo', variante_nombre($vars[0]));

/* Un color con precio propio manda sobre el del producto. */
precios_guardar($PROD, $V_ROJO, [['desde'=>1,'hasta'=>'','precio'=>'750']]);
es('el rojo cuesta lo suyo', 75000, precio_de_variante($PROD, $V_ROJO, 1));
es('y el negro sigue con el del producto', 69900, precio_de_variante($PROD, (int)$vars[1]['id'], 1));

/* La búsqueda del asesor. */
$hall = catalogo_buscar('carrito');
es('el asesor ya lo encuentra', 1, count($hall));
es('con su precio de una unidad', 69900, $hall[0]['precio']);
es('y sus dos modelos', 2, count($hall[0]['variantes']));
es('y sus cuatro tramos', 4, count($hall[0]['tramos']));
producto_guardar($PROD, ['nombre' => 'Carrito eléctrico Waka', 'activo' => 0]);
es('un producto apagado no sale', 0, count(catalogo_buscar('carrito')));
producto_guardar($PROD, ['nombre' => 'Carrito eléctrico Waka', 'garantia_item_id' => $G6, 'activo' => 1]);
es('y vuelve a salir al encenderlo', 1, count(catalogo_buscar('carrito')));
es('el código de un producto no cambia al editarlo', (string)$prod1['sku'],
   (string)producto_de($PROD)['sku']);

grupo('Parche 2u · probar la conexión');

/* La primera forma de mandar el token no la acepta; la segunda sí: se queda
   con la que funciona. */
$NF['respuestas'][] = ['http' => 401, 'red' => '', 'cuerpo' => ['errors' => 'Token no válido', 'codigo' => 10]];
$NF['respuestas'][] = ['http' => 400, 'red' => '', 'cuerpo' => ['errors' => 'No existe', 'codigo' => 24]];
$pr = nubefact_probar('prueba');
ok('probar la conexión funciona', $pr['ok'], $pr['texto']);
es('y recuerda cómo mandar el token', 'token', (string) ajuste('nubefact_auth'));
$NF['respuestas'][] = ['http' => 0, 'red' => 'Could not resolve host', 'cuerpo' => null];
ok('sin llegar a NUBEFACT, lo dice', !nubefact_probar('prueba')['ok']);

unset($GLOBALS['__nubefact_transporte']);

/* ═══════════════════════  MÓDULO 3b · LA TIENDA  ═══════════════════════ */
require_once __DIR__ . '/tienda_falsa.php';

grupo('Módulo 3b · los códigos de la tienda');

es('«COD: PRD-016782» se lee como PRD-016782', 'PRD-016782', tienda_sku_limpio('COD: PRD-016782'));
es('el código sin prefijo queda igual', 'PRD-010437', tienda_sku_limpio('PRD-010437'));
es('en minúsculas y con espacios, también', 'PRD-010437', tienda_sku_limpio('  prd-010437 '));
es('un código que no es PRD entra con el suyo', 'NEL-603', tienda_sku_limpio('COD: NEL-603'));
es('«Código:» con tilde también se quita', 'PRD-1', tienda_sku_limpio('Código: PRD-1'));
es('«SKU » también', 'PRD-2', tienda_sku_limpio('SKU PRD-2'));
es('pero no el «COD» que es parte del código', 'CODEX-1', tienda_sku_limpio('CODEX-1'));
es('sin código, vacío', '', tienda_sku_limpio(''));
ok('un código con signos raros no vale', !sku_valido('PRD01?'));
ok('uno normal sí', sku_valido('PRD-016782') && sku_valido('NEL-603'));
$p_raro = producto_guardar(null, ['nombre' => 'Producto raro', 'sku' => 'AB?C', 'activo' => 1]);
ok('el alta a mano usa la misma regla del código', !$p_raro['ok'] && str_contains($p_raro['error'], 'Ese código no vale'));

es('la dirección se queda sin barra final', 'https://compraenwaka.com', tienda_ruta_limpia('https://compraenwaka.com/'));
es('y sin la ruta de la API si la pegan entera', 'https://compraenwaka.com',
   tienda_ruta_limpia('https://compraenwaka.com/wp-json/wc/v3/products'));
es('sin https no vale', '', tienda_ruta_limpia('http://compraenwaka.com'));
es('ni sin el https://', '', tienda_ruta_limpia('compraenwaka.com'));
ok('la clave del cliente empieza por ck_', tienda_clave_valida('ck_' . str_repeat('a1', 20), 'ck')
   && !tienda_clave_valida('cs_' . str_repeat('a1', 20), 'ck') && !tienda_clave_valida('ck_corta', 'ck'));
es('la llave se enseña tapada', '•••• wxyz', llave_tapada('cs_abcdefghijklmnopqrstuvwxyz'));
es('y NUBEFACT usa la misma', llave_tapada('1234567890abcdef'), nubefact_token_tapado('1234567890abcdef'));

$at = tienda_atributos([['name' => 'Color', 'option' => 'Rojo'], ['name' => 'Tamaño', 'option' => '20 cm'],
                        ['name' => 'Modelo', 'option' => 'Pro']]);
es('el atributo «Color» va al color', 'Rojo', $at['color']);
es('y el resto se junta en el modelo', '20 cm / Pro', $at['medida']);

grupo('Módulo 3b · probar la conexión');

banco_entrar($ADMIN);
$KEY = 'ck_' . str_repeat('4f', 20);
$SEC = 'cs_' . str_repeat('9a', 18) . 'Q7Z9';
$T = ['productos' => [], 'variaciones' => [], 'key' => $KEY, 'secret' => $SEC,
      'modos' => ['consulta'], 'llamadas' => []];
$GLOBALS['__tienda_transporte'] = tienda_falsa($T);

$pr0 = tienda_probar();
ok('sin datos no se puede probar', !$pr0['ok']);
es('y no se llamó a la tienda', 0, count($T['llamadas']));
$l0 = tienda_leer();
ok('ni leer', !$l0['ok'] && str_contains($l0['error'], 'Configuración › Tienda'));

guardar_ajuste('woo_url', 'https://compraenwaka.test');
guardar_ajuste('woo_key', $KEY);
guardar_ajuste('woo_secret', $SEC);
ajustes_olvidar();
ok('con los tres datos, queda lista', tienda_config()['listo']);

$T['productos'] = array_map(fn($i) => ['id' => 9000 + $i, 'name' => 'Relleno ' . $i, 'sku' => 'PRD-9' . str_pad((string)$i, 5, '0', STR_PAD_LEFT),
                                        'type' => 'simple', 'categories' => []], range(1, 3));
$pr1 = tienda_probar();
ok('probar la conexión funciona', $pr1['ok'], $pr1['texto']);
es('primero por la cabecera y luego por la dirección', ['basica', 'consulta'], array_column($T['llamadas'], 1));
es('y se queda con la forma que funcionó', 'consulta', (string) ajuste('woo_auth'));
ok('dice cuántos productos tiene la tienda', str_contains($pr1['texto'], '3 productos'), $pr1['texto']);
ok('y apunta cuándo se probó', (string) ajuste('woo_probada_en') !== '');
ok('guardar la clave deja constancia en la bitácora',
   (int) valor("SELECT COUNT(*) FROM bitacora WHERE accion = 'config.ajuste'") > 0);
ok('pero nunca la clave', (int) valor('SELECT COUNT(*) FROM bitacora WHERE detalle LIKE ?', ['%' . $SEC . '%']) === 0);

$T['secret'] = 'cs_otra';
$pr2 = tienda_probar();
ok('con la clave equivocada, no', !$pr2['ok']);
ok('y lo dice sin jerga', str_contains($pr2['texto'], 'no aceptó las claves'), $pr2['texto']);
$T['secret'] = $SEC;
$T['caida'] = true;
$pr3 = tienda_probar();
ok('si la tienda no contesta, lo dice', !$pr3['ok'] && str_contains($pr3['texto'], 'No hubo respuesta'), $pr3['texto']);
$T['caida'] = false;
$T['http'] = 404;
ok('una dirección que no es la tienda, también', str_contains(tienda_probar()['texto'], 'No encuentro la tienda'));
$T['http'] = 0;

grupo('Módulo 3b · leer la tienda y enseñar qué pasa');

/* Lo que ya hay aquí antes de leer. */
$PERU = (int) valor("SELECT id FROM paises WHERE codigo = 'PE'");
$MX   = (int) valor("SELECT id FROM paises WHERE codigo = 'MX'")
     ?: insertar('paises', ['nombre' => 'México', 'codigo' => 'MX', 'moneda' => 'MXN', 'simbolo' => '$']);
$ya = producto_guardar(null, ['nombre' => 'Camilla vieja', 'sku' => 'PRD-010437', 'categoria' => 'Viejas',
                              'garantia_item_id' => $G6, 'activo' => 1]);
$YA = (int)$ya['id'];
precios_guardar($YA, null, [['desde' => 1, 'hasta' => '', 'precio' => '1500']]);
$enl = producto_guardar(null, ['nombre' => 'Enlazado', 'sku' => 'NEL-999', 'activo' => 1]);
actualizar('productos', (int)$enl['id'], ['woo_id' => 777]);
$otro = producto_guardar(null, ['nombre' => 'De México', 'sku' => 'PRD-MX1', 'activo' => 1]);
actualizar('productos', (int)$otro['id'], ['pais_id' => $MX]);
$negro = producto_guardar(null, ['nombre' => 'Silla', 'sku' => 'PRD-030000', 'activo' => 1]);
$NEGRO_P = (int)$negro['id'];
actualizar('productos', $NEGRO_P, ['woo_id' => 3100]);
variante_guardar($NEGRO_P, ['color' => 'Negro']);
$V_NEGRO = (int) valor('SELECT id FROM variantes WHERE producto_id = ?', [$NEGRO_P]);
$ajena = producto_guardar(null, ['nombre' => 'Ajena', 'sku' => 'PRD-040000', 'activo' => 1]);
variante_guardar((int)$ajena['id'], ['color' => 'Verde']);
$SKU_VERDE = (string) valor('SELECT sku FROM variantes WHERE producto_id = ?', [(int)$ajena['id']]);

$prod = fn(int $id, string $nombre, string $sku, string $tipo = 'simple', array $cats = []) =>
    ['id' => $id, 'name' => $nombre, 'sku' => $sku, 'type' => $tipo,
     'categories' => array_map(fn($c) => ['id' => 1, 'name' => $c, 'slug' => 'x'], $cats)];
$var = fn(int $id, string $sku, array $atr) =>
    ['id' => $id, 'sku' => $sku, 'attributes' => array_map(fn($k, $v) => ['id' => 0, 'name' => $k, 'option' => $v],
                                                           array_keys($atr), $atr)];
$RELLENO = 120;          // para que haya dos páginas
$T['productos'] = array_merge([
    $prod(3068, 'CAMILLA HEAD FUTURE PRO', 'PRD-010437', 'simple', ['Sin categorizar', 'Camillas']),
    $prod(3074, 'CAMILLA HEAD BROWN  PLUS', 'COD: PRD-016782', 'simple', ['Camillas']),
    $prod(1192, 'CARRO A BATERIA &#8211; LAMBORGHINI', 'COD: NEL-603', 'variable', ['Carros']),
    $prod(3100, 'Silla', 'PRD-030000', 'variable'),
    $prod(3200, 'Sin código', ''),
    $prod(3201, 'Repetido uno', 'PRD-020000'),
    $prod(3202, 'Repetido dos', 'COD: PRD-020000'),
    $prod(777,  'Cambió de código', 'NEL-111'),
    $prod(3203, 'Paquete', 'PRD-050000', 'grouped'),
    $prod(3204, 'Signos raros', 'PRD 01 ?'),
    $prod(3205, 'Mexicano', 'PRD-MX1'),
], array_map(fn($i) => $prod(20000 + $i, 'Relleno ' . $i, 'PRD-9' . str_pad((string)$i, 5, '0', STR_PAD_LEFT)),
             range(1, $RELLENO)));
$T['variaciones'] = [
    1192 => [
        $var(5001, 'PRD-000603-ROJO', ['Color' => 'Rojo']),
        $var(5002, '',                ['Color' => 'Azul']),
        $var(5003, '',                ['Color' => 'azul']),         // repetido
        $var(5004, 'PRD-000603-X',    []),                          // sin color ni modelo
        $var(5005, $SKU_VERDE,        ['Color' => 'Verde']),        // código de otro producto
    ],
    3100 => [ $var(6001, '', ['Color' => 'Negro']) ],               // ya existe, sin enlazar
];
$T['llamadas'] = [];
$n_prod_antes = (int) valor('SELECT COUNT(*) FROM productos');
$n_var_antes  = (int) valor('SELECT COUNT(*) FROM variantes');

$L = tienda_leer();
ok('se lee la tienda entera', $L['ok'], $L['error']);
es('3f · AL LEER LA TIENDA SE GUARDAN SUS CATEGORÍAS (sin «Sin categorizar»)', ['Camillas', 'Carros'], categorias_del_catalogo());
es('con todas sus filas, de las dos páginas', 11 + $RELLENO, count($L['filas']));
ok('pidió la segunda página', (bool) array_filter($T['llamadas'], fn($c) => str_contains($c[0], 'page=2')));
ok('con la forma de mandar las claves que funcionó', !array_filter($T['llamadas'], fn($c) => $c[1] !== 'consulta'));
ok('pidiendo solo los publicados, también los colores',
   !array_filter($T['llamadas'], fn($c) => !str_contains($c[0], 'status=publish')));
ok('y las variaciones solo de los que tienen colores',
   (bool) array_filter($T['llamadas'], fn($c) => str_contains($c[0], 'products/1192/variations'))
   && !array_filter($T['llamadas'], fn($c) => str_contains($c[0], 'products/3068/variations')));
$f_carro = array_values(array_filter($L['filas'], fn($f) => $f['woo_id'] === 1192))[0] ?? [];
es('el nombre sale sin los códigos raros de la web', 'CARRO A BATERIA – LAMBORGHINI', $f_carro['nombre'] ?? null);
es('«Sin categorizar» no cuenta como categoría', 'Camillas',
   array_values(array_filter($L['filas'], fn($f) => $f['woo_id'] === 3068))[0]['categoria'] ?? null);

$LID = tienda_lectura_nueva($L['filas'], $PERU);
ok('la lectura queda apuntada', $LID > 0);
es('LEER NO GUARDA NADA en el catálogo', $n_prod_antes, (int) valor('SELECT COUNT(*) FROM productos'));
es('ni en los colores', $n_var_antes, (int) valor('SELECT COUNT(*) FROM variantes'));

$lec  = tienda_lectura($LID);
$plan = tienda_plan($lec['filas'], $PERU);
$R = $plan['resumen'];
es('se crean la camilla, el carro y los de relleno', 2 + $RELLENO, $R['crear']);
es('se actualizan la camilla vieja y la silla', 2, $R['actualizar']);
es('no hay ninguno sin cambios todavía', 0, $R['igual']);
es('dos colores nuevos del carro', 2, $R['var_crear']);
es('y uno que se enlaza', 1, $R['var_actualizar']);
es('lo apuntado al leer es lo mismo que se enseña', $R, $lec['resumen']);
$motivos = array_column($plan['fuera'], 'motivo', 'nombre');
ok('sin código no entra', str_contains($motivos['Sin código'] ?? '', 'No tiene código'));
ok('dos con el mismo código no entran ninguno',
   isset($motivos['Repetido uno'], $motivos['Repetido dos']) && str_contains($motivos['Repetido uno'], 'mismo código'));
ok('si el código cambió en la tienda, se avisa y no se adivina',
   str_contains($motivos['Cambió de código'] ?? '', 'aquí es NEL-999'), $motivos['Cambió de código'] ?? '');
ok('un paquete no entra', str_contains($motivos['Paquete'] ?? '', 'no se vende suelto'));
ok('un código con signos raros no entra', str_contains($motivos['Signos raros'] ?? '', 'no valen'));
ok('ni uno que es de otro país', str_contains($motivos['Mexicano'] ?? '', 'otro país'));
ok('el color repetido del carro no entra dos veces',
   str_contains($motivos['CARRO A BATERIA – LAMBORGHINI · azul'] ?? '', 'repetido'));
ok('una variación sin color ni modelo no entra',
   str_contains($motivos['CARRO A BATERIA – LAMBORGHINI · Única'] ?? '', 'no dice su color'));
ok('ni una con el código de un color de otro producto',
   str_contains($motivos['CARRO A BATERIA – LAMBORGHINI · Verde'] ?? '', 'otro producto'));
es('en total, diez no entran', 10, $R['fuera']);
$camb = array_values(array_filter($plan['productos'], fn($p) => $p['id'] === $YA))[0]['cambios'] ?? [];
es('de la camilla vieja cambia el nombre', ['Camilla vieja', 'CAMILLA HEAD FUTURE PRO'], $camb['nombre'] ?? null);
es('y la categoría', ['Viejas', 'Camillas'], $camb['categoria'] ?? null);
ok('y queda enlazada', isset($camb['enlace']));

grupo('Módulo 3b · guardar en el catálogo');

$G = tienda_importar($LID);
ok('se guarda', $G['ok'], $G['error']);
es('con las mismas cifras que se enseñaron', $R, array_diff_key($G['resumen'], ['fotos_ok' => 0, 'fotos_malas' => 0]));
es('los productos nuevos están', $n_prod_antes + 2 + $RELLENO, (int) valor('SELECT COUNT(*) FROM productos'));
$nuevo = una('SELECT * FROM productos WHERE sku = ?', ['PRD-016782']);
ok('el que llevaba «COD:» entra con el código limpio', (bool)$nuevo);
es('enlazado con su producto de la tienda', 3074, (int)($nuevo['woo_id'] ?? 0));
es('en el país de quien lo trajo', $PERU, (int)($nuevo['pais_id'] ?? 0));
es('con su categoría', 'Camillas', $nuevo['categoria'] ?? null);
ok('SIN PRECIO: el asesor todavía no lo ve', !producto_completo((int)$nuevo['id'])
   && !array_filter(catalogo_buscar('BROWN'), fn($x) => $x['sku'] === 'PRD-016782'));
$carro = una('SELECT * FROM productos WHERE sku = ?', ['NEL-603']);
ok('el que no es PRD entra con su código', (bool)$carro && (int)$carro['woo_id'] === 1192);
$vc = todas('SELECT * FROM variantes WHERE producto_id = ? ORDER BY id', [(int)($carro['id'] ?? 0)]);
es('con sus dos colores', ['Rojo', 'Azul'], array_column($vc, 'color'));
es('el rojo con el código que trae de la tienda', 'PRD-000603-ROJO', $vc[0]['sku'] ?? null);
ok('el azul, sin código allá, con uno colgado del producto', str_starts_with((string)($vc[1]['sku'] ?? ''), 'NEL-603-'),
   (string)($vc[1]['sku'] ?? ''));
es('y cada uno enlazado con su variación', [5001, 5002], array_map('intval', array_column($vc, 'woo_id')));
$vieja = producto_de($YA);
es('MANDA LA TIENDA: la camilla vieja tiene el nombre de la web', 'CAMILLA HEAD FUTURE PRO', $vieja['nombre']);
es('y su categoría', 'Camillas', $vieja['categoria']);
es('queda enlazada', 3068, (int)$vieja['woo_id']);
es('EL PRECIO POR CANTIDAD NO SE TOCA', 150000, precio_de_variante($YA, null, 1));
es('NI LA GARANTÍA', $G6, (int)$vieja['garantia_item_id']);
es('el negro de la silla se enlaza, no se duplica', 1, (int) valor('SELECT COUNT(*) FROM variantes WHERE producto_id = ?', [$NEGRO_P]));
es('con su variación de la tienda', 6001, (int) valor('SELECT woo_id FROM variantes WHERE id = ?', [$V_NEGRO]));
es('el enlazado con otro código sigue como estaba', 'Enlazado', (string) valor("SELECT nombre FROM productos WHERE sku = 'NEL-999'"));
es('el de México no se toca', 'De México', (string) valor("SELECT nombre FROM productos WHERE sku = 'PRD-MX1'"));
ok('queda en la bitácora quién lo trajo y qué lectura',
   (bool) valor("SELECT 1 FROM bitacora WHERE accion = 'catalogo.tienda' AND usuario_id = ? AND entidad_id = ?", [$ADMIN, $LID]));

es('una conexión sin país queda como del país que la usó primero', (string)$PERU, (string) ajuste('woo_pais'));
$G2 = tienda_importar($LID);
ok('la misma lectura no se guarda dos veces', !$G2['ok'] && str_contains($G2['error'], 'ya se guardó'), $G2['error']);

grupo('Módulo 3b · leer otra vez');

$L2 = tienda_leer();
$R2 = tienda_plan($L2['filas'], $PERU)['resumen'];
es('la segunda lectura no crea nada', 0, $R2['crear']);
es('ni actualiza nada', 0, $R2['actualizar'] + $R2['var_crear'] + $R2['var_actualizar']);
es('todo queda sin cambios', 4 + $RELLENO, $R2['igual']);

/* Manda la tienda: un nombre cambiado aquí vuelve al de la web. */
producto_guardar($YA, ['nombre' => 'Nombre puesto aquí', 'garantia_item_id' => $G6, 'activo' => 1]);
$R3 = tienda_plan($L2['filas'], $PERU)['resumen'];
es('un nombre cambiado aquí se vuelve a poner el de la tienda', 1, $R3['actualizar']);

/* Y un nombre cambiado en la tienda se trae. */
$T['productos'][1]['name'] = 'CAMILLA BROWN PLUS 2';
$L3 = tienda_leer();
$ID3 = tienda_lectura_nueva($L3['filas'], $PERU);
$ID4 = tienda_lectura_nueva($L3['filas'], $PERU);
ok('la lectura anterior sin guardar se borra al leer otra', tienda_lectura($ID3) === null);
ok('la guardada se queda como constancia', tienda_lectura($LID) !== null);
ok('una lectura vieja sin guardar ya no existe', !tienda_importar($ID3)['ok']);
$G4 = tienda_importar($ID4);
ok('la nueva sí', $G4['ok'], $G4['error']);
es('y trae el nombre nuevo', 'CAMILLA BROWN PLUS 2', (string) valor("SELECT nombre FROM productos WHERE sku = 'PRD-016782'"));
es('y devuelve el de la camilla', 'CAMILLA HEAD FUTURE PRO', (string) producto_de($YA)['nombre']);

grupo('Módulo 3b · lo que se enseñó es lo que se guarda');

$T['productos'][] = $prod(3300, 'Llega luego', 'PRD-060000');
$L5 = tienda_leer();
$ID5 = tienda_lectura_nueva($L5['filas'], $PERU);
es('la lectura dice que se crea uno', 1, tienda_lectura($ID5)['resumen']['crear']);
/* Mientras tanto, alguien lo da de alta a mano con el mismo código. */
producto_guardar(null, ['nombre' => 'Dado de alta a mano', 'sku' => 'PRD-060000', 'activo' => 1]);
$n_antes5 = (int) valor('SELECT COUNT(*) FROM productos');
$G5 = tienda_importar($ID5);
ok('si el catálogo cambió entre medias, NO SE GUARDA', !$G5['ok']);
ok('y pide leer otra vez', str_contains((string)$G5['error'], 'Vuelve a leerla'), (string)$G5['error']);
es('sin tocar ni un producto', $n_antes5, (int) valor('SELECT COUNT(*) FROM productos'));
es('el de a mano sigue con su nombre', 'Dado de alta a mano', (string) valor("SELECT nombre FROM productos WHERE sku = 'PRD-060000'"));

grupo('Módulo 3b · todo o nada');

$T['productos'][] = $prod(3400, 'Primero bien', 'PRD-070000');
$T['productos'][] = $prod(3401, 'Luego falla', 'PRD-070001', 'variable');
$T['variaciones'][3401] = [ $var(7001, 'PRD-070001-BOOM', ['Color' => 'Rosa']) ];
$L6 = tienda_leer();
$ID6 = tienda_lectura_nueva($L6['filas'], $PERU);
bd()->exec("CREATE TRIGGER falla_a_mitad BEFORE INSERT ON variantes
            WHEN NEW.sku = 'PRD-070001-BOOM' BEGIN INSERT INTO tabla_que_no_existe VALUES (1); END");
$n_antes6 = (int) valor('SELECT COUNT(*) FROM productos');
$G6r = tienda_importar($ID6);
bd()->exec('DROP TRIGGER falla_a_mitad');
ok('si algo falla a mitad, se dice', !$G6r['ok'] && str_contains((string)$G6r['error'], 'No se cambió nada'), (string)$G6r['error']);
es('Y NO QUEDA MEDIO CATÁLOGO: ni el que iba primero', 0,
   (int) valor("SELECT COUNT(*) FROM productos WHERE sku IN ('PRD-070000', 'PRD-070001')"));
es('el número de productos no se movió', $n_antes6, (int) valor('SELECT COUNT(*) FROM productos'));
es('y la lectura se puede volver a intentar', 'leida', (string) valor('SELECT estado FROM tienda_lecturas WHERE id = ?', [$ID6]));
ok('al reintentar sin el fallo, entra', tienda_importar($ID6)['ok']);

grupo('Módulo 3b · un color o un producto que se volvió a crear en la tienda');

/* Marketing borra el negro de la silla y lo vuelve a crear: cambia de número. */
$T['variaciones'][3100] = [ $var(6009, '', ['Color' => 'Negro']) ];
/* Y un producto que se borró y se volvió a crear con el mismo código. */
$rec = producto_guardar(null, ['nombre' => 'Recreado', 'sku' => 'PRD-080000', 'activo' => 1]);
actualizar('productos', (int)$rec['id'], ['woo_id' => 999]);           // 999 ya no está en la tienda
$T['productos'][] = $prod(3500, 'Recreado', 'PRD-080000');
/* Y dos que SÍ siguen en la tienda: uno cambió de código y otro se quedó con el suyo. */
$dos = producto_guardar(null, ['nombre' => 'Dos', 'sku' => 'PRD-082000', 'activo' => 1]);
actualizar('productos', (int)$dos['id'], ['woo_id' => 3701]);
$T['productos'][] = $prod(3701, 'Dos', 'PRD-082001');
$T['productos'][] = $prod(3702, 'Dos bis', 'PRD-082000');
$L8 = tienda_leer();
$P8 = tienda_plan($L8['filas'], $PERU);
$mot8 = array_column($P8['fuera'], 'motivo', 'nombre');
ok('el producto recreado se vuelve a enlazar, no se queda fuera', !isset($mot8['Recreado']));
ok('si el enlace viejo sigue en la tienda, no se adivina',
   str_contains($mot8['Dos bis'] ?? '', 'ya está enlazado a otro producto'), $mot8['Dos bis'] ?? '');
$n_negro = (int) valor('SELECT COUNT(*) FROM variantes WHERE producto_id = ?', [$NEGRO_P]);
$ID8 = tienda_lectura_nueva($L8['filas'], $PERU);
ok('se guarda', tienda_importar($ID8)['ok']);
es('EL NEGRO NO SE DUPLICA', $n_negro, (int) valor('SELECT COUNT(*) FROM variantes WHERE producto_id = ?', [$NEGRO_P]));
es('queda enlazado con su número nuevo', 6009, (int) valor('SELECT woo_id FROM variantes WHERE id = ?', [$V_NEGRO]));
es('y el producto recreado, también', 3500, (int) valor("SELECT woo_id FROM productos WHERE sku = 'PRD-080000'"));

/* Dos variaciones que apuntan al mismo color de aquí: una por su número y
   otra por su nombre. No se le dan dos enlaces al mismo color. */
$T['variaciones'][3100] = [ $var(6009, '', ['Color' => 'Negro mate']), $var(6010, '', ['Color' => 'Negro']) ];
$P9 = tienda_plan(tienda_leer()['filas'], $PERU);
$v9 = array_values(array_filter($P9['productos'], fn($p) => $p['id'] === $NEGRO_P))[0]['variantes'] ?? [];
es('el negro enlazado sigue a su número y cambia de nombre', 'actualizar', $v9[0]['accion'] ?? null);
es('y el otro «Negro» se crea aparte, no se le pega al mismo color', 'crear', $v9[1]['accion'] ?? null);
$T['variaciones'][3100] = [ $var(6009, '', ['Color' => 'Negro']) ];

grupo('Módulo 3b · la tienda se cae a mitad de la lectura');

$T['caida'] = 2;                     // la primera página contesta, la segunda no
$n_lect = (int) valor('SELECT COUNT(*) FROM tienda_lecturas');
$L7 = tienda_leer();
ok('una lectura a medias no vale', !$L7['ok'] && $L7['filas'] === []);
ok('y lo dice', str_contains($L7['error'], 'No hubo respuesta'), $L7['error']);
$T['caida'] = false;

grupo('Módulo 3b · otro país');

/* Una lectura NUEVA y sin guardar en Perú: la única forma de que la frontera
   entre países sea lo que la frena, y no que ya estaba guardada. */
$T['productos'][] = $prod(3800, 'Solo de Perú', 'PRD-380000');
$ID_PE = tienda_lectura_nueva(tienda_leer()['filas'], $PERU);
$n_antes_pe = (int) valor('SELECT COUNT(*) FROM productos');
$USU_MX = banco_usuario('administracion', ['pais_id' => $MX]);
banco_entrar($USU_MX);
ok('una lectura de Perú no se abre desde México', tienda_lectura($ID_PE) === null);
$G_MX = tienda_importar($ID_PE);
ok('ni se guarda', !$G_MX['ok']);
es('sin crear nada', $n_antes_pe, (int) valor('SELECT COUNT(*) FROM productos'));
es('y la lectura sigue esperando en Perú', 'leida', (string) valor('SELECT estado FROM tienda_lecturas WHERE id = ?', [$ID_PE]));

/* LA TIENDA ES DE UN PAÍS: la conexión se apuntó para Perú. */
guardar_ajuste('woo_pais', (string)$PERU);
ajustes_olvidar();
$L_MX = tienda_leer();
ok('desde México no se lee la tienda de Perú', !$L_MX['ok'] && str_contains($L_MX['error'], 'otro país'), $L_MX['error']);
/* Una lectura de México que se colara igual no se guarda. */
$ID_MX = tienda_lectura_nueva($L['filas'], $MX);
$G_MX2 = tienda_importar($ID_MX);
ok('ni se guarda', !$G_MX2['ok'] && str_contains((string)$G_MX2['error'], 'otro país'), (string)$G_MX2['error']);
banco_entrar($ADMIN);
banco_entrar($USU_MX);
ok('ni se prueba la conexión desde allí', str_contains(tienda_probar()['texto'], 'otro país'));
banco_entrar($ADMIN);
ok('desde Perú sí', tienda_leer()['ok']);
/* Y leer en Perú no borra lo que México tenga sin guardar. */
tienda_lectura_nueva(tienda_leer()['filas'], $PERU);
es('la lectura de México sigue ahí', 1, (int) valor('SELECT COUNT(*) FROM tienda_lecturas WHERE id = ?', [$ID_MX]));
ok('guardar la de Perú sigue funcionando', tienda_importar((int) valor('SELECT MAX(id) FROM tienda_lecturas WHERE pais_id = ?', [$PERU]))['ok']);

grupo('Módulo 3b · lo que encontró la auditoría');

/* Cada escenario con su tienda propia, para que uno no tape al otro. */
$solo = function (array $productos, array $variaciones = []) use (&$T) {
    $T['productos'] = $productos; $T['variaciones'] = $variaciones; $T['llamadas'] = [];
};
$leer_y_plan = function () use ($PERU) {
    $l = tienda_leer();
    return ['filas' => $l['filas'], 'plan' => tienda_plan($l['filas'], $PERU), 'ok' => $l['ok'], 'error' => $l['error']];
};
$motivo = fn(array $plan, string $nombre) => array_column($plan['fuera'], 'motivo', 'nombre')[$nombre] ?? '';
$item = fn(array $plan, int $woo) => array_values(array_filter($plan['productos'], fn($p) => $p['fila']['woo_id'] === $woo))[0] ?? null;

/* 1 · Un producto dado de alta a mano sin código ya no se funde con uno de la tienda. */
$amano = producto_guardar(null, ['nombre' => 'Mesa plegable', 'activo' => 1]);
$SKU_AMANO = (string) producto_de((int)$amano['id'])['sku'];
ok('el código que se genera solo empieza por WK-', str_starts_with($SKU_AMANO, 'WK-'), $SKU_AMANO);
$solo([$prod(501, 'Silla gamer RGB', 'PRD-' . substr($SKU_AMANO, 3), 'simple', ['Sillas'])]);
$x = $leer_y_plan();
es('el de la tienda con el mismo número se crea aparte', 'crear', $item($x['plan'], 501)['accion'] ?? null);
tienda_importar(tienda_lectura_nueva($x['filas'], $PERU));
es('LA MESA SIGUE SIENDO LA MESA', 'Mesa plegable', (string) producto_de((int)$amano['id'])['nombre']);
ok('y sin enlazar con la tienda', producto_de((int)$amano['id'])['woo_id'] === null);

/* 3 · Un color sin código cuyo código generado es el de otro color de la tienda. */
$solo([$prod(610, 'Mochila roja', 'PRD-777777', 'variable')],
      [610 => [$var(6101, '', ['Color' => 'Rojo']), $var(6102, 'PRD-777777-ROJO', ['Color' => 'Rojo mate'])]]);
$x = $leer_y_plan();
$vs = $item($x['plan'], 610)['variantes'] ?? [];
ok('el código generado no pisa el que trae la tienda', ($vs[0]['sku'] ?? '') !== 'PRD-777777-ROJO' && ($vs[0]['sku'] ?? '') !== '',
   (string)($vs[0]['sku'] ?? ''));
es('y la vista previa ya dice el código que se va a guardar', 'PRD-777777-ROJO-2', $vs[0]['sku'] ?? null);
$G3 = tienda_importar(tienda_lectura_nueva($x['filas'], $PERU));
ok('SE GUARDA, en vez de fallar siempre', $G3['ok'], (string)$G3['error']);
es('con los dos colores y sus códigos', ['PRD-777777-ROJO-2', 'PRD-777777-ROJO'],
   array_column(todas("SELECT v.sku FROM variantes v JOIN productos p ON p.id = v.producto_id
                        WHERE p.sku = 'PRD-777777' ORDER BY v.id"), 'sku'));

/* WooCommerce devuelve el código del padre en los colores sin código propio. */
$solo([$prod(620, 'Lámpara', 'PRD-620000', 'variable')],
      [620 => [$var(6201, 'PRD-620000', ['Color' => 'Blanco']), $var(6202, 'PRD-620000', ['Color' => 'Negro'])]]);
$x = $leer_y_plan();
es('los colores que heredan el código del padre no son «repetidos»', 2, count($item($x['plan'], 620)['variantes'] ?? []));
es('ni «de un producto»', '', $motivo($x['plan'], 'Lámpara · Blanco'));
ok('y cada uno recibe el suyo', str_starts_with((string)($item($x['plan'], 620)['variantes'][0]['sku'] ?? ''), 'PRD-620000-BLANCO'));

/* 4 · «Marrón» en la tienda y «Marron» aquí son el mismo color. */
$marr = producto_guardar(null, ['nombre' => 'Puff', 'sku' => 'PRD-888888', 'activo' => 1]);
variante_guardar((int)$marr['id'], ['color' => 'Marron']);
$V_MARR = (int) valor('SELECT id FROM variantes WHERE producto_id = ?', [(int)$marr['id']]);
ok('el alta a mano ya no deja «marrón » junto a «Marron»', !variante_guardar((int)$marr['id'], ['color' => 'marrón '])['ok']);
$solo([$prod(630, 'Puff', 'PRD-888888', 'variable')], [630 => [$var(6301, '', ['Color' => 'Marrón'])]]);
$x = $leer_y_plan();
ok('el importador lo reconoce como el mismo', ($item($x['plan'], 630)['variantes'][0]['id'] ?? 0) === $V_MARR);
tienda_importar(tienda_lectura_nueva($x['filas'], $PERU));
es('no se duplica', 1, (int) valor('SELECT COUNT(*) FROM variantes WHERE producto_id = ?', [(int)$marr['id']]));
es('y se queda con el nombre de la tienda, GUARDADO', 'Marrón', (string) valor('SELECT color FROM variantes WHERE id = ?', [$V_MARR]));

/* 5 · El código manda sobre el nombre del color, sea cual sea el orden. */
$rojo = producto_guardar(null, ['nombre' => 'Banco', 'sku' => 'PRD-999999', 'activo' => 1]);
variante_guardar((int)$rojo['id'], ['color' => 'Rojo']);
$V1 = (int) valor('SELECT id FROM variantes WHERE producto_id = ?', [(int)$rojo['id']]);
$SKU_V1 = (string) valor('SELECT sku FROM variantes WHERE id = ?', [$V1]);
$solo([$prod(640, 'Banco', 'PRD-999999', 'variable')],
      [640 => [$var(801, 'OTRO-CODIGO', ['Color' => 'Rojo']), $var(802, $SKU_V1, ['Color' => 'Rojo mate'])]]);
$x = $leer_y_plan();
$vs = $item($x['plan'], 640)['variantes'] ?? [];
$de802 = array_values(array_filter($vs, fn($v) => $v['fila']['woo_id'] === 802))[0] ?? [];
$de801 = array_values(array_filter($vs, fn($v) => $v['fila']['woo_id'] === 801))[0] ?? [];
es('la variación con el código del color se queda con el color', $V1, $de802['id'] ?? null);
es('y la otra se crea aparte', 'crear', $de801['accion'] ?? null);
$solo([$prod(640, 'Banco', 'PRD-999999', 'variable')],
      [640 => [$var(802, $SKU_V1, ['Color' => 'Rojo mate']), $var(801, 'OTRO-CODIGO', ['Color' => 'Rojo'])]]);
$de802b = array_values(array_filter($item($leer_y_plan()['plan'], 640)['variantes'] ?? [], fn($v) => $v['fila']['woo_id'] === 802))[0] ?? [];
es('en el otro orden, lo mismo', $V1, $de802b['id'] ?? null);

/* 6 · Mismas cifras, otro plan: no se guarda. */
$alfa = producto_guardar(null, ['nombre' => 'Alfa viejo', 'sku' => 'PRD-600001', 'activo' => 1]);
$beta = producto_guardar(null, ['nombre' => 'Beta', 'sku' => 'PRD-600002', 'activo' => 1]);
actualizar('productos', (int)$alfa['id'], ['woo_id' => 651]);
actualizar('productos', (int)$beta['id'], ['woo_id' => 652]);
$solo([$prod(651, 'Alfa', 'PRD-600001'), $prod(652, 'Beta', 'PRD-600002')]);
$x = $leer_y_plan();
$ID6b = tienda_lectura_nueva($x['filas'], $PERU);
es('al leer: Alfa se actualiza y Beta no', ['actualizar', 'igual'],
   [$item($x['plan'], 651)['accion'] ?? '', $item($x['plan'], 652)['accion'] ?? '']);
actualizar('productos', (int)$alfa['id'], ['nombre' => 'Alfa']);          // alguien lo arregla a mano
actualizar('productos', (int)$beta['id'], ['nombre' => 'Beta a mano']);   // y cambia el otro
$G6b = tienda_importar($ID6b);
ok('con las mismas cifras pero OTRO plan, no se guarda', !$G6b['ok'], 'se guardó');
es('y Beta conserva lo que se le puso a mano', 'Beta a mano', (string) producto_de((int)$beta['id'])['nombre']);

/* 9 · Los nombres con «<» no se cortan; las etiquetas de verdad sí se quitan. */
es('«Cable HDMI <2m> 4K» queda entero', 'Cable HDMI <2m> 4K', tienda_texto('Cable HDMI <2m> 4K', 180));
es('«Pack x<5 unidades» también', 'Pack x<5 unidades', tienda_texto('Pack x<5 unidades', 180));
es('una etiqueta de verdad se quita', 'Silla gamer', tienda_texto('<b>Silla</b> <span class="x">gamer</span>', 180));
es('«Polo talla <M>» se queda con su talla', 'Polo talla <M>', tienda_texto('Polo talla <M>', 180));
es('y «Pack <XL> x3» también', 'Pack <XL> x3', tienda_texto('Pack <XL> x3', 180));

/* 10 · Un producto con colores del que no entra ninguno no se trae como «Única». */
$solo([$prod(660, 'Mochila sin colores', 'PRD-660000', 'variable')],
      [660 => [$var(6601, 'PRD-660000-A', []), $var(6602, 'PRD-660000-B', [])]]);
$x = $leer_y_plan();
ok('un producto con colores del que no entra ninguno no se crea', $item($x['plan'], 660) === null);
ok('y dice por qué', str_contains($motivo($x['plan'], 'Mochila sin colores'), 'Ninguno de sus colores'));

/* 11 · Detalles. */
$solo([$prod(670, 'Uno', 'PRD-670000', 'variable'), $prod(671, 'Dos', 'PRD-671000')],
      [670 => [$var(6701, 'PRD-671000', ['Color' => 'Rojo']), $var(6702, '', ['Color' => 'Azul'])]]);
$x = $leer_y_plan();
ok('una variación con el código de un producto no entra', str_contains($motivo($x['plan'], 'Uno · Rojo'), 'es de un producto'));
ok('probar la conexión cuenta solo los publicados', str_contains((string)($T['llamadas'][0][0] ?? ''), 'status=publish')
   || (tienda_probar() && str_contains((string) end($T['llamadas'])[0], 'status=publish')));
tienda_probar();
ok('y lo pide así', str_contains((string) end($T['llamadas'])[0], 'status=publish'));
ok('la prueba buena deja la conexión como probada', (string) ajuste('woo_probada_en') !== '');
$T['secret'] = 'cs_mala';
tienda_probar();
$T['secret'] = $SEC;
es('una prueba que falla la deja como no probada', '', (string) ajuste('woo_probada_en'));

grupo('Módulo 3b · lo que las pruebas no miraban');

/* Cada color de la tienda contra lo que hay aquí. (La lectura de antes dejó
   la lista de categorías de la tienda de mentira: se pone la que usa esto.) */
guardar_ajuste_tecnico('tienda_categorias', json_encode(['Hogar']));
$az = producto_guardar(null, ['nombre' => 'Cojín', 'sku' => 'PRD-700000', 'categoria' => 'Hogar', 'activo' => 1]);
actualizar('productos', (int)$az['id'], ['woo_id' => 700]);
variante_guardar((int)$az['id'], ['color' => 'Azul']);
$V_AZ = (int) valor('SELECT id FROM variantes WHERE producto_id = ?', [(int)$az['id']]);
$SKU_AZ = (string) valor('SELECT sku FROM variantes WHERE id = ?', [$V_AZ]);
$otroP = producto_guardar(null, ['nombre' => 'Otro', 'sku' => 'PRD-710000', 'activo' => 1]);
variante_guardar((int)$otroP['id'], ['color' => 'Gris']);
$V_GRIS = (int) valor('SELECT id FROM variantes WHERE producto_id = ?', [(int)$otroP['id']]);
actualizar('variantes', $V_GRIS, ['woo_id' => 7609]);
$solo([
    $prod(700, 'Cojín', 'PRD-700000', 'variable'),                 // sin categoría en la tienda
    $prod(701, 'X', 'PRD-701000'),                                  // nombre de una letra
    $prod(702, 'Enlace externo', 'PRD-702000', 'external'),
    $prod(0,   'Sin número', 'PRD-703000'),
    $prod(704, 'Colores sueltos', 'PRD-704000', 'variable'),
], [
    700 => [$var(7501, $SKU_AZ, ['Color' => 'Celeste']),            // se lleva el azul por el código
            $var(7502, '', ['Color' => 'Azul']),                    // el nombre ya no tiene dueño libre
            $var(7503, 'AB?', ['Color' => 'Verde']),                // código que no vale
            $var(7504, '', ['Colour' => 'Rosa', 'Talla' => 'M'])],
    704 => [$var(7541, 'PRD-DUP', ['Color' => 'Rojo']), $var(7542, '', ['Color' => 'Negro']),
            $var(7609, '', ['Color' => 'Blanco'])],                 // enlazada con el gris de OTRO producto
]);
$T['variaciones'][700][] = $var(7505, 'PRD-DUP', ['Color' => 'Lila']);       // código repetido entre productos
$x = $leer_y_plan();
es('un producto sin número en la tienda ni se lee', 0, count(array_filter($x['filas'], fn($f) => $f['nombre'] === 'Sin número')));
ok('todas las peticiones, también las de colores, piden solo lo publicado',
   !array_filter($T['llamadas'], fn($c) => !str_contains($c[0], 'status=publish')));
$c = $item($x['plan'], 700);
$porWoo = fn(int $w) => array_values(array_filter($c['variantes'] ?? [], fn($v) => $v['fila']['woo_id'] === $w))[0] ?? null;
es('la variación con el código del azul se lo lleva', $V_AZ, $porWoo(7501)['id'] ?? null);
es('y la que se llama «Azul» se crea aparte, no se le pega al mismo', 'crear', $porWoo(7502)['accion'] ?? null);
ok('un código que no vale se cambia por uno generado', str_starts_with((string)($porWoo(7503)['sku'] ?? ''), 'PRD-700000-VERDE'),
   (string)($porWoo(7503)['sku'] ?? ''));
es('«Colour» también es el color', 'Rosa', $porWoo(7504)['fila']['color'] ?? null);
es('y lo demás va al modelo', 'M', $porWoo(7504)['fila']['medida'] ?? null);
ok('el mismo código en dos colores de la tienda: no entra ninguno',
   str_contains($motivo($x['plan'], 'Cojín · Lila'), 'mismo código') && str_contains($motivo($x['plan'], 'Colores sueltos · Rojo'), 'mismo código'));
ok('una variación enlazada con un color de otro producto no entra',
   str_contains($motivo($x['plan'], 'Colores sueltos · Blanco'), 'otro producto de aquí'));
ok('un nombre de una letra no entra', str_contains($motivo($x['plan'], 'X'), 'No tiene nombre'));
ok('un enlace a otra web no entra', str_contains($motivo($x['plan'], 'Enlace externo'), 'no se vende suelto'));
ok('sin categoría en la tienda, no se borra la de aquí', !isset($c['cambios']['categoria']));
$GA = tienda_importar(tienda_lectura_nueva($x['filas'], $PERU));
ok('se guarda', $GA['ok'], (string)$GA['error']);
es('el cojín conserva su categoría', 'Hogar', (string) producto_de((int)$az['id'])['categoria']);
es('el azul pasa a llamarse como en la tienda', 'Celeste', (string) valor('SELECT color FROM variantes WHERE id = ?', [$V_AZ]));
$nuevaV = una('SELECT * FROM variantes WHERE woo_id = 7502');
ok('los colores nuevos nacen encendidos', (int)($nuevaV['activo'] ?? 0) === 1);
ok('y sin modelo, el modelo queda vacío de verdad', $nuevaV && array_key_exists('medida', $nuevaV) && $nuevaV['medida'] === null);
$sinCat = una("SELECT * FROM productos WHERE sku = 'PRD-704000'");
ok('un producto nuevo sin categoría queda sin categoría', $sinCat && $sinCat['categoria'] === null);
$ultimaL = una('SELECT * FROM tienda_lecturas WHERE pais_id = ? ORDER BY id DESC LIMIT 1', [$PERU]);
es('la lectura dice quién la leyó', $ADMIN, (int)$ultimaL['usuario_id']);
es('quién la guardó', $ADMIN, (int)$ultimaL['guardada_por']);
ok('y cuándo', (string)$ultimaL['guardada_en'] !== '' && $ultimaL['guardada_en'] !== null);
$bit = una("SELECT * FROM bitacora WHERE accion = 'catalogo.tienda' ORDER BY id DESC LIMIT 1");
ok('la bitácora apunta esa lectura, y quién', (int)($bit['entidad_id'] ?? 0) === (int)$ultimaL['id'] && (int)($bit['usuario_id'] ?? 0) === $ADMIN);

/* El rename que choca con otro color del mismo producto. */
$dosc = producto_guardar(null, ['nombre' => 'Toalla', 'sku' => 'PRD-720000', 'activo' => 1]);
actualizar('productos', (int)$dosc['id'], ['woo_id' => 720]);
variante_guardar((int)$dosc['id'], ['color' => 'Rojo']);
variante_guardar((int)$dosc['id'], ['color' => 'Azul']);
actualizar('variantes', (int) valor("SELECT id FROM variantes WHERE producto_id = ? AND color = 'Rojo'", [(int)$dosc['id']]), ['woo_id' => 7201]);
$solo([$prod(720, 'Toalla', 'PRD-720000', 'variable')], [720 => [$var(7201, '', ['Color' => 'Azul'])]]);
ok('un color que pasaría a llamarse como otro del mismo producto no entra',
   str_contains($motivo($leer_y_plan()['plan'], 'Toalla · Azul'), 'otro color o modelo'));

/* Un color de aquí enlazado a una variación que sigue en la tienda pero no
   entra (sin atributos), y otra variación nueva con el mismo nombre. */
$neg = producto_guardar(null, ['nombre' => 'Mantel', 'sku' => 'PRD-740000', 'activo' => 1]);
actualizar('productos', (int)$neg['id'], ['woo_id' => 740]);
variante_guardar((int)$neg['id'], ['color' => 'Negro']);
actualizar('variantes', (int) valor('SELECT id FROM variantes WHERE producto_id = ?', [(int)$neg['id']]), ['woo_id' => 7401]);
$solo([$prod(740, 'Mantel', 'PRD-740000', 'variable')],
      [740 => [$var(7401, '', []), $var(7402, '', ['Color' => 'Negro'])]]);
$xn = $leer_y_plan();
ok('no se crea un segundo «Negro» junto al que sigue enlazado',
   str_contains($motivo($xn['plan'], 'Mantel · Negro'), 'enlazado a otra variación'), $motivo($xn['plan'], 'Mantel · Negro'));
tienda_importar(tienda_lectura_nueva($xn['filas'], $PERU));
es('el mantel sigue con un solo negro', 1, (int) valor('SELECT COUNT(*) FROM variantes WHERE producto_id = ?', [(int)$neg['id']]));

/* El caso que encontró la verificación: la tienda cambia el negro a «Blanco»
   (que ya existe aquí, así que ese cambio no entra) y trae un «Negro» nuevo. */
$nb = producto_guardar(null, ['nombre' => 'Individual', 'sku' => 'PRD-780000', 'activo' => 1]);
actualizar('productos', (int)$nb['id'], ['woo_id' => 780]);
variante_guardar((int)$nb['id'], ['color' => 'Negro']);
variante_guardar((int)$nb['id'], ['color' => 'Blanco']);
actualizar('variantes', (int) valor("SELECT id FROM variantes WHERE producto_id = ? AND color = 'Negro'", [(int)$nb['id']]), ['woo_id' => 7801]);
$solo([$prod(780, 'Individual', 'PRD-780000', 'variable')],
      [780 => [$var(7801, '', ['Color' => 'Blanco']), $var(7802, '', ['Color' => 'Negro'])]]);
$xb = $leer_y_plan();
ok('el cambio de nombre que choca no entra', str_contains($motivo($xb['plan'], 'Individual · Blanco'), 'otro color o modelo'));
ok('y el «Negro» nuevo tampoco: el de aquí se queda con ese nombre',
   str_contains($motivo($xb['plan'], 'Individual · Negro'), 'Ya hay un color que se llama así'), $motivo($xb['plan'], 'Individual · Negro'));
tienda_importar(tienda_lectura_nueva($xb['filas'], $PERU));
es('NUNCA DOS «NEGRO» EN EL MISMO PRODUCTO', 1,
   (int) valor("SELECT COUNT(*) FROM variantes WHERE producto_id = ? AND color = 'Negro'", [(int)$nb['id']]));

/* Una lectura más nueva de otra persona (dos que leen a la vez). */
$solo([$prod(730, 'Nueva', 'PRD-730000')]);
$xa = $leer_y_plan();
$IDa = tienda_lectura_nueva($xa['filas'], $PERU);
insertar('tienda_lecturas', ['pais_id' => $PERU, 'usuario_id' => $ADMIN, 'filas' => '[]',
                             'resumen' => '{}', 'estado' => 'leida']);   // la otra, que llegó después
$Ga = tienda_importar($IDa);
ok('si otra persona leyó después, esta ya no se guarda', !$Ga['ok'] && str_contains((string)$Ga['error'], 'más nueva'), (string)$Ga['error']);
es('sin crear nada', 0, (int) valor("SELECT COUNT(*) FROM productos WHERE sku = 'PRD-730000'"));

/* El choque de códigos a mitad del guardado: el mensaje dice qué pasó. */
$IDc = tienda_lectura_nueva($xa['filas'], $PERU);
bd()->exec("CREATE TRIGGER choque BEFORE INSERT ON productos WHEN NEW.sku = 'PRD-730000'
            BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: productos.sku'); END");
$Gc = tienda_importar($IDc);
bd()->exec('DROP TRIGGER choque');
ok('un código que otro se quedó a la vez se explica así', str_contains((string)$Gc['error'], 'Otro producto se quedó'), (string)$Gc['error']);

grupo('Módulo 3b · el precio de la web, solo a quien no tiene ninguno');

$conp = fn(array $p, string $precio, string $oferta = '') => $p + ['regular_price' => $precio, 'sale_price' => $oferta];
$conpv = fn(array $v, string $precio) => $v + ['regular_price' => $precio];
$yaConPrecio = producto_guardar(null, ['nombre' => 'Ya con precio', 'sku' => 'PRD-810000', 'activo' => 1]);
precios_guardar((int)$yaConPrecio['id'], null, [['desde' => 1, 'hasta' => '', 'precio' => '15']]);
$yaSinPrecio = producto_guardar(null, ['nombre' => 'Ya sin precio', 'sku' => 'PRD-811000', 'activo' => 1]);
$solo([
    $conp($prod(8120, 'Nuevo con precio', 'PRD-812000'), '699', '599'),
    $conp($prod(8121, 'Nuevo con precio raro', 'PRD-812100'), 'gratis'),
    $conp($prod(8122, 'Nuevo a cero', 'PRD-812200'), '0'),
    $conp($prod(8123, 'Ya con precio', 'PRD-810000'), '999'),
    $conp($prod(8124, 'Ya sin precio', 'PRD-811000'), '888'),
    $prod(8125, 'Nuevo con colores', 'PRD-812500', 'variable'),
    $prod(8126, 'Colores sin precio', 'PRD-812600', 'variable'),
], [
    8125 => [$conpv($var(81251, '', ['Color' => 'Rojo']), '100'), $conpv($var(81252, '', ['Color' => 'Azul']), '100'),
             $conpv($var(81253, '', ['Color' => 'Oro']), '120.50'), $conpv($var(81254, '', ['Color' => 'Gris']), '')],
    8126 => [$var(81261, '', ['Color' => 'Rojo'])],
]);
$xp = $leer_y_plan();
es('los productos nuevos sin precio en la web se cuentan', 3, $xp['plan']['resumen']['sin_precio']);
ok('a la tienda se le pide el precio normal, de productos y de colores',
   (bool) array_filter($T['llamadas'], fn($c) => str_contains(urldecode($c[0]), '/products?') && str_contains(urldecode($c[0]), 'regular_price'))
   && (bool) array_filter($T['llamadas'], fn($c) => str_contains($c[0], '/variations') && str_contains(urldecode($c[0]), 'regular_price')));
es('el tramo nace con el precio NORMAL, no el de oferta', [['woo_var' => null, 'precio' => 69900]], $item($xp['plan'], 8120)['tramos'] ?? null);
es('uno que ya tiene precio aquí no recibe el de la web', [], $item($xp['plan'], 8123)['tramos'] ?? null);
es('uno que ya existía SIN NINGÚN precio sí lo recibe', [['woo_var' => null, 'precio' => 88800]], $item($xp['plan'], 8124)['tramos'] ?? null);
es('y cuenta como actualizado', 'actualizar', $item($xp['plan'], 8124)['accion'] ?? null);
es('la vista previa los cuenta aparte', 1, $xp['plan']['resumen']['precio_web']);
$GP = tienda_importar(tienda_lectura_nueva($xp['filas'], $PERU));
ok('se guarda', $GP['ok'], (string)$GP['error']);
$np = (int) valor("SELECT id FROM productos WHERE sku = 'PRD-812000'");
es('el nuevo ya se puede vender: 1 a más a S/ 699', 69900, precio_de_variante($np, null, 1));
es('y a cualquier cantidad', 69900, precio_de_variante($np, null, 40));
ok('el asesor ya lo encuentra', (bool) array_filter(catalogo_buscar('Nuevo con precio'), fn($x) => $x['sku'] === 'PRD-812000'));
ok('quedó en la bitácora como cualquier precio', (bool) valor("SELECT 1 FROM bitacora WHERE accion = 'catalogo.precios' AND entidad_id = ?", [$np]));
ok('un precio que no se entiende no se inventa: nace sin precio',
   !producto_completo((int) valor("SELECT id FROM productos WHERE sku = 'PRD-812100'")));
ok('uno a cero, tampoco', !producto_completo((int) valor("SELECT id FROM productos WHERE sku = 'PRD-812200'")));
es('EL QUE YA TENÍA PRECIO SE QUEDA CON EL SUYO', 1500, precio_de_variante((int)$yaConPrecio['id'], null, 1));
es('el que estaba sin precio ya se puede vender a S/ 888', 88800, precio_de_variante((int)$yaSinPrecio['id'], null, 1));
$nc = (int) valor("SELECT id FROM productos WHERE sku = 'PRD-812500'");
$vcol = fn(string $c) => (int) valor('SELECT id FROM variantes WHERE producto_id = ? AND color = ?', [$nc, $c]);
es('con colores: el precio que más se repite va al producto', 10000, precio_de_variante($nc, null, 1));
es('el rojo lo hereda', 10000, precio_de_variante($nc, $vcol('Rojo'), 1));
es('el que cuesta otra cosa lleva el suyo', 12050, precio_de_variante($nc, $vcol('Oro'), 1));
es('el que no tiene precio en la web hereda el del producto', 10000, precio_de_variante($nc, $vcol('Gris'), 1));
ok('colores sin ningún precio: nace sin precio', !producto_completo((int) valor("SELECT id FROM productos WHERE sku = 'PRD-812600'")));

/* Un producto con colores que ya estaba sin precio: el color con otro
   precio lo recibe en SU color de aquí, no en uno nuevo. */
$colsin = producto_guardar(null, ['nombre' => 'Colores ya sin precio', 'sku' => 'PRD-813000', 'activo' => 1]);
variante_guardar((int)$colsin['id'], ['color' => 'Rojo']);
variante_guardar((int)$colsin['id'], ['color' => 'Oro']);
$T['productos'][] = $prod(8130, 'Colores ya sin precio', 'PRD-813000', 'variable');
$T['variaciones'][8130] = [$conpv($var(81301, '', ['Color' => 'Rojo']), '50'), $conpv($var(81302, '', ['Color' => 'Oro']), '65')];
$xc = $leer_y_plan();
tienda_importar(tienda_lectura_nueva($xc['filas'], $PERU));
$vOro = (int) valor("SELECT id FROM variantes WHERE producto_id = ? AND color = 'Oro'", [(int)$colsin['id']]);
es('sus colores no se duplican', 2, (int) valor('SELECT COUNT(*) FROM variantes WHERE producto_id = ?', [(int)$colsin['id']]));
es('el oro, que ya estaba, lleva su precio de la web', 6500, precio_de_variante((int)$colsin['id'], $vOro, 1));

/* Uno ya enlazado, con el mismo nombre y sin precio: solo le falta el precio.
   Tiene que salir como «se actualiza», o la vista previa no ofrece guardar. */
$enlsin = producto_guardar(null, ['nombre' => 'Enlazado sin precio', 'sku' => 'PRD-814000', 'activo' => 1]);
actualizar('productos', (int)$enlsin['id'], ['woo_id' => 8140]);
$T['productos'][] = $conp($prod(8140, 'Enlazado sin precio', 'PRD-814000'), '77');
$xe = $leer_y_plan();
es('uno que solo necesita el precio cuenta como actualizado', 'actualizar', $item($xe['plan'], 8140)['accion'] ?? null);
ok('y hay algo que guardar', $xe['plan']['resumen']['actualizar'] > 0);
tienda_importar(tienda_lectura_nueva($xe['filas'], $PERU));
es('y queda con su precio', 7700, precio_de_variante((int)$enlsin['id'], null, 1));

/* Leer otra vez con otro precio en la web no toca el tramo: ya existe. */
$T['productos'][0]['regular_price'] = '750';
$xp2 = $leer_y_plan();
es('leer otra vez no propone cambiar el precio', [], $item($xp2['plan'], 8120)['tramos'] ?? null);
tienda_importar(tienda_lectura_nueva($xp2['filas'], $PERU));
es('y guardar tampoco lo cambia', 69900, precio_de_variante($np, null, 1));

grupo('Módulo 3b · la foto principal, en miniatura');

/* Las miniaturas de las pruebas van a una carpeta propia: el banco no toca
   las fotos de verdad de la instalación donde corre. */
$GLOBALS['__tienda_fotos_dir'] = sys_get_temp_dir() . '/waka-fotos-' . getmypid();
@mkdir($GLOBALS['__tienda_fotos_dir'], 0755, true);
register_shutdown_function(function () {
    foreach (glob($GLOBALS['__tienda_fotos_dir'] . '/*') ?: [] as $f) @unlink($f);
    @rmdir($GLOBALS['__tienda_fotos_dir']);
});
$DIRF = $GLOBALS['__tienda_fotos_dir'];

/* Fotos de verdad, hechas aquí. */
$imagen = function (int $w, int $h, string $tipo = 'jpg', array $color = [200, 30, 30]): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, ...$color));
    ob_start();
    $tipo === 'png' ? imagepng($im, null, 9) : imagejpeg($im, null, 95);
    imagedestroy($im);
    return (string) ob_get_clean();
};
$FOTOS = ['https://img.compraenwaka.test/silla.jpg' => $imagen(1600, 1600),
          'https://img.compraenwaka.test/mesa.png'  => $imagen(2000, 1000, 'png', [20, 90, 200]),
          'https://img.compraenwaka.test/nueva.jpg' => $imagen(900, 900, 'jpg', [10, 160, 60]),
          'https://img.compraenwaka.test/rota.jpg'  => 'esto no es una imagen',
          'https://img.compraenwaka.test/enorme.png' => $imagen(5000, 4000, 'png')];
$PEDIDAS = [];
$GLOBALS['__tienda_fotos'] = function (string $src) use (&$FOTOS, &$PEDIDAS) {
    $PEDIDAS[] = $src;
    return $FOTOS[$src] ?? null;
};
$conf = fn(array $p, int $id, string $src) => $p + ['images' => [['id' => $id, 'src' => $src]]];
$en_disco = fn() => count(glob($DIRF . '/w*.jpg') ?: []);

es('la carpeta de las pruebas es la suya', $DIRF, tienda_fotos_dir());
es('una foto por http no se baja', null, tienda_foto_de(['images' => [['id' => 1, 'src' => 'http://x.test/a.jpg']]]));
es('ni una sin número', null, tienda_foto_de(['images' => [['id' => 0, 'src' => 'https://x.test/a.jpg']]]));
es('ni una con usuario en la dirección', null, tienda_foto_de(['images' => [['id' => 1, 'src' => 'https://yo@x.test/a.jpg']]]));
es('una dirección de foto rara no se enseña', '', producto_foto_url('../../app/config.jpg'));
es('LA RED INTERNA NO: 127.0.0.1', null, tienda_foto_destino('https://127.0.0.1/a.jpg'));
es('ni 10.x', null, tienda_foto_destino('https://10.0.0.5/a.jpg'));
es('ni 192.168.x', null, tienda_foto_destino('https://192.168.1.10:8443/a.jpg'));
es('ni localhost', null, tienda_foto_destino('https://localhost/a.jpg'));
es('una ip pública sí, y se fija para la descarga', ['93.184.216.34', 443, '93.184.216.34'],
   tienda_foto_destino('https://93.184.216.34/a.jpg'));

$solo([
    $conf($prod(9001, 'Silla con foto', 'PRD-900100') + ['regular_price' => '350'], 501, 'https://img.compraenwaka.test/silla.jpg'),
    $conf($prod(9002, 'Mesa con foto', 'PRD-900200'), 502, 'https://img.compraenwaka.test/mesa.png'),
    $conf($prod(9003, 'Foto rota', 'PRD-900300'), 503, 'https://img.compraenwaka.test/rota.jpg'),
    $conf($prod(9004, 'Foto caída', 'PRD-900400'), 504, 'https://img.compraenwaka.test/no-esta.jpg'),
    $conf($prod(9006, 'Foto enorme', 'PRD-900600'), 506, 'https://img.compraenwaka.test/enorme.png'),
    $conf($prod(9007, 'Misma foto que la silla', 'PRD-900700'), 501, 'https://img.compraenwaka.test/silla.jpg'),
]);
$xf = $leer_y_plan();
ok('a la tienda se le pide la foto', str_contains(urldecode((string)$T['llamadas'][0][0]), 'images'));
es('la vista previa cuenta las fotos que se traen', 6, $xf['plan']['resumen']['fotos']);
es('leer no baja ninguna foto', [], $PEDIDAS);
$GF = tienda_importar(tienda_lectura_nueva($xf['filas'], $PERU));
ok('se guarda', $GF['ok'], (string)$GF['error']);
es('cada dirección se pide una sola vez, aunque la compartan dos productos', 5, count($PEDIDAS));
es('entraron las tres que sirven', 3, (int)$GF['resumen']['fotos_ok']);
es('dos no sirven: la que no es imagen y la enorme', 2, (int)$GF['resumen']['fotos_malas']);
$silla = una("SELECT * FROM productos WHERE sku = 'PRD-900100'");
ok('la silla tiene su miniatura', producto_foto_url($silla['foto'] ?? null) !== '', (string)($silla['foto'] ?? ''));
es('apuntando de qué foto de la tienda salió', 501, (int)($silla['foto_woo'] ?? 0));
$ruta_silla = $DIRF . '/' . $silla['foto'];
$tam = @getimagesize($ruta_silla);
es('la miniatura es de 240 × 240', [240, 240], [$tam[0] ?? 0, $tam[1] ?? 0]);
ok('y pesa poco (menos de 25 KB)', filesize($ruta_silla) < 25 * 1024, (string) filesize($ruta_silla));
$mesa = una("SELECT * FROM productos WHERE sku = 'PRD-900200'");
$im_mesa = imagecreatefromjpeg($DIRF . '/' . $mesa['foto']);
$arriba = imagecolorsforindex($im_mesa, imagecolorat($im_mesa, 120, 5));
$centro = imagecolorsforindex($im_mesa, imagecolorat($im_mesa, 120, 120));
ok('una foto ancha entra ENTERA, con blanco arriba y abajo', $arriba['red'] > 240 && $arriba['blue'] > 240 && $centro['blue'] > 150 && $centro['red'] < 80);
$rota = una("SELECT * FROM productos WHERE sku = 'PRD-900300'");
ok('la que no es imagen no deja foto, pero queda apuntada como intentada', $rota['foto'] === null && (int)$rota['foto_woo'] === 503);
ok('la enorme, igual', una("SELECT foto FROM productos WHERE sku = 'PRD-900600'")['foto'] === null);
es('la que no bajó no queda apuntada: se vuelve a intentar', null, una("SELECT foto_woo FROM productos WHERE sku = 'PRD-900400'")['foto_woo']);
$misma = una("SELECT * FROM productos WHERE sku = 'PRD-900700'");
es('el que comparte foto con la silla tiene la misma miniatura', $silla['foto'], $misma['foto']);
es('en el disco quedan solo las dos distintas', 2, $en_disco());
es('el asesor ve la foto en el buscador', producto_foto_url($silla['foto']),
   array_values(array_filter(catalogo_buscar('Silla con foto'), fn($x) => $x['sku'] === 'PRD-900100'))[0]['foto'] ?? null);
es('las fotos de varios productos, de una vez', [(int)$silla['id'] => $silla['foto'], (int)$mesa['id'] => $mesa['foto']],
   producto_fotos([(int)$silla['id'], (int)$mesa['id'], 0, 999999]));

/* Leer otra vez: solo se pide la que no llegó. Las rotas no se piden más. */
$PEDIDAS = [];
$xf2 = $leer_y_plan();
es('la segunda lectura solo pide la que no llegó', 1, $xf2['plan']['resumen']['fotos']);
ok('y una foto no cuenta como cambio del producto', ($item($xf2['plan'], 9001)['accion'] ?? '') === 'igual');
$FOTOS['https://img.compraenwaka.test/no-esta.jpg'] = $imagen(500, 500);
tienda_importar(tienda_lectura_nueva($xf2['filas'], $PERU));
ok('la que no había bajado baja ahora', producto_foto_url(una("SELECT foto FROM productos WHERE sku = 'PRD-900400'")['foto']) !== '');
es('sin volver a bajar ni la silla ni las rotas', ['https://img.compraenwaka.test/no-esta.jpg'], $PEDIDAS);
$x_al_dia = $leer_y_plan();
es('con todo traído, no queda ninguna foto por traer', 0, $x_al_dia['plan']['resumen']['fotos']);

/* Marketing cambia la foto de la silla: se baja la nueva. La vieja sigue en
   el disco porque el otro producto la usa. */
$T['productos'][0]['images'] = [['id' => 601, 'src' => 'https://img.compraenwaka.test/nueva.jpg']];
$xf3 = $leer_y_plan();
es('una foto cambiada en la tienda se vuelve a traer', 1, $xf3['plan']['resumen']['fotos']);
$vieja = $silla['foto'];
tienda_importar(tienda_lectura_nueva($xf3['filas'], $PERU));
$silla2 = una("SELECT * FROM productos WHERE sku = 'PRD-900100'");
ok('la silla tiene la foto nueva', $silla2['foto'] !== $vieja && (int)$silla2['foto_woo'] === 601);
ok('LA VIEJA SIGUE: la usa el otro producto', is_file($DIRF . '/' . $vieja));
/* Y cuando el otro también cambia, la vieja ya no la usa nadie y se va. */
$T['productos'][5]['images'] = [['id' => 601, 'src' => 'https://img.compraenwaka.test/nueva.jpg']];
tienda_importar(tienda_lectura_nueva($leer_y_plan()['filas'], $PERU));
ok('cuando ya nadie la usa, se borra', !is_file($DIRF . '/' . $vieja));

/* Un guardado que se va a negar no baja nada. */
$T['productos'][] = $conf($prod(9005, 'Foto de un guardado negado', 'PRD-900500'), 505, 'https://img.compraenwaka.test/silla.jpg');
$xf4 = $leer_y_plan();
$ID_F4 = tienda_lectura_nueva($xf4['filas'], $PERU);
producto_guardar(null, ['nombre' => 'Se adelantó', 'sku' => 'PRD-900500', 'activo' => 1]);
$PEDIDAS = [];
$d_antes = $en_disco();
ok('el guardado se niega', !tienda_importar($ID_F4)['ok']);
es('SIN BAJAR NINGUNA FOTO', [], $PEDIDAS);
es('ni dejar ninguna en el disco', $d_antes, $en_disco());

/* Como mucho 40 por guardado; las demás, en la siguiente lectura. */
$muchas = [];
for ($i = 1; $i <= TIENDA_FOTOS_POR_VEZ + 5; $i++) {
    $src = 'https://img.compraenwaka.test/m' . $i . '.jpg';
    $FOTOS[$src] = $FOTOS['https://img.compraenwaka.test/nueva.jpg'];
    $muchas[] = $conf($prod(95000 + $i, 'Con foto ' . $i, 'PRD-95' . str_pad((string)$i, 4, '0', STR_PAD_LEFT)), 70000 + $i, $src);
}
$solo($muchas);
$PEDIDAS = [];
$xm = $leer_y_plan();
es('la vista previa las cuenta todas', TIENDA_FOTOS_POR_VEZ + 5, $xm['plan']['resumen']['fotos']);
$GM = tienda_importar(tienda_lectura_nueva($xm['filas'], $PERU));
es('en un guardado se bajan como mucho 40', TIENDA_FOTOS_POR_VEZ, count($PEDIDAS));
es('y se dice cuántas entraron', TIENDA_FOTOS_POR_VEZ, (int)$GM['resumen']['fotos_ok']);
es('la siguiente lectura pide las que faltan', 5, $leer_y_plan()['plan']['resumen']['fotos']);

/* Las huérfanas: una de hace dos horas que nadie nombra se va; una recién
   bajada (puede ser de un guardado en curso) y una que se usa, se quedan. */
$huerf = 'w123-aaaaaaaa.jpg';
file_put_contents($DIRF . '/' . $huerf, 'x');
touch($DIRF . '/' . $huerf, time() - 7200);
$reciente = 'w124-bbbbbbbb.jpg';
file_put_contents($DIRF . '/' . $reciente, 'x');
$usada = (string) valor("SELECT foto FROM productos WHERE sku = 'PRD-900200'");
touch($DIRF . '/' . $usada, time() - 7200);
es('el barrido se lleva una sola', 1, tienda_fotos_barrer());
ok('la huérfana vieja', !is_file($DIRF . '/' . $huerf));
ok('no la recién bajada', is_file($DIRF . '/' . $reciente));
ok('ni la que usa un producto', is_file($DIRF . '/' . $usada));
@unlink($DIRF . '/' . $reciente);

/* Una foto de celular «acostada»: 400 × 200 con la marca de girar 90°. */
if (function_exists('exif_read_data')) {
    $acostada = $imagen(400, 200, 'jpg', [30, 30, 200]);
    $tiff = "II\x2A\x00\x08\x00\x00\x00" . "\x01\x00" . "\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00" . "\x00\x00\x00\x00";
    $app1 = "Exif\x00\x00" . $tiff;
    $acostada = substr($acostada, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($acostada, 2);
    $f_ac = tienda_foto_guardar($acostada, 777);
    $im_ac = imagecreatefromjpeg($DIRF . '/' . $f_ac);
    $lado_ac = imagecolorsforindex($im_ac, imagecolorat($im_ac, 20, 120));
    $medio_ac = imagecolorsforindex($im_ac, imagecolorat($im_ac, 120, 120));
    ok('una foto acostada se endereza: queda de pie, con blanco a los lados',
       $lado_ac['red'] > 240 && $medio_ac['blue'] > 150 && $medio_ac['red'] < 80);
    @unlink($DIRF . '/' . $f_ac);
}

/* El borrón que se lleva el catálogo se lleva las miniaturas. */
require_once HUB_APP . '/nucleo/limpieza.php';
ok('el borrón del catálogo vacía la carpeta de miniaturas', limpieza_borrar_fotos_catalogo() >= 3 && $en_disco() === 0);
unset($GLOBALS['__tienda_fotos']);

grupo('Módulo 3b.4 · las fotos nunca rompen el guardado');

/* En el 3b.3, un fallo al bajar fotos (una función que el hosting no deja
   usar) daba «Algo se rompió» y no se guardaba nada. */
$GLOBALS['__tienda_fotos_dir'] = sys_get_temp_dir() . '/waka-fotos4-' . getmypid();
@mkdir($GLOBALS['__tienda_fotos_dir'], 0755, true);
register_shutdown_function(function () {
    foreach (glob(sys_get_temp_dir() . '/waka-fotos4-' . getmypid() . '/*') ?: [] as $f) @unlink($f);
    @rmdir(sys_get_temp_dir() . '/waka-fotos4-' . getmypid());
});
$DIRF4 = $GLOBALS['__tienda_fotos_dir'];
$jpg4 = $imagen(300, 300);
$GLOBALS['__tienda_fotos'] = function (string $src) { throw new Error('Call to undefined function gethostbynamel()'); };
$solo([$conf($prod(9801, 'Foto que revienta', 'PRD-980100') + ['regular_price' => '50'], 8801, 'https://img.compraenwaka.test/r.jpg')]);
$xr = $leer_y_plan();
es('la vista previa ofrece la foto', 1, $xr['plan']['resumen']['fotos']);
$GR = null;
try { $GR = tienda_importar(tienda_lectura_nueva($xr['filas'], $PERU)); } catch (Throwable $ex) { $GR = ['ok' => false, 'error' => 'REVENTÓ: ' . $ex->getMessage()]; }
ok('SI BAJAR LAS FOTOS FALLA, EL CATÁLOGO SE GUARDA IGUAL', (bool)($GR['ok'] ?? false), (string)($GR['error'] ?? ''));
$pr = una("SELECT * FROM productos WHERE sku = 'PRD-980100'");
ok('el producto entró', $pr !== null);
es('con su precio de la web', 5000, $pr ? precio_de_variante((int)$pr['id'], null, 1) : null);
ok('sin foto, y sin marcarla como intentada: se vuelve a pedir', $pr && $pr['foto'] === null && $pr['foto_woo'] === null);
es('se cuentan cero fotos', 0, (int)($GR['resumen']['fotos_ok'] ?? -1));
es('y la siguiente lectura la vuelve a ofrecer', 1, $leer_y_plan()['plan']['resumen']['fotos']);

/* Falla a mitad: lo que ya llegó se aprovecha; el resto, otra vez. */
$pedidas4 = 0;
$GLOBALS['__tienda_fotos'] = function (string $src) use (&$pedidas4, $jpg4) {
    if (++$pedidas4 > 7) throw new Error('se cayó a mitad');
    return $jpg4;
};
$ocho = [];
for ($i = 1; $i <= 14; $i++) {
    $ocho[] = $conf($prod(9810 + $i, 'Mitad ' . $i, 'PRD-9810' . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '0'), 8810 + $i, 'https://img.compraenwaka.test/mitad' . $i . '.jpg');
}
$solo($ocho);
$GM4 = tienda_importar(tienda_lectura_nueva($leer_y_plan()['filas'], $PERU));
ok('con un fallo en la segunda tanda, se guarda', $GM4['ok'], (string)$GM4['error']);
es('entran las siete que llegaron, también la que llegó en la tanda que falló', 7, (int)$GM4['resumen']['fotos_ok']);
es('después del fallo no se pide ninguna más', 8, $pedidas4);
es('las otras no se marcan: se vuelven a pedir', 7,
   (int) valor("SELECT COUNT(*) FROM productos WHERE sku LIKE 'PRD-9810%' AND foto_woo IS NULL"));
es('y en el disco quedan justo las siete', 7, count(glob($DIRF4 . '/w*.jpg') ?: []));

/* LA CONEXIÓN QUE SE DUERME mientras bajan las fotos: se abre otra. */
class PdoDormida extends PdoPruebas
{
    public int $pings = 0;
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        $this->pings++;
        throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
    }
}
$PDO_BUENA = $GLOBALS['__pdo_instalacion'];
$reabiertas = 0;
$GLOBALS['__bd_reabrir'] = function () use (&$reabiertas, $PDO_BUENA) { $reabiertas++; $GLOBALS['__pdo_instalacion'] = $PDO_BUENA; };
$dormida = new PdoDormida('sqlite::memory:');
$GLOBALS['__pdo_instalacion'] = $dormida;
bd_despertar();
ok('una conexión que no contesta se cambia por otra', bd() === $PDO_BUENA && $reabiertas === 1 && $dormida->pings === 1);
bd_despertar();
es('una que contesta se queda como está', 1, $reabiertas);
ok('y sigue siendo la misma', bd() === $PDO_BUENA);
$GLOBALS['__pdo_instalacion'] = $dormida;
$dormida->beginTransaction();
bd_despertar();
ok('dentro de una transacción no se toca', bd() === $dormida && $dormida->pings === 1);
$dormida->rollBack();
$GLOBALS['__pdo_instalacion'] = $PDO_BUENA;

/* Y de punta a punta: mientras bajan, la conexión se duerme. */
$GLOBALS['__tienda_fotos'] = function (string $src) use ($jpg4, $dormida) {
    $GLOBALS['__pdo_instalacion'] = $dormida;
    return $jpg4;
};
$solo([$conf($prod(9830, 'Se durmió', 'PRD-983000'), 8830, 'https://img.compraenwaka.test/dormida.jpg')]);
$reabiertas = 0;
$GD4 = tienda_importar(tienda_lectura_nueva($leer_y_plan()['filas'], $PERU));
$GLOBALS['__pdo_instalacion'] = $PDO_BUENA;
ok('si la base de datos se durmió bajando fotos, se guarda igual', $GD4['ok'] && $reabiertas === 1, (string)$GD4['error']);
ok('con su foto', (string) valor("SELECT foto FROM productos WHERE sku = 'PRD-983000'") !== '');
unset($GLOBALS['__bd_reabrir']);

/* Sin lo necesario para hacer miniaturas, no se ofrecen fotos. */
$GLOBALS['__tienda_fotos_posible'] = false;
$solo([$conf($prod(9840, 'Sin GD', 'PRD-984000'), 8840, 'https://img.compraenwaka.test/sin-gd.jpg')]);
$pedidas4 = 0;
$GLOBALS['__tienda_fotos'] = function (string $src) use (&$pedidas4, $jpg4) { $pedidas4++; return $jpg4; };
$xg = $leer_y_plan();
es('si el servidor no puede hacer miniaturas, no se ofrece ninguna foto', 0, $xg['plan']['resumen']['fotos']);
tienda_importar(tienda_lectura_nueva($xg['filas'], $PERU));
es('ni se pide ninguna', 0, $pedidas4);
unset($GLOBALS['__tienda_fotos_posible']);
ok('de normal, en este servidor sí se puede', tienda_fotos_posible());

/* A dónde se conecta: con el nombre ya resuelto. */
$GLOBALS['__tienda_resolver'] = fn(string $h) => ['10.0.0.9'];
es('un nombre que apunta a la red interna no se baja', null, tienda_foto_destino('https://tienda.test/a.jpg'));
$GLOBALS['__tienda_resolver'] = fn(string $h) => ['10.0.0.9', '93.184.216.34'];
es('entre varias ips, se usa la pública', ['tienda.test', 443, '93.184.216.34'], tienda_foto_destino('https://tienda.test/a.jpg'));
$GLOBALS['__tienda_resolver'] = fn(string $h) => [];
es('un nombre que no contesta no se baja, pero no queda como mala: se vuelve a intentar', false, tienda_foto_destino('https://no-existe.test/a.jpg'));
unset($GLOBALS['__tienda_resolver']);
ok('sin gancho, localhost se resuelve y se niega', tienda_resolver('localhost') !== [] && tienda_foto_destino('https://localhost/a.jpg') === null);

es('la red compartida del proveedor (100.64/10) tampoco', false, tienda_ip_publica('100.64.12.1'));
es('ni su último tramo', false, tienda_ip_publica('100.127.255.254'));
es('justo fuera de ella, sí', true, tienda_ip_publica('100.128.0.1'));
es('ni 169.254 (la del propio servidor en la nube)', false, tienda_ip_publica('169.254.169.254'));

/* UNA FOTO QUE NO CABE EN LA MEMORIA DEL SERVIDOR no se abre: pasarse de la
   memoria mata la página entera, sin aviso. */
$GLOBALS['__tienda_memoria_libre'] = 1024 * 1024;
es('sin memoria para abrirla, no se abre: se pide otra vez (no queda como mala)', 'otra-vez', tienda_foto_guardar($imagen(1000, 1000), 1));
$GLOBALS['__tienda_memoria_libre'] = 64 * 1024 * 1024;
$f_mem = tienda_foto_guardar($imagen(1000, 1000), 2);
ok('con memoria de sobra, sí', foto_producto_valida($f_mem), $f_mem);
@unlink($DIRF4 . '/' . $f_mem);
es('1000 × 1000 pide unos 13 MB: con 12 no alcanza', 'otra-vez', (function () use ($imagen) {
    $GLOBALS['__tienda_memoria_libre'] = 12 * 1024 * 1024;
    return tienda_foto_guardar($imagen(1000, 1000), 3);
})());
unset($GLOBALS['__tienda_memoria_libre']);
ok('la memoria libre se lee del servidor', tienda_memoria_libre() > 0);

/* LAS QUE NUNCA BAJAN NO TAPAN A LAS DEMÁS: 40 fotos que la tienda nunca
   entrega y 5 buenas. Se piden en desorden, así que las buenas llegan. */
$pedidas_b = [];
$GLOBALS['__tienda_fotos'] = function (string $src) use (&$pedidas_b, $jpg4) {
    $pedidas_b[] = $src;
    return str_contains($src, '/buena') ? $jpg4 : null;
};
$tapan = [];
for ($i = 1; $i <= 45; $i++) {
    $nom = $i <= 40 ? 'nunca' . $i : 'buena' . $i;
    $tapan[] = $conf($prod(9900 + $i, 'Tapa ' . $i, 'PRD-99' . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . '0'), 8900 + $i,
                     'https://img.compraenwaka.test/' . $nom . '.jpg');
}
$solo($tapan);
$GT = tienda_importar(tienda_lectura_nueva($leer_y_plan()['filas'], $PERU));
es('se piden 40', TIENDA_FOTOS_POR_VEZ, count($pedidas_b));
ok('y entre ellas alguna de las buenas, aunque vayan al final', (int)$GT['resumen']['fotos_ok'] >= 1, json_encode($GT['resumen']));

/* Lo que contesta la tienda por una foto. */
$o4 = ['ok' => [], 'malas' => []]; $b4 = [];
tienda_foto_llegada($o4, $b4, 1, 200, 'bytes');
tienda_foto_llegada($o4, $b4, 2, 404, 'no');
tienda_foto_llegada($o4, $b4, 3, 500, 'caída');
tienda_foto_llegada($o4, $b4, 4, 200, '');
tienda_foto_llegada($o4, $b4, 5, 301, '');
tienda_foto_llegada($o4, $b4, 6, 0, false);
es('un 200 con algo dentro se aprovecha', [1 => 'bytes'], $b4);
es('un 404 o una redirección no sirven; un 500, vacío o sin respuesta se vuelven a pedir', [2 => true, 5 => true], $o4['malas']);

/* Algo falla ANTES de bajar (barrer las viejas): el catálogo se guarda igual. */
class PdoBarreRota extends PdoPruebas
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'WHERE foto IS NOT NULL')) throw new Error('barrer reventó');
        return parent::prepare($query, $options);
    }
}
$archivo_bd = (string) $PDO_BUENA->query('PRAGMA database_list')->fetch()['file'];
$rota_bd = new PdoBarreRota('sqlite:' . $archivo_bd, null, null,
                            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$rota_bd->exec('PRAGMA foreign_keys = ON');
$GLOBALS['__tienda_fotos'] = fn(string $src) => $jpg4;
$solo([$conf($prod(9845, 'Barrer revienta', 'PRD-984500') + ['regular_price' => '10'], 8845, 'https://img.compraenwaka.test/barre.jpg')]);
$xbarre = $leer_y_plan();
$ID_BARRE = tienda_lectura_nueva($xbarre['filas'], $PERU);
$GLOBALS['__pdo_instalacion'] = $rota_bd;
try { $GB = tienda_importar($ID_BARRE); } catch (Throwable $ex) { $GB = ['ok' => false, 'error' => 'REVENTÓ: ' . $ex->getMessage()]; }
$GLOBALS['__pdo_instalacion'] = $PDO_BUENA;
ok('si algo de las fotos falla antes de bajar, se guarda igual', $GB['ok'], (string)$GB['error']);
ok('el producto entró, sin foto', (int) valor("SELECT COUNT(*) FROM productos WHERE sku = 'PRD-984500' AND foto IS NULL") === 1);

/* Un guardado que falla DESPUÉS de bajar las fotos no deja miniaturas sueltas. */
$GLOBALS['__tienda_fotos'] = fn(string $src) => $jpg4;
$solo([$conf($prod(9850, 'Falla al guardar', 'PRD-985000'), 8850, 'https://img.compraenwaka.test/falla.jpg')]);
$xfalla = $leer_y_plan();
$ID_FALLA = tienda_lectura_nueva($xfalla['filas'], $PERU);
q("CREATE TRIGGER falla_foto BEFORE INSERT ON productos WHEN NEW.sku = 'PRD-985000' BEGIN SELECT RAISE(ABORT, 'no such table: nada'); END");
$d4 = count(glob($DIRF4 . '/w*.jpg') ?: []);
$GFALLA = tienda_importar($ID_FALLA);
q('DROP TRIGGER falla_foto');
ok('el guardado que falla lo dice', !$GFALLA['ok'] && str_contains($GFALLA['error'], 'No se cambió nada'), (string)$GFALLA['error']);
es('y la foto recién bajada no se queda en el disco', $d4, count(glob($DIRF4 . '/w*.jpg') ?: []));

/* Las recién bajadas de un guardado que falló se borran sin preguntar a la base. */
file_put_contents($DIRF4 . '/w1-cccccccc.jpg', 'x');
file_put_contents($DIRF4 . '/nota.txt', 'x');
tienda_fotos_quitar_sin_mirar(['w1-cccccccc.jpg', '../nota.txt', 'nota.txt']);
ok('se borra la recién bajada', !is_file($DIRF4 . '/w1-cccccccc.jpg'));
ok('y nada con otro nombre', is_file($DIRF4 . '/nota.txt'));
@unlink($DIRF4 . '/nota.txt');
unset($GLOBALS['__tienda_fotos']);
$GLOBALS['__tienda_fotos_dir'] = $DIRF;

grupo('3b.5 · traer las fotos que faltan, sin volver a leer');
$GLOBALS['__tienda_fotos_dir'] = $DIRF4;
$jpg5 = $imagen(300, 300);
$llegan = false;
$pedidas5 = [];
$GLOBALS['__tienda_fotos'] = function (string $src) use (&$llegan, &$pedidas5, $jpg5) {
    $pedidas5[] = $src;
    if (str_contains($src, 'rota5')) return 'no es una foto';
    if (str_contains($src, 'mala-b')) return $llegan ? 'no es una foto' : null;
    return $llegan ? $jpg5 : null;
};
$cin = [];
for ($i = 1; $i <= 45; $i++) {
    $cin[] = $conf($prod(9950 + $i, 'Cinco ' . $i, 'PRD-995' . str_pad((string)$i, 3, '0', STR_PAD_LEFT)), 8950 + $i,
                   'https://img.compraenwaka.test/' . ($i === 2 ? 'rota5' : 'c' . $i) . '.jpg');
}
/* Dos productos con la MISMA foto de la tienda. */
$cin[] = $conf($prod(9999, 'Cinco gemelo', 'PRD-995999'), 8951, 'https://img.compraenwaka.test/c1.jpg');
$solo($cin);
/* En orden, para que la rota caiga seguro entre las 40 de este guardado. */
$GLOBALS['__tienda_fotos_en_orden'] = true;
$G5 = tienda_importar(tienda_lectura_nueva($leer_y_plan()['filas'], $PERU));
unset($GLOBALS['__tienda_fotos_en_orden']);
ok('se guarda la lectura sin que llegue ninguna foto', $G5['ok'] && (int)$G5['resumen']['fotos_ok'] === 0, (string)$G5['error']);
$pend5 = tienda_fotos_pendientes($PERU);
es('quedan pendientes las 44 que no llegaron (la rota ya quedó marcada)', 44, count($pend5));
es('la foto compartida cuenta una vez, con sus dos productos', 2, count($pend5[8951]['productos'] ?? []));
es('una lectura sin guardar no cuenta: sale de la última GUARDADA', 44, (function () use ($leer_y_plan, $PERU, &$T) {
    $antes = $T['productos'];
    foreach ($T['productos'] as &$pp) $pp['images'] = [];      // la tienda, sin fotos: esta lectura no pediría ninguna
    unset($pp);
    tienda_lectura_nueva($leer_y_plan()['filas'], $PERU);
    $T['productos'] = $antes;
    return count(tienda_fotos_pendientes($PERU));
})());

$llegan = true;
$pedidas5 = [];
$llamadas_antes = count($T['llamadas'] ?? []);
$T5 = tienda_traer_fotos($PERU);
ok('trae una tanda', $T5['ok'], (string)$T5['error']);
es('de 40', TIENDA_FOTOS_POR_VEZ, $T5['traidas'] + $T5['malas']);
es('SIN VOLVER A LEER LA TIENDA', $llamadas_antes, count($T['llamadas'] ?? []));
es('y sin pedir la rota otra vez', 0, count(array_filter($pedidas5, fn($x) => str_contains($x, 'rota5'))));
es('y dice cuántas quedan', 4, $T5['quedan']);
$T5b = tienda_traer_fotos($PERU);
es('la segunda vuelta trae las que faltaban', 4, $T5b['traidas']);
es('y ya no queda ninguna', 0, $T5b['quedan']);
$g1 = (string) valor("SELECT foto FROM productos WHERE sku = 'PRD-995001'");
es('los dos productos con la misma foto la tienen', $g1, (string) valor("SELECT foto FROM productos WHERE sku = 'PRD-995999'"));
ok('y es una miniatura de verdad', foto_producto_valida($g1) && is_file($DIRF4 . '/' . $g1));
es('una vuelta más no hace nada', ['ok' => true, 'error' => '', 'traidas' => 0, 'malas' => 0, 'quedan' => 0], tienda_traer_fotos($PERU));

/* Una foto que llega mala en esta vuelta queda apuntada: no se pide más. */
$T['productos'][2]['images'] = [['id' => 7003, 'src' => 'https://img.compraenwaka.test/mala-b.jpg']];
$llegan = false;
tienda_importar(tienda_lectura_nueva($leer_y_plan()['filas'], $PERU));
$llegan = true;
$TM = tienda_traer_fotos($PERU);
es('una foto que no sirve se cuenta como mala', 1, $TM['malas']);
es('Y QUEDA APUNTADA: no se vuelve a pedir', 7003, (int) valor('SELECT foto_woo FROM productos WHERE woo_id = 9953'));
es('ni cuenta como que falta', 0, $TM['quedan']);

/* Si mientras bajaba alguien guardó otra foto en ese producto, esa manda. */
$T['productos'][3]['images'] = [['id' => 7004, 'src' => 'https://img.compraenwaka.test/c4-nueva.jpg']];
$llegan = false;
tienda_importar(tienda_lectura_nueva($leer_y_plan()['filas'], $PERU));
$GLOBALS['__tienda_fotos'] = function (string $src) use ($jpg5) {
    /* «Otra pestaña» guarda la misma foto en el producto a media descarga. */
    q("UPDATE productos SET foto = 'w7004-cafecafe.jpg', foto_woo = 7004 WHERE woo_id = 9954");
    return $jpg5;
};
$TR = tienda_traer_fotos($PERU);
es('LO QUE OTRO GUARDÓ MIENTRAS TANTO NO SE PISA', 'w7004-cafecafe.jpg', (string) valor('SELECT foto FROM productos WHERE woo_id = 9954'));
es('y la bajada que nadie usó no se queda en el disco', 0, count(array_filter(glob($DIRF4 . '/w7004-*.jpg') ?: [],
   fn($f) => basename($f) !== 'w7004-cafecafe.jpg')));
q("UPDATE productos SET foto = NULL WHERE woo_id = 9954");
$GLOBALS['__tienda_fotos'] = function (string $src) use (&$llegan, &$pedidas5, $jpg5) {
    $pedidas5[] = $src;
    if (str_contains($src, 'rota5')) return 'no es una foto';
    if (str_contains($src, 'mala-b')) return $llegan ? 'no es una foto' : null;
    return $llegan ? $jpg5 : null;
};

/* Si la tienda no da las fotos ahora, la vuelta dice que no trajo nada: la
   pantalla para en vez de dar vueltas para siempre. */
$T['productos'][0]['images'] = [['id' => 7001, 'src' => 'https://img.compraenwaka.test/nueva-c1.jpg']];
$llegan = false;
tienda_importar(tienda_lectura_nueva($leer_y_plan()['filas'], $PERU));
$T5c = tienda_traer_fotos($PERU);
ok('si no llega ninguna, la vuelta no trae nada y lo dice', $T5c['ok'] && $T5c['traidas'] + $T5c['malas'] === 0 && $T5c['quedan'] === 1,
   json_encode($T5c));
$llegan = true;
$T5d = tienda_traer_fotos($PERU);
es('cuando llega, la foto nueva reemplaza a la vieja', 1, $T5d['traidas']);
ok('y la vieja se va del disco si nadie más la usa… pero el gemelo la usa: se queda', is_file($DIRF4 . '/' . $g1));

/* Otro país no trae fotos de esta tienda. */
guardar_ajuste('woo_pais', (string)$MX); ajustes_olvidar();
ok('si la tienda es de otro país, no trae', !tienda_traer_fotos($PERU)['ok']);
guardar_ajuste('woo_pais', (string)$PERU); ajustes_olvidar();
unset($GLOBALS['__tienda_fotos']);
$GLOBALS['__tienda_fotos_dir'] = $DIRF;

grupo('3b.5 · el stock de la web');

es('con inventario: la cantidad', ['cantidad' => 5, 'estado' => 'instock'],
   tienda_stock_de(['manage_stock' => true, 'stock_quantity' => 5, 'stock_status' => 'instock']));
es('sin inventario: solo el estado', ['cantidad' => null, 'estado' => 'instock'],
   tienda_stock_de(['manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock']));
es('una variación que hereda el del producto, marcada como compartida', ['cantidad' => 9, 'estado' => 'instock', 'compartido' => true],
   tienda_stock_de(['manage_stock' => 'parent', 'stock_quantity' => null, 'stock_status' => 'instock'],
                   ['cantidad' => 9, 'estado' => 'instock']));
es('si la tienda no manda stock, no se sabe nada', null, tienda_stock_de(['id' => 1]));
es('un estado raro cuenta como «hay»', 'instock', tienda_stock_de(['manage_stock' => false, 'stock_status' => 'raro'])['estado']);
es('«gestionar» también puede venir como 1', 4, tienda_stock_de(['manage_stock' => 1, 'stock_quantity' => 4, 'stock_status' => 'instock'])['cantidad']);
es('o como «1»', 4, tienda_stock_de(['manage_stock' => '1', 'stock_quantity' => '4', 'stock_status' => 'instock'])['cantidad']);
es('una cantidad que no es número no vale', null, tienda_stock_de(['manage_stock' => true, 'stock_quantity' => 'x', 'stock_status' => 'instock'])['cantidad']);
es('agotado', ['texto' => 'Agotado', 'tono' => 'rojo'], stock_web_texto(['cantidad' => 0, 'estado' => 'outofstock']));
es('cero unidades es agotado aunque diga «instock»', 'Agotado', stock_web_texto(['cantidad' => 0, 'estado' => 'instock'])['texto']);
es('quedan pocas: en ámbar', ['texto' => '3 en stock', 'tono' => 'ambar'], stock_web_texto(['cantidad' => 3, 'estado' => 'instock']));
es('de 4 para arriba, en verde', ['texto' => '4 en stock', 'tono' => 'verde'], stock_web_texto(['cantidad' => 4, 'estado' => 'instock']));
es('sin cantidad', ['texto' => 'Hay stock', 'tono' => 'verde'], stock_web_texto(['cantidad' => null, 'estado' => 'instock']));
es('bajo pedido', ['texto' => 'Bajo pedido', 'tono' => 'ambar'], stock_web_texto(['cantidad' => null, 'estado' => 'onbackorder']));
es('de lo que no se sabe nada, no se dice nada', ['texto' => '', 'tono' => ''], stock_web_texto(null));
guardar_ajuste('stock_poco', '0'); ajustes_olvidar();
es('el umbral de «quedan pocas» se cambia sin programador', 'verde', stock_web_texto(['cantidad' => 3, 'estado' => 'instock'])['tono']);
guardar_ajuste('stock_poco', '3'); ajustes_olvidar();

$st = fn(int $cant = null, string $estado = 'instock', $gest = true) =>
    ['manage_stock' => $gest, 'stock_quantity' => $cant, 'stock_status' => $estado];
$solo([
    $prod(9701, 'Stock simple', 'PRD-970100') + ['regular_price' => '10'] + $st(7),
    $prod(9702, 'Stock sin contar', 'PRD-970200') + ['regular_price' => '10'] + $st(null, 'instock', false),
    $prod(9703, 'Stock colores', 'PRD-970300', 'variable') + ['regular_price' => '10'] + $st(null, 'instock', false),
    $prod(9704, 'Stock agotado', 'PRD-970400') + ['regular_price' => '10'] + $st(0, 'outofstock'),
], [9703 => [
    $var(97031, 'PRD-970301', ['Color' => 'Rojo']) + ['regular_price' => '10'] + $st(2),
    $var(97032, 'PRD-970302', ['Color' => 'Azul']) + ['regular_price' => '10'] + $st(0, 'outofstock'),
    $var(97033, 'PRD-970303', ['Color' => 'Verde']) + ['regular_price' => '10'] + ['manage_stock' => 'parent', 'stock_status' => 'instock'],
]]);
$lst = $leer_y_plan();
ok('a la tienda se le pide el stock', str_contains(urldecode((string)$T['llamadas'][0][0]), 'stock_quantity')
   && (bool) array_filter($T['llamadas'], fn($c) => str_contains($c[0], '/variations') && str_contains(urldecode($c[0]), 'stock_status')));
$GST = tienda_importar(tienda_lectura_nueva($lst['filas'], $PERU));
ok('al guardar la tienda se guarda también el stock', $GST['ok'], (string)$GST['error']);
$pid = fn(string $sku) => (int) valor('SELECT id FROM productos WHERE sku = ?', [$sku]);
$SW = stock_web_de([$pid('PRD-970100'), $pid('PRD-970200'), $pid('PRD-970300'), $pid('PRD-970400'), 999999]);
es('el simple: 7', 7, $SW[$pid('PRD-970100')]['cantidad'] ?? -1);
es('el que no cuenta unidades: sin cantidad, «hay»', [true, null, 'instock'],
   [isset($SW[$pid('PRD-970200')]), $SW[$pid('PRD-970200')]['cantidad'] ?? null, $SW[$pid('PRD-970200')]['estado'] ?? '']);
es('con colores: la suma de los colores que cuentan', 2, $SW[$pid('PRD-970300')]['cantidad'] ?? -1);
es('y cada color con lo suyo', ['Rojo' => '2 en stock', 'Azul' => 'Agotado', 'Verde' => 'Hay stock'], (function () use ($SW, $pid) {
    $o = [];
    foreach (todas('SELECT id, color FROM variantes WHERE producto_id = ? ORDER BY id', [$pid('PRD-970300')]) as $v) {
        $o[$v['color']] = stock_web_texto($SW[$pid('PRD-970300')]['variantes'][(int)$v['id']] ?? null)['texto'];
    }
    return $o;
})());
es('el agotado', 'Agotado', stock_web_texto($SW[$pid('PRD-970400')] ?? null)['texto']);
ok('de un producto sin lectura no se dice nada', !isset($SW[999999]));
ok('con su hora', ($SW[$pid('PRD-970100')]['leido_en'] ?? '') !== '' && stock_web_leido_en($PERU) !== '');
$L_ST = (int) valor("SELECT id FROM tienda_lecturas ORDER BY id DESC LIMIT 1");
es('el stock no entra en lo que se compara al guardar: se guarda aunque cambie', true, (function () use (&$T, $leer_y_plan, $PERU) {
    $x = $leer_y_plan();
    $idl = tienda_lectura_nueva($x['filas'], $PERU);
    $T['productos'][0]['stock_quantity'] = 6;
    return tienda_importar($idl)['ok'];
})());

/* ACTUALIZAR STOCK: lee y guarda SOLO el stock. */
$T['productos'][0]['stock_quantity'] = 3;
$T['productos'][] = $prod(9705, 'Stock nuevo en la web', 'PRD-970500') + ['regular_price' => '10'] + $st(4);
$n_prod = (int) valor('SELECT COUNT(*) FROM productos');
$AS = stock_web_actualizar();
ok('actualizar el stock funciona', $AS['ok'], (string)$AS['error']);
es('trae la cantidad nueva', 3, stock_web_de([$pid('PRD-970100')])[$pid('PRD-970100')]['cantidad'] ?? -1);
es('SIN CREAR PRODUCTOS: para eso está «Traer de la tienda»', $n_prod, (int) valor('SELECT COUNT(*) FROM productos'));
es('y dice cuántos quedaron con stock', 4, $AS['productos']);
/* Un producto que se despublica deja de enseñar una cantidad vieja. */
array_splice($T['productos'], 3, 1);           // fuera el agotado
stock_web_actualizar();
ok('lo que ya no está en la web deja de enseñar stock', !isset(stock_web_de([$pid('PRD-970400')])[$pid('PRD-970400')]));
/* Si la tienda no contesta, lo que había se queda como estaba. */
$T['caida'] = true;
$AS2 = stock_web_actualizar();
ok('con la tienda caída, lo dice', !$AS2['ok'] && $AS2['error'] !== '');
es('y el stock de antes sigue ahí', 3, stock_web_de([$pid('PRD-970100')])[$pid('PRD-970100')]['cantidad'] ?? -1);
unset($T['caida']);
/* La tienda de otro país no pone stock aquí. */
guardar_ajuste('woo_pais', (string)$MX); ajustes_olvidar();
ok('si la tienda es de otro país, no se lee', !stock_web_actualizar()['ok']);
ok('pero el cron lee para el país de la tienda', stock_web_actualizar($MX)['ok']);
guardar_ajuste('woo_pais', (string)$PERU); ajustes_olvidar();

/* Lo que encontró la auditoría del 3b.5. */
/* 1 · Colores que COMPARTEN las unidades del producto: se cuentan una vez. */
$solo([
    $prod(9711, 'Stock compartido', 'PRD-971100', 'variable') + ['regular_price' => '10'] + $st(10),
    $prod(9701, 'Stock simple', 'PRD-970100') + ['regular_price' => '10'] + $st(7),
], [9711 => [
    $var(97111, 'PRD-971101', ['Color' => 'Rojo']) + ['regular_price' => '10', 'manage_stock' => 'parent', 'stock_status' => 'instock'],
    $var(97112, 'PRD-971102', ['Color' => 'Azul']) + ['regular_price' => '10', 'manage_stock' => 'parent', 'stock_status' => 'instock'],
    $var(97113, 'PRD-971103', ['Color' => 'Verde']) + ['regular_price' => '10', 'manage_stock' => 'parent', 'stock_status' => 'instock'],
]]);
tienda_importar(tienda_lectura_nueva($leer_y_plan()['filas'], $PERU));
$SC = stock_web_de([$pid('PRD-971100')])[$pid('PRD-971100')] ?? [];
es('TRES COLORES QUE COMPARTEN 10 UNIDADES SON 10, NO 30', 10, $SC['cantidad'] ?? -1);
es('y cada color dice las 10 que comparte', ['10 en stock', '10 en stock', '10 en stock'],
   array_values(array_map(fn($v) => stock_web_texto($v)['texto'], $SC['variantes'] ?? [])));
/* 2 · Un color apagado aquí no suma. */
$v_azul = (int) valor("SELECT id FROM variantes WHERE sku = 'PRD-970302'") ?: 0;
$v_rojo = (int) valor('SELECT id FROM variantes WHERE producto_id = ? ORDER BY id LIMIT 1', [$pid('PRD-970300')]);
$solo([
    $prod(9703, 'Stock colores', 'PRD-970300', 'variable') + ['regular_price' => '10'] + $st(null, 'instock', false),
], [9703 => [
    $var(97031, 'PRD-970301', ['Color' => 'Rojo']) + ['regular_price' => '10'] + $st(2),
    $var(97032, 'PRD-970302', ['Color' => 'Azul']) + ['regular_price' => '10'] + $st(5),
]]);
stock_web_actualizar();
es('con los dos colores encendidos: 7', 7, stock_web_de([$pid('PRD-970300')])[$pid('PRD-970300')]['cantidad'] ?? -1);
$T['variaciones'][9703][0]['stock_quantity'] = -3;
stock_web_actualizar();
es('UN COLOR EN NEGATIVO (vendido de más en la web) cuenta como cero, no resta', 5,
   stock_web_de([$pid('PRD-970300')])[$pid('PRD-970300')]['cantidad'] ?? -1);
$T['variaciones'][9703][0]['stock_quantity'] = 2;
stock_web_actualizar();
variante_encender($v_rojo, false);
es('UN COLOR APAGADO AQUÍ NO SUMA: 5', 5, stock_web_de([$pid('PRD-970300')])[$pid('PRD-970300')]['cantidad'] ?? -1);
variante_encender($v_rojo, true);
/* 3 · Guardar una lectura vieja no pisa un stock más nuevo, ni lo borra si no trae stock. */
$x_vieja = $leer_y_plan();
$ID_VIEJA = tienda_lectura_nueva($x_vieja['filas'], $PERU);
q("UPDATE tienda_lecturas SET creado_en = ? WHERE id = ?", [date('Y-m-d H:i:s', strtotime('-2 hours')), $ID_VIEJA]);
$T['variaciones'][9703][0]['stock_quantity'] = 40;
stock_web_actualizar();                                        // el cron, más nuevo
tienda_importar($ID_VIEJA);
es('GUARDAR UNA LECTURA VIEJA NO PISA EL STOCK MÁS NUEVO', 45, stock_web_de([$pid('PRD-970300')])[$pid('PRD-970300')]['cantidad'] ?? -1);
$sin_stock = array_map(function ($f) { unset($f['stock']); foreach ($f['variaciones'] as &$v) unset($v['stock']); return $f; }, $x_vieja['filas']);
es('una lectura de antes de la 3b.5 (sin stock) no borra nada', 0, stock_web_guardar($sin_stock, $PERU));
es('y el stock sigue', 45, stock_web_de([$pid('PRD-970300')])[$pid('PRD-970300')]['cantidad'] ?? -1);
/* …pero los productos que esa lectura vieja CREA sí salen con su stock. */
$T['productos'][] = $prod(9720, 'Stock recien creado', 'PRD-972000') + ['regular_price' => '10'] + $st(8);
$x_vieja2 = $leer_y_plan();
$ID_VIEJA2 = tienda_lectura_nueva($x_vieja2['filas'], $PERU);
q("UPDATE tienda_lecturas SET creado_en = ? WHERE id = ?", [date('Y-m-d H:i:s', strtotime('-2 hours')), $ID_VIEJA2]);
stock_web_actualizar();
$T['variaciones'][9703][0]['stock_quantity'] = 1;             // la web cambia, pero la lectura vieja no lo sabe
tienda_importar($ID_VIEJA2);
es('LO QUE LA LECTURA VIEJA CREA SALE CON SU STOCK', 8, stock_web_de([$pid('PRD-972000')])[$pid('PRD-972000')]['cantidad'] ?? -1);
es('sin tocar lo que ya tenía uno más nuevo', 45, stock_web_de([$pid('PRD-970300')])[$pid('PRD-970300')]['cantidad'] ?? -1);
$T['variaciones'][9703][0]['stock_quantity'] = 40;
/* 4 · Un stock viejo se dice viejo. */
q("UPDATE stock_web SET leido_en = ?", [date('Y-m-d H:i:s', strtotime('-2 days'))]);
$viejo_t = stock_web_texto(stock_web_de([$pid('PRD-970300')])[$pid('PRD-970300')] ?? null);
es('UN STOCK DE HACE DOS DÍAS SE DICE VIEJO, en gris', ['texto' => '45 en stock (hace 2 días)', 'tono' => 'gris'], $viejo_t);
stock_web_actualizar();
es('y al leerlo otra vez vuelve a ser de ahora', 'verde', stock_web_texto(stock_web_de([$pid('PRD-970300')])[$pid('PRD-970300')] ?? null)['tono']);
/* 5 · Desde otro país no se ve el stock de esta tienda. */
guardar_ajuste('woo_pais', (string)$MX); ajustes_olvidar();
es('desde otro país no se enseña el stock de esta tienda', [], stock_web_de([$pid('PRD-970300')]));
guardar_ajuste('woo_pais', (string)$PERU); ajustes_olvidar();
/* 6 · Al cambiar la tienda de país, lo del otro país no se queda. */
q("INSERT INTO stock_web (producto_id, variante_id, cantidad, estado, compartido, leido_en) VALUES (?, 0, 99, 'instock', 0, ?)",
  [$pid('PRD-971100'), date('Y-m-d H:i:s', strtotime('-1 hour'))]);
q('DELETE FROM stock_web WHERE producto_id = ? AND variante_id > 0', [$pid('PRD-971100')]);
stock_web_actualizar();
ok('cada lectura reemplaza TODO el stock: lo que no está en la tienda se va', !isset(stock_web_de([$pid('PRD-971100')])[$pid('PRD-971100')]));

/* El asesor lo ve al elegir el producto en la venta. */
$solo([
    $prod(9703, 'Stock colores', 'PRD-970300', 'variable') + ['regular_price' => '10'] + $st(null, 'instock', false),
], [9703 => [
    $var(97031, 'PRD-970301', ['Color' => 'Rojo']) + ['regular_price' => '10'] + $st(2),
    $var(97032, 'PRD-970302', ['Color' => 'Azul']) + ['regular_price' => '10'] + $st(0, 'outofstock'),
    $var(97033, 'PRD-970303', ['Color' => 'Verde']) + ['regular_price' => '10'] + ['manage_stock' => 'parent', 'stock_status' => 'instock'],
]]);
stock_web_actualizar();
$b = array_values(array_filter(catalogo_buscar('Stock colores'), fn($x) => $x['sku'] === 'PRD-970300'))[0] ?? null;
es('el buscador de la venta dice el stock del producto', '2 en stock', $b['stock']['texto'] ?? null);
es('y el de cada color', ['2 en stock', 'Agotado', 'Hay stock'], array_map(fn($v) => $v['stock']['texto'], $b['variantes'] ?? []));
$sin_st = producto_guardar(null, ['nombre' => 'Sin stock web zeta', 'activo' => 1]);
precios_guardar((int)$sin_st['id'], null, [['desde' => 1, 'hasta' => '', 'precio' => '10.00']]);
$b2 = array_values(array_filter(catalogo_buscar('Sin stock web zeta'), fn($x) => true))[0] ?? null;
es('de uno sin stock de la web, no dice nada', '', $b2['stock']['texto'] ?? 'x');

/* El borrón que se lleva el catálogo se lleva el stock. */
require_once HUB_APP . '/nucleo/limpieza.php';
ok('el borrón del catálogo se lleva el stock de la web', in_array('stock_web', limpieza_tablas(true), true)
   && !in_array('stock_web', limpieza_tablas(false), true));

grupo('3b.5 · escribirle al asesor por WhatsApp');
$yo_id = (int)(yo()['id'] ?? 0);
$pw = ['asesor_id' => $yo_id + 999, 'codigo' => 'P-00999', 'asesor_nombre' => 'Joel Pérez', 'asesor_celular' => '987 654 321'];
$wa1 = whatsapp_al_asesor($pw, 0);
ok('el enlace va al celular del asesor, con el prefijo del Perú', str_starts_with($wa1, 'https://wa.me/51987654321?text='), $wa1);
es('y el saludo con su nombre y el pedido', 'Hola Joel, te escribo por el pedido P-00999.', rawurldecode(explode('?text=', $wa1)[1] ?? ''));
es('con saldo sin registrar, el mensaje lo dice', 'Hola Joel, el pedido P-00999 ya se entregó y falta registrar el saldo de ' . soles(35000) . '. ¿Ya lo cobraste?',
   rawurldecode(explode('?text=', whatsapp_al_asesor($pw, 35000))[1] ?? ''));
es('a uno mismo no se le ofrece', '', whatsapp_al_asesor(['asesor_id' => $yo_id] + $pw, 0));
es('sin celular, no hay enlace', '', whatsapp_al_asesor(['asesor_celular' => ''] + $pw, 0));
es('un pedido que no existe no tiene saldo sin registrar', 0, pedido_saldo_sin_registrar(999999));
es('de un pedido anulado no se ofrece', '', whatsapp_al_asesor(['estado' => 'anulado'] + $pw, 0));
banco_entrar(banco_usuario('facturacion'));
es('el celular del asesor no se le enseña a Facturación', '', whatsapp_al_asesor($pw, 0));
banco_entrar($ADMIN);

grupo('Módulo 3b · las páginas y las respuestas raras');

es('fuera de una lectura, cada pedido espera hasta 40 s', 40, tienda_segundos_por_pedido());
tienda_plazo(microtime(true) + 12);
ok('dentro de una lectura, solo lo que queda del plazo', tienda_segundos_por_pedido() <= 12 && tienda_segundos_por_pedido() >= 5);
tienda_plazo(microtime(true) - 100);
es('y nunca menos de 5', 5, tienda_segundos_por_pedido());
tienda_plazo(0.0);

$muchos = array_map(fn($i) => $prod(40000 + $i, 'Cien ' . $i, 'PRD-4' . str_pad((string)$i, 5, '0', STR_PAD_LEFT)), range(1, 100));
$solo($muchos);
$T['sin_paginas'] = true;
$lp = tienda_leer();
ok('sin cabeceras y con justo cien productos, la página de más no es un error', $lp['ok'], $lp['error']);
es('y se leen los cien', 100, count($lp['filas']));
unset($T['sin_paginas']);
$T['paginas_falsas'] = 999;
$T['llamadas'] = [];
$lp2 = tienda_leer();
ok('una tienda sin fin tiene un tope', !$lp2['ok'] && str_contains($lp2['error'], 'más productos'), $lp2['error']);
es('de sesenta páginas', 60, count(array_filter($T['llamadas'], fn($c) => str_contains($c[0], '/products?'))));
unset($T['paginas_falsas']);
$T['cuerpo_raro'] = ['code' => 'no_es_una_lista'];
$pr_raro = tienda_probar();
ok('una respuesta que no es una lista no cuenta como conectada', !$pr_raro['ok'], $pr_raro['texto']);
unset($T['cuerpo_raro']);
$T['http'] = 403;
ok('un 403 es «no aceptó las claves»', str_contains(tienda_probar()['texto'], 'no aceptó las claves'));
$T['http'] = 500;
ok('un 500 es «tuvo un problema»', str_contains(tienda_probar()['texto'], 'tuvo un problema'));
$T['http'] = 0;

es('«COD:&nbsp;PRD-1» es PRD-1', 'PRD-1', tienda_sku_limpio('COD:&nbsp;PRD-1'));
es('los espacios de dentro se quitan', 'PRD016782', tienda_sku_limpio('PRD 0167 82'));
es('el segundo «Color» no pisa al primero', 'Rojo',
   tienda_atributos([['name' => 'Color', 'option' => 'Rojo'], ['name' => 'Color', 'option' => 'Azul']])['color']);
es('un valor vacío no ensucia el modelo', 'Pro',
   tienda_atributos([['name' => 'Color', 'option' => 'Rojo'], ['name' => 'Talla', 'option' => '  '],
                     ['name' => 'Modelo', 'option' => 'Pro']])['medida']);
ok('una clave con basura detrás no vale', !tienda_clave_valida('ck_' . str_repeat('a1', 20) . ' x', 'ck'));
es('una dirección con «?» no vale', '', tienda_ruta_limpia('https://compraenwaka.com/?x=1'));
es('/WP-JSON en mayúsculas también se corta', 'https://compraenwaka.com', tienda_ruta_limpia('https://compraenwaka.com/WP-JSON/wc/v3'));

banco_entrar($ADMIN);
unset($GLOBALS['__tienda_transporte']);

/* ═══════════════  3d · EL DESCUENTO DEL ASESOR  ═══════════════ */
grupo('3d · el descuento del asesor: el tope, el motivo y la respuesta de Administración');

es('3h.1: DE FÁBRICA EL DESCUENTO NO PIDE APROBACIÓN', false, descuento_pide_aprobacion());
es('y uno grande nace valiendo', 'ok', descuento_estado_inicial(20000, 37900, ['id' => 0, 'rol' => 'asesor']));
/* 3h.1: la aprobación nace apagada; estas pruebas son de cuando está encendida. */
q("DELETE FROM ajustes WHERE clave = 'descuento_pide_aprobacion'");
q("INSERT INTO ajustes (clave, valor, tipo, grupo, descripcion, tecnico) VALUES ('descuento_pide_aprobacion', '1', 'booleano', 'pedidos', '', 0)");
q("UPDATE ajustes SET valor = '5' WHERE clave = 'descuento_tope_pct'");
q("UPDATE ajustes SET valor = '0' WHERE clave = 'cashback_con_descuento'");
ajustes_olvidar();
es('el tope nace en 5 %', 5, descuento_tope_pct());
ok('el 5 % de S/ 379 son S/ 18.95 y S/ 18.95 NO pasa', !descuento_pasa_tope(1895, 37900));
ok('un céntimo más SÍ pasa', descuento_pasa_tope(1896, 37900));
ok('sin descuento no hay nada que pasar', !descuento_pasa_tope(0, 37900));
es('el % se enseña con un decimal', '13.2 %', descuento_pct_texto(5000, 37900));
es('y sin decimales cuando es redondo', '10 %', descuento_pct_texto(3790, 37900));

es('sin descuento y sin motivo, nada que decir', '', descuento_problema(0, '', 37900, 0, 'inmediata'));
ok('sin motivo, lo pide', str_contains(descuento_problema(1000, '', 37900, 0, 'inmediata'), 'Escribe el motivo'));
ok('y unos espacios no son un motivo', str_contains(descuento_problema(1000, '   ', 37900, 0, 'inmediata'), 'Escribe el motivo'));
ok('un motivo de dos letras no vale', str_contains(descuento_problema(1000, 'ok', 37900, 0, 'inmediata'), 'corto'));
ok('en liquidación no hay descuento: el precio ya es el descuento',
   str_contains(descuento_problema(1000, 'cliente frecuente', 37900, 0, 'liquidacion'), 'liquidación'));
ok('un descuento que no se entiende se dice', str_contains(descuento_problema(-1, 'x x x', 37900, 0, 'inmediata'), 'no se entiende'));
ok('no puede dejar los productos en cero',
   str_contains(descuento_problema(37900, 'regalo total', 37900, 0, 'inmediata'), 'en cero'));
ok('ni sumado al cashback', descuento_problema(30000, 'cliente frecuente', 37900, 7900, 'inmediata') !== '');
ok('CON CASHBACK EN LA MISMA VENTA, NO (el ajuste nace apagado)',
   str_contains(descuento_problema(1000, 'cliente frecuente', 37900, 1000, 'inmediata'), 'cashback'));
q("UPDATE ajustes SET valor = '1' WHERE clave = 'cashback_con_descuento'"); ajustes_olvidar();
es('y con el ajuste encendido, sí', '', descuento_problema(1000, 'cliente frecuente', 37900, 1000, 'inmediata'));
q("UPDATE ajustes SET valor = '0' WHERE clave = 'cashback_con_descuento'"); ajustes_olvidar();
es('dentro del tope, en pre venta también vale', '', descuento_problema(1000, 'cliente frecuente', 37900, 0, 'preventa'));

$u_as = una('SELECT u.*, r.clave AS rol FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?', [$ASESOR]);
$u_ad = una('SELECT u.*, r.clave AS rol FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?', [$ADMIN]);
es('dentro del tope, el del asesor nace bueno', 'ok', descuento_estado_inicial(1000, 37900, $u_as));
es('fuera del tope, el del asesor ESPERA', 'pendiente', descuento_estado_inicial(5000, 37900, $u_as));
es('fuera del tope, el de Administración no espera a nadie', 'ok', descuento_estado_inicial(5000, 37900, $u_ad));
es('sin descuento, sin estado', '', descuento_estado_inicial(0, 37900, $u_as));
ok('Administración puede aprobar', puede_aprobar_descuentos($u_ad));
ok('el asesor no', !puede_aprobar_descuentos($u_as));
$u_fa = una('SELECT u.*, r.clave AS rol FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?', [$FACTU]);
ok('Facturación tampoco', !puede_aprobar_descuentos($u_fa));
q("UPDATE ajustes SET valor = '0' WHERE clave = 'descuento_tope_pct'"); ajustes_olvidar();
es('con tope 0, todo descuento espera', 'pendiente', descuento_estado_inicial(1, 37900, $u_as));
q("UPDATE ajustes SET valor = '5' WHERE clave = 'descuento_tope_pct'"); ajustes_olvidar();

/* Un pedido con el descuento esperando, como lo deja la pantalla. */
banco_entrar($ASESOR);
$CLI_D = nuevo_cliente($PAIS, $ASESOR, '10000093', 'desc@waka.test');
$con_desc = function (int $monto, int $desc, string $estado) use ($PAIS, $CLI_D, $ASESOR, $EQUIPO, $CANAL): int {
    $id = nuevo_pedido(['pais_id'=>$PAIS,'cliente_id'=>$CLI_D,'asesor_id'=>$ASESOR,'equipo_id'=>$EQUIPO,
                        'monto'=>$monto,'canal_item_id'=>$CANAL]);
    actualizar('pedidos', $id, ['descuento_centimos' => $desc, 'descuento_pedido_centimos' => $desc,
                                'descuento_motivo' => 'compra 3 unidades', 'descuento_estado' => $estado,
                                'descuento_por' => $ASESOR]);
    pedido_recalcular($id);
    return $id;
};
$PD1 = $con_desc(37900, 5000, 'pendiente');
es('EL DESCUENTO RESTA DESDE YA, aunque espere', 32900, (int) valor('SELECT total_centimos FROM pedidos WHERE id = ?', [$PD1]));
ok('EL MENSAJE AL CLIENTE LLEVA LA LÍNEA DEL DESCUENTO: las líneas suman el total',
   str_contains(variables_de_pedido($PD1)['detalle'], 'Descuento — − ' . soles(5000)));
$r = cobrar($PD1, 32900, $EFEC, $HOY);
ok('el cliente paga el precio que le dijeron', $r['ok'], $r['error']);
banco_entrar($ASESOR);
es('y queda cobrado al 100 %', 0, pedido_saldo(pedido_de($PD1)));
es('CON EL DESCUENTO ESPERANDO, NO SALE A DESPACHO', 'descuento', pedido_despacho_motivo(pedido_de($PD1))['clave']);
ok('y la frase lo dice', str_contains(pedido_despacho_bloqueo(pedido_de($PD1)), 'Administración'));
es('el SQL dice lo mismo que la frase', 0, (int) valor('SELECT COUNT(*) FROM pedidos pe WHERE pe.id = ? AND ' . sql_despacho_listo(), [$PD1]));
ok('NI SE LE EMITE COMPROBANTE: el total todavía puede cambiar',
   str_contains(pedido_emision_bloqueo(pedido_de($PD1)), 'descuento'));
ok('con una fila recortada (sin la columna) también lo sabe',
   pedido_descuento_pendiente(['id' => $PD1]));
$acc = pedido_accion_pendiente(pedido_de($PD1));
ok('al asesor se le dice que espera a Administración', str_contains((string)($acc['texto'] ?? ''), 'Administración'));

$r = descuento_resolver($PD1, true);
ok('el asesor NO puede aprobar su propio descuento', !$r['ok'] && str_contains($r['error'], 'perfil'), $r['error']);

banco_entrar($ADMIN);
$acc = pedido_accion_pendiente(pedido_de($PD1));
ok('a Administración, lo primero que se le pide es la respuesta', !empty($acc['descuento']));
es('está en su lista', 1, count(array_filter(descuentos_pendientes(), fn($f) => (int)$f['id'] === $PD1)));
ok('y en el número', descuentos_pendientes_n() >= 1);

$r = descuento_resolver($PD1, true);
ok('Administración lo aprueba', $r['ok'], $r['error']);
es('queda bueno', 'ok', (string) valor('SELECT descuento_estado FROM pedidos WHERE id = ?', [$PD1]));
es('el total no cambia', 32900, (int) valor('SELECT total_centimos FROM pedidos WHERE id = ?', [$PD1]));
es('y con quién lo aprobó', $ADMIN, (int) valor('SELECT descuento_revisado_por FROM pedidos WHERE id = ?', [$PD1]));
es('ya sale a despacho', '', pedido_despacho_bloqueo(pedido_de($PD1)));
es('ya no está en la lista', 0, count(array_filter(descuentos_pendientes(), fn($f) => (int)$f['id'] === $PD1)));
$r = descuento_resolver($PD1, false, 'tarde');
ok('responder dos veces no vale', !$r['ok'] && str_contains($r['error'], 'ya estaba aprobado'), $r['error']);
es('y sigue bueno', 'ok', (string) valor('SELECT descuento_estado FROM pedidos WHERE id = ?', [$PD1]));
es('y la aprobación queda en la bitácora', 1,
   (int) valor("SELECT COUNT(*) FROM bitacora WHERE accion = 'pedido.descuento_ok' AND entidad_id = ?", [$PD1]));

/* EL RECHAZO: el total sube y, si estaba «Pagado», vuelve atrás solo. */
banco_entrar($ASESOR);
$PD2 = $con_desc(37900, 5000, 'pendiente');
cobrar($PD2, 32900, $EFEC, $HOY);
banco_entrar($ASESOR);
es('pagado el precio con descuento, pasa a «Pagado»', 'pagado', (string) pedido_de($PD2)['estado']);
banco_entrar($ADMIN);
$r = descuento_resolver($PD2, false, 'Ese precio ya es de oferta');
ok('Administración lo rechaza', $r['ok'], $r['error']);
$p2 = pedido_de($PD2);
es('EL TOTAL VUELVE A SUBIR', 37900, (int)$p2['total_centimos']);
es('queda saldo por cobrar', 5000, pedido_saldo($p2));
es('Y SALE DE «PAGADO»: con deuda no puede decir pagado', 'registrado', (string)$p2['estado']);
es('el descuento vigente queda en cero', 0, (int)$p2['descuento_centimos']);
es('pero lo que se pidió no se borra', 5000, (int)$p2['descuento_pedido_centimos']);
es('con la nota para el asesor', 'Ese precio ya es de oferta', (string)$p2['descuento_nota']);
es('y el rechazo en el historial del pedido', 1,
   (int) valor("SELECT COUNT(*) FROM pedido_eventos WHERE pedido_id = ? AND tipo = 'descuento' AND texto LIKE '%rechazó%'", [$PD2]));

/* EL ASESOR SE ENTERA: el sondeo con la marca de agua de la bitácora. */
banco_entrar($ASESOR);
$marca_d = reclamos_marca();
$PD3 = $con_desc(37900, 5000, 'pendiente');
bitacora('pedido.descuento_pide', 'pedido', $PD3, ['monto' => 5000]);
$u_ad = una('SELECT u.*, r.clave AS rol FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?', [$ADMIN]);
$dn = descuentos_pedidos_desde($marca_d, reclamos_marca(), $u_ad);
es('a Administración le entra UN descuento por aprobar', 1, $dn['n']);
es('con su código', (string) pedido_de($PD3)['codigo'], $dn['codigo']);
es('al asesor no le suena (no puede aprobar)', 0, descuentos_pedidos_desde($marca_d, reclamos_marca(), $u_as)['n']);
es('desde «ahora», nada', 0, descuentos_pedidos_desde(reclamos_marca(), reclamos_marca(), $u_ad)['n']);
$marca_r = reclamos_marca();
banco_entrar($ADMIN);
descuento_resolver($PD3, true);
$dr = descuentos_resueltos_desde($marca_r, reclamos_marca(), $u_as);
es('AL ASESOR LE LLEGA LA RESPUESTA', 1, $dr['n']);
ok('y dice que se aprobó', $dr['aprobado']);
es('a otro asesor no le llega', 0,
   descuentos_resueltos_desde($marca_r, reclamos_marca(),
       una('SELECT u.*, r.clave AS rol FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?', [$OTRO]))['n']);
es('y una vez contestado, deja de sonarle a Administración', 0, descuentos_pedidos_desde($marca_d, reclamos_marca(), $u_ad)['n']);

/* Contestar es escribir: Administración de OTRO país no puede. */
banco_entrar($ASESOR);
$PD5 = $con_desc(37900, 5000, 'pendiente');
$ADMIN_MX = banco_usuario('administracion', ['pais_id' => $MX]);
banco_entrar($ADMIN_MX);
$r = descuento_resolver($PD5, true);
ok('Administración de otro país no lo contesta', !$r['ok'], $r['error']);
es('y sigue esperando', 'pendiente', (string) valor('SELECT descuento_estado FROM pedidos WHERE id = ?', [$PD5]));
es('ni lo ve en su lista', 0, count(array_filter(descuentos_pendientes(), fn($f) => (int)$f['id'] === $PD5)));
banco_entrar($ADMIN);
descuento_resolver($PD5, true);

/* Un pedido anulado no se aprueba. */
banco_entrar($ASESOR);
$PD4 = $con_desc(37900, 5000, 'pendiente');
q('UPDATE pedidos SET anulado_en = ?, estado_id = ? WHERE id = ?',
  [date('Y-m-d H:i:s'), (int) estado_por_clave('anulado')['id'], $PD4]);
banco_entrar($ADMIN);
$r = descuento_resolver($PD4, true);
ok('uno anulado no se aprueba', !$r['ok'] && str_contains($r['error'], 'anulado'), $r['error']);
ok('ni sale en la lista', !array_filter(descuentos_pendientes(), fn($f) => (int)$f['id'] === $PD4));

/* La guarda de siempre: SQL y prosa, pedido por pedido, con los descuentos dentro. */
$vivos_d = array_map('intval', array_column(todas('SELECT id FROM pedidos WHERE anulado_en IS NULL ORDER BY id'), 'id'));
$sql_d = array_map('intval', array_column(todas('SELECT pe.id FROM pedidos pe WHERE pe.anulado_en IS NULL AND '
                                               . sql_despacho_listo() . ' ORDER BY pe.id'), 'id'));
$php_d = [];
foreach ($vivos_d as $vid) { $ped = pedido_de($vid); if ($ped && pedido_despacho_bloqueo($ped) === '') $php_d[] = $vid; }
sort($sql_d); sort($php_d);
es('CON DESCUENTOS, la regla en SQL y en prosa siguen diciendo lo mismo', implode(',', $php_d), implode(',', $sql_d));


/* ══════════════════════ 3g · ALMACÉN Y MARKETING ══════════════════════ */
grupo('3g · Almacén lleva el stock y Marketing los datos de la tienda');
$rid = fn(string $c) => (int) valor('SELECT id FROM roles WHERE clave = ?', [$c]);
$perms = function (string $c) use ($rid) { $p = permisos_del_rol($rid($c)); sort($p); return $p; };
es('los dos roles existen, con su nombre', ['Almacén', 'Marketing'],
   [(string) valor("SELECT nombre FROM roles WHERE clave = 'almacen'"), (string) valor("SELECT nombre FROM roles WHERE clave = 'marketing'")]);
es('ALMACÉN: alistar, el stock y los lotes', ['lotes.ver', 'pedidos.alistar', 'stock.ajustar', 'stock.ver'], $perms('almacen'));
es('MARKETING: el catálogo, los precios, los datos de la web y mirar clientes',
   ['catalogo.gestionar', 'clientes.ver', 'precios.editar', 'stock.ver', 'tienda.datos'], $perms('marketing'));
es('el permiso viejo de la tienda ya no existe', 0, (int) valor("SELECT COUNT(*) FROM permisos WHERE clave = 'tienda.escribir'"));
foreach (['tienda.datos', 'tienda.cola', 'stock.ajustar', 'pedidos.alistar'] as $pp) {
    ok("Administración tiene $pp", in_array($pp, $perms('administracion'), true));
}
ok('Dirección no cambia la web ni revisa lo pendiente',
   !array_intersect(['tienda.datos', 'tienda.cola', 'stock.ajustar'], $perms('direccion')));
ok('el asesor tampoco', !array_intersect(['tienda.datos', 'tienda.cola', 'stock.ajustar', 'pedidos.alistar'], $perms('asesor')));
ok('nadie más que Administración revisa lo pendiente de la web',
   array_map('strval', array_column(todas("SELECT r.clave FROM rol_permiso rp JOIN roles r ON r.id = rp.rol_id
      JOIN permisos p ON p.id = rp.permiso_id WHERE p.clave = 'tienda.cola' ORDER BY r.clave"), 'clave')) === ['administracion', 'desarrollador']);

es('QUIEN NO VENDE VE TODO SU PAÍS (no tiene nada «suyo»)', ['todo', 'todo'],
   [ambito_forzado_de_rol($rid('almacen')), ambito_forzado_de_rol($rid('marketing'))]);
ok('el asesor sigue eligiendo', ambito_forzado_de_rol($rid('asesor')) === null);

$ADM3 = banco_usuario('administracion');
banco_entrar($ADM3);
ok('ADMINISTRACIÓN DA DE ALTA ALMACÉN Y MARKETING', !array_diff(['almacen', 'marketing'], roles_que_puedo_dar()));
ok('y Dirección también', !array_diff(['almacen', 'marketing'], roles_que_puedo_dar(['rol' => 'direccion'])));
es('Almacén y Marketing no reparten roles', [[], []],
   [roles_que_puedo_dar(['rol' => 'almacen']), roles_que_puedo_dar(['rol' => 'marketing'])]);

$ALM = banco_usuario('almacen');
$MKT = banco_usuario('marketing');
banco_entrar($ALM);
ok('ALMACÉN NO ESCRIBE NINGÚN REGISTRO, ni «suyo»', !puedo_editar($ALM, null) && !puedo_editar($ALM, (int)yo()['pais_id']));
/* 5b (usuario, 2026-09-29): «Por entregar» es otra opción de su menú. */
es('su menú: Inicio, Por alistar, Por entregar y Stock (no Por despachar)', ['inicio', 'alistar', 'entregar', 'stock'], array_column(menu_de(), 'clave'));
es('EN EL CELULAR, LAS CUATRO Y «MÁS» (ahí están Mi perfil y Cerrar sesión)', ['inicio', 'alistar', 'entregar', 'stock', 'mas'], array_column(barra_movil(), 'clave'));
es('y «Más» no repite ninguna sección', [], menu_mas());
ok('su frase del día no habla de vender', ($f_a = frase_del_dia('almacen', 'manana')) !== null && $f_a['rol_clave'] === 'almacen');
ok('no se le pinta el contador de despacho (no es quien manda)', !le_avisamos_de_despacho(yo()));
banco_entrar($MKT);
ok('MARKETING TAMPOCO', !puedo_editar($MKT, null));
es('su menú: Inicio, Stock y Clientes', ['inicio', 'stock', 'clientes'], array_column(menu_de(), 'clave'));
ok('Clientes, en solo mirar', !empty(array_column(menu_de(), 'solo_lectura', 'clave')['clientes']));
es('con «Más»', ['inicio', 'stock', 'clientes', 'mas'], array_column(barra_movil(), 'clave'));
foreach (['manana', 'tarde', 'noche', 'madrugada'] as $fr) {
    ok("marketing tiene su frase de la $fr", (frase_del_dia('marketing', $fr)['rol_clave'] ?? '') === 'marketing'
       && (frase_del_dia('almacen', $fr)['rol_clave'] ?? '') === 'almacen');
}
$nf = (int) valor('SELECT COUNT(*) FROM frases');
sembrar_frases_de_rol();
es('sembrarlas otra vez no las repite', $nf, (int) valor('SELECT COUNT(*) FROM frases'));
banco_entrar(banco_usuario('facturacion'));
/* 3i (usuario, 2026-09-28): Reportes pasa a «Más», y con él cerrar sesión y la contraseña. */
es('FACTURACIÓN TIENE «MÁS» (3i): Inicio · Pagos · Pedidos · Clientes · Más', ['inicio', 'pagos', 'pedidos', 'clientes', 'mas'], array_column(barra_movil(), 'clave'));
es('y en «Más» están los Reportes', ['reportes'], array_column(menu_mas(), 'clave'));
banco_entrar(banco_usuario('asesor'));
ok('el asesor sigue con «Más»', in_array('mas', array_column(barra_movil(), 'clave'), true) && menu_mas() !== []);

/* Recordarme: quien cambia la tienda web no se queda 30 días abierto. */
$ses = fn(int $id) => (int) valor('SELECT COUNT(*) FROM sesiones WHERE usuario_id = ?', [$id]);
@crear_recuerdo($ALM); @crear_recuerdo($MKT);
es('ALMACÉN Y MARKETING NO SE RECUERDAN (cambian la web)', [0, 0], [$ses($ALM), $ses($MKT)]);
$AS3 = banco_usuario('asesor');
@crear_recuerdo($AS3);
es('el asesor sí', 1, $ses($AS3));

/* Un HUB que todavía tiene el permiso viejo: se reparte y se borra. */
$vp = insertar('permisos', ['clave' => 'tienda.escribir', 'nombre' => 'Viejo', 'grupo' => 'stock', 'tecnico' => 0]);
sembrar_par('rol_permiso', ['rol_id' => $rid('direccion'), 'permiso_id' => $vp]);
$hecho_m = migraciones_tras_semillas();
ok('LA ACTUALIZACIÓN REPARTE EL PERMISO VIEJO', (bool) array_filter($hecho_m, fn($x) => str_contains($x, 'se reparte')));
ok('quien lo tenía se queda con los datos y lo pendiente', !array_diff(['tienda.datos', 'tienda.cola'], $perms('direccion')));
ok('pero no con el stock (antes pedía también «stock.ajustar»)', !in_array('stock.ajustar', $perms('direccion'), true));
es('y el viejo desaparece, con sus filas', [0, 0],
   [(int) valor("SELECT COUNT(*) FROM permisos WHERE clave = 'tienda.escribir'"), (int) valor('SELECT COUNT(*) FROM rol_permiso WHERE permiso_id = ?', [$vp])]);
ok('pasarla otra vez no hace nada', !array_filter(migraciones_tras_semillas(), fn($x) => str_contains($x, 'se reparte')));
q("DELETE FROM rol_permiso WHERE rol_id = ? AND permiso_id IN (SELECT id FROM permisos WHERE clave IN ('tienda.datos','tienda.cola'))", [$rid('direccion')]);
permisos_del_rol(0, true);

/* ── POR ALISTAR ── */
grupo('3g · Por alistar: foto de lo alistado y quién lo alistó');
$ped_a = (int) valor('SELECT id FROM pedidos WHERE anulado_en IS NULL AND pais_id = (SELECT id FROM paises WHERE codigo = \'PE\') ORDER BY id LIMIT 1');
q('UPDATE pedidos SET despacho_veces = 0, alistado_en = NULL WHERE despacho_veces > 0 OR alistado_en IS NOT NULL');
banco_entrar($ALM);
es('SIN MANDAR A DESPACHO NO HAY NADA POR ALISTAR', 0, pedidos_por_alistar_n());
q('UPDATE pedidos SET despacho_veces = 1, despacho_en = ? WHERE id = ?', [date('Y-m-d H:i:s'), $ped_a]);
es('MANDADO A DESPACHO, ENTRA', [1, $ped_a], [pedidos_por_alistar_n(), (int)(pedidos_por_alistar()[0]['id'] ?? 0)]);
ok('a Almacén se le pinta el contador', le_avisamos_de_alistar(yo()) && contador_de('alistar') === 1);
banco_entrar($ADM3);
ok('a Administración no (alista de respaldo)', !le_avisamos_de_alistar(yo()) && puede('pedidos.alistar'));
banco_entrar($ALM);
$lid_eq = (int) valor("SELECT id FROM listas WHERE clave = 'equipo_despacho'");
ok('la lista «Equipo de despacho» existe y nace vacía', $lid_eq > 0 && lista('equipo_despacho') === []);
$juan = insertar('lista_items', ['lista_id' => $lid_eq, 'pais_id' => (int)yo()['pais_id'], 'valor' => 'Juan', 'orden' => 10]);
$apagado = insertar('lista_items', ['lista_id' => $lid_eq, 'pais_id' => (int)yo()['pais_id'], 'valor' => 'Apagado', 'orden' => 20, 'activo' => 0]);
@mkdir(HUB_SUBIDAS . '/vouchers', 0755, true);
$foto_a = 'v' . date('Ymd') . '-' . 'abcdef123456.jpg';
file_put_contents(HUB_SUBIDAS . '/vouchers/' . $foto_a, 'x');
$r = pedido_alistar($ped_a, null, $foto_a);
ok('SIN QUIÉN NO SE MARCA', !$r['ok'] && str_contains($r['error'], 'quién'), $r['error']);
$r = pedido_alistar($ped_a, $apagado, $foto_a);
ok('ni con alguien apagado', !$r['ok']);
$r = pedido_alistar($ped_a, $juan, null);
ok('SIN FOTO NO SE MARCA', !$r['ok'] && str_contains($r['error'], 'foto'), $r['error']);
$r = pedido_alistar($ped_a, $juan, 'v20260101-000000000000.jpg');
ok('ni con una foto que no existe', !$r['ok']);
$r = pedido_alistar($ped_a, $juan, $foto_a);
ok('CON LAS DOS COSAS, QUEDA ALISTADO', $r['ok'], $r['error']);
es('con quién lo alistó, quién lo marcó y la foto', [$juan, $ALM, $foto_a],
   array_values(array_map(fn($v) => is_numeric($v) ? (int)$v : $v, una('SELECT alistado_quien_id, alistado_por, alistado_foto FROM pedidos WHERE id = ?', [$ped_a]))));
es('sale de la lista y pasa a «alistados hoy»', [0, $ped_a], [pedidos_por_alistar_n(), (int)(pedidos_alistados_hoy()[0]['id'] ?? 0)]);
ok('dice por quién', str_contains(pedido_alistado_detalle(pedido_de($ped_a)), 'por Juan'));
ok('MARCARLO OTRA VEZ NO', !pedido_alistar($ped_a, $juan, $foto_a)['ok']);
ok('queda en la bitácora', (bool) valor("SELECT 1 FROM bitacora WHERE accion = 'pedido.alistado' AND entidad_id = ?", [$ped_a]));
$r = pedido_alistado_deshacer($ped_a);
ok('DESHACER LO DEVUELVE A LA LISTA', $r['ok'] && pedidos_por_alistar_n() === 1 && valor('SELECT alistado_foto FROM pedidos WHERE id = ?', [$ped_a]) === null);
ok('deshacer dos veces no', !pedido_alistado_deshacer($ped_a)['ok']);
$ADM_MX3 = banco_usuario('almacen', ['pais_id' => (int) valor("SELECT id FROM paises WHERE codigo <> 'PE' ORDER BY id LIMIT 1") ?: 0]);
if ((int) valor("SELECT COUNT(*) FROM paises WHERE codigo <> 'PE'") > 0) {
    banco_entrar($ADM_MX3);
    ok('ALMACÉN DE OTRO PAÍS NO LO VE NI LO MARCA', pedidos_por_alistar_n() === 0 && !pedido_alistar($ped_a, $juan, $foto_a)['ok']);
    banco_entrar($ALM);
}
/* Solo el mismo día. */
pedido_alistar($ped_a, $juan, $foto_a);
q('UPDATE pedidos SET alistado_en = ? WHERE id = ?', [date('Y-m-d H:i:s', strtotime('-1 day')), $ped_a]);
$r = pedido_alistado_deshacer($ped_a);
ok('LO DE AYER NO SE DESHACE', !$r['ok'] && str_contains($r['error'], 'mismo día'), $r['error']);
ok('la bitácora guarda la foto de cada alistado', str_contains((string) valor("SELECT detalle FROM bitacora WHERE accion = 'pedido.alistado' AND entidad_id = ? ORDER BY id DESC LIMIT 1", [$ped_a]), $foto_a));
q('UPDATE pedidos SET alistado_en = ? WHERE id = ?', [date('Y-m-d H:i:s'), $ped_a]);
q('UPDATE pedidos SET anulado_en = ? WHERE id = ?', [date('Y-m-d H:i:s'), $ped_a]);
ok('ANULADO DESPUÉS DE ALISTAR: SIGUE EN «ALISTADOS HOY» PARA DESARMAR LA CAJA',
   ($h = array_values(array_filter(pedidos_alistados_hoy(), fn($x) => (int)$x['id'] === $ped_a))) && $h[0]['anulado_en'] !== null);
ok('y ya no se deshace', !pedido_alistado_deshacer($ped_a)['ok']);
q('UPDATE pedidos SET alistado_en = NULL, alistado_foto = NULL, alistado_quien_id = NULL WHERE id = ?', [$ped_a]);
es('UNO ANULADO SALE DE LA LISTA', 0, pedidos_por_alistar_n());
ok('y no se marca', !pedido_alistar($ped_a, $juan, $foto_a)['ok']);
q('UPDATE pedidos SET anulado_en = NULL WHERE id = ?', [$ped_a]);

/* LO QUE YA HABÍA SALIDO ANTES DE LA 3g no se le echa encima a Almacén. */
q("UPDATE ajustes SET valor = '' WHERE clave = 'alistar_arranque'"); ajustes_olvidar();
es('(uno mandado de antes)', 1, pedidos_por_alistar_n());
$m_a = alistado_arranque();
ok('LA ACTUALIZACIÓN DA POR ALISTADO LO QUE YA HABÍA SALIDO', str_contains($m_a, 'no entran') && pedidos_por_alistar_n() === 0, $m_a);
ok('sin foto ni quién, y sin salir en «alistados hoy»', valor('SELECT alistado_foto FROM pedidos WHERE id = ?', [$ped_a]) === null
   && !array_filter(pedidos_alistados_hoy(), fn($x) => (int)$x['id'] === $ped_a));
q('UPDATE pedidos SET alistado_en = NULL WHERE id = ?', [$ped_a]);
es('UNA SOLA VEZ: lo que se manda después sí entra', ['', 1], [alistado_arranque(), pedidos_por_alistar_n()]);
q('UPDATE pedidos SET anulado_en = NULL, despacho_veces = 0, despacho_en = NULL, alistado_en = NULL WHERE id = ?', [$ped_a]);
@unlink(HUB_SUBIDAS . '/vouchers/' . $foto_a);

exit(marcador());
