<?php
declare(strict_types=1);
/**
 * Arma el banco del servidor de pruebas: dos países, tres asesores, un líder,
 * Administración de cada país, y algunos clientes y pedidos.
 * Los nombres NO son de nadie real: viven solo en esta carpeta y no viajan
 * en el ZIP.
 */
require_once __DIR__ . '/comun.php';

$archivo = getenv('WAKA_BANCO') ?: (sys_get_temp_dir() . '/waka-http.sqlite');
banco_crear($archivo);

$PE = (int) valor("SELECT id FROM paises WHERE codigo = 'PE'");
$MX = insertar('paises', ['nombre' => 'México', 'codigo' => 'MX', 'moneda' => 'MXN',
                          'simbolo' => '$', 'activo' => 1]);
insertar('oficinas', ['pais_id' => $MX, 'nombre' => 'CDMX', 'orden' => 1]);

$EQ_PE = insertar('equipos', ['pais_id' => $PE, 'nombre' => 'Equipo Uno', 'activo' => 1]);

$clave = fn(string $c) => password_hash($c, PASSWORD_DEFAULT);
$mk = function (string $rol, string $email, int $pais, array $extra = []) use ($clave) {
    return insertar('usuarios', array_merge([
        'pais_id' => $pais,
        'rol_id'  => (int) valor('SELECT id FROM roles WHERE clave = ?', [$rol]),
        'nombre'  => 'Cuenta', 'apellidos' => 'De Prueba',
        'email'   => $email, 'password_hash' => $clave('Clave-Larga-1'),
        'ambito'  => $rol === 'asesor' ? 'propio' : 'todo',
        'activo'  => 1, 'debe_cambiar_password' => 0,
    ], $extra));
};

$ids = [
    'asesor'  => $mk('asesor',        'asesor@waka.test',  $PE, ['equipo_id' => $EQ_PE]),
    'otro'    => $mk('asesor',        'otro@waka.test',    $PE),
    'lider'   => $mk('asesor',        'lider@waka.test',   $PE, ['equipo_id' => $EQ_PE, 'ambito' => 'equipo']),
    'admin'   => $mk('administracion','admin@waka.test',   $PE),
    'factu'   => $mk('facturacion',   'factu@waka.test',   $PE),
    'factumx' => $mk('facturacion',   'factumx@waka.test', $MX),
    /* Una cuenta de Facturación con el ámbito estrecho. No se crea así desde
       la pantalla —el alta lo fuerza a «todo»— pero puede quedar así una vieja
       o un UPDATE a mano, y entonces la pantalla de facturar tiene que cortar
       igual que la ficha del pedido. */
    'factuflaco' => $mk('facturacion', 'factuflaco@waka.test', $PE, ['ambito' => 'propio']),
    'adminmx' => $mk('administracion','adminmx@waka.test', $MX),
    'ceo'     => $mk('direccion',     'ceo@waka.test',     $PE),
    /* 3g: los dos roles que no venden. */
    'almacen'   => $mk('almacen',     'almacen@waka.test',   $PE),
    'marketing' => $mk('marketing',   'marketing@waka.test', $PE),
];

$_SESSION['usuario_id'] = $ids['asesor'];
yo_olvidar();

$canal = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id=i.lista_id
                       WHERE l.clave='canales' LIMIT 1");
$efectivo = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id=i.lista_id
                          WHERE l.clave='metodos_pago' AND i.valor='Efectivo'");

$cli = fn(int $pais, int $asesor, string $doc, string $mail) => insertar('clientes', [
    'pais_id' => $pais, 'asesor_id' => $asesor, 'tipo_doc' => 'DNI', 'documento' => $doc,
    'nombre' => 'Cliente', 'apellidos' => 'Prueba', 'email' => $mail,
    'celular' => '900111222', 'canal_item_id' => $canal, 'activo' => 1,
]);

$c_asesor = $cli($PE, $ids['asesor'], '70636127', 'cli-asesor@waka.test');
$c_otro   = $cli($PE, $ids['otro'],   '45332647', 'cli-otro@waka.test');
$c_mx     = $cli($MX, $ids['adminmx'],'99887766', 'cli-mx@waka.test');

/* Los pedidos de fixtura son ENVÍOS A LIMA. No es un capricho: desde que la
   ventana de entrega solo existe donde entregamos nosotros, un pedido sin
   distrito es un recojo en oficina y no admite día ni hora, así que las
   pruebas de despacho no tendrían sobre qué correr. */
$sjl = (int) valor("SELECT id FROM ubigeo WHERE tipo='distrito' AND nombre='San Juan de Lurigancho'");

$pedido = function (int $pais, int $cliente, int $asesor, int $monto) use ($canal, $sjl) {
    $estado = estado_por_clave('registrado');
    $id = insertar('pedidos', [
        'codigo' => 'tmp-' . bin2hex(random_bytes(4)), 'pais_id' => $pais,
        'cliente_id' => $cliente, 'asesor_id' => $asesor, 'estado_id' => (int)$estado['id'],
        'tipo' => 'inmediata', 'fecha' => date('Y-m-d'), 'canal_item_id' => $canal,
        'entrega' => 'envio', 'ubigeo_id' => $sjl ?: null,
        'direccion_txt' => 'Av. Próceres 500',
    ]);
    actualizar('pedidos', $id, ['codigo' => pedido_codigo_de_id($id)]);
    insertar('pedido_lineas', ['pedido_id' => $id, 'descripcion' => 'Silla Gamer Comic Duality',
        'cantidad' => 1, 'precio_unit_centimos' => $monto, 'total_centimos' => $monto]);
    pedido_recalcular($id);
    return $id;
};

$datos = [
    'PE' => $PE, 'MX' => $MX, 'EQ_PE' => $EQ_PE,
    'usuarios' => $ids,
    'clientes' => ['asesor' => $c_asesor, 'otro' => $c_otro, 'mx' => $c_mx],
    'pedidos'  => [
        'asesor' => $pedido($PE, $c_asesor, $ids['asesor'], 37900),
        'otro'   => $pedido($PE, $c_otro,   $ids['otro'],   50000),
        'mx'     => $pedido($MX, $c_mx,     $ids['adminmx'],80000),
    ],
    'canal' => $canal, 'efectivo' => $efectivo,
];

/* Dos contenedores en camino. Están aquí para que la línea del saludo tenga
   algo que decir: sin ellos, «no habla de contenedores» pasaría por casualidad
   en vez de por permiso. */
insertar('lotes', ['pais_id' => $PE, 'codigo' => 'LOTE-P1', 'nombre' => 'Contenedor uno',
                   'estado' => 'en_camino', 'precios_listos' => 0]);
insertar('lotes', ['pais_id' => $PE, 'codigo' => 'LOTE-P2', 'nombre' => 'Contenedor dos',
                   'estado' => 'en_aduana',  'precios_listos' => 1]);

// Un pago pendiente, para la bandeja de validación.
$trans = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id=i.lista_id
                       WHERE l.clave='metodos_pago' AND i.valor='Transferencia BCP'");
$r = pago_registrar($datos['pedidos']['asesor'],
    ['monto_centimos' => 10000, 'metodo_item_id' => $trans, 'fecha' => date('Y-m-d'),
     'voucher' => 'v20260908-aaaaaaaaaaaa.jpg']);
$datos['pago_pendiente'] = $r['id'];

/* El archivo del voucher tiene que existir de verdad, o la prueba de que se
   sirve con permiso mediría un 404 en vez del 200. */
$dir = HUB_SUBIDAS . '/vouchers';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
$img = imagecreatetruecolor(40, 40);
imagejpeg($img, $dir . '/v20260908-aaaaaaaaaaaa.jpg');
imagedestroy($img);

file_put_contents(sys_get_temp_dir() . '/waka-fixtura.json', json_encode($datos));
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    echo "banco listo: $archivo\n";
}
