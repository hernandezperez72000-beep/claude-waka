<?php
declare(strict_types=1);
/**
 * Pruebas por HTTP de verdad: un servidor PHP, cookies, redirecciones, CSRF y
 * códigos de estado. Aquí es donde se comprueba que el aislamiento vive en el
 * SERVIDOR y no en esconder el menú: escribir la dirección a mano tiene que
 * devolver 403.
 *
 *   php pruebas/fixtura.php
 *   bash pruebas/servidor.sh start
 *   php pruebas/correr3.php
 */
/* La base se REHACE en cada pasada. Si se reutilizara, el cliente que creó la
   pasada anterior seguiría ahí y la prueba de «con todo bien, sí se crea»
   fallaría por documento repetido: un fallo que no habla de ningún error.
   El servidor abre su propia conexión en cada petición, así que rehacer el
   archivo entre peticiones no le molesta.
   Además, las pruebas miran la MISMA base: unas cosas se comprueban por la
   pantalla y otras leyendo la fila, que es donde se ve si el servidor guardó
   lo que dijo. */
require_once __DIR__ . '/fixtura.php';
require_once __DIR__ . '/http.php';

$BASE = 'http://127.0.0.1:' . (getenv('WAKA_PUERTO') ?: '8123');
$F = $datos;

$n = new Navegador($BASE);
$n->ir('/');
if ($n->codigo !== 200) {
    echo "El servidor no responde en $BASE. Levántalo con: bash pruebas/servidor.sh start\n";
    exit(1);
}

/* ═══════════════════  ACCESO  ═══════════════════ */
grupo('Acceso · sin sesión no se entra a ninguna parte');

foreach (['/clientes', '/pedidos', '/pagos/por-validar', '/reportes/pagos',
          '/configuracion/equipos', '/clientes/nuevo', '/pedidos/nuevo'] as $ruta) {
    $n->ir($ruta, null, false);
    ok("sin sesión, $ruta manda al acceso", $n->codigo === 302);
}

$n->salir();
ok('el asesor entra', $n->entrar('asesor@waka.test', 'Clave-Larga-1'));
ok('la hoja de estilo lleva su versión pegada',
   (bool) preg_match('/hub\.css\?v=\d+/', $n->cuerpo),
   'sin ?v= el service worker sirve el CSS viejo tras cada actualización');

/* ═══════════════════  PERMISOS  ═══════════════════ */
grupo('Permisos · el menú no es la barrera');

$n->ir('/clientes');  ok('el asesor entra a Clientes', $n->codigo === 200);
$n->ir('/pedidos');   ok('el asesor entra a Pedidos',  $n->codigo === 200);
$n->ir('/usuarios');  ok('el asesor NO entra a Usuarios (403)', $n->codigo === 403);
$n->ir('/configuracion/equipos');
ok('el asesor NO entra a Equipos (403)', $n->codigo === 403);
$n->ir('/pagos/por-validar');
ok('el asesor NO valida pagos (403)', $n->codigo === 403);
$n->ir('/reportes/pagos');
ok('el asesor NO ve el reporte de pagos (403)', $n->codigo === 403);
$n->ir('/reportes/pagos?excel=1');
ok('ni la descarga a Excel (403)', $n->codigo === 403);

/* ═══════════════════  AISLAMIENTO  ═══════════════════ */
grupo('Aislamiento · escribir la dirección a mano no sirve de nada');

$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['otro']);
ok('el asesor NO ve el pedido de otro asesor (403)', $n->codigo === 403);
$n->ir('/clientes/ficha?id=' . (int)$F['clientes']['otro']);
ok('el asesor NO ve el cliente de otro asesor (403)', $n->codigo === 403);
$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['mx']);
ok('el asesor NO ve un pedido de México (403)', $n->codigo === 403);
$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['asesor']);
ok('el suyo sí lo ve', $n->codigo === 200);

$n->ir('/clientes/buscar?q=Cliente');
$j = json_decode($n->cuerpo, true);
$ids = array_column($j['clientes'] ?? [], 'id');
ok('el buscador JSON tampoco filtra clientes ajenos',
   in_array((int)$F['clientes']['asesor'], $ids, true)
   && !in_array((int)$F['clientes']['otro'], $ids, true)
   && !in_array((int)$F['clientes']['mx'], $ids, true),
   'ids devueltos: ' . implode(',', $ids));

$n->ir('/pagos/voucher?id=' . (int)$F['pago_pendiente']);
ok('el voucher de su propio pago sí se sirve', $n->codigo === 200);

grupo('Aislamiento · el líder LEE a su equipo pero no lo TOCA');

$n->salir();
$n->entrar('lider@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['asesor']);
ok('el líder ve el pedido de uno de los suyos', $n->codigo === 200);
ok('pero no le sale el botón de anular',
   !str_contains($n->cuerpo, 'action="/pedidos/anular"'));
ok('ni el formulario de registrar un pago',
   !str_contains($n->cuerpo, 'action="/pagos/registrar"'));

$n->ir('/pedidos/estado', ['_t' => $n->testigoValido(), 'id' => (int)$F['pedidos']['asesor'],
                           'estado' => 'entregado'], false);
ok('y si fuerza el POST, el servidor responde 403', $n->codigo === 403);

$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['otro']);
ok('a uno de fuera de su equipo tampoco llega (403)', $n->codigo === 403);

/* Lo encontró la auditoría: mirar la cartera de los suyos no es poder
   venderle. Si el líder registra el pedido, la venta nace a SU nombre y le
   suma a su meta, a su podio y a sus bonos: la disputa de fin de mes que el
   ámbito de solo lectura existe para evitar. */
$n->ir('/clientes/ficha?id=' . (int)$F['clientes']['asesor']);
ok('el líder ve la ficha del cliente de uno de los suyos', $n->codigo === 200);
ok('pero NO se le ofrece registrarle un pedido',
   !str_contains($n->cuerpo, '/pedidos/nuevo?cliente=' . (int)$F['clientes']['asesor']));

$n->ir('/pedidos/nuevo?cliente=' . (int)$F['clientes']['asesor'], null, false);
ok('y si escribe la dirección a mano, 403', $n->codigo === 403);

$antes = (int) valor('SELECT COUNT(*) FROM pedidos');
$n->ir('/pedidos/nuevo', [
    '_t' => $n->testigoValido(), 'tipo' => 'inmediata',
    'cliente_id' => (int)$F['clientes']['asesor'], 'entrega' => 'recojo',
    'canal_item_id' => (int)$F['canal'], 'l_desc' => ['Silla'], 'l_sku' => [''],
    'l_cant' => ['1'], 'l_precio' => ['379'], 'fecha' => date('Y-m-d'),
], false);
ok('y si fuerza el POST, también 403', $n->codigo === 403);
es('no se creó ningún pedido', $antes, (int) valor('SELECT COUNT(*) FROM pedidos'));

grupo('El asesor se entera de que le pararon un pago · lo pidió el usuario');

/* La contrapartida que faltaba: la auditoría 14 anotó que un texto prometía
   que «el asesor recibirá el motivo» y ese aviso no existía. */
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');

$pg_esp = (int) valor("SELECT pg.id FROM pagos pg JOIN pedidos pe ON pe.id = pg.pedido_id
                        WHERE pe.asesor_id = ? AND pg.anulado = 0 AND pg.verificado = 0
                          AND pg.en_espera = 0 AND pg.denegado = 0
                        ORDER BY pg.id DESC LIMIT 1", [(int)$F['usuarios']['asesor']]);
ok('hay un pago del asesor sin revisar para la prueba', $pg_esp > 0);

$n->ir('/pagos/espera', ['_t' => $n->testigoValido(), 'id' => $pg_esp,
                         'nota' => 'No aparece en el extracto de hoy'], false);
es('facturación lo deja en espera', 1,
   (int) valor('SELECT en_espera FROM pagos WHERE id = ?', [$pg_esp]));

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');

$plano_i = preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo));
ok('el asesor lo ve al entrar', str_contains($plano_i, 'Facturación paró'),
   'sin esto el HUB frena la venta y no le dice nunca por qué');
ok('Y CON EL MOTIVO ESCRITO', str_contains($plano_i, 'No aparece en el extracto de hoy'),
   'un aviso que dice «hay un problema» sin decir cuál obliga a entrar a buscarlo');

/* El sondeo en vivo: viaja en la MISMA respuesta que el del despacho, para no
   duplicarle las peticiones al asesor. */
$n->ir('/pedidos/nuevos-despacho?desde=999999');
$j = json_decode($n->cuerpo, true);
ok('el sondeo del asesor trae también los pagos trabados',
   is_array($j) && isset($j['trabados']) && (int)$j['trabados'] >= 1, (string)$n->cuerpo);
/* Va por CUENTA y no por marca de agua: con marca, el primer pago trabado no
   avisaba nunca, y uno con id menor que otro ya trabado tampoco. */
ok('y va por cuenta, no por marca de agua', is_array($j) && !isset($j['trabados_ultimo']));

/* A quien los trabó NO se le avisa: sería contarle su propio trabajo hecho. */
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('a Facturación no se le avisa de lo que ella misma paró',
   !str_contains(preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo)), 'Facturación paró'));

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');


grupo('Ninguna pantalla promete lo que todavía no existe');

/* La tarjeta «Tu racha» leía una tabla que nadie llena: decía «Sin racha
   todavía · mejor marca 0» por muchas ventas que registrara el asesor. Lo
   destapó una pregunta del usuario. Mismo fallo que la pantalla de recuperar
   contraseña prometiendo un correo que nadie enviaba. */
$n->ir('/inicio');
$plano_r = preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo));
ok('el Inicio del asesor abre', $n->codigo === 200);
ok('el botón NUEVO PEDIDO lleva a crear un pedido, no a la lista',
   str_contains($n->cuerpo, 'pedidos/nuevo"'),
   'el botón dominante del Inicio obligaba a un clic de más en lo que más se repite');
ok('5a · la tarjeta de racha ya es de verdad: hoy, días seguidos y su mejor',
   str_contains($plano_r, 'Tu racha') && str_contains($plano_r, 'Días seguidos') && str_contains($plano_r, 'tu mejor'));
ok('y ya no dice «Sin racha todavía»', !str_contains($plano_r, 'Sin racha todavía'),
   'la racha es un número, no una frase vacía');


grupo('Aislamiento · Administración de México no toca Perú');

$n->salir();
$n->entrar('adminmx@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['asesor']);
ok('el administrador de México NO ve un pedido de Perú (403)', $n->codigo === 403);
$n->ir('/clientes/ficha?id=' . (int)$F['clientes']['asesor']);
ok('ni su cliente (403)', $n->codigo === 403);
$n->ir('/pagos/voucher?id=' . (int)$F['pago_pendiente']);
ok('ni su voucher (403)', $n->codigo === 403);
$n->ir('/pagos/por-validar');
ok('su bandeja de validación existe', $n->codigo === 200);
ok('y NO lista el pago pendiente de Perú',
   !str_contains($n->cuerpo, 'value="' . (int)$F['pago_pendiente'] . '"'));

$n->ir('/pagos/validar', ['_t' => $n->testigoValido(), 'id' => (int)$F['pago_pendiente']], false);
ok('y si fuerza la validación de un pago peruano, 403', $n->codigo === 403);

/* ═══════════════════  CSRF  ═══════════════════ */
grupo('CSRF · ninguna escritura pasa sin testigo');

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/clientes/nuevo');
$n->ir('/clientes/nuevo', ['nombre' => 'Sin', 'apellidos' => 'Testigo'], false);
ok('un POST sin testigo devuelve 419', $n->codigo === 419);
$n->ir('/pedidos/estado', ['id' => 1, 'estado' => 'pagado'], false);
ok('tampoco cambia un estado sin testigo', $n->codigo === 419);

/* ═══════════════════  ALTA DE CLIENTE  ═══════════════════ */
grupo('Alta de cliente · lo que no deja pasar');

$base_cliente = [
    'tipo_doc' => 'DNI', 'nombre' => 'Nueva', 'apellidos' => 'Persona',
    'email' => 'nueva@waka.test', 'celular' => '999888777',
    'canal_item_id' => (int)$F['canal'], 'tipo_comprobante' => 'boleta',
];

$n->ir('/clientes/nuevo');
$n->ir('/clientes/nuevo', array_merge($base_cliente,
      ['_t' => $n->testigo(), 'documento' => '1234567']));
ok('un DNI de 7 dígitos no pasa', str_contains($n->cuerpo, '8 dígitos'));

$n->ir('/clientes/nuevo');
$n->ir('/clientes/nuevo', array_merge($base_cliente,
      ['_t' => $n->testigo(), 'documento' => '70636127']));
ok('un documento ya registrado no crea una segunda ficha',
   str_contains($n->cuerpo, 'ese documento'));
ok('y ofrece abrir la ficha que ya existe',
   str_contains($n->cuerpo, '/clientes/ficha?id='));

$n->ir('/clientes/nuevo');
$n->ir('/clientes/nuevo', array_merge($base_cliente,
      ['_t' => $n->testigo(), 'documento' => '10000009', 'email' => '']));
ok('sin correo no se guarda: es la llave del cashback',
   str_contains($n->cuerpo, 'Falta el correo'));

$n->ir('/clientes/nuevo');
$n->ir('/clientes/nuevo', array_merge($base_cliente,
      ['_t' => $n->testigo(), 'documento' => '10000009', 'email' => 'ok@waka.test']));
ok('con todo bien, sí se crea', str_contains($n->cuerpo, 'Ficha creada'));
ok('y queda en cola para su cuenta de la tienda',
   str_contains($n->cuerpo, 'cola'));

grupo('Editar un cliente · el refactor no aflojó nada');

$cli_id = (int)$F['clientes']['asesor'];
$edicion = [
    'tipo_doc' => 'DNI', 'documento' => '70636127', 'nombre' => 'Cliente',
    'apellidos' => 'Prueba', 'email' => 'cli-asesor@waka.test', 'celular' => '900111222',
    'canal_item_id' => (int)$F['canal'], 'tipo_comprobante' => 'boleta',
];
$n->ir('/clientes/editar?id=' . $cli_id);
$n->ir('/clientes/editar?id=' . $cli_id, array_merge($edicion, [
    '_t' => $n->testigo(),
    // Campos inyectados que el formulario no ofrece
    'pais_id' => (int)$F['MX'], 'asesor_id' => (int)$F['usuarios']['otro'],
    'activo' => 0, 'estado_tienda' => 'creada',
]));
es('guardar su propia ficha no la reclama como duplicada', (int)$F['PE'],
   (int) valor('SELECT pais_id FROM clientes WHERE id = ?', [$cli_id]));
es('el asesor inyectado no cambia nada', (int)$F['usuarios']['asesor'],
   (int) valor('SELECT asesor_id FROM clientes WHERE id = ?', [$cli_id]));
es('ni se puede apagar la ficha desde el formulario', 1,
   (int) valor('SELECT activo FROM clientes WHERE id = ?', [$cli_id]));

$n->ir('/clientes/editar?id=' . $cli_id);
$n->ir('/clientes/editar?id=' . $cli_id, array_merge($edicion, ['_t' => $n->testigo(), 'email' => '']));
ok('y sigue exigiendo el correo', str_contains($n->cuerpo, 'Falta el correo'));

$n->ir('/clientes/editar?id=' . (int)$F['clientes']['otro'], null, false);
ok('la ficha de otro asesor no se edita (403)', $n->codigo === 403);

/* ═══════════════  ALTA RÁPIDA DESDE EL PEDIDO  ═══════════════ */
grupo('Alta rápida de cliente · pide lo mismo que la pantalla normal');

$rapido = function (array $extra = []) use ($n) {
    $base = [
        '_t' => $n->testigoValido(), 'tipo_doc' => 'DNI', 'documento' => '10203040',
        'nombre' => 'Veloz', 'apellidos' => 'Registrado', 'email' => 'veloz@waka.test',
        'celular' => '999111222', 'canal_item_id' => 0,
    ];
    return array_merge($base, $extra);
};
$canal = (int)$F['canal'];

$n->ir('/clientes/rapido', $rapido(['canal_item_id' => $canal, 'email' => '']), false);
$j = json_decode($n->cuerpo, true);
ok('sin correo NO se crea', ($j['ok'] ?? true) === false, $n->cuerpo);
ok('y lo dice con el mismo mensaje que la pantalla normal',
   (bool) array_filter($j['errores'] ?? [], fn($x) => str_contains($x, 'Falta el correo')));

$n->ir('/clientes/rapido', $rapido(['canal_item_id' => 0]), false);
$j = json_decode($n->cuerpo, true);
ok('sin «cómo nos conoció» tampoco', ($j['ok'] ?? true) === false);

$n->ir('/clientes/rapido', $rapido(['canal_item_id' => $canal, 'documento' => '123']), false);
$j = json_decode($n->cuerpo, true);
ok('un DNI de 3 dígitos tampoco', ($j['ok'] ?? true) === false);

$n->ir('/clientes/rapido', $rapido(['canal_item_id' => $canal, 'documento' => '70636127',
                                    'email' => 'otro@waka.test']), false);
$j = json_decode($n->cuerpo, true);
ok('un documento que ya existe no crea una segunda ficha', ($j['ok'] ?? true) === false);
es('y devuelve la ficha que ya estaba, para poder usarla',
   (int)$F['clientes']['asesor'], (int)($j['existe']['id'] ?? 0));

$antes = (int) valor('SELECT COUNT(*) FROM clientes');
$n->ir('/clientes/rapido', $rapido(['canal_item_id' => $canal]), false);
$j = json_decode($n->cuerpo, true);
ok('con todo bien, se crea', ($j['ok'] ?? false) === true, $n->cuerpo);
es('y solo una vez', $antes + 1, (int) valor('SELECT COUNT(*) FROM clientes'));

$creado_cli = (int)($j['cliente']['id'] ?? 0);
es('queda a nombre del asesor que lo registró', (int)$F['usuarios']['asesor'],
   (int) valor('SELECT asesor_id FROM clientes WHERE id = ?', [$creado_cli]));
es('y en SU país', (int)$F['PE'],
   (int) valor('SELECT pais_id FROM clientes WHERE id = ?', [$creado_cli]));
es('entra en la cola de la tienda igual que el alta normal', 1,
   (int) valor('SELECT COUNT(*) FROM cola_tienda WHERE cliente_id = ?', [$creado_cli]));

$n->ir('/clientes/rapido', ['documento' => '11223344', 'nombre' => 'Sin', 'apellidos' => 'Testigo',
                            'email' => 'st@waka.test', 'celular' => '9', 'canal_item_id' => $canal], false);
ok('sin testigo CSRF no pasa', $n->codigo === 419);

$n->ir('/clientes/rapido', null, false);
ok('por GET no existe', $n->codigo === 404);

/* La auditoría del delta: si el duplicado es de OTRO asesor, se avisa de que
   existe pero NO se dice de quién. Si no, este formulario sería un buscador
   encubierto de la cartera de al lado: se teclea un documento y sale el nombre. */
$n->ir('/clientes/rapido', $rapido(['canal_item_id' => $canal, 'documento' => '45332647',
                                    'email' => 'sondeo@waka.test']), false);
$j = json_decode($n->cuerpo, true);
ok('avisa de que ese documento ya está', ($j['ok'] ?? true) === false);
es('pero NO delata la ficha del asesor de al lado', null, $j['existe'] ?? null);
ok('y lo dice sin nombres',
   (bool) array_filter($j['errores'] ?? [], fn($x) => str_contains($x, 'otro asesor')));

$n->ir('/clientes/rapido', $rapido(['canal_item_id' => $canal, 'documento' => '70636127',
                                    'email' => 'sondeo2@waka.test']), false);
$j = json_decode($n->cuerpo, true);
ok('la suya sí se la ofrece, y marcada como usable',
   (int)($j['existe']['id'] ?? 0) === (int)$F['clientes']['asesor']
   && ($j['existe']['usable'] ?? false) === true, $n->cuerpo);

$n->salir();
$n->entrar('ceo@waka.test', 'Clave-Larga-1');
$n->ir('/clientes/rapido', $rapido(['canal_item_id' => $canal, 'documento' => '55667788',
                                    'email' => 'ceo-intenta@waka.test']), false);
ok('Dirección no puede crear clientes ni por aquí (403)', $n->codigo === 403);

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');

/* ═══════════════════  ALTA DE PEDIDO  ═══════════════════ */
grupo('Alta de pedido · el asesor de la venta no se elige a dedo');

$n->ir('/pedidos/nuevo?cliente=' . (int)$F['clientes']['asesor']);
ok('el formulario abre con el cliente ya puesto', $n->codigo === 200);

$post = [
    '_t' => $n->testigo(),
    'tipo' => 'inmediata', 'cliente_id' => (int)$F['clientes']['asesor'],
    'asesor_id' => (int)$F['usuarios']['otro'],          // ← intento de escalada
    'entrega' => 'recojo', 'canal_item_id' => (int)$F['canal'],
    'l_desc' => ['Silla Gamer Comic Duality'], 'l_sku' => [''],
    'l_cant' => ['1'], 'l_precio' => ['379'],
    'fecha' => date('Y-m-d'),
    /* Desde el 2026-09-11 un pedido no se registra sin dinero: «no pueden
       generar pedidos sin pagos» (usuario). Todos los POST de este banco
       llevan su primer pago. */
    'modo_pago' => 'completo', 'pago_monto' => '379.00',
    'pago_metodo_item_id' => (int)$F['efectivo'], 'pago_fecha' => date('Y-m-d'),
];
$n->ir('/pedidos/nuevo', $post);
ok('el pedido se registra', str_contains($n->cuerpo, 'Pedido registrado'));

$creado = (int) valor("SELECT id FROM pedidos ORDER BY id DESC LIMIT 1");
es('el pedido queda a nombre de QUIEN lo registró, no del que puso en el formulario',
   (int)$F['usuarios']['asesor'],
   (int) valor('SELECT asesor_id FROM pedidos WHERE id = ?', [$creado]));
es('y el código se genera solo', 'P-' . str_pad((string)$creado, 5, '0', STR_PAD_LEFT),
   (string) valor('SELECT codigo FROM pedidos WHERE id = ?', [$creado]));

$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', array_merge($post, ['_t' => $n->testigo(),
    'cliente_id' => (int)$F['clientes']['otro']]));
ok('no se le puede hacer un pedido al cliente de otro asesor', $n->codigo === 403);

grupo('El voucher sobrevive al rebote · lo reportó el usuario probando');

/* Un JPG de verdad, chiquito: guardar_voucher() lo vuelve a codificar con GD,
   así que un archivo con cara de imagen no pasaría. */
$img = imagecreatetruecolor(40, 20);
imagefilledrectangle($img, 0, 0, 40, 20, imagecolorallocate($img, 250, 217, 26));
ob_start(); imagejpeg($img, null, 80); $JPG = (string) ob_get_clean();
imagedestroy($img);

$n->ir('/pedidos/nuevo?cliente=' . (int)$F['clientes']['asesor']);
$post_v = [
    '_t' => $n->testigo(),
    'tipo' => 'inmediata', 'cliente_id' => (int)$F['clientes']['asesor'],
    'entrega' => 'envio',                    // ← envío SIN distrito: rebota
    'canal_item_id' => (int)$F['canal'],
    'l_desc' => ['Silla Gamer Comic Duality'], 'l_sku' => [''],
    'l_cant' => ['1'], 'l_precio' => ['379'],
    'fecha' => date('Y-m-d'),
    'pago_monto' => '379', 'pago_metodo_item_id' => (int)$F['efectivo'],
    'pago_fecha' => date('Y-m-d'),
];
$n->subir('/pedidos/nuevo', $post_v, ['voucher' => ['banco.jpg', 'image/jpeg', $JPG]]);

ok('el formulario rebota por el distrito que falta',
   str_contains($n->cuerpo, 'distrito'), mb_substr(strip_tags($n->cuerpo), 0, 200));
ok('y AVISA de que el voucher sigue adjunto',
   str_contains($n->cuerpo, 'Adjunto:'),
   'sin este cartel la pantalla parece decir que no hay voucher, y el asesor lo cree');

/* Y ahora lo importante: corrige el error SIN volver a elegir el archivo
   —que es justo lo que el navegador no le deja hacer— y el pago tiene que
   quedar con su voucher igual. */
$antes_v = (int) valor('SELECT COUNT(*) FROM pagos');
/* Se reenvía TAMBIÉN el `f_id`, que es lo que el navegador hace solo: es lo
   que ata el voucher a ESTE formulario aunque cambie el cliente. */
$n->ir('/pedidos/nuevo', array_merge($post_v, [
    '_t' => $n->testigo(), 'entrega' => 'recojo', 'f_id' => $n->oculto('f_id'),
]));
ok('al corregir el error el pedido se registra', str_contains($n->cuerpo, 'Pedido registrado'));
es('y se anotó el pago', $antes_v + 1, (int) valor('SELECT COUNT(*) FROM pagos'));

$voucher_final = (string) valor('SELECT voucher FROM pagos ORDER BY id DESC LIMIT 1');
ok('EL VOUCHER NO SE PERDIÓ', $voucher_final !== '',
   'el asesor lo subió una vez y el HUB se lo tenía que acordar');
ok('y el archivo está de verdad en el disco',
   $voucher_final !== '' && is_file(ruta_voucher($voucher_final)));

/* Y no se le queda pegado al siguiente pedido: sería colgarle a una venta el
   comprobante de otra. */
$n->ir('/pedidos/nuevo');
ok('el siguiente pedido ya no lo arrastra', !str_contains($n->cuerpo, 'Adjunto:'));

/* DOS PESTAÑAS. Cada formulario tiene su propio `f_id` desde el parche 2j, y
   la sesión guardaba UN SOLO voucher en espera: subirlo en la segunda pestaña
   borraba el archivo de la primera, que luego se guardaba sin comprobante y,
   con efectivo, sin un solo aviso. */
$n->ir('/pedidos/nuevo');            $f_uno = $n->oculto('f_id');
$n->ir('/pedidos/nuevo');            $f_dos = $n->oculto('f_id');
ok('cada formulario trae su propio identificador', $f_uno !== '' && $f_uno !== $f_dos);
$n->subir('/pedidos/nuevo', array_merge($post_v, ['_t' => $n->testigo(), 'f_id' => $f_uno,
    'ubigeo_id' => '']), ['voucher' => ['uno.jpg', 'image/jpeg', $JPG]]);
$n->subir('/pedidos/nuevo', array_merge($post_v, ['_t' => $n->testigo(), 'f_id' => $f_dos,
    'ubigeo_id' => '']), ['voucher' => ['dos.jpg', 'image/jpeg', $JPG]]);
$n->ir('/pedidos/nuevo', array_merge($post_v, ['_t' => $n->testigo(), 'f_id' => $f_uno,
    'entrega' => 'recojo']));
ok('la primera pestaña conserva su voucher', str_contains($n->cuerpo, 'Pedido registrado'));
es('y se guardó con él', 1, (int) (valor('SELECT voucher FROM pagos ORDER BY id DESC LIMIT 1')
                                   ? 1 : 0));
$n->ir('/pedidos/nuevo', array_merge($post_v, ['_t' => $n->testigo(), 'f_id' => $f_dos,
    'entrega' => 'recojo']));
es('y la segunda también', 1, (int) (valor('SELECT voucher FROM pagos ORDER BY id DESC LIMIT 1')
                                     ? 1 : 0));


/* ── Los hallazgos de la auditoría del propio parche ────────────────── */

/* GRAVE 1: denegar es anular, así que «anulado = 0 AND (… OR denegado = 1)»
   dejaba la denegación fuera del aviso — el caso urgente, el que hay que
   rehacer. Las pruebas de arriba solo probaban «en espera». */
/* Una venta nueva con adelanto: deja saldo, que es lo que hace que la
   denegación siga siendo tarea del asesor. */
$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', array_merge($post_v, [
    '_t' => $n->testigo(), 'entrega' => 'recojo',
    'l_precio' => ['500'], 'pago_monto' => '100',
]));
ok('se registra una venta con adelanto', str_contains($n->cuerpo, 'Pedido registrado'));
$pg_den = (int) valor('SELECT MAX(id) FROM pagos');
ok('hay un pago para denegar', $pg_den > 0);

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/denegar', ['_t'=>$n->testigoValido(), 'id'=>$pg_den,
                          'motivo'=>'El voucher es de otra cuenta'], false);
es('facturación lo deniega', 1, (int) valor('SELECT denegado FROM pagos WHERE id = ?', [$pg_den]));

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
$plano_d = preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo));
ok('AL ASESOR TAMBIÉN LE LLEGA UNA DENEGACIÓN', str_contains($plano_d, 'Denegado'),
   'denegar es anular: la condición dejaba fuera justo el caso que hay que rehacer');
ok('y con su motivo', str_contains($plano_d, 'El voucher es de otra cuenta'));

/* GRAVE 2: el voucher a medias no puede saltar de un pedido a otro. */
$n->ir('/pedidos/nuevo?cliente=' . (int)$F['clientes']['asesor']);
$n->subir('/pedidos/nuevo', array_merge($post_v, ['_t' => $n->testigo()]),
          ['voucher' => ['de-otro.jpg', 'image/jpeg', $JPG]]);
ok('queda un voucher a medias en el pedido nuevo', str_contains($n->cuerpo, 'Adjunto:'));

$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['asesor']);
ok('LA FICHA DE OTRO PEDIDO NO SE LO CUELGA', !str_contains($n->cuerpo, 'Adjunto:'),
   'sin atarlo a su pantalla, el comprobante del cliente A acababa en el pago del cliente B');

/* GRAVE 3: en la ficha, un monto mal escrito se llevaba el voucher por delante. */
$antes_v2 = (int) valor('SELECT COUNT(*) FROM pagos');
$n->subir('/pagos/registrar', ['concepto'=>'Saldo de prueba', 
    '_t' => $n->testigoValido(), 'pedido_id' => (int)$F['pedidos']['asesor'],
    'monto' => 'trescientos', 'metodo_item_id' => (int)$F['efectivo'],
    'fecha' => date('Y-m-d'),
], ['voucher' => ['banco2.jpg', 'image/jpeg', $JPG]]);
es('no se registró el pago con el monto ilegible', $antes_v2,
   (int) valor('SELECT COUNT(*) FROM pagos'));
$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['asesor']);
ok('pero el voucher SIGUE ADJUNTO en la ficha', str_contains($n->cuerpo, 'Adjunto:'),
   'el ir() del monto estaba encima de guardar el archivo');

/* La bandeja de facturación no puede pintar un estado con el amarillo de marca. */
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/por-validar');
ok('la bandeja no usa el amarillo de marca para un estado',
   !str_contains($n->cuerpo, 'chip--marca'),
   'tiene 1.36:1 de contraste y está reservado para botones');

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');





/* LA FECHA DEL PEDIDO YA NO LA ELIGE EL ASESOR (usuario, 2026-09-11): «la
   fecha del pedido no sería la fecha en la que se registra el pedido y estaría
   de más?». Un campo menos en una pantalla larga. Se queda para quien confirma
   dinero —Administración, Facturación— porque ellos sí registran cosas de
   ayer. Al asesor ni se le enseña, y si el campo llegara igual (una pestaña
   vieja, alguien tocando el HTML) NO se hace caso: se sella con hoy. */
$n->ir('/pedidos/nuevo');
ok('al asesor no se le pregunta la fecha del pedido',
   !str_contains($n->cuerpo, 'name="fecha"'));

$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', array_merge($post, ['_t' => $n->testigo(),
    'fecha' => date('Y-m-d', strtotime('+3 days'))]));
ok('y una fecha futura mandada a mano no rebota: se ignora',
   str_contains($n->cuerpo, 'Pedido registrado'));
es('el pedido queda fechado hoy', date('Y-m-d'),
   (string) valor('SELECT fecha FROM pedidos ORDER BY id DESC LIMIT 1'));

$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', array_merge($post, ['_t' => $n->testigo(), 'canal_item_id' => '']));
ok('sin canal de venta no se guarda', str_contains($n->cuerpo, 'canal de venta'));

/* Lo encontró la auditoría: un precio de quince dígitos desbordaba el entero
   de PHP, se volvía float y acababa en una columna de dinero. Un solo pedido
   así destroza el «por cobrar» del panel del país. */
$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', array_merge($post, ['_t' => $n->testigo(),
    'l_precio' => ['999999999999999'], 'l_cant' => ['100000']]));
ok('un precio imposible se rechaza con un mensaje, no se guarda',
   str_contains($n->cuerpo, 'no puede ser ese') || str_contains($n->cuerpo, 'suma más de'));
es('y no queda ni una línea con un total desmesurado', 0,
   (int) valor('SELECT COUNT(*) FROM pedido_lineas WHERE total_centimos > ?',
               [DINERO_MAXIMO_CENTIMOS]));

/* Y a quien SÍ la elige se le sigue comprobando: ni de mañana ni del año
   pasado. Administración es la única cuenta que registra ventas y además
   confirma dinero, así que es la que ve el campo. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/nuevo');
ok('a Administración sí se le pregunta la fecha', str_contains($n->cuerpo, 'name="fecha"'));

$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', array_merge($post, ['_t' => $n->testigo(),
    'fecha' => date('Y-m-d', strtotime('+3 days'))]));
ok('la fecha del pedido no puede ser futura', str_contains($n->cuerpo, 'no puede ser futura'));

$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', array_merge($post, ['_t' => $n->testigo(),
    'fecha' => date('Y-m-d', strtotime('-400 days'))]));
ok('un pedido fechado hace más de un año se rechaza',
   str_contains($n->cuerpo, 'más de') && str_contains($n->cuerpo, 'días'));
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');

grupo('Las tres formas de recibirlo · cada rama pide solo lo suyo');

/* El formulario nuevo pregunta A DÓNDE VA en el paso 2, y el paso 5 pide solo
   lo que hace falta para ese caso. Antes pedía siempre lo mismo: a provincia
   exigía una dirección que nadie iba a usar, y «recoge en la agencia» —el caso
   MÁS usado fuera de Lima— no existía. */
$SJL     = (int) valor("SELECT id FROM ubigeo WHERE tipo='distrito' AND nombre='San Juan de Lurigancho'");
$WANCHAQ = (int) valor("SELECT d.id FROM ubigeo d JOIN ubigeo p ON p.id=d.padre_id
                         WHERE d.tipo='distrito' AND d.nombre='Wanchaq' AND p.nombre='Cusco'");
$AGENCIA = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id=i.lista_id
                         WHERE l.clave='agencias' ORDER BY i.orden LIMIT 1");
ok('el banco tiene San Juan de Lurigancho y Wanchaq', $SJL > 0 && $WANCHAQ > 0 && $AGENCIA > 0);

/* El formulario NO valida en el navegador, a propósito: en el celular los
   pasos se pliegan con display:none y un campo obligatorio dentro de un bloque
   oculto hace que Chrome aborte el envío en silencio —sin burbuja, sin error,
   sin petición—. El botón dejaba de funcionar y no había forma de saber por
   qué. Lo valida el servidor, que es donde hay que validarlo. */
$n->ir('/pedidos/nuevo');
ok('el formulario no valida en el navegador', str_contains($n->cuerpo, 'novalidate'));
ok('y ningún campo lleva required',
   !preg_match('/<(select|input)[^>]*\srequired/', $n->cuerpo));
ok('el paso 1 nace abierto aunque el JavaScript no corra',
   str_contains($n->cuerpo, 'paso paso--abierto'));

/* Y con errores no se pliega nada: el mensaje puede señalar un campo que
   quedaría dentro de un paso cerrado. */
$n->ir('/pedidos/nuevo', ['_t' => $n->testigo(), 'destino' => 'oficina']);
/* Se busca la CLASE en el contenedor, no el texto suelto: «pasos--todos»
   también aparece dentro del JavaScript de la página, así que buscarlo a secas
   daba verde con la clase quitada del HTML. */
ok('con errores, ningún paso se pliega',
   str_contains($n->cuerpo, 'class="pasos pasos--todos"'));

$base = [
    'tipo' => 'inmediata', 'cliente_id' => (int)$F['clientes']['asesor'],
    'canal_item_id' => (int)$F['canal'],
    'l_desc' => ['Silla Gamer'], 'l_sku' => [''], 'l_cant' => ['1'], 'l_precio' => ['500'],
    'fecha' => date('Y-m-d'),
    'modo_pago' => 'completo', 'pago_monto' => '500.00',
    'pago_metodo_item_id' => (int)$F['efectivo'], 'pago_fecha' => date('Y-m-d'),
    /* EL MAPA VIAJA SIEMPRE, como en la pantalla real: en Lima es obligatorio
       —lo repartimos nosotros— y fuera de Lima el servidor lo borra. Las dos
       cosas se comprueban más abajo. */
    'gps' => 'https://maps.app.goo.gl/prueba',
];
$mandar = function (array $extra) use ($n, $base) {
    $n->ir('/pedidos/nuevo');
    $n->ir('/pedidos/nuevo', array_merge($base, $extra, ['_t' => $n->testigo()]));
};
$ultimo = fn() => una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');

/* 1) Recoge en la agencia: distrito, agencia y sucursal. Ni dirección, ni
      referencia, ni mapa. Tres campos en vez de cuatro, y ninguno inventado. */
/* Se manda TAMBIÉN lo de la rama anterior —dirección, referencia, mapa, quién
   recibe—, porque esconder un bloque con `hidden` NO impide que sus campos
   viajen: el asesor empieza rellenando Lima, cambia a provincia, y esos datos
   siguen en el formulario. Si el servidor no los limpiara, un pedido que se
   recoge en la agencia de Cusco llevaría una dirección de Lima pegada. */
$mandar(['destino' => 'provincia', 'envio_modo' => 'agencia', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => $AGENCIA, 'sucursal' => 'Sucursal Wanchaq',
         'direccion_txt' => 'Av. Arequipa 100', 'referencia_txt' => 'Frente al parque',
         'recibe' => 'Su mamá', 'gps' => 'https://maps.google/x']);
ok('a provincia, recoge en la agencia, se registra sin dirección',
   str_contains($n->cuerpo, 'Pedido registrado'));
$p = $ultimo();
es('queda marcado como recojo en agencia', 1, (int)$p['recojo_agencia']);
es('y sigue siendo un ENVÍO, no un recojo en oficina', 'envio', (string)$p['entrega']);
es('la dirección que venía de la rama de Lima se limpia', '', (string)$p['direccion_txt']);
es('la referencia también', '', (string)$p['referencia_txt']);
es('y quién recibe', '', (string)$p['recibe']);
es('y el enlace del mapa: fuera de Lima no lo abre nadie', '', (string)$p['gps']);
es('la ficha lo dice con esas palabras', 'Recoge en la agencia', pedido_como_recibe($p));

/* 2) La misma venta a domicilio SÍ pide dirección. */
$mandar(['destino' => 'provincia', 'envio_modo' => 'domicilio', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => $AGENCIA]);
ok('a domicilio sin dirección, rebota', str_contains($n->cuerpo, 'Falta la dirección del reparto'));

$mandar(['destino' => 'provincia', 'envio_modo' => 'domicilio', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => $AGENCIA, 'direccion_txt' => 'Av. La Cultura 123']);
ok('con dirección, se registra', str_contains($n->cuerpo, 'Pedido registrado'));
$p = $ultimo();
es('y NO es recojo en agencia', 0, (int)$p['recojo_agencia']);
es('la ficha lo dice de otra manera', 'La agencia se lo lleva a su dirección', pedido_como_recibe($p));

/* 3) El distrito manda. Si lo marcado arriba y el distrito no cuadran, se dice
      cuál de los dos hay que cambiar en vez de corregirlo por detrás. */
$mandar(['destino' => 'lima', 'ubigeo_id' => $WANCHAQ, 'direccion_txt' => 'Calle 1']);
ok('marcar Lima con un distrito de Cusco avisa',
   str_contains($n->cuerpo, 'no es de Lima'));
$mandar(['destino' => 'provincia', 'envio_modo' => 'agencia', 'ubigeo_id' => $SJL,
         'agencia_item_id' => $AGENCIA, 'sucursal' => 'X']);
ok('y marcar Provincia con un distrito de Lima también',
   str_contains($n->cuerpo, 'es de Lima'));

/* 4) La ventana de entrega solo donde entregamos nosotros. A provincia el
      horario lo pone la agencia: aunque alguien mande la fecha, no se guarda. */
$mandar(['destino' => 'provincia', 'envio_modo' => 'agencia', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => $AGENCIA, 'sucursal' => 'Sucursal Wanchaq',
         'entrega_fecha' => date('Y-m-d'), 'entrega_hora_desde' => '09:00',
         'entrega_recepcion' => '1']);
ok('la venta a provincia se registra', str_contains($n->cuerpo, 'Pedido registrado'));
$p = $ultimo();
es('y NO se guarda ninguna fecha de entrega', null, $p['entrega_fecha']);
es('ni la marca de recepción', 0, (int)$p['entrega_recepcion']);

/* En Lima sí, que es donde entregamos nosotros. Y desde el parche 2r la fecha
   es a partir de MAÑANA: cuando se registra la venta, la ruta de hoy ya está
   armada (usuario, 2026-09-20). */
$MANANA3 = date('Y-m-d', strtotime('+1 day'));
$mandar(['destino' => 'lima', 'ubigeo_id' => $SJL, 'direccion_txt' => 'Av. Próceres 500',
         'entrega_fecha' => $MANANA3, 'entrega_hora_desde' => '09:00',
         'entrega_hora_hasta' => '13:00']);
ok('la venta en Lima se registra', str_contains($n->cuerpo, 'Pedido registrado'));
$p = $ultimo();
es('y ahí la fecha sí se guarda', $MANANA3, (string)$p['entrega_fecha']);

/* Y PARA HOY MISMO NO: se rechaza la ventana y se dice por qué. */
$mandar(['destino' => 'lima', 'ubigeo_id' => $SJL, 'direccion_txt' => 'Av. Próceres 500',
         'entrega_fecha' => date('Y-m-d'), 'entrega_hora_desde' => '09:00']);
ok('pedir la entrega para hoy mismo se rechaza',
   str_contains($n->cuerpo, 'a partir de mañana'), mb_substr(strip_tags($n->cuerpo), 0, 240));
es('la ficha lo dice', 'Se lo llevamos a su dirección', pedido_como_recibe($p));

/* 4bis) EN LIMA EL MAPA ES OBLIGATORIO (usuario, 2026-09-11): el reparto lo
   hacemos nosotros y una dirección escrita a mano no basta para encontrar la
   casa. Fuera de Lima no se pide: ahí entrega la agencia. */
$antes_sin_mapa = (int) valor('SELECT COUNT(*) FROM pedidos');
$mandar(['destino' => 'lima', 'ubigeo_id' => $SJL, 'direccion_txt' => 'Av. Próceres 500',
         'gps' => '']);
ok('en Lima, sin enlace de Google Maps, el pedido rebota',
   str_contains($n->cuerpo, 'Falta el enlace de Google Maps'));
es('y no se guardó nada', $antes_sin_mapa, (int) valor('SELECT COUNT(*) FROM pedidos'));

$mandar(['destino' => 'provincia', 'envio_modo' => 'agencia', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => $AGENCIA, 'sucursal' => 'Sucursal Wanchaq', 'gps' => '']);
ok('pero a provincia sin mapa se registra igual',
   str_contains($n->cuerpo, 'Pedido registrado'));

$mandar(['destino' => 'oficina', 'gps' => '']);
ok('y recoger en oficina tampoco pide mapa',
   str_contains($n->cuerpo, 'Pedido registrado'));

/* Y TIENE QUE SER UN ENLACE. Un obligatorio que acepta cualquier cosa se
   rellena con «sí» el primer día, y esto viaja al grupo de despacho. */
$mandar(['destino' => 'lima', 'ubigeo_id' => $SJL, 'direccion_txt' => 'Av. Próceres 500',
         'gps' => 'al costado del parque']);
ok('una frase no vale como mapa', str_contains($n->cuerpo, 'no es un enlace de Google Maps'));

/* Y TIENE QUE CABER ENTERO. Se guardaba recortado a 80 caracteres sin decirlo,
   y quien reparte abría un enlace partido que no lleva a ninguna parte. */
$largo = 'https://www.google.com/maps/place/' . str_repeat('Av.+Javier+Prado+Este+123,+', 12);
$mandar(['destino' => 'lima', 'ubigeo_id' => $SJL, 'direccion_txt' => 'Av. Próceres 500',
         'gps' => $largo]);
ok('un enlace larguísimo se rechaza en vez de recortarse',
   str_contains($n->cuerpo, 'no cabe entero'));

$normal = 'https://www.google.com/maps/place/Av.+Javier+Prado+Este+123,+San+Isidro+15046,+Per%C3%BA/@-12.0906,-77.0261,17z';
$mandar(['destino' => 'lima', 'ubigeo_id' => $SJL, 'direccion_txt' => 'Av. Próceres 500',
         'gps' => $normal]);
ok('y uno de los de verdad, pegado de la barra del navegador, entra',
   str_contains($n->cuerpo, 'Pedido registrado'));
$p_mapa = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
es('entero, sin recortar', $normal, (string)$p_mapa['gps']);
$n->ir('/pedidos/ficha?id=' . (int)$p_mapa['id']);
ok('y la ficha lo abre entero', str_contains($n->cuerpo, e($normal)),
   'el enlace llegó cortado o no llegó');

/* Y LE LLEGA AL GRUPO DE DESPACHO, que es para lo que se volvió obligatorio.
   El mensaje solo se arma con el dinero confirmado, así que primero se
   confirma. */
$pg_mapa = (int) valor('SELECT MAX(id) FROM pagos WHERE pedido_id = ?', [(int)$p_mapa['id']]);
$n->salir(); $n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/validar', ['_t'=>$n->testigoValido(), 'id'=>$pg_mapa], false);
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
/* 3h.1: el mensaje ya no se pinta en la pantalla de despacho; es el texto de
   las indicaciones que lee Almacén. Se mira el texto mismo. */
$msg_mapa = mensaje_de_pedido((int)$p_mapa['id'], plantilla_de_despacho(pedido_de((int)$p_mapa['id'])));
ok('el mensaje a despacho lleva la línea del mapa', str_contains($msg_mapa, 'Mapa:'),
   'la migración del 📍 Mapa no llegó a la plantilla');
ok('con el enlace entero', str_contains($msg_mapa, $normal),
   'el enlace llegó cortado o no llegó');

/* Y fuera de Lima esa línea DESAPARECE entera en vez de quedarse coja: es lo
   que hace plantilla_render() con una línea cuyas variables están todas
   vacías. Sin eso, a Cusco le llegaría «📍 Mapa:» a secas. */
$mandar(['destino' => 'provincia', 'envio_modo' => 'agencia', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => $AGENCIA, 'sucursal' => 'Sucursal Wanchaq']);
$p_sin_mapa = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
$pg_sin = (int) valor('SELECT MAX(id) FROM pagos WHERE pedido_id = ?', [(int)$p_sin_mapa['id']]);
$n->salir(); $n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/validar', ['_t'=>$n->testigoValido(), 'id'=>$pg_sin], false);
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
ok('a provincia la línea del mapa no aparece vacía', !str_contains(mensaje_de_pedido((int)$p_sin_mapa['id'], plantilla_de_despacho(pedido_de((int)$p_sin_mapa['id']))), 'Mapa:'),
   'quedó la etiqueta sin enlace');

/* 5) Recoge en oficina: cero campos de envío, aunque lleguen todos. «Enviar
      armado» es el que más duele: el asesor lo marcaba pensando en un envío,
      cambiaba a recojo, y al grupo de despacho le llegaba «ENVIAR ARMADO»
      debajo de «recoge en la oficina». */
$TIPO_ENV = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id=i.lista_id
                          WHERE l.clave='tipos_envio' ORDER BY i.orden LIMIT 1");
$mandar(['destino' => 'oficina', 'ubigeo_id' => $SJL, 'direccion_txt' => 'Av. Próceres 1',
         'referencia_txt' => 'Al lado', 'gps' => 'https://maps.google/y',
         'agencia_item_id' => $AGENCIA, 'sucursal' => 'Sucursal X',
         'envio_armado' => '1', 'tipo_envio_item_id' => $TIPO_ENV]);
ok('recoge en oficina se registra sin nada más',
   str_contains($n->cuerpo, 'Pedido registrado'));
$p = $ultimo();
es('sin distrito',    null, $p['ubigeo_id']);
es('sin dirección',   '',   (string)$p['direccion_txt']);
es('sin referencia',  '',   (string)$p['referencia_txt']);
es('sin mapa',        '',   (string)$p['gps']);
es('sin agencia',     null, $p['agencia_item_id']);
es('sin sucursal',    '',   (string)$p['sucursal']);
es('y sin «enviar armado»: no hay envío que armar', 0, (int)$p['envio_armado']);
es('ni tipo de envío', null, $p['tipo_envio_item_id']);
es('y la ficha lo dice', 'Recoge en la oficina', pedido_como_recibe($p));

/* En Lima, agencia y sucursal se limpian igual: vienen del bloque de
   provincia, que la pantalla esconde pero el formulario sigue mandando. */
$mandar(['destino' => 'lima', 'ubigeo_id' => $SJL, 'direccion_txt' => 'Av. Próceres 500',
         'agencia_item_id' => $AGENCIA, 'sucursal' => 'Sucursal Wanchaq']);
ok('la venta en Lima con datos de agencia pegados se registra',
   str_contains($n->cuerpo, 'Pedido registrado'));
$p = $ultimo();
es('y en Lima no queda agencia',  null, $p['agencia_item_id']);
es('ni sucursal',                 '',   (string)$p['sucursal']);

/* Y la comisión de pasarela no puede quedarse en un pedido que no cobró nada:
   vive dentro del bloque de pago, que se esconde al marcar «todavía no paga»
   y sigue viajando. */
$mandar(['destino' => 'oficina', 'pago_monto' => '0', 'comision' => '3.50']);
ok('sin pago el pedido NO se registra', str_contains($n->cuerpo, 'Falta el primer pago'));
ok('y se dice que basta un adelanto', str_contains($n->cuerpo, 'aunque sea un adelanto'));

/* 6) Lo tecleado sobrevive al rebote. El flete y la comisión se vaciaban al
      volver el formulario con un error: el asesor corregía otra cosa, enviaba,
      y la venta se registraba sin el costo del envío. */
$POS_R = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id
                       WHERE l.clave = 'metodos_pago' AND i.valor = 'POS / tarjeta'");
$mandar(['destino' => 'provincia', 'envio_modo' => 'agencia', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => $AGENCIA, 'sucursal' => '',
         /* Con POS: desde el 2r la comisión de pasarela solo existe ahí. */
         'pago_metodo_item_id' => $POS_R,
         'flete' => '25.00', 'comision' => '3.50']);
ok('sin sucursal rebota', str_contains($n->cuerpo, 'Falta la sucursal'));
ok('pero el costo del envío sigue escrito', str_contains($n->cuerpo, 'value="25.00"'));
ok('y la comisión también',                 str_contains($n->cuerpo, 'value="3.50"'));

/* 7) La ventana de entrega sobrevive al rebote. El asesor acuerda «el jueves
      de 3 a 6», se olvida de clicar el distrito, corrige, envía — y la hora
      tiene que seguir ahí. Se borraba, y el pedido se guardaba sin ella. */
$mandar(['destino' => 'lima', 'ubigeo_id' => '', 'direccion_txt' => 'Av. Próceres 500',
         'entrega_fecha' => $MANANA3, 'entrega_hora_desde' => '15:00',
         'entrega_hora_hasta' => '18:00', 'entrega_recepcion' => '1']);
ok('sin distrito el pedido rebota', str_contains($n->cuerpo, 'Elige el distrito'));
ok('pero el día acordado sigue escrito', str_contains($n->cuerpo, 'value="' . $MANANA3 . '"'));
ok('y la hora de inicio',                str_contains($n->cuerpo, 'value="15:00"'));
ok('y la de fin',                        str_contains($n->cuerpo, 'value="18:00"'));
ok('y lo de dejar en recepción sigue marcado',
   (bool) preg_match('/name="entrega_recepcion"[^>]*checked/', $n->cuerpo));

grupo('El tipo de envío, la agencia que falta y el pago obligatorio');

/* Cada tipo de envío dice dónde aplica. Enseñarlos todos siempre le ofrecía
   «Envío gratis Lima» a una venta a Cusco — y ese nombre lo lee después
   facturación y despacho. */
$ENV_LIMA = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id=i.lista_id
                          WHERE l.clave='tipos_envio' AND i.valor='Envío gratis Lima'");
$ENV_PROV = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id=i.lista_id
                          WHERE l.clave='tipos_envio' AND i.valor='Flete a provincia'");
ok('las semillas dicen dónde aplica cada tipo de envío',
   tipo_envio_ambito($ENV_LIMA) === 'lima' && tipo_envio_ambito($ENV_PROV) === 'provincia');

$mandar(['destino' => 'provincia', 'envio_modo' => 'agencia', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => $AGENCIA, 'sucursal' => 'Wanchaq',
         'tipo_envio_item_id' => $ENV_LIMA]);
ok('un tipo de envío de Lima en una venta a provincia rebota',
   str_contains($n->cuerpo, 'no aplica a provincia'));

$mandar(['destino' => 'provincia', 'envio_modo' => 'agencia', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => $AGENCIA, 'sucursal' => 'Wanchaq',
         'tipo_envio_item_id' => $ENV_PROV, 'flete' => '30.00']);
ok('con el suyo, se registra', str_contains($n->cuerpo, 'Pedido registrado'));
/* Y el flete de provincia NO se guarda: se lo paga el cliente a la agencia en
   destino, no a Waka. Pedirlo era pedir un dato que a nadie le sirve. */
es('el flete a provincia no entra en el pedido', 0, (int)$ultimo()['flete_centimos']);
es('ni cambia el total', 50000, (int)$ultimo()['total_centimos']);

/* La agencia que no está en la lista: se escribe, se manda con el pedido y la
   venta no se traba. Pero no se deja duplicar una que ya existe. */
$mandar(['destino' => 'provincia', 'envio_modo' => 'agencia', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => 'otra', 'agencia_otra' => 'Transportes Del Sur',
         'sucursal' => 'Wanchaq']);
ok('una agencia que no está en la lista se registra igual',
   str_contains($n->cuerpo, 'Pedido registrado'));
$p = $ultimo();
es('queda escrita en el pedido', 'Transportes Del Sur', (string)$p['agencia_otra']);
es('y sin id de la lista', null, $p['agencia_item_id']);

$mandar(['destino' => 'provincia', 'envio_modo' => 'agencia', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => 'otra', 'agencia_otra' => 'shalom', 'sucursal' => 'Wanchaq']);
ok('una que ya existe, escrita distinto, NO se deja añadir',
   str_contains($n->cuerpo, 'ya está en la lista'));
ok('y se dice con qué nombre está', str_contains($n->cuerpo, 'Shalom'));

$mandar(['destino' => 'provincia', 'envio_modo' => 'agencia', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => 'otra', 'agencia_otra' => '', 'sucursal' => 'Wanchaq']);
ok('y si no escribe ninguna, se le pide',
   str_contains($n->cuerpo, 'Escribe el nombre de la agencia'));

/* En Lima la agencia no existe: lo que venga pegado de la rama de provincia se
   borra, incluida la escrita a mano. */
$mandar(['destino' => 'lima', 'ubigeo_id' => $SJL, 'direccion_txt' => 'Av. Próceres 500',
         'agencia_item_id' => 'otra', 'agencia_otra' => 'Transportes Del Sur']);
ok('en Lima se registra sin mirar la agencia', str_contains($n->cuerpo, 'Pedido registrado'));
es('y no queda ninguna agencia escrita', null, $ultimo()['agencia_otra']);

/* La agencia escrita a mano tiene que LLEGARLE A ALGUIEN. Se guardaba en la
   base y no la leía ninguna pantalla: al grupo de despacho le llegaba una
   sucursal de ninguna agencia. */
$mandar(['destino' => 'provincia', 'envio_modo' => 'agencia', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => 'otra', 'agencia_otra' => 'Transportes Del Sur',
         'sucursal' => 'Wanchaq']);
$id_otra = (int) valor('SELECT id FROM pedidos ORDER BY id DESC LIMIT 1');
$n->ir('/pedidos/ficha?id=' . $id_otra);
ok('la ficha enseña la agencia escrita a mano',
   str_contains($n->cuerpo, 'Transportes Del Sur'));
ok('y avisa de que está por autorizar', str_contains($n->cuerpo, 'por autorizar'));
$n->ir('/pedidos/mensaje?id=' . $id_otra);
ok('y el mensaje al grupo la lleva', str_contains($n->cuerpo, 'Transportes Del Sur'));
ok('sin el «por autorizar», que es cosa nuestra',
   !str_contains($n->cuerpo, 'por autorizar'));

/* Y el mensaje al CLIENTE tiene que hablar de lo que pagó, no de lo que
   facturación ya confirmó: desde que ningún método cuenta al instante, todo
   pedido recién registrado tiene su dinero sin confirmar y el mensaje decía
   «recibido S/ 0.00» y le pedía el total entero a quien acababa de pagar. */
ok('el mensaje no dice que no pagó nada', !str_contains($n->cuerpo, 'S/ 0.00'));
ok('y dice lo que de verdad pagó',        str_contains($n->cuerpo, '500.00'));

/* Y el aviso de «facturación tiene que confirmar» tiene que salir también
   cuando el cliente adelantó una parte, que es el caso normal de una pre
   venta: ese adelanto tampoco suma a la meta hasta que lo miren. */
$mandar(['destino' => 'oficina', 'tipo' => 'preventa',
         'l_precio' => ['5000'], 'modo_pago' => 'adelanto', 'pago_monto' => '500.00']);
ok('la pre venta con adelanto se registra', str_contains($n->cuerpo, 'Pedido registrado'));
/* La frase es la del usuario, tal cual la escribió en el dibujo (2026-09-11).
   Se comprueba el texto y no solo «que salga algo»: es lo primero que lee el
   asesor después de vender, y ya se cambió una vez por sonar a regaño. */
ok('y avisa de que falta la confirmación',
   str_contains($n->cuerpo, 'Esperando confirmación por parte de facturación'));
ok('prometiendo el aviso',   str_contains($n->cuerpo, 'Te notificaremos cuando el pago esté aprobado'));
ok('diciendo lo que pagó',   str_contains($n->cuerpo, '500.00'));
ok('y lo que queda',         str_contains($n->cuerpo, '4,500.00'));
ok('con el rótulo que pidió el usuario', str_contains($n->cuerpo, 'Queda por cobrar'));
ok('y la salida lleva al Inicio, no al mensaje al cliente',
   str_contains($n->cuerpo, 'ESPERAR CONFIRMACIÓN')
   && !str_contains($n->cuerpo, 'MENSAJE PARA EL CLIENTE'));

/* El voucher no se pierde con el error más probable del pago obligatorio:
   adjuntar la foto y olvidarse de escribir el monto. */
$n->ir('/pedidos/nuevo');
$n->subir('/pedidos/nuevo', array_merge($base, [
    '_t' => $n->testigo(), 'destino' => 'oficina', 'pago_monto' => '',
]), ['voucher' => ['banco.jpg', 'image/jpeg', $JPG]]);
ok('sin monto el pedido rebota', str_contains($n->cuerpo, 'Falta el primer pago'));
ok('pero el voucher NO se pierde', str_contains($n->cuerpo, 'Adjunto:'));

grupo('Liquidación y garantía, desde el formulario de verdad');

$id_gar = fn(string $v) => (int) valor(
    "SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id
      WHERE l.clave = 'garantias' AND i.valor = ?", [$v]);
$G12 = $id_gar('12 meses');
$G6  = $id_gar('6 meses');
$GNO = $id_gar('No aplica');

/* EL CONTRATO QUE EL JAVASCRIPT NECESITA. La caja «usar su cashback» se
   enseña o se esconde según si hay cliente, y el JavaScript lo pregunta por el
   campo `cliente_id`. Ese campo se pinta en DOS ramas de la vista —con cliente
   ya elegido y sin él— y durante un rato solo una de las dos llevaba el `id`:
   entrando desde la ficha del cliente, que es el camino normal para vender, el
   JavaScript daba por hecho que no había cliente y escondía el campo del
   cashback con lo que el asesor hubiera escrito dentro. Y lo escondido se
   envía igual. Las pruebas no ejecutan JavaScript, así que lo que se vigila
   aquí es el HTML del que depende. */
$n->ir('/pedidos/nuevo?cliente=' . (int)$F['clientes']['asesor']);
ok('con el cliente ya elegido, el campo cliente_id lleva nombre Y id',
   (bool) preg_match('/name="cliente_id"[^>]*id="cliente_id"|id="cliente_id"[^>]*name="cliente_id"/',
                     $n->cuerpo));
ok('y la caja del cashback se ofrece', str_contains($n->cuerpo, 'id="caja-usar-cb"'));
ok('sin decirle que elija al cliente que ya eligió',
   (bool) preg_match('/id="sin-cliente-cb"[^>]*\shidden/', $n->cuerpo));

$n->ir('/pedidos/nuevo');
ok('el formulario ofrece los tres tipos de venta',
   str_contains($n->cuerpo, 'value="liquidacion"')
   && str_contains($n->cuerpo, 'value="preventa"')
   && str_contains($n->cuerpo, 'value="inmediata"'));
ok('y pregunta por la garantía', str_contains($n->cuerpo, 'name="garantia_item_id"'));

/* Sin tocar nada, la garantía es la primera de la lista: el asesor no tiene
   que elegir en el caso normal. */
$mandar(['destino' => 'oficina']);
ok('una entrega inmediata se registra', str_contains($n->cuerpo, 'Pedido registrado'));
es('y sale con los 12 meses puestos', $G12, (int)$ultimo()['garantia_item_id']);

$mandar(['destino' => 'oficina', 'garantia_item_id' => $G6]);
es('si el asesor pacta otra, manda la suya', $G6, (int)$ultimo()['garantia_item_id']);

$mandar(['destino' => 'oficina', 'garantia_item_id' => 999999]);
ok('una garantía que no está en la lista se rechaza',
   str_contains($n->cuerpo, 'Esa garantía no está en la lista'));

/* LIQUIDACIÓN. Es un tipo de venta, no una etiqueta: su propio almacén, su
   propia garantía y sin cashback en ninguna de las dos direcciones. */
$mandar(['destino' => 'oficina', 'tipo' => 'liquidacion']);
ok('una liquidación se registra', str_contains($n->cuerpo, 'Pedido registrado'));
$p_lq = $ultimo();
es('con su tipo, no convertida en inmediata', 'liquidacion', (string)$p_lq['tipo']);
es('nace registrada', 'registrado',
   (string) valor('SELECT clave FROM pedido_estados WHERE id = ?', [(int)$p_lq['estado_id']]));
es('y sin garantía, sin que el asesor tenga que acordarse', $GNO, (int)$p_lq['garantia_item_id']);
es('la línea sale del almacén de liquidación', 'liquidacion',
   (string) valor('SELECT origen FROM pedido_lineas WHERE pedido_id = ?', [(int)$p_lq['id']]));

$n->ir('/pedidos/ficha?id=' . (int)$p_lq['id']);
ok('la ficha la llama Liquidación', str_contains($n->cuerpo, 'Liquidación'));
ok('y dice que no lleva garantía', str_contains($n->cuerpo, 'No aplica'));

/* El campo del cashback se esconde en liquidación, pero un campo escondido
   sigue viajando: si el servidor no lo comprobara, el descuento entraría
   igual y el precio de liquidación se habría descontado dos veces. */
$mandar(['destino' => 'oficina', 'tipo' => 'liquidacion', 'cashback' => '10.00']);
ok('con cashback escrito, la liquidación rebota',
   str_contains($n->cuerpo, 'no se puede usar cashback'));

/* Y el filtro de la lista tiene que conocerla, o sus pedidos salen mezclados
   sin que nadie sepa por qué. */
$n->ir('/pedidos?v=todos&t=liquidacion');
ok('el filtro por liquidación existe en la pantalla', str_contains($n->cuerpo, 't=liquidacion'));
ok('y encuentra el pedido', str_contains($n->cuerpo, (string)$p_lq['codigo']));
ok('que se llama Liquidación en la columna', str_contains($n->cuerpo, 'Liquidación'));
$n->ir('/pedidos?v=todos&t=preventa');
ok('y el filtro de pre venta NO lo enseña', !str_contains($n->cuerpo, (string)$p_lq['codigo']));

grupo('El buscador de distritos, con el país entero cargado');

/* El buscador es la única puerta a los 1.893 distritos: no hay desplegable ni
   lista en el HTML, así que si esto no contesta bien, no se puede vender fuera
   de Lima por más que el padrón esté cargado. */
$n->ir('/ubigeo/buscar?q=wanchaq');
ok('el buscador contesta JSON', $n->codigo === 200);
$j = json_decode($n->cuerpo, true);
ok('y encuentra Wanchaq, en Cusco',
   (bool) array_filter($j['sitios'] ?? [], fn($x) => ($x['texto'] ?? '') === 'Wanchaq'));
ok('y dice que NO es Lima: lleva agencia y sucursal',
   ($j['sitios'][0]['lima'] ?? true) === false);

$n->ir('/ubigeo/buscar?q=santa');
$j = json_decode($n->cuerpo, true);
es('la lista se corta en 25', 25, count($j['sitios'] ?? []));
/* Y lo dice. Una lista cortada que se presenta como completa es peor que una
   lista larga: el asesor no encuentra su distrito y cree que no existe. */
ok('y avisa de que hay más', ($j['mas'] ?? false) === true);

$n->ir('/ubigeo/buscar?q=miraflores');
$j = json_decode($n->cuerpo, true);
ok('con pocas coincidencias no avisa de nada', ($j['mas'] ?? true) === false);

/* Y el aviso tiene que poder cumplirse: si el buscador no buscara también por
   provincia, el asesor que hace caso a «escribe también la provincia» recibiría
   «Nada con ese nombre» y creería que su distrito no existe. */
$n->ir('/ubigeo/buscar?q=' . rawurlencode('santa rosa lima'));
$j = json_decode($n->cuerpo, true);
ok('buscar «santa rosa lima» encuentra la de Lima',
   (($j['sitios'][0]['texto'] ?? '') === 'Santa Rosa')
   && str_contains((string)($j['sitios'][0]['sub'] ?? ''), 'Lima'));
ok('y ya no avisa de que hay más', ($j['mas'] ?? true) === false);

grupo('El pago NO se pierde cuando el pedido vuelve con un error');

/* Lo encontró la auditoría del delta: el modo de pago se pintaba siempre en
   «todavía no paga», así que al volver el formulario con un error el JS vaciaba
   el monto ya escrito y escondía el bloque. El asesor corregía, enviaba, y la
   venta se registraba SIN el pago. Dinero cobrado que no llega a la meta. */
$conPago = array_merge($post, [
    'entrega' => 'envio', 'ubigeo_id' => '',          // ← falta el distrito: rebota
    'modo_pago' => 'completo', 'pago_monto' => '379.00',
    'pago_metodo_item_id' => (int)$F['efectivo'], 'pago_fecha' => date('Y-m-d'),
]);
$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', array_merge($conPago, ['_t' => $n->testigo()]));
ok('el pedido rebota por el distrito', str_contains($n->cuerpo, 'distrito'));
ok('pero el monto sigue escrito',      str_contains($n->cuerpo, 'value="379.00"'));
ok('y «Pago completo» sigue elegido',
   (bool) preg_match('/value="completo"[^>]*checked/', $n->cuerpo));
ok('el bloque del pago NO vuelve escondido',
   !preg_match('/id="datos-pago"\s+hidden/', $n->cuerpo));

ok('el N.º de operación ya no se le pide al asesor',
   !str_contains($n->cuerpo, 'name="pago_operacion"'));

/* ═══════════════════  EL MENSAJE DE WHATSAPP  ═══════════════════ */
grupo('El mensaje de WhatsApp sale armado');

$n->ir('/pedidos/mensaje?id=' . $creado);
ok('la pantalla del mensaje abre', $n->codigo === 200);
ok('trae el nombre del cliente',  str_contains($n->cuerpo, 'Cliente Prueba'));
ok('y el código del pedido',      str_contains($n->cuerpo, 'P-'));
ok('no deja variables sin sustituir', !preg_match('/\{[a-z_]+\}/', $n->cuerpo));

/* ═══════════════════  ADMINISTRACIÓN  ═══════════════════ */
grupo('Administración · equipos, validación y reporte');

$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');

$n->ir('/configuracion/equipos');
ok('Administración entra a Equipos', $n->codigo === 200);
$n->ir('/configuracion/equipos', ['_t' => $n->testigo(), 'accion' => 'guardar',
                                  'nombre' => 'Equipo Dos']);
ok('crea un equipo', str_contains($n->cuerpo, 'Equipo Dos'));

$eq2 = (int) valor("SELECT id FROM equipos WHERE nombre = 'Equipo Dos'");
$n->ir('/configuracion/equipos?editar=' . $eq2);
$n->ir('/configuracion/equipos', ['_t' => $n->testigo(), 'accion' => 'guardar',
    'id' => $eq2, 'nombre' => 'Equipo Dos',
    'lider_usuario_id' => (int)$F['usuarios']['asesor']]);   // ← no es de ese equipo
ok('no se puede nombrar líder a alguien que no está en el equipo',
   str_contains($n->cuerpo, 'ya esté en ese equipo'));

$n->ir('/configuracion/equipos');
$n->ir('/configuracion/equipos', ['_t' => $n->testigo(), 'accion' => 'asignar',
    'equipo_de' => [(string)(int)$F['usuarios']['otro'] => $eq2]]);
es('la asignación en bloque mueve al asesor', $eq2,
   (int) valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$F['usuarios']['otro']]));

$n->ir('/pagos/por-validar');
ok('la bandeja lista el pago pendiente de Perú',
   str_contains($n->cuerpo, 'value="' . (int)$F['pago_pendiente'] . '"'));
/* Con su N.º de operación: desde el 2026-09-09 es obligatorio en los métodos
   que lo tienen, y el de la fixtura es una transferencia. */
$n->ir('/pagos/validar', ['_t' => $n->testigo(), 'id' => (int)$F['pago_pendiente'],
                          'operacion' => 'OP-HTTP-001']);
es('al validarlo queda validado', 1,
   (int) valor('SELECT verificado FROM pagos WHERE id = ?', [(int)$F['pago_pendiente']]));
es('y recién ahora acredita cashback', 100,
   (int) valor("SELECT COALESCE(SUM(monto_centimos),0) FROM cashback_movimientos
                 WHERE pago_id = ? AND tipo = 'acredita'", [(int)$F['pago_pendiente']]));

$n->ir('/usuarios/editar?id=' . (int)$F['usuarios']['asesor']);
$n->ir('/usuarios/editar?id=' . (int)$F['usuarios']['asesor'], [
    '_t' => $n->testigo(), 'nombre' => 'Cuenta', 'apellidos' => 'De Prueba',
    'email' => 'asesor@waka.test', 'pais_id' => (int)$F['PE'],
    'rol_id' => (int) valor("SELECT id FROM roles WHERE clave = 'asesor'"),
    'ambito' => 'propio', 'meta' => '-30000']);
ok('una meta negativa se rechaza con un mensaje', str_contains($n->cuerpo, 'Esa meta no puede ser'));
ok('y no se guardó', (int) valor('SELECT COALESCE(meta_mensual_centimos, 0) FROM usuarios WHERE id = ?',
                                 [(int)$F['usuarios']['asesor']]) >= 0);

$n->ir('/reportes/pagos');
ok('el reporte de pagos abre', $n->codigo === 200);
ok('y separa lo cobrado de lo vendido', str_contains($n->cuerpo, 'Pagos ingresados')
   || str_contains($n->cuerpo, 'Ingresado'));

$n->ir('/reportes/pagos?excel=1&desde=' . date('Y-m-01') . '&hasta=' . date('Y-m-d'), null, false);
ok('la descarga a Excel devuelve un archivo', $n->codigo === 200);
ok('y es un .xlsx de verdad', str_starts_with($n->cuerpo, 'PK'),
   substr($n->cuerpo, 0, 40));

/* ═══════════════════  FACTURACIÓN  ═══════════════════ */
grupo('Facturación · entra a lo suyo');

$n->salir();
ok('la cuenta de facturación entra', $n->entrar('factu@waka.test', 'Clave-Larga-1'));

foreach ([['/pagos/por-validar','la bandeja'], ['/pedidos','los pedidos'],
          ['/clientes','los clientes'], ['/reportes/pagos','el reporte de pagos'],
          ['/pedidos/facturar?id=' . (int)$F['pedidos']['asesor'], 'la pantalla de facturar']] as [$r,$q]) {
    $n->ir($r);
    ok("entra a $q", $n->codigo === 200, 'código ' . $n->codigo);
}

$n->ir('/reportes/pagos?excel=1', null, false);
ok('y baja el Excel', $n->codigo === 200 && str_starts_with($n->cuerpo, 'PK'));

$n->ir('/pedidos/facturar?id=' . (int)$F['pedidos']['asesor']);
ok('la pantalla de facturar trae los datos del cliente', str_contains($n->cuerpo, 'Cliente Prueba'));
ok('con su botón de copiar campo por campo', str_contains($n->cuerpo, 'data-copiar-valor'));
ok('y el desglose del IGV',                  str_contains($n->cuerpo, 'Base imponible'));

grupo('Facturación · NO puede hacer lo que no es suyo');

foreach ([['/pedidos/nuevo','registrar un pedido'], ['/clientes/nuevo','crear un cliente'],
          ['/clientes/editar?id=' . (int)$F['clientes']['asesor'],'editar un cliente'],
          ['/usuarios','ver usuarios'], ['/configuracion','la configuración'],
          ['/configuracion/equipos','los equipos'], ['/stock','el stock'],
          ['/cashback','el cashback'], ['/bonos','los bonos']] as [$r,$q]) {
    $n->ir($r, null, false);
    ok("403 en $q", $n->codigo === 403, 'código ' . $n->codigo);
}

$t = $n->testigoValido();
foreach ([['/pedidos/estado', ['id'=>(int)$F['pedidos']['asesor'],'estado'=>'entregado'], 'cambiar un estado'],
          ['/pedidos/anular', ['id'=>(int)$F['pedidos']['asesor'],'motivo_item_id'=>1], 'anular un pedido'],
          ['/pagos/registrar',['concepto'=>'Saldo de prueba','pedido_id'=>(int)$F['pedidos']['asesor'],'monto'=>'10','metodo_item_id'=>(int)$F['efectivo']], 'registrar un pago'],
          ['/pagos/devolver', ['id'=>(int)$F['pago_pendiente']], 'devolver un pago'],
          ['/clientes/rapido',['documento'=>'99999991','nombre'=>'X','apellidos'=>'Y',
                               'email'=>'x@waka.test','celular'=>'9','canal_item_id'=>(int)$F['canal']], 'crear un cliente por JSON'],
         ] as [$r,$datos,$q]) {
    $n->ir($r, array_merge($datos, ['_t' => $t]), false);
    ok("403 al forzar $q", $n->codigo === 403, 'código ' . $n->codigo);
}

grupo('Facturación · el país sigue aislando');

$n->ir('/pedidos/facturar?id=' . (int)$F['pedidos']['mx'], null, false);
ok('facturación de Perú NO abre una venta de México (403)', $n->codigo === 403);
$n->ir('/pagos/nuevos');
$j = json_decode($n->cuerpo, true);
ok('el sondeo responde JSON', ($j['ok'] ?? false) === true, $n->cuerpo);
es('cuenta solo los pagos por confirmar de SU país', (int) valor(
    'SELECT COUNT(*) FROM pagos p JOIN pedidos pe ON pe.id = p.pedido_id
      WHERE p.verificado = 0 AND p.anulado = 0 AND pe.pais_id = ?', [(int)$F['PE']]),
   (int)($j['n'] ?? -1));

/* El aviso tiene que decir cuántos ENTRARON, no cuántos hay: con siete
   pendientes de ayer y uno nuevo hoy, "entraron ocho" es mentira. */
$ultimo = (int)($j['ultimo'] ?? 0);
$n->ir('/pagos/nuevos?desde=' . $ultimo);
$j2 = json_decode($n->cuerpo, true);
es('sin novedades, ninguno es nuevo', 0, (int)($j2['nuevos'] ?? -1));
$n->ir('/pagos/nuevos?desde=0');
$j3 = json_decode($n->cuerpo, true);
es('desde cero, todos los pendientes son nuevos',
   (int)($j['n'] ?? -1), (int)($j3['nuevos'] ?? -2));
$n->ir('/pagos/nuevos?desde=abc');
ok('un «desde» inventado no rompe nada', $n->codigo === 200);
$n->ir('/pagos/nuevos?desde=-99');
$j4 = json_decode($n->cuerpo, true);
ok('ni uno negativo', ($j4['ok'] ?? false) === true);

$n->salir();
$n->entrar('factumx@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/facturar?id=' . (int)$F['pedidos']['asesor'], null, false);
ok('y al revés: facturación de México no abre una de Perú (403)', $n->codigo === 403);
$n->ir('/pagos/validar', ['_t' => $n->testigoValido(), 'id' => (int)$F['pago_pendiente']], false);
ok('ni puede confirmar un pago peruano (403)', $n->codigo === 403);

grupo('Facturar · la MISMA puerta que la ficha, no una más floja');

/* La pantalla de facturar enseña MÁS que la ficha —documento, dirección,
   correo, desglose de IGV—. Si aquí solo se mirara el país, una cuenta con
   ámbito estrecho recibiría 403 en la ficha y 200 aquí, con el mismo pedido
   delante. Las dos rutas tienen que contestar lo mismo, pedido por pedido. */
$n->salir();
ok('entra la cuenta de facturación con ámbito estrecho',
   $n->entrar('factuflaco@waka.test', 'Clave-Larga-1'));

foreach (['asesor' => 'un pedido ajeno', 'otro' => 'otro pedido ajeno'] as $cual => $q) {
    $id = (int)$F['pedidos'][$cual];
    $n->ir('/pedidos/ficha?id=' . $id, null, false);   $ficha = $n->codigo;
    $n->ir('/pedidos/facturar?id=' . $id, null, false); $fact = $n->codigo;
    es("ficha y facturar contestan igual en $q", $ficha, $fact);
    ok("y las dos cortan ($q)", $ficha === 403, 'ficha ' . $ficha . ' / facturar ' . $fact);
}

/* Y la de ámbito «todo», que es como el alta la crea, sí entra a las dos. */
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$id = (int)$F['pedidos']['otro'];
$n->ir('/pedidos/ficha?id=' . $id, null, false);    $ficha = $n->codigo;
$n->ir('/pedidos/facturar?id=' . $id, null, false); $fact  = $n->codigo;
es('con ámbito «todo» las dos abren', 200, $ficha);
es('las dos, no una', $ficha, $fact);

grupo('El alta fija el ámbito de Facturación · nadie confirma a ciegas');

/* El desplegable de ámbito nace en «lo suyo». Una cuenta de Facturación creada
   sin tocarlo podía confirmar un pago y recibir 403 al abrir el voucher de ESE
   MISMO pago: confirmaba dinero sin poder mirarlo. El alta lo fija. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/usuarios/nuevo');
$rol_factu = (int) valor("SELECT id FROM roles WHERE clave = 'facturacion'");
$n->ir('/usuarios/nuevo', [
    '_t' => $n->testigoValido(),
    'nombre' => 'Nueva', 'apellidos' => 'Facturacion',
    'email' => 'nuevafactu@waka.test', 'celular' => '999888777',
    'rol_id' => $rol_factu, 'ambito' => 'propio',      // ← lo que sale por defecto
    'pais_id' => (int)$F['PE'], 'nivel_cuota' => 0,
], false);
$creada = una("SELECT * FROM usuarios WHERE email = 'nuevafactu@waka.test'");
ok('la cuenta se crea', $creada !== null, 'código ' . $n->codigo);
es('y nace con ámbito «todo» aunque se pidiera «lo suyo»', 'todo', $creada['ambito'] ?? '');

/* La prueba de que eso importa: con esa cuenta se abre el voucher. */
$n->salir();
q('UPDATE usuarios SET password_hash = ?, debe_cambiar_password = 0 WHERE id = ?',
  [password_hash('Clave-Larga-1', PASSWORD_DEFAULT), (int)$creada['id']]);
ok('entra', $n->entrar('nuevafactu@waka.test', 'Clave-Larga-1'));
$n->ir('/pagos/voucher?id=' . (int)$F['pago_pendiente'], null, false);
es('y abre el voucher del pago que tiene que confirmar', 200, $n->codigo);

grupo('La bandeja de pagos · el orden, el corte y el ámbito');

/* Iba de la fecha más VIEJA a la más nueva y cortaba en 200. Con el 100% de
   los pagos pasando por aquí, un voucher falso que nadie borra se queda
   clavado arriba y empuja fuera todo lo de hoy: el aviso dice «entró un pago»,
   facturación pulsa «Verlos» y el pago no está. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$t = $n->testigoValido();
for ($i = 0; $i < 3; $i++) {
    $n->ir('/pagos/registrar', ['concepto'=>'Saldo de prueba', '_t'=>$t, 'pedido_id'=>(int)$F['pedidos']['asesor'],
                                'monto'=>'5.0' . $i, 'metodo_item_id'=>(int)$F['efectivo'],
                                'fecha'=>date('Y-m-d')], false);
}
$ultimo_id = (int) valor('SELECT MAX(id) FROM pagos WHERE verificado = 0 AND anulado = 0');
es('los tres pagos se registran', 3, (int) valor(
   'SELECT COUNT(*) FROM pagos WHERE monto_centimos IN (500,501,502)'));

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/por-validar');
$plano = preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo));
ok('el pago recién entrado sale en la bandeja',
   str_contains($n->cuerpo, 'S/ 5.02'), 'no estaba el más nuevo');
$pendientes = (int) valor(
    'SELECT COUNT(*) FROM pagos p JOIN pedidos pe ON pe.id = p.pedido_id
      WHERE p.verificado = 0 AND p.anulado = 0 AND pe.pais_id = ?', [(int)$F['PE']]);
ok('y la cabecera cuenta TODO lo que espera, no lo que cupo',
   (bool) preg_match('/' . $pendientes . ' pagos? sin revisar/u', $plano), $plano);

/* El orden importa tanto como el corte: con los viejos arriba, el día que la
   lista se llene lo de hoy cae fuera de la página y el aviso miente. */
$pos_nuevo = strpos($n->cuerpo, 'S/ 5.02');   // el último que entró
$pos_viejo = strpos($n->cuerpo, 'S/ 5.00');   // el primero, misma fecha
ok('el último en entrar va arriba, no el primero',
   $pos_nuevo !== false && $pos_viejo !== false && $pos_nuevo < $pos_viejo,
   'nuevo en ' . var_export($pos_nuevo, true) . ' · viejo en ' . var_export($pos_viejo, true));

/* Y el ámbito: la cuenta estrecha no confirma lo que no puede abrir. */
$n->salir();
$n->entrar('factuflaco@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/por-validar');
ok('con ámbito estrecho la bandeja se queda vacía',
   str_contains($n->cuerpo, 'No hay nada esperando'), 'listaba pagos ajenos');

/* Y el chip del menú y el aviso tienen que decir lo MISMO que esa bandeja: si
   el chip cuenta por país y la bandeja por ámbito, la pantalla dice «351» y al
   entrar dice «no hay nada esperando». */
ok('y el chip del menú no anuncia pagos que no puede abrir',
   !preg_match('/nv__chip[^>]*>\s*[1-9]/u', $n->cuerpo), 'el chip traía número');
ok('ni le arranca el aviso en vivo', !str_contains($n->cuerpo, 'data-pagos-n="1"'));
$n->ir('/pagos/nuevos');
$jf = json_decode($n->cuerpo, true);
es('y el sondeo cuenta cero, como la bandeja', 0, (int)($jf['n'] ?? -1));
$n->ir('/inicio');
ok('la bandeja del panel tampoco le promete pagos',
   !preg_match('/\d+ pagos? sin revisar/u', preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo))));
$n->ir('/pagos/validar', ['_t'=>$n->testigoValido(), 'id'=>$ultimo_id], false);
es('y forzar la confirmación contesta 403', 403, $n->codigo);
es('el pago sigue sin confirmar', 0,
   (int) valor('SELECT verificado FROM pagos WHERE id = ?', [$ultimo_id]));

/* La cuenta con el ámbito correcto sí confirma. */
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/validar', ['_t'=>$n->testigoValido(), 'id'=>$ultimo_id, 'operacion'=>'OP-BANDEJA'], false);
es('y la de ámbito «todo» sí', 1,
   (int) valor('SELECT verificado FROM pagos WHERE id = ?', [$ultimo_id]));

grupo('El aviso cuenta los que ENTRARON, también con la bandeja vacía');

/* La marca de agua salía del MAX(id) de los PENDIENTES, así que con la bandeja
   vacía valía 0; desde 0 el servidor no sabía contar y el aviso caía en su
   plan B: «entró un pago» habiendo entrado cuatro. */
$n->ir('/pagos/nuevos');
$j = json_decode($n->cuerpo, true);
$marca = (int)($j['ultimo'] ?? 0);
es('la marca de agua es el pago más nuevo del país, confirmado o no',
   (int) valor('SELECT MAX(p.id) FROM pagos p JOIN pedidos pe ON pe.id = p.pedido_id
                 WHERE pe.pais_id = ?', [(int)$F['PE']]), $marca);
ok('y no es cero aunque no quede nada pendiente', $marca > 0);

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$t = $n->testigoValido();
for ($i = 0; $i < 4; $i++) {
    $n->ir('/pagos/registrar', ['concepto'=>'Saldo de prueba', '_t'=>$t, 'pedido_id'=>(int)$F['pedidos']['asesor'],
                                'monto'=>'7.0' . $i, 'metodo_item_id'=>(int)$F['efectivo'],
                                'fecha'=>date('Y-m-d')], false);
}
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/nuevos?desde=' . $marca);
$j2 = json_decode($n->cuerpo, true);
es('entraron cuatro y dice cuatro', 4, (int)($j2['nuevos'] ?? -1));

grupo('El reporte de pagos no puede decir siempre «nada pendiente»');

$n->ir('/reportes/pagos');
$plano = preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo));
ok('sin tocar los filtros, lo pendiente NO sale en cero',
   !str_contains($plano, 'nada pendiente en este periodo'), $plano);
$n->ir('/reportes/pagos?desde=2026-13-45&hasta=2026-99-99');
ok('una fecha imposible pero bien escrita no deja la cabecera en blanco',
   !str_contains(strip_tags($n->cuerpo), 'pagos del — al —'));
es('y la pantalla sigue respondiendo', 200, $n->codigo);

grupo('La bandeja no repite el número');

/* El chip lo pintaba la vista con el número por delante y el texto ya lo
   traía dentro: «1 1 pago por confirmar». No rompe nada y por eso llevaba
   días ahí — pero el primero que lo ve piensa que hay once. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/registrar', ['concepto'=>'Saldo de prueba', '_t'=>$n->testigoValido(), 'pedido_id'=>(int)$F['pedidos']['asesor'],
                            'monto'=>'12.00', 'metodo_item_id'=>(int)$F['efectivo'],
                            'fecha'=>date('Y-m-d')], false);
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
$plano = preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo));
ok('hay algo en la bandeja para mirar', (bool) preg_match('/sin revisar/u', $plano), $plano);
ok('el chip dice el número una sola vez',
   !preg_match('/(\d+) \1 pagos? sin revisar/u', $plano));
ok('y sigue diciendo cuántos son',
   (bool) preg_match('/\d+ pagos? sin revisar/u', $plano));

grupo('Quien deja de confirmar pagos no se queda con el ámbito ancho');

/* Al revés del forzado: una cuenta de Facturación pasada a Asesor se quedaba
   con «todo» —viendo todos los clientes y todos los pedidos del país— porque
   ese valor se lo había puesto el rol anterior, no una persona. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$rol_asesor = (int) valor("SELECT id FROM roles WHERE clave = 'asesor'");
$rol_fact   = (int) valor("SELECT id FROM roles WHERE clave = 'facturacion'");

// Se crea aquí su propia cuenta: esta prueba la deja cambiada de rol, y
// reutilizar una de la fixtura ataría el resultado al orden de los grupos.
$n->ir('/usuarios/nuevo', [
    '_t' => $n->testigoValido(),
    'nombre' => 'Cuenta', 'apellidos' => 'Que Cambia',
    'email' => 'cambiarol@waka.test', 'celular' => '',
    'rol_id' => $rol_fact, 'ambito' => 'propio',
    'pais_id' => (int)$F['PE'], 'nivel_cuota' => 0,
], false);
$id_cambia = (int) valor("SELECT id FROM usuarios WHERE email = 'cambiarol@waka.test'");
es('nace de Facturación y con ámbito «todo»', 'todo',
   (string) valor('SELECT ambito FROM usuarios WHERE id = ?', [$id_cambia]));

$n->ir('/usuarios/editar?id=' . $id_cambia, [
    '_t' => $n->testigoValido(),
    'nombre' => 'Cuenta', 'apellidos' => 'Que Cambia',
    'email' => 'cambiarol@waka.test', 'celular' => '',
    'rol_id' => $rol_asesor, 'ambito' => 'todo',   // lo que deja el formulario sin JS
    'pais_id' => (int)$F['PE'], 'nivel_cuota' => 0,
], false);
es('la cuenta pasa a asesor', 'asesor',
   (string) valor('SELECT r.clave FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=?', [$id_cambia]));
es('y vuelve a «lo suyo»: el ámbito ancho era del rol viejo', 'propio',
   (string) valor('SELECT ambito FROM usuarios WHERE id = ?', [$id_cambia]));

grupo('Nadie se ensancha el ámbito a sí mismo');

/* Arriba está escrito «nadie se cambia a sí mismo el rol, el ámbito ni el
   país». El forzado por rol corría después y lo pisaba: una cuenta puesta a
   mano en «lo suyo» salía con «todo» solo por editarse el celular. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$id_admin = (int) valor("SELECT id FROM usuarios WHERE email = 'admin@waka.test'");
q("UPDATE usuarios SET ambito = 'propio' WHERE id = ?", [$id_admin]);
$n->ir('/mi-perfil');   // refresca la sesión con el ámbito nuevo
$n->ir('/usuarios/editar?id=' . $id_admin, [
    '_t' => $n->testigoValido(),
    'nombre' => 'Cuenta', 'apellidos' => 'De Prueba',
    'email' => 'admin@waka.test', 'celular' => '987654321',
    'rol_id' => (int) valor("SELECT id FROM roles WHERE clave = 'administracion'"),
    'ambito' => 'todo',
    'pais_id' => (int)$F['PE'], 'nivel_cuota' => 0,
], false);
es('editarse a uno mismo no toca el ámbito', 'propio',
   (string) valor('SELECT ambito FROM usuarios WHERE id = ?', [$id_admin]));
es('pero sí guarda lo que sí puede cambiar', '987654321',
   (string) valor('SELECT celular FROM usuarios WHERE id = ?', [$id_admin]));
q("UPDATE usuarios SET ambito = 'todo' WHERE id = ?", [$id_admin]);

grupo('El reporte de pagos respeta el ámbito, como la ficha');

$n->salir();
$n->entrar('factuflaco@waka.test', 'Clave-Larga-1');
$n->ir('/reportes/pagos?e=todos&desde=2000-01-01&hasta=' . date('Y-m-d'));
es('entra al reporte', 200, $n->codigo);
ok('pero no le salen los clientes de pedidos que no puede abrir',
   !str_contains($n->cuerpo, 'Cliente Prueba'), 'listaba clientes ajenos');
$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['asesor'], null, false);
es('coherente con la ficha, que le contesta 403', 403, $n->codigo);

grupo('Al asesor no se le pide el N.º de operación');

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['asesor']);
ok('el formulario de registrar pago no trae ese campo',
   !str_contains($n->cuerpo, 'N.º de operación'), 'seguía pidiéndolo');
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['asesor']);
ok('a quien confirma pagos, sí', str_contains($n->cuerpo, 'N.º de operación'));

grupo('El Excel de pagos · la fecha es una fecha, no un texto');

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/reportes/pagos?excel=1&e=todos&desde=' . date('Y-m-01') . '&hasta=' . date('Y-m-d'), null, false);
es('baja el archivo', 200, $n->codigo);
ok('y es un .xlsx', str_starts_with($n->cuerpo, 'PK'));

$tmp = sys_get_temp_dir() . '/waka-hoja-prueba.xlsx';
file_put_contents($tmp, $n->cuerpo);
$zip = new ZipArchive();
$hoja = $zip->open($tmp) === true ? (string)$zip->getFromName('xl/worksheets/sheet1.xml') : '';
$estilos = $zip->numFiles ? (string)$zip->getFromName('xl/styles.xml') : '';
$zip->close();
@unlink($tmp);

ok('la primera columna NO va como texto',
   !preg_match('/<c r="A2"[^>]*t="inlineStr"/', $hoja), substr($hoja, 0, 400));
ok('va como número con el estilo de fecha',
   (bool) preg_match('/<c r="A2" s="3"><v>\d+<\/v><\/c>/', $hoja), substr($hoja, 0, 400));
ok('y ese estilo tiene formato de fecha', str_contains($estilos, 'dd/mm/yyyy'));
es('el número de serie es el de hoy en Excel',
   (int) floor(strtotime(date('Y-m-d')) / 86400) + 25569,
   (int) (preg_match('/<c r="A2" s="3"><v>(\d+)<\/v>/', $hoja, $m) ? $m[1] : 0));
ok('y el monto sigue siendo un número sumable',
   (bool) preg_match('/<c r="G2" s="2"><v>[\d.]+<\/v><\/c>/', $hoja), substr($hoja, 0, 600));

grupo('El panel de Facturación no pinta botones que contestan 403');

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('no le sale la tarjeta de recepción',  !str_contains($n->cuerpo, 'En recepción ahora'));
ok('ni el botón de avisar',               !str_contains($n->cuerpo, 'AVISAR QUE LLEGÓ UN CLIENTE'));
ok('ni un solo enlace a /recepcion',      !str_contains($n->cuerpo, 'href="' . url('/recepcion') . '"'));
ok('ni la bitácora',                      !str_contains($n->cuerpo, 'Lo último que se tocó'));
ok('pero la columna no se queda vacía',    str_contains($n->cuerpo, 'Pagos sin revisar'));
ok('y no habla de contenedores que no puede abrir',
   !str_contains($n->cuerpo, 'contenedor en camino') && !str_contains($n->cuerpo, 'contenedores en camino'));

/* Administración sí las ve: la que se recorta es la cuenta sin permiso. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('a Administración sí le sale recepción', str_contains($n->cuerpo, 'En recepción ahora'));
ok('y la bitácora',                         str_contains($n->cuerpo, 'Lo último que se tocó'));
ok('y sí le hablan de los contenedores en camino',
   str_contains($n->cuerpo, 'contenedores en camino'), 'no salió la línea de datos');

grupo('El aviso de pagos nuevos · a quién le sale y a quién no');

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('a Facturación le sale el aviso en marcha', str_contains($n->cuerpo, 'data-avisa-pagos="1"'));

$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('a Administración también', str_contains($n->cuerpo, 'data-avisa-pagos="1"'));

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('al asesor no', !str_contains($n->cuerpo, 'data-avisa-pagos'));
$n->ir('/pagos/nuevos', null, false);
ok('y el sondeo le devuelve 403', $n->codigo === 403);

/* ═══════════════════  DIRECCIÓN  ═══════════════════ */
grupo('Dirección · lo ve todo y no toca nada');

$n->salir();
$n->entrar('ceo@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['asesor']);
ok('el CEO ve cualquier pedido', $n->codigo === 200);
$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['mx']);
ok('incluso los de México', $n->codigo === 200);
$n->ir('/pedidos/nuevo');
ok('pero NO puede registrar un pedido (403)', $n->codigo === 403);
$n->ir('/clientes/nuevo');
ok('ni dar de alta un cliente (403)', $n->codigo === 403);
$n->ir('/configuracion/equipos');
ok('sí configura equipos', $n->codigo === 200);

/* El CEO PUEDE confirmar un pago —lo pidió el usuario, para que nunca falte
   quien lo haga— pero NO recibe los avisos: un contador rojo que no piensa
   vaciar se deja de mirar en tres días, y con él los demás. */
$n->ir('/pagos/por-validar');
ok('el CEO puede entrar a la bandeja de pagos', $n->codigo === 200);
$n->ir('/inicio');
ok('pero NO le corre el aviso de pagos nuevos', !str_contains($n->cuerpo, 'data-avisa-pagos'));
ok('ni le sale la sección en el menú',
   !str_contains($n->cuerpo, 'href="/pagos/por-validar"'));

$n->ir('/pedidos/anular', ['_t' => $n->testigoValido(), 'id' => (int)$F['pedidos']['asesor'],
                           'motivo_item_id' => 1], false);
ok('y si fuerza una anulación, 403', $n->codigo === 403);

/* ═══════════════════  LO QUE NO SE SIRVE  ═══════════════════ */
grupo('Dirección · si la cabecera dice «todos los países», las cifras también');

/* La cabecera decía «Perú · todos los países» y debajo había cifras que solo
   contaban Perú, y al lado una línea de saludo que sí cruzaba: tres números de
   lo mismo en la misma pantalla. */
$of_mx = (int) valor('SELECT id FROM oficinas WHERE pais_id = ? LIMIT 1', [(int)$F['MX']]);
$of_pe = (int) valor('SELECT id FROM oficinas WHERE pais_id = ? LIMIT 1', [(int)$F['PE']]);
q("DELETE FROM visitas");
insertar('visitas', ['oficina_id'=>$of_pe, 'cliente_nombre'=>'Visita Perú',
                     'estado'=>'esperando', 'modulo'=>'M1']);
insertar('visitas', ['oficina_id'=>$of_mx, 'cliente_nombre'=>'Visita México',
                     'estado'=>'esperando', 'modulo'=>'M2']);
insertar('visitas', ['oficina_id'=>$of_mx, 'cliente_nombre'=>'Otra de México',
                     'estado'=>'esperando', 'modulo'=>'M3']);

$n->salir();
$n->entrar('ceo@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
$plano = preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo));
ok('la línea del saludo cuenta las tres visitas',
   str_contains($plano, '3 clientes esperando en oficina'), $plano);
ok('el chip de la bandeja dice lo mismo',
   str_contains($plano, '3 visitas en recepción'), $plano);
ok('y la tarjeta de recepción también',
   str_contains($n->cuerpo, 'Visita Perú') && str_contains($n->cuerpo, 'Visita México'));
ok('los rótulos dicen que las cifras cruzan países',
   str_contains($plano, 'en todos los países'), $plano);

/* Y a Administración de Perú, que NO cruza, se le sigue contando solo lo suyo. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
$plano = preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo));
ok('Administración cuenta una sola visita, la de su país',
   str_contains($plano, 'un cliente esperando en oficina'), $plano);
ok('y su rótulo sigue diciendo «en tu país»', str_contains($plano, 'en tu país'));
ok('sin la visita de México en la tarjeta', !str_contains($n->cuerpo, 'Visita México'));

grupo('Cierres de la última auditoría');

/* El N.º de operación no se esconde: se ignora. Esconder un campo no es
   quitarlo, y la bandeja lo precargaba delante de Facturación como si lo
   hubiera escrito ella. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/registrar', ['concepto'=>'Saldo de prueba', '_t'=>$n->testigoValido(),
                            'pedido_id'=>(int)$F['pedidos']['asesor'],
                            'monto'=>'3.00', 'metodo_item_id'=>(int)$F['efectivo'],
                            'fecha'=>date('Y-m-d'), 'operacion'=>'OP-DEL-ASESOR'], false);
$ultimo_pg = (int) valor('SELECT MAX(id) FROM pagos');
es('el pago del asesor entra sin N.º de operación, aunque lo mande a mano', '',
   (string) valor('SELECT operacion FROM pagos WHERE id = ?', [$ultimo_pg]));

$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/registrar', ['concepto'=>'Saldo de prueba', '_t'=>$n->testigoValido(),
                            'pedido_id'=>(int)$F['pedidos']['asesor'],
                            'monto'=>'3.00', 'metodo_item_id'=>(int)$F['efectivo'],
                            'fecha'=>date('Y-m-d'), 'operacion'=>'OP-DE-ADMIN'], false);
es('el de quien confirma pagos, sí lo guarda', 'OP-DE-ADMIN',
   (string) valor('SELECT operacion FROM pagos WHERE id = ?', [(int) valor('SELECT MAX(id) FROM pagos')]));

/* Dirección puede corregir su propia ficha. No podía: su rol no está entre los
   que puede repartir, y la validación lo rechazaba con «ese rol no existe». */
$n->salir();
$n->entrar('ceo@waka.test', 'Clave-Larga-1');
$id_ceo = (int) valor("SELECT id FROM usuarios WHERE email = 'ceo@waka.test'");
$n->ir('/usuarios/editar?id=' . $id_ceo, [
    '_t' => $n->testigoValido(),
    'nombre' => 'Cuenta', 'apellidos' => 'Corregida',
    'email' => 'ceo@waka.test', 'celular' => '911111111',
    'rol_id' => (int) valor("SELECT rol_id FROM usuarios WHERE id = ?", [$id_ceo]),
    'ambito' => 'todo',
    'pais_id' => (int)$F['PE'], 'nivel_cuota' => 0,
], false);
es('Dirección puede corregir su propia ficha', 'Corregida',
   (string) valor('SELECT apellidos FROM usuarios WHERE id = ?', [$id_ceo]));
es('y su rol no se mueve', 'direccion',
   (string) valor('SELECT r.clave FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=?', [$id_ceo]));

/* El dinero de Dirección no suma soles con pesos. */
$n->ir('/inicio');
$plano = preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo));
ok('las cifras de dinero dicen de qué país son',
   str_contains($plano, 'en Perú'), $plano);
ok('y no dicen «en todos los países» sobre un importe',
   !preg_match('/S\/[^A-Za-z]{0,20}[\d,.]+ en todos los países/u', $plano));
$vendido_pe = (int) valor(
    'SELECT COALESCE(SUM(total_centimos),0) FROM pedidos
      WHERE pais_id = ? AND fecha = ? AND anulado_en IS NULL',
    [(int)$F['PE'], date('Y-m-d')]);
ok('y el importe es el de su país, no la suma de los dos',
   str_contains($plano, soles_corto($vendido_pe)),
   'esperaba ' . soles_corto($vendido_pe) . ' en: ' . $plano);

/* «Devuelto» sale de un agregado, no de las filas recortadas. */
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/reportes/pagos?e=todos&desde=2000-01-01&hasta=' . date('Y-m-d'));
$devuelto_real = (int) valor(
    "SELECT COALESCE(SUM(-pg.monto_centimos),0) FROM pagos pg
       JOIN pedidos pe ON pe.id = pg.pedido_id
      WHERE pg.tipo = 'devolucion' AND pg.anulado = 0 AND pe.pais_id = ?", [(int)$F['PE']]);
ok('la tarjeta de devuelto cuadra con la base',
   str_contains($n->cuerpo, soles_corto($devuelto_real)),
   'esperaba ' . soles_corto($devuelto_real));

grupo('Ni un sol y un peso en la misma suma');

/* El símbolo salía escrito a mano y el reporte no acotaba el país para quien
   cruza: el CEO leía «Ingresado S/ 9,214» sumando soles peruanos con pesos
   mexicanos. Dos países, dos monedas, dos cifras. */
$n->salir();
$n->entrar('adminmx@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('México ve su cifra con SU símbolo', str_contains($n->cuerpo, '$ '), 'no salió el símbolo de México');
ok('y no con el del sol', !str_contains($n->cuerpo, 'S/ '), 'seguía pintando soles');

$n->salir();
$n->entrar('ceo@waka.test', 'Clave-Larga-1');
$n->ir('/reportes/pagos?e=todos&desde=2000-01-01&hasta=' . date('Y-m-d'));
ok('el reporte le ofrece elegir país', str_contains($n->cuerpo, 'name="p"'));
/* PAGO_CUENTA y nada más: la tarjeta «Ingresado» mide lo mismo que la meta. */
$ingresado_pe = (int) valor(
    "SELECT COALESCE(SUM(pg.monto_centimos),0) FROM pagos pg
       JOIN pedidos pe ON pe.id = pg.pedido_id
      WHERE pg.anulado = 0 AND pg.verificado = 1 AND pe.pais_id = ?", [(int)$F['PE']]);
ok('y por defecto cuenta solo su país, no la suma de los dos',
   str_contains($n->cuerpo, soles_corto($ingresado_pe)),
   'esperaba ' . soles_corto($ingresado_pe));

$n->ir('/reportes/pagos?e=todos&p=' . (int)$F['MX'] . '&desde=2000-01-01&hasta=' . date('Y-m-d'));
$ingresado_mx = (int) valor(
    "SELECT COALESCE(SUM(pg.monto_centimos),0) FROM pagos pg
       JOIN pedidos pe ON pe.id = pg.pedido_id
      WHERE pg.anulado = 0 AND pg.verificado = 1 AND pe.pais_id = ?", [(int)$F['MX']]);
/* Y con el símbolo de México, no con el del sol: el selector promete «cada uno
   cobra en su moneda» y el CEO es peruano. */
$esperado_mx = '$ ' . number_format($ingresado_mx / 100, 0, '.', ',');
ok('y si elige México, ve México', str_contains($n->cuerpo, $esperado_mx),
   'esperaba ' . $esperado_mx);
ok('con el símbolo de su moneda', !str_contains($n->cuerpo, 'S/ ' . number_format($ingresado_mx/100, 0, '.', ',')));
$n->ir('/reportes/pagos?p=99999', null, false);
es('un país inventado no rompe nada', 200, $n->codigo);

grupo('El aviso del ámbito solo salta cuando hay algo que contar');

/* El desplegable llega deshabilitado cuando el rol fija el ámbito, y un campo
   deshabilitado no se envía: tomando el relleno como una elección, el aviso
   saltaba en cada guardado diciendo «guardé otra cosa» a quien no había podido
   tocar el campo. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$id_admin2 = (int) valor("SELECT id FROM usuarios WHERE email = 'admin@waka.test'");
$n->ir('/usuarios/editar?id=' . $id_admin2, [
    '_t' => $n->testigoValido(),
    'nombre' => 'Cuenta', 'apellidos' => 'De Prueba',
    'email' => 'admin@waka.test', 'celular' => '922222222',
    'rol_id' => (int) valor("SELECT rol_id FROM usuarios WHERE id = ?", [$id_admin2]),
    'pais_id' => (int)$F['PE'], 'nivel_cuota' => 0,
    // sin 'ambito': el desplegable iba deshabilitado
], false);
$n->ir('/usuarios');
ok('guardar sin el campo de ámbito no inventa un aviso',
   !str_contains($n->cuerpo, 'El ámbito quedó en'), 'salió el aviso sin motivo');
es('y el ámbito sigue donde estaba', 'todo',
   (string) valor('SELECT ambito FROM usuarios WHERE id = ?', [$id_admin2]));

grupo('Las rutas nuevas · quién puede y quién no');

/* Las tres salidas del pago y el comprobante son de quien confirma pagos.
   Y valen las MISMAS puertas que confirmar: país y ámbito. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$t = $n->testigoValido();
$n->ir('/pagos/registrar', ['concepto'=>'Saldo de prueba', '_t'=>$t, 'pedido_id'=>(int)$F['pedidos']['asesor'],
                            'monto'=>'9.00', 'metodo_item_id'=>(int)$F['efectivo'],
                            'fecha'=>date('Y-m-d')], false);
$pg_nuevo = (int) valor('SELECT MAX(id) FROM pagos WHERE verificado = 0 AND anulado = 0');

foreach ([['/pagos/espera', ['id'=>$pg_nuevo,'nota'=>'x'], 'dejar en espera'],
          ['/pagos/denegar',['id'=>$pg_nuevo,'motivo'=>'x'], 'denegar'],
          ['/pedidos/comprobante', ['id'=>(int)$F['pedidos']['asesor'],'tipo'=>'boleta'], 'anotar el comprobante'],
         ] as [$r, $datos, $q]) {
    $n->ir($r, array_merge($datos, ['_t' => $n->testigoValido()]), false);
    ok("el asesor NO puede $q (403)", $n->codigo === 403, 'código ' . $n->codigo);
}
es('y el pago sigue intacto', 0,
   (int) valor('SELECT en_espera + anulado FROM pagos WHERE id = ?', [$pg_nuevo]));

$n->salir();
$n->entrar('factumx@waka.test', 'Clave-Larga-1');
foreach ([['/pagos/espera', ['id'=>$pg_nuevo,'nota'=>'x']],
          ['/pagos/denegar',['id'=>$pg_nuevo,'motivo'=>'x']]] as [$r, $datos]) {
    $n->ir($r, array_merge($datos, ['_t' => $n->testigoValido()]), false);
    es("facturación de México tampoco, sobre un pago de Perú · $r", 403, $n->codigo);
}

$n->salir();
$n->entrar('factuflaco@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/espera', ['_t'=>$n->testigoValido(), 'id'=>$pg_nuevo, 'nota'=>'x'], false);
es('ni una cuenta con el ámbito estrecho', 403, $n->codigo);

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/espera', ['_t'=>$n->testigoValido(), 'id'=>$pg_nuevo,
                         'nota'=>'Interbancario, entra mañana'], false);
es('facturación sí', 1, (int) valor('SELECT en_espera FROM pagos WHERE id = ?', [$pg_nuevo]));

$n->ir('/pagos/por-validar');
$plano = preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo));
ok('y la bandeja lo separa en su propio grupo', str_contains($plano, 'En espera'), $plano);
ok('con su nota a la vista', str_contains($plano, 'Interbancario, entra mañana'));
ok('y la cabecera cuenta las dos cosas por separado',
   (bool) preg_match('/pagos? sin revisar y \d+ en espera/u', $plano), $plano);

grupo('Despacho · el mensaje y su constancia');

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');

/* LA REGLA (usuario, 2026-09-09): «solo sale un producto a despacho si el pago
   fue confirmado». Este pedido tiene el pago pendiente de la fixtura, así que
   la pantalla NO arma el texto. Se comprueba aquí y no solo en las pruebas de
   unidad porque lo que llega al grupo es el texto copiado de esta pantalla:
   si el corte estuviera solo en el POST, el mensaje ya habría salido. */
$n->ir('/pedidos/despacho?id=' . (int)$F['pedidos']['asesor'], null, false);
es('con el pago sin confirmar, la pantalla corta (409)', 409, $n->codigo);
ok('y no arma el mensaje', !str_contains($n->cuerpo, '📦 DESPACHO'), 'armó el texto igual');
ok('y explica que falta confirmar el pago',
   str_contains($n->cuerpo, 'Todavía no sale a despacho'), substr($n->cuerpo, 0, 600));

/* Y la ficha no ofrece un botón que la ruta va a rechazar: un botón que lleva
   a un error se lee como que el HUB está roto, no como una regla. */
$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['asesor']);
ok('la ficha no ofrece el botón de despacho',
   !str_contains($n->cuerpo, url('/pedidos/despacho?id=' . (int)$F['pedidos']['asesor'])),
   'la ficha enlaza a una pantalla que contesta 409');
ok('y dice por qué en su lugar', str_contains($n->cuerpo, 'Todavía no sale a despacho'));

/* Sin nada pendiente, todo lo de siempre vuelve a funcionar. Este pedido lleva
   encima los pagos de una docena de pruebas anteriores, así que se limpian de
   una vez —es andamiaje de la prueba, no una operación del HUB— en lugar de
   confirmarlos uno a uno, que lo dejaría cobrado por encima de su total. */
q('UPDATE pagos SET anulado = 1 WHERE pedido_id = ? AND verificado = 0 AND anulado = 0',
         [(int)$F['pedidos']['asesor']]);

$n->ir('/pedidos/despacho?id=' . (int)$F['pedidos']['asesor']);
es('el asesor abre el mensaje de su pedido', 200, $n->codigo);
ok('y trae los datos del envío', str_contains($n->cuerpo, 'DESPACHO'));
$n->ir('/pedidos/despacho', ['_t'=>$n->testigoValido(), 'id'=>(int)$F['pedidos']['asesor']], false);
es('lo marca como mandado', 1,
   (int) valor('SELECT despacho_veces FROM pedidos WHERE id = ?', [(int)$F['pedidos']['asesor']]));

$n->ir('/pedidos/despacho?id=' . (int)$F['pedidos']['otro'], null, false);
es('pero no abre el de otro asesor (403)', 403, $n->codigo);

/* El líder LEE lo de su equipo pero no escribe en sus fichas: el ámbito
   «equipo» es de solo lectura en todo el HUB, y aquí tampoco deja de serlo. */
$n->salir();
$n->entrar('lider@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/despacho?id=' . (int)$F['pedidos']['asesor']);
es('el líder ve el mensaje de los suyos', 200, $n->codigo);
$antes_veces = (int) valor('SELECT despacho_veces FROM pedidos WHERE id = ?', [(int)$F['pedidos']['asesor']]);
$n->ir('/pedidos/despacho', ['_t'=>$n->testigoValido(), 'id'=>(int)$F['pedidos']['asesor']], false);
es('pero no anota en su ficha (403)', 403, $n->codigo);
es('y la cuenta no se movió', $antes_veces,
   (int) valor('SELECT despacho_veces FROM pedidos WHERE id = ?', [(int)$F['pedidos']['asesor']]));

grupo('El reporte sabe qué ventas no tienen comprobante');

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/reportes/pagos?e=todos&c=1&desde=2000-01-01&hasta=' . date('Y-m-d'));
es('el filtro responde', 200, $n->codigo);
ok('y todo lo que lista está sin emitir',
   !preg_match('/<td data-k="Comprobante">\s*<span class="mini">/u', $n->cuerpo),
   'salió alguna venta que sí tiene comprobante');

$n->ir('/pedidos/comprobante', ['_t'=>$n->testigoValido(), 'id'=>(int)$F['pedidos']['asesor'],
                                'tipo'=>'boleta', 'serie'=>'B001', 'numero'=>'00009999'], false);
es('se anota una boleta', 'B001',
   (string) valor('SELECT comprobante_serie FROM pedidos WHERE id = ?', [(int)$F['pedidos']['asesor']]));
$n->ir('/reportes/pagos?e=todos&desde=2000-01-01&hasta=' . date('Y-m-d'));
ok('y sale en el reporte', str_contains($n->cuerpo, 'B001-00009999'));

grupo('La pregunta del comprobante sale sola al confirmar');

/* Lo pidió el usuario: nadie vuelve por su cuenta a una pantalla a anotar algo
   que ya hizo en otro programa, así que la pregunta tiene que salir en el
   momento en que se confirma el pago. */
$n->salir();
$n->entrar('otro@waka.test', 'Clave-Larga-1');   // el pedido 'otro' es suyo
$n->ir('/pagos/registrar', ['concepto'=>'Saldo de prueba', '_t'=>$n->testigoValido(), 'pedido_id'=>(int)$F['pedidos']['otro'],
                            'monto'=>'15.00', 'metodo_item_id'=>(int)$F['efectivo'],
                            'fecha'=>date('Y-m-d')], false);
es('el pago se registra', 302, $n->codigo);
$pg_c = (int) valor('SELECT MAX(id) FROM pagos WHERE verificado = 0 AND anulado = 0');

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/validar', ['_t'=>$n->testigoValido(), 'id'=>$pg_c], true);
/* LA PREGUNTA SALE ENCIMA Y SIN MOVER A NADIE DE SITIO (usuario, 2026-09-11).
   Antes esto te llevaba a /pedidos/facturar y facturación perdía la cola en
   cada confirmación; ahora se vuelve a la bandeja con la ventana puesta. */
ok('al confirmar se vuelve a la bandeja, no a otra pantalla',
   str_contains($n->cuerpo, 'Pagos por validar'), 'acabó en otra pantalla');
ok('con la ventana encima', str_contains($n->cuerpo, 'class="tapa"'));
ok('diciendo lo que pasó',  str_contains($n->cuerpo, 'Pago aprobado'));
ok('y la pantalla pregunta por el comprobante',
   str_contains($n->cuerpo, '¿Emites el comprobante?'), 'no preguntó');
ok('y ofrece boleta',   str_contains($n->cuerpo, 'EMITIR BOLETA'));
ok('factura',           str_contains($n->cuerpo, 'EMITIR FACTURA'));
/* «OMITIR POR AHORA» YA NO EXISTE: lo borró el usuario. En su lugar están
   «ESTA NO LLEVA» —que anota que esa venta no lleva comprobante, sin pedir
   motivo— y «CERRAR VENTANA», que es de verdad no hacer nada. */
ok('y ESTA NO LLEVA',   str_contains($n->cuerpo, 'ESTA NO LLEVA'));
ok('sin el viejo «omitir por ahora»', !str_contains($n->cuerpo, 'OMITIR POR AHORA'));
ok('con la salida de cerrar',  str_contains($n->cuerpo, 'CERRAR VENTANA'));
ok('y el atajo a lo cobrado hoy', str_contains($n->cuerpo, 'VER INGRESOS DE HOY'));

/* Y SIN DECIR LO MISMO DOS VECES: el aviso verde de arriba repetía la frase de
   la ventana, quedaba DETRÁS del velo y se consumía en esa misma petición. */
es('el mensaje de la acción sale una sola vez', 1,
   substr_count($n->cuerpo, 'Pago aprobado'));

/* Cerrar la ventana no escribe nada: la venta sigue sin comprobante. */
es('cerrar no anota nada', '',
   (string) valor('SELECT COALESCE(comprobante_tipo, \'\') FROM pedidos WHERE id = ?',
                  [(int)$F['pedidos']['otro']]));

/* «ESTA NO LLEVA» anota «ninguno», NO pide motivo y devuelve a la cola
   (usuario, 2026-09-11). */
$n->ir('/pedidos/comprobante', ['_t'=>$n->testigoValido(), 'id'=>(int)$F['pedidos']['otro'],
                                'tipo'=>'ninguno', 'volver'=>'cola'], false);
es('«esta no lleva» anota que no lleva', 'ninguno',
   (string) valor('SELECT comprobante_tipo FROM pedidos WHERE id = ?', [(int)$F['pedidos']['otro']]));
$destino_no_lleva = implode(' | ', array_filter($n->cabeceras,
    fn($h) => stripos((string)$h, 'Location:') === 0));
ok('y devuelve a la cola de pagos',
   str_contains($destino_no_lleva, '/pagos/por-validar'), 'fue a ' . $destino_no_lleva);

$n->ir('/pedidos/comprobante', ['_t'=>$n->testigoValido(), 'id'=>(int)$F['pedidos']['otro'],
                                'tipo'=>'boleta', 'volver'=>'facturar'], false);
es('y elegir boleta sí', 'boleta',
   (string) valor('SELECT comprobante_tipo FROM pedidos WHERE id = ?', [(int)$F['pedidos']['otro']]));

/* Y no vuelve a preguntar por una venta que ya tiene comprobante. */
$n->salir(); $n->entrar('otro@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/registrar', ['concepto'=>'Saldo de prueba', '_t'=>$n->testigoValido(), 'pedido_id'=>(int)$F['pedidos']['otro'],
                            'monto'=>'5.00', 'metodo_item_id'=>(int)$F['efectivo'],
                            'fecha'=>date('Y-m-d')], false);
$pg_c2 = (int) valor('SELECT MAX(id) FROM pagos WHERE verificado = 0 AND anulado = 0');
$n->salir(); $n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/validar', ['_t'=>$n->testigoValido(), 'id'=>$pg_c2], true);
ok('con el comprobante ya anotado, no vuelve a preguntar',
   !str_contains($n->cuerpo, '¿emites el comprobante?'));

grupo('El asesor quita su propio pago con motivo, y queda en el reporte');

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/registrar', ['concepto'=>'Saldo de prueba', '_t'=>$n->testigoValido(), 'pedido_id'=>(int)$F['pedidos']['asesor'],
                            'monto'=>'44.00', 'metodo_item_id'=>(int)$F['efectivo'],
                            'fecha'=>date('Y-m-d')], false);
$pg_q = (int) valor('SELECT MAX(id) FROM pagos WHERE verificado = 0 AND anulado = 0');
$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['asesor']);
ok('la ficha le ofrece quitarlo con motivo',
   str_contains($n->cuerpo, 'Motivo · p. ej. voucher falso'));
$n->ir('/pagos/quitar', ['_t'=>$n->testigoValido(), 'id'=>$pg_q,
                         'motivo'=>'El cliente mandó un voucher falso'], false);
es('el pago queda apagado', 1, (int) valor('SELECT anulado FROM pagos WHERE id=?', [$pg_q]));
es('con su motivo', 'El cliente mandó un voucher falso',
   (string) valor('SELECT anulado_motivo FROM pagos WHERE id=?', [$pg_q]));
es('y NO como denegado por facturación', 0,
   (int) valor('SELECT denegado FROM pagos WHERE id=?', [$pg_q]));

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/reportes/pagos?e=rechazados&desde=2000-01-01&hasta=' . date('Y-m-d'));
es('el reporte tiene su filtro', 200, $n->codigo);
ok('y ahí sale, con el motivo',
   str_contains($n->cuerpo, 'El cliente mandó un voucher falso'), 'no salió en el reporte');
ok('marcado como quitado', str_contains($n->cuerpo, 'Quitado'));

grupo('Ventas sin comprobante · se cuentan sobre PEDIDOS, no sobre pagos');

/* El filtro anterior colgaba de la lista de pagos, así que una venta sin
   ningún pago no aparecía nunca y con el estado por defecto la lista salía
   vacía justo cuando importaba. */
$n->ir('/reportes/pagos?desde=2000-01-01&hasta=' . date('Y-m-d'));
ok('la tarjeta existe', str_contains($n->cuerpo, 'Ventas sin comprobante'), 'no salió la tarjeta');

$sin = (int) valor(
    "SELECT COUNT(*) FROM pedidos WHERE anulado_en IS NULL AND pais_id = ?
       AND (comprobante_tipo IS NULL OR comprobante_tipo = '')", [(int)$F['PE']]);
ok('y cuenta las ventas de verdad, tengan pagos o no',
   str_contains(preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo)),
                'Ventas sin comprobante ' . $sin),
   'esperaba ' . $sin);

grupo('Auditoría 15 · el reporte cuadra consigo mismo');

$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/reportes/pagos?e=todos&desde=2000-01-01&hasta=' . date('Y-m-d'));
$plano = preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo));

/* «Ingresado» tiene que ser PAGO_CUENTA y nada más: la MISMA cifra que mide la
   meta, el podio y los bonos. Sumando también lo sin confirmar, la tarjeta
   decía una cosa y su propio desglose, diez centímetros más abajo, otra. */
$cuenta = cobrado_del_pais((int)$F['PE'], '2000-01-01', date('Y-m-d'));
ok('«Ingresado» es lo mismo que mide la meta',
   str_contains($plano, soles_corto($cuenta)), 'esperaba ' . soles_corto($cuenta) . ' en: ' . $plano);

/* Y se lee la tarjeta misma, no «está en algún sitio de la página»: la suma de
   lo confirmado más lo pendiente puede coincidir por casualidad con otra cifra
   de la pantalla, y entonces la prueba pasaría sin medir nada. */
preg_match('/Ingresado ([^ ]+ [\d,.]+)/u', $plano, $m);
es('la tarjeta «Ingresado» dice exactamente eso', soles_corto($cuenta), trim($m[1] ?? '(no salió)'));

ok('con pocas filas no dice que recortó nada',
   !str_contains($plano, 'se calculó con los'), $plano);

/* Y el desglose de al lado tiene que sumar lo MISMO que la tarjeta: es su
   desglose. Salía de las filas filtradas y decía otra cosa. */
preg_match_all('/S\/ ([\d,]+\.\d{2}) \d+ pagos?/u', $plano, $mm);
$suma = 0;
foreach ($mm[1] as $x) $suma += (int) round(((float) str_replace(',', '', $x)) * 100);
es('el desglose por día suma lo mismo que «Ingresado»', $cuenta, $suma);

$n->ir('/reportes/pagos?e=rechazados&desde=2000-01-01&hasta=' . date('Y-m-d'));
$plano = preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo));
ok('con el filtro de rechazados tampoco',
   !str_contains($plano, 'se calculó con los'), $plano);
ok('y las tarjetas siguen contando dinero de verdad',
   str_contains($plano, soles_corto($cuenta)), 'las tarjetas cambiaron con el filtro');

grupo('Auditoría 15 · un pedido anulado no invita a nada');

$n->ir('/pedidos/anular', ['_t'=>$n->testigoValido(), 'id'=>(int)$F['pedidos']['otro'],
                           'motivo_item_id'=>(int) valor("SELECT i.id FROM lista_items i
                                JOIN listas l ON l.id=i.lista_id WHERE l.clave='motivos_anulacion' LIMIT 1")], false);
es('el pedido queda anulado', 'anulado',
   (string) valor('SELECT e.clave FROM pedidos p JOIN pedido_estados e ON e.id=p.estado_id WHERE p.id=?',
                  [(int)$F['pedidos']['otro']]));

$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['otro']);
ok('la ficha no ofrece mandarlo a despacho', !str_contains($n->cuerpo, 'MANDAR A DESPACHO'));
ok('ni ver el mensaje de despacho',          !str_contains($n->cuerpo, 'VER EL MENSAJE DE DESPACHO'));
ok('y lo dice con todas las letras',          str_contains($n->cuerpo, 'no se despacha'));

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['otro']);
ok('a facturación tampoco le ofrece los datos para facturar',
   !str_contains($n->cuerpo, 'Datos para facturar'));
/* Y a facturación la ruta de despacho ya no le pertenece: mandar al grupo es
   tarea del asesor (usuario, 2026-09-09). Antes le salía solo por llevar
   'pedidos.ver'. */
$n->ir('/pedidos/despacho?id=' . (int)$F['pedidos']['otro'], null, false);
es('y la ruta de despacho no es suya (403)', 403, $n->codigo);
$n->ir('/pedidos/por-despachar', null, false);
es('ni la lista de por despachar', 403, $n->codigo);

/* El 410 del pedido anulado sigue en pie para quien SÍ despacha. */
$n->salir();
$n->entrar('otro@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/despacho?id=' . (int)$F['pedidos']['otro'], null, false);
es('a su dueño la ruta le corta por anulado', 410, $n->codigo);

grupo('Auditoría 15 · «omitir por ahora» ya no existe');

/* La tarjeta de «¿emites el comprobante?» que vivía DENTRO de /pedidos/facturar
   se fue con el parche 2j: la pregunta la hace ahora la ventana superpuesta,
   igual en las tres pantallas. Aquella tenía su propio «OMITIR POR AHORA» —que
   no anotaba nada— y su propio `volver` en la dirección; con dos botones en el
   mismo sitio haciendo lo contrario, el que quedaba muerto era una trampa. */
$n->ir('/pedidos/facturar?id=' . (int)$F['pedidos']['asesor'] . '&comprobante=1');
ok('la tarjeta vieja ya no se pinta', !str_contains($n->cuerpo, 'OMITIR POR AHORA'));
ok('ni con la dirección que la abría', !str_contains($n->cuerpo, 'Pago confirmado'));

grupo('Auditoría 16 · lo omitido vuelve a aparecer solo');

/* «Omitir por ahora» solo es honesto si lo omitido se vuelve a ver. La tarjeta
   del reporte va por rango y el rango nace en el mes en curso, así que una pre
   venta de hace meses se quedaba fuera y la pantalla se leía como «ya está
   todo emitido». */
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');

/* EL AVISO DE «X VENTAS SIN COMPROBANTE» YA NO EXISTE EN LA BANDEJA (usuario,
   2026-09-11): «también debemos de quitarla». Aquí no siempre se emite
   comprobante —a veces a propósito—, así que ese contador estaba regañando por
   algo que es normal. La cola sigue viva en el reporte, que es donde se mira a
   propósito; lo que se quita es el reproche en la pantalla de trabajo diario. */
$n->ir('/pagos/por-validar');
ok('la bandeja YA NO regaña con las ventas sin comprobante',
   !str_contains($n->cuerpo, 'sin comprobante'),
   'volvió el aviso que el usuario mandó quitar');

/* Un rango donde no hay ninguna venta: la tarjeta tiene que seguir diciendo
   cuántas quedan fuera en vez de desaparecer. */
$n->ir('/reportes/pagos?desde=2019-01-01&hasta=2019-01-31');
ok('con un rango vacío la tarjeta no desaparece',
   str_contains($n->cuerpo, 'Ventas sin comprobante'),
   'la tarjeta se esconde y la cola se vuelve invisible');
ok('y dice cuántas quedan fuera del rango',
   str_contains($n->cuerpo, 'Fuera de este rango quedan'),
   'no avisa de las que el filtro está tapando');
ok('sin pintar una tabla vacía',
   str_contains($n->cuerpo, 'Ninguna en este rango'));

grupo('Auditoría 18 · el asesor se entera de que ya puede despachar');

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');

$n->ir('/pedidos/por-despachar');
es('el asesor abre su lista de por despachar', 200, $n->codigo);
ok('y el menú le pinta la sección', str_contains($n->cuerpo, 'Por despachar'));

/* La cifra del chip y la de la propia pantalla salen de la misma función; si
   alguien escribiera la consulta a mano en una de las dos, aquí saltaría. */
$esperado = (int) valor(
    'SELECT COUNT(*) FROM pedidos pe
      WHERE pe.anulado_en IS NULL AND pe.despacho_veces = 0
        AND pe.asesor_id = (SELECT id FROM usuarios WHERE email = ?)
        AND ' . sql_despacho_listo(), ['asesor@waka.test']);
$plano = preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo));
ok('la pantalla dice la misma cifra que el chip del menú',
   $esperado === 0
     ? str_contains($plano, 'No tienes nada esperando salir')
     : (bool) preg_match('/' . $esperado . ' ventas? listas? para mandar/u', $plano),
   'esperaba ' . $esperado . ' · ' . mb_substr($plano, 0, 300));

$n->ir('/inicio');
ok('y su panel lo dice también',
   $esperado === 0 || str_contains($n->cuerpo, 'listas para despacho')
                   || str_contains($n->cuerpo, 'lista para despacho'),
   'el panel no menciona los despachos pendientes');

/* El sondeo en vivo: nunca avisa en la primera vuelta, porque sin marca de
   agua previa «hay 7 pendientes» se leería como «entraron 7 ahora mismo». */
$n->ir('/pedidos/nuevos-despacho');
$j = json_decode($n->cuerpo, true);
ok('el sondeo contesta JSON', is_array($j) && !empty($j['ok']), $n->cuerpo);
es('y sin marca de agua previa no avisa de nada', 0, (int)($j['nuevos'] ?? -1));

grupo('Auditoría 18 · cuándo entregar viaja hasta el mensaje');

/* Un pedido suyo, listo para despachar, al que se le pone la ventana desde la
   propia pantalla de despacho. COBRADO AL 100%: más abajo se muda a Cusco para
   ver la pantalla de provincia, y desde el 2s a provincia solo sale lo que
   está pagado entero — con saldo, la pantalla ya ni se abriría. */
$pd = (int) valor(
    'SELECT pe.id FROM pedidos pe
      WHERE pe.anulado_en IS NULL AND pe.asesor_id = (SELECT id FROM usuarios WHERE email = ?)
        AND pe.total_centimos - pe.cobrado_centimos = 0
        AND ' . sql_despacho_listo() . ' ORDER BY pe.id LIMIT 1', ['asesor@waka.test']);
ok('hay un pedido cobrado entero con el que probar', $pd > 0);
if ($pd) {
    $n->ir('/pedidos/despacho', ['_t'=>$n->testigoValido(), 'id'=>$pd, 'accion'=>'entrega',
        'entrega_fecha'=>date('Y-m-d', strtotime('+1 day')), 'entrega_hora_desde'=>'15:00',
        'entrega_hora_hasta'=>'18:00', 'entrega_recepcion'=>'1'], false);
    /* Y a provincia NO se ofrece, porque ahí el horario lo pone la agencia.
       Antes se ofrecía, se pulsaba GUARDAR, salía «Anotado, el mensaje ya lo
       dice» en verde… y no lo decía ni lo tenía. */
    $cusco_id = (int) valor("SELECT d.id FROM ubigeo d JOIN ubigeo p ON p.id=d.padre_id
                              WHERE d.tipo='distrito' AND d.nombre='Wanchaq' AND p.nombre='Cusco'");
    $lima_antes = valor('SELECT ubigeo_id FROM pedidos WHERE id = ?', [$pd]);
    q('UPDATE pedidos SET ubigeo_id = ?, recojo_agencia = 1 WHERE id = ?', [$cusco_id, $pd]);
    $n->ir('/pedidos/despacho?id=' . $pd);
    ok('a provincia no se ofrece anotar el día ni la hora',
       !str_contains($n->cuerpo, '¿Quedaron en un día u hora?'));
    ok('y se dice quién pone la hora', str_contains($n->cuerpo, 'los pone la agencia'));
    ok('y cómo lo recibe el cliente', str_contains($n->cuerpo, 'Recoge en la agencia'));
    $n->ir('/pedidos/despacho', ['_t'=>$n->testigoValido(), 'id'=>$pd, 'accion'=>'entrega',
        'entrega_fecha'=>date('Y-m-d', strtotime('+1 day')), 'entrega_hora_desde'=>'15:00'], false);
    $n->ir('/pedidos/despacho?id=' . $pd);
    ok('y si alguien la manda igual, se le dice que NO se guardó',
       !str_contains($n->cuerpo, 'Anotado cuándo hay que entregarlo'));
    es('y no se guardó', null, valor('SELECT entrega_fecha FROM pedidos WHERE id = ?', [$pd]));
    q('UPDATE pedidos SET ubigeo_id = ?, recojo_agencia = 0 WHERE id = ?', [$lima_antes, $pd]);

    $n->ir('/pedidos/despacho', ['_t'=>$n->testigoValido(), 'id'=>$pd, 'accion'=>'entrega',
        'entrega_fecha'=>date('Y-m-d', strtotime('+1 day')), 'entrega_hora_desde'=>'15:00',
        'entrega_hora_hasta'=>'18:00', 'entrega_recepcion'=>'1'], false);
    $n->ir('/pedidos/despacho?id=' . $pd);
    ok('la ventana de entrega queda en el mensaje',
       str_contains($n->cuerpo, 'de 15:00 a 18:00'), 'no salió el rango');
    ok('y lo de recepción también',
       str_contains($n->cuerpo, 'DEJAR EN RECEPCIÓN'));

    $n->ir('/pedidos/despacho', ['_t'=>$n->testigoValido(), 'id'=>$pd, 'accion'=>'entrega',
        'entrega_hora_desde'=>'18:00', 'entrega_hora_hasta'=>'09:00'], false);
    $n->ir('/pedidos/despacho?id=' . $pd);
    ok('un rango al revés no se guarda',
       !str_contains($n->cuerpo, 'de 18:00 a 09:00'), 'guardó el rango invertido');
} else {
    ok('había un pedido listo para probar la ventana de entrega', false, 'no lo hubo');
}

grupo('limpiar.php · las puertas antes del borrado');

/* No se prueba el borrado en sí por HTTP: vaciaría la base con la que corren
   todas las pruebas de este archivo. Lo que sí se prueba es lo que protege el
   borrado, que es lo que de verdad puede salir caro. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/limpiar.php', null, false);
es('un asesor no puede ni abrirlo (403)', 403, $n->codigo);
es('y el HUB sigue con sus pedidos', true,
   (int) valor('SELECT COUNT(*) FROM pedidos') > 0);

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/limpiar.php', null, false);
es('facturación tampoco (403)', 403, $n->codigo);

$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/limpiar.php');
es('Administración sí lo abre', 200, $n->codigo);
ok('y ve antes lo que se llevaría', str_contains($n->cuerpo, 'Lo que se va'));
ok('y lo que se conserva', str_contains($n->cuerpo, 'Lo que se queda'));
ok('el catálogo sale apagado por defecto',
   str_contains($n->cuerpo, 'El catálogo NO se toca'), 'nace encendido');

/* Que la página llegue ENTERA hasta el final. Una llamada a una función que no
   está cargada corta la salida a media página sin cambiar el código HTTP: el
   200 seguía siendo 200 y media pantalla no existía. Pasó de verdad con ico(),
   que vive en la capa de vistas. */
ok('y la página llega entera hasta el botón',
   str_contains($n->cuerpo, 'BORRAR Y EMPEZAR DE CERO')
   && str_contains($n->cuerpo, '</html>'), 'la salida se cortó a media página');

$antes = (int) valor('SELECT COUNT(*) FROM pedidos');
ok('abrirlo no borra nada', $antes > 0);

/* Las dos llaves, por separado: cada una tiene que parar el borrado ella sola. */
$n->ir('/limpiar.php', ['_t'=>$n->testigoValido(), 'palabra'=>'borrar',
                        'clave'=>'Clave-Larga-1'], false);
es('sin la palabra en mayúsculas no borra', $antes,
   (int) valor('SELECT COUNT(*) FROM pedidos'));

$n->ir('/limpiar.php', ['_t'=>$n->testigoValido(), 'palabra'=>'BORRAR',
                        'clave'=>'la-que-no-es'], false);
es('con la contraseña equivocada tampoco', $antes,
   (int) valor('SELECT COUNT(*) FROM pedidos'));

$n->ir('/limpiar.php', ['palabra'=>'BORRAR', 'clave'=>'Clave-Larga-1'], false);
es('y sin el testigo CSRF menos todavía', $antes,
   (int) valor('SELECT COUNT(*) FROM pedidos'));

grupo('Auditoría 19 · el modo noche no se apaga solo');

/* EL FALLO: el JS corrige el tema cada minuto si la persona lo dejó en «auto».
   Se protege con data-auto y data-forzado, que LEÍA pero que el marco no
   escribía nunca — y `undefined !== '0'` es verdadero, así que la guarda no
   frenaba jamás. A los sesenta segundos la pantalla se ponía en blanco en
   mitad de un formulario aunque hubieras elegido «oscuro» a propósito. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');

$n->ir('/mi-perfil', ['_t'=>$n->testigoValido(), 'tema'=>'oscuro'], false);
$n->ir('/pedidos/nuevo');
ok('con el tema fijado en oscuro, el HUB lo pinta oscuro',
   str_contains($n->cuerpo, 'data-theme="oscuro"'), 'no salió oscuro');
ok('y le dice al JS que NO lo corrija',
   str_contains($n->cuerpo, 'data-auto="0"') && str_contains($n->cuerpo, 'data-forzado="1"'),
   'faltan las banderas: el temporizador volvería a apagarlo');

$n->ir('/mi-perfil', ['_t'=>$n->testigoValido(), 'tema'=>'auto'], false);
$n->ir('/pedidos/nuevo');
ok('en «auto» sí se le deja corregir', str_contains($n->cuerpo, 'data-auto="1"'));

grupo('Auditoría 19 · el N.º de operación donde existe');

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');

/* Un pago por transferencia sin número no se valida: ese número es lo único
   que después permite cuadrarlo contra el extracto. */
/* Se siembra uno a propósito en vez de buscar entre los que queden: las
   pruebas de arriba van confirmando pagos, así que «el que sobre» depende del
   orden de ejecución — y una prueba que depende del orden pasa por casualidad. */
$met_op = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id
                        WHERE l.clave = 'metodos_pago' AND i.pide_comprobante = 1 LIMIT 1");
$id_pend = insertar('pagos', [
    'pedido_id'       => (int)$F['pedidos']['asesor'],
    'monto_centimos'  => 100,
    'metodo_item_id'  => $met_op,
    'fecha'           => date('Y-m-d'),
    'tipo'            => 'cobro',
    'verificado'      => 0,
    'anulado'         => 0,
]);
$pend = ['id' => $id_pend];
if ($pend) {
    $n->ir('/pagos/validar', ['_t'=>$n->testigoValido(), 'id'=>(int)$pend['id'], 'operacion'=>''], false);
    es('sin N.º de operación NO se valida', 0,
       (int) valor('SELECT verificado FROM pagos WHERE id = ?', [(int)$pend['id']]));
    $n->ir('/pagos/validar', ['_t'=>$n->testigoValido(), 'id'=>(int)$pend['id'],
                              'operacion'=>'OP-CUADRA-1'], false);
    es('con él, sí', 1,
       (int) valor('SELECT verificado FROM pagos WHERE id = ?', [(int)$pend['id']]));
} else {
    ok('había un pago sin operación para probarlo', false, 'no lo hubo');
}

/* Otro pendiente: el de arriba acaba de confirmarse en la prueba anterior, y
   una bandeja vacía no pinta ninguna fila — la comprobación pasaría o fallaría
   por el orden, no por lo que se quiere probar. */
insertar('pagos', [
    'pedido_id'      => (int)$F['pedidos']['asesor'],
    'monto_centimos' => 100,
    'metodo_item_id' => $met_op,
    'fecha'          => date('Y-m-d'),
    'tipo'           => 'cobro', 'verificado' => 0, 'anulado' => 0,
]);

$n->ir('/pagos/por-validar');
/* LAS TRES SALIDAS, EN LA PROPIA FILA. Antes solo se veía «Validar» y desde la
   bandeja parecía que confirmar era lo único posible con un voucher que no
   cuadra. Se comprueba que están las tres Y que las dos que piden motivo
   traen su casilla: unos botones que abren una franja vacía no sirven. */
ok('la bandeja ofrece las tres salidas',
   str_contains($n->cuerpo, '>Validar<')
   && str_contains($n->cuerpo, '>En espera<')
   && str_contains($n->cuerpo, '>Denegar<'), 'falta alguna de las tres');
ok('y las dos que piden motivo lo piden de verdad',
   str_contains($n->cuerpo, 'action="/pagos/espera"')
   && str_contains($n->cuerpo, 'action="/pagos/denegar"')
   && str_contains($n->cuerpo, 'name="nota"')
   && str_contains($n->cuerpo, 'name="motivo"'), 'la franja no trae las casillas');
ok('y marca el N.º de operación como obligatorio',
   str_contains($n->cuerpo, 'N.º de operación *'), 'no lo marca');

/* Y que funcionen desde aquí, no solo que se vean. */
$p_esp = (int) valor("SELECT id FROM pagos WHERE verificado = 0 AND anulado = 0
                        AND en_espera = 0 ORDER BY id DESC LIMIT 1");
$n->ir('/pagos/espera', ['_t'=>$n->testigoValido(), 'id'=>$p_esp,
                         'nota'=>'Interbancario, entra mañana'], false);
es('dejar en espera desde la bandeja funciona', 1,
   (int) valor('SELECT en_espera FROM pagos WHERE id = ?', [$p_esp]));

$n->ir('/pagos/denegar', ['_t'=>$n->testigoValido(), 'id'=>$p_esp,
                          'motivo'=>'No aparece en el extracto'], false);
es('y denegar también', 1, (int) valor('SELECT denegado FROM pagos WHERE id = ?', [$p_esp]));
es('sin anular el pedido', 0,
   (int) valor('SELECT COUNT(*) FROM pedidos WHERE id = (SELECT pedido_id FROM pagos WHERE id = ?)
                  AND anulado_en IS NOT NULL', [$p_esp]));

grupo('Auditoría 19 · la línea de tiempo y la bitácora con nombre');

$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['asesor']);
ok('la ficha pinta la línea de tiempo', str_contains($n->cuerpo, 'class="hitos"'));
$plano = preg_replace('/\s+/u', ' ', strip_tags($n->cuerpo));
ok('con sus cuatro hitos',
   str_contains($plano, 'Registrado') && str_contains($plano, 'despacho')
   && str_contains($plano, 'Entregado'), mb_substr($plano, 0, 300));
/* Los textos para el cliente se fueron de la cabecera de la ficha (usuario,
   2026-09-22): ahora están en «Mandar a despacho», y el de los datos para el
   pago sale en la tarjeta de Pagos mientras quede saldo. */
ok('la cabecera de la ficha ya no lleva «Textos rápidos»', !str_contains($n->cuerpo, 'Textos rápidos'));
ok('ni «Datos para facturar»', !str_contains($n->cuerpo, 'Datos para facturar'));

$n->ir('/reportes/bitacora');
es('el reporte de la bitácora abre', 200, $n->codigo);
ok('y cada línea dice sobre qué', str_contains($n->cuerpo, 'Sobre qué'));

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/reportes/bitacora', null, false);
es('pero no es de todos (403)', 403, $n->codigo);

/* Se le devuelve el tema a «auto», que es como estaba: una prueba que deja la
   cuenta en oscuro le cambiaría la pantalla a las pruebas de más abajo. */
$n->ir('/mi-perfil', ['_t'=>$n->testigoValido(), 'tema'=>'auto'], false);

grupo('Quien puede vender, tiene por dónde empezar');

/* Administración PUEDE registrar una venta desde el primer día, pero su Inicio
   no tenía por dónde empezar una: había que ir a Pedidos y buscar el botón.
   Lo reportó el usuario el 2026-09-10. Va por PERMISO, no por rol: si mañana
   se le quita «pedidos.crear» a Administración, el botón se va solo. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('el Inicio de Administración ofrece registrar una venta',
   str_contains($n->cuerpo, 'NUEVO PEDIDO'));
ok('y el botón lleva al formulario, no a la lista',
   str_contains($n->cuerpo, 'href="/pedidos/nuevo"'));
$n->ir('/pedidos');
ok('en Pedidos también lo tiene', str_contains($n->cuerpo, 'NUEVO PEDIDO'));

$n->salir();
$n->entrar('ceo@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('a Dirección NO le sale: no registra ventas',
   !str_contains($n->cuerpo, 'NUEVO PEDIDO'));

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('a Facturación tampoco', !str_contains($n->cuerpo, 'NUEVO PEDIDO'));

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('y el asesor lo sigue teniendo en su Inicio',
   str_contains($n->cuerpo, 'NUEVO PEDIDO'));
ok('llevando al formulario', str_contains($n->cuerpo, 'href="/pedidos/nuevo"'));

grupo('Parche 2j · la ventana del pago, en espera y denegado');

/* Las tres decisiones sobre un pago devuelven a la MISMA pantalla con su
   ventana encima (usuario, 2026-09-11). Se comprueban las tres porque la que
   más importa es la que menos se usa: denegar. */
$ventana = function (string $accion, array $extra = []) use ($n, $F) {
    $n->salir();
    $n->entrar('otro@waka.test', 'Clave-Larga-1');
    $n->ir('/pagos/registrar', ['concepto'=>'Saldo de prueba', '_t'=>$n->testigoValido(), 'pedido_id'=>(int)$F['pedidos']['otro'],
                                'monto'=>'7.00', 'metodo_item_id'=>(int)$F['efectivo'],
                                'fecha'=>date('Y-m-d')], false);
    $pg = (int) valor('SELECT MAX(id) FROM pagos WHERE verificado = 0 AND anulado = 0');
    $n->salir();
    $n->entrar('factu@waka.test', 'Clave-Larga-1');
    $n->ir('/pagos/' . $accion, array_merge(['_t'=>$n->testigoValido(), 'id'=>$pg], $extra), true);
    return $pg;
};

$ventana('espera', ['nota' => 'Interbancario, entra mañana']);
ok('dejarlo en espera vuelve a la bandeja',
   str_contains($n->cuerpo, 'Pagos por validar'), 'acabó en otra pantalla');
ok('con su ventana encima',   str_contains($n->cuerpo, 'Pago en espera'));
ok('que no pregunta por el comprobante',
   !str_contains($n->cuerpo, '¿Emites el comprobante?'),
   'no hay comprobante que emitir de un dinero que no entró');
ok('y ofrece seguir revisando', str_contains($n->cuerpo, 'SEGUIR REVISANDO'));
ok('o ver el pedido',           str_contains($n->cuerpo, 'VER EL PEDIDO'));

$ventana('denegar', ['motivo' => 'El voucher no aparece en el banco']);
ok('denegar también vuelve a la bandeja', str_contains($n->cuerpo, 'Pagos por validar'));
ok('con su ventana',   str_contains($n->cuerpo, 'Pago denegado'));
ok('y dice lo que NO pasa: el pedido no se anula',
   str_contains($n->cuerpo, 'El pedido no se anula'));
ok('sin preguntar por el comprobante',
   !str_contains($n->cuerpo, '¿Emites el comprobante?'));

/* Y la ventana no puede enseñar un pedido que quien mira no puede abrir: la
   dirección la escribe cualquiera. */
$n->ir('/pagos/por-validar?hecho=aprobado&ped=999999');
ok('con un pedido que no existe, no se pinta ninguna ventana',
   !str_contains($n->cuerpo, 'Pago aprobado'));
/* Y EL ÁMBITO. La bandeja contesta 200 a todo el mundo —cada uno ve la suya—,
   así que la ventana es el sitio por donde se podría colar el código y el
   nombre del cliente de un pedido que esa cuenta no puede abrir: el `ped` lo
   escribe cualquiera en la barra de direcciones. Se comprueba contra la cuenta
   de facturación de ámbito estrecho, que en la ficha recibe 403. */
$n->salir();
$n->entrar('factuflaco@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['asesor'], null, false);
es('a la cuenta estrecha ese pedido le da 403', 403, $n->codigo);
$codigo_ajeno = (string) valor('SELECT codigo FROM pedidos WHERE id = ?',
                               [(int)$F['pedidos']['asesor']]);
$n->ir('/pagos/por-validar?hecho=aprobado&ped=' . (int)$F['pedidos']['asesor']);
es('su bandeja abre igual', 200, $n->codigo);
ok('pero la ventana no se pinta',  !str_contains($n->cuerpo, 'Pago aprobado'));
ok('ni se le escapa el código del pedido ajeno',
   $codigo_ajeno !== '' && !str_contains($n->cuerpo, $codigo_ajeno),
   'se coló ' . $codigo_ajeno);

grupo('Parche 2j · el 4% del POS lo pone el servidor, no el navegador');

/* La cuenta vivía SOLO en el JavaScript de la pantalla: sin JS, cada venta con
   POS se guardaba con comisión 0 y nadie se enteraba. Estas pruebas mandan el
   POST a pelo —que es exactamente un navegador sin JavaScript— así que si la
   regla vuelve a irse al navegador, aquí salta. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$POS3 = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id=i.lista_id
                      WHERE l.clave='metodos_pago' AND i.valor='POS / tarjeta'");
/* El POS pide voucher, así que va con archivo, como en la pantalla real. */
$con_pos = function (string $comision) use ($n, $base, $POS3, $JPG) {
    $n->ir('/pedidos/nuevo');
    $n->subir('/pedidos/nuevo', array_merge($base, [
        '_t' => $n->testigo(), 'f_id' => $n->oculto('f_id'),
        'destino' => 'oficina', 'pago_metodo_item_id' => $POS3,
        'pago_monto' => '500.00', 'comision' => $comision,
    ]), ['voucher' => ['pos.jpg', 'image/jpeg', $JPG], 'foto_dni' => ['dni.jpg', 'image/jpeg', $JPG]]);   // el POS pide también el DNI (3f)
};
$con_pos('');
ok('la venta con POS se registra', str_contains($n->cuerpo, 'Pedido registrado'),
   mb_substr(strip_tags($n->cuerpo), 0, 200));
es('y el 4% queda anotado sin que nadie lo teclee', 2000,
   (int) valor('SELECT comision_centimos FROM pedidos ORDER BY id DESC LIMIT 1'));

/* Y sigue mandando lo que escriba la persona: el banco cobra lo que cobra. */
$con_pos('17.50');
es('lo tecleado manda sobre el cálculo', 1750,
   (int) valor('SELECT comision_centimos FROM pedidos ORDER BY id DESC LIMIT 1'));

/* En efectivo no hay pasarela que cobre nada. */
$mandar(['destino' => 'oficina', 'comision' => '']);
es('en efectivo la comisión se queda en cero', 0,
   (int) valor('SELECT comision_centimos FROM pedidos ORDER BY id DESC LIMIT 1'));

/* PARCHE 2R · LA COMISIÓN ES SOLO DEL POS (usuario, 2026-09-20). La pantalla
   esconde el campo con cualquier otro método, y un campo escondido sigue
   viajando en el formulario: si llega igual, no se guarda. */
$mandar(['destino' => 'oficina', 'comision' => '99.00']);
es('y una comisión mandada a mano con otro método se descarta', 0,
   (int) valor('SELECT comision_centimos FROM pedidos ORDER BY id DESC LIMIT 1'));

grupo('Parche 2j · el cliente pagó anoche, la venta se registra hoy');

/* EL CASO MÁS COMÚN DEL NEGOCIO, y el que rompió el parche: el cliente yapeó
   ayer y el asesor registra la venta esta mañana. Con la fecha del pedido
   clavada en hoy, pago_registrar() rechazaba el pago por ser anterior a su
   pedido — pero el pedido YA estaba guardado, así que quedaba una venta a cero
   que el asesor no podía arreglar desde ninguna pantalla. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$ayer = date('Y-m-d', strtotime('-1 day'));
$pagos_antes = (int) valor('SELECT COUNT(*) FROM pagos');
$mandar(['destino' => 'oficina', 'pago_fecha' => $ayer]);
ok('la venta de un pago de ayer se registra', str_contains($n->cuerpo, 'Pedido registrado'));
ok('y no dice que el pago se quedó fuera',
   !str_contains($n->cuerpo, 'el pago no'), 'el pedido se guardó sin su pago');
es('el pago está', $pagos_antes + 1, (int) valor('SELECT COUNT(*) FROM pagos'));
$p_ayer = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
es('la venta queda fechada el día en que el cliente pagó', $ayer, (string)$p_ayer['fecha']);
es('y el pedido no queda a cero', 1,
   (int) valor('SELECT COUNT(*) FROM pagos WHERE pedido_id = ?', [(int)$p_ayer['id']]));

/* NINGUNA FECHA DE PAGO PUEDE DEJAR UN PEDIDO A CERO. El pedido se guarda
   primero y el pago se anota después, así que cada regla de la fecha que
   pago_registrar() aplique y el formulario no, deja una venta sin dinero que
   NO se puede arreglar desde ninguna pantalla —la ficha aplica la misma regla
   y la fecha del pedido ya no se edita—. Se comprueban las cuatro, y lo que se
   mide no es el texto del error: es que NO NACIÓ NINGÚN PEDIDO. Medir el
   mensaje era lo que dejaba pasar el fallo. */
foreach ([
    ['un pago del futuro',            date('Y-m-d', strtotime('+2 days')), 'no puede ser futura'],
    ['un pago de hace más de un mes', date('Y-m-d', strtotime('-45 days')), 'más de'],
    ['una fecha de pago ilegible',    'ayer por la tarde',                 'no se entiende'],
    /* El 30 de febrero NO existe, y con strtotime() a secas pasaba y valía 2
       de marzo: en MySQL esa columna es DATE, así que el dinero acababa en
       0000-00-00 y no sumaba a ningún mes. */
    ['un 30 de febrero',              date('Y') . '-02-30',                'no se entiende'],
] as [$que, $f_pago, $texto]) {
    $antes_p = (int) valor('SELECT COUNT(*) FROM pedidos');
    $antes_g = (int) valor('SELECT COUNT(*) FROM pagos');
    $mandar(['destino' => 'oficina', 'pago_fecha' => $f_pago]);
    ok("$que rebota", str_contains($n->cuerpo, $texto), mb_substr(strip_tags($n->cuerpo), 0, 200));
    es("y con $que no nace ningún pedido", $antes_p, (int) valor('SELECT COUNT(*) FROM pedidos'));
    es("ni ningún pago", $antes_g, (int) valor('SELECT COUNT(*) FROM pagos'));
}

/* Y lo mismo para quien SÍ elige la fecha del pedido: Administración pone hoy
   y el cliente pagó ayer. Ese era el caso que quedaba suelto, y el único que
   además no tiene arreglo posible después. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$antes_p = (int) valor('SELECT COUNT(*) FROM pedidos');
$mandar(['destino' => 'oficina', 'fecha' => date('Y-m-d'), 'pago_fecha' => $ayer]);
ok('a Administración también se le avisa antes de guardar',
   str_contains($n->cuerpo, 'anterior al pedido'), mb_substr(strip_tags($n->cuerpo), 0, 200));
es('y tampoco nace un pedido a cero', $antes_p, (int) valor('SELECT COUNT(*) FROM pedidos'));
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');

grupo('Parche 2j · el asesor SOLICITA el comprobante, cuando lo sabe');

/* «Tiene que poder avisarse luego, y que aparezca en dos lugares: en el popup
   que sale después de realizar el pedido, y en la página de pedidos, al
   presionar los tres puntitos» (usuario, 2026-09-12). Dejó de ser un campo del
   formulario —el asesor casi nunca lo sabe mientras teclea— y pasó a ser una
   solicitud que manda cuando la tiene. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');

$n->ir('/pedidos/nuevo');
/* Y solicitar desde la ventana devuelve al INICIO: pedir el comprobante es lo
   último que el asesor hace con esa venta (usuario, 2026-09-14). */
$n->ir('/pedidos/nuevo');
ok('el formulario ya NO pregunta por el comprobante',
   !str_contains($n->cuerpo, 'name="comprobante_pide"'),
   'sigue siendo un campo más en una pantalla larga');

$mandar(['destino' => 'oficina']);
ok('la venta se registra sin decir nada del comprobante',
   str_contains($n->cuerpo, 'Pedido registrado'));
$p_sol = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
es('y NO PEDIR NADA es «no necesita»', '', (string)($p_sol['comprobante_pide'] ?? ''));

/* LUGAR 1: la ventana que sale al registrar el pedido. */
$n->ir('/pedidos/ficha?id=' . (int)$p_sol['id'] . '&nuevo=1');
ok('la ventana de «Pedido registrado» lo pregunta ahí mismo',
   str_contains($n->cuerpo, '¿El cliente pide comprobante?'));
ok('con SOLICITAR BOLETA',  str_contains($n->cuerpo, 'SOLICITAR BOLETA'));
ok('y SOLICITAR FACTURA',   str_contains($n->cuerpo, 'SOLICITAR FACTURA'));
ok('y dice que no pulsar nada también es una respuesta',
   str_contains($n->cuerpo, 'no pulses nada'));

/* SE MIRA LA CABECERA Location, no el cuerpo. «Inicio» es el rótulo del menú y
   sale en TODAS las páginas: la comprobación anterior daba verde aunque el
   botón devolviera a la ficha, que es justo lo que se cambió. */
$n->ir('/pedidos/solicitar-comprobante', ['_t'=>$n->testigo(), 'id'=>(int)$p_sol['id'],
                                          'tipo'=>'boleta', 'volver'=>'inicio'], false);
$destino_sol = '';
foreach ($n->cabeceras as $h_sol) {
    if (preg_match('/^Location:\s*(.+)$/i', $h_sol, $m_sol)) $destino_sol = trim($m_sol[1]);
}
ok('desde la ventana, solicitar lleva al Inicio y no a la ficha',
   str_ends_with($destino_sol, '/inicio'),
   'se queda en una ficha que ya no tiene nada que decirle · ' . $destino_sol);
$n->ir('/inicio');
$n->ir('/pedidos/ficha?id=' . (int)$p_sol['id'] . '&nuevo=1');
es('queda solicitada', 'boleta',
   (string) valor('SELECT comprobante_pide FROM pedidos WHERE id = ?', [(int)$p_sol['id']]));
ok('con su firma: quién y cuándo', (int) valor(
   'SELECT COUNT(*) FROM pedidos WHERE id = ? AND comprobante_pide_en IS NOT NULL
      AND comprobante_pide_por IS NOT NULL', [(int)$p_sol['id']]) === 1);
ok('y la ventana lo dice sin sacarte de ella',
   str_contains($n->cuerpo, 'Boleta solicitada'), mb_substr(strip_tags($n->cuerpo), 0, 200));
ok('sin volver a ofrecer los dos botones',
   !str_contains($n->cuerpo, 'SOLICITAR FACTURA'));

/* LUGAR 2: el menú ⋮ de la lista de pedidos, para avisar LUEGO. */
$mandar(['destino' => 'oficina']);
$p_luego = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
$n->ir('/pedidos?q=' . urlencode((string)$p_luego['codigo']));
ok('el menú de la fila ofrece solicitar boleta', str_contains($n->cuerpo, 'Solicitar boleta'));
ok('y solicitar factura',                        str_contains($n->cuerpo, 'Solicitar factura'));

$n->ir('/pedidos/solicitar-comprobante', ['_t'=>$n->testigo(), 'id'=>(int)$p_luego['id'],
                                          'tipo'=>'factura', 'volver'=>'lista']);
es('desde la lista también se pide', 'factura',
   (string) valor('SELECT comprobante_pide FROM pedidos WHERE id = ?', [(int)$p_luego['id']]));
ok('y te deja en la lista, no en otra pantalla',
   str_contains($n->cuerpo, '<h1>Pedidos</h1>'), 'acabó en otra pantalla');

/* Se puede cambiar de idea y se puede retirar. */
$n->ir('/pedidos?q=' . urlencode((string)$p_luego['codigo']));
ok('el menú ya no ofrece la que está pedida',
   !str_contains($n->cuerpo, 'Solicitar factura'));
ok('ofrece cambiar a la otra',  str_contains($n->cuerpo, 'Cambiar a boleta'));
ok('y retirar la solicitud',    str_contains($n->cuerpo, 'Retirar la solicitud'));

$n->ir('/pedidos/solicitar-comprobante', ['_t'=>$n->testigo(), 'id'=>(int)$p_luego['id'],
                                          'tipo'=>'', 'volver'=>'lista']);
es('retirarla la deja como estaba', null,
   valor('SELECT comprobante_pide FROM pedidos WHERE id = ?', [(int)$p_luego['id']]));
es('y borra la firma con ella', null,
   valor('SELECT comprobante_pide_en FROM pedidos WHERE id = ?', [(int)$p_luego['id']]));

/* Y queda en la línea de tiempo del pedido: es lo que se mira cuando alguien
   pregunta «yo pedí factura» tres semanas después. */
$n->ir('/pedidos/ficha?id=' . (int)$p_luego['id']);
ok('la línea de tiempo guarda lo que se pidió',
   str_contains($n->cuerpo, 'Se solicitó Factura'));
ok('y que se retiró',  str_contains($n->cuerpo, 'Se retiró la solicitud'));

/* SI FALLA, «inicio» NO MANDA AL INICIO. El botón devuelve al asesor a
   trabajar porque ya no queda nada que hacer con esa venta; cuando sí queda
   algo —aquí, un tipo que no existe—, salir despedido a la pantalla principal
   con un error rojo y sin vuelta es perderlo. */
$n->ir('/pedidos/solicitar-comprobante', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_luego['id'],
                                          'tipo'=>'marciano', 'volver'=>'inicio'], false);
$destino_mal = '';
foreach ($n->cabeceras as $h_mal) {
    if (preg_match('/^Location:\s*(.+)$/i', $h_mal, $m_mal)) $destino_mal = trim($m_mal[1]);
}
ok('si la solicitud falla, no se le manda al Inicio',
   str_contains($destino_mal, '/pedidos/ficha?id=' . (int)$p_luego['id']),
   'sale despedido con un error rojo y sin vuelta · ' . $destino_mal);

/* Un tipo inventado no entra. */
$n->ir('/pedidos/solicitar-comprobante', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_luego['id'],
                                          'tipo'=>'marciano'], true);
ok('un comprobante inventado se rechaza', str_contains($n->cuerpo, 'boleta o factura'));
es('y no se guarda nada', null,
   valor('SELECT comprobante_pide FROM pedidos WHERE id = ?', [(int)$p_luego['id']]));

/* Y desde la propia ficha, que es donde está mirando el asesor cuando el
   cliente le llama. */
$mandar(['destino' => 'oficina']);
$p_ficha = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
$n->ir('/pedidos/ficha?id=' . (int)$p_ficha['id']);
ok('la ficha del pedido también deja pedirlo', str_contains($n->cuerpo, 'Solicitar boleta'));
$n->ir('/pedidos/solicitar-comprobante', ['_t'=>$n->testigo(), 'id'=>(int)$p_ficha['id'],
                                          'tipo'=>'boleta']);
ok('y se queda en la ficha', str_contains($n->cuerpo, 'Qué ha pasado'));
ok('diciendo lo que pidió y cuándo', str_contains($n->cuerpo, 'Pidió'));

/* Y no es de cualquiera: la venta de otro asesor no se le toca. */
$n->salir();
$n->entrar('otro@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/solicitar-comprobante', ['_t'=>$n->testigoValido(),
                                          'id'=>(int)$p_ficha['id'], 'tipo'=>'factura'], false);
es('pedir por la venta de otro asesor da 403', 403, $n->codigo);
es('y no se movió nada', 'boleta',
   (string) valor('SELECT comprobante_pide FROM pedidos WHERE id = ?', [(int)$p_ficha['id']]));
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');

grupo('Parche 2j · la solicitud manda sobre la ficha del cliente');

/* EL FALLO QUE MÁS CARO SALE de todo este cambio: la cola lleva a
   /pedidos/facturar, y esa pantalla premarcaba lo que dice la FICHA DEL
   CLIENTE ignorando lo que pidió el asesor. A un cliente con RUC que pidió
   boleta se le premarcaba FACTURA, con su razón social lista para copiar: un
   clic y se emite un comprobante que nadie pidió, y eso va a SUNAT. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
/* Un cliente que factura por defecto: es el caso donde la ficha y la solicitud
   se contradicen, que es justo lo que hay que probar. */
$cli_ruc = insertar('clientes', [
    'pais_id' => (int) valor("SELECT id FROM paises WHERE codigo = 'PE'"),
    'asesor_id' => (int) valor("SELECT id FROM usuarios WHERE email = 'asesor@waka.test'"),
    'tipo_doc' => 'RUC', 'documento' => '20512333221',
    'nombre' => 'Empresa', 'apellidos' => 'Que Factura',
    'razon_social' => 'EMPRESA QUE FACTURA S.A.C.', 'ruc_factura' => '20512333221',
    'tipo_comprobante' => 'factura',
    'email' => 'factura@waka.test', 'celular' => '900111222', 'activo' => 1,
]);
ok('hay un cliente que factura por defecto', $cli_ruc > 0);
$mandar(['destino' => 'oficina', 'cliente_id' => $cli_ruc]);
$p_ruc = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
ok('la venta a ese cliente se registra', (int)$p_ruc['cliente_id'] === $cli_ruc,
   mb_substr(preg_replace('/\s+/u',' ',strip_tags($n->cuerpo)), 0, 400));

$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/facturar?id=' . (int)$p_ruc['id']);
ok('sin solicitud, viene marcada la que dice su ficha',
   str_contains($n->cuerpo, 'value="factura" checked'));

/* Y ahora el asesor pide BOLETA para esa misma venta. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/solicitar-comprobante', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_ruc['id'],
                                          'tipo'=>'boleta'], false);
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/facturar?id=' . (int)$p_ruc['id']);
ok('la pantalla de facturar DICE lo que pidió el asesor',
   str_contains($n->cuerpo, 'El asesor pidió'),
   'facturación llega desde la cola y no se entera de lo que se pidió');
ok('y viene marcada la BOLETA, no la de su ficha',
   str_contains($n->cuerpo, 'value="boleta" checked'),
   'un clic emite el comprobante que nadie pidió, y eso va a SUNAT');
ok('sin marcar la factura', !str_contains($n->cuerpo, 'value="factura" checked'));
ok('y se dice de dónde sale la marca', str_contains($n->cuerpo, 'lo pidió el asesor'));
ok('recordando que decide facturación', str_contains($n->cuerpo, 'Decides tú'));

/* Y LA PANTALLA ENTERA DICE LO MISMO. El chip del título, los datos que se
   ofrecen para copiar y el freno del RUC salían de la ficha del cliente, así
   que a este pedido —RUC que pidió boleta— le ponía «[FACTURA]» en letras
   grandes y le servía el RUC y la razón social para pegar. Quien emite campo
   por campo tenía delante los datos del comprobante que nadie pidió. */
ok('el título NO dice FACTURA', !str_contains($n->cuerpo, '>FACTURA</span>'),
   'el chip más grande de la pantalla contradice a la solicitud');
ok('y ofrece los datos de una boleta', str_contains($n->cuerpo, 'Nombre completo'),
   'sigue sirviendo el RUC y la razón social para copiar');
ok('sin la razón social', !str_contains($n->cuerpo, 'Razón social'));

/* Y al revés: DNI al que se le pidió FACTURA. Ahí el freno del RUC es lo único
   que evita que se emita una factura que SUNAT rebota, y no salía nunca. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$mandar(['destino' => 'oficina']);
$p_dni = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
$n->ir('/pedidos/solicitar-comprobante', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_dni['id'],
                                          'tipo'=>'factura'], false);
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/facturar?id=' . (int)$p_dni['id']);
ok('con DNI y factura pedida, viene marcada la factura',
   str_contains($n->cuerpo, 'value="factura" checked'));
ok('y AHÍ SÍ salta el freno del RUC', str_contains($n->cuerpo, 'no tiene RUC'),
   'guardar rebota y el texto que dice qué hacer no aparece en ninguna parte');
ok('diciendo también la salida', str_contains($n->cuerpo, 'va con boleta'));

/* La ventana del pago tiene que decir lo MISMO: son la misma figura. */
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$pg_ruc = (int) valor('SELECT MAX(id) FROM pagos WHERE pedido_id = ? AND verificado = 0 AND anulado = 0',
                      [(int)$p_ruc['id']]);
if ($pg_ruc > 0) {
    $n->ir('/pagos/validar', ['_t'=>$n->testigoValido(), 'id'=>$pg_ruc, 'volver'=>'facturar'], true);
    ok('la ventana del pago marca la misma que la pantalla de facturar',
       str_contains($n->cuerpo, 'EMITIR BOLETA</button>')
       || str_contains($n->cuerpo, 'btn--negro" type="submit">EMITIR BOLETA'),
       'dos pantallas marcando comprobantes distintos del mismo pedido');
} else {
    ok('había un pago por confirmar para probarlo', false, 'no lo hubo');
}

grupo('Parche 2j · la solicitud le llega a facturación');

/* «Que aparezca en dos lugares» tiene un tercero implícito: alguien la tiene
   que RECIBIR. Va en la bandeja donde facturación pasa el día. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/solicitar-comprobante', ['_t'=>$n->testigoValido(),
                                          'id'=>(int)$p_luego['id'], 'tipo'=>'factura'], false);
es('la solicitud queda puesta', 'factura',
   (string) valor('SELECT comprobante_pide FROM pedidos WHERE id = ?', [(int)$p_luego['id']]));
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/por-validar');
ok('la bandeja enseña los comprobantes solicitados',
   str_contains($n->cuerpo, 'comprobante solicitado')
   || str_contains($n->cuerpo, 'comprobantes solicitados'));
$cola_llena = preg_match('/comprobantes? solicitados?(.*?)<\/table>/su', $n->cuerpo, $m1)
              ? $m1[1] : '';
ok('con el pedido que lo pidió DENTRO de esa cola',
   str_contains($cola_llena, (string)$p_luego['codigo']), 'no salió en la cola');
ok('y dice que lo pidió el asesor, no facturación',
   str_contains($n->cuerpo, 'Lo pidió el asesor'));

/* Y SE VACÍA: en cuanto se resuelve, la fila desaparece. Esa es la diferencia
   con el aviso viejo de «X ventas sin comprobante», que nunca bajaba a cero. */
$n->ir('/pedidos/comprobante', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_luego['id'],
                                'tipo'=>'ninguno', 'volver'=>'cola'], false);
$n->ir('/pagos/por-validar');
/* Se mira la COLA, no la página entera: el código del pedido puede salir
   abajo, en la lista de pagos, que es otra cosa. */
$cola = '';
if (preg_match('/comprobantes? solicitados?(.*?)<\/table>/su', $n->cuerpo, $mm)) $cola = $mm[1];
ok('marcada como que no lleva, la fila se va',
   $cola === '' || !str_contains($cola, (string)$p_luego['codigo']),
   'la cola no se vacía y se va a aprender a ignorar');

/* Y ya resuelto, el asesor no puede pedir otra cosa por detrás. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/solicitar-comprobante', ['_t'=>$n->testigoValido(),
                                          'id'=>(int)$p_luego['id'], 'tipo'=>'boleta'], true);
ok('con el comprobante ya resuelto no se puede volver a pedir',
   str_contains($n->cuerpo, 'ya resolvió el comprobante'));
$n->ir('/pedidos?q=' . urlencode((string)$p_luego['codigo']));
ok('y el menú tampoco lo ofrece', !str_contains($n->cuerpo, 'Solicitar boleta'));

grupo('Parche 2j · el 4% del POS lo pone el servidor, no el navegador');

/* La cuenta vivía SOLO en el JavaScript de la pantalla: sin JS, cada venta con
   POS se guardaba con comisión 0 y nadie se enteraba. Estas pruebas mandan el
   POST a pelo —que es exactamente un navegador sin JavaScript— así que si la
   regla vuelve a irse al navegador, aquí salta. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$POS3 = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id=i.lista_id
                      WHERE l.clave='metodos_pago' AND i.valor='POS / tarjeta'");
/* El POS pide voucher, así que va con archivo, como en la pantalla real. */
$con_pos = function (string $comision) use ($n, $base, $POS3, $JPG) {
    $n->ir('/pedidos/nuevo');
    $n->subir('/pedidos/nuevo', array_merge($base, [
        '_t' => $n->testigo(), 'f_id' => $n->oculto('f_id'),
        'destino' => 'oficina', 'pago_metodo_item_id' => $POS3,
        'pago_monto' => '500.00', 'comision' => $comision,
    ]), ['voucher' => ['pos.jpg', 'image/jpeg', $JPG], 'foto_dni' => ['dni.jpg', 'image/jpeg', $JPG]]);   // el POS pide también el DNI (3f)
};
$con_pos('');
ok('la venta con POS se registra', str_contains($n->cuerpo, 'Pedido registrado'),
   mb_substr(strip_tags($n->cuerpo), 0, 200));
es('y el 4% queda anotado sin que nadie lo teclee', 2000,
   (int) valor('SELECT comision_centimos FROM pedidos ORDER BY id DESC LIMIT 1'));

/* Y sigue mandando lo que escriba la persona: el banco cobra lo que cobra. */
$con_pos('17.50');
es('lo tecleado manda sobre el cálculo', 1750,
   (int) valor('SELECT comision_centimos FROM pedidos ORDER BY id DESC LIMIT 1'));

/* En efectivo no hay pasarela que cobre nada. */
$mandar(['destino' => 'oficina', 'comision' => '']);
es('en efectivo la comisión se queda en cero', 0,
   (int) valor('SELECT comision_centimos FROM pedidos ORDER BY id DESC LIMIT 1'));

grupo('Parche 2j · el cliente pagó anoche, la venta se registra hoy');

/* EL CASO MÁS COMÚN DEL NEGOCIO, y el que rompió el parche: el cliente yapeó
   ayer y el asesor registra la venta esta mañana. Con la fecha del pedido
   clavada en hoy, pago_registrar() rechazaba el pago por ser anterior a su
   pedido — pero el pedido YA estaba guardado, así que quedaba una venta a cero
   que el asesor no podía arreglar desde ninguna pantalla. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$ayer = date('Y-m-d', strtotime('-1 day'));
$pagos_antes = (int) valor('SELECT COUNT(*) FROM pagos');
$mandar(['destino' => 'oficina', 'pago_fecha' => $ayer]);
ok('la venta de un pago de ayer se registra', str_contains($n->cuerpo, 'Pedido registrado'));
ok('y no dice que el pago se quedó fuera',
   !str_contains($n->cuerpo, 'el pago no'), 'el pedido se guardó sin su pago');
es('el pago está', $pagos_antes + 1, (int) valor('SELECT COUNT(*) FROM pagos'));
$p_ayer = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
es('la venta queda fechada el día en que el cliente pagó', $ayer, (string)$p_ayer['fecha']);
es('y el pedido no queda a cero', 1,
   (int) valor('SELECT COUNT(*) FROM pagos WHERE pedido_id = ?', [(int)$p_ayer['id']]));

/* NINGUNA FECHA DE PAGO PUEDE DEJAR UN PEDIDO A CERO. El pedido se guarda
   primero y el pago se anota después, así que cada regla de la fecha que
   pago_registrar() aplique y el formulario no, deja una venta sin dinero que
   NO se puede arreglar desde ninguna pantalla —la ficha aplica la misma regla
   y la fecha del pedido ya no se edita—. Se comprueban las cuatro, y lo que se
   mide no es el texto del error: es que NO NACIÓ NINGÚN PEDIDO. Medir el
   mensaje era lo que dejaba pasar el fallo. */
foreach ([
    ['un pago del futuro',            date('Y-m-d', strtotime('+2 days')), 'no puede ser futura'],
    ['un pago de hace más de un mes', date('Y-m-d', strtotime('-45 days')), 'más de'],
    ['una fecha de pago ilegible',    'ayer por la tarde',                 'no se entiende'],
    /* El 30 de febrero NO existe, y con strtotime() a secas pasaba y valía 2
       de marzo: en MySQL esa columna es DATE, así que el dinero acababa en
       0000-00-00 y no sumaba a ningún mes. */
    ['un 30 de febrero',              date('Y') . '-02-30',                'no se entiende'],
] as [$que, $f_pago, $texto]) {
    $antes_p = (int) valor('SELECT COUNT(*) FROM pedidos');
    $antes_g = (int) valor('SELECT COUNT(*) FROM pagos');
    $mandar(['destino' => 'oficina', 'pago_fecha' => $f_pago]);
    ok("$que rebota", str_contains($n->cuerpo, $texto), mb_substr(strip_tags($n->cuerpo), 0, 200));
    es("y con $que no nace ningún pedido", $antes_p, (int) valor('SELECT COUNT(*) FROM pedidos'));
    es("ni ningún pago", $antes_g, (int) valor('SELECT COUNT(*) FROM pagos'));
}

/* Y lo mismo para quien SÍ elige la fecha del pedido: Administración pone hoy
   y el cliente pagó ayer. Ese era el caso que quedaba suelto, y el único que
   además no tiene arreglo posible después. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$antes_p = (int) valor('SELECT COUNT(*) FROM pedidos');
$mandar(['destino' => 'oficina', 'fecha' => date('Y-m-d'), 'pago_fecha' => $ayer]);
ok('a Administración también se le avisa antes de guardar',
   str_contains($n->cuerpo, 'anterior al pedido'), mb_substr(strip_tags($n->cuerpo), 0, 200));
es('y tampoco nace un pedido a cero', $antes_p, (int) valor('SELECT COUNT(*) FROM pedidos'));
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');

grupo('Parche 2j · «esta no lleva» y el comprobante que se corrige');

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');

/* Una boleta mal anotada que se corrige a «no se emite» NO puede quedarse con
   la serie y el número: el reporte la contaría como sin comprobante y la ficha
   enseñaría el número, las dos a la vez. */
$n->ir('/pedidos/comprobante', ['_t'=>$n->testigoValido(), 'id'=>(int)$F['pedidos']['asesor'],
                                'tipo'=>'boleta', 'serie'=>'B001', 'numero'=>'1234'], false);
es('la boleta queda anotada con su número', 'B001',
   (string) valor('SELECT comprobante_serie FROM pedidos WHERE id=?', [(int)$F['pedidos']['asesor']]));
/* SE MANDA LA SERIE Y EL NÚMERO, como los manda el formulario de verdad: sus
   dos campos siguen ahí con el valor viejo dentro cuando se marca «no se
   emite». Sin mandarlos, esta prueba pasaría aunque el borrado no existiera. */
$n->ir('/pedidos/comprobante', ['_t'=>$n->testigoValido(), 'id'=>(int)$F['pedidos']['asesor'],
                                'tipo'=>'ninguno', 'serie'=>'B001', 'numero'=>'1234'], false);
es('al corregir a «no se emite» se borra la serie', null,
   valor('SELECT comprobante_serie FROM pedidos WHERE id=?', [(int)$F['pedidos']['asesor']]));
es('y el número', null,
   valor('SELECT comprobante_numero FROM pedidos WHERE id=?', [(int)$F['pedidos']['asesor']]));

grupo('Parche 2j · una emisión que falla no pierde la venta');

/* EL CASO QUE EL PARCHE ESTUVO A PUNTO DE DEJAR SUELTO: el asesor indicó
   «factura», el cliente es persona natural sin RUC, la ventana pinta EMITIR
   FACTURA en negro porque es lo que él indicó, y pedido_comprobante() lo
   rechaza. Antes se volvía igual a la bandeja: el pago ya estaba confirmado,
   la venta se quedaba sin comprobante y el aviso que la habría recogido se
   quitó el mismo día. */
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$sin_ruc = (int) valor("SELECT c.id FROM clientes c JOIN pedidos p ON p.cliente_id = c.id
                         WHERE COALESCE(c.ruc_factura,'') = '' AND c.tipo_doc <> 'RUC'
                         ORDER BY p.id DESC LIMIT 1");
$ped_sin_ruc = (int) valor('SELECT id FROM pedidos WHERE cliente_id = ? ORDER BY id DESC LIMIT 1',
                           [$sin_ruc]);
ok('hay una venta de un cliente sin RUC', $ped_sin_ruc > 0);
$n->ir('/pedidos/comprobante', ['_t'=>$n->testigoValido(), 'id'=>$ped_sin_ruc,
                                'tipo'=>'factura', 'volver'=>'cola'], true);
ok('se dice por qué no se pudo', str_contains($n->cuerpo, 'no tiene RUC'));
ok('y la ventana vuelve a estar delante',
   str_contains($n->cuerpo, 'EMITIR BOLETA'),
   'la venta se quedó sin comprobante y sin nada que la reclamara');
es('sin haber anotado nada', '',
   (string) valor("SELECT COALESCE(comprobante_tipo,'') FROM pedidos WHERE id = ?", [$ped_sin_ruc]));

grupo('Parche 2j · el WhatsApp de facturación se puede poner desde el HUB');

/* «El botón de hablar con facturación dentro del pedido debe redirigir a wtsp.
   Número editable» (usuario, 2026-09-11). Nacía vacío y NO había pantalla para
   escribirlo: el botón no se pintaba nunca y media banda de acción del pedido
   denegado era código muerto. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion');
ok('Configuración ofrece Contactos', str_contains($n->cuerpo, 'Contactos'));
$n->ir('/configuracion/contactos');
es('la pantalla abre', 200, $n->codigo);

$n->ir('/configuracion/contactos', ['_t'=>$n->testigo(),
                                    'whatsapp_facturacion'=>'+51 987 654 321']);
es('el número se guarda solo con dígitos', '51987654321',
   (string) valor("SELECT valor FROM ajustes WHERE clave = 'whatsapp_facturacion'"));

/* Y desde ese momento el botón aparece en el pedido trabado. */
$trab = (int) valor('SELECT pedido_id FROM pagos WHERE denegado = 1 ORDER BY id DESC LIMIT 1');
if ($trab > 0) {
    $n->ir('/pedidos/ficha?id=' . $trab);
    ok('el pedido trabado ya ofrece hablar con facturación',
       str_contains($n->cuerpo, 'wa.me/51987654321'), 'el botón sigue sin salir');
} else {
    ok('había un pedido con pago denegado para probarlo', false, 'no lo hubo');
}

/* Vaciarlo apaga el botón, que es una decisión válida. */
$n->ir('/configuracion/contactos');
$n->ir('/configuracion/contactos', ['_t'=>$n->testigo(), 'whatsapp_facturacion'=>'']);
es('vaciarlo lo deja vacío', '',
   (string) valor("SELECT valor FROM ajustes WHERE clave = 'whatsapp_facturacion'"));
if ($trab > 0) {
    $n->ir('/pedidos/ficha?id=' . $trab);
    ok('y el botón deja de pintarse', !str_contains($n->cuerpo, 'wa.me/51987654321'));
}

/* Un número imposible se rechaza en vez de guardarse y apagar el botón sin
   decir por qué. */
$n->ir('/configuracion/contactos');
$n->ir('/configuracion/contactos', ['_t'=>$n->testigo(), 'whatsapp_facturacion'=>'123']);
ok('un número imposible se rechaza', str_contains($n->cuerpo, 'no parece un WhatsApp'));

/* Y no es de cualquiera. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion/contactos', null, false);
es('el asesor no entra (403)', 403, $n->codigo);
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');

grupo('Parche 2j · lo que cada rol lee en la ficha');

/* La ficha decía «Este pedido está anulado» a Facturación y a Dirección sobre
   pedidos VIVOS: el mensaje estaba en el `else` de «puede despachar», y ese
   permiso no lo tienen. Y lo leían dentro de la misma tarjeta donde ven
   «Comprobante: todavía no · lo anota facturación». */
/* Uno vivo y CON SALDO: hace falta poder registrarle un pago de Yape para
   comprobar lo del N.º de operación. */
$vivo_id = (int) valor("SELECT p.id FROM pedidos p JOIN pedido_estados e ON e.id=p.estado_id
                         WHERE e.clave <> 'anulado'
                           AND (p.total_centimos - p.cobrado_centimos) > 200
                         ORDER BY p.id DESC LIMIT 1");
ok('hay un pedido vivo con saldo', $vivo_id > 0);
foreach (['factu@waka.test', 'ceo@waka.test', 'admin@waka.test'] as $quien) {
    $n->salir();
    $n->entrar($quien, 'Clave-Larga-1');
    $n->ir('/pedidos/ficha?id=' . $vivo_id);
    ok("a $quien la ficha NO le dice que un pedido vivo está anulado",
       !str_contains($n->cuerpo, 'Este pedido está anulado'),
       'lo lee en la misma tarjeta del comprobante');
}

/* Y el N.º DE OPERACIÓN: la pantalla de facturar decía «Opcional, pero es lo
   que permite cuadrar después» y pago_validar() lo EXIGE en seis de los siete
   métodos. Facturación pulsaba CONFIRMAR y le rebotaba. */
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$ped_yape = (int) valor("SELECT pg.pedido_id FROM pagos pg
                           JOIN lista_items i ON i.id = pg.metodo_item_id
                          WHERE pg.verificado = 0 AND pg.anulado = 0
                            AND i.pide_comprobante = 1
                          ORDER BY pg.id DESC LIMIT 1");
ok('hay un pago esperando de un método que pide voucher', $ped_yape > 0);
if ($ped_yape > 0) {
    $n->ir('/pedidos/facturar?id=' . $ped_yape);
    ok('facturar no promete que el N.º de operación es opcional',
       !str_contains($n->cuerpo, 'Opcional, pero es lo que permite cuadrar'),
       'la pantalla dice opcional y pago_validar() lo rechaza');
    ok('y dice que hace falta', str_contains($n->cuerpo, 'Hace falta para confirmar este pago'));
}

grupo('Parche 2j · quién acumula denegados');

$n->ir('/reportes/pagos?e=denegados&desde=2000-01-01&hasta=' . date('Y-m-d'));
es('el reporte tiene el filtro de solo denegados', 200, $n->codigo);
ok('y enseña quién los acumula', str_contains($n->cuerpo, 'Denegados por asesor'),
   'sin esa tarjeta el filtro solo lista pagos y no contesta la pregunta');
ok('diciendo de cuántos son',   str_contains($n->cuerpo, 'De cuántos'));
ok('sin llamarlo una falta',    str_contains($n->cuerpo, 'no es una falta'));
/* Lo que el asesor QUITÓ él mismo no puede contarse como denegado: es la
   distinción entera por la que este filtro existe aparte de «rechazados». */
ok('y no cuenta lo que el propio asesor quitó',
   !str_contains($n->cuerpo, 'El cliente mandó un voucher falso'),
   'un pago quitado por su dueño se está colando como denegado por facturación');

$n->ir('/reportes/pagos?e=rechazados&desde=2000-01-01&hasta=' . date('Y-m-d'));
ok('y «denegados y quitados» los sigue enseñando juntos',
   str_contains($n->cuerpo, 'El cliente mandó un voucher falso'));
ok('sin la tarjeta de por asesor, que es de la otra lista',
   !str_contains($n->cuerpo, 'Denegados por asesor'));

grupo('Parche 2k · el formulario no nace medio lleno');

/* «En móvil, al crear un nuevo pedido, ya aparece como si hubiese llenado los
   2 primeros campos» (usuario, 2026-09-14). Los pasos 1 y 2 tienen respuesta
   por defecto —«Entrega inmediata» y «Lima»—, así que se pintaban con su ✓ y
   su valor desde el primer render. El ✓ tiene que significar «esto lo
   contestaste TÚ». */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/nuevo');
ok('un formulario en blanco no marca ningún paso como hecho',
   str_contains($n->cuerpo, 'MAX_TOCADO = 0'),
   'los pasos 1 y 2 salen con ✓ sin que el asesor haya tocado nada');

/* Y cuando vuelve con errores sí: todo lo que mandó lo llenó de verdad. */
$n->ir('/pedidos/nuevo', ['_t' => $n->testigo(), 'destino' => 'oficina']);
ok('al volver con errores, lo que mandó cuenta como suyo',
   str_contains($n->cuerpo, 'MAX_TOCADO = 9'));

/* Y el texto de recojo en oficina, sin la coletilla que sobraba. */
ok('recoger en oficina lo dice y para',
   str_contains($n->cuerpo, 'no hay nada que llenar aquí'));
ok('sin explicar de más',
   !str_contains($n->cuerpo, 'ya sabe en qué oficina'),
   'el que lo lee es el asesor, no nosotros');

grupo('Parche 2k · reclamar que confirmen una venta');

/* «Tiene que haber un botón de reenviar solicitud, pero que se active después
   de X tiempo, con un contador» (usuario, 2026-09-14). Cinco minutos, editable:
   con uno, facturación recibe reclamos de ventas que estaba atendiendo.

   TODAS LAS AFIRMACIONES DE ABAJO MIDEN LA BASE O LA FILA, no la página
   entera. Las primeras las medían con str_contains sobre todo el cuerpo y
   eran vacuas: «Todavía no» está en el rótulo fijo «Todavía no salen…» de esa
   misma pantalla, y «Reclamar» está dentro del JavaScript que se sirve
   siempre — las dos daban verde con la comprobación de minutos desactivada
   (auditoría de pruebas, 2026-09-14). */

/** El botón de reclamar de la fila de ESE pedido, o '' si no se pinta. */
$boton_reclamar = function (string $cuerpo, int $pedido_id): string {
    if (!preg_match_all('#<form[^>]*/pedidos/reclamar.*?</form>#su', $cuerpo, $m)) return '';
    foreach ($m[0] as $f) {
        if (preg_match('/name="id" value="' . $pedido_id . '"/', $f)) return $f;
    }
    return '';
};
/** Las veces reclamadas, leídas de la base. */
$veces = fn(int $id) => (int) valor('SELECT reclamo_veces FROM pedidos WHERE id = ?', [$id]);

/* El número de facturación nace VACÍO en las semillas, y con él vacío el
   botón de WhatsApp no se pinta a propósito (uno que abre un chat con nadie es
   peor que no tenerlo). Se pone aquí para poder probar el botón. */
q("UPDATE ajustes SET valor = '51987654321' WHERE clave = 'whatsapp_facturacion'");

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$id_asesor_rec = (int) valor('SELECT id FROM usuarios WHERE email = ?', ['asesor@waka.test']);
$mandar(['destino' => 'oficina']);
$p_rec = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');

$n->ir('/pedidos/por-despachar');
ok('la venta sale como esperando que confirmen',
   str_contains($n->cuerpo, (string)$p_rec['codigo']));
$b = $boton_reclamar($n->cuerpo, (int)$p_rec['id']);
ok('con SU botón, y apagado',
   $b !== '' && str_contains($b, 'disabled') && str_contains($b, 'Reenviar en'),
   'el botón nace encendido: facturación recibiría el reclamo al instante · ' . $b);
/* El contador arranca en los minutos que toca y no en cero: con `faltan = 0`
   el JavaScript encendía el botón en el primer tick, en el mismo instante de
   cargar la página. */
ok('y con la cuenta atrás de verdad, no en cero',
   (bool) preg_match('/data-faltan="(\d+)"/', $b, $m_f) && (int)$m_f[1] > 60,
   'la cuenta atrás nace en 0 y el navegador enciende el botón al instante');

/* Y el servidor NO se fía del contador del navegador. */
$n->ir('/pedidos/reclamar', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_rec['id']], true);
es('reclamar antes de tiempo no queda anotado', 0, $veces((int)$p_rec['id']));
ok('y se dice por qué', str_contains($n->cuerpo, 'Facturación necesita un rato'),
   'un contador de JavaScript lo para cualquiera con la consola abierta');

/* Se envejece el pago para que el reloj ya haya pasado. */
q("UPDATE pagos SET creado_en = ? WHERE pedido_id = ?",
  [date('Y-m-d H:i:s', strtotime('-30 minutes')), (int)$p_rec['id']]);
$n->ir('/pedidos/por-despachar');
$b = $boton_reclamar($n->cuerpo, (int)$p_rec['id']);
ok('pasados los minutos, SU botón se enciende',
   $b !== '' && !str_contains($b, 'disabled'), 'sigue apagado · ' . $b);

$marca_rec = (int) valor('SELECT COALESCE(MAX(id), 0) FROM bitacora');
$n->ir('/pedidos/reclamar', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_rec['id']], true);
es('queda anotado', 1, $veces((int)$p_rec['id']));
es('y con QUIÉN: el que pulsó, no cualquiera', $id_asesor_rec,
   (int) valor('SELECT reclamo_por FROM pedidos WHERE id = ?', [(int)$p_rec['id']]));
ok('y cuándo', (string) valor('SELECT reclamo_en FROM pedidos WHERE id = ?',
   [(int)$p_rec['id']]) !== '');
ok('y se dice que quedó anotado', str_contains($n->cuerpo, 'Reclamo anotado'));

/* LA CONSTANCIA. «Deja constancia en el HUB» dice el controlador: si no queda
   escrito, dentro de un mes nadie puede decir quién hizo esperar a quién. */
ok('la línea de tiempo del pedido lo recoge',
   (int) valor("SELECT COUNT(*) FROM pedido_eventos
                 WHERE pedido_id = ? AND texto LIKE '%reclam%'",
               [(int)$p_rec['id']]) >= 1,
   'el reclamo no deja rastro en el pedido');
ok('y la bitácora también',
   (int) valor("SELECT COUNT(*) FROM bitacora WHERE accion = 'pedido.reclamo'") >= 1);

/* EL WHATSAPP, que es lo que de verdad le llega a facturación: el reclamo
   dentro del HUB solo lo ve si tiene el HUB abierto. */
ok('y se ofrece avisar por WhatsApp, con el mensaje escrito',
   str_contains($n->cuerpo, 'wa.me') && str_contains($n->cuerpo, 'AVISAR POR WHATSAPP'),
   'el entregable del parche no se pinta');

/* 3b.5 · EL WHATSAPP VA ARRIBA DEL TODO: iba debajo de la lista de ventas
   listas y el asesor no lo veía. */
$pos_wa = strpos($n->cuerpo, 'id="aviso-reclamo-wa"');
$pos_resto = array_filter([strpos($n->cuerpo, 'No tienes nada esperando salir'),
                           strpos($n->cuerpo, 'lista para mandar'), strpos($n->cuerpo, 'listas para mandar'),
                           strpos($n->cuerpo, '<table')], fn($x) => $x !== false);
ok('EL BOTÓN DE WHATSAPP SALE ARRIBA, antes que la lista', $pos_wa !== false && $pos_resto && $pos_wa < min($pos_resto),
   'wa ' . var_export($pos_wa, true) . ' · resto ' . json_encode(array_values($pos_resto)));
ok('y el aviso del reclamo lleva el botón dentro',
   (bool) preg_match('~id="aviso-reclamo-wa".*?AVISAR POR WHATSAPP.*?</div>~s', $n->cuerpo));
ok('el asesor no lleva el sondeo de reclamos', !str_contains($n->cuerpo, 'data-reclamos-ultimo'));

/* 3b.5 · EL RECLAMO SUENA a Facturación: viaja en su mismo sondeo. */
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('facturación lleva la marca de los reclamos en la página', (bool) preg_match('~data-reclamos-ultimo="\d+"~', $n->cuerpo));
/* Otra línea de la bitácora del mismo pedido no es un reclamo. */
q("INSERT INTO bitacora (usuario_id, accion, entidad, entidad_id) VALUES (NULL, 'pedido.editar', 'pedido', ?)", [(int)$p_rec['id']]);
$n->ir('/pagos/nuevos?desde=0&rdesde=' . $marca_rec);
$jr = json_decode($n->cuerpo, true);
es('AL RECLAMAR, A FACTURACIÓN LE LLEGA EL AVISO', 1, (int)($jr['reclamos'] ?? -1));
es('con el código de la venta', (string)$p_rec['codigo'], (string)($jr['reclamo_codigo'] ?? ''));
ok('y quién la reclama', (string)($jr['reclamo_asesor'] ?? '') !== '', $n->cuerpo);
ok('con la marca nueva', (int)($jr['reclamo_ultimo'] ?? 0) > $marca_rec);
$n->ir('/pagos/nuevos?desde=0&rdesde=' . (int)($jr['reclamo_ultimo'] ?? 0));
es('desde la marca nueva, ya no avisa otra vez', 0, (int)(json_decode($n->cuerpo, true)['reclamos'] ?? -1));
$n->ir('/pagos/nuevos?desde=0');
es('sin marca (una página vieja), no avisa de toda la historia', 0, (int)(json_decode($n->cuerpo, true)['reclamos'] ?? -1));
$n->salir();
$n->entrar('factumx@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/nuevos?desde=0&rdesde=' . $marca_rec);
es('a facturación de OTRO país no le suena', 0, (int)(json_decode($n->cuerpo, true)['reclamos'] ?? -1));
$n->salir();
$n->entrar('ceo@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/nuevos?desde=0&rdesde=' . $marca_rec);
es('ni a Dirección, que confirma pero no recibe avisos', 0, (int)(json_decode($n->cuerpo, true)['reclamos'] ?? -1));
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
/* Si facturación ya confirmó ese pago, el reclamo ya no suena. */
q('UPDATE pagos SET verificado = 1 WHERE pedido_id = ?', [(int)$p_rec['id']]);
$n->ir('/pagos/nuevos?desde=0&rdesde=' . $marca_rec);
es('un reclamo de un pago ya confirmado no suena', 0, (int)(json_decode($n->cuerpo, true)['reclamos'] ?? -1));
q('UPDATE pagos SET verificado = 0 WHERE pedido_id = ?', [(int)$p_rec['id']]);
q('UPDATE pagos SET en_espera = 1 WHERE pedido_id = ?', [(int)$p_rec['id']]);
$n->ir('/pagos/nuevos?desde=0&rdesde=' . $marca_rec);
es('ni uno que facturación ya dejó en espera', 0, (int)(json_decode($n->cuerpo, true)['reclamos'] ?? -1));
q('UPDATE pagos SET en_espera = 0 WHERE pedido_id = ?', [(int)$p_rec['id']]);
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');

/* Y el segundo reclamo no se puede mandar pegado al primero: el reloj vuelve
   a correr desde el último reclamo, no desde el pago. */
$n->ir('/pedidos/reclamar', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_rec['id']], true);
es('el segundo reclamo espera su turno y no se cuenta dos veces', 1, $veces((int)$p_rec['id']));

/* LOS MINUTOS SON EDITABLES, que es la condición de siempre en este HUB: sin
   esto, el ajuste podía no existir y nadie se enteraba. */
$min_antes = (string) valor("SELECT valor FROM ajustes WHERE clave = 'reclamo_minutos'");
ok('los minutos viven en un ajuste, no en el código', $min_antes !== '');
q("UPDATE ajustes SET valor = '600' WHERE clave = 'reclamo_minutos'");
$mandar(['destino' => 'oficina']);
$p_min = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
q("UPDATE pagos SET creado_en = ? WHERE pedido_id = ?",
  [date('Y-m-d H:i:s', strtotime('-30 minutes')), (int)$p_min['id']]);
$n->ir('/pedidos/reclamar', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_min['id']], true);
es('y subirlos a 600 frena un reclamo que con 5 pasaba', 0, $veces((int)$p_min['id']));
q("UPDATE ajustes SET valor = ? WHERE clave = 'reclamo_minutos'", [$min_antes]);

/* EL RELOJ CORRE DESDE EL PAGO MÁS VIEJO SIN REVISAR, no desde el último.
   El reclamo dice «mi venta lleva parada media hora»: con el más nuevo,
   registrar el saldo de una pre venta le quitaba al asesor el derecho a
   reclamar el adelanto que llevaba una hora esperando. */
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$mandar(['destino' => 'oficina', 'l_precio' => ['5000'],
         'modo_pago' => 'adelanto', 'pago_monto' => '500.00']);
$p_reloj = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
q("UPDATE pagos SET creado_en = ? WHERE pedido_id = ?",
  [date('Y-m-d H:i:s', strtotime('-60 minutes')), (int)$p_reloj['id']]);
$n->ir('/pedidos/ficha?id=' . (int)$p_reloj['id']);
$n->subir('/pagos/registrar', ['concepto'=>'Saldo de prueba', '_t'=>$n->testigoValido(), 'pedido_id'=>(int)$p_reloj['id'],
                               'monto'=>'300.00', 'metodo_item_id'=>(int)$F['efectivo'],
                               'fecha'=>date('Y-m-d')], [], false);
ok('entra un pago nuevo sobre uno que lleva una hora esperando',
   (int) valor('SELECT COUNT(*) FROM pagos WHERE pedido_id = ?', [(int)$p_reloj['id']]) === 2);
$n->ir('/pedidos/reclamar', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_reloj['id']], true);
es('y el pago nuevo NO le reinicia el reloj al viejo', 1, $veces((int)$p_reloj['id']));

grupo('Parche 2k · lo que NO se puede reclamar');

/* UNA SOLA DEFINICIÓN de por qué una venta no sale. El reclamo se escribió su
   propia lectura del estado del pago y decía «esperando a facturación»
   también cuando el voucher había sido DENEGADO: la venta se quedaba para
   siempre bajo ese cartel mientras el asesor esperaba una respuesta que ya
   había llegado (auditoría, 2026-09-14). */
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$mandar(['destino' => 'oficina']);
$p_den = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
$pg_den = (int) valor('SELECT MAX(id) FROM pagos WHERE pedido_id = ?', [(int)$p_den['id']]);

$n->salir(); $n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/denegar', ['_t'=>$n->testigoValido(), 'id'=>$pg_den,
                          'motivo'=>'El voucher no cuadra'], true);
es('facturación deniega el pago', 1,
   (int) valor('SELECT anulado FROM pagos WHERE id = ?', [$pg_den]));

$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
q("UPDATE pagos SET creado_en = ? WHERE pedido_id = ?",
  [date('Y-m-d H:i:s', strtotime('-30 minutes')), (int)$p_den['id']]);
$n->ir('/pedidos/por-despachar');
ok('el pago denegado se dice POR SU NOMBRE, no como «esperando a facturación»',
   str_contains($n->cuerpo, 'Pago denegado'),
   'el asesor espera una respuesta que ya llegó');
es('y no se le ofrece reclamar: lo que toca es registrar el pago bueno',
   '', $boton_reclamar($n->cuerpo, (int)$p_den['id']));
$n->ir('/pedidos/reclamar', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_den['id']], true);
es('y el servidor tampoco lo deja', 0, $veces((int)$p_den['id']));

/* EN ESPERA: facturación YA lo miró y está esperando al banco. Reclamar es
   meterle prisa por algo que no depende de ella, cada cinco minutos. */
$mandar(['destino' => 'oficina']);
$p_esp = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
$pg_esp = (int) valor('SELECT MAX(id) FROM pagos WHERE pedido_id = ?', [(int)$p_esp['id']]);
$n->salir(); $n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/espera', ['_t'=>$n->testigoValido(), 'id'=>$pg_esp,
                         'nota'=>'No aparece en el banco'], true);
es('facturación lo pone en espera', 1,
   (int) valor('SELECT en_espera FROM pagos WHERE id = ?', [$pg_esp]));
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
q("UPDATE pagos SET creado_en = ? WHERE pedido_id = ?",
  [date('Y-m-d H:i:s', strtotime('-30 minutes')), (int)$p_esp['id']]);
$n->ir('/pedidos/por-despachar');
ok('lo que está en revisión se dice así', str_contains($n->cuerpo, 'Revisando el banco'));
es('y tampoco se reclama', '', $boton_reclamar($n->cuerpo, (int)$p_esp['id']));
$n->ir('/pedidos/reclamar', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_esp['id']], true);
es('ni por la puerta de atrás', 0, $veces((int)$p_esp['id']));

/* Y LO YA CONFIRMADO: no hay nada que pedir. */
$mandar(['destino' => 'oficina']);
$p_ok = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
$pg_ok = (int) valor('SELECT MAX(id) FROM pagos WHERE pedido_id = ?', [(int)$p_ok['id']]);
$n->salir(); $n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/validar', ['_t'=>$n->testigoValido(), 'id'=>$pg_ok], true);
es('facturación lo confirma', 1,
   (int) valor('SELECT verificado FROM pagos WHERE id = ?', [$pg_ok]));
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
q("UPDATE pagos SET creado_en = ? WHERE pedido_id = ?",
  [date('Y-m-d H:i:s', strtotime('-30 minutes')), (int)$p_ok['id']]);
$n->ir('/pedidos/reclamar', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_ok['id']], true);
es('lo ya confirmado no se reclama', 0, $veces((int)$p_ok['id']));

/* UN PEDIDO ANULADO tampoco, aunque le quede un cobro colgando. */
$mandar(['destino' => 'oficina']);
$p_anu = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
q("UPDATE pagos SET creado_en = ? WHERE pedido_id = ?",
  [date('Y-m-d H:i:s', strtotime('-30 minutes')), (int)$p_anu['id']]);
$motivo_anu = (int) valor("SELECT li.id FROM lista_items li
                            JOIN listas l ON l.id = li.lista_id
                           WHERE l.clave = 'motivos_anulacion' ORDER BY li.id LIMIT 1");
ok('hay un motivo de anulación en la lista', $motivo_anu > 0);
$n->ir('/pedidos/ficha?id=' . (int)$p_anu['id']);
$n->ir('/pedidos/anular', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_anu['id'],
                           'motivo_item_id'=>$motivo_anu,
                           'nota'=>'El cliente se arrepintió'], true);
ok('la venta queda anulada',
   valor('SELECT anulado_en FROM pedidos WHERE id = ?', [(int)$p_anu['id']]) !== null);
/* El cobro se reabre a mano: sin esto la guarda de anulado no se prueba,
   porque anular ya apaga los pagos y la venta sale por otra puerta. */
q("UPDATE pagos SET anulado = 0, verificado = 0 WHERE pedido_id = ?", [(int)$p_anu['id']]);
$n->ir('/pedidos/reclamar', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_anu['id']], true);
es('una venta anulada no se reclama', 0, $veces((int)$p_anu['id']));

grupo('Parche 2k · el reclamo sube el pago, y solo el pago que habla');

/* LO QUE DE VERDAD LE LLEGA A FACTURACIÓN: la fila sube en su bandeja y lo
   dice. Sin el chip, subir se lee como un orden caprichoso.
   Se registra ANTES otra venta más nueva: si no, la reclamada sería la última
   en entrar y quedaría arriba de todas formas — la prueba pasaría en verde sin
   el orden que dice medir. */
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$mandar(['destino' => 'oficina']);
$p_nueva = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
ok('hay una venta MÁS NUEVA que la reclamada',
   (int)$p_nueva['id'] > (int)$p_rec['id']);

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/por-validar');
ok('la bandeja marca el pago reclamado', str_contains($n->cuerpo, 'Reclamado'));
/* Se mira DENTRO de la tabla de «Sin revisar»: el código del pedido sale
   también arriba, en la cola de comprobantes, y compararlo contra la página
   entera medía otra cosa. */
$tabla_sr = '';
if (preg_match('/Sin revisar(.*?)<\/table>/su', $n->cuerpo, $m_sr)) $tabla_sr = $m_sr[1];
$otro = (string)$p_nueva['codigo'];
ok('hay otra venta sin reclamar con la que comparar', $otro !== '' && $tabla_sr !== '');
if ($otro !== '' && $tabla_sr !== '') {
    $pos_rec  = mb_strpos($tabla_sr, (string)$p_rec['codigo']);
    $pos_otro = mb_strpos($tabla_sr, $otro);
    ok('y lo reclamado sube por encima de lo demás',
       $pos_rec !== false && $pos_otro !== false && $pos_rec < $pos_otro,
       'queda abajo y sigue parado · reclamado en ' . var_export($pos_rec, true)
       . ' y ' . $otro . ' en ' . var_export($pos_otro, true));
}

/* EL CHIP ES DEL PAGO, NO DEL PEDIDO. El reclamo se anota en el pedido, pero
   la bandeja es por pago: sin distinguirlo, el adelanto reclamado en marzo
   dejaba marcado «Reclamado» —y arriba del todo— al saldo que entra en junio,
   que nadie ha reclamado nunca. Con el tiempo las ventas reclamadas alguna vez
   copaban la cabecera y el distintivo rojo dejaba de significar nada.

   Hace falta una PRE VENTA: en una venta pagada entera no cabe un pago más, y
   con el segundo pago rechazado la prueba se quedaba mirando el primero —que
   sí está reclamado— y medía lo contrario de lo que dice medir. */
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$mandar(['destino' => 'oficina', 'l_precio' => ['5000'],
         'modo_pago' => 'adelanto', 'pago_monto' => '500.00']);
$p_chip = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
$pg_uno = (int) valor('SELECT MAX(id) FROM pagos WHERE pedido_id = ?', [(int)$p_chip['id']]);
ok('la pre venta queda con saldo por cobrar',
   (int)$p_chip['cobrado_centimos'] < (int)$p_chip['total_centimos']);

q("UPDATE pagos SET creado_en = ? WHERE pedido_id = ?",
  [date('Y-m-d H:i:s', strtotime('-30 minutes')), (int)$p_chip['id']]);
$n->ir('/pedidos/reclamar', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_chip['id']], true);
es('se reclama el adelanto', 1, $veces((int)$p_chip['id']));

/* Y ahora entra el saldo, DESPUÉS del reclamo. */
$n->ir('/pedidos/ficha?id=' . (int)$p_chip['id']);
$n->subir('/pagos/registrar', ['concepto'=>'Saldo de prueba', '_t'=>$n->testigoValido(), 'pedido_id'=>(int)$p_chip['id'],
                               'monto'=>'300.00', 'metodo_item_id'=>(int)$F['efectivo'],
                               'fecha'=>date('Y-m-d')], [], false);
$pg_tarde = (int) valor('SELECT MAX(id) FROM pagos WHERE pedido_id = ?', [(int)$p_chip['id']]);
ok('entra un pago DESPUÉS del reclamo, y es otro',
   $pg_tarde > 0 && $pg_tarde !== $pg_uno,
   'el segundo pago no se registró: la prueba mediría el primero');

$n->salir(); $n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/por-validar');
if (preg_match_all('#<tr>.*?</tr>#su', $n->cuerpo, $m_tr)) {
    $fila_tarde = ''; $fila_uno = '';
    foreach ($m_tr[0] as $tr) {
        if (!str_contains($tr, '/pedidos/facturar?id=' . (int)$p_chip['id'])) continue;
        if (str_contains($tr, 'value="' . $pg_tarde . '"')) $fila_tarde = $tr;
        if (str_contains($tr, 'value="' . $pg_uno . '"'))   $fila_uno   = $tr;
    }
    ok('se encuentran las dos filas en la bandeja',
       $fila_tarde !== '' && $fila_uno !== '');
    ok('el adelanto reclamado sí lleva su marca',
       $fila_uno !== '' && str_contains($fila_uno, 'Reclamado'),
       'el reclamo no se ve donde tiene que verse');
    ok('y el saldo que entra después NO nace marcado',
       $fila_tarde !== '' && !str_contains($fila_tarde, 'Reclamado'),
       'el reclamo viejo mancha los pagos que entran después');
}

grupo('Parche 2k · de quién es el reclamo');

/* No es de cualquiera: por la venta de otro asesor no se reclama. El reloj de
   esa venta YA pasó, así que el 403 solo puede venir del permiso — antes la
   prueba no distinguía «me lo frenó el permiso» de «me lo frenó el reloj». */
$n->salir();
$n->entrar('otro@waka.test', 'Clave-Larga-1');
$veces_antes = $veces((int)$p_rec['id']);
$n->ir('/pedidos/reclamar', ['_t'=>$n->testigoValido(), 'id'=>(int)$p_rec['id']], false);
es('reclamar por la venta de otro da 403', 403, $n->codigo);
es('y no queda anotado', $veces_antes, $veces((int)$p_rec['id']));

/* Y el que solo mira —el líder, que ve las ventas de su equipo— no ve el
   botón: pulsarlo le daba un 403 a pantalla completa y perdía la lista. */
$n->salir();
$n->entrar('lider@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/por-despachar');
if ($n->codigo === 200) {
    ok('al que solo mira no se le pinta el botón de reclamar',
       $boton_reclamar($n->cuerpo, (int)$p_rec['id']) === '',
       'le sale un botón que le va a dar 403 a pantalla completa');
}

grupo('Parche 2n · el aviso de «listas para despacho» en la lista de pedidos');

/* «En la página de pedidos, escritorio y móvil, debemos tener un indicador con
   botón» (usuario, 2026-09-14). El asesor vive en esta pantalla: cuando
   facturación le confirma un pago, la venta queda libre y hasta ahora nadie se
   lo decía donde estaba mirando.

   TODAS LAS AFIRMACIONES MIRAN DENTRO DE LA BANDA, no la página entera. El
   menú lateral se pinta en todas las pantallas, así que buscar '/clientes' o
   'por-despachar' en el cuerpo acertaba SIEMPRE: tres de estas pruebas daban
   verde con el aviso desactivado y con el botón apuntando a /inicio (auditoría
   de pruebas, 2026-09-14). */

/** La banda de «listas para despacho» de la página, o '' si no se pinta. */
$banda = function (string $cuerpo): string {
    return preg_match('#<div class="aviso aviso--verde aviso--conbtn".*?</div>#su', $cuerpo, $m)
        ? $m[0] : '';
};

$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/por-despachar');
$listas = (int) preg_match_all('#class="form-despacho"#', $n->cuerpo);
ok('el asesor tiene ventas listas para mandar', $listas > 0);

$n->ir('/pedidos');
$b = $banda($n->cuerpo);
ok('la banda sale en la lista de pedidos', $b !== '',
   'el asesor no se entera de que le desbloquearon una venta donde está mirando');
ok('dice cuántas son', (bool) preg_match('#<strong>\d+ ventas? lista#u', $b));
ok('y con su botón dentro', str_contains($b, 'VER Y MANDAR'), $b);
ok('que lleva a la pantalla de despacho',
   str_contains($b, 'href="/pedidos/por-despachar"'), $b);
/* El rótulo NO promete mandar: el botón abre una lista y todavía hay que
   copiar el mensaje. Un botón que desmiente a la frase de al lado enseña a no
   leer los botones. */
ok('y no promete lo que no hace', !str_contains($b, 'MANDAR A DESPACHO'), $b);

/* UNA SOLA CIFRA: la banda, el chip del menú y la pantalla de destino salen de
   pedidos_por_despachar_resumen(). Escrita a mano, en la MISMA pantalla
   acabarían diciendo números distintos. */
preg_match('#<strong>(\d+) ventas? lista#u', $b, $m_av);
$del_aviso = (int)($m_av[1] ?? -1);
$del_menu = preg_match('#por-despachar.*?nv__chip">(\d+)<#su', $n->cuerpo, $m_me) ? (int)$m_me[1] : -2;
es('la banda y el chip del menú dicen el mismo número', $del_aviso, $del_menu);
$n->ir('/pedidos/por-despachar');
$del_destino = preg_match('#<strong>(\d+) ventas? lista#u', $n->cuerpo, $m_de) ? (int)$m_de[1] : -3;
es('y la pantalla de destino, también', $del_aviso, $del_destino);

/* CON CERO NO DEJA HUECO: desaparece del todo. */
$n->salir(); $n->entrar('otro@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos');
es('sin ventas listas, la banda no se pinta', '', $banda($n->cuerpo));

/* Y a quien NO puede mandar no se le ofrece: un botón que contesta 403 es peor
   que no tenerlo. */
$n->salir(); $n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos');
es('a Facturación no le sale la banda', '', $banda($n->cuerpo));
$n->salir(); $n->entrar('ceo@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos');
es('a Dirección tampoco', '', $banda($n->cuerpo));

/* EL LÍDER DE EQUIPO. Ve las ventas de los suyos y NO las manda —el ámbito
   «equipo» es de solo lectura—, así que contarlas le ponía un número que no
   podía bajar nunca: el contador rojo permanente que este proyecto rechaza por
   escrito. Su banda solo puede hablar de lo SUYO. */
$n->salir(); $n->entrar('lider@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos');
$b_lider = $banda($n->cuerpo);
if ($b_lider !== '') {
    preg_match('#<strong>(\d+)#', $b_lider, $m_l);
    $suyas = (int) valor(
        "SELECT COUNT(*) FROM pedidos pe WHERE pe.anulado_en IS NULL
           AND pe.despacho_veces = 0 AND pe.asesor_id = ?
           AND " . sql_despacho_listo(),
        [(int) valor('SELECT id FROM usuarios WHERE email = ?', ['lider@waka.test'])]);
    es('al líder la banda solo le cuenta LO SUYO', $suyas, (int)($m_l[1] ?? -1));
} else {
    ok('al líder no le sale una banda que no puede vaciar', true);
}

grupo('Parche 2n · el «Más» del asesor, con lo suyo y nada más');

/** Solo la barra de abajo, no el menú lateral que se pinta en todas partes. */
$solo_barra = function (string $cuerpo): string {
    return preg_match('#<nav class="barra">(.*?)</nav>#su', $cuerpo, $m) ? $m[1] : '';
};
/** Solo la tarjeta de secciones de «Más». */
$solo_mas = function (string $cuerpo): string {
    return preg_match('#<div class="tarjeta">\s*<a class="fila".*?</div>#su', $cuerpo, $m) ? $m[0] : '';
};

$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
$barra = $solo_barra($n->cuerpo);
ok('la barra de abajo existe', $barra !== '');
es('son cinco pestañas y no más', 5, (int) preg_match_all('#<a href=#', $barra));
ok('y lleva «Por despachar», que antes no se podía alcanzar',
   str_contains($barra, '/pedidos/por-despachar'),
   'el asesor no tiene ninguna forma de llegar a esa sección desde el celular');
ok('con su rótulo corto', str_contains($barra, 'Despacho'));
ok('y lleva «Más»', str_contains($barra, '/mas'));
/* Y las tres que SALIERON de la barra, salieron de verdad. */
ok('Clientes ya no está en la barra',  !str_contains($barra, 'href="/clientes"'));
ok('ni Stock',                         !str_contains($barra, 'href="/stock"'));
ok('ni Recepción',                     !str_contains($barra, 'href="/recepcion"'));

$n->ir('/mas');
es('la pantalla «Más» abre', 200, $n->codigo);
$mas = $solo_mas($n->cuerpo);
ok('la tarjeta de secciones tiene algo dentro', $mas !== '',
   'con «Más» vacío el asesor pierde tres secciones y nada lo avisa');
ok('y dentro están las suyas: Clientes',  str_contains($mas, 'href="/clientes"'), $mas);
ok('Stock',                               str_contains($mas, 'href="/stock"'), $mas);
ok('y Recepción',                         str_contains($mas, 'href="/recepcion"'), $mas);
/* LO QUE NO PUEDE, NO SALE. */
ok('y NO sale Configuración',    !str_contains($mas, '/configuracion'),
   'el asesor no gestiona el HUB');
ok('ni Usuarios',                !str_contains($mas, 'href="/usuarios"'));
ok('ni Pagos por validar',       !str_contains($mas, '/pagos/por-validar'),
   'confirmar dinero no es del asesor: es lo que frena al voucher falso');

/* 3i (usuario, 2026-09-28): Facturación SÍ tiene «Más». Reportes pasó ahí,
   y con él cerrar sesión y la contraseña, que en el celular no tenía. */
$n->salir(); $n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('FACTURACIÓN TIENE «MÁS»', str_contains($solo_barra($n->cuerpo), '/mas'));
$n->ir('/mas');
ok('y dentro están los Reportes', $n->codigo === 200 && str_contains($n->cuerpo, 'href="/reportes"'));

grupo('Quién puede confirmar un pago que él mismo registró');

/* DECISIÓN DEL USUARIO (2026-09-14, preguntada cinco veces):
   «Administración sí, el asesor no». Un administrador que registra una venta
   puede confirmar su dinero él mismo — pasa cuando adm vende directo y no hay
   a quién esperar—; el asesor no confirma nada, ni lo suyo. El control que
   frena al voucher falso se queda donde importa: los 19 asesores.
   Esto ya funcionaba así, y se fija aquí para que no se mueva sin querer: es
   una regla de dinero y no tiene ninguna prueba que la sujete. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/ficha?id=' . (int)$F['pedidos']['asesor']);
$n->subir('/pagos/registrar', ['concepto'=>'Saldo de prueba', '_t'=>$n->testigoValido(), 'pedido_id'=>(int)$F['pedidos']['asesor'],
                               'monto'=>'2.00', 'metodo_item_id'=>(int)$F['efectivo'],
                               'fecha'=>date('Y-m-d')], [], false);
$pg_suyo = (int) valor('SELECT MAX(id) FROM pagos WHERE pedido_id = ? AND verificado = 0 AND anulado = 0',
                       [(int)$F['pedidos']['asesor']]);
ok('el asesor registra un pago suyo', $pg_suyo > 0);
$n->ir('/pagos/validar', ['_t'=>$n->testigoValido(), 'id'=>$pg_suyo], false);
es('y NO puede confirmárselo (403)', 403, $n->codigo);
es('el pago sigue sin confirmar', 0,
   (int) valor('SELECT verificado FROM pagos WHERE id = ?', [$pg_suyo]));

/* Administración sí: registra y confirma. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$ped_adm = (int) valor("SELECT p.id FROM pedidos p JOIN pedido_estados e ON e.id=p.estado_id
                         WHERE e.clave <> 'anulado'
                           AND (p.total_centimos - p.cobrado_centimos) > 500
                         ORDER BY p.id DESC LIMIT 1");
if ($ped_adm > 0) {
    $n->ir('/pedidos/ficha?id=' . $ped_adm);
    $n->subir('/pagos/registrar', ['concepto'=>'Saldo de prueba', '_t'=>$n->testigoValido(), 'pedido_id'=>$ped_adm,
                                   'monto'=>'2.00', 'metodo_item_id'=>(int)$F['efectivo'],
                                   'fecha'=>date('Y-m-d')], [], false);
    $pg_adm = (int) valor('SELECT MAX(id) FROM pagos WHERE pedido_id = ? AND verificado = 0 AND anulado = 0',
                          [$ped_adm]);
    ok('Administración registra un pago', $pg_adm > 0);
    $n->ir('/pagos/validar', ['_t'=>$n->testigoValido(), 'id'=>$pg_adm], false);
    es('y SÍ puede confirmar el que ella misma registró', 1,
       (int) valor('SELECT verificado FROM pagos WHERE id = ?', [$pg_adm]));
    es('y queda con su nombre en las dos puntas',
       (int) valor("SELECT id FROM usuarios WHERE email = 'admin@waka.test'"),
       (int) valor('SELECT verificado_por FROM pagos WHERE id = ?', [$pg_adm]));
} else {
    ok('había un pedido con saldo para probarlo', false, 'no lo hubo');
}

grupo('Qué versión está subida');

/* Un parche subido a medias —o el ZIP anterior subido por error— es
   indistinguible del bueno mirando la pantalla, y se pierde media tarde
   averiguando si el fallo es del código o de la subida. El pie lo dice. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('el pie dice la versión', str_contains($n->cuerpo, 'versión ' . HUB_VERSION),
   'sin esto no hay forma de saber qué hay arriba');

/* Y EL CAMPO VIEJO NO PUEDE VOLVER. El usuario lo vio todavía en pantalla
   después de subir el parche —era el ZIP anterior—, así que esto queda fijado:
   el comprobante se SOLICITA, no se teclea al registrar la venta. */
$n->ir('/pedidos/nuevo');
ok('el formulario de nueva venta no pregunta por el comprobante',
   !str_contains($n->cuerpo, 'comprobante_pide')
   && !str_contains($n->cuerpo, 'Pide comprobante'),
   'volvió el campo que se quitó el 2026-09-12');

grupo('Lo que nunca se sirve por web');

$n->ir('/app/nucleo/pagos.php', null, false);
ok('la carpeta app no se sirve', in_array($n->codigo, [403, 404], true));
$n->ir('/uploads/vouchers/v20260908-aaaaaaaaaaaa.jpg', null, false);
ok('los vouchers no se sirven por su dirección', in_array($n->codigo, [403, 404], true));
$n->ir('/pruebas/banco.php', null, false);
ok('la carpeta de pruebas tampoco', in_array($n->codigo, [403, 404], true));

/* ═════════════  PARCHE 2R · LO QUE PIDIÓ EL USUARIO EL 2026-09-20  ═════════════ */
grupo('Parche 2r · enviar armado, la sucursal y el modelo');

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');

/* $ultimo se reusó más arriba para otra cosa (un id de pago), así que aquí va
   con su propio nombre: el último pedido registrado. */
$ult2r = fn() => una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');

/* 1 · ENVIAR ARMADO NO APLICA A PROVINCIA NI EN LIQUIDACIÓN. La pantalla
   esconde la casilla, y una casilla escondida sigue viajando en el formulario:
   lo que manda es el servidor. */
$mandar(['destino' => 'provincia', 'envio_modo' => 'agencia', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => $AGENCIA, 'sucursal' => 'Terminal Terrestre',
         'envio_armado' => '1',
         'l_desc' => ['Silla Gamer'], 'l_modelo' => ['1 negro - 1 blanco'],
         'l_sku' => [''], 'l_cant' => ['2'], 'l_precio' => ['250']]);
ok('la venta a provincia se registra', str_contains($n->cuerpo, 'Pedido registrado'));
$p2r = $ult2r();
es('pero «enviar armado» no se guarda a provincia', 0, (int)$p2r['envio_armado']);

/* 2 · EL MODELO Y EL COLOR se guardan en la línea y viajan al mensaje. */
es('el modelo queda en la línea del producto', '1 negro - 1 blanco',
   (string) valor('SELECT modelo FROM pedido_lineas WHERE pedido_id = ? ORDER BY id LIMIT 1',
                  [(int)$p2r['id']]));
$msg2r = mensaje_de_pedido((int)$p2r['id'], plantilla_de_despacho($p2r));
ok('y sale en el mensaje del grupo', str_contains($msg2r, '1 negro - 1 blanco'), $msg2r);
ok('con el destino en una sola línea', str_contains($msg2r, 'Wanchaq - Cusco - Cusco'), $msg2r);

/* 3 · LA SUCURSAL QUEDA APRENDIDA para la próxima venta. */
$n->ir('/sucursales/buscar?ag=' . $AGENCIA . '&ubigeo=' . $WANCHAQ);
$js = json_decode($n->cuerpo, true);
$nombres = array_map(fn($x) => $x['nombre'], $js['sucursales'] ?? []);
ok('la sucursal escrita se ofrece en la siguiente venta',
   in_array('Terminal Terrestre', $nombres, true), $n->cuerpo);
/* Y no se duplica por escribirla otra vez. */
$mandar(['destino' => 'provincia', 'envio_modo' => 'agencia', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => $AGENCIA, 'sucursal' => 'terminal terrestre']);
es('escribirla otra vez no crea una segunda', 1,
   (int) valor('SELECT COUNT(*) FROM agencia_sucursales WHERE busca = ?', ['terminal terrestre']));
es('pero sí cuenta que se usó dos veces', 2,
   (int) valor('SELECT veces FROM agencia_sucursales WHERE busca = ?', ['terminal terrestre']));

/* 3bis · LA SUCURSAL DE UN ENVÍO A DOMICILIO NO ES UNA SUCURSAL. La pantalla
   esconde el campo al cambiar a «la agencia se lo lleva», y un campo escondido
   sigue viajando: sin limpiarlo, al grupo le llegaba «Agencia: Shalom - Av.
   Grau 123» y esa dirección se aprendía como sucursal buena (auditoría 2r). */
$mandar(['destino' => 'provincia', 'envio_modo' => 'domicilio', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => $AGENCIA, 'sucursal' => 'Av. Grau 123',
         'direccion_txt' => 'Av. Grau 123', 'recibe' => 'Su mamá']);
ok('el envío a domicilio a provincia se registra', str_contains($n->cuerpo, 'Pedido registrado'),
   mb_substr(strip_tags($n->cuerpo), 0, 200));
es('y no se guarda ninguna sucursal', '', (string)$ult2r()['sucursal']);
es('ni se aprende como sucursal de esa agencia', 0,
   (int) valor('SELECT COUNT(*) FROM agencia_sucursales WHERE busca = ?', ['av grau 123']));

/* 4 · EN LIQUIDACIÓN TAMPOCO SE ENVÍA ARMADO, ni en Lima. */
$mandar(['tipo' => 'liquidacion', 'destino' => 'lima', 'ubigeo_id' => $SJL,
         'direccion_txt' => 'Av. Próceres 500', 'envio_armado' => '1']);
ok('la liquidación en Lima se registra', str_contains($n->cuerpo, 'Pedido registrado'),
   mb_substr(strip_tags($n->cuerpo), 0, 200));
es('y tampoco va armada', 0, (int)$ult2r()['envio_armado']);

/* Y en una venta normal de Lima sí se guarda: la regla quita lo que no aplica,
   no la casilla entera. */
$mandar(['destino' => 'lima', 'ubigeo_id' => $SJL,
         'direccion_txt' => 'Av. Próceres 500', 'envio_armado' => '1']);
es('en Lima y venta normal, «enviar armado» sí queda', 1, (int)$ult2r()['envio_armado']);
$PED_LIMA_2R = (int)$ult2r()['id'];

/* 5 · A PROVINCIA SE COBRA TODO ANTES DE DESPACHAR: no hay contra entrega
   (usuario, 2026-09-20). La pantalla esconde «pagó una parte»; el servidor lo
   comprueba, que es lo que de verdad lo impide. */
$mandar(['destino' => 'provincia', 'envio_modo' => 'agencia', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => $AGENCIA, 'sucursal' => 'Terminal Terrestre',
         'modo_pago' => 'adelanto', 'pago_monto' => '100.00',
         'l_desc' => ['Silla Gamer'], 'l_sku' => [''], 'l_cant' => ['1'], 'l_precio' => ['500']]);
ok('a provincia, media venta pagada se rechaza',
   str_contains($n->cuerpo, 'no hay pago contra'), mb_substr(strip_tags($n->cuerpo), 0, 240));
ok('y dice cuánto falta', str_contains($n->cuerpo, 'Faltan S/ 400.00'));

/* Pagando el total, la misma venta entra. */
$mandar(['destino' => 'provincia', 'envio_modo' => 'agencia', 'ubigeo_id' => $WANCHAQ,
         'agencia_item_id' => $AGENCIA, 'sucursal' => 'Terminal Terrestre',
         'modo_pago' => 'completo', 'pago_monto' => '500.00',
         'l_desc' => ['Silla Gamer'], 'l_sku' => [''], 'l_cant' => ['1'], 'l_precio' => ['500']]);
ok('pagando todo, la venta a provincia entra', str_contains($n->cuerpo, 'Pedido registrado'),
   mb_substr(strip_tags($n->cuerpo), 0, 240));

/* Y LA PRE VENTA SE QUEDA FUERA de la regla: ahí el adelanto ES el trato y el
   saldo se cobra cuando llega el contenedor. */
$mandar(['tipo' => 'preventa', 'destino' => 'provincia', 'envio_modo' => 'agencia',
         'ubigeo_id' => $WANCHAQ, 'agencia_item_id' => $AGENCIA, 'sucursal' => 'Terminal Terrestre',
         'modo_pago' => 'adelanto', 'pago_monto' => '50.00',
         'l_desc' => ['Silla Gamer'], 'l_sku' => [''], 'l_cant' => ['1'], 'l_precio' => ['500']]);
ok('una pre venta a provincia sí se separa con su adelanto',
   str_contains($n->cuerpo, 'Pedido registrado'), mb_substr(strip_tags($n->cuerpo), 0, 240));

grupo('Parche 2r · el filtro Lima / provincia y el rótulo');

$COD_LIMA_2R = (string) valor('SELECT codigo FROM pedidos WHERE id = ?', [$PED_LIMA_2R]);
$n->ir('/pedidos?z=lima');
ok('el filtro de Lima existe y contesta', $n->codigo === 200);
ok('y trae la venta de Lima', str_contains($n->cuerpo, $COD_LIMA_2R), 'no salió ' . $COD_LIMA_2R);
$n->ir('/pedidos?z=provincia');
ok('el de provincia también contesta', $n->codigo === 200);
ok('y la venta de Lima NO sale ahí', !str_contains($n->cuerpo, $COD_LIMA_2R));
/* Y la de provincia sí está en su filtro. */
$COD_PROV_2R = (string) valor("SELECT p.codigo FROM pedidos p WHERE p.ubigeo_id = ?
                                ORDER BY p.id DESC LIMIT 1", [$WANCHAQ]);
/* 3j: la pre venta va en su pestaña. */
if ((string) valor('SELECT tipo FROM pedidos WHERE codigo = ?', [$COD_PROV_2R]) === 'preventa') $n->ir('/pedidos?tab=preventa&z=provincia');
ok('y la de provincia sí sale en el suyo', str_contains($n->cuerpo, $COD_PROV_2R), $COD_PROV_2R);

/* EL RÓTULO. Se pide por su dirección, como haría el navegador.
   Y SOLO DE UNA VENTA QUE YA PUEDE SALIR: un rótulo impreso es una caja
   preparada para salir. Mientras el pago no esté confirmado, la misma puerta
   que cierra la pantalla de despacho cierra el rótulo. */
$n->ir('/pedidos/rotulo?id=' . $PED_LIMA_2R, null, false);
es('con el pago sin confirmar, el rótulo no sale', 409, $n->codigo);

/* Se confirma el pago y entonces sí. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$pago_2r = (int) valor('SELECT id FROM pagos WHERE pedido_id = ? ORDER BY id DESC LIMIT 1',
                       [$PED_LIMA_2R]);
pago_validar($pago_2r, 'OP-2R-0001');
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/rotulo?id=' . $PED_LIMA_2R, null, false);
ok('con el pago confirmado, el rótulo se descarga', $n->codigo === 200, 'código ' . $n->codigo);
ok('y es un PDF de verdad', str_starts_with($n->cuerpo, '%PDF-'),
   mb_substr($n->cuerpo, 0, 40));

/* Un pedido anulado no lleva rótulo: una caja con rótulo es una caja que sale. */
$anul = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id
                      WHERE l.clave = 'motivos_anulacion' LIMIT 1");
pedido_anular($PED_LIMA_2R, $anul);
$n->ir('/pedidos/rotulo?id=' . $PED_LIMA_2R, null, false);
es('y un pedido anulado no lo lleva', 410, $n->codigo);

grupo('Parche 2r · el resumen antes de confirmar');

$n->ir('/pedidos/nuevo');
ok('la pantalla de venta trae la confirmación final',
   str_contains($n->cuerpo, 'CONFIRMAR Y REGISTRAR'));
ok('y el campo de modelo o color', str_contains($n->cuerpo, 'l_modelo[]'));
ok('la comisión de pasarela nace escondida',
   str_contains($n->cuerpo, 'id="caja-comision" hidden'), 'sale con cualquier método');
ok('y el día de entrega no deja elegir hoy',
   str_contains($n->cuerpo, 'min="' . date('Y-m-d', strtotime('+1 day')) . '"'));
ok('ni una fecha a más de un año',
   str_contains($n->cuerpo, 'max="' . date('Y-m-d', strtotime('+12 months')) . '"'));
/* Las fechas de pago admiten lo mismo que el servidor —treinta días atrás para
   el asesor—, no «desde el 1 de enero»: con el tope de año, cada 1 de enero el
   pago de anoche quedaba fuera del calendario (auditoría del 2r). */
ok('y la fecha del pago llega hasta treinta días atrás',
   str_contains($n->cuerpo, 'min="' . date('Y-m-d', strtotime('-30 days')) . '"'),
   'el asesor no puede fechar el pago de ayer si cae en otro año');

/* Y a quien confirma pagos no se le pone tope, que es la excepción que el
   servidor le concede a propósito. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/nuevo');
ok('y a Administración no se le topa la fecha del pago',
   !str_contains($n->cuerpo, 'name="pago_fecha" value="' . date('Y-m-d') . '"' . "\n" . '                     min='),
   'no debería llevar min');
ok('y la fecha del pedido le llega hasta un año atrás',
   str_contains($n->cuerpo, 'min="' . date('Y-m-d', strtotime('-365 days')) . '"'));
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');

/* ═══════════════  PARCHE 2S · LO QUE PIDIÓ EL USUARIO EL 2026-09-21  ═══════════════ */
grupo('Parche 2s · el concepto de un pago adicional');

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$ult2s = fn() => una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
/* Una venta a Lima con adelanto: el asesor sube después el saldo. */
$mandar(['destino' => 'lima', 'ubigeo_id' => $SJL, 'direccion_txt' => 'Av. Próceres 500',
         'modo_pago' => 'adelanto', 'pago_monto' => '100.00',
         'l_desc' => ['Silla Gamer'], 'l_sku' => [''], 'l_cant' => ['1'], 'l_precio' => ['500']]);
$P2S = (int)$ult2s()['id'];
$cuenta_pagos = fn() => (int) valor('SELECT COUNT(*) FROM pagos WHERE pedido_id = ?', [$P2S]);
es('la venta nace con su primer pago', 1, $cuenta_pagos());

$n->ir('/pedidos/ficha?id=' . $P2S);
ok('la ficha pide el concepto del siguiente pago', str_contains($n->cuerpo, 'name="concepto"'));
ok('y dice qué número será', str_contains($n->cuerpo, 'Será el 2.º pago'));

/* Sin concepto: se rechaza y NO nace ningún pago. */
$n->ir('/pagos/registrar', ['_t'=>$n->testigoValido(), 'pedido_id'=>$P2S,
                            'monto'=>'100', 'metodo_item_id'=>(int)$F['efectivo']], false);
es('un pago adicional sin concepto no se registra', 1, $cuenta_pagos());
$n->ir('/pedidos/ficha?id=' . $P2S);
ok('y se dice qué falta', str_contains($n->cuerpo, 'Escribe el concepto de este pago'));

/* Con concepto: entra y se ve con su número. */
$n->ir('/pagos/registrar', ['_t'=>$n->testigoValido(), 'pedido_id'=>$P2S, 'concepto'=>'Delivery',
                            'monto'=>'100', 'metodo_item_id'=>(int)$F['efectivo']], false);
es('con concepto se registra', 2, $cuenta_pagos());
$n->ir('/pedidos/ficha?id=' . $P2S);
ok('y la ficha lo enseña con su número y su concepto', str_contains($n->cuerpo, '2.º pago · Delivery'));
ok('el primero sale como 1.er pago', str_contains($n->cuerpo, '1.er pago'));

/* Y facturación lo ve igual en su bandeja. */
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/por-validar');
ok('la bandeja de facturación dice qué pago es', str_contains($n->cuerpo, '2.º pago · Delivery'));

grupo('Parche 2s · el aviso del saldo por registrar');

/* Se confirma el adelanto, se despacha, y se deja pasar un día. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
foreach (todas('SELECT id FROM pagos WHERE pedido_id = ? AND verificado = 0 AND anulado = 0', [$P2S]) as $pgx) {
    pago_validar((int)$pgx['id'], 'OP-2S-' . $pgx['id']);
}
q('UPDATE pedidos SET despacho_veces = 1, despacho_en = ? WHERE id = ?',
  [date('Y-m-d H:i:s', strtotime('-1 day')), $P2S]);

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('al día siguiente, el Inicio del asesor avisa del cobro por registrar',
   str_contains($n->cuerpo, 'Registra el cobro de'), 'no salió el aviso');
ok('con el código de la venta', str_contains($n->cuerpo, (string)$ult2s()['codigo']));

/* Administración lo ve a partir del tercer día, en su bandeja. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('el primer día Administración todavía no lo tiene en la bandeja',
   !str_contains($n->cuerpo, 'saldo sin registrar') && !str_contains($n->cuerpo, 'saldos sin registrar'));
/* Y la lista a la que lleva la bandeja usa EL MISMO plazo: si la bandeja no lo
   cuenta, la lista no lo trae. */
$n->ir('/pedidos?v=porregistrar');
ok('ni en la lista de saldos sin registrar',
   !str_contains($n->cuerpo, (string) valor('SELECT codigo FROM pedidos WHERE id = ?', [$P2S])));
q('UPDATE pedidos SET despacho_en = ? WHERE id = ?',
  [date('Y-m-d H:i:s', strtotime('-3 days')), $P2S]);
$n->ir('/inicio');
ok('a los tres días le sale en la bandeja',
   str_contains($n->cuerpo, 'saldo sin registrar') || str_contains($n->cuerpo, 'saldos sin registrar'));
$n->ir('/pedidos?v=porregistrar');
ok('y la lista a la que lleva trae la venta', str_contains($n->cuerpo, (string)$ult2s()['codigo']));
/* 3h.1 (usuario, 2026-09-28): al CEO no. */
$n->salir(); $n->entrar('ceo@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('3h.1: AL CEO, CON EL MISMO SALDO, NO LE SALE', $n->codigo === 200 && !str_contains($n->cuerpo, 'sin registrar'));
$n->ir('/pedidos/ficha?id=' . $P2S);
ok('ni la ficha le pide preguntarle al asesor', !str_contains($n->cuerpo, 'falta registrar'));
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');

/* 3b.5 · ESCRIBIRLE AL ASESOR (usuario, 2026-09-25): el aviso llevaba a la
   lista, el menú «···» no se veía y en la ficha no había cómo preguntarle. */
$ID_AS2S = (int) valor('SELECT asesor_id FROM pedidos WHERE id = ?', [$P2S]);
$cel_antes = valor('SELECT celular FROM usuarios WHERE id = ?', [$ID_AS2S]);
q("UPDATE usuarios SET celular = '987654321' WHERE id = ?", [$ID_AS2S]);
$n->ir('/pedidos?v=porregistrar');
$menu_2s = preg_match('~<a href="https://wa\.me/51987654321\?text=([^"]+)"[^>]*data-wa-asesor="' . $P2S . '"~', $n->cuerpo, $mw) ? urldecode(html_entity_decode($mw[1])) : '';
ok('EL MENÚ DE LA FILA OFRECE ESCRIBIRLE AL ASESOR', $menu_2s !== '', 'no está el enlace');
ok('con el mensaje del saldo ya escrito', str_contains($menu_2s, 'ya se entregó y falta registrar el saldo de S/') && str_contains($menu_2s, (string)$ult2s()['codigo']), $menu_2s);
$n->ir('/pedidos/ficha?id=' . $P2S);
ok('LA FICHA DICE LO QUE FALTA Y OFRECE ESCRIBIRLE', (bool) preg_match('~accion-ahora.*?falta registrar S/.*?id="accion-wa" href="https://wa\.me/51987654321~s', $n->cuerpo));
$bloque_acc = fn(string $c) => preg_match('~<div class="accion-ahora[^"]*">(.*?)</div>~s', $c, $ma) ? $ma[1] : '';
ok('y no le pide a Administración que registre el pago ella', !str_contains($bloque_acc($n->cuerpo), 'REGISTRAR EL PAGO'), $bloque_acc($n->cuerpo));
/* Facturación no ve el celular del asesor: es un dato personal. */
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/ficha?id=' . $P2S);
ok('A FACTURACIÓN NO SE LE ENSEÑA EL CELULAR DEL ASESOR', !str_contains($n->cuerpo, 'wa.me/51987654321'));
$n->ir('/pedidos?v=todos&q=' . urlencode((string)$ult2s()['codigo']));
ok('ni en el menú de la lista', !str_contains($n->cuerpo, 'data-wa-asesor='));
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
/* Recién despachada, con la entrega todavía por delante, no «debe» nada. */
q('UPDATE pedidos SET despacho_en = ? WHERE id = ?', [date('Y-m-d H:i:s'), $P2S]);
$n->ir('/pedidos/ficha?id=' . $P2S);
ok('UNA VENTA QUE SALIÓ HOY NO DICE «FALTA REGISTRAR»: aún no toca', !str_contains($n->cuerpo, 'id="accion-wa"')
   && !str_contains($bloque_acc($n->cuerpo), 'falta registrar'), $bloque_acc($n->cuerpo));
ok('solo el botón de escribirle, sin prisa', str_contains($n->cuerpo, 'id="wa-asesor"'));
$n->ir('/pedidos?v=todos&q=' . urlencode((string)$ult2s()['codigo']));
ok('y en el menú de la lista, sin la estrella de «falta registrar»',
   (bool) preg_match('~data-wa-asesor="' . $P2S . '">\s*<span>Escribir a~', $n->cuerpo), 'sale con estrella o no sale');
q('UPDATE pedidos SET despacho_en = ? WHERE id = ?', [date('Y-m-d H:i:s', strtotime('-3 days')), $P2S]);
q("UPDATE usuarios SET celular = NULL WHERE id = ?", [$ID_AS2S]);
$n->ir('/pedidos/ficha?id=' . $P2S);
ok('sin celular en la ficha del asesor, lo dice y ofrece ponerlo', (bool) preg_match('~no tiene celular en su ficha.*?PONER SU CELULAR~s', $n->cuerpo));
q("UPDATE usuarios SET celular = ? WHERE id = ?", [$cel_antes, $ID_AS2S]);
/* El propio asesor no se escribe a sí mismo. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/ficha?id=' . $P2S);
ok('al asesor no se le ofrece escribirse a sí mismo', !str_contains($n->cuerpo, 'id="accion-wa"') && !str_contains($n->cuerpo, 'id="wa-asesor"'));
ok('a él se le sigue pidiendo registrar el pago', str_contains($bloque_acc($n->cuerpo), 'REGISTRAR EL PAGO'), $bloque_acc($n->cuerpo));
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');

grupo('Parche 2s · a provincia no sale con saldo');

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
/* Una pre venta a provincia separada con el adelanto, y confirmado. */
$mandar(['tipo' => 'preventa', 'destino' => 'provincia', 'envio_modo' => 'agencia',
         'ubigeo_id' => $WANCHAQ, 'agencia_item_id' => $AGENCIA, 'sucursal' => 'Terminal Terrestre',
         'modo_pago' => 'adelanto', 'pago_monto' => '50.00',
         'l_desc' => ['Silla Gamer'], 'l_sku' => [''], 'l_cant' => ['1'], 'l_precio' => ['500']]);
$PPV3 = (int)$ult2s()['id'];
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
foreach (todas('SELECT id FROM pagos WHERE pedido_id = ? AND verificado = 0 AND anulado = 0', [$PPV3]) as $pgx) {
    pago_validar((int)$pgx['id'], 'OP-2S-P' . $pgx['id']);
}
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/despacho?id=' . $PPV3, null, false);
es('con el adelanto confirmado, la pre venta a provincia no se puede mandar', 409, $n->codigo);
ok('y dice que a provincia se cobra todo', str_contains($n->cuerpo, 'A provincia se cobra todo'));
$n->ir('/pedidos/rotulo?id=' . $PPV3, null, false);
es('ni se puede sacar su rótulo', 409, $n->codigo);
$n->ir('/pedidos/por-despachar');
ok('«Por despachar» dice el motivo de verdad: falta cobrar el total',
   str_contains($n->cuerpo, 'Falta cobrar el total'), 'decía «Sin confirmar»');

grupo('Parche 2s · lo que ya salió, salió');

/* La venta de Lima de arriba ya se mandó a despacho. El asesor sube el saldo y
   queda pendiente: la ficha no puede volver a decir «en espera del pago», y el
   rótulo de esa caja se tiene que poder reimprimir. */
$n->ir('/pagos/registrar', ['_t'=>$n->testigoValido(), 'pedido_id'=>$P2S, 'concepto'=>'Saldo contraentrega',
                            'monto'=>'300', 'metodo_item_id'=>(int)$F['efectivo']], false);
$n->ir('/pedidos/ficha?id=' . $P2S);
ok('con el saldo pendiente, la ficha sigue diciendo que ya salió',
   str_contains($n->cuerpo, 'Ya salió a despacho'));
ok('y no que espera el pago', !str_contains($n->cuerpo, 'Despacho en espera del pago'));
$n->ir('/pedidos/rotulo?id=' . $P2S, null, false);
es('y su rótulo se puede reimprimir', 200, $n->codigo);

/* Y registrado el saldo, sale del aviso aunque facturación no lo haya visto. */
$n->ir('/inicio');
ok('registrado el saldo, el aviso del Inicio se va',
   !str_contains($n->cuerpo, (string) valor('SELECT codigo FROM pedidos WHERE id = ?', [$P2S]))
   || !str_contains($n->cuerpo, 'Registra el cobro de'));

/* ═══════════════  PARCHE 2T · LA FICHA RESALTA LO QUE HAY QUE HACER  ═══════════════ */
grupo('Parche 2t · las acciones de la ficha, resaltadas');

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$P2T = (int) valor('SELECT pe.id FROM pedidos pe
                     WHERE pe.anulado_en IS NULL AND pe.despacho_veces = 0
                       AND pe.asesor_id = (SELECT id FROM usuarios WHERE email = ?)
                       AND ' . sql_despacho_listo() . ' ORDER BY pe.id DESC LIMIT 1',
                   ['asesor@waka.test']);
ok('hay una venta lista para mandar con la que mirar', $P2T > 0);
$n->ir('/pedidos/ficha?id=' . $P2T);
/* Se mide EL BOTÓN, no la página: «MANDAR A DESPACHO» puede aparecer en otros
   sitios con otro estilo. */
ok('«Mandar a despacho» sale resaltado',
   (bool) preg_match('~name="id" value="' . $P2T . '">\s*<input type="hidden" name="primera" value="1">\s*<input type="hidden" name="volver" value="ficha">\s*<button class="btn btn--amarillo"~', $n->cuerpo));
ok('el cashback del cliente sale en grande', str_contains($n->cuerpo, 'class="cashback-cifra__v"'));
/* Y el formulario del siguiente pago pide lo mismo de siempre: método, fecha y
   voucher. El N.º de operación lo pone facturación al confirmar. Se mira en
   una venta a la que le falta dinero por registrar: en una cobrada entera el
   formulario no sale, y con razón. */
$P2T_S = (int) valor("SELECT pe.id FROM pedidos pe
                       WHERE pe.anulado_en IS NULL
                         AND pe.asesor_id = (SELECT id FROM usuarios WHERE email = ?)
                         AND pe.total_centimos - pe.cobrado_centimos
                             - COALESCE((SELECT SUM(x.monto_centimos) FROM pagos x
                                          WHERE x.pedido_id = pe.id AND x.anulado = 0
                                            AND x.verificado = 0), 0) > 0
                       ORDER BY pe.id DESC LIMIT 1", ['asesor@waka.test']);
ok('hay una venta con saldo por registrar', $P2T_S > 0);
$n->ir('/pedidos/ficha?id=' . $P2T_S);
ok('el siguiente pago pide el método', str_contains($n->cuerpo, 'name="metodo_item_id"'));
ok('y la fecha del pago', (bool) preg_match('~<input type="date" name="fecha"~', $n->cuerpo));
ok('y el voucher', str_contains($n->cuerpo, 'name="voucher"'));

grupo('Parche 2u · facturación electrónica con NUBEFACT');

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion/facturacion', ['_t' => $n->testigoValido(), 'accion' => 'guardar', 'amb' => 'prueba',
                                      'ruta' => 'https://127.0.0.1:1/api/v1/x', 'token' => str_repeat('a', 30)]);
ok('el asesor no puede tocar los datos de NUBEFACT', $n->codigo === 403);

$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion');
ok('Configuración lleva a Facturación electrónica', str_contains($n->cuerpo, '/configuracion/facturacion'));
$n->ir('/configuracion/facturacion?amb=prueba');
ok('la pantalla de NUBEFACT se abre', $n->codigo === 200 && str_contains($n->cuerpo, 'Facturación electrónica'));
$TOK = 'tok' . str_repeat('x', 24) . 'Z9Q7';
$guardar_nf = fn(array $extra) => $n->ir('/configuracion/facturacion', array_merge([
    '_t' => $n->testigo(), 'accion' => 'guardar', 'amb' => 'prueba',
    'ruta' => 'https://127.0.0.1:1/api/v1/prueba', 'token' => $TOK,
    'serie_boleta' => 'BBB1', 'serie_factura' => 'FFF1',
    'serie_nc_boleta' => 'BBB2', 'serie_nc_factura' => 'FFF2'], $extra));
$guardar_nf(['serie_boleta' => 'X001']);
ok('una serie que no empieza por B se rechaza', str_contains($n->cuerpo, 'La serie «X001» no vale'));
ok('y no se guarda nada', (string) valor("SELECT valor FROM ajustes WHERE clave = 'nubefact_prueba_token'") !== $TOK);
$n->ir('/configuracion/facturacion?amb=prueba');
$guardar_nf([]);
ok('con datos buenos se guarda', (string) valor("SELECT valor FROM ajustes WHERE clave = 'nubefact_prueba_token'") === $TOK);
$n->ir('/configuracion/facturacion?amb=prueba');
ok('el token no se vuelve a enseñar entero', !str_contains($n->cuerpo, $TOK));
ok('solo sus cuatro últimos caracteres', str_contains($n->cuerpo, 'Z9Q7'));
ok('y se puede probar la conexión', str_contains($n->cuerpo, 'PROBAR LA CONEXIÓN'));
/* Guardar otra vez con el token vacío lo conserva. */
$guardar_nf(['token' => '']);
ok('dejar el token vacío conserva el que había',
   (string) valor("SELECT valor FROM ajustes WHERE clave = 'nubefact_prueba_token'") === $TOK);

/* Una venta con un pago confirmado y sin comprobante. */
$P2U = (int) valor("SELECT pe.id FROM pedidos pe
                     WHERE pe.anulado_en IS NULL AND pe.cobrado_centimos >= pe.total_centimos
                       AND (pe.comprobante_tipo IS NULL OR pe.comprobante_tipo = '')
                       AND pe.asesor_id = (SELECT id FROM usuarios WHERE email = ?)
                     ORDER BY pe.id DESC LIMIT 1", ['asesor@waka.test']);
ok('hay una venta cobrada del todo y sin comprobante', $P2U > 0);

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/facturar?id=' . $P2U);
ok('facturar enseña EMITIR BOLETA con NUBEFACT',
   (bool) preg_match('~action="[^"]*/pedidos/emitir"~', $n->cuerpo) && str_contains($n->cuerpo, 'EMITIR BOLETA'));
ok('y sigue dejando anotar uno emitido fuera', str_contains($n->cuerpo, 'se emitió fuera'));

/* NUBEFACT no contesta (la ruta apunta a un puerto cerrado). */
$n->ir('/pedidos/emitir', ['_t' => $n->testigo(), 'id' => $P2U, 'tipo' => 'boleta', 'volver' => 'facturar']);
ok('si NUBEFACT no contesta, se dice', str_contains($n->cuerpo, 'No hubo respuesta de NUBEFACT'));
ok('y la venta sigue sin comprobante',
   in_array((string) valor('SELECT comprobante_tipo FROM pedidos WHERE id = ?', [$P2U]), ['', '0'], true)
   || valor('SELECT comprobante_tipo FROM pedidos WHERE id = ?', [$P2U]) === null);
ok('el número queda reservado como «sin confirmar»',
   (string) valor("SELECT estado FROM comprobantes_electronicos WHERE pedido_id = ? ORDER BY id DESC LIMIT 1", [$P2U]) === 'incierto');
ok('facturar avisa de la boleta que quedó sin respuesta', str_contains($n->cuerpo, 'se mandó y no tuvo respuesta'));
q("UPDATE comprobantes_electronicos SET enviado_en = ? WHERE pedido_id = ?", [date('Y-m-d H:i:s', time() - 600), $P2U]);
$n->ir('/pedidos/emitir', ['_t' => $n->testigo(), 'id' => $P2U, 'tipo' => 'boleta', 'volver' => 'facturar']);
ok('al reintentar se usa el mismo número, no uno nuevo',
   (int) valor('SELECT COUNT(*) FROM comprobantes_electronicos WHERE pedido_id = ?', [$P2U]) === 1);

/* Se da por emitida (como si NUBEFACT hubiera contestado) para ver la anulación. */
q("UPDATE comprobantes_electronicos SET estado = 'emitido', enlace_pdf = 'https://ejemplo.test/b.pdf' WHERE pedido_id = ?", [$P2U]);
q("UPDATE pedidos SET comprobante_tipo = 'boleta', comprobante_serie = 'BBB1', comprobante_numero = ? WHERE id = ?",
  [(string)(int) valor('SELECT numero FROM comprobantes_electronicos WHERE pedido_id = ?', [$P2U]), $P2U]);
$n->ir('/pedidos/ficha?id=' . $P2U);
ok('la ficha enseña el PDF de la boleta', str_contains($n->cuerpo, 'https://ejemplo.test/b.pdf'));

$motivo_2u = (int) valor("SELECT li.id FROM lista_items li JOIN listas l ON l.id = li.lista_id
                           WHERE l.clave = 'motivos_anulacion' ORDER BY li.id LIMIT 1");
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/anular', ['_t' => $n->testigoValido(), 'id' => $P2U, 'motivo_item_id' => $motivo_2u,
                           'nota' => 'prueba']);
ok('el asesor no anula una venta con boleta emitida', str_contains($n->cuerpo, 'Pídele a Administración que la anule'));
ok('y la venta sigue viva', valor('SELECT anulado_en FROM pedidos WHERE id = ?', [$P2U]) === null);

$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/anular', ['_t' => $n->testigoValido(), 'id' => $P2U, 'motivo_item_id' => $motivo_2u,
                           'nota' => 'prueba']);
ok('si la nota de crédito no sale, no se anula', str_contains($n->cuerpo, 'la nota de crédito no salió'));
ok('y la venta sigue viva', valor('SELECT anulado_en FROM pedidos WHERE id = ?', [$P2U]) === null);
ok('sin nota de crédito emitida',
   (int) valor("SELECT COUNT(*) FROM comprobantes_electronicos WHERE pedido_id = ? AND tipo_cpe = 3 AND estado = 'emitido'", [$P2U]) === 0);

/* Al pasar a producción, la boleta de prueba deja de contar. */
$n->ir('/configuracion/facturacion?amb=produccion');
$n->ir('/configuracion/facturacion', ['_t' => $n->testigo(), 'accion' => 'guardar', 'amb' => 'produccion',
    'ruta' => 'https://127.0.0.1:1/api/v1/real', 'token' => str_repeat('r', 32),
    'serie_boleta' => 'B001', 'serie_factura' => 'F001', 'serie_nc_boleta' => 'BC01', 'serie_nc_factura' => 'FC01']);
$n->ir('/configuracion/facturacion', ['_t' => $n->testigo(), 'accion' => 'usar', 'ambiente' => 'produccion']);
ok('se pasa a producción', (string) valor("SELECT valor FROM ajustes WHERE clave = 'nubefact_ambiente'") === 'produccion');
ok('y la venta con boleta de prueba vuelve a quedar sin comprobante',
   valor('SELECT comprobante_tipo FROM pedidos WHERE id = ?', [$P2U]) === null);
$n->ir('/pedidos/facturar?id=' . $P2U);
ok('y se le puede emitir la de verdad', str_contains($n->cuerpo, 'EMITIR BOLETA'));
ok('el botón avisa que va a SUNAT', str_contains($n->cuerpo, 'Va a SUNAT.'));

/* Se deja como estaba: sin NUBEFACT configurado. */
q("UPDATE ajustes SET valor = '' WHERE clave IN ('nubefact_prueba_ruta', 'nubefact_prueba_token',
                                               'nubefact_produccion_ruta', 'nubefact_produccion_token')");
q("UPDATE ajustes SET valor = 'prueba' WHERE clave = 'nubefact_ambiente'");

grupo('Parche 2v · un solo título por pantalla');
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
foreach (['/configuracion', '/configuracion/contactos', '/configuracion/facturacion', '/configuracion/equipos',
          '/pedidos', '/clientes', '/usuarios', '/pagos/por-validar', '/reportes/pagos'] as $ruta_h1) {
    $n->ir($ruta_h1);
    ok($ruta_h1 . ' lleva un solo título', substr_count($n->cuerpo, '<h1') === 1,
       substr_count($n->cuerpo, '<h1') . ' títulos');
}

grupo('Parche 2w · lo que se pidió en pantalla');

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/nuevo');
ok('el botón del cliente nuevo dice qué hace', str_contains($n->cuerpo, 'Registrar nuevo cliente'));
ok('y sale en amarillo', (bool) preg_match('~class="btn btn--amarillo" id="abrir-alta"~', $n->cuerpo));

/* Una venta con saldo: el saldo resaltado, el texto del pago a mano y el
   formulario con su título. */
$P2W = (int) valor("SELECT pe.id FROM pedidos pe
                     WHERE pe.anulado_en IS NULL
                       AND pe.asesor_id = (SELECT id FROM usuarios WHERE email = ?)
                       AND pe.total_centimos - pe.cobrado_centimos > 0
                     ORDER BY pe.id DESC LIMIT 1", ['asesor@waka.test']);
ok('hay una venta con saldo', $P2W > 0);
$n->ir('/pedidos/ficha?id=' . $P2W);
ok('el saldo por cobrar sale resaltado', str_contains($n->cuerpo, 'class="saldo-cifra"'));
ok('y los textos para el cliente están junto a los pagos',
   str_contains($n->cuerpo, 'Textos para el cliente:'));
ok('el formulario del pago dice para qué es',
   str_contains($n->cuerpo, 'Añadir otro pago') || str_contains($n->cuerpo, 'Registrar el primer pago'));

/* Y en una venta cobrada del todo, el saldo a cero no se resalta. */
$P2W_OK = (int) valor("SELECT pe.id FROM pedidos pe
                        WHERE pe.anulado_en IS NULL AND pe.cobrado_centimos >= pe.total_centimos
                          AND pe.asesor_id = (SELECT id FROM usuarios WHERE email = ?)
                        ORDER BY pe.id DESC LIMIT 1", ['asesor@waka.test']);
$n->ir('/pedidos/ficha?id=' . $P2W_OK);
ok('con todo cobrado, el saldo no se resalta', !str_contains($n->cuerpo, 'class="saldo-cifra"'));

$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/reportes/pagos');
ok('los filtros del reporte son una tira', str_contains($n->cuerpo, 'class="barra-filtros"'));
ok('y las cifras van compactas', str_contains($n->cuerpo, 'cifra--compacta'));
$n->ir('/pedidos');
ok('la lista de pedidos ofrece «Emitir comprobante»', str_contains($n->cuerpo, 'Emitir comprobante'));
ok('y ya no «Datos para facturar»', !str_contains($n->cuerpo, 'Datos para facturar'));
$n->ir('/pagos/por-validar');
ok('la bandeja también', !str_contains($n->cuerpo, 'DATOS PARA FACTURAR'));

grupo('Parche 2x · el repaso visual');

$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion');
/* Las secciones que todavía no existen no llevan flecha: con ella se leían
   como pantallas que no abren. */
ok('hay secciones que todavía no existen', str_contains($n->cuerpo, 'Pronto'));
/* Ninguna fila junta «Pronto» y la flecha: la flecha promete que abre. */
preg_match_all('~<(a|div) class="fila".*?</\1>~su', $n->cuerpo, $filas_cfg);
$pronto_con_flecha = 0;
foreach ($filas_cfg[0] as $f_cfg) {
    if (str_contains($f_cfg, 'Pronto') && str_contains($f_cfg, 'm9 6 6 6-6 6')) $pronto_con_flecha++;
}
ok('y ninguna «Pronto» lleva flecha', $pronto_con_flecha === 0, $pronto_con_flecha . ' con flecha');
$n->ir('/usuarios');
ok('en Usuarios, «Editar» es el chip con borde',
   (bool) preg_match('~class="chip chip--linea"[^>]*>Editar~', $n->cuerpo));
ok('y Apagar y Nueva contraseña van discretos',
   substr_count($n->cuerpo, 'chip chip--suave') >= 2
   && (bool) preg_match('~class="chip chip--suave"[^>]*>Nueva contraseña~', $n->cuerpo));
$n->ir('/pedidos');
ok('los filtros van por grupos', str_contains($n->cuerpo, 'filtros__sep'));
ok('y ya no con espaciadores a mano', !str_contains($n->cuerpo, 'style="width:10px"'));
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/mas');
/* Facturación no tiene secciones sueltas: sin esto le salía una tarjeta
   blanca vacía entre su perfil y los ajustes. */
ok('«Más» no pinta tarjetas vacías',
   !preg_match('~<div class="tarjeta">\s*</div>~', $n->cuerpo));

grupo('Módulo 3a · el catálogo, desde la pantalla');

$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/stock');
es('la sección Stock ya abre', 200, $n->codigo);
ok('y lleva también a la pre venta', str_contains($n->cuerpo, 'id="menu-preventa"'));

/* Las categorías vienen de la web (3f). */
q("DELETE FROM ajustes WHERE clave = 'tienda_categorias'");
q("INSERT INTO ajustes (clave, valor, tipo, grupo, descripcion, tecnico) VALUES ('tienda_categorias', ?, 'texto', 'stock', '', 1)",
  [json_encode(['Sillas', 'Mesas'])]);
$n->ir('/stock/producto');
ok('LA CATEGORÍA SE ELIGE DE UNA LISTA (la de la web)', (bool) preg_match('~<select name="categoria" id="campo-categoria"[^>]*>.*?<option value="Mesas"~s', $n->cuerpo));
$n->ir('/stock/producto', ['_t' => $n->testigo(), 'accion' => 'producto', 'nombre' => 'Con inventada', 'categoria' => 'Inventada', 'activo' => '1']);
ok('y una escrita a mano no entra', str_contains($n->cuerpo, 'Elige una categoría de la lista')
   && !valor("SELECT 1 FROM productos WHERE nombre = 'Con inventada'"));
$n->ir('/stock/producto');
$n->ir('/stock/producto', ['_t' => $n->testigo(), 'accion' => 'producto',
                           'nombre' => 'Silla Gamer Waka', 'categoria' => 'Sillas',
                           'activo' => '1']);
$PROD_W = (int) valor("SELECT id FROM productos WHERE nombre = 'Silla Gamer Waka'");
$G12_W = (int) valor("SELECT li.id FROM lista_items li JOIN listas l ON l.id = li.lista_id
                       WHERE l.clave = 'garantias' AND li.valor LIKE '12 %' LIMIT 1");
ok('el producto se crea desde la pantalla', $PROD_W > 0);
ok('y lleva a su ficha', str_contains($n->cuerpo, 'Precio por cantidad'));
ok('que avisa de que falta el precio', str_contains($n->cuerpo, 'Falta el precio'));

$n->ir('/stock/producto?id=' . $PROD_W);
$n->ir('/stock/producto', ['_t' => $n->testigo(), 'accion' => 'precios', 'id' => $PROD_W,
                           't_desde' => ['1', '10'], 't_hasta' => ['9', ''],
                           't_precio' => ['500', '450'], 't_alias' => ['', 'Por 10']]);
es('los precios se guardan', 50000, precio_de_variante($PROD_W, null, 1));
es('y el tramo de diez también', 45000, precio_de_variante($PROD_W, null, 12));

$n->ir('/stock/producto?id=' . $PROD_W);
/* 3f: los colores ya no se añaden desde la pantalla (llegan de la web). */
ok('LA FICHA NO OFRECE AÑADIR COLORES: dice que se crean en la web', str_contains($n->cuerpo, 'id="nota-colores-web"')
   && !str_contains($n->cuerpo, 'Añadir modelo o color'));
$n->ir('/stock/producto', ['_t' => $n->testigo(), 'accion' => 'variante', 'id' => $PROD_W,
                           'color' => 'Negra', 'medida' => '']);
ok('y a mano tampoco entra', str_contains($n->cuerpo, 'se crean en la web')
   && !valor("SELECT 1 FROM variantes WHERE producto_id = ? AND color = 'Negra'", [$PROD_W]));
/* El color de estas pruebas se crea como lo crea la lectura de la tienda. */
variante_guardar($PROD_W, ['color' => 'Negra', 'medida' => '']);
$VAR_W = (int) valor('SELECT id FROM variantes WHERE producto_id = ? ORDER BY id DESC LIMIT 1', [$PROD_W]);
ok('el color existe', $VAR_W > 0);

/* El asesor no crea productos: los mira. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/stock');
es('el asesor entra al catálogo', 200, $n->codigo);
ok('pero no ve el botón de crear', !str_contains($n->cuerpo, 'NUEVO PRODUCTO'));
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'producto', 'nombre' => 'Mío']);
es('y si lo intenta, 403', 403, $n->codigo);
es('no se creó nada', 0, (int) valor("SELECT COUNT(*) FROM productos WHERE nombre = 'Mío'"));

/* El buscador que usa el formulario del pedido. */
$n->ir('/stock/buscar?q=silla');
$j_cat = json_decode($n->cuerpo, true);
ok('el buscador contesta', is_array($j_cat) && isset($j_cat['r']));
es('y trae la silla', 'Silla Gamer Waka', $j_cat['r'][0]['nombre'] ?? '');
es('con sus dos tramos', 2, count($j_cat['r'][0]['tramos'] ?? []));

/* Y la venta con un producto del catálogo: el precio lo pone el servidor. */
$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', array_merge($base, [
    '_t' => $n->testigo(), 'destino' => 'oficina',
    'l_desc' => ['Lo que sea que escriba'], 'l_producto' => [(string)$PROD_W],
    'l_variante' => [(string)$VAR_W], 'l_sku' => [''], 'l_cant' => ['12'],
    /* Manda un precio de regalo: el servidor tiene que ignorarlo. */
    'l_precio' => ['1.00'], 'modo_pago' => 'nada',
]));
$ped_cat = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
$lin_cat = una('SELECT * FROM pedido_lineas WHERE pedido_id = ?', [(int)$ped_cat['id']]);
es('la línea queda enlazada al producto', $PROD_W, (int)$lin_cat['producto_id']);
es('y a su color', $VAR_W, (int)$lin_cat['variante_id']);
es('el nombre es el del catálogo, no lo que se escribió', 'Silla Gamer Waka',
   (string)$lin_cat['descripcion']);
es('el precio lo pone el catálogo, no el navegador', 45000, (int)$lin_cat['precio_unit_centimos']);
es('y no está marcada como fuera de catálogo', 0, (int)$lin_cat['fuera_catalogo']);

/* Mientras el catálogo se llena, lo escrito a mano se permite y se marca. */
$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', array_merge($base, ['_t' => $n->testigo(), 'destino' => 'oficina',
       'l_desc' => ['Un producto que no está cargado'], 'l_cant' => ['1'], 'l_precio' => ['300'],
       'modo_pago' => 'completo', 'pago_monto' => '300.00']));
$lin_libre = una('SELECT l.* FROM pedido_lineas l ORDER BY l.id DESC LIMIT 1');
es('la venta a mano se registra igual', 'Un producto que no está cargado', (string)$lin_libre['descripcion']);
es('y queda marcada como fuera de catálogo', 1, (int)$lin_libre['fuera_catalogo']);

/* Con el catálogo cerrado desde Configuración, ya no. */
q("UPDATE ajustes SET valor = '0' WHERE clave = 'catalogo_libre'");
$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', array_merge($base, ['_t' => $n->testigo(), 'destino' => 'oficina',
       'l_desc' => ['Otro que tampoco está'], 'l_cant' => ['1'], 'l_precio' => ['300'],
       'modo_pago' => 'completo', 'pago_monto' => '300.00']));
ok('con el catálogo cerrado, se pide elegirlo de la lista',
   str_contains($n->cuerpo, 'del catálogo'));
es('y no se registró', 0, (int) valor("SELECT COUNT(*) FROM pedido_lineas WHERE descripcion = 'Otro que tampoco está'"));
q("UPDATE ajustes SET valor = '1' WHERE clave = 'catalogo_libre'");

grupo('Módulo 3a · lo que encontró la auditoría');

/* 1 · La venta sigue quedando a nombre de quien la registra. Se comprueba
   SIEMPRE, no solo con catálogo: la línea del catálogo pisaba la variable del
   usuario y todos los pedidos nacían sin asesor y sin oficina. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', array_merge($base, ['_t' => $n->testigo(), 'destino' => 'oficina',
       'l_desc' => ['Algo suelto'], 'l_cant' => ['1'], 'l_precio' => ['200'],
       'modo_pago' => 'completo', 'pago_monto' => '200.00']));
$ped_q = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
$id_asesor = (int) valor('SELECT id FROM usuarios WHERE email = ?', ['asesor@waka.test']);
es('el pedido queda a nombre de quien lo registró', $id_asesor, (int)$ped_q['registrado_por']);
/* Y la línea de tiempo dice quién lo registró: con la variable pisada salía
   «Pedido registrado por » y ahí se acababa la frase. */
$ev_q = (string) valor('SELECT texto FROM pedido_eventos WHERE pedido_id = ? ORDER BY id LIMIT 1',
                       [(int)$ped_q['id']]);
ok('y su primera línea de tiempo dice quién fue',
   trim($ev_q) !== '' && !str_ends_with(trim($ev_q), 'por'), $ev_q);

/* 2 · La garantía la trae el producto mientras el asesor no elija otra. */
$G6_W = (int) valor("SELECT li.id FROM lista_items li JOIN listas l ON l.id = li.lista_id
                      WHERE l.clave = 'garantias' AND li.valor LIKE '6 %' LIMIT 1");
ok('hay una garantía de 6 meses en la lista', $G6_W > 0);
q('UPDATE productos SET garantia_item_id = ? WHERE id = ?', [$G6_W, $PROD_W]);
$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', array_merge($base, ['_t' => $n->testigo(), 'destino' => 'oficina',
       'l_desc' => ['Silla'], 'l_producto' => [(string)$PROD_W], 'l_variante' => [(string)$VAR_W],
       'l_cant' => ['1'], 'l_precio' => ['500'], 'modo_pago' => 'completo', 'pago_monto' => '500.00',
       'garantia_item_id' => (string)$G12_W, 'garantia_auto' => '1']));
$ped_g = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
es('la venta hereda la garantía del producto', $G6_W, (int)$ped_g['garantia_item_id']);

/* Y si el asesor la eligió él, manda él. */
$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', array_merge($base, ['_t' => $n->testigo(), 'destino' => 'oficina',
       'l_desc' => ['Silla'], 'l_producto' => [(string)$PROD_W], 'l_variante' => [(string)$VAR_W],
       'l_cant' => ['1'], 'l_precio' => ['500'], 'modo_pago' => 'completo', 'pago_monto' => '500.00',
       'garantia_item_id' => (string)$G12_W, 'garantia_auto' => '0']));
$ped_g2 = una('SELECT * FROM pedidos ORDER BY id DESC LIMIT 1');
es('si la eligió el asesor, manda él', $G12_W, (int)$ped_g2['garantia_item_id']);

/* 3 · El color es obligatorio cuando el producto tiene colores. */
$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', array_merge($base, ['_t' => $n->testigo(), 'destino' => 'oficina',
       'l_desc' => ['Silla'], 'l_producto' => [(string)$PROD_W], 'l_variante' => [''],
       'l_cant' => ['1'], 'l_precio' => ['500'], 'modo_pago' => 'completo', 'pago_monto' => '500.00']));
ok('sin color, la venta no se registra', str_contains($n->cuerpo, 'Elige el modelo o color'));

/* 4 · Un producto apagado no cae a texto libre con el precio del navegador. */
q('UPDATE productos SET activo = 0 WHERE id = ?', [$PROD_W]);
$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', array_merge($base, ['_t' => $n->testigo(), 'destino' => 'oficina',
       'l_desc' => ['Silla'], 'l_producto' => [(string)$PROD_W], 'l_variante' => [(string)$VAR_W],
       'l_cant' => ['1'], 'l_precio' => ['1.00'], 'modo_pago' => 'completo', 'pago_monto' => '1.00']));
ok('un producto apagado para la venta', str_contains($n->cuerpo, 'ya no está disponible'));
es('y no se coló a S/ 1.00', 0,
   (int) valor("SELECT COUNT(*) FROM pedido_lineas WHERE precio_unit_centimos = 100"));
q('UPDATE productos SET activo = 1 WHERE id = ?', [$PROD_W]);

/* 5 · El color con precio propio: lo que se guarda es SU precio. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/stock/producto?id=' . $PROD_W);
precios_guardar($PROD_W, $VAR_W, [['desde' => 1, 'hasta' => '', 'precio' => '900']]);
$n->ir('/stock/buscar?q=silla');
$j_var = json_decode($n->cuerpo, true);
$tr_var = $j_var['r'][0]['variantes'][0]['tramos'] ?? [];
es('el buscador manda los tramos del color', 90000, (int)($tr_var[0]['precio'] ?? 0));

/* 6 · Apagar un color sin decir cuál no revienta ni toca el de otro producto. */
$n->ir('/stock/producto?id=' . $PROD_W);
$n->ir('/stock/producto', ['_t' => $n->testigo(), 'accion' => 'variante_estado', 'id' => $PROD_W]);
ok('un POST sin variante no rompe la pantalla', $n->codigo === 200);
es('y el color sigue encendido', 1, (int) valor('SELECT activo FROM variantes WHERE id = ?', [$VAR_W]));
/* Ni se puede apagar el color de OTRO producto mandando su id a mano. */
$OTRO_P = producto_guardar(null, ['nombre' => 'Producto de al lado', 'activo' => 1])['id'];
variante_guardar((int)$OTRO_P, ['color' => 'Azul', 'medida' => '']);
$OTRA_V = (int) valor('SELECT id FROM variantes WHERE producto_id = ? ORDER BY id DESC LIMIT 1', [(int)$OTRO_P]);
$n->ir('/stock/producto?id=' . $PROD_W);
$n->ir('/stock/producto', ['_t' => $n->testigo(), 'accion' => 'variante_estado', 'id' => $PROD_W,
                           'variante_id' => (string)$OTRA_V, 'encender' => '0']);
es('el color de otro producto no se apaga desde aquí', 1,
   (int) valor('SELECT activo FROM variantes WHERE id = ?', [$OTRA_V]));

/* 7 · El catálogo es de un país. */
q('UPDATE productos SET pais_id = 999 WHERE id = ?', [$PROD_W]);
$n->ir('/stock/buscar?q=silla');
$j_otro = json_decode($n->cuerpo, true);
es('un producto de otro país no se ofrece', 0, count($j_otro['r'] ?? []));
$n->ir('/stock/producto?id=' . $PROD_W);
es('ni se puede abrir su ficha', 404, $n->codigo);
q('UPDATE productos SET pais_id = NULL WHERE id = ?', [$PROD_W]);

grupo('Versión 3a.1 · lo que pidió el usuario el 23/09');

$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');

/* 1 · En la lista de pedidos se ve QUÉ lleva cada venta. */
$n->ir('/pedidos');
ok('la lista dice qué lleva cada venta', str_contains($n->cuerpo, 'Qué lleva'));
$una_desc = (string) valor('SELECT descripcion FROM pedido_lineas ORDER BY id DESC LIMIT 1');
ok('con el nombre del producto', $una_desc !== '' && str_contains($n->cuerpo, e($una_desc)), $una_desc);

/* 2 · Filtros por asesor y por estado, y los demás plegados. */
ok('los filtros de más van plegados', str_contains($n->cuerpo, 'class="desplegable filtros-mas"'));
ok('y se puede filtrar por asesor', str_contains($n->cuerpo, 'name="a"'));
ok('y por estado', str_contains($n->cuerpo, 'name="es"'));
$id_as = (int) valor('SELECT id FROM usuarios WHERE email = ?', ['asesor@waka.test']);
$n->ir('/pedidos?a=' . $id_as);
$otros = (int) valor('SELECT COUNT(*) FROM pedidos WHERE asesor_id <> ?', [$id_as]);
ok('hay pedidos de otros asesores con los que comparar', $otros > 0);
$cod_otro = (string) valor('SELECT codigo FROM pedidos WHERE asesor_id <> ? ORDER BY id DESC LIMIT 1', [$id_as]);
ok('filtrando por asesor no salen los de otros', !str_contains($n->cuerpo, '>' . $cod_otro . '<'), $cod_otro);
$n->ir('/pedidos?es=anulado');
$cod_vivo = (string) valor("SELECT p.codigo FROM pedidos p JOIN pedido_estados e ON e.id = p.estado_id
                             WHERE e.clave <> 'anulado' ORDER BY p.id DESC LIMIT 1");
ok('filtrando por estado, solo ese estado', !str_contains($n->cuerpo, '>' . $cod_vivo . '<'), $cod_vivo);

/* 3 · Los pagos validados, con su filtro, y el recién validado resaltado. */
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/por-validar');
ok('la bandeja enseña lo ya validado', str_contains($n->cuerpo, '<h2>Validados'));
ok('con filtro de fechas', str_contains($n->cuerpo, 'name="vd"'));
ok('de asesor', str_contains($n->cuerpo, 'name="va"'));
ok('y de comprobante', str_contains($n->cuerpo, 'name="vc"'));
$pg_val = una("SELECT pg.* FROM pagos pg WHERE pg.verificado = 1 AND pg.anulado = 0 ORDER BY pg.id DESC LIMIT 1");
ok('hay algún pago validado', (bool)$pg_val);
q('UPDATE pagos SET verificado_en = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int)$pg_val['id']]);
$n->ir('/pagos/por-validar?val=' . (int)$pg_val['id']);
ok('el que se acaba de validar sale resaltado', str_contains($n->cuerpo, 'fila--recien'));
$n->ir('/pagos/por-validar?vd=2000-01-01&vh=2000-01-02');
ok('y con otras fechas, no hay nada', str_contains($n->cuerpo, 'Ningún pago validado'));

/* 4 · La acción pendiente, en grande y arriba de la ficha. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$P_ACC = (int) valor('SELECT pe.id FROM pedidos pe
                       WHERE pe.anulado_en IS NULL AND pe.despacho_veces = 0
                         AND pe.asesor_id = (SELECT id FROM usuarios WHERE email = ?)
                         AND ' . sql_despacho_listo() . ' ORDER BY pe.id DESC LIMIT 1',
                     ['asesor@waka.test']);
ok('hay una venta lista para mandar', $P_ACC > 0);
$n->ir('/pedidos/ficha?id=' . $P_ACC);
ok('la ficha lo dice arriba y en grande', str_contains($n->cuerpo, 'class="accion-ahora'));
ok('y dice qué hacer', str_contains($n->cuerpo, 'Manda este pedido a despacho ahora'));
$P_ANU = (int) valor("SELECT id FROM pedidos WHERE anulado_en IS NOT NULL ORDER BY id DESC LIMIT 1");
if ($P_ANU) {
    $n->salir();
    $n->entrar('admin@waka.test', 'Clave-Larga-1');
    $n->ir('/pedidos/ficha?id=' . $P_ANU);
    ok('una venta anulada no pide nada', !str_contains($n->cuerpo, 'class="accion-ahora'));
}

/* 5 · La fecha de nacimiento del equipo. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/usuarios/nuevo');
ok('el alta pide la fecha de nacimiento', str_contains($n->cuerpo, 'name="fecha_nacimiento"'));
$ROL_AS = (int) valor("SELECT id FROM roles WHERE clave = 'asesor'");
$PAIS_1 = (int) valor('SELECT id FROM paises ORDER BY id LIMIT 1');
$n->ir('/usuarios/nuevo', ['_t' => $n->testigo(), 'nombre' => 'Cumple', 'apellidos' => 'Prueba',
       'email' => 'cumple@waka.test', 'rol_id' => $ROL_AS, 'pais_id' => $PAIS_1,
       'fecha_nacimiento' => '1990-05-04', 'ambito' => 'propio']);
es('se guarda el cumpleaños', '1990-05-04',
   (string) valor('SELECT fecha_nacimiento FROM usuarios WHERE email = ?', ['cumple@waka.test']));
$n->ir('/usuarios/nuevo');
$n->ir('/usuarios/nuevo', ['_t' => $n->testigo(), 'nombre' => 'Futuro', 'apellidos' => 'Prueba',
       'email' => 'futuro@waka.test', 'rol_id' => $ROL_AS, 'pais_id' => $PAIS_1,
       'fecha_nacimiento' => date('Y-m-d', strtotime('+2 days')), 'ambito' => 'propio']);
ok('una fecha de nacimiento futura se rechaza', str_contains($n->cuerpo, 'no parece real'));

/* 7 · Lo que encontró la auditoría del 3a.1. */
$n->salir();
$n->entrar('lider@waka.test', 'Clave-Larga-1');
$P_OTRO = (int) valor("SELECT pe.id FROM pedidos pe
                        WHERE pe.anulado_en IS NULL AND pe.despacho_veces = 0
                          AND pe.asesor_id <> (SELECT id FROM usuarios WHERE email = ?)
                          AND pe.asesor_id IN (SELECT id FROM usuarios WHERE equipo_id =
                                (SELECT equipo_id FROM usuarios WHERE email = ?))
                          AND " . sql_despacho_listo() . " ORDER BY pe.id DESC LIMIT 1",
                      ['lider@waka.test', 'lider@waka.test']);
if ($P_OTRO) {
    $n->ir('/pedidos/ficha?id=' . $P_OTRO);
    ok('a quien solo mira no se le manda hacer nada',
       !str_contains($n->cuerpo, 'Manda este pedido a despacho ahora'));
}

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
/* Una devolución también queda «verificada»: no es un pago validado. */
$PG_DEV = (int) valor("SELECT id FROM pagos WHERE tipo = 'devolucion' AND anulado = 0 ORDER BY id DESC LIMIT 1");
if ($PG_DEV) {
    q('UPDATE pagos SET verificado = 1, verificado_en = ? WHERE id = ?', [date('Y-m-d H:i:s'), $PG_DEV]);
    $n->ir('/pagos/por-validar');
    ok('las devoluciones no se cuelan entre los validados',
       !str_contains($n->cuerpo, 'S/ -'), 'salió un monto en negativo');
}
/* Y la fecha que se enseña es la de la validación, que es por la que filtra. */
$n->ir('/pagos/por-validar');
ok('la columna dice «Validado»', str_contains($n->cuerpo, '<th>Validado</th>'));

/* Con un filtro puesto, el vacío no dice «todavía no hay pedidos». */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos?a=999999');
ok('con un filtro sin resultados se dice que no coincide',
   str_contains($n->cuerpo, 'Ningún pedido coincide'));

/* El cumpleaños del equipo sale en el Inicio el día que toca. */
q("UPDATE usuarios SET fecha_nacimiento = ? WHERE email = ?",
  [date('1992-m-d'), 'asesor@waka.test']);
$n->ir('/inicio');
ok('el Inicio saluda a quien cumple años hoy', str_contains($n->cuerpo, 'Hoy cumple años'));
q("UPDATE usuarios SET fecha_nacimiento = NULL WHERE email = ?", ['asesor@waka.test']);
$n->ir('/inicio');
ok('y el resto de los días no dice nada', !str_contains($n->cuerpo, 'Hoy cumple años'));

/* 6 · El logo lleva al inicio. */
$n->ir('/pedidos');
ok('el logo lleva al inicio', (bool) preg_match('~class="lateral__inicio" href="[^"]*/inicio"~', $n->cuerpo));

/* ═══════════════════════  MÓDULO 3b · LA TIENDA  ═══════════════════════ */
grupo('Módulo 3b · Configuración › Tienda');

/* La tienda de mentira: el servidor de pruebas la carga si existe este
   archivo (pruebas/inyectar.php), con el puerto en el nombre para que dos
   servidores no se pisen. Se borra al terminar pase lo que pase. */
$TIENDA_ARCHIVO = sys_get_temp_dir() . '/waka-tienda-falsa-' . (int)(getenv('WAKA_PUERTO') ?: 8123) . '.json';
register_shutdown_function(fn() => @unlink($TIENDA_ARCHIVO));
$CK = 'ck_' . str_repeat('7b', 20);
$CS = 'cs_' . str_repeat('3e', 18) . 'K4W8';
$tienda_datos = fn(array $productos, array $variaciones = [], array $extra = []) => file_put_contents($TIENDA_ARCHIVO, json_encode([
    'productos' => $productos, 'variaciones' => $variaciones,
    'key' => $CK, 'secret' => $CS, 'modos' => ['basica'], 'llamadas' => [],
] + $extra));
$tienda_datos([]);
$aj = fn(string $k) => (string) valor('SELECT valor FROM ajustes WHERE clave = ?', [$k]);
/* Los avisos de un momento («Guardado.», los errores): son los únicos
   .aviso sin más atributos. Los de la pantalla llevan id o estilo. */
$flash = fn() => preg_match_all('~<div class="aviso aviso--(?:rojo|verde|gris)">\s*([^<]*?)\s*</div>~u', $n->cuerpo, $m)
                 ? implode(' | ', $m[1]) : '';
$destino = function () use ($n): string {
    foreach ($n->cabeceras as $h) if (preg_match('/^Location:\s*(.+)$/i', $h, $m)) return trim($m[1]);
    return '';
};

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion/tienda');
ok('el asesor no abre la pantalla de la tienda', $n->codigo === 403);
$n->ir('/configuracion/tienda', ['_t' => $n->testigoValido(), 'accion' => 'guardar',
                                 'ruta' => 'https://malo.test', 'key' => $CK, 'secret' => $CS]);
ok('ni guarda claves', $n->codigo === 403);
ok('y no se guardó nada', $aj('woo_secret') !== $CS && $aj('woo_url') !== 'https://malo.test');
$n->ir('/stock/tienda');
ok('ni trae el catálogo', $n->codigo === 403);
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'leer']);
ok('ni aunque mande el formulario a mano', $n->codigo === 403);
$n->ir('/stock');
ok('en el catálogo no le sale el botón de traer', $n->codigo === 200 && !str_contains($n->cuerpo, 'id="btn-tienda"'));

$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/stock/tienda');
ok('facturación tampoco', $n->codigo === 403);

$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion');
ok('Configuración lleva a Tienda', (bool) preg_match('~href="[^"]*/configuracion/tienda"~', $n->cuerpo));
$n->ir('/configuracion/tienda');
ok('la pantalla se abre', $n->codigo === 200);
ok('con un solo título', substr_count($n->cuerpo, '<h1') === 1);
ok('sin conectar lo dice', str_contains($n->cuerpo, 'id="tienda-estado">Sin conectar'));
ok('y sin datos no ofrece probar', !str_contains($n->cuerpo, 'id="form-probar"'));
ok('lleva a traer la tienda a quien puede', (bool) preg_match('~href="[^"]*/stock/tienda"~', $n->cuerpo));

$guardar_t = fn(array $d) => $n->ir('/configuracion/tienda', array_merge(
    ['_t' => $n->testigo(), 'accion' => 'guardar', 'ruta' => 'https://compraenwaka.test/', 'key' => $CK, 'secret' => $CS], $d));
$foto = fn() => [$aj('woo_url'), $aj('woo_key'), $aj('woo_secret')];
$antes_t = $foto();
$guardar_t(['secret' => 'secreta-sin-prefijo']);
ok('una clave secreta que no empieza por cs_ se rechaza', str_contains($n->cuerpo, 'empieza por cs_'));
es('y no se guarda NADA, ni la dirección ni la otra clave', $antes_t, $foto());
$n->ir('/configuracion/tienda');
$guardar_t(['key' => 'ck_corta']);
ok('una clave del cliente que no vale, igual', str_contains($n->cuerpo, 'empieza por ck_'));
es('sin guardar nada', $antes_t, $foto());
$n->ir('/configuracion/tienda');
$guardar_t(['ruta' => 'http://compraenwaka.test']);
ok('una dirección sin https tampoco', str_contains($n->cuerpo, 'empieza por https://'));
es('sin guardar nada', $antes_t, $foto());
$n->ir('/configuracion/tienda');
$guardar_t([]);
es('con datos buenos se guardan los tres', ['https://compraenwaka.test', $CK, $CS], $foto());
ok('y lo dice', $flash() === 'Guardado.', $flash());
es('la tienda queda como del país de quien la puso',
   (string)(int) valor("SELECT pais_id FROM usuarios WHERE email = 'admin@waka.test'"), $aj('woo_pais'));
$n->ir('/configuracion/tienda');
ok('LA CLAVE SECRETA NO SE VUELVE A ENSEÑAR', !str_contains($n->cuerpo, $CS));
ok('ni un trozo largo de ella', !str_contains($n->cuerpo, substr($CS, 3, 20)));
ok('solo sus cuatro últimos caracteres', str_contains($n->cuerpo, '•••• K4W8'));
ok('y ahora se puede probar', str_contains($n->cuerpo, 'id="form-probar"'));
ok('todavía sin probar', str_contains($n->cuerpo, 'id="tienda-estado">Sin probar'));
$guardar_t(['secret' => '']);
es('dejar la secreta vacía conserva la que había', $CS, $aj('woo_secret'));
$n->ir('/configuracion/tienda');
$guardar_t(['key' => '']);
es('dejar la clave del cliente vacía conserva la que había', $CK, $aj('woo_key'));
$n->ir('/configuracion/tienda');
$guardar_t(['ruta' => '']);
es('y la dirección vacía conserva la que había', 'https://compraenwaka.test', $aj('woo_url'));

$n->ir('/configuracion/tienda', ['_t' => $n->testigo(), 'accion' => 'probar']);
ok('probar la conexión contesta que está conectada', str_contains($flash(), 'Conectada con la tienda'), $flash());
ok('y el estado pasa a Conectada', str_contains($n->cuerpo, 'id="tienda-estado">Conectada'));
$guardar_t([]);
$n->ir('/configuracion/tienda');
ok('guardar sin cambiar nada no la deja «Sin probar»', str_contains($n->cuerpo, 'id="tienda-estado">Conectada'));

/* LA CLAVE NO VIAJA A OTRA DIRECCIÓN. Cambiar solo la dirección y probar
   mandaba la secreta guardada a esa otra web (auditoría del 3b). */
$guardar_t(['ruta' => 'https://otra-web.test', 'key' => '', 'secret' => '']);
ok('cambiar la dirección sin pegar las claves se rechaza', str_contains($n->cuerpo, 'pega otra vez las dos claves'));
es('y se queda la dirección de antes', 'https://compraenwaka.test', $aj('woo_url'));
$n->ir('/configuracion/tienda');
$guardar_t(['ruta' => 'https://otra-web.test', 'secret' => '']);
ok('tampoco con una sola', str_contains($n->cuerpo, 'pega otra vez las dos claves'));
$n->ir('/configuracion/tienda');
$guardar_t(['ruta' => 'https://otra-web.test']);
es('con las dos claves, sí', 'https://otra-web.test', $aj('woo_url'));
$n->ir('/configuracion/tienda');
ok('y vuelve a «Sin probar»', str_contains($n->cuerpo, 'id="tienda-estado">Sin probar'));
$guardar_t([]);
$n->ir('/configuracion/tienda', ['_t' => $n->testigo(), 'accion' => 'probar']);

/* LA TIENDA ES DEL PAÍS QUE LA CONECTÓ. Un admin de México abre la pantalla
   y pulsa GUARDAR sin tocar nada: antes se quedaba con la tienda. */
if (!valor("SELECT id FROM usuarios WHERE email = 'adminmx@waka.test'")) {
    insertar('usuarios', ['pais_id' => (int) valor("SELECT id FROM paises WHERE codigo = 'MX'"),
        'rol_id' => (int) valor("SELECT id FROM roles WHERE clave = 'administracion'"),
        'nombre' => 'Cuenta', 'apellidos' => 'De Prueba', 'email' => 'adminmx@waka.test',
        'password_hash' => password_hash('Clave-Larga-1', PASSWORD_DEFAULT), 'ambito' => 'todo',
        'activo' => 1, 'debe_cambiar_password' => 0]);
}
ok('la cuenta de México es de administración de México', (int) valor(
   "SELECT u.pais_id FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.email = 'adminmx@waka.test' AND r.clave = 'administracion'")
   === (int) valor("SELECT id FROM paises WHERE codigo = 'MX'"));
$PAIS_TIENDA = $aj('woo_pais');
$n->salir();
$n->entrar('adminmx@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion/tienda');
ok('desde otro país, la pantalla dice de quién es la tienda', str_contains($n->cuerpo, 'id="aviso-otro-pais"'));
ok('y no ofrece guardar ni probar', !str_contains($n->cuerpo, '>GUARDAR<') && !str_contains($n->cuerpo, 'id="form-probar"'));
$poco_antes = (string) valor("SELECT valor FROM ajustes WHERE clave = 'stock_poco'");
$n->ir('/configuracion/tienda', ['_t' => $n->testigoValido(), 'accion' => 'poco', 'stock_poco' => '77']);
es('ni deja cambiar «quedan pocas» desde otro país', $poco_antes, (string) valor("SELECT valor FROM ajustes WHERE clave = 'stock_poco'"));
$n->ir('/configuracion/tienda', ['_t' => $n->testigoValido(), 'accion' => 'guardar',
       'ruta' => 'https://compraenwaka.test', 'key' => $CK, 'secret' => '']);
ok('mandar el formulario tal cual no se queda con la tienda', $aj('woo_pais') === $PAIS_TIENDA, $aj('woo_pais'));
ok('y lo dice', str_contains($n->cuerpo, 'solo se cambia desde allí'));
$n->ir('/configuracion/tienda', ['_t' => $n->testigoValido(), 'accion' => 'probar']);
ok('tampoco prueba la conexión desde allí', str_contains($flash(), 'otro país'), $flash());
$n->ir('/stock/tienda');
ok('ni trae el catálogo', !str_contains($n->cuerpo, 'id="form-leer"'));
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion/tienda');
ok('en su país dice que la tienda es suya', str_contains($n->cuerpo, 'id="pais-tienda">La tienda es de Perú'));
$guardar_t([]);
es('guardar sin cambiar nada no le cambia el país', $PAIS_TIENDA, $aj('woo_pais'));

/* Sin dirección guardada pero con la clave secreta: poner una dirección
   también pide las dos claves. */
q("UPDATE ajustes SET valor = '' WHERE clave = 'woo_url'");
$n->ir('/configuracion/tienda');
$guardar_t(['ruta' => 'https://otra-web.test', 'key' => '', 'secret' => '']);
ok('sin dirección de antes, igual pide las dos claves', str_contains($n->cuerpo, 'pega otra vez las dos claves'));
es('y no guarda la dirección', '', $aj('woo_url'));
$n->ir('/configuracion/tienda');
$guardar_t([]);
$n->ir('/configuracion/tienda', ['_t' => $n->testigo(), 'accion' => 'probar']);

/* Cambiar una clave borra la prueba; una prueba que falla, también. */
$CS2 = 'cs_' . str_repeat('5d', 18) . 'P2P2';
$guardar_t(['secret' => $CS2]);
$n->ir('/configuracion/tienda');
ok('al cambiar la clave vuelve a «Sin probar»', str_contains($n->cuerpo, 'id="tienda-estado">Sin probar'));
$n->ir('/configuracion/tienda', ['_t' => $n->testigo(), 'accion' => 'probar']);
ok('con una clave que la tienda no acepta, lo dice', str_contains($flash(), 'no aceptó las claves'), $flash());
ok('y no dice «Conectada»', !str_contains($n->cuerpo, 'id="tienda-estado">Conectada'));
$guardar_t(['secret' => $CS]);
$n->ir('/configuracion/tienda', ['_t' => $n->testigo(), 'accion' => 'probar']);

grupo('Módulo 3b · traer el catálogo, por la pantalla');

/* Uno que ya estaba aquí con su código y otro nombre (se une a la tienda),
   uno ya enlazado con otro nombre (se actualiza) y uno igual (sin cambios). */
$ya_aqui = insertar('productos', ['sku' => 'PRD-HTTP05', 'nombre' => 'Nombre de aquí', 'activo' => 1]);
$ya_enl  = insertar('productos', ['sku' => 'PRD-HTTP06', 'nombre' => 'Nombre viejo', 'woo_id' => 8106, 'activo' => 1]);
$ya_igual = insertar('productos', ['sku' => 'PRD-HTTP07', 'nombre' => 'Igual', 'woo_id' => 8107, 'activo' => 1]);
$tienda_datos([
    ['id' => 8101, 'name' => 'Aro de luz LED', 'sku' => 'COD: PRD-LC033', 'type' => 'simple', 'regular_price' => '89.90',
     'categories' => [['id' => 1, 'name' => 'Belleza', 'slug' => 'b']]],
    ['id' => 8102, 'name' => 'Carro &amp; remolque', 'sku' => 'PRD-HTTP02', 'type' => 'variable', 'categories' => []],
    ['id' => 8103, 'name' => 'Sin código', 'sku' => '', 'type' => 'simple', 'categories' => []],
    ['id' => 8105, 'name' => 'Nombre de la tienda', 'sku' => 'PRD-HTTP05', 'type' => 'simple', 'regular_price' => '45', 'categories' => []],
    ['id' => 8106, 'name' => 'Nombre nuevo', 'sku' => 'PRD-HTTP06', 'type' => 'simple', 'categories' => []],
    ['id' => 8107, 'name' => 'Igual', 'sku' => 'PRD-HTTP07', 'type' => 'simple', 'categories' => []],
], [8102 => [['id' => 8201, 'sku' => '', 'attributes' => [['id' => 0, 'name' => 'Color', 'option' => 'Rojo']]],
             ['id' => 8202, 'sku' => '', 'attributes' => [['id' => 0, 'name' => 'Color', 'option' => 'Negro']]]]]);

$n->ir('/stock');
ok('en el catálogo sale TRAER DE LA TIENDA', str_contains($n->cuerpo, 'id="btn-tienda"'));
$n->ir('/stock/tienda');
ok('la pantalla se abre', $n->codigo === 200 && substr_count($n->cuerpo, '<h1') === 1);
ok('y ofrece leer la tienda', str_contains($n->cuerpo, 'id="form-leer"'));
$n_prod_http = (int) valor('SELECT COUNT(*) FROM productos');
$n->ir('/stock/tienda', ['_t' => $n->testigo(), 'accion' => 'leer'], false);
$ir_a = $destino();
ok('leer lleva a la vista previa de esa lectura', (bool) preg_match('~/stock/tienda\?l=\d+$~', $ir_a), $ir_a);
es('LEER NO CREA NINGÚN PRODUCTO', $n_prod_http, (int) valor('SELECT COUNT(*) FROM productos'));
es('ni le cambia el nombre a nadie', 'Nombre viejo', (string) valor('SELECT nombre FROM productos WHERE id = ?', [$ya_enl]));
$URL_L = parse_url($ir_a, PHP_URL_PATH) . '?' . parse_url($ir_a, PHP_URL_QUERY);
$n->ir($URL_L);
$cifra = fn(string $k) => preg_match('~data-cifra="' . $k . '">\s*<span class="cifra__k">[^<]*</span>\s*<span class="cifra__v[^"]*">(\d+)</span>~',
                                     $n->cuerpo, $m) ? (int)$m[1] : -1;
es('la vista previa dice dos nuevos', 2, $cifra('crear'));
es('dos que se actualizan', 2, $cifra('actualizar'));
ok('y la cifra dice cuántos se unen por primera vez', (bool) preg_match('~data-cifra="actualizar">.*?1 se une por primera vez~s', $n->cuerpo));
es('uno sin cambios', 1, $cifra('igual'));
es('y uno que no entra', 1, $cifra('fuera'));
$lista = fn(string $id) => preg_match('~<details[^>]*id="' . $id . '".*?</details>~s', $n->cuerpo, $m) ? $m[0] : '';
ok('dice por qué no entra, en su lista', str_contains($lista('lista-fuera'), 'No tiene código en la tienda'));
ok('los nuevos llevan el código limpio', str_contains($lista('lista-nuevos'), 'PRD-LC033') && !str_contains($lista('lista-nuevos'), 'COD:'));
ok('el nombre se enseña limpio', str_contains($lista('lista-nuevos'), 'Carro &amp; remolque') && !str_contains($n->cuerpo, '&amp;amp;'));
ok('el que se actualiza dice qué cambia', str_contains($lista('lista-cambian'), 'Nombre: «Nombre viejo» → «Nombre nuevo»'));
ok('EL QUE SE UNE A LA TIENDA POR PRIMERA VEZ VA APARTE', str_contains($lista('lista-enlazan'), 'Nombre de la tienda')
   && str_contains($lista('lista-enlazan'), 'Nombre de aquí'));
ok('y no se mezcla con los que solo cambian', !str_contains($lista('lista-cambian'), 'PRD-HTTP05'));
ok('como estaba sin precio, dice que toma el de la web', str_contains($lista('lista-enlazan'), 'toma el de la web') && str_contains($lista('lista-enlazan'), 'S/ 45.00'));
ok('el que ya estaba enlazado y sin precio en la web no dice nada de precio', !str_contains($lista('lista-cambian'), 'toma el de la web'));
ok('y abierto, para mirarlo', (bool) preg_match('~<details[^>]*id="lista-enlazan"[^>]*open~', $n->cuerpo));
ok('el nuevo con precio en la web lo enseña', (bool) preg_match('~PRD-LC033.*?S/ 89\.90~s', $lista('lista-nuevos')));
ok('el que no tiene precio en la web lo dice', str_contains($lista('lista-nuevos'), 'Sin precio'));
ok('y el aviso cuenta cuántos entran sin precio', (bool) preg_match('~id="aviso-sin-precio">1 nuevo no tiene~', $n->cuerpo));
$LECT_HTTP = (int) $n->oculto('lectura');
ok('el botón de guardar lleva su lectura', $LECT_HTTP > 0 && str_contains($n->cuerpo, 'id="form-guardar"'));
ok('y pregunta con las cifras de verdad', str_contains($n->cuerpo, 'data-confirmar="Se guardan 2 nuevos y 2 actualizados. ¿Seguimos?"'));

/* Un asesor no guarda una lectura ajena ni aunque sepa el número. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'guardar', 'lectura' => $LECT_HTTP]);
ok('el asesor no puede guardarla', $n->codigo === 403);
es('y no se creó nada', $n_prod_http, (int) valor('SELECT COUNT(*) FROM productos'));

/* Si el catálogo cambia entre medias, la pantalla lo dice y no deja guardar. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
q("UPDATE productos SET nombre = 'Cambiado a mano' WHERE id = ?", [$ya_igual]);
$n->ir($URL_L);
ok('si el catálogo cambió, lo avisa', str_contains($n->cuerpo, 'id="aviso-cambio"'));
ok('y no ofrece guardar', !str_contains($n->cuerpo, 'id="form-guardar"'));
$n->ir('/stock/tienda', ['_t' => $n->testigo(), 'accion' => 'guardar', 'lectura' => $LECT_HTTP], false);
es('forzarlo a mano no guarda: vuelve a la lectura', '/stock/tienda?l=' . $LECT_HTTP, parse_url($destino(), PHP_URL_PATH) . '?' . parse_url($destino(), PHP_URL_QUERY));
es('sin crear nada', $n_prod_http, (int) valor('SELECT COUNT(*) FROM productos'));
$n->ir($URL_L);
ok('y dice por qué', str_contains($flash(), 'El catálogo cambió'), $flash());
q("UPDATE productos SET nombre = 'Igual' WHERE id = ?", [$ya_igual]);

$n->ir($URL_L);
ok('deshecho el cambio, vuelve a dejar guardar', str_contains($n->cuerpo, 'id="form-guardar"'));
$n->ir('/stock/tienda', ['_t' => $n->testigo(), 'accion' => 'guardar', 'lectura' => $LECT_HTTP], false);
ok('al guardar con nuevos lleva a los productos sin precio', str_ends_with($destino(), '/stock?v=sin_precio'), $destino());
es('se crearon los dos', $n_prod_http + 2, (int) valor('SELECT COUNT(*) FROM productos'));
$P_HTTP = (int) valor("SELECT id FROM productos WHERE sku = 'PRD-LC033'");
ok('el del «COD:» con el código limpio', $P_HTTP > 0);
es('el otro con sus dos colores', 2, (int) valor("SELECT COUNT(*) FROM variantes v JOIN productos p ON p.id = v.producto_id
                                                WHERE p.sku = 'PRD-HTTP02'"));
es('el que se unió, con el nombre de la tienda', 'Nombre de la tienda', (string) valor('SELECT nombre FROM productos WHERE id = ?', [$ya_aqui]));
$n->ir('/stock?v=sin_precio');
ok('dice qué se guardó', $flash() === 'Guardado en el catálogo: 2 productos nuevos, 2 actualizados, 2 colores o modelos nuevos, 1 con el precio de la web, 1 nuevo sin precio.', $flash());
es('el que estaba sin precio ya se vende a S/ 45', 4500, precio_de_variante($ya_aqui, null, 1));
es('el que traía precio ya se puede vender', 8990, precio_de_variante($P_HTTP_ARO = (int) valor("SELECT id FROM productos WHERE sku = 'PRD-LC033'"), null, 1));
ok('entre los que no tienen precio sale el que no lo traía', str_contains($n->cuerpo, 'PRD-HTTP02'));
ok('y no el que ya tiene el de la web', !str_contains($n->cuerpo, 'PRD-LC033'));
$n->ir('/stock');
ok('marcado como de la tienda', str_contains($n->cuerpo, 'PRD-LC033 · Belleza · en la tienda'));
$n->ir('/stock/producto?id=' . $P_HTTP);
ok('en su ficha dice que el nombre se cambia en la tienda', str_contains($n->cuerpo, 'id="nota-tienda"'));
$n->ir($URL_L);
ok('la lectura guardada ya no ofrece guardar', !str_contains($n->cuerpo, 'id="form-guardar"')
   && str_contains($n->cuerpo, 'id="aviso-guardada"'));
$n->ir('/stock/tienda', ['_t' => $n->testigo(), 'accion' => 'guardar', 'lectura' => $LECT_HTTP], false);
es('si se manda otra vez a mano, vuelve a la lectura', '/stock/tienda?l=' . $LECT_HTTP, parse_url($destino(), PHP_URL_PATH) . '?' . parse_url($destino(), PHP_URL_QUERY));
$n->ir($URL_L);
ok('con el motivo en el aviso, no solo en la pantalla', $flash() === 'Esa lectura ya se guardó.', $flash());
es('sin duplicar nada', $n_prod_http + 2, (int) valor('SELECT COUNT(*) FROM productos'));

/* La segunda lectura: todo al día. */
$n->ir('/stock/tienda', ['_t' => $n->testigo(), 'accion' => 'leer']);
ok('leer otra vez dice que ya está al día', str_contains($n->cuerpo, 'id="aviso-al-dia"'));
ok('sin botón de guardar', !str_contains($n->cuerpo, 'id="form-guardar"'));
ok('ni el aviso de que entran sin precio', !str_contains($n->cuerpo, 'id="aviso-sin-precio"'));

/* Una lectura que solo actualiza lleva al catálogo, no a «sin precio». */
file_put_contents($TIENDA_ARCHIVO, str_replace('Nombre nuevo', 'Nombre más nuevo', (string) file_get_contents($TIENDA_ARCHIVO)));
$n->ir('/stock/tienda', ['_t' => $n->testigo(), 'accion' => 'leer'], false);
$n->ir(parse_url($destino(), PHP_URL_PATH) . '?' . parse_url($destino(), PHP_URL_QUERY));
$n->ir('/stock/tienda', ['_t' => $n->testigo(), 'accion' => 'guardar', 'lectura' => (int) $n->oculto('lectura')], false);
ok('una lectura que solo actualiza lleva al catálogo', str_ends_with($destino(), '/stock'), $destino());

/* Y una que crea productos que YA traen su precio de la web, también: no
   hay nada que poner a mano. */
$datos_t = json_decode((string) file_get_contents($TIENDA_ARCHIVO), true);
$datos_t['productos'][] = ['id' => 8110, 'name' => 'Con precio', 'sku' => 'PRD-HTTP10', 'type' => 'simple',
                           'regular_price' => '120', 'categories' => []];
file_put_contents($TIENDA_ARCHIVO, json_encode($datos_t));
$n->ir('/stock');
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'leer'], false);
$n->ir(parse_url($destino(), PHP_URL_PATH) . '?' . parse_url($destino(), PHP_URL_QUERY));
ok('si todos los nuevos traen precio, no avisa de ninguno sin precio',
   str_contains($n->cuerpo, 'id="form-guardar"') && !str_contains($n->cuerpo, 'id="aviso-sin-precio"'));
$n->ir('/stock/tienda', ['_t' => $n->testigo(), 'accion' => 'guardar', 'lectura' => (int) $n->oculto('lectura')], false);
ok('crear solo productos con precio lleva al catálogo, no a «sin precio»', str_ends_with($destino(), '/stock'), $destino());
es('y ese ya se vende a S/ 120', 12000, precio_de_variante((int) valor("SELECT id FROM productos WHERE sku = 'PRD-HTTP10'"), null, 1));

/* LA FOTO. La tienda trae la foto de un producto: sale en el catálogo, en su
   ficha, en el buscador del pedido, y se sirve como imagen. */
$im = imagecreatetruecolor(800, 800);
imagefilledrectangle($im, 0, 0, 800, 800, imagecolorallocate($im, 220, 40, 40));
ob_start(); imagejpeg($im, null, 90); $jpg = (string) ob_get_clean(); imagedestroy($im);
$datos_t = json_decode((string) file_get_contents($TIENDA_ARCHIVO), true);
$datos_t['productos'][] = ['id' => 8111, 'name' => 'Lámpara con foto', 'sku' => 'PRD-HTTP11', 'type' => 'simple',
                           'regular_price' => '99', 'categories' => [],
                           'images' => [['id' => 7771, 'src' => 'https://img.compraenwaka.test/lampara.jpg']]];
$datos_t['fotos'] = ['https://img.compraenwaka.test/lampara.jpg' => base64_encode($jpg)];
file_put_contents($TIENDA_ARCHIVO, json_encode($datos_t));
$n->ir('/stock');
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'leer'], false);
$n->ir(parse_url($destino(), PHP_URL_PATH) . '?' . parse_url($destino(), PHP_URL_QUERY));
ok('la vista previa dice que trae la foto', (bool) preg_match('~id="aviso-fotos">Se traen 1 foto de la web~', $n->cuerpo));
$n->ir('/stock/tienda', ['_t' => $n->testigo(), 'accion' => 'guardar', 'lectura' => (int) $n->oculto('lectura')]);
ok('y al guardar lo cuenta', str_contains($flash(), '1 foto'), $flash());
$P_LAMP = (int) valor("SELECT id FROM productos WHERE sku = 'PRD-HTTP11'");
$FOTO_LAMP = (string) valor('SELECT foto FROM productos WHERE id = ?', [$P_LAMP]);
ok('la lámpara tiene su miniatura', (bool) preg_match('/^w7771-[a-f0-9]{8}\.jpg$/', $FOTO_LAMP), $FOTO_LAMP);
$n->ir('/stock?q=' . urlencode('Lámpara con foto'));
ok('en el catálogo sale su foto', str_contains($n->cuerpo, 'src="/uploads/productos/' . $FOTO_LAMP . '"'));
ok('que se carga al bajar, no de golpe', (bool) preg_match('~src="/uploads/productos/' . preg_quote($FOTO_LAMP, '~') . '"[^>]*loading="lazy"~', $n->cuerpo));
$n->ir('/stock/producto?id=' . $P_LAMP);
ok('y arriba de su ficha', (bool) preg_match('~id="foto-ficha" src="/uploads/productos/' . preg_quote($FOTO_LAMP, '~') . '"~', $n->cuerpo));
$n->ir('/uploads/productos/' . $FOTO_LAMP);
ok('la miniatura se sirve como imagen', $n->codigo === 200 && str_starts_with($n->cuerpo, "\xFF\xD8"));
/* Solo cambia la foto en la tienda: hay algo que guardar, y se dice qué. */
$datos_t = json_decode((string) file_get_contents($TIENDA_ARCHIVO), true);
foreach ($datos_t['productos'] as &$pp) if ($pp['id'] === 8111) $pp['images'] = [['id' => 7772, 'src' => 'https://img.compraenwaka.test/lampara2.jpg']];
unset($pp);
$datos_t['fotos']['https://img.compraenwaka.test/lampara2.jpg'] = base64_encode($jpg);
file_put_contents($TIENDA_ARCHIVO, json_encode($datos_t));
$n->ir('/stock');
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'leer'], false);
$n->ir(parse_url($destino(), PHP_URL_PATH) . '?' . parse_url($destino(), PHP_URL_QUERY));
ok('si solo cambió una foto, se ofrece guardar', str_contains($n->cuerpo, 'id="form-guardar"'));
ok('y la pregunta habla de la foto, no de «0 nuevos y 0 actualizados»',
   str_contains($n->cuerpo, 'data-confirmar="Se traen 1 foto. ¿Seguimos?"'));
$n->ir('/stock/tienda', ['_t' => $n->testigo(), 'accion' => 'guardar', 'lectura' => (int) $n->oculto('lectura')]);
$FOTO_LAMP2 = (string) valor('SELECT foto FROM productos WHERE id = ?', [$P_LAMP]);
ok('la lámpara tiene la foto nueva', str_starts_with($FOTO_LAMP2, 'w7772-'), $FOTO_LAMP2);
ok('y la vieja, que ya no usa nadie, se fue del disco', !is_file(HUB_SUBIDAS . '/productos/' . $FOTO_LAMP));

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/stock/buscar?q=' . urlencode('Lámpara con foto'));
$j = json_decode($n->cuerpo, true);
es('el buscador del pedido trae la foto', '/uploads/productos/' . $FOTO_LAMP2, $j['r'][0]['foto'] ?? null);
$n->ir('/pedidos/nuevo');
ok('y el formulario la pinta junto al nombre', str_contains($n->cuerpo, "b.classList.add('sugerencia--foto')")
   && str_contains($n->cuerpo, 'var conFoto = r.some('));
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
/* Una venta con la lámpara enseña su foto en la línea. */
$PED_LAMP = (int) valor('SELECT pedido_id FROM pedido_lineas ORDER BY id DESC LIMIT 1');
q('UPDATE pedido_lineas SET producto_id = ? WHERE pedido_id = ?', [$P_LAMP, $PED_LAMP]);
$n->ir('/pedidos/ficha?id=' . $PED_LAMP);
ok('en la ficha de la venta, la línea lleva la foto del producto',
   (bool) preg_match('~class="foto-prod foto-prod--linea" src="/uploads/productos/' . preg_quote($FOTO_LAMP2, '~') . '"~', $n->cuerpo));
@unlink(HUB_SUBIDAS . '/productos/' . $FOTO_LAMP2);

/* 3b.5 · LAS FOTOS QUE FALTAN, SIN VOLVER A LEER. Un producto cuya foto no
   llegó al guardar: la pantalla dice cuántas faltan y el botón las trae. */
$datos_t = json_decode((string) file_get_contents($TIENDA_ARCHIVO), true);
$datos_t['productos'][] = ['id' => 8113, 'name' => 'Foto que tarda', 'sku' => 'PRD-HTTP13', 'type' => 'simple',
                           'regular_price' => '30', 'categories' => [],
                           'images' => [['id' => 7774, 'src' => 'https://img.compraenwaka.test/tarda.jpg']]];
file_put_contents($TIENDA_ARCHIVO, json_encode($datos_t));
$n->ir('/stock');
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'leer'], false);
$n->ir(parse_url($destino(), PHP_URL_PATH) . '?' . parse_url($destino(), PHP_URL_QUERY));
$n->ir('/stock/tienda', ['_t' => $n->testigo(), 'accion' => 'guardar', 'lectura' => (int) $n->oculto('lectura')]);
ok('al guardar sin que llegue la foto, el aviso manda al botón', str_contains($flash(), 'Traer las fotos que faltan'), $flash());
$n->ir('/stock/tienda');
ok('LA PANTALLA DICE CUÁNTAS FOTOS FALTAN', (bool) preg_match('~id="tarjeta-fotos">.*?Faltan 1 foto de la web~s', $n->cuerpo));
ok('con su botón, que sigue solo', (bool) preg_match('~<form method="post" id="form-fotos" data-fotos-auto="1" data-multiple="1">.*?name="accion" value="fotos".*?TRAER LAS FOTOS QUE FALTAN~s', $n->cuerpo));
$datos_t['fotos']['https://img.compraenwaka.test/tarda.jpg'] = base64_encode($jpg);
file_put_contents($TIENDA_ARCHIVO, json_encode($datos_t));
$n_llamadas_antes = null;
$n->aceptar = 'application/json';
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'fotos'], false);
$n->aceptar = 'text/html,application/json';
$jf = json_decode($n->cuerpo, true);
ok('el botón contesta a la pantalla con lo que trajo', ($jf['ok'] ?? false) === true && (int)($jf['traidas'] ?? 0) === 1
   && (int)($jf['quedan'] ?? -1) === 0, $n->cuerpo);
$FOTO_TARDA = (string) valor("SELECT foto FROM productos WHERE sku = 'PRD-HTTP13'");
ok('y la foto ya está en el producto', (bool) preg_match('/^w7774-[a-f0-9]{8}\.jpg$/', $FOTO_TARDA), $FOTO_TARDA);
$n->ir('/stock/tienda');
ok('sin fotos pendientes, la tarjeta ya no sale', !str_contains($n->cuerpo, 'id="tarjeta-fotos"'));
/* Sin JavaScript: el mismo botón, con aviso y de vuelta a la pantalla. */
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'fotos'], false);
ok('sin JavaScript, vuelve a la pantalla', $n->codigo === 302 && str_ends_with($destino(), '/stock/tienda'), $destino());
$n->ir('/stock/tienda');
ok('diciendo que ya están todas', str_contains($flash(), 'Ya están todas las fotos'), $flash());
@unlink(HUB_SUBIDAS . '/productos/' . $FOTO_TARDA);
/* El asesor no puede traer fotos. */
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'fotos'], false);
es('el asesor no puede traer fotos (403)', 403, $n->codigo);
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');

/* 3b.5 · EL STOCK DE LA WEB, en pantalla. */
$datos_t = json_decode((string) file_get_contents($TIENDA_ARCHIVO), true);
foreach ($datos_t['productos'] as &$pp) {
    if ($pp['id'] === 8110) { $pp['manage_stock'] = true; $pp['stock_quantity'] = 12; $pp['stock_status'] = 'instock'; }
    if ($pp['id'] === 8111) { $pp['manage_stock'] = true; $pp['stock_quantity'] = 0; $pp['stock_status'] = 'outofstock'; }
}
unset($pp);
file_put_contents($TIENDA_ARCHIVO, json_encode($datos_t));
$n->ir('/stock');
ok('el catálogo dice cuándo se leyó el stock, con su botón', (bool) preg_match('~id="aviso-stock".*?ACTUALIZAR STOCK~s', $n->cuerpo));
$n->ir('/stock/leer', ['_t' => $n->testigoValido()], false);
ok('ACTUALIZAR STOCK vuelve al catálogo', $n->codigo === 302 && str_ends_with($destino(), '/stock'), $destino());
$n->ir('/stock');
ok('diciendo cuántos productos tienen stock', str_contains($flash(), 'Stock actualizado'), $flash());
$P10 = (int) valor("SELECT id FROM productos WHERE sku = 'PRD-HTTP10'");
$P11 = (int) valor("SELECT id FROM productos WHERE sku = 'PRD-HTTP11'");
$n->ir('/stock?q=' . urlencode('Con precio'));
ok('LA LISTA ENSEÑA EL STOCK DE LA WEB', (bool) preg_match('~data-stock="' . $P10 . '">12 en stock<~', $n->cuerpo));
$n->ir('/stock?q=' . urlencode('Lámpara con foto'));
ok('y el agotado, en rojo', (bool) preg_match('~chip chip--rojo" data-stock="' . $P11 . '">Agotado<~', $n->cuerpo));
ok('con la hora de la lectura', (bool) preg_match('~id="aviso-stock">.*?Stock de la web leído hace~s', $n->cuerpo));
$n->ir('/stock/producto?id=' . $P10);
ok('la ficha del producto también', (bool) preg_match('~id="stock-ficha">.*?12 en stock~s', $n->cuerpo));
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/stock/buscar?q=' . urlencode('Con precio'));
$jb = json_decode($n->cuerpo, true);
es('el asesor lo ve al buscar el producto en la venta', '12 en stock', $jb['r'][0]['stock']['texto'] ?? null);
$n->ir('/stock');
if ($n->codigo === 200) {
    ok('el asesor ve el stock en el catálogo', str_contains($n->cuerpo, 'id="aviso-stock"'));
    ok('pero no el botón de actualizarlo', !str_contains($n->cuerpo, 'id="form-stock"'));
}
$n->ir('/stock/leer', ['_t' => $n->testigoValido()], false);
es('ni puede actualizarlo a mano (403)', 403, $n->codigo);
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
/* «Quedan pocas» se cambia sin programador, en Configuración › Tienda. */
$n->ir('/configuracion/tienda');
ok('Configuración › Tienda deja cambiar desde cuántas unidades se avisa', (bool) preg_match('~id="form-poco".*?name="stock_poco"[^>]*value="3"~s', $n->cuerpo));
$n->ir('/configuracion/tienda', ['_t' => $n->testigoValido(), 'accion' => 'poco', 'stock_poco' => '5'], false);
es('y se guarda', '5', (string) valor("SELECT valor FROM ajustes WHERE clave = 'stock_poco'"));
$n->ir('/configuracion/tienda', ['_t' => $n->testigoValido(), 'accion' => 'poco', 'stock_poco' => 'muchas']);
es('una cantidad que no es número no se guarda', '5', (string) valor("SELECT valor FROM ajustes WHERE clave = 'stock_poco'"));
ok('y se dice por qué', str_contains($n->cuerpo, 'Escribe un número de unidades'));
q("UPDATE ajustes SET valor = '3' WHERE clave = 'stock_poco'");

/* ══════════════ 3c · EL CONECTOR, POR LA PANTALLA ══════════════ */
$WP_ARCHIVO = sys_get_temp_dir() . '/waka-wp-falso-' . (int)(getenv('WAKA_PUERTO') ?: 8123) . '.json';
$CLAVE_C = bin2hex(random_bytes(24));
$P10 = (int) valor("SELECT id FROM productos WHERE sku = 'PRD-HTTP10'");
$wp_prod = fn(array $d) => $d + ['tipo' => 'simple', 'padre' => 0, 'nombre' => '', 'sku' => '', 'precio' => '',
                                  'gestiona' => false, 'stock' => null, 'estado' => 'instock'];
file_put_contents($WP_ARCHIVO, json_encode(['opciones' => ['waka_hub_clave' => $CLAVE_C], 'transitorios' => [],
    'productos' => [8110 => $wp_prod(['id' => 8110, 'nombre' => 'Con precio', 'sku' => 'PRD-HTTP10', 'precio' => '120.00', 'gestiona' => true, 'stock' => 12]),
                    8199 => $wp_prod(['id' => 8199, 'nombre' => 'Ajeno', 'sku' => 'PRD-AJENO'])]]));
$wp_de = fn(int $id) => (json_decode((string) file_get_contents($WP_ARCHIVO), true)['productos'][$id] ?? []);

$n->ir('/configuracion/tienda');
ok('CONFIGURACIÓN › TIENDA TIENE EL CONECTOR', (bool) preg_match('~id="tarjeta-conector".*?id="conector-estado">Sin poner<~s', $n->cuerpo));
ok('con el enlace para descargarlo', (bool) preg_match('~id="conector-descargar"[^>]*|href="[^"]*assets/descargas/waka-hub-conector\.zip"~', $n->cuerpo));
$n->ir('/assets/descargas/waka-hub-conector.zip');
ok('y el conector se descarga', $n->codigo === 200 && str_starts_with($n->cuerpo, "PK"));
$n->ir('/configuracion/tienda', ['_t' => $n->testigoValido(), 'accion' => 'conector', 'conector_clave' => 'no-es-una-clave']);
ok('una clave con otra forma no se guarda', str_contains($n->cuerpo, 'Esa no es la clave del conector')
   && (string) valor("SELECT valor FROM ajustes WHERE clave = 'conector_clave'") === '');
$n->ir('/configuracion/tienda', ['_t' => $n->testigoValido(), 'accion' => 'conector', 'conector_clave' => $CLAVE_C], false);
$n->ir('/configuracion/tienda');
ok('LA CLAVE BUENA SE GUARDA Y SE PRUEBA SOLA', str_contains($flash(), 'El conector funciona'), $flash());
ok('y sale «Funciona»', str_contains($n->cuerpo, 'id="conector-estado">Funciona<'));
ok('LA CLAVE NO SE VUELVE A ENSEÑAR', !str_contains($n->cuerpo, $CLAVE_C) && str_contains($n->cuerpo, substr($CLAVE_C, -4)));

/* La ficha del producto enlazado. */
$n->ir('/stock/producto?id=' . $P10);
ok('LA FICHA DICE QUE EL NOMBRE Y EL CÓDIGO TAMBIÉN CAMBIAN EN LA WEB', str_contains($n->cuerpo, 'al guardar, el nombre y el código también cambian allá')
   && str_contains($n->cuerpo, 'id="sku-web"'));
ok('y tiene la tarjeta del stock en la web', str_contains($n->cuerpo, 'id="tarjeta-stock-web"'));
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'producto', 'id' => $P10, 'nombre' => 'Con precio renombrado',
                           'sku' => 'PRD-HTTP10B', 'categoria' => '', 'garantia_item_id' => '', 'activo' => '1'], false);
es('CAMBIAR EL NOMBRE Y EL CÓDIGO AQUÍ LOS CAMBIA EN LA WEB', ['Con precio renombrado', 'PRD-HTTP10B'], [$wp_de(8110)['nombre'] ?? '', $wp_de(8110)['sku'] ?? '']);
es('y aquí', ['Con precio renombrado', 'PRD-HTTP10B'], array_values(una('SELECT nombre, sku FROM productos WHERE id = ?', [$P10])));
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'producto', 'id' => $P10, 'nombre' => 'Otro nombre',
                           'sku' => 'PRD-AJENO', 'categoria' => '', 'garantia_item_id' => '', 'activo' => '1']);
ok('SI LA WEB NO LO ACEPTA (código de otro), SE DICE', str_contains($n->cuerpo, 'ya lo tiene otro producto'));
es('Y AQUÍ NO SE GUARDÓ NADA', ['Con precio renombrado', 'PRD-HTTP10B'], array_values(una('SELECT nombre, sku FROM productos WHERE id = ?', [$P10])));
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'producto', 'id' => $P10, 'nombre' => 'Cable <2m> HDMI',
                           'sku' => 'PRD-HTTP10B', 'categoria' => '', 'garantia_item_id' => '', 'activo' => '1']);
ok('UN NOMBRE CON «<» O «>» SE AVISA ANTES (la web lo cortaría)', str_contains($n->cuerpo, 'no puede llevar'));
es('y la web no se tocó', 'Con precio renombrado', $wp_de(8110)['nombre'] ?? '');
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'precios', 'id' => $P10,
                           't_desde' => ['1', '5'], 't_hasta' => ['4', ''], 't_precio' => ['130.00', '125.00'], 't_alias' => ['', '']], false);
es('EL PRECIO DE 1 UNIDAD VA A LA WEB', '130.00', $wp_de(8110)['precio'] ?? '');
es('los tramos por cantidad se quedan aquí', 12500, precio_de_variante($P10, null, 5));
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'web_stock', 'id' => $P10, 'variante_id' => '0',
                           'cantidad' => '8', 'motivo' => 'Conteo'], false);
es('EL STOCK SE PONE EN LA WEB', 8, $wp_de(8110)['stock'] ?? -1);
$n->ir('/stock/producto?id=' . $P10);
ok('y la ficha lo enseña al momento', (bool) preg_match('~id="stock-ficha">.*?8 en stock~s', $n->cuerpo));
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'web_stock', 'id' => $P10, 'variante_id' => '0',
                           'cantidad' => '3', 'motivo' => '']);
ok('sin motivo, no', str_contains($n->cuerpo, 'Escribe el motivo') && ($wp_de(8110)['stock'] ?? -1) === 8);
/* Con la tienda caída, nada. */
$e_wp = json_decode((string) file_get_contents($WP_ARCHIVO), true); $e_wp['caida'] = true; file_put_contents($WP_ARCHIVO, json_encode($e_wp));
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'precios', 'id' => $P10,
                           't_desde' => ['1'], 't_hasta' => [''], 't_precio' => ['99.00'], 't_alias' => ['']]);
ok('CON LA TIENDA CAÍDA, EL PRECIO NO CAMBIA NI AQUÍ', str_contains($n->cuerpo, 'No hubo respuesta de la tienda')
   && precio_de_variante($P10, null, 1) === 13000);
$e_wp['caida'] = false; file_put_contents($WP_ARCHIVO, json_encode($e_wp));

/* Dirección: ve la ficha, pero la web no la cambia. */
$n->salir();
$n->entrar('ceo@waka.test', 'Clave-Larga-1');
$n->ir('/stock/producto?id=' . $P10);
es('Dirección abre la ficha', 200, $n->codigo);
{
    ok('a Dirección no se le ofrece cambiar la web', !str_contains($n->cuerpo, 'id="tarjeta-stock-web"') && !str_contains($n->cuerpo, 'id="sku-web"'));
    $n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'producto', 'id' => $P10, 'nombre' => 'Lo cambia el CEO',
                               'sku' => '', 'categoria' => '', 'garantia_item_id' => '', 'activo' => '1']);
    ok('NI CAMBIAR EL NOMBRE DE UN PRODUCTO DE LA TIENDA', str_contains($n->cuerpo, 'tu cuenta no cambia su nombre ni su código'));
    es('y no cambió', 'Con precio renombrado', (string) valor('SELECT nombre FROM productos WHERE id = ?', [$P10]));
}
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');

/* Al leer la tienda: lo que la web dice distinto se enseña para decidir. */
$datos_t = json_decode((string) file_get_contents($TIENDA_ARCHIVO), true);
foreach ($datos_t['productos'] as &$pp) if ($pp['id'] === 8110) { $pp['name'] = 'Nombre que puso marketing'; $pp['sku'] = 'PRD-HTTP10B'; $pp['regular_price'] = '130'; }
unset($pp);
file_put_contents($TIENDA_ARCHIVO, json_encode($datos_t));
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'leer'], false);
$L3C = (int) (preg_match('~l=(\d+)~', $destino(), $mm) ? $mm[1] : 0);
$n->ir('/stock/tienda?l=' . $L3C);
ok('LA VISTA PREVIA ENSEÑA LO QUE LA WEB DICE DISTINTO', (bool) preg_match('~id="lista-difieren".*?data-difiere="8110".*?Nombre en la web: «Nombre que puso marketing»~s', $n->cuerpo));
ok('con los dos botones', (bool) preg_match('~data-difiere="8110".*?Corregir la web.*?Usar el de la web~s', $n->cuerpo));
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'corregir_web', 'lectura' => $L3C, 'woo' => 8110], false);
es('«CORREGIR LA WEB» MANDA LO DE AQUÍ', 'Con precio renombrado', $wp_de(8110)['nombre'] ?? '');
$n->ir('/stock/tienda?l=' . $L3C);
ok('y la diferencia ya no sale', !str_contains($n->cuerpo, 'data-difiere="8110"'));
ok('sin obligar a leer otra vez', !str_contains($n->cuerpo, 'id="aviso-cambio"'));
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'leer'], false);
$L3D = (int) (preg_match('~l=(\d+)~', $destino(), $mm) ? $mm[1] : 0);
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'usar_web', 'lectura' => $L3D, 'woo' => 8110], false);
es('«USAR EL DE LA WEB» LO TRAE AQUÍ', 'Nombre que puso marketing', (string) valor('SELECT nombre FROM productos WHERE id = ?', [$P10]));
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'corregir_web', 'lectura' => $L3D, 'woo' => 8110]);
ok('una diferencia que ya no está lo dice', str_contains($flash(), 'Esa diferencia ya no está'), $flash());
/* Una lectura de ANTES del último cambio hecho aquí no vale para «usar el de la web». */
$e_t = json_decode((string) file_get_contents($TIENDA_ARCHIVO), true);
foreach ($e_t['productos'] as &$pp) if ($pp['id'] === 8110) $pp['name'] = 'Otro de marketing';
unset($pp);
file_put_contents($TIENDA_ARCHIVO, json_encode($e_t));
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'leer'], false);
$L3E = (int) (preg_match('~l=(\d+)~', $destino(), $mm) ? $mm[1] : 0);
q('UPDATE tienda_lecturas SET creado_en = ? WHERE id = ?', [date('Y-m-d H:i:s', strtotime('-5 minutes')), $L3E]);
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'producto', 'id' => $P10, 'nombre' => 'Puesto después de leer',
                           'sku' => 'PRD-HTTP10B', 'categoria' => '', 'garantia_item_id' => '', 'activo' => '1'], false);
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'usar_web', 'lectura' => $L3E, 'woo' => 8110]);
ok('CON UNA LECTURA DE ANTES DEL ÚLTIMO CAMBIO, «USAR EL DE LA WEB» SE NIEGA', str_contains($flash(), 'cambió aquí después de leer'), $flash());
es('y el nombre de aquí se queda', 'Puesto después de leer', (string) valor('SELECT nombre FROM productos WHERE id = ?', [$P10]));

/* Cambiar la dirección de la tienda se lleva la clave del conector. */
$woo_antes = [];
foreach (['woo_url', 'woo_key', 'woo_secret', 'woo_probada_en'] as $k) $woo_antes[$k] = (string) valor('SELECT valor FROM ajustes WHERE clave = ?', [$k]);
$n->ir('/configuracion/tienda', ['_t' => $n->testigoValido(), 'accion' => 'guardar', 'ruta' => 'https://otra-tienda.test',
                                 'key' => 'ck_' . str_repeat('6c', 20), 'secret' => 'cs_' . str_repeat('1f', 20)], false);
es('OTRA DIRECCIÓN, EL CONECTOR HAY QUE PONERLO OTRA VEZ', '', (string) valor("SELECT valor FROM ajustes WHERE clave = 'conector_clave'"));
@unlink($WP_ARCHIVO);
foreach ($woo_antes as $k => $v) q('UPDATE ajustes SET valor = ? WHERE clave = ?', [$v, $k]);

/* 3b.4 · SI LAS FOTOS FALLAN, SE GUARDA IGUAL. En el 3b.3 esto daba «Algo se
   rompió» (referencia 8B9BE29A): se pulsaba guardar y no entraba nada. */
$datos_t = json_decode((string) file_get_contents($TIENDA_ARCHIVO), true);
$datos_t['productos'][] = ['id' => 8112, 'name' => 'Foto que revienta', 'sku' => 'PRD-HTTP12', 'type' => 'simple',
                           'regular_price' => '45', 'categories' => [],
                           'images' => [['id' => 7773, 'src' => 'https://img.compraenwaka.test/revienta.jpg']]];
$datos_t['fotos_revientan'] = true;
file_put_contents($TIENDA_ARCHIVO, json_encode($datos_t));
$n->ir('/stock');
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'leer'], false);
$n->ir(parse_url($destino(), PHP_URL_PATH) . '?' . parse_url($destino(), PHP_URL_QUERY));
ok('la vista previa ofrece la foto', str_contains($n->cuerpo, 'id="aviso-fotos"'));
$n->ir('/stock/tienda', ['_t' => $n->testigo(), 'accion' => 'guardar', 'lectura' => (int) $n->oculto('lectura')], false);
ok('GUARDAR CON LAS FOTOS FALLANDO NO ROMPE LA PÁGINA', $n->codigo === 302, (string) $n->codigo);
ok('y lleva al catálogo', str_ends_with($destino(), '/stock'), $destino());
$n->ir('/stock');
ok('diciendo lo que entró', str_contains($flash(), 'Guardado en el catálogo') && str_contains($flash(), '1 producto nuevo'), $flash());
ok('el producto entró, sin foto', (int) valor("SELECT COUNT(*) FROM productos WHERE sku = 'PRD-HTTP12' AND foto IS NULL AND foto_woo IS NULL") === 1);
unset($datos_t['fotos_revientan']);

/* Y una lectura que revienta por dentro dice que no se pudo, sin «Algo se rompió». */
$datos_t['revienta'] = true;
file_put_contents($TIENDA_ARCHIVO, json_encode($datos_t));
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'leer'], false);
ok('LEER QUE REVIENTA NO ROMPE LA PÁGINA', $n->codigo === 302, (string) $n->codigo);
$n->ir('/stock/tienda');
ok('y lo dice con palabras', str_contains($flash(), 'No se pudo leer la tienda'), $flash());
unset($datos_t['revienta']);

/* La base de datos se duerme mientras se lee la tienda: se abre otra y la
   lectura se apunta. */
$datos_t['duerme_al_leer'] = true;
file_put_contents($TIENDA_ARCHIVO, json_encode($datos_t));
$marca_reab = sys_get_temp_dir() . '/waka-reabierta-' . (int)(getenv('WAKA_PUERTO') ?: 8123);
@unlink($marca_reab);
$n_lect_d = (int) valor('SELECT COUNT(*) FROM tienda_lecturas');
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'leer'], false);
ok('SI LA BASE SE DURMIÓ LEYENDO, SE ABRE OTRA Y LA LECTURA SE APUNTA',
   str_contains($destino(), '/stock/tienda?l=') && is_file($marca_reab), $destino());
es('una lectura más', $n_lect_d + 1, (int) valor('SELECT COUNT(*) FROM tienda_lecturas'));
@unlink($marca_reab);
unset($datos_t['duerme_al_leer']);
file_put_contents($TIENDA_ARCHIVO, json_encode($datos_t));

/* La tienda caída: lo dice y no deja una lectura a medias. */
file_put_contents($TIENDA_ARCHIVO, json_encode(['caida' => true]));
$n_lect_http = (int) valor('SELECT COUNT(*) FROM tienda_lecturas');
$n->ir('/stock');                       // la anterior no siguió la redirección: sin testigo
$n->ir('/stock/tienda', ['_t' => $n->testigoValido(), 'accion' => 'leer']);
ok('con la tienda caída, lo dice', str_contains($flash(), 'No hubo respuesta de la tienda'), $flash());
es('y no apunta ninguna lectura', $n_lect_http, (int) valor('SELECT COUNT(*) FROM tienda_lecturas'));

/* Una tienda puesta por otro país no se trae desde aquí. */
q("UPDATE ajustes SET valor = '9999' WHERE clave = 'woo_pais'");
$n->ir('/stock/tienda');
ok('si la tienda es de otro país, lo dice', str_contains($n->cuerpo, 'id="aviso-otro-pais"'));
ok('y no ofrece leer', !str_contains($n->cuerpo, 'id="form-leer"'));

/* Sin claves, la pantalla lleva a Configuración. */
q("UPDATE ajustes SET valor = '' WHERE clave IN ('woo_url', 'woo_key', 'woo_secret', 'woo_probada_en')");
q("UPDATE ajustes SET valor = '0' WHERE clave = 'woo_pais'");
$n->ir('/stock/tienda');
ok('sin conectar, manda a Configuración › Tienda', (bool) preg_match('~href="[^"]*/configuracion/tienda"~', $n->cuerpo)
   && !str_contains($n->cuerpo, 'id="form-leer"'));
@unlink($TIENDA_ARCHIVO);

/* ═══════════════  3d · EL DESCUENTO DEL ASESOR, POR HTTP  ═══════════════ */
grupo('3d · el descuento del asesor, de punta a punta');

/* 3h.1: la aprobación nace apagada; estas pruebas son de cuando está encendida. */
q("DELETE FROM ajustes WHERE clave = 'descuento_pide_aprobacion'");
q("INSERT INTO ajustes (clave, valor, tipo, grupo, descripcion, tecnico) VALUES ('descuento_pide_aprobacion', '1', 'booleano', 'pedidos', '', 0)");
q("UPDATE ajustes SET valor = '5' WHERE clave = 'descuento_tope_pct'");
q("UPDATE ajustes SET valor = '0' WHERE clave = 'cashback_con_descuento'");
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/nuevo?cliente=' . (int)$F['clientes']['asesor']);
ok('EL FORMULARIO TRAE EL CAMPO DEL DESCUENTO', str_contains($n->cuerpo, 'id="descuento"'));
ok('con su motivo', str_contains($n->cuerpo, 'name="descuento_motivo"'));
ok('y le dice al asesor hasta dónde sale solo', str_contains($n->cuerpo, 'Hasta el 5 %'));

$venta_d = function (string $desc, string $motivo, string $pago) use ($n, $F): void {
    $n->ir('/pedidos/nuevo?cliente=' . (int)$F['clientes']['asesor']);
    $n->ir('/pedidos/nuevo', [
        '_t' => $n->testigo(), 'f_id' => $n->oculto('f_id'),
        'tipo' => 'inmediata', 'cliente_id' => (int)$F['clientes']['asesor'],
        'entrega' => 'recojo', 'canal_item_id' => (int)$F['canal'],
        'l_desc' => ['Silla Gamer Comic Duality'], 'l_sku' => [''], 'l_cant' => ['1'], 'l_precio' => ['379'],
        'fecha' => date('Y-m-d'), 'descuento' => $desc, 'descuento_motivo' => $motivo,
        'modo_pago' => 'completo', 'pago_monto' => $pago,
        'pago_metodo_item_id' => (int)$F['efectivo'], 'pago_fecha' => date('Y-m-d'),
    ]);
};
$ultimo_ped = fn() => (int) valor('SELECT MAX(id) FROM pedidos');

$antes_d = $ultimo_ped();
$venta_d('50', '', '329');
es('SIN MOTIVO NO SE REGISTRA', $antes_d, $ultimo_ped());
ok('y lo dice', str_contains($n->cuerpo, 'Escribe el motivo del descuento'));
ok('devolviendo lo escrito', str_contains($n->cuerpo, 'value="50"'));

$venta_d('379', 'regalo', '0.01');
es('un descuento que deja los productos en cero tampoco', $antes_d, $ultimo_ped());

$venta_d('10', 'cliente frecuente', '369');
$PDH1 = $ultimo_ped();
ok('DENTRO DEL TOPE, SE REGISTRA', $PDH1 > $antes_d, mb_substr(strip_tags($n->cuerpo), 0, 300));
$f1 = una('SELECT * FROM pedidos WHERE id = ?', [$PDH1]);
es('con el total ya rebajado', 36900, (int)$f1['total_centimos']);
es('y el descuento bueno, sin esperar a nadie', 'ok', (string)$f1['descuento_estado']);
es('con su motivo', 'cliente frecuente', (string)$f1['descuento_motivo']);
es('y quién lo hizo', (int)$F['usuarios']['asesor'], (int)$f1['descuento_por']);
ok('la ficha lo enseña', str_contains($n->cuerpo, 'id="dato-descuento"') && str_contains($n->cuerpo, '2.6 %'));

$marca_h = reclamos_marca();
$venta_d('50', 'compra 3 unidades', '329');
$PDH2 = $ultimo_ped();
$f2 = una('SELECT * FROM pedidos WHERE id = ?', [$PDH2]);
es('FUERA DEL TOPE, SE REGISTRA ESPERANDO', 'pendiente', (string)$f2['descuento_estado']);
es('con el precio que se le dijo al cliente', 32900, (int)$f2['total_centimos']);
ok('y el asesor lo lee al registrar', str_contains($flash(), 'espera que Administración lo apruebe'), $flash());
ok('la ficha dice que está por aprobar', str_contains($n->cuerpo, 'Por aprobar'));
ok('y al asesor no le ofrece aprobarlo', !str_contains($n->cuerpo, 'id="desc-resolver"'));

$n->ir('/pedidos/descuento', ['_t' => $n->testigoValido(), 'id' => $PDH2, 'accion' => 'aprobar'], false);
es('SI EL ASESOR FUERZA LA APROBACIÓN, 403', 403, $n->codigo);
es('y sigue esperando', 'pendiente', (string) valor('SELECT descuento_estado FROM pedidos WHERE id = ?', [$PDH2]));
$n->ir('/pedidos/descuentos', null, false);
es('la lista de descuentos no es para el asesor', 403, $n->codigo);

/* Facturación confirma el pago: aun así no sale a despacho. */
$n->salir();
$n->entrar('factu@waka.test', 'Clave-Larga-1');
$pg_h2 = (int) valor('SELECT id FROM pagos WHERE pedido_id = ? ORDER BY id DESC LIMIT 1', [$PDH2]);
$n->ir('/pagos/por-validar');
$n->ir('/pagos/validar', ['_t' => $n->testigoValido(), 'id' => $pg_h2], false);
es('facturación confirma el pago', 1, (int) valor('SELECT verificado FROM pagos WHERE id = ?', [$pg_h2]));
$n->ir('/pagos/nuevos?desde=0&rdesde=' . $marca_h);
$jf = json_decode($n->cuerpo, true);
es('a Facturación no le suena el descuento: no es suyo', 0, (int)($jf['descuentos'] ?? -1));
$n->ir('/pedidos/facturar?id=' . $PDH2);
ok('LA PANTALLA DE FACTURAR AVISA QUE EL TOTAL PUEDE CAMBIAR', str_contains($n->cuerpo, 'id="aviso-descuento-espera"'));
$n->ir('/pedidos/comprobante', ['_t' => $n->testigo(), 'id' => $PDH2, 'tipo' => 'boleta',
                                'serie' => 'B001', 'numero' => '77']);
ok('y el motivo es el descuento', str_contains($flash(), 'Anótalo cuando respondan'), $flash());
es('Y ANOTAR UNA BOLETA A MANO NO SE PUEDE', '', (string) valor('SELECT COALESCE(comprobante_tipo, \'\') FROM pedidos WHERE id = ?', [$PDH2]));

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/ficha?id=' . $PDH2);
ok('CON EL PAGO CONFIRMADO, LA VENTA SIGUE SIN SALIR', str_contains($n->cuerpo, 'Despacho en espera del descuento'));
ok('y se le dice por qué', str_contains($n->cuerpo, 'espera que Administración lo apruebe'));
$n->ir('/pedidos/mensaje?id=' . $PDH2);
ok('EL MENSAJE AL CLIENTE AVISA QUE EL TOTAL PUEDE CAMBIAR', str_contains($n->cuerpo, 'id="aviso-msg-descuento"'));
$n->ir('/pedidos/por-despachar');
ok('en «por despachar» sale con su motivo', str_contains($n->cuerpo, 'Descuento por aprobar'));
$n->ir('/pedidos/despacho?id=' . $PDH2, null, false);
es('LA PANTALLA DE DESPACHO LO FRENA (409)', 409, $n->codigo);
ok('diciendo por qué', str_contains($n->cuerpo, 'espera que Administración lo apruebe'));
$n->ir('/pedidos/despacho', ['_t' => $n->testigoValido(), 'id' => $PDH2], false);
es('y forzar el envío tampoco lo manda', 0, (int) valor('SELECT despacho_veces FROM pedidos WHERE id = ?', [$PDH2]));
$marca_as = reclamos_marca();

/* ADMINISTRACIÓN: le suena, lo ve y contesta. */
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/nuevos?desde=0&rdesde=' . $marca_h);
$ja = json_decode($n->cuerpo, true);
es('A ADMINISTRACIÓN LE SUENA EL DESCUENTO', 1, (int)($ja['descuentos'] ?? 0));
es('con el código de la venta', (string)$f2['codigo'], (string)($ja['descuento_codigo'] ?? ''));
$n->ir('/pedidos');
ok('en la lista de pedidos, el chip de los que esperan', str_contains($n->cuerpo, 'id="chip-descuentos"'));
$n->ir('/pedidos/descuentos');
ok('LA LISTA DE LOS QUE ESPERAN', $n->codigo === 200 && str_contains($n->cuerpo, (string)$f2['codigo']));
ok('con el motivo', str_contains($n->cuerpo, 'compra 3 unidades'));
$n->ir('/pedidos/ficha?id=' . $PDH2);
ok('la ficha le pide la respuesta arriba', str_contains($n->cuerpo, 'id="desc-resolver"'));
$n->ir('/pedidos/descuento', ['_t' => $n->testigo(), 'id' => $PDH2, 'accion' => 'aprobar']);
ok('LO APRUEBA', str_contains($flash(), 'Descuento aprobado'), $flash());
es('queda bueno', 'ok', (string) valor('SELECT descuento_estado FROM pedidos WHERE id = ?', [$PDH2]));
es('y la venta quedó pagada', 'pagado', (string) pedido_de($PDH2)['estado']);

$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/nuevos-despacho?desde=0&ddesde=' . $marca_as);
$jd = json_decode($n->cuerpo, true);
es('AL ASESOR LE LLEGA LA RESPUESTA', 1, (int)($jd['desc_n'] ?? 0));
ok('diciendo que se aprobó', !empty($jd['desc_ok']));
es('de su venta', $PDH2, (int)($jd['desc_id'] ?? 0));
$n->ir('/pedidos/ficha?id=' . $PDH2);
ok('y YA PUEDE MANDARLA A DESPACHO', str_contains($n->cuerpo, 'name="id" value="' . $PDH2 . '">' . "\n" . '  <input type="hidden" name="primera"'));

/* EL RECHAZO, con nota, desde la ficha. */
$venta_d('60', 'cliente de años', '319');
$PDH3 = $ultimo_ped();
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/ficha?id=' . $PDH3);
$n->ir('/pedidos/descuento', ['_t' => $n->testigo(), 'id' => $PDH3, 'accion' => 'rechazar',
                              'nota' => 'Ya tiene precio de oferta']);
ok('LO RECHAZA', str_contains($flash(), 'Descuento rechazado'), $flash());
$f3 = una('SELECT * FROM pedidos WHERE id = ?', [$PDH3]);
es('el total vuelve a S/ 379', 37900, (int)$f3['total_centimos']);
es('con la nota', 'Ya tiene precio de oferta', (string)$f3['descuento_nota']);
$n->ir('/pedidos/descuento', ['_t' => $n->testigoValido(), 'id' => $PDH3, 'accion' => 'aprobar']);
ok('aprobar después de rechazar no vale', str_contains($flash(), 'ya se rechazó'), $flash());
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/ficha?id=' . $PDH3);
ok('el asesor ve que no se aprobó, y por qué', str_contains($n->cuerpo, 'Rechazado')
   && str_contains($n->cuerpo, 'Ya tiene precio de oferta'));

/* CONFIGURACIÓN › DESCUENTOS */
$n->ir('/configuracion/descuentos', null, false);
es('la configuración no es para el asesor', 403, $n->codigo);
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion');
ok('Configuración tiene la fila de Descuentos', str_contains($n->cuerpo, '/configuracion/descuentos'));
$n->ir('/configuracion/descuentos');
ok('la pantalla abre con el tope', $n->codigo === 200 && str_contains($n->cuerpo, 'value="5"'));
$n->ir('/configuracion/descuentos', ['_t' => $n->testigo(), 'tope' => 'mucho']);
ok('un tope que no es número se dice', str_contains($n->cuerpo, 'del 0 al 100'));
es('y no se guarda', '5', (string) valor("SELECT valor FROM ajustes WHERE clave = 'descuento_tope_pct'"));
$n->ir('/configuracion/descuentos', ['_t' => $n->testigo(), 'tope' => '15 %', 'con_cashback' => '1', 'pide' => '1']);
es('SE GUARDA EL TOPE', '15', (string) valor("SELECT valor FROM ajustes WHERE clave = 'descuento_tope_pct'"));
es('y el cashback junto al descuento', '1', (string) valor("SELECT valor FROM ajustes WHERE clave = 'cashback_con_descuento'"));
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$venta_d('50', 'compra 3 unidades', '329');
es('con el tope en 15 %, el de S/ 50 ya sale solo', 'ok',
   (string) valor('SELECT descuento_estado FROM pedidos WHERE id = ?', [$ultimo_ped()]));
q("UPDATE ajustes SET valor = '5' WHERE clave = 'descuento_tope_pct'");
q("UPDATE ajustes SET valor = '0' WHERE clave = 'cashback_con_descuento'");
/* Uno se queda ESPERANDO a propósito: la revisión de pantallas (movil.cjs)
   mide la ficha con los botones de aprobar y la lista con una fila. */
$venta_d('45', 'cliente de años, compra dos', '334');
es('queda uno esperando para la revisión de pantallas', 'pendiente',
   (string) valor('SELECT descuento_estado FROM pedidos WHERE id = ?', [$ultimo_ped()]));


/* ══════════════ 3e · LA VENTA DESCUENTA EL STOCK DE LA WEB ══════════════ */
function intval_o_texto($v) { return is_numeric($v) ? (int)$v : $v; }
grupo('3e · Control de stock, por la pantalla');
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
$WP_ARCHIVO = sys_get_temp_dir() . '/waka-wp-falso-' . (int)(getenv('WAKA_PUERTO') ?: 8123) . '.json';
$CLAVE_E = bin2hex(random_bytes(24));
/* La tienda conectada (las pruebas de antes la dejaron vacía). */
foreach (['woo_url' => 'https://compraenwaka.test', 'woo_key' => 'ck_' . str_repeat('a1', 20),
          'woo_secret' => 'cs_' . str_repeat('b2', 20), 'woo_probada_en' => date('Y-m-d H:i:s'),
          'woo_pais' => (string) valor("SELECT pais_id FROM usuarios WHERE email = 'asesor@waka.test'")] as $k => $v) {
    q('UPDATE ajustes SET valor = ? WHERE clave = ?', [$v, $k]);
}
$P10 = (int) valor("SELECT id FROM productos WHERE woo_id = 8110");
$wp_e = fn(array $extra = []) => file_put_contents($WP_ARCHIVO, json_encode(array_merge(json_decode((string) @file_get_contents($WP_ARCHIVO), true) ?: [], $extra)));
file_put_contents($WP_ARCHIVO, json_encode(['opciones' => ['waka_hub_clave' => $CLAVE_E], 'transitorios' => [],
    'productos' => [8110 => ['id' => 8110, 'tipo' => 'simple', 'padre' => 0, 'nombre' => 'Con precio', 'sku' => 'PRD-HTTP10B',
                             'precio' => '130.00', 'gestiona' => true, 'stock' => 3, 'estado' => 'instock']]]));
$wp_st = fn() => (json_decode((string) file_get_contents($WP_ARCHIVO), true)['productos'][8110]['stock'] ?? null);
q("DELETE FROM stock_web WHERE producto_id = ?", [$P10]);
insertar('stock_web', ['producto_id' => $P10, 'variante_id' => 0, 'cantidad' => 3, 'estado' => 'instock', 'compartido' => 0, 'leido_en' => date('Y-m-d H:i:s')]);
q("UPDATE productos SET activo = 1 WHERE id = ?", [$P10]);

$n->ir('/configuracion/tienda');
ok('CONFIGURACIÓN › TIENDA TIENE EL INTERRUPTOR, APAGADO', (bool) preg_match('~id="tarjeta-control".*?id="control-estado">Apagado<~s', $n->cuerpo));
$n->ir('/configuracion/tienda', ['_t' => $n->testigoValido(), 'accion' => 'control', 'valor' => '1']);
ok('SIN CONECTOR NO SE ENCIENDE', str_contains($n->cuerpo, 'pon la clave del conector') && (string) valor("SELECT valor FROM ajustes WHERE clave = 'control_stock'") === '0');
$n->ir('/configuracion/tienda', ['_t' => $n->testigoValido(), 'accion' => 'conector', 'conector_clave' => $CLAVE_E], false);
$n->ir('/configuracion/tienda', ['_t' => $n->testigoValido(), 'accion' => 'control', 'valor' => '1'], false);
$n->ir('/configuracion/tienda');
ok('CON EL CONECTOR, SE ENCIENDE', str_contains($flash(), 'Control de stock encendido') && str_contains($n->cuerpo, 'id="control-estado">Encendido<'), $flash());
ok('y queda quién lo encendió', (bool) valor("SELECT 1 FROM bitacora WHERE accion = 'tienda.control_on'"));

grupo('3e · el asesor vende');
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/stock/buscar?q=' . rawurlencode('Puesto'));
$j_e = json_decode($n->cuerpo, true);
es('EL BUSCADOR DICE QUE EL CONTROL ESTÁ ENCENDIDO', true, $j_e['control'] ?? null);
$venta_e = function (string $cant, bool $seguir = true) use ($n, $F, $P10) {
    $n->ir('/pedidos/nuevo?cliente=' . (int)$F['clientes']['asesor']);
    $n->ir('/pedidos/nuevo', [
        '_t' => $n->testigo(), 'f_id' => $n->oculto('f_id'),
        'tipo' => 'inmediata', 'cliente_id' => (int)$F['clientes']['asesor'],
        'entrega' => 'recojo', 'canal_item_id' => (int)$F['canal'],
        'l_desc' => ['Silla'], 'l_producto' => [(string)$P10], 'l_variante' => [''], 'l_sku' => [''],
        'l_cant' => [$cant], 'l_precio' => ['0'], 'fecha' => date('Y-m-d'),
        'modo_pago' => 'completo', 'pago_monto' => number_format(precio_de_variante($P10, null, (int)$cant) * (int)$cant / 100, 2, '.', ''),
        'pago_metodo_item_id' => (int)$F['efectivo'], 'pago_fecha' => date('Y-m-d'),
    ], $seguir);
};
$antes_e = $ultimo_ped();
$venta_e('5');
ok('SIN STOCK SUFICIENTE NO SE REGISTRA', $ultimo_ped() === $antes_e);
ok('y lo dice con los números', str_contains(html_entity_decode($n->cuerpo), 'quedan 3 y pides 5'), preg_match('~aviso--rojo.*?</div>\s*</div>~s', $n->cuerpo, $mm) ? mb_substr(strip_tags($mm[0]), 0, 400) : 'sin aviso');
es('la web no se tocó', 3, $wp_st());
$venta_e('2');
$PE1 = $ultimo_ped();
ok('CON STOCK, SE REGISTRA', $PE1 > $antes_e);
es('Y LA WEB BAJA A 1', 1, $wp_st());
ok('la ficha dice que se descontó', (bool) preg_match('~id="stock-venta">.*?Descontado del stock de la web~s', $n->cuerpo));

$wp_e(['caida' => true]);
$venta_e('1');
$PE2 = $ultimo_ped();
ok('CON LA TIENDA CAÍDA Y LA FOTO DICIENDO QUE HAY, SE REGISTRA', $PE2 > $PE1);
ok('con el aviso corto', str_contains($n->cuerpo, 'La tienda no respondió. El stock de la web se descontará solo'), mb_substr(strip_tags($n->cuerpo), 0, 200));
ok('y la ficha dice que falta', str_contains($n->cuerpo, 'Stock por descontar de la web'));
$venta_e('1');
ok('LA SIGUIENTE YA VE LA FOTO REBAJADA: NO HAY', $ultimo_ped() === $PE2 && str_contains(html_entity_decode($n->cuerpo), '«' . valor('SELECT nombre FROM productos WHERE id = ?', [$P10]) . '» está agotado'),
   ($ultimo_ped() === $PE2 ? '' : 'SE REGISTRÓ · ') . (preg_match('~aviso--rojo.*?</div>\s*</div>~s', $n->cuerpo, $mm) ? trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($mm[0])))) : 'sin aviso'));
$n->ir('/stock/pendientes', null, false);
es('EL ASESOR NO ABRE LA COLA', 403, $n->codigo);

grupo('3e · Administración ve lo que espera');
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
q("UPDATE stock_web_cola SET creado_en = ? WHERE pedido_id = ?", [date('Y-m-d H:i:s', time() - 3600), $PE2]);
$n->ir('/stock');
ok('EL CATÁLOGO AVISA', str_contains($n->cuerpo, 'id="aviso-cola"'));
$n->ir('/stock/pendientes');
ok('LA COLA ENSEÑA LA VENTA QUE ESPERA', (bool) preg_match('~id="lista-pendientes".*?' . preg_quote((string) valor('SELECT codigo FROM pedidos WHERE id = ?', [$PE2]), '~') . '.*?Descontar~s', $n->cuerpo));
ok('con lo que dijo la tienda', str_contains($n->cuerpo, 'No hubo respuesta de la tienda'));
$wp_e(['caida' => false]);
$n->ir('/stock/pendientes', ['_t' => $n->testigoValido(), 'accion' => 'reintentar'], false);
$n->ir('/stock/pendientes');
ok('«REINTENTAR TODO» CON LA TIENDA DE VUELTA LA PONE AL DÍA', str_contains($flash(), 'la web ya está al día') && str_contains($n->cuerpo, 'id="pendientes-vacio"'), $flash());
es('la web bajó a 0', 0, $wp_st());

$n->ir('/pedidos/anular', ['_t' => $n->testigoValido(), 'id' => $PE1, 'motivo_item_id' => (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id WHERE l.clave = 'motivos_anulacion' ORDER BY i.id LIMIT 1"), 'nota' => 'prueba'], false);
es('AL ANULAR, LA WEB RECUPERA LAS 2', 2, $wp_st());
$n->ir('/pedidos/ficha?id=' . $PE1);
ok('y la ficha lo dice', str_contains($n->cuerpo, 'Stock devuelto a la web'));

/* Productos escritos a mano: se apagan desde la misma tarjeta. */
$n->ir('/configuracion/tienda', ['_t' => $n->testigoValido(), 'accion' => 'libre', 'valor' => '0'], false);
es('«SOLO DEL CATÁLOGO» APAGA LOS ESCRITOS A MANO', '0', (string) valor("SELECT valor FROM ajustes WHERE clave = 'catalogo_libre'"));
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$antes_l = $ultimo_ped();
$n->ir('/pedidos/nuevo?cliente=' . (int)$F['clientes']['asesor']);
$n->ir('/pedidos/nuevo', ['_t' => $n->testigo(), 'f_id' => $n->oculto('f_id'), 'tipo' => 'inmediata', 'cliente_id' => (int)$F['clientes']['asesor'],
    'entrega' => 'recojo', 'canal_item_id' => (int)$F['canal'], 'l_desc' => ['Algo escrito a mano'], 'l_sku' => [''], 'l_cant' => ['1'],
    'l_precio' => ['10'], 'fecha' => date('Y-m-d'), 'modo_pago' => 'completo', 'pago_monto' => '10.00',
    'pago_metodo_item_id' => (int)$F['efectivo'], 'pago_fecha' => date('Y-m-d')]);
ok('y el asesor ya no puede venderlos', $ultimo_ped() === $antes_l && str_contains(html_entity_decode($n->cuerpo), 'del catálogo'));
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion/tienda', ['_t' => $n->testigoValido(), 'accion' => 'libre', 'valor' => '1'], false);
es('y se vuelven a dejar', '1', (string) valor("SELECT valor FROM ajustes WHERE clave = 'catalogo_libre'"));

/* El menú cuenta lo que Administración tiene que mirar (junto con lo que ya
   contaba: los lotes sin precio). */
$chip_stock = fn() => preg_match('~<a class="nv[^"]*" href="[^"]*/stock">(?:(?!</a>).)*?<span class="nv__chip">(\d+)</span>~s', $n->cuerpo, $mm) ? (int)$mm[1] : 0;
$n->ir('/inicio');
$chip0 = $chip_stock();
q("UPDATE stock_web_cola SET revisar = 1 WHERE pedido_id = ?", [$PE2]);
$n->ir('/inicio');
es('EL MENÚ «STOCK» CUENTA UNA MÁS PARA REVISAR', $chip0 + 1, $chip_stock());
q("UPDATE stock_web_cola SET revisar = 0 WHERE pedido_id = ?", [$PE2]);

/* La lista de pedidos mueve la cola de paso (por si el cron no está puesto). */
$wp_e(['caida' => true]);
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$venta_e('1');
$PE3 = $ultimo_ped();
$wp_e(['caida' => false]);
q("UPDATE stock_web_cola SET proximo_en = ? WHERE pedido_id = ?", [date('Y-m-d H:i:s', time() - 5), $PE3]);
q("DELETE FROM ajustes WHERE clave = 'stock_cola_ultima'");
$n->ir('/pedidos');
es('ABRIR LA LISTA DE PEDIDOS MUEVE LO QUE ESPERA', 'hecho', (string) valor("SELECT estado FROM stock_web_cola WHERE pedido_id = ? AND tipo = 'descuento'", [$PE3]));
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');

/* Cambiar la dirección de la tienda apaga el control y no manda lo que esperaba a la otra. */
$wp_e(['caida' => true]);
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$venta_e('1');
$PE4 = $ultimo_ped();
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
$url_e = (string) valor("SELECT valor FROM ajustes WHERE clave = 'woo_url'");
$n->ir('/configuracion/tienda', ['_t' => $n->testigoValido(), 'accion' => 'guardar', 'ruta' => 'https://otra-tienda.test',
                                 'key' => 'ck_' . str_repeat('6c', 20), 'secret' => 'cs_' . str_repeat('1f', 20)], false);
es('OTRA TIENDA: EL CONTROL SE APAGA', '0', (string) valor("SELECT valor FROM ajustes WHERE clave = 'control_stock'"));
es('y lo que esperaba queda para revisar a mano', ['cancelado', 1],
   array_values(array_map('intval_o_texto', una("SELECT estado, revisar FROM stock_web_cola WHERE pedido_id = ? AND tipo = 'descuento'", [$PE4]) ?? [])));
q("UPDATE ajustes SET valor = ? WHERE clave = 'woo_url'", [$url_e]);
q("UPDATE ajustes SET valor = '1' WHERE clave = 'control_stock'");
q("UPDATE ajustes SET valor = ? WHERE clave = 'conector_clave'", [$CLAVE_E]);

/* Uno se queda esperando a propósito, y uno para revisar: la revisión de
   pantallas (movil.cjs) mide la cola con filas. Sin clave del conector, la
   cola no llama a nadie durante esa revisión. */
$wp_e(['caida' => true]);
$n->salir();
$n->entrar('asesor@waka.test', 'Clave-Larga-1');
$venta_e('1');
$n->salir();
$n->entrar('admin@waka.test', 'Clave-Larga-1');
q("UPDATE stock_web_cola SET revisar = 1, negativo = 'No hay stock suficiente. «Silla»: quedan 0 y pides 1.' WHERE pedido_id = ?", [$PE2]);
ok('queda una esperando para la revisión de pantallas', stock_cola_pendientes_n() >= 1 || (int) valor("SELECT COUNT(*) FROM stock_web_cola WHERE estado = 'pendiente'") >= 1);
q("UPDATE ajustes SET valor = '' WHERE clave = 'conector_clave'");
@unlink($WP_ARCHIVO);


/* ══════════════ 3f · FOTO DEL DNI, CÁMARA, CATEGORÍAS, COLORES Y LA BANDEJA ══════════════ */
grupo('3f · Configuración › Métodos de pago');
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
/* Los métodos que ve Perú (los de todos y los suyos). */
$MET = fn(string $v) => una("SELECT i.* FROM lista_items i JOIN listas l ON l.id = i.lista_id WHERE l.clave = 'metodos_pago' AND i.valor = ?
                              AND (i.pais_id IS NULL OR i.pais_id = (SELECT pais_id FROM usuarios WHERE email = 'admin@waka.test'))", [$v]);
$n->ir('/configuracion');
ok('Configuración tiene «Métodos de pago»', str_contains($n->cuerpo, '/configuracion/metodos-pago'));
$n->ir('/configuracion/metodos-pago');
$pos_f = $MET('POS / tarjeta');
ok('LA PANTALLA ENSEÑA CADA MÉTODO CON LO QUE PIDE', $n->codigo === 200
   && (bool) preg_match('~data-metodo="' . (int)$pos_f['id'] . '".*?name="pide_dni" value="1" checked~s', $n->cuerpo));
es('EL POS NACE PIDIENDO LA FOTO DEL DNI', 1, (int)$pos_f['pide_dni']);
$yape_f = $MET('Yape');
$n->ir('/configuracion/metodos-pago', ['_t' => $n->testigoValido(), 'accion' => 'guardar', 'id' => (int)$yape_f['id'],
    'valor' => 'Yape', 'pide_comprobante' => '1', 'pide_dni' => '1', 'activo' => '1', 'recargo' => '4,5'], false);
$yape_f = $MET('Yape');
es('SE MARCA «PIDE FOTO DEL DNI» Y EL RECARGO (4,5 % = 450)', [1, 450], [(int)$yape_f['pide_dni'], (int)$yape_f['recargo_centesimas']]);
$n->ir('/configuracion/metodos-pago', ['_t' => $n->testigoValido(), 'accion' => 'guardar', 'id' => (int)$yape_f['id'],
    'valor' => 'Yape', 'pide_comprobante' => '1', 'activo' => '1', 'recargo' => 'mucho']);
ok('un recargo que no es número no se guarda', str_contains($n->cuerpo, 'El recargo es un porcentaje') && (int)$MET('Yape')['recargo_centesimas'] === 450);
$n->ir('/configuracion/metodos-pago', ['_t' => $n->testigoValido(), 'accion' => 'guardar', 'id' => (int)$yape_f['id'],
    'valor' => 'Plin', 'pide_comprobante' => '1', 'activo' => '1', 'recargo' => '']);
ok('ni un nombre repetido', str_contains($n->cuerpo, 'Ya hay un método con ese nombre'));
$n->ir('/configuracion/metodos-pago', ['_t' => $n->testigoValido(), 'accion' => 'guardar', 'id' => (int)$yape_f['id'],
    'valor' => 'Yape', 'pide_comprobante' => '1', 'activo' => '1', 'recargo' => ''], false);
es('y se desmarca', [0, 0], [(int)$MET('Yape')['pide_dni'], (int)$MET('Yape')['recargo_centesimas']]);
$n->ir('/configuracion/metodos-pago', ['_t' => $n->testigoValido(), 'accion' => 'nuevo', 'valor' => 'Izipay'], false);
es('UN MÉTODO NUEVO SE AÑADE (pidiendo voucher)', [1, 1], [(int)($MET('Izipay')['activo'] ?? 0), (int)($MET('Izipay')['pide_comprobante'] ?? 0)]);
$n->ir('/configuracion/metodos-pago', ['_t' => $n->testigoValido(), 'accion' => 'guardar', 'id' => (int)$MET('Izipay')['id'],
    'valor' => 'Izipay', 'pide_comprobante' => '1', 'recargo' => ''], false);
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/nuevo');
ok('APAGADO, YA NO SE OFRECE AL VENDER', !str_contains($n->cuerpo, '>Izipay<') && !preg_match('~>\s*Izipay\s*</option>~', $n->cuerpo));
ok('EL FORMULARIO SABE QUÉ MÉTODO PIDE EL DNI', (bool) preg_match('~value="' . (int)$pos_f['id'] . '"[^>]*data-dni="1"~s', $n->cuerpo)
   && str_contains($n->cuerpo, 'data-metodo-fotos'));
ok('Y OFRECE LA CÁMARA para el voucher y el DNI', substr_count($n->cuerpo, 'capture="environment"') >= 2
   && str_contains($n->cuerpo, 'name="foto_dni_cam"') && str_contains($n->cuerpo, 'name="voucher_cam"'));
$n->ir('/configuracion/metodos-pago', null, false);
es('LA PANTALLA NO ES PARA EL ASESOR', 403, $n->codigo);

grupo('3f · el POS pide la foto del DNI');
/* $recargar = false: se vuelve a mandar desde la pantalla del error, como hace
   la persona (el mismo formulario, con su voucher en espera). */
$pos_venta = function (array $archivos, bool $recargar = true) use ($n, $F, $pos_f) {
    if ($recargar) $n->ir('/pedidos/nuevo?cliente=' . (int)$F['clientes']['asesor']);
    $n->subir('/pedidos/nuevo', [
        '_t' => $n->testigo(), 'f_id' => $n->oculto('f_id'),
        'tipo' => 'inmediata', 'cliente_id' => (int)$F['clientes']['asesor'],
        'entrega' => 'recojo', 'canal_item_id' => (int)$F['canal'],
        'l_desc' => ['Algo de prueba DNI'], 'l_sku' => [''], 'l_cant' => ['1'], 'l_precio' => ['100'],
        'fecha' => date('Y-m-d'), 'modo_pago' => 'completo', 'pago_monto' => '100.00',
        'pago_metodo_item_id' => (int)$pos_f['id'], 'pago_fecha' => date('Y-m-d'),
    ], $archivos);
};
$antes_dni = $ultimo_ped();
$pos_venta(['voucher_cam' => ['foto.jpg', 'image/jpeg', $JPG]]);
ok('SIN LA FOTO DEL DNI NO SE REGISTRA', $ultimo_ped() === $antes_dni && str_contains($n->cuerpo, 'necesita también la foto del DNI'));
ok('y el voucher (de la cámara) se queda adjunto para el siguiente intento', str_contains($n->cuerpo, 'Adjunto: foto.jpg'));
$pos_venta(['foto_dni_cam' => ['dni.jpg', 'image/jpeg', $JPG]], false);
$PDNI = $ultimo_ped();
ok('CON LAS DOS FOTOS, SE REGISTRA', $PDNI > $antes_dni, mb_substr(strip_tags($n->cuerpo), 0, 200));
$pg_dni = una('SELECT * FROM pagos WHERE pedido_id = ? ORDER BY id DESC LIMIT 1', [$PDNI]);
ok('el pago guarda las dos: el voucher y el DNI', (string)$pg_dni['voucher'] !== '' && (string)$pg_dni['foto_dni'] !== ''
   && $pg_dni['voucher'] !== $pg_dni['foto_dni']);
$n->ir('/pagos/voucher?que=dni&id=' . (int)$pg_dni['id']);
ok('LA FOTO DEL DNI SE VE', $n->codigo === 200 && str_starts_with($n->cuerpo, "\xFF\xD8"));
$pg_sin = (int) valor("SELECT id FROM pagos WHERE voucher IS NOT NULL AND foto_dni IS NULL ORDER BY id DESC LIMIT 1");
$n->ir('/pagos/voucher?que=dni&id=' . $pg_sin, null, false);
es('un pago sin foto del DNI da 404', 404, $n->codigo);
$n->salir(); $n->entrar('otro@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/voucher?que=dni&id=' . (int)$pg_dni['id'], null, false);
es('OTRO ASESOR NO VE ESA FOTO', 403, $n->codigo);
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
/* Desde la ficha, un pago posterior con POS. */
q('UPDATE pedidos SET total_centimos = total_centimos + 5000 WHERE id = ?', [$PDNI]);
$n->ir('/pedidos/ficha?id=' . $PDNI);
$n->subir('/pagos/registrar', ['_t' => $n->testigoValido(), 'pedido_id' => $PDNI, 'monto' => '50.00', 'metodo_item_id' => (int)$pos_f['id'],
    'fecha' => date('Y-m-d'), 'concepto' => 'Saldo'], ['voucher' => ['v2.jpg', 'image/jpeg', $JPG]], false);
$n->ir('/pedidos/ficha?id=' . $PDNI);
ok('EN LA FICHA, TAMBIÉN LO PIDE', str_contains($n->cuerpo, 'necesita también la foto del DNI'));
$n->subir('/pagos/registrar', ['_t' => $n->testigoValido(), 'pedido_id' => $PDNI, 'monto' => '50.00', 'metodo_item_id' => (int)$pos_f['id'],
    'fecha' => date('Y-m-d'), 'concepto' => 'Saldo'], ['foto_dni' => ['dni2.jpg', 'image/jpeg', $JPG]], false);
es('y con la foto (el voucher seguía adjunto), se registra', 2, (int) valor('SELECT COUNT(*) FROM pagos WHERE pedido_id = ? AND foto_dni IS NOT NULL', [$PDNI]));

grupo('3f · Pagos por validar: qué se vende y la venta encima');
$n->salir(); $n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/por-validar?vd=' . date('Y-m-d', strtotime('-3 days')));
ok('CADA PAGO DICE QUÉ SE VENDE', str_contains($n->cuerpo, '1× Algo de prueba DNI'));
ok('con «VER DNI» donde lo hay', str_contains($n->cuerpo, 'que=dni&amp;id=' . (int)$pg_dni['id']));
ok('Y CON «VER VENTA»', (bool) preg_match('~href="[^"]*/pagos/por-validar\?vd=[^"]*ver=' . $PDNI . '"[^>]*data-ver-venta~', $n->cuerpo));
$n->ir('/pagos/por-validar?vd=' . date('Y-m-d', strtotime('-3 days')) . '&ver=' . $PDNI);
ok('LA VENTA SALE ENCIMA, CON LO QUE LLEVA Y SUS CIFRAS', (bool) preg_match('~id="tapa-venta".*?' . preg_quote((string) valor('SELECT codigo FROM pedidos WHERE id = ?', [$PDNI]), '~')
   . '.*?1× Algo de prueba DNI.*?Total.*?Cobrado.*?Por cobrar.*?ABRIR LA VENTA~s', $n->cuerpo));
ok('y cerrarla vuelve a la bandeja con los filtros', (bool) preg_match('~class="tapa__x" href="[^"]*/pagos/por-validar\?vd=' . date('Y-m-d', strtotime('-3 days')) . '"~', $n->cuerpo));
$n->ir('/pagos/por-validar?ver=999999');
ok('una venta que no existe no abre nada', !str_contains($n->cuerpo, 'id="tapa-venta"'));
/* Validados: se valida el del DNI y sale abajo con lo mismo. */
$n->ir('/pagos/validar', ['_t' => $n->testigoValido(), 'id' => (int)$pg_dni['id'], 'operacion' => 'OP-DNI-1'], false);
$n->ir('/pagos/por-validar');
ok('EN VALIDADOS TAMBIÉN: qué se vendió y VER VENTA', (bool) preg_match('~Validados.*?1× Algo de prueba DNI.*?ver=' . $PDNI . '~s', $n->cuerpo));
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos');
ok('la lista de pedidos sigue diciendo qué lleva cada venta', str_contains($n->cuerpo, '1× Algo de prueba DNI'));

grupo('3f · colores de un producto de la web');
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
$P10f = (int) valor("SELECT id FROM productos WHERE woo_id = 8110");
$n->ir('/stock/producto?id=' . $P10f);
ok('EN UNO DE LA WEB TAMPOCO SE OFRECE AÑADIR COLORES', str_contains($n->cuerpo, 'id="nota-colores-web"')
   && !str_contains($n->cuerpo, 'Añadir modelo o color'));
ok('y la categoría se ve, no se cambia', (bool) preg_match('~<select name="categoria" id="campo-categoria"\s+disabled~', $n->cuerpo));
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'variante', 'id' => $P10f, 'color' => 'Verde', 'medida' => '']);
ok('Y A MANO TAMPOCO ENTRA', str_contains($n->cuerpo, 'se crean en la web')
   && !valor("SELECT 1 FROM variantes WHERE producto_id = ? AND color = 'Verde'", [$P10f]));
$P_libre = (int) valor("SELECT id FROM productos WHERE woo_id IS NULL AND activo = 1 ORDER BY id LIMIT 1");
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'variante', 'id' => $P_libre, 'color' => 'Violeta', 'medida' => ''], false);
ok('NI EN UNO QUE SOLO ESTÁ EN EL HUB', !valor("SELECT 1 FROM variantes WHERE producto_id = ? AND color = 'Violeta'", [$P_libre]));


grupo('3f · lo que encontró la auditoría');
$n->salir(); $n->entrar('adminmx@waka.test', 'Clave-Larga-1');
$pos_pe = $MET('POS / tarjeta');
$n->ir('/configuracion/metodos-pago', ['_t' => $n->testigoValido(), 'accion' => 'guardar', 'id' => (int)$pos_pe['id'],
    'valor' => 'POS MX', 'pide_comprobante' => '1', 'recargo' => ''], false);
$pos_pe2 = una("SELECT * FROM lista_items WHERE lista_id = ? AND pais_id = (SELECT pais_id FROM usuarios WHERE email = 'adminmx@waka.test')
                 AND valor = 'POS MX'", [(int)$pos_pe['lista_id']]) ?? [];
$pos_otro = una('SELECT * FROM lista_items WHERE id = ?', [(int)$pos_pe['id']]);
es('MÉXICO CAMBIA SU POS: EL DE MÉXICO QUEDA COMO LO DEJÓ', ['POS MX', 0, 0], [$pos_pe2['valor'], (int)$pos_pe2['activo'], (int)$pos_pe2['pide_dni']]);
es('Y PERÚ CONSERVA EL SUYO, INTACTO', [1, 1, 400, (int) valor("SELECT pais_id FROM usuarios WHERE email = 'admin@waka.test'")],
   [(int)($pos_otro['activo'] ?? 0), (int)($pos_otro['pide_dni'] ?? 0), (int)($pos_otro['recargo_centesimas'] ?? 0), (int)($pos_otro['pais_id'] ?? 0)]);
es('el de México ya es de México', (int) valor("SELECT pais_id FROM usuarios WHERE email = 'adminmx@waka.test'"), (int)$pos_pe2['pais_id']);
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion/metodos-pago');
ok('en Perú no se ve el POS de México', !str_contains($n->cuerpo, 'value="POS MX"'));
$pag_pe = (int) valor("SELECT pg.id FROM pagos pg JOIN pedidos pe ON pe.id = pg.pedido_id WHERE pg.metodo_item_id = ? LIMIT 1", [(int)$pos_pe['id']]);
es('LOS PAGOS VIEJOS DE PERÚ SIGUEN DICIENDO SU MÉTODO (la fila de siempre se quedó con Perú)', 'POS / tarjeta',
   (string) valor('SELECT i.valor FROM pagos pg JOIN lista_items i ON i.id = pg.metodo_item_id WHERE pg.id = ?', [$pag_pe]));

/* Renombrar uno de fábrica no lo hace volver en la siguiente actualización. */
$dep = $MET('Depósito');
$n->ir('/configuracion/metodos-pago', ['_t' => $n->testigoValido(), 'accion' => 'guardar', 'id' => (int)$dep['id'],
    'valor' => 'Depósito BCP', 'pide_comprobante' => '1', 'activo' => '1', 'recargo' => ''], false);
sembrar_listas();
es('RENOMBRADO, LA ACTUALIZACIÓN NO VUELVE A CREAR EL DE FÁBRICA', null, $MET('Depósito'));
es('y el renombrado sigue ahí', 1, (int)($MET('Depósito BCP')['activo'] ?? 0));
/* Siempre queda uno encendido. */
$lid_m = (int)$dep['lista_id'];
$pe_id = (int) valor("SELECT pais_id FROM usuarios WHERE email = 'admin@waka.test'");
$encendidos = array_column(todas('SELECT id FROM lista_items WHERE lista_id = ? AND activo = 1 AND (pais_id IS NULL OR pais_id = ?)', [$lid_m, $pe_id]), 'id');
$queda = (int) array_pop($encendidos);
if ($encendidos) q('UPDATE lista_items SET activo = 0 WHERE id IN (' . implode(',', array_map('intval', $encendidos)) . ')');
$ult = una('SELECT * FROM lista_items WHERE id = ?', [$queda]);
$n->ir('/configuracion/metodos-pago', ['_t' => $n->testigoValido(), 'accion' => 'guardar', 'id' => $queda,
    'valor' => (string)$ult['valor'], 'pide_comprobante' => '1', 'recargo' => '']);
ok('EL ÚLTIMO ENCENDIDO NO SE APAGA', str_contains($n->cuerpo, 'Tiene que quedar al menos un método encendido')
   && (int) valor('SELECT activo FROM lista_items WHERE id = ?', [$queda]) === 1);
if ($encendidos) q('UPDATE lista_items SET activo = 1 WHERE id IN (' . implode(',', array_map('intval', $encendidos)) . ')');

/* La ficha: un voucher malo no se lleva la foto del DNI buena. */
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
q('UPDATE pedidos SET total_centimos = total_centimos + 20000 WHERE id = ?', [$PDNI]);
$GIF = base64_decode('R0lGODlhAQABAAAAACw=');
$n->ir('/pedidos/ficha?id=' . $PDNI);
$n->subir('/pagos/registrar', ['_t' => $n->testigoValido(), 'pedido_id' => $PDNI, 'monto' => '50.00', 'metodo_item_id' => (int)$pos_otro['id'],
    'fecha' => date('Y-m-d'), 'concepto' => 'Otro saldo'], ['voucher' => ['v.gif', 'image/gif', $GIF], 'foto_dni' => ['dni3.jpg', 'image/jpeg', $JPG]], false);
/* Se sigue a donde manda el HUB, no a una dirección escrita aquí. */
$n->ir(preg_replace('~#.*$~', '', (string) parse_url($destino(), PHP_URL_PATH) . '?' . (string) parse_url($destino(), PHP_URL_QUERY)));
ok('UN VOUCHER MALO NO SE LLEVA LA FOTO DEL DNI', str_contains($n->cuerpo, 'Adjunto: dni3.jpg'),
   $n->codigo . ' ' . (preg_match('~aviso--rojo.*?</div>~s', $n->cuerpo, $mm) ? trim(preg_replace('/\s+/', ' ', strip_tags($mm[0]))) : 'sin aviso') . ' | ' . (preg_match('~id="fc-foto_dni".{0,600}~s', $n->cuerpo, $m2) ? trim(preg_replace('/\s+/', ' ', strip_tags($m2[0]))) : 'sin caja'));
ok('y al volver con el método elegido, la caja del DNI está a la vista', (bool) preg_match('~id="fc-foto_dni"(?![^>]*hidden)~', $n->cuerpo)
   && (bool) preg_match('~value="' . (int)$pos_otro['id'] . '"[^>]*selected~s', $n->cuerpo));
$n->subir('/pagos/registrar', ['_t' => $n->testigoValido(), 'pedido_id' => $PDNI, 'monto' => '50.00', 'metodo_item_id' => (int)$pos_otro['id'],
    'fecha' => date('Y-m-d'), 'concepto' => 'Otro saldo'], ['voucher' => ['v3.jpg', 'image/jpeg', $JPG]], false);
es('con el voucher bueno se registra, con la foto del DNI de antes', 3, (int) valor('SELECT COUNT(*) FROM pagos WHERE pedido_id = ? AND foto_dni IS NOT NULL', [$PDNI]));

/* Una foto de la cámara que llega «de lado» se guarda derecha. */
$gi = imagecreatetruecolor(400, 200); ob_start(); imagejpeg($gi); $plano = ob_get_clean();
$tiff = "II*\x00\x08\x00\x00\x00" . "\x01\x00" . "\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00" . "\x00\x00\x00\x00";
$app1 = "Exif\x00\x00" . $tiff;
$girada = "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($plano, 2);
es('(la foto de prueba dice «girar»)', 6, (int)(@exif_read_data('data://image/jpeg;base64,' . base64_encode($girada))['Orientation'] ?? 0));
q('UPDATE pedidos SET total_centimos = total_centimos + 20000 WHERE id = ?', [$PDNI]);
$n->ir('/pedidos/ficha?id=' . $PDNI);
$n->subir('/pagos/registrar', ['_t' => $n->testigoValido(), 'pedido_id' => $PDNI, 'monto' => '50.00', 'metodo_item_id' => (int)$pos_otro['id'],
    'fecha' => date('Y-m-d'), 'concepto' => 'Girada'], ['voucher' => ['v4.jpg', 'image/jpeg', $JPG], 'foto_dni_cam' => ['girada.jpg', 'image/jpeg', $girada]], false);
$dni_g = (string) valor('SELECT foto_dni FROM pagos WHERE pedido_id = ? ORDER BY id DESC LIMIT 1', [$PDNI]);
$dim = @getimagesize(ruta_voucher($dni_g));
es('LA FOTO DEL DNI QUEDA DERECHA (200 × 400)', [200, 400], [$dim[0] ?? 0, $dim[1] ?? 0]);

/* Un envío que pasa del tamaño que acepta el servidor lo dice como es. */
$n->ir('/pedidos/ficha?id=' . $PDNI);
$n->subir('/pagos/registrar', ['_t' => $n->testigoValido(), 'pedido_id' => $PDNI, 'monto' => '1.00', 'metodo_item_id' => (int)$pos_otro['id']],
    ['voucher' => ['enorme.jpg', 'image/jpeg', str_repeat('x', 9 * 1024 * 1024)]], false);
ok('DOS FOTOS QUE PASAN DEL LÍMITE: «LAS FOTOS PESAN DEMASIADO», NO «LA PÁGINA CADUCÓ»', $n->codigo === 413 && str_contains($n->cuerpo, 'Las fotos pesan demasiado'),
   $n->codigo . ' ' . mb_substr(strip_tags($n->cuerpo), 0, 120));

/* La categoría de uno de la web no se cambia con un POST a mano. */
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
$cat_p10 = (string) valor('SELECT categoria FROM productos WHERE id = ?', [$P10f]);
/* Una categoría que SÍ está en la lista de la web, para que lo único que la
   frene sea que el producto es de la web. */
$cat_otra = (string) (array_values(array_diff(categorias_del_catalogo(), [$cat_p10]))[0] ?? '');
ok('(hay otra categoría en la lista para probar)', $cat_otra !== '');
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'producto', 'id' => $P10f,
    'nombre' => (string) valor('SELECT nombre FROM productos WHERE id = ?', [$P10f]), 'sku' => (string) valor('SELECT sku FROM productos WHERE id = ?', [$P10f]),
    'categoria' => $cat_otra, 'garantia_item_id' => '', 'activo' => '1'], false);
es('EN UNO DE LA WEB LA CATEGORÍA NO CAMBIA NI A MANO', $cat_p10, (string) valor('SELECT categoria FROM productos WHERE id = ?', [$P10f]));

/* El resumen solo de lo que se puede ver: Facturación de México no abre una venta de Perú. */
$n->salir(); $n->entrar('factumx@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/por-validar?ver=' . $PDNI);
ok('LA VENTA DE OTRO PAÍS NO SE ABRE ENCIMA', $n->codigo === 200 && !str_contains($n->cuerpo, 'id="tapa-venta"'));

/* Cerrar el resumen no vuelve a abrir la ventana del pago decidido. */
$n->salir(); $n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/pagos/por-validar?ver=' . $PDNI . '&hecho=aprobado&ped=' . $PDNI);
es('CERRAR EL RESUMEN VUELVE A LA BANDEJA LIMPIA', '/pagos/por-validar',
   preg_match('~id="tapa-venta">\s*<div class="tapa__caja[^"]*">\s*<a class="tapa__x" href="([^"]*)"~', $n->cuerpo, $mm) ? html_entity_decode($mm[1]) : '');

/* ══════════════════════ 3g · ALMACÉN Y MARKETING ══════════════════════ */
grupo('3g · Almacén y Marketing, por la pantalla');

/* NINGÚN ENLACE QUE SE LES ENSEÑA CONTESTA 403. Se recorre todo lo que se
   alcanza desde el Inicio siguiendo enlaces: un botón que no funciona enseña
   a desconfiar de los demás. */
$recorrer = function (string $email) use ($n): array {
    $n->salir(); $n->entrar($email, 'Clave-Larga-1');
    $cola = ['/inicio']; $visto = []; $malos = [];
    while ($cola && count($visto) < 160) {
        $r = array_shift($cola);
        if (isset($visto[$r])) continue;
        $visto[$r] = true;
        $n->ir($r);
        if ($n->codigo !== 200) { $malos[] = $r . ' → ' . $n->codigo; continue; }
        preg_match_all('~<a\b[^>]*\bhref="(/[^"#]*)~', $n->cuerpo, $mm);
        foreach ($mm[1] as $h) {
            $h = html_entity_decode($h);
            if ($h === '/' || str_starts_with($h, '//') || str_starts_with($h, '/salir') || str_starts_with($h, '/assets')
                || str_starts_with($h, '/uploads') || str_starts_with($h, '/pagos/voucher')) continue;
            if (!isset($visto[$h])) $cola[] = $h;
        }
    }
    return [$malos, count($visto)];
};
[$malos_a, $vistos_a] = $recorrer('almacen@waka.test');
es('ALMACÉN: NINGÚN ENLACE QUE VE LE CONTESTA 403', [], $malos_a);
ok('(y se recorrieron varias pantallas)', $vistos_a >= 5, (string)$vistos_a);
[$malos_m, $vistos_m] = $recorrer('marketing@waka.test');
es('MARKETING: TAMPOCO', [], $malos_m);
ok('(y se recorrieron varias pantallas)', $vistos_m >= 5, (string)$vistos_m);

/* Almacén: alista lo que el asesor mandó a despacho. */
$n->salir(); $n->entrar('almacen@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('SU INICIO NO ENSEÑA DINERO', $n->codigo === 200 && !str_contains($n->cuerpo, 'Vendido hoy') && !str_contains($n->cuerpo, 'Por cobrar'));
ok('y le dice cuántos pedidos hay por alistar', str_contains($n->cuerpo, 'id="cifra-alistar"') && !str_contains($n->cuerpo, 'id="cifra-sin-precio"'));
$n->ir('/stock');
ok('ALMACÉN PUEDE ACTUALIZAR EL STOCK LEÍDO DE LA WEB', str_contains($n->cuerpo, 'id="form-stock"'));
$n->ir('/stock/leer', ['_t' => $n->testigoValido()], false);
ok('y lo actualiza', $n->codigo === 302 && str_ends_with($destino(), '/stock'), $n->codigo . ' ' . $destino());

/* Un pedido mandado a despacho y sin alistar, con su modelo. */
$PA = (int) valor('SELECT pe.id FROM pedidos pe WHERE pe.anulado_en IS NULL AND pe.pais_id = ? AND '
                  . sql_despacho_listo() . ' ORDER BY pe.id LIMIT 1', [(int)$F['PE']]);
ok('(hay una venta lista para mandar)', $PA > 0);
q('UPDATE pedidos SET despacho_veces = 1, despacho_en = ?, alistado_en = NULL WHERE id = ?', [date('Y-m-d H:i:s'), $PA]);
q("UPDATE pedido_lineas SET modelo = 'Rojo mate' WHERE id = (SELECT MIN(id) FROM pedido_lineas WHERE pedido_id = ?)", [$PA]);
$lin_a = una('SELECT descripcion, cantidad FROM pedido_lineas WHERE pedido_id = ? ORDER BY id LIMIT 1', [$PA]);
$cod_a = (string) valor('SELECT codigo FROM pedidos WHERE id = ?', [$PA]);
$n->ir('/pedidos/por-alistar');
es('ALMACÉN ABRE «POR ALISTAR»', 200, $n->codigo);
ok('SIN EL EQUIPO DE DESPACHO, LO DICE Y NO DEJA MARCAR', str_contains($n->cuerpo, 'id="aviso-sin-equipo"'));
ok('ENTRA EL PEDIDO MANDADO, CON LO QUE LLEVA Y SU MODELO', str_contains($n->cuerpo, 'id="alistar-' . $PA . '"') && str_contains($n->cuerpo, e($cod_a))
   && str_contains($n->cuerpo, e((string)$lin_a['descripcion'])) && str_contains($n->cuerpo, '<span class="alistar__modelo">Rojo mate</span>'));
ok('y las indicaciones del envío (el mensaje del grupo)', (bool) preg_match('~id="alistar-' . $PA . '".*?Ver indicaciones del envío~s', $n->cuerpo));
ok('sin enlaces a la venta ni dinero', !str_contains($n->cuerpo, '/pedidos/ficha?id='));

/* Administración pone el equipo. */
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion');
ok('CONFIGURACIÓN TIENE «EQUIPO DE DESPACHO»', str_contains($n->cuerpo, '/configuracion/equipo-despacho'));
$n->ir('/configuracion/equipo-despacho', ['_t' => $n->testigoValido(), 'accion' => 'anadir', 'nombre' => 'Juan Almacén']);
$n->ir('/configuracion/equipo-despacho', ['_t' => $n->testigoValido(), 'accion' => 'anadir', 'nombre' => 'juan  almacen']);
ok('UN NOMBRE REPETIDO NO ENTRA (aunque cambien tildes y mayúsculas)', str_contains($n->cuerpo, 'Ya está en la lista.'));
$n->ir('/configuracion/equipo-despacho', ['_t' => $n->testigoValido(), 'accion' => 'anadir', 'nombre' => 'Pedro']);
$JUAN = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id WHERE l.clave = 'equipo_despacho' AND i.valor = 'Juan Almacén'");
$PEDRO = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id WHERE l.clave = 'equipo_despacho' AND i.valor = 'Pedro'");
ok('se añaden', $JUAN > 0 && $PEDRO > 0);
$n->ir('/configuracion/equipo-despacho', ['_t' => $n->testigoValido(), 'accion' => 'renombrar', 'id' => $PEDRO, 'nombre' => 'Pedro Ruiz']);
$n->ir('/configuracion/equipo-despacho', ['_t' => $n->testigoValido(), 'accion' => 'estado', 'id' => $PEDRO, 'encender' => '0']);
es('SE RENOMBRA Y SE APAGA, NO SE BORRA', ['Pedro Ruiz', 0], array_values(array_map(fn($v) => is_numeric($v) ? (int)$v : $v, una('SELECT valor, activo FROM lista_items WHERE id = ?', [$PEDRO]))));
$n->salir(); $n->entrar('adminmx@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion/equipo-despacho');
ok('OTRO PAÍS NO VE NI TOCA ESA LISTA', !str_contains($n->cuerpo, 'Juan Almacén'));
$n->ir('/configuracion/equipo-despacho', ['_t' => $n->testigoValido(), 'accion' => 'estado', 'id' => $JUAN, 'encender' => '0']);
ok('(le contesta que no está)', $n->codigo === 404 && (int) valor('SELECT activo FROM lista_items WHERE id = ?', [$JUAN]) === 1);

/* Almacén alista. */
$n->salir(); $n->entrar('almacen@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/por-alistar');
ok('ya sale «¿Quién lo alistó?» con Juan, y no el apagado', str_contains($n->cuerpo, '>Juan Almacén</option>') && !str_contains($n->cuerpo, 'Pedro Ruiz'));
$n->subir('/pedidos/por-alistar', ['_t' => $n->testigoValido(), 'accion' => 'alistado', 'id' => $PA, 'quien_id' => $JUAN], []);
ok('SIN FOTO NO SE MARCA', str_contains($n->cuerpo, 'Falta la foto de lo alistado') && valor('SELECT alistado_en FROM pedidos WHERE id = ?', [$PA]) === null);
$n->subir('/pedidos/por-alistar', ['_t' => $n->testigoValido(), 'accion' => 'alistado', 'id' => $PA, 'quien_id' => ''],
          ['foto_alistado_cam' => ['caja.jpg', 'image/jpeg', $JPG]]);
ok('SIN QUIÉN TAMPOCO, PERO LA FOTO SE GUARDA PARA EL SIGUIENTE INTENTO', str_contains($n->cuerpo, 'Elige quién lo alistó') && str_contains($n->cuerpo, 'Adjunto: caja.jpg'));
$n->subir('/pedidos/por-alistar', ['_t' => $n->testigoValido(), 'accion' => 'alistado', 'id' => $PA, 'quien_id' => $JUAN], []);
ok('CON QUIÉN Y LA FOTO DE ANTES: ALISTADO', str_contains($n->cuerpo, e($cod_a) . ' quedó alistado') && valor('SELECT alistado_en FROM pedidos WHERE id = ?', [$PA]) !== null,
   mb_substr(strip_tags($n->cuerpo), 0, 300));
ok('sale de la lista y pasa a «Alistados hoy», por Juan', !str_contains($n->cuerpo, 'id="alistar-' . $PA . '"')
   && (bool) preg_match('~id="alistados-hoy".*?' . preg_quote(e($cod_a), '~') . '.*?por Juan Almacén~s', $n->cuerpo));
es('queda quién lo marcó', (int) valor("SELECT id FROM usuarios WHERE email = 'almacen@waka.test'"), (int) valor('SELECT alistado_por FROM pedidos WHERE id = ?', [$PA]));
$n->ir('/pedidos/alistado-foto?id=' . $PA);
ok('VE LA FOTO', $n->codigo === 200 && str_starts_with($n->cuerpo, "\xFF\xD8"));
$n->ir('/pedidos/por-alistar', ['_t' => $n->testigoValido(), 'accion' => 'deshacer', 'id' => $PA]);
ok('DESHACER LO DEVUELVE A LA LISTA', str_contains($n->cuerpo, 'id="alistar-' . $PA . '"') && valor('SELECT alistado_en FROM pedidos WHERE id = ?', [$PA]) === null);
$n->subir('/pedidos/por-alistar', ['_t' => $n->testigoValido(), 'accion' => 'alistado', 'id' => $PA, 'quien_id' => $JUAN],
          ['foto_alistado' => ['caja2.jpg', 'image/jpeg', $JPG]]);
ok('(alistado otra vez)', valor('SELECT alistado_en FROM pedidos WHERE id = ?', [$PA]) !== null);
$n->subir('/pedidos/por-alistar', ['_t' => $n->testigoValido(), 'accion' => 'alistado', 'id' => $PA, 'quien_id' => $JUAN],
          ['foto_alistado' => ['caja3.jpg', 'image/jpeg', $JPG]]);
ok('UN ERROR DE UN PEDIDO QUE YA NO ESTÁ EN LA LISTA SE DICE ARRIBA', str_contains($n->cuerpo, 'id="aviso-alistar-error"') && str_contains($n->cuerpo, 'ya estaba alistado'));
/* Un PDF no vale como foto de lo alistado. */
$PA3 = (int) valor('SELECT pe.id FROM pedidos pe WHERE pe.anulado_en IS NULL AND pe.pais_id = ? AND pe.despacho_veces = 0 AND '
                   . sql_despacho_listo() . ' ORDER BY pe.id DESC LIMIT 1', [(int)$F['PE']]);
q('UPDATE pedidos SET despacho_veces = 1, despacho_en = ? WHERE id = ?', [date('Y-m-d H:i:s'), $PA3]);
$n->subir('/pedidos/por-alistar', ['_t' => $n->testigoValido(), 'accion' => 'alistado', 'id' => $PA3, 'quien_id' => $JUAN],
          ['foto_alistado' => ['caja.pdf', 'application/pdf', "%PDF-1.4
%%EOF"]]);
ok('UN PDF NO VALE COMO FOTO DE LO ALISTADO', str_contains($n->cuerpo, 'no un PDF') && valor('SELECT alistado_en FROM pedidos WHERE id = ?', [$PA3]) === null);
q('UPDATE pedidos SET despacho_veces = 0, despacho_en = NULL WHERE id = ?', [$PA3]);
/* El nombre de quien ya alistó no se cambia. */
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion/equipo-despacho', ['_t' => $n->testigoValido(), 'accion' => 'renombrar', 'id' => $JUAN, 'nombre' => 'Otro nombre']);
ok('QUIEN YA ALISTÓ NO SE RENOMBRA (los pedidos dirían otro nombre)', str_contains($n->cuerpo, 'ya alistó pedidos')
   && (string) valor('SELECT valor FROM lista_items WHERE id = ?', [$JUAN]) === 'Juan Almacén');
$n->salir(); $n->entrar('almacen@waka.test', 'Clave-Larga-1');
/* Uno se queda por alistar, para la revisión de pantallas (movil.cjs). */
$PA2 = (int) valor('SELECT pe.id FROM pedidos pe WHERE pe.anulado_en IS NULL AND pe.pais_id = ? AND pe.despacho_veces = 0 AND '
                   . sql_despacho_listo() . ' ORDER BY pe.id LIMIT 1', [(int)$F['PE']]);
if ($PA2) {
    q('UPDATE pedidos SET despacho_veces = 1, despacho_en = ? WHERE id = ?', [date('Y-m-d H:i:s'), $PA2]);
    q("UPDATE pedido_lineas SET modelo = 'Azul marino con detalles' WHERE pedido_id = ?", [$PA2]);
}
ok('(queda uno por alistar para la revisión de pantallas)', $PA2 > 0);
foreach (['/pedidos', '/pedidos/por-despachar', '/pedidos/despacho?id=' . $PA, '/pedidos/ficha?id=' . $PA, '/stock/pendientes', '/stock/tienda', '/clientes', '/pagos/por-validar', '/configuracion/equipo-despacho'] as $r) {
    $n->ir($r);
    es("Almacén no entra a $r", 403, $n->codigo);
}
/* El asesor de la venta lo ve en su ficha, con la foto. */
$as_pa = (string) valor('SELECT u.email FROM pedidos p JOIN usuarios u ON u.id = p.asesor_id WHERE p.id = ?', [$PA]);
$n->salir(); $n->entrar($as_pa, 'Clave-Larga-1');
$n->ir('/pedidos/ficha?id=' . $PA);
ok('EL ASESOR VE EN LA FICHA QUE ESTÁ ALISTADO, POR QUIÉN', (bool) preg_match('~id="dato-alistado".*?Alistado.*?por Juan Almacén.*?VER FOTO~s', $n->cuerpo));
$n->ir('/pedidos/alistado-foto?id=' . $PA);
es('y abre la foto', 200, $n->codigo);
$n->salir(); $n->entrar('marketing@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/alistado-foto?id=' . $PA);
es('Marketing no', 403, $n->codigo);
$n->salir(); $n->entrar('almacen@waka.test', 'Clave-Larga-1');

/* El conector puesto solo para esto (al final de la 3e se dejó sin clave,
   para que la revisión de pantallas no llame a nadie). */
$P10 = (int) valor("SELECT id FROM productos WHERE woo_id = 8110");
file_put_contents($WP_ARCHIVO, json_encode(['opciones' => ['waka_hub_clave' => $CLAVE_E], 'transitorios' => [],
    'productos' => [8110 => ['id' => 8110, 'tipo' => 'simple', 'padre' => 0, 'nombre' => (string) valor('SELECT nombre FROM productos WHERE id = ?', [$P10]),
                             'sku' => (string) valor('SELECT sku FROM productos WHERE id = ?', [$P10]),
                             'precio' => '130.00', 'gestiona' => true, 'stock' => 3, 'estado' => 'instock']]]));
q("UPDATE ajustes SET valor = ? WHERE clave = 'conector_clave'", [$CLAVE_E]);
$n->ir('/stock/producto?id=' . $P10);
ok('en la ficha del producto no cambia nombre ni precio', $n->codigo === 200 && !str_contains($n->cuerpo, 'id="sku-web"') && !str_contains($n->cuerpo, 'GUARDAR LOS PRECIOS'));
ajustes_olvidar();
ok('(el conector está puesto)', conector_config()['listo']);
ok('PERO SÍ PONE EL STOCK DE LA WEB', str_contains($n->cuerpo, 'id="tarjeta-stock-web"'));
$vid_st = preg_match('~name="variante_id" value="(\d+)"~', $n->cuerpo, $mv) ? $mv[1] : '0';
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'web_stock', 'id' => $P10, 'variante_id' => $vid_st, 'cantidad' => '9', 'motivo' => 'Conteo de almacén']);
ok('y la web lo recibe', str_contains($n->cuerpo, 'Stock cambiado en la web.'), mb_substr(strip_tags($n->cuerpo), 0, 200));
ok('(la web tiene 9)', (int)(json_decode((string) file_get_contents($WP_ARCHIVO), true)['productos'][8110]['stock'] ?? -1) === 9);
ok('queda quién lo puso', (int) valor("SELECT COUNT(*) FROM bitacora WHERE accion = 'tienda.stock' AND entidad_id = ? AND usuario_id = (SELECT id FROM usuarios WHERE email = 'almacen@waka.test')", [$P10]) === 1);
$nombre_p10 = (string) valor('SELECT nombre FROM productos WHERE id = ?', [$P10]);
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'producto', 'id' => $P10, 'nombre' => 'Lo cambia almacén',
                           'sku' => '', 'categoria' => '', 'garantia_item_id' => '', 'activo' => '1']);
ok('un POST a mano para cambiar el nombre: 403', $n->codigo === 403 && (string) valor('SELECT nombre FROM productos WHERE id = ?', [$P10]) === $nombre_p10);
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'precios', 'id' => $P10, 't_desde' => ['1'], 't_hasta' => [''], 't_precio' => ['1.00'], 't_alias' => ['']]);
es('ni los precios', 403, $n->codigo);

/* Marketing: los datos de la tienda y los clientes, mirando. */
$n->salir(); $n->entrar('marketing@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('SU INICIO NO ENSEÑA DINERO y le dice los productos sin precio', !str_contains($n->cuerpo, 'Vendido hoy') && str_contains($n->cuerpo, 'id="cifra-sin-precio"')
   && !str_contains($n->cuerpo, 'id="cifra-salir"'));
$n->ir('/stock/tienda');
es('MARKETING TRAE LA TIENDA', 200, $n->codigo);
$n->ir('/stock/producto?id=' . $P10);
ok('en la ficha: precios sí, stock de la web no', str_contains($n->cuerpo, 'GUARDAR LOS PRECIOS') && !str_contains($n->cuerpo, 'id="tarjeta-stock-web"'));
ok('y el nombre y el código cambian también en la web', str_contains($n->cuerpo, 'id="sku-web"'));
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'producto', 'id' => $P10, 'nombre' => 'Renombrado por marketing',
                           'sku' => (string) valor('SELECT sku FROM productos WHERE id = ?', [$P10]), 'garantia_item_id' => '', 'activo' => '1']);
ok('MARKETING CAMBIA EL NOMBRE, TAMBIÉN EN LA WEB', str_contains($n->cuerpo, 'Producto guardado, también en la web.')
   && (string) valor('SELECT nombre FROM productos WHERE id = ?', [$P10]) === 'Renombrado por marketing'
   && (json_decode((string) file_get_contents($WP_ARCHIVO), true)['productos'][8110]['nombre'] ?? '') === 'Renombrado por marketing', mb_substr(strip_tags($n->cuerpo), 0, 200));
q("UPDATE ajustes SET valor = '' WHERE clave = 'conector_clave'");
@unlink($WP_ARCHIVO);
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'web_stock', 'id' => $P10, 'variante_id' => '0', 'cantidad' => '1', 'motivo' => 'x']);
es('un POST a mano para poner el stock: 403', 403, $n->codigo);
$cli_pe = (int) valor('SELECT id FROM clientes WHERE pais_id = ? AND EXISTS (SELECT 1 FROM pedidos p WHERE p.cliente_id = clientes.id) ORDER BY id LIMIT 1', [(int)$F['PE']]);
$n->ir('/clientes');
ok('VE LOS CLIENTES DE SU PAÍS', $n->codigo === 200 && str_contains($n->cuerpo, '/clientes/ficha?id='));
$n->ir('/clientes/ficha?id=' . $cli_pe);
ok('abre la ficha, con sus compras pero sin abrirlas', $n->codigo === 200 && str_contains($n->cuerpo, 'Sus pedidos') && !str_contains($n->cuerpo, '/pedidos/ficha?id='));
ok('y sin editar ni venderle', !str_contains($n->cuerpo, '/clientes/editar') && !str_contains($n->cuerpo, 'NUEVO PEDIDO'));
$cli_sin = (int) valor('SELECT id FROM clientes WHERE pais_id = ? AND NOT EXISTS (SELECT 1 FROM pedidos p WHERE p.cliente_id = clientes.id) ORDER BY id LIMIT 1', [(int)$F['PE']]);
if ($cli_sin) {
    $n->ir('/clientes/ficha?id=' . $cli_sin);
    ok('uno sin compras no le dice «todavía no le has vendido»', str_contains($n->cuerpo, 'Todavía no ha comprado') && !str_contains($n->cuerpo, 'le has vendido'));
}
$n->ir('/stock/leer', ['_t' => $n->testigoValido()], false);
ok('Marketing también actualiza el stock leído (trae la tienda)', $n->codigo === 302);
foreach (['/clientes/editar?id=' . $cli_pe, '/clientes/nuevo', '/pedidos', '/pedidos/por-despachar', '/stock/pendientes', '/pagos/por-validar', '/configuracion'] as $r) {
    $n->ir($r);
    es("Marketing no entra a $r", 403, $n->codigo);
}

/* El alta: el ámbito lo pone el trabajo. */
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/usuarios/nuevo');
ok('ADMINISTRACIÓN PUEDE DAR DE ALTA ALMACÉN Y MARKETING', str_contains($n->cuerpo, '>Almacén</option>') && str_contains($n->cuerpo, '>Marketing</option>'));
$n->ir('/usuarios/nuevo', ['_t' => $n->testigoValido(), 'nombre' => 'Nuevo', 'apellidos' => 'Almacen',
    'email' => 'nuevoalm@waka.test', 'celular' => '999888766', 'rol_id' => (int) valor("SELECT id FROM roles WHERE clave = 'almacen'"),
    'ambito' => 'propio', 'pais_id' => (int)$F['PE'], 'nivel_cuota' => 0], false);
es('nace viendo todo su país aunque se pidiera «lo suyo»', 'todo', (string) valor("SELECT ambito FROM usuarios WHERE email = 'nuevoalm@waka.test'"));

/* Quien no vende no va en un equipo de ventas. */
$eq_pe = (int) valor('SELECT id FROM equipos WHERE pais_id = ? AND activo = 1 ORDER BY id LIMIT 1', [(int)$F['PE']]);
$n->ir('/usuarios/nuevo');
$n->ir('/usuarios/nuevo', ['_t' => $n->testigoValido(), 'nombre' => 'Nueva', 'apellidos' => 'Marketing',
    'email' => 'nuevamkt@waka.test', 'celular' => '999888755', 'rol_id' => (int) valor("SELECT id FROM roles WHERE clave = 'marketing'"),
    'ambito' => 'todo', 'pais_id' => (int)$F['PE'], 'equipo_id' => $eq_pe, 'nivel_cuota' => 0], false);
ok('MARKETING NO QUEDA EN UN EQUIPO DE VENTAS aunque se elija', $eq_pe > 0
   && valor("SELECT id FROM usuarios WHERE email = 'nuevamkt@waka.test'") !== null
   && valor("SELECT equipo_id FROM usuarios WHERE email = 'nuevamkt@waka.test'") === null);

/* Cambiar el rol cierra lo que estaba recordado en el celular. */
$id_cambia = (int) valor("SELECT id FROM usuarios WHERE email = 'nuevamkt@waka.test'");
insertar('sesiones', ['usuario_id' => $id_cambia, 'selector' => 'selprueba3g', 'validador_hash' => hash('sha256', 'x'),
                      'ip' => '127.0.0.1', 'user_agent' => 'prueba', 'expira_en' => date('Y-m-d H:i:s', time() + 86400)]);
$fila_c = una('SELECT * FROM usuarios WHERE id = ?', [$id_cambia]);
$n->ir('/usuarios/editar?id=' . $id_cambia);
$n->ir('/usuarios/editar?id=' . $id_cambia, ['_t' => $n->testigoValido(), 'id' => $id_cambia, 'nombre' => $fila_c['nombre'], 'apellidos' => $fila_c['apellidos'],
    'email' => $fila_c['email'], 'celular' => $fila_c['celular'], 'rol_id' => (int) valor("SELECT id FROM roles WHERE clave = 'almacen'"),
    'ambito' => 'todo', 'pais_id' => (int)$F['PE'], 'nivel_cuota' => 0], false);
es('(el rol cambió)', 'almacen', (string) valor('SELECT r.clave FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?', [$id_cambia]));
es('CAMBIAR EL ROL CIERRA LA SESIÓN RECORDADA', 0, (int) valor('SELECT COUNT(*) FROM sesiones WHERE usuario_id = ?', [$id_cambia]));

/* La bandeja de Administración ya no llama «lotes» a lo pendiente de la web. */
$n->ir('/inicio');
if (stock_cola_atencion() > 0) {
    ok('LO PENDIENTE DE LA WEB SALE CON SU NOMBRE', (bool) preg_match('~href="/stock/pendientes"[^>]*>\s*\d+ cambios? de stock por mover en la web~', $n->cuerpo));
}
ok('y no como lotes sin precios', !str_contains($n->cuerpo, 'lote sin precios') && !str_contains($n->cuerpo, 'lotes sin precios')
   || (int) valor("SELECT COUNT(*) FROM lotes WHERE estado IN ('en_camino','en_aduana') AND precios_listos = 0") > 0);

/* ══════════════════════ 3h · PRE VENTA Y LOTES ══════════════════════ */
grupo('3h · el CEO llena un lote en el formulario');
$n->salir(); $n->entrar('ceo@waka.test', 'Clave-Larga-1');
$n->ir('/stock');
ok('EL STOCK LLEVA A LOS LOTES Y A LO DISPONIBLE', str_contains($n->cuerpo, 'id="menu-preventa"') && str_contains($n->cuerpo, '/stock/lotes'));
$n->ir('/stock/lotes');
ok('la lista de lotes, con NUEVO LOTE y TRAER DEL EXCEL', $n->codigo === 200 && str_contains($n->cuerpo, 'id="btn-lote-nuevo"') && str_contains($n->cuerpo, 'id="btn-lote-excel"'));
$n->ir('/stock/lote');
ok('el formulario del lote nuevo', $n->codigo === 200 && str_contains($n->cuerpo, 'name="fecha_llegada_est"') && str_contains($n->cuerpo, 'CREAR EL LOTE'));
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'viaje', 'nombre' => 'Contenedor HTTP · 40HQ',
    'fecha_salida' => date('Y-m-d', strtotime('-10 days')), 'fecha_llegada_est' => date('Y-m-d', strtotime('+30 days')),
    'canal' => 'verde', 'puerto_origen' => 'Shenzhen', 'puerto_destino' => 'Callao'], false);
$LH = (int) valor("SELECT id FROM lotes WHERE nombre = 'Contenedor HTTP · 40HQ'");
ok('SE CREA Y LLEVA A PONER LOS PRODUCTOS', $LH > 0 && str_contains($destino(), '/stock/lote?id=' . $LH), $destino());
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'filas', 'id' => $LH,
    'f_id' => ['0', '0', '0'], 'f_codigo' => ['PRD-HTTP90', '', ''], 'f_nombre' => ['Silla pre venta', 'Mesa pre venta', ''],
    'f_modelo' => ['3 rojas, 2 azules', '', ''], 'f_unidades' => ['5', '4', ''], 'f_nuevo' => ['1']]);
ok('LOS PRODUCTOS SE GUARDAN (la silla en dos colores)', str_contains($n->cuerpo, 'Productos guardados') && (int) valor('SELECT COUNT(*) FROM lote_lineas WHERE lote_id = ?', [$LH]) === 3,
   mb_substr(strip_tags($n->cuerpo), 0, 200));
$SILLA = (int) valor("SELECT id FROM productos WHERE sku = 'PRD-HTTP90'");
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'precios', 'id' => $LH, 'producto_id' => $SILLA,
    't_desde' => ['1', '3'], 't_hasta' => ['2', ''], 't_precio' => ['100', '90'], 't_alias' => ['', 'Por 3']]);
ok('los precios de pre venta', str_contains($n->cuerpo, 'Precios guardados') && precio_de_lote($SILLA, $LH, 3) === 9000);
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'filas', 'id' => $LH,
    'f_id' => ['0'], 'f_codigo' => ['PRD-HTTP92'], 'f_nombre' => ['Lo que escribí'], 'f_modelo' => [''], 'f_unidades' => ['cero']]);
ok('SI NO SE PUEDE GUARDAR, LO ESCRITO SIGUE AHÍ', str_contains($n->cuerpo, 'las unidades son un número') && str_contains($n->cuerpo, 'value="Lo que escribí"'));
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'interruptor', 'id' => $LH, 'encender' => '1']);
ok('A LA VENTA, AVISANDO DEL QUE NO TIENE PRECIO', str_contains($n->cuerpo, 'Lote a la venta') && str_contains($n->cuerpo, 'id="aviso-ocultos"')
   && (int) valor('SELECT disponible FROM lotes WHERE id = ?', [$LH]) === 1);
ok('con la barra del barco', str_contains($n->cuerpo, 'class="barco barco--amarillo"') && str_contains($n->cuerpo, 'Shenzhen'));

grupo('3h · el asesor ve lo disponible y vende con corte duro');
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('EN EL CELULAR, «PRE VENTA» VA EN LA BARRA EN LUGAR DE BONOS', (bool) preg_match('~<nav class="barra">.*?/preventa.*?</nav>~s', $n->cuerpo)
   && !preg_match('~<nav class="barra">.*?/bonos.*?</nav>~s', $n->cuerpo));
$n->ir('/stock/lotes');
es('EL ASESOR NO ABRE LOS LOTES ENTEROS (BL, factura, lo que no está a la venta)', 403, $n->codigo);
$n->ir('/pedidos/nuevo?tipo=preventa');
ok('«NUEVA VENTA DE PRE VENTA» abre el formulario ya en pre venta', (bool) preg_match('~name="tipo" value="preventa"[^>]*checked~', $n->cuerpo));
$n->ir('/preventa');
ok('«DISPONIBLE PARA PRE VENTA» ENSEÑA LA SILLA CON SUS PRECIOS Y LO QUE QUEDA', $n->codigo === 200 && str_contains($n->cuerpo, 'Silla pre venta')
   && str_contains($n->cuerpo, 'Quedan 5') && str_contains($n->cuerpo, e(soles(9000))) && str_contains($n->cuerpo, 'Rojas · 3'));
ok('y no la mesa, que no tiene precio', !str_contains($n->cuerpo, 'Mesa pre venta'));
$n->ir('/stock/buscar?q=' . urlencode('silla pre') . '&tipo=preventa');
$jb = json_decode($n->cuerpo, true);
ok('EL BUSCADOR DEL PEDIDO, EN PRE VENTA, TRAE LA SILLA DEL LOTE CON SUS COLORES', ($jb['preventa'] ?? false) && (int)($jb['r'][0]['lote'] ?? 0) === $LH
   && count($jb['r'][0]['variantes'] ?? []) === 2);
$ROJAS = (int) valor("SELECT id FROM lote_lineas WHERE lote_id = ? AND modelo = 'Rojas'", [$LH]);
$pv_post = fn(int $cant, string $lote) => ['_t' => $n->testigoValido(), 'tipo' => 'preventa', 'cliente_id' => (int)$F['clientes']['asesor'],
    'entrega' => 'recojo', 'canal_item_id' => (int)$F['canal'], 'l_desc' => ['Silla pre venta'], 'l_sku' => [''], 'l_modelo' => ['Verde'],
    'l_producto' => [(string)$SILLA], 'l_variante' => [''], 'l_lote' => [$lote], 'l_cant' => [(string)$cant], 'l_precio' => ['1'],
    'fecha' => date('Y-m-d'), 'modo_pago' => 'completo', 'pago_monto' => number_format($cant * 90, 2, '.', ''),
    'pago_metodo_item_id' => (int)$F['efectivo'], 'pago_fecha' => date('Y-m-d')];
$n->ir('/pedidos/nuevo');
$n->ir('/pedidos/nuevo', $pv_post(4, (string)$ROJAS));
ok('PEDIR 4 ROJAS CUANDO HAY 3: NO SE GUARDA Y LO DICE', str_contains($n->cuerpo, 'quedan 3') && !valor('SELECT 1 FROM pedido_lineas WHERE lote_linea_id = ?', [$ROJAS]),
   mb_substr(strip_tags($n->cuerpo), 0, 300));
$n->ir('/pedidos/nuevo', array_merge($pv_post(3, (string)$ROJAS), ['_t' => $n->testigoValido()]));
$PPV = (int) valor('SELECT pedido_id FROM pedido_lineas WHERE lote_linea_id = ?', [$ROJAS]);
/* Una línea fantasma (vacía, con 99) no baja el tramo de la de verdad. */
$AZULES = (int) valor("SELECT id FROM lote_lineas WHERE lote_id = ? AND modelo = 'Azules'", [$LH]);
$fant = $pv_post(1, (string)$AZULES);
foreach (['l_desc' => '', 'l_sku' => '', 'l_modelo' => '', 'l_producto' => (string)$SILLA, 'l_variante' => '', 'l_lote' => (string)$AZULES, 'l_cant' => '99', 'l_precio' => '1'] as $kf => $vf) $fant[$kf][] = $vf;
$fant['pago_monto'] = '100.00';
$n->ir('/pedidos/nuevo', array_merge($fant, ['_t' => $n->testigoValido()]));
es('UNA LÍNEA VACÍA NO BAJA EL PRECIO DE LA OTRA', 10000, (int) valor('SELECT precio_unit_centimos FROM pedido_lineas WHERE lote_linea_id = ? ORDER BY id DESC LIMIT 1', [$AZULES]));
ok('3 ROJAS SÍ', $PPV > 0 && str_contains($n->cuerpo, 'Pedido registrado'), mb_substr(strip_tags($n->cuerpo), 0, 300));
es('CON EL PRECIO DEL LOTE (no el del navegador) Y EL COLOR DEL LOTE', [9000, 'Rojas', 'preventa'],
   array_values(array_map(fn($v) => is_numeric($v) ? (int)$v : $v, una('SELECT precio_unit_centimos, modelo, origen FROM pedido_lineas WHERE lote_linea_id = ?', [$ROJAS]))));
$n->ir('/preventa');
ok('y ya dice que las rojas se agotaron', str_contains($n->cuerpo, 'Rojas · agotado') && str_contains($n->cuerpo, 'Quedan 1'));
$n->ir('/pedidos/nuevo', array_merge($pv_post(1, ''), ['_t' => $n->testigoValido(), 'l_producto' => ['']]));
ok('CON LOTES A LA VENTA, LA PRE VENTA NO SE ESCRIBE A MANO', str_contains($n->cuerpo, 'de la pre venta'));
$n->ir('/pedidos/nuevo', array_merge($pv_post(1, (string)$ROJAS), ['_t' => $n->testigoValido(), 'tipo' => 'inmediata']));
ok('un producto de lote no se vende como entrega inmediata', str_contains($n->cuerpo, 'es de pre venta'));

grupo('3h · la llegada, y quién ve qué');
$n->salir(); $n->entrar('ceo@waka.test', 'Clave-Larga-1');
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'estado', 'id' => $LH, 'estado' => 'recibido']);
ok('AL MARCAR «LLEGÓ», EL PEDIDO PASA A «PRODUCTO LLEGÓ»', (bool) preg_match('~pas(ó|aron) a «Producto llegó»~u', $n->cuerpo)
   && (string) valor('SELECT e.clave FROM pedidos p JOIN pedido_estados e ON e.id = p.estado_id WHERE p.id = ?', [$PPV]) === 'llego',
   mb_substr(strip_tags($n->cuerpo), 0, 300));
/* Para la revisión de pantallas, el lote vuelve a estar en camino y a la venta
   (en la pantalla, «Llegó» ya no se deshace). */
q("UPDATE lotes SET estado = 'en_camino', disponible = 1, fecha_llegada_real = NULL WHERE id = ?", [$LH]);
$n->salir(); $n->entrar('almacen@waka.test', 'Clave-Larga-1');
$n->ir('/stock/lote?id=' . $LH);
ok('ALMACÉN VE EL LOTE, SIN PODER TOCARLO', $n->codigo === 200 && !str_contains($n->cuerpo, 'GUARDAR LOS PRODUCTOS') && !str_contains($n->cuerpo, 'id="form-interruptor"'));
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'interruptor', 'id' => $LH, 'encender' => '0']);
es('ni por un POST a mano', 403, $n->codigo);
foreach (['marketing@waka.test', 'factu@waka.test'] as $quien) {
    $n->salir(); $n->entrar($quien, 'Clave-Larga-1');
    $n->ir('/stock/lotes');
    es("$quien no entra a los lotes", 403, $n->codigo);
    $n->ir('/preventa');
    es("ni a lo disponible para pre venta", 403, $n->codigo);
}
$n->salir(); $n->entrar('adminmx@waka.test', 'Clave-Larga-1');
$n->ir('/stock/lote?id=' . $LH);
es('OTRO PAÍS NO ABRE EL LOTE', 404, $n->codigo);

grupo('3h · traer del Excel, por la pantalla');
$n->salir(); $n->entrar('ceo@waka.test', 'Clave-Larga-1');
$n->ir('/stock/lote/excel');
$csv_h = "1,INFORMACIÓN DE PRODUCTOS | X1 CTN 40HQ,,,\nCODIGO,,PRODUCTO,Cantidad,Colores / Modelo / Detalle\n"
       . "PRD-HTTP91,,Trampolín HTTP | NUEVO,10,\"6 rojos, 4 azules\"\n,,,10,\n"
       . "FECHA DE SALIDA,16/07/2026,,,\nFECHA DE LLEGADA PERU,26/09,,,\nAGENCIA DETALLE,HUB1 | HBL:X,CANAL AMARILLO,,\n";
$n->subir('/stock/lote/excel', ['_t' => $n->testigoValido(), 'accion' => 'leer'], ['archivo' => ['carga.csv', 'text/csv', $csv_h]]);
ok('LEE EL ARCHIVO Y ENSEÑA EL CONTENEDOR ANTES DE GUARDAR NADA', str_contains($n->cuerpo, 'Contenedor 1 · X1 CTN 40HQ') && str_contains($n->cuerpo, 'TRAER ESTE LOTE')
   && !valor("SELECT 1 FROM lotes WHERE nombre = 'Contenedor 1 · X1 CTN 40HQ'"), mb_substr(strip_tags($n->cuerpo), 0, 300));
$n->ir('/stock/lote/excel', ['_t' => $n->testigoValido(), 'accion' => 'traer', 'bloque' => '0']);
$LX = (int) valor("SELECT id FROM lotes WHERE nombre = 'Contenedor 1 · X1 CTN 40HQ'");
ok('TRAERLO LO CREA APAGADO, CON SUS COLORES Y SU CANAL', $LX > 0 && (int) valor('SELECT COUNT(*) FROM lote_lineas WHERE lote_id = ?', [$LX]) === 2
   && (string) valor('SELECT canal FROM lotes WHERE id = ?', [$LX]) === 'amarillo' && (int) valor('SELECT disponible FROM lotes WHERE id = ?', [$LX]) === 0);
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/stock/lote/excel');
es('el asesor no trae lotes', 403, $n->codigo);

/* ══════════════════════ 3h.1 · LO QUE PIDIÓ EL USUARIO EL 2026-09-28 ══════════════════════ */
grupo('3h.1 · el botón PRE VENTA y las filas del lote');
$n->salir(); $n->entrar('ceo@waka.test', 'Clave-Larga-1');
$n->ir('/stock');
ok('EL CEO TIENE EL BOTÓN PRE VENTA, CON NUEVO LOTE Y EL EXCEL', str_contains($n->cuerpo, 'id="menu-preventa"')
   && str_contains($n->cuerpo, 'Nuevo lote') && str_contains($n->cuerpo, 'Traer lotes del Excel') && !str_contains($n->cuerpo, 'id="aviso-preventa"'));
ok('Y «PRE VENTA» EN SU MENÚ, QUE LLEVA A LOS LOTES', (bool) preg_match('~href="/stock/lotes"[^>]*>.*?Pre venta~s', $n->cuerpo));
$n->ir('/stock/lotes');
ok('y en los lotes esa sección sale marcada', str_contains($n->cuerpo, '<a class="nv on" href="/stock/lotes">'));
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'viaje', 'nombre' => 'Contenedor 3h.1']);
$L31 = (int) valor("SELECT id FROM lotes WHERE nombre = 'Contenedor 3h.1'");
$n->ir('/stock/lote?id=' . $L31);
es('UN LOTE NUEVO EMPIEZA CON UNA SOLA FILA', 1, preg_match_all('~<tr class="fila-vacia">~', preg_replace('~<template.*?</template>~s', '', $n->cuerpo)));
ok('con AÑADIR FILA, AÑADIR VARIAS y QUITAR', str_contains($n->cuerpo, 'id="mas-fila"') && str_contains($n->cuerpo, 'id="mas-varias"')
   && str_contains($n->cuerpo, 'id="fila-molde"') && str_contains($n->cuerpo, 'quitar-fila'));
ok('«NUEVO» TIENE SU AYUDA', str_contains($n->cuerpo, 'id="ayuda-nuevo"') && str_contains($n->cuerpo, 'etiqueta «Nuevo»'));
$fl = fn(array $nom, array $und) => ['_t' => $n->testigoValido(), 'accion' => 'filas', 'id' => $L31,
      'f_id' => array_fill(0, count($nom), '0'), 'f_codigo' => array_fill(0, count($nom), ''), 'f_nombre' => $nom,
      'f_modelo' => array_fill(0, count($nom), ''), 'f_unidades' => $und];
$n->ir('/stock/lote', $fl(['Silla 3h.1', '', '', ''], ['dos', '', '', '']));
ok('un error de verdad se dice', str_contains($n->cuerpo, 'Fila 1: las unidades'));
ok('Y LAS FILAS EN BLANCO NO VUELVEN CON «0»', !str_contains($n->cuerpo, 'name="f_unidades[]" inputmode="numeric" value="0"'));
es('ni vuelven: solo la fila escrita, con lo escrito', 1, preg_match_all('~name="f_nombre\[\]"~', preg_replace('~<template.*?</template>~s', '', $n->cuerpo)));
$n->ir('/stock/lote', $fl(['Silla 3h.1', '', ''], ['4', '0', '']));
ok('LO GUARDA AUNQUE SOBREN FILAS EN BLANCO', str_contains($n->cuerpo, 'Productos guardados') && (int) valor('SELECT COUNT(*) FROM lote_lineas WHERE lote_id = ?', [$L31]) === 1,
   mb_substr(strip_tags($n->cuerpo), 0, 200));
$n->ir('/stock/lote?id=' . $L31);
es('con productos, ninguna fila vacía de más', 0, preg_match_all('~<tr class="fila-vacia">~', preg_replace('~<template.*?</template>~s', '', $n->cuerpo)));
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'filas', 'id' => $L31, 'f_nombre' => []]);
es('QUITAR LA FILA (sin ventas) LA QUITA', 0, (int) valor('SELECT COUNT(*) FROM lote_lineas WHERE lote_id = ?', [$L31]));
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/stock');
ok('el asesor ve el botón, con lo disponible y sin lotes', str_contains($n->cuerpo, 'id="menu-preventa"') && str_contains($n->cuerpo, 'Disponible para pre venta')
   && !str_contains($n->cuerpo, 'id="btn-lotes"'));
$n->salir(); $n->entrar('marketing@waka.test', 'Clave-Larga-1');
$n->ir('/stock');
ok('Marketing no', !str_contains($n->cuerpo, 'id="menu-preventa"'));

grupo('3h.1 · el descuento ya no pide permiso, y queda anotado');
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion/descuentos');
ok('la pantalla tiene el interruptor de la aprobación', str_contains($n->cuerpo, 'id="desc-pide"'));
$n->ir('/configuracion/descuentos', ['_t' => $n->testigo(), 'tope' => '5']);
es('SIN MARCARLO, SE APAGA', '0', (string) valor("SELECT valor FROM ajustes WHERE clave = 'descuento_pide_aprobacion'"));
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$venta_d('100', 'cliente de años', '279');
$PD31 = $ultimo_ped();
es('UN DESCUENTO GRANDE SALE SIN ESPERAR', ['ok', '10000'], array_values(array_map('strval', una('SELECT descuento_estado, descuento_centimos FROM pedidos WHERE id = ?', [$PD31]) ?: [])));
ok('Y QUEDA ANOTADO: la línea del pedido y la bitácora, con su motivo',
   (bool) valor("SELECT 1 FROM pedido_eventos WHERE pedido_id = ? AND tipo = 'descuento' AND texto LIKE '%cliente de años%'", [$PD31])
   && (bool) valor("SELECT 1 FROM bitacora WHERE entidad = 'pedido' AND entidad_id = ? AND accion = 'pedido.descuento'", [$PD31]));

grupo('3h.1 · mandar a despacho de un clic, el rótulo para Almacén y el comprobante aparte');
$PL31 = (int) valor('SELECT pe.id FROM pedidos pe WHERE pe.anulado_en IS NULL AND pe.despacho_veces = 0
        AND pe.asesor_id = (SELECT id FROM usuarios WHERE email = ?) AND ' . sql_despacho_listo() . ' ORDER BY pe.id DESC LIMIT 1', ['asesor@waka.test']);
ok('hay una venta lista para mandar', $PL31 > 0);
$n->ir('/pedidos/ficha?id=' . $PL31);
ok('EL BOTÓN DE LA FICHA ES UN FORMULARIO, NO UN ENLACE AL MENSAJE', str_contains($n->cuerpo, 'class="form-despacho"')
   && !str_contains($n->cuerpo, 'VER EL MENSAJE DE DESPACHO'));
$n->ir('/pedidos/despacho', ['_t' => $n->testigoValido(), 'id' => $PL31, 'primera' => '1', 'volver' => 'ficha']);
ok('UN CLIC Y QUEDA EN DESPACHO, DE VUELTA EN LA FICHA', (int) valor('SELECT despacho_veces FROM pedidos WHERE id = ?', [$PL31]) === 1
   && str_contains($n->cuerpo, 'Mandado a despacho') && str_contains($n->cuerpo, 'class="hitos"'), mb_substr(strip_tags($n->cuerpo), 0, 200));
ok('con su línea en el pedido', (bool) valor("SELECT 1 FROM pedido_eventos WHERE pedido_id = ? AND tipo = 'despacho' AND texto = 'Se mandó a despacho'", [$PL31]));
$n->ir('/pedidos/despacho', ['_t' => $n->testigoValido(), 'id' => $PL31, 'primera' => '1', 'volver' => 'ficha']);
es('UN DOBLE TOQUE NO LO MANDA DOS VECES', 1, (int) valor('SELECT despacho_veces FROM pedidos WHERE id = ?', [$PL31]));
$n->ir('/pedidos/despacho?id=' . $PL31);
ok('LA PANTALLA DE DESPACHO YA NO TRAE EL MENSAJE PARA EL WHATSAPP', $n->codigo === 200 && !str_contains($n->cuerpo, 'id="msg"')
   && !str_contains($n->cuerpo, 'COPIAR EL MENSAJE') && str_contains($n->cuerpo, 'VOLVER A MANDAR A DESPACHO'));
$n->ir('/pedidos/despacho', ['_t' => $n->testigoValido(), 'id' => $PL31]);
es('y desde ella sí se vuelve a mandar', 2, (int) valor('SELECT despacho_veces FROM pedidos WHERE id = ?', [$PL31]));
$n->salir(); $n->entrar('almacen@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/por-alistar');
ok('ALMACÉN TIENE EL RÓTULO EN «POR ALISTAR»', str_contains($n->cuerpo, '/pedidos/rotulo?id=' . $PL31));
$n->ir('/pedidos/rotulo?id=' . $PL31);
ok('Y LO DESCARGA', $n->codigo === 200 && str_starts_with($n->cuerpo, '%PDF'), (string)$n->codigo);
$n->salir(); $n->entrar('marketing@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/rotulo?id=' . $PL31, null, false);
es('Marketing no', 403, $n->codigo);
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/ficha?id=' . $PL31);
$ol = preg_match('~<ol class="hitos">.*?</ol>~s', $n->cuerpo, $mo) ? $mo[0] : '';
ok('EL COMPROBANTE NO ESTÁ EN LA LÍNEA DE TIEMPO', $ol !== '' && !str_contains($ol, 'omprobante') && str_contains($ol, 'Mandado a despacho'));
ok('SALE APARTE, COMO INDICADOR', str_contains($n->cuerpo, 'id="indicador-comprobante"'));
$n->ir('/pedidos');
ok('«MÁS FILTROS» LLEVA SU ÍCONO DE BOTÓN', (bool) preg_match('~<summary><svg[^>]*>.*?</svg> Más filtros~s', $n->cuerpo));

grupo('3h.1 · el CEO no ve los saldos sin registrar');
q("UPDATE pedidos SET despacho_veces = 1, despacho_en = ? WHERE id = ?", [date('Y-m-d H:i:s', strtotime('-20 days')), $PL31]);
$n->salir(); $n->entrar('ceo@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('EN SU INICIO NO SALE «SALDO SIN REGISTRAR»', !str_contains($n->cuerpo, 'sin registrar'));
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('a Administración sí, si hay', pedidos_saldo_pendiente_n(una("SELECT u.*, r.clave AS rol FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.email = 'admin@waka.test'"), saldo_admin_dias()) === 0 || str_contains($n->cuerpo, 'sin registrar'));

/* ═══════════════════  3i · REPUESTOS Y GARANTÍA  ═══════════════════ */
grupo('3i · el repuesto, de la ficha del producto');
$PE3 = (int)$F['PE'];
$MAQ3 = insertar('productos', ['sku' => 'PRD-004569', 'nombre' => 'Máquina peluchera clásica', 'pais_id' => $PE3, 'activo' => 1]);
insertar('precios', ['producto_id' => $MAQ3, 'desde' => 1, 'precio_centimos' => 250000, 'activo' => 1]);
$JOY3 = insertar('productos', ['sku' => 'WK-JOY001', 'nombre' => 'Joystick', 'pais_id' => $PE3, 'activo' => 1]);
$BOT3 = insertar('productos', ['sku' => 'WK-BOT001', 'nombre' => 'Botón', 'pais_id' => $PE3, 'activo' => 1, 'padre_id' => $MAQ3]);
insertar('precios', ['producto_id' => $JOY3, 'desde' => 1, 'precio_centimos' => 8000, 'activo' => 1]);
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/stock/producto?id=' . $JOY3);
ok('LA FICHA DEL PRODUCTO TIENE «REPUESTO»', str_contains($n->cuerpo, 'id="tarjeta-repuesto"'));
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'repuesto', 'id' => $JOY3, 'maquina' => 'PRD-999999']);
ok('una máquina que no existe se dice', str_contains($n->cuerpo, 'No hay ninguna máquina') && valor('SELECT padre_id FROM productos WHERE id = ?', [$JOY3]) === null);
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'repuesto', 'id' => $JOY3, 'maquina' => 'prd-004569']);
es('SE CUELGA DE SU MÁQUINA POR EL CÓDIGO', $MAQ3, (int) valor('SELECT padre_id FROM productos WHERE id = ?', [$JOY3]));
ok('y la ficha lo dice', str_contains($n->cuerpo, 'id="es-repuesto-de"') && str_contains($n->cuerpo, 'id="tarjeta-stock-hub"'));
$n->ir('/stock/producto?id=' . $MAQ3);
ok('la máquina enseña sus repuestos', str_contains($n->cuerpo, 'id="sus-repuestos"') && str_contains($n->cuerpo, 'Joystick'));
$n->ir('/stock?v=repuestos');
ok('EL CATÁLOGO TIENE SU FILTRO «REPUESTOS»', $n->codigo === 200 && str_contains($n->cuerpo, 'Repuesto de Máquina peluchera clásica')
   && str_contains($n->cuerpo, 'Solo garantía') && !str_contains($n->cuerpo, '>Máquina peluchera clásica</div>'));
$n->salir(); $n->entrar('almacen@waka.test', 'Clave-Larga-1');
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'hub_stock', 'id' => $JOY3, 'cantidad' => '6', 'motivo' => 'Conteo']);
es('ALMACÉN PONE EL STOCK DEL ALMACÉN', 6, (int) valor('SELECT cantidad FROM stock_hub WHERE producto_id = ?', [$JOY3]));
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'hub_stock', 'id' => $BOT3, 'cantidad' => '4', 'motivo' => 'Conteo']);
$n->salir(); $n->entrar('marketing@waka.test', 'Clave-Larga-1');
$n->ir('/stock/producto', ['_t' => $n->testigoValido(), 'accion' => 'hub_stock', 'id' => $JOY3, 'cantidad' => '99', 'motivo' => 'x'], false);
ok('Marketing no', $n->codigo === 403 && (int) valor('SELECT cantidad FROM stock_hub WHERE producto_id = ?', [$JOY3]) === 6);
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/stock/buscar?q=peluchera&tipo=inmediata');
$bj = json_decode($n->cuerpo, true)['r'] ?? [];
es('EL BUSCADOR DE LA VENTA: LA MÁQUINA Y, DETRÁS, SU REPUESTO CON PRECIO', ['Máquina peluchera clásica', 'Joystick'], array_column($bj, 'nombre'));
es('dice de qué máquina es', 'Máquina peluchera clásica', (string)($bj[1]['maquina'] ?? ''));

grupo('3i · pedir la garantía desde el pedido');
$PG3 = insertar('pedidos', ['codigo' => 'tmp-g3', 'pais_id' => $PE3, 'cliente_id' => (int)$F['clientes']['asesor'], 'asesor_id' => (int)$F['usuarios']['asesor'],
                            'estado_id' => (int) estado_por_clave('entregado')['id'], 'tipo' => 'inmediata', 'fecha' => date('Y-m-d'), 'entrega' => 'recojo',
                            'garantia_item_id' => (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id WHERE l.clave = 'garantias' AND i.valor = '12 meses'"),
                            'despacho_veces' => 1, 'despacho_en' => date('Y-m-d H:i:s', strtotime('-40 days')), 'despacho_primero_en' => date('Y-m-d H:i:s', strtotime('-40 days')),
                            'alistado_en' => date('Y-m-d H:i:s', strtotime('-40 days'))]);
actualizar('pedidos', $PG3, ['codigo' => pedido_codigo_de_id($PG3)]);
insertar('pedido_lineas', ['pedido_id' => $PG3, 'descripcion' => 'Máquina peluchera clásica', 'cantidad' => 1, 'precio_unit_centimos' => 250000,
                           'total_centimos' => 250000, 'producto_id' => $MAQ3]);
pedido_recalcular($PG3);
$n->ir('/pedidos/ficha?id=' . $PG3);
ok('LA FICHA DICE HASTA CUÁNDO VALE Y OFRECE PEDIRLA', str_contains($n->cuerpo, 'id="tarjeta-garantia"') && str_contains($n->cuerpo, 'Vigente hasta el')
   && str_contains($n->cuerpo, 'id="btn-pedir-garantia"'));
$n->ir('/garantias/pedir?pedido=' . $PG3);
ok('la pantalla de pedirla trae sus piezas', $n->codigo === 200 && str_contains($n->cuerpo, 'name="pieza[' . $JOY3 . ']"') && str_contains($n->cuerpo, 'name="pieza[' . $BOT3 . ']"'));
$n->subir('/garantias/pedir', ['_t' => $n->testigoValido(), 'pedido' => $PG3, 'pieza' => [$JOY3 => '1'], 'motivo' => 'No responde'], []);
ok('SIN FOTO REBOTA Y LO DICE', str_contains($n->cuerpo, 'Falta la foto de la falla') && !valor('SELECT 1 FROM garantias WHERE pedido_id = ?', [$PG3]));
$n->subir('/garantias/pedir', ['_t' => $n->testigoValido(), 'pedido' => $PG3, 'pieza' => [$JOY3 => '1'], 'motivo' => 'No responde el joystick'],
          ['falla_1' => ['falla.jpg', 'image/jpeg', $JPG], 'falla_2_cam' => ['otra.jpg', 'image/jpeg', $JPG]]);
$GA3 = (int) valor('SELECT id FROM garantias WHERE pedido_id = ? ORDER BY id DESC LIMIT 1', [$PG3]);
ok('SE PIDE CON DOS FOTOS (una por la cámara)', $GA3 > 0 && (int) valor('SELECT COUNT(*) FROM garantia_fotos WHERE garantia_id = ?', [$GA3]) === 2, mb_substr(strip_tags($n->cuerpo), 0, 300));
ok('y lleva a su ficha', str_contains($n->cuerpo, garantia_codigo($GA3)) && str_contains($n->cuerpo, 'id="garantia-estado"'));
$FOTO3 = (int) valor('SELECT id FROM garantia_fotos WHERE garantia_id = ? ORDER BY id LIMIT 1', [$GA3]);
$n->ir('/garantias/foto?id=' . $FOTO3);
ok('la foto se ve', $n->codigo === 200);
$n->subir('/garantias/pedir', ['_t' => $n->testigoValido(), 'pedido' => $PG3, 'pieza' => [$MAQ3 => '1'], 'motivo' => 'Rota'],
          ['falla_1' => ['falla.jpg', 'image/jpeg', $JPG]]);
ok('UNA PIEZA QUE NO ES DE SU MÁQUINA NO', str_contains($n->cuerpo, 'no es de las máquinas'));
$n->subir('/garantias/pedir', ['_t' => $n->testigoValido(), 'pedido' => $PG3, 'sin_pieza' => '1', 'motivo' => 'No prende'],
          ['falla_1' => ['falla.jpg', 'image/jpeg', $JPG]]);
$GB3 = (int) valor('SELECT id FROM garantias WHERE pedido_id = ? ORDER BY id DESC LIMIT 1', [$PG3]);
ok('«no sé qué pieza es» también se pide', $GB3 !== $GA3 && (int) valor('SELECT sin_pieza FROM garantias WHERE id = ?', [$GB3]) === 1);
$n->salir(); $n->entrar('otro@waka.test', 'Clave-Larga-1');
$n->ir('/garantias/pedir?pedido=' . $PG3, null, false);
es('OTRO ASESOR NO PIDE GARANTÍA DE UN PEDIDO AJENO', 403, $n->codigo);
$n->ir('/garantias/ver?id=' . $GA3, null, false);
es('ni la ve', 403, $n->codigo);
$n->ir('/garantias/foto?id=' . $FOTO3, null, false);
es('ni su foto', 403, $n->codigo);
$n->salir(); $n->entrar('factu@waka.test', 'Clave-Larga-1');
$n->ir('/garantias', null, false);
es('Facturación no entra a Garantías', 403, $n->codigo);
$n->ir('/inicio');
ok('FACTURACIÓN TIENE «MÁS» EN EL CELULAR', str_contains($solo_barra($n->cuerpo), '/mas'));

grupo('3i · Administración la aprueba y sale por «Por alistar»');
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('SU BANDEJA DICE QUE HAY GARANTÍAS POR APROBAR', str_contains($n->cuerpo, 'garantías por aprobar'));
$n->ir('/garantias?e=pedida');
ok('y la lista las trae', str_contains($n->cuerpo, garantia_codigo($GA3)) && str_contains($n->cuerpo, garantia_codigo($GB3)));
$n->ir('/garantias/ver?id=' . $GA3);
ok('su ficha tiene aprobar y no aprobar', str_contains($n->cuerpo, 'id="garantia-decidir"') && str_contains($n->cuerpo, 'id="anadir-pieza"'));
$n->ir('/garantias/ver', ['_t' => $n->testigoValido(), 'id' => $GA3, 'accion' => 'aprobar', 'nota' => 'Con cinta']);
es('SE APRUEBA Y EL JOYSTICK SALE DEL ALMACÉN', ['aprobada', 5], [(string) valor('SELECT estado FROM garantias WHERE id = ?', [$GA3]),
   (int) valor('SELECT cantidad FROM stock_hub WHERE producto_id = ?', [$JOY3])]);
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/garantias/ver', ['_t' => $n->testigoValido(), 'id' => $GB3, 'accion' => 'denegar', 'nota' => 'no'], false);
es('EL ASESOR NO LA RESUELVE (ni armando el formulario)', 403, $n->codigo);
$n->ir('/inicio');
ok('SU INICIO LE DICE QUE SE APROBÓ', str_contains($n->cuerpo, 'id="aviso-garantias"') && str_contains($n->cuerpo, garantia_codigo($GA3)));
$n->ir('/garantias?v=70636127');
ok('«¿SIGUE EN GARANTÍA?» POR EL DNI', str_contains($n->cuerpo, 'class="fila vigencia-fila"') && str_contains($n->cuerpo, (string) valor('SELECT codigo FROM pedidos WHERE id = ?', [$PG3])));
$n->salir(); $n->entrar('almacen@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/por-alistar');
ok('ALMACÉN LA TIENE EN «POR ALISTAR», SIN COBRO', str_contains($n->cuerpo, 'id="garantia-' . $GA3 . '"') && str_contains($n->cuerpo, 'Garantía · sin cobro'));
$n->ir('/garantias/rotulo?id=' . $GA3);
ok('Y SU RÓTULO', $n->codigo === 200 && str_starts_with($n->cuerpo, '%PDF'));
$n->ir('/garantias/rotulo?id=' . $GB3, null, false);
es('una sin aprobar no lleva rótulo', 409, $n->codigo);
$n->salir(); $n->entrar('marketing@waka.test', 'Clave-Larga-1');
$n->ir('/garantias/rotulo?id=' . $GA3, null, false);
es('Marketing no', 403, $n->codigo);

grupo('3i · Configuración › Garantías');
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion/garantias');
ok('la pantalla está, con sus meses', $n->codigo === 200 && str_contains($n->cuerpo, 'name="meses"'));
$n->ir('/configuracion/garantias', ['_t' => $n->testigoValido(), 'accion' => 'anadir', 'nombre' => '18 meses', 'meses' => '18']);
es('SE AÑADE UNA CON SUS MESES', 18, (int) valor("SELECT meses FROM lista_items WHERE valor = '18 meses'"));
$G12 = (int) valor("SELECT id FROM lista_items WHERE valor = '12 meses'");
q('UPDATE lista_items SET meses = 12 WHERE id = ?', [$G12]);      // como lo deja actualizar.php
$n->ir('/configuracion/garantias', ['_t' => $n->testigoValido(), 'accion' => 'guardar', 'id' => $G12, 'nombre' => '12 meses', 'meses' => '24']);
ok('UNA YA PROMETIDA EN VENTAS NO CAMBIA DE MESES', str_contains($n->cuerpo, 'ya se prometió en ventas') && (int) valor('SELECT meses FROM lista_items WHERE id = ?', [$G12]) !== 24);
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion/garantias', null, false);
es('el asesor no entra', 403, $n->codigo);

/* ═══════════════════════  3j  ═══════════════════════ */
grupo('3j · el lote: el siguiente paso, la ventana del error y la casilla «Es repuesto»');
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/stock/lote');
ok('LOTE NUEVO: EL PASO QUE TOCA ES EL VIAJE', str_contains($n->cuerpo, 'id="paso-siguiente"') && str_contains($n->cuerpo, 'data-ancla="viaje"'));
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'viaje', 'nombre' => 'X']);
ok('UN ERROR SALE TAMBIÉN EN LA VENTANA, CON A DÓNDE IR', str_contains($n->cuerpo, 'id="error-emergente"') && str_contains($n->cuerpo, 'data-hay-error="1"')
   && str_contains($n->cuerpo, 'data-abrir="viaje"') && str_contains($n->cuerpo, 'id="error-emergente-ir"'));
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'viaje', 'nombre' => 'Contenedor 3j', 'fecha_llegada_est' => date('Y-m-d', strtotime('+15 days'))]);
$L3J = (int) valor("SELECT id FROM lotes WHERE nombre = 'Contenedor 3j'");
ok('CREADO, EL SIGUIENTE PASO ES PONER LOS PRODUCTOS', $L3J > 0 && str_contains($n->cuerpo, 'data-ancla="productos"'));
ok('LA COLUMNA ES «¿ES REPUESTO?», CON CASILLA, NO UN CÓDIGO', str_contains($n->cuerpo, '¿Es repuesto?') && str_contains($n->cuerpo, 'class="maq-es"')
   && !str_contains($n->cuerpo, 'placeholder="Código de la máquina"'));
ok('Y LAS MÁQUINAS DEL CATÁLOGO PARA ELEGIR', str_contains($n->cuerpo, 'id="maquinas-molde"') && str_contains($n->cuerpo, 'value="PRD-004569"'));
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'filas', 'id' => $L3J,
       'f_id' => ['0', '0'], 'f_codigo' => ['', ''], 'f_nombre' => ['Silla Plata 3j', 'Palanca 3j'], 'f_modelo' => ['', ''],
       'f_unidades' => ['8', '3'], 'f_maquina' => ['', 'PRD-004569']]);
$pal = una("SELECT ll.maquina, p.padre_id FROM lote_lineas ll JOIN productos p ON p.id = ll.producto_id WHERE ll.lote_id = ? AND p.nombre = 'Palanca 3j'", [$L3J]);
ok('LA MÁQUINA ELEGIDA SE GUARDA Y EL REPUESTO QUEDA COLGADO', ($pal['maquina'] ?? '') === 'PRD-004569' && (int)($pal['padre_id'] ?? 0) === $MAQ3);
$SP3 = (int) valor("SELECT id FROM productos WHERE nombre = 'Silla Plata 3j'");
ok('EL SIGUIENTE PASO ES EL PRECIO DE LA SILLA', str_contains($n->cuerpo, 'data-ancla="precio-' . $SP3 . '"') && str_contains($n->cuerpo, 'GUARDAR TODOS LOS PRECIOS'));
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'filas', 'id' => $L3J,
       'f_id' => ['0'], 'f_codigo' => [''], 'f_nombre' => [''], 'f_modelo' => ['Roja'], 'f_unidades' => ['2'], 'f_maquina' => ['']]);
ok('EL ERROR DE UNA FILA LLEVA A LA FILA', str_contains($n->cuerpo, 'Fila 1') && str_contains($n->cuerpo, 'data-abrir="productos"'));

grupo('3j · los precios se guardan sin recargar');
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'precios', 'id' => $L3J, 'producto_id' => $SP3, 'ajax' => '1',
       't_desde' => ['1'], 't_hasta' => [''], 't_precio' => ['']]);
$j = json_decode($n->cuerpo, true);
ok('UN PRECIO MAL: CONTESTA CON EL ERROR, SIN PÁGINA', is_array($j) && $j['ok'] === false && str_contains((string)$j['error'], 'precio'), mb_substr($n->cuerpo, 0, 200));
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'precios', 'id' => $L3J, 'producto_id' => $SP3, 'ajax' => '1',
       't_desde' => ['1'], 't_hasta' => [''], 't_precio' => ['650.00']]);
$j = json_decode($n->cuerpo, true);
ok('BIEN: GUARDA Y DICE EL SIGUIENTE PASO', is_array($j) && $j['ok'] === true && ($j['siguiente']['paso'] ?? '') === 'venta', mb_substr($n->cuerpo, 0, 200));
es('y el precio quedó', 65000, (int) valor('SELECT precio_centimos FROM precios WHERE producto_id = ? AND lote_id = ? AND activo = 1', [$SP3, $L3J]));
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'interruptor', 'id' => $L3J, 'encender' => '1']);
ok('a la venta: lo siguiente es «Llegó»', str_contains($n->cuerpo, 'data-ancla="form-estado"'));

grupo('3j · la pre venta espera a «Listo para entrega»');
$PE3J = (int)$F['PE'];
$LL3J = (int) valor('SELECT ll.id FROM lote_lineas ll JOIN productos p ON p.id = ll.producto_id WHERE ll.lote_id = ? AND p.nombre = ?', [$L3J, 'Silla Plata 3j']);
$PV3J = insertar('pedidos', ['codigo' => 'tmp-pv3j', 'pais_id' => $PE3J, 'cliente_id' => (int)$F['clientes']['asesor'], 'asesor_id' => (int)$F['usuarios']['asesor'],
                             'estado_id' => (int) estado_por_clave('reservado')['id'], 'tipo' => 'preventa', 'fecha' => date('Y-m-d'), 'entrega' => 'envio',
                             'tipo_envio_item_id' => (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id = i.lista_id WHERE l.clave = 'tipos_envio' AND i.cobra_flete = 1 ORDER BY i.id LIMIT 1"),
                             'ubigeo_id' => $SJL, 'flete_lo_paga' => 'incluido', 'flete_centimos' => 0]);
actualizar('pedidos', $PV3J, ['codigo' => pedido_codigo_de_id($PV3J)]);
insertar('pedido_lineas', ['pedido_id' => $PV3J, 'descripcion' => 'Silla Plata 3j', 'cantidad' => 1, 'precio_unit_centimos' => 65000, 'total_centimos' => 65000,
                           'producto_id' => $SP3, 'lote_linea_id' => $LL3J, 'origen' => 'preventa']);
pedido_recalcular($PV3J);
$CPV3J = (string) valor('SELECT codigo FROM pedidos WHERE id = ?', [$PV3J]);
insertar('pagos', ['pedido_id' => $PV3J, 'monto_centimos' => 65000, 'metodo_item_id' => (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id=i.lista_id WHERE l.clave='metodos_pago' AND i.valor='Efectivo'"),
                   'fecha' => date('Y-m-d'), 'verificado' => 1, 'verificado_en' => date('Y-m-d H:i:s'), 'anulado' => 0, 'tipo' => 'cobro']);
pedido_recalcular($PV3J);
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos');
ok('PEDIDOS TIENE DOS PESTAÑAS', str_contains($n->cuerpo, 'id="pestanas-pedidos"') && substr_count($n->cuerpo, 'class="pestanas__p') === 2 && str_contains($n->cuerpo, 'Pre venta'));
ok('LA PRE VENTA NO SALE EN «STOCK»', !str_contains($n->cuerpo, $CPV3J));
$n->ir('/pedidos?tab=preventa');
ok('SALE EN SU PESTAÑA, CON «LLEGA APROX.»', str_contains($n->cuerpo, $CPV3J) && str_contains($n->cuerpo, 'Llega aprox. ' . date('d/m', strtotime('+15 days'))));
ok('CON SUS FILTROS: LOTE Y «¿YA SE PUEDE ENTREGAR?»', str_contains($n->cuerpo, 'name="lo"') && str_contains($n->cuerpo, 'name="pv"') && !str_contains($n->cuerpo, '<select name="t">'));
$n->ir('/pedidos?tab=preventa&pv=listo');
ok('«listas para entrega» todavía no la trae', !str_contains($n->cuerpo, $CPV3J));
$n->ir('/pedidos?tab=preventa&pv=camino');
ok('«todavía en camino» sí', str_contains($n->cuerpo, $CPV3J));
$n->ir('/pedidos/ficha?id=' . $PV3J);
ok('LA FICHA DICE CUÁNDO LLEGA EN VEZ DE «MANDAR»', str_contains($n->cuerpo, 'Llega aprox.') && !str_contains($n->cuerpo, 'MANDAR A DESPACHO'));
ok('YA NO HAY «CAMBIAR EL ESTADO»', !str_contains($n->cuerpo, 'Cambiar el estado') && !str_contains($n->cuerpo, '/pedidos/estado'));
ok('Y PIDE EL COSTO DEL ENVÍO QUE QUEDÓ PENDIENTE', str_contains($n->cuerpo, 'id="flete"') && str_contains($n->cuerpo, 'PONER EL COSTO DEL ENVÍO'));
$n->ir('/pedidos/estado', ['_t' => $n->testigoValido(), 'id' => $PV3J, 'estado' => 'entregado']);
ok('EL FORMULARIO VIEJO DEL ESTADO NO CAMBIA NADA Y LO DICE', str_contains($n->cuerpo, 'El estado cambia solo') && (string) valor('SELECT e.clave FROM pedidos p JOIN pedido_estados e ON e.id = p.estado_id WHERE p.id = ?', [$PV3J]) !== 'entregado');
$n->ir('/pedidos/por-despachar');
ok('EN «POR DESPACHAR» SALE ENTRE LAS QUE NO PUEDEN, CON SU FECHA', str_contains($n->cuerpo, 'Pre venta') && str_contains($n->cuerpo, 'Llega aprox.'));
$n->ir('/pedidos/despacho', ['_t' => $n->testigoValido(), 'id' => $PV3J, 'primera' => '1'], false);
ok('Y NO SE PUEDE MANDAR AUNQUE SE FUERCE', (int) valor('SELECT despacho_veces FROM pedidos WHERE id = ?', [$PV3J]) === 0);
$n->salir(); $n->entrar('otro@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/flete', ['_t' => $n->testigoValido(), 'id' => $PV3J, 'flete' => '10'], false);
ok('OTRO ASESOR NO PONE EL COSTO', $n->codigo === 403 && (int) valor('SELECT flete_centimos FROM pedidos WHERE id = ?', [$PV3J]) === 0);
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'estado', 'id' => $L3J, 'estado' => 'recibido']);
ok('LLEGÓ: APARECE «LISTO PARA ENTREGA» COMO SIGUIENTE PASO', str_contains($n->cuerpo, 'id="form-listo"') && str_contains($n->cuerpo, 'data-ancla="form-listo"'));
$n->ir('/stock/lote', ['_t' => $n->testigoValido(), 'accion' => 'listo', 'id' => $L3J]);
ok('EL CEO LO MARCA', valor('SELECT listo_en FROM lotes WHERE id = ?', [$L3J]) !== null && str_contains($n->cuerpo, 'id="lote-listo"'));
es('SU PEDIDO PASA A «LISTO PARA ENTREGA»', 'listo', (string) valor('SELECT e.clave FROM pedidos p JOIN pedido_estados e ON e.id = p.estado_id WHERE p.id = ?', [$PV3J]));
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/por-despachar');
ok('AHORA SALE PARA MANDAR, Y EL BOTÓN PIDE EL COSTO DEL ENVÍO', str_contains($n->cuerpo, $CPV3J) && str_contains($n->cuerpo, 'class="despacho-flete"'));
$n->ir('/pedidos/despacho', ['_t' => $n->testigoValido(), 'id' => $PV3J, 'primera' => '1', 'volver' => 'pordespachar']);
ok('SIN EL COSTO NO SALE Y LO DICE', str_contains($n->cuerpo, 'Falta el costo del envío') && (int) valor('SELECT despacho_veces FROM pedidos WHERE id = ?', [$PV3J]) === 0);
$n->ir('/pedidos/despacho', ['_t' => $n->testigoValido(), 'id' => $PV3J, 'primera' => '1', 'volver' => 'pordespachar', 'flete' => '15.00']);
es('CON EL COSTO, SALE Y SUMA AL TOTAL', [1, 1500, 66500], array_map('intval', array_values(una('SELECT despacho_veces, flete_centimos, total_centimos FROM pedidos WHERE id = ?', [$PV3J]))));
es('→ «En despacho»', 'en_despacho', (string) valor('SELECT e.clave FROM pedidos p JOIN pedido_estados e ON e.id = p.estado_id WHERE p.id = ?', [$PV3J]));

grupo('3j · Almacén marca ENTREGADO con la foto');
$n->salir(); $n->entrar('almacen@waka.test', 'Clave-Larga-1');
$QUIEN3J = insertar('lista_items', ['lista_id' => (int) valor("SELECT id FROM listas WHERE clave = 'equipo_despacho'"), 'pais_id' => $PE3J, 'valor' => 'Pedro 3j', 'orden' => 99]);
$n->subir('/pedidos/por-alistar', ['_t' => $n->testigoValido(), 'accion' => 'alistado', 'id' => $PV3J, 'quien_id' => $QUIEN3J], ['foto_alistado' => ['caja.jpg', 'image/jpeg', $JPG]]);
ok('se alista', valor('SELECT alistado_foto FROM pedidos WHERE id = ?', [$PV3J]) !== null);
$n->ir('/pedidos/por-alistar');
ok('Y QUEDA EN «POR ENTREGAR»', str_contains($n->cuerpo, 'id="por-entregar"') && str_contains($n->cuerpo, 'id="entregar-' . $PV3J . '"'));
$n->subir('/pedidos/por-alistar', ['_t' => $n->testigoValido(), 'accion' => 'entregado', 'id' => $PV3J], []);
ok('SIN FOTO NO', str_contains($n->cuerpo, 'Falta la foto de la entrega') && valor('SELECT entregado_en FROM pedidos WHERE id = ?', [$PV3J]) === null, mb_substr(strip_tags($n->cuerpo), 0, 300));
$n->subir('/pedidos/por-alistar', ['_t' => $n->testigoValido(), 'accion' => 'entregado', 'id' => $PV3J], ['foto_entrega' => ['entrega.jpg', 'image/jpeg', $JPG]]);
ok('CON LA FOTO, ENTREGADO', valor('SELECT entregado_foto FROM pedidos WHERE id = ?', [$PV3J]) !== null && str_contains($n->cuerpo, 'id="entregados-hoy"'));
es('→ «Entregado»', 'entregado', (string) valor('SELECT e.clave FROM pedidos p JOIN pedido_estados e ON e.id = p.estado_id WHERE p.id = ?', [$PV3J]));
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/entrega-foto?id=' . $PV3J);
ok('SU ASESOR VE LA FOTO DE LA ENTREGA', $n->codigo === 200);
$n->ir('/pedidos/ficha?id=' . $PV3J);
ok('y la ficha la enseña', str_contains($n->cuerpo, 'id="dato-entrega"') && str_contains($n->cuerpo, '/pedidos/entrega-foto?id=' . $PV3J));
$n->ir('/pedidos/por-despachar?eq=' . rawurlencode('plata 3j'));
ok('«ENVIADOS»: LO BUSCA POR PRODUCTO, CON TODAS SUS FOTOS', str_contains($n->cuerpo, 'id="enviado-' . $PV3J . '"') && str_contains($n->cuerpo, '/pedidos/alistado-foto?id=' . $PV3J)
   && str_contains($n->cuerpo, '/pedidos/rotulo?id=' . $PV3J) && str_contains($n->cuerpo, '/pedidos/entrega-foto?id=' . $PV3J));
$n->ir('/pedidos/por-despachar?eq=' . rawurlencode('no existe nada así'));
ok('y lo que no coincide no sale', !str_contains($n->cuerpo, 'id="enviado-' . $PV3J . '"') && str_contains($n->cuerpo, 'No hay envíos con esos filtros'));
$n->salir(); $n->entrar('otro@waka.test', 'Clave-Larga-1');
$n->ir('/pedidos/entrega-foto?id=' . $PV3J, null, false);
es('OTRO ASESOR NO VE LA FOTO', 403, $n->codigo);
$n->ir('/pedidos/por-despachar');
ok('ni el envío en sus «Enviados»', !str_contains($n->cuerpo, 'id="enviado-' . $PV3J . '"'));

grupo('3j · el cliente de otro asesor');
$n->salir(); $n->entrar('lider@waka.test', 'Clave-Larga-1');
$doc_as = (string) valor('SELECT documento FROM clientes WHERE id = ?', [(int)$F['clientes']['asesor']]);
$n->ir('/clientes/buscar?q=' . rawurlencode($doc_as));
ok('EL BUSCADOR DE LA VENTA NO LE DA AL LÍDER EL CLIENTE DE SU ASESOR', !in_array((int)$F['clientes']['asesor'], array_column(json_decode($n->cuerpo, true)['clientes'] ?? [], 'id'), true));
$n->ir('/clientes/ficha?id=' . (int)$F['clientes']['asesor']);
ok('SU FICHA DICE DE QUIÉN ES Y QUÉ HACER, SIN «NUEVO PEDIDO»', str_contains($n->cuerpo, 'id="aviso-otro-asesor"') && str_contains($n->cuerpo, 'pide a Administración')
   && !str_contains($n->cuerpo, '/pedidos/nuevo?cliente=' . (int)$F['clientes']['asesor']));
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/clientes/buscar?q=' . rawurlencode($doc_as));
ok('su asesor sí lo encuentra', in_array((int)$F['clientes']['asesor'], array_column(json_decode($n->cuerpo, true)['clientes'] ?? [], 'id'), true));
$n->ir('/pedidos/nuevo');
ok('EN LA VENTA, EL COSTO DEL ENVÍO TIENE SU MARCA «OPCIONAL»', str_contains($n->cuerpo, 'id="flete-opcional"') && str_contains($n->cuerpo, 'puedes llenarlo luego'));

/* ═══════════════════  5a · BONOS, METAS Y RACHAS  ═══════════════════ */
grupo('5a · los bonos, por la pantalla');
bonos_sembrar();
$PE5 = (int)$F['PE'];
$Y5 = bono_tipo($PE5, 'yapa');
q('UPDATE bonos SET activo = 1, activo_desde = ? WHERE pais_id = ? AND tipo IN (?, ?, ?, ?)', [date('Y-m-d'), $PE5, 'yapa', 'caceria', 'manada', 'alfa_semana']);
q('DELETE FROM usuario_niveles WHERE usuario_id = ?', [(int)$F['usuarios']['asesor']]);
insertar('usuario_niveles', ['usuario_id' => (int)$F['usuarios']['asesor'], 'desde' => '2000-01-01', 'nivel' => 1]);
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('EL INICIO DEL ASESOR LLEVA SU RACHA Y ABRE BONOS', str_contains($n->cuerpo, 'id="chip-racha"') && str_contains($n->cuerpo, 'id="tarjeta-racha"'));
ok('Bonos está en «Más» (no en la barra)', str_contains($n->cuerpo, 'href="' . '/bonos"') || str_contains($n->cuerpo, '/bonos"'));
$n->ir('/bonos');
ok('EL ASESOR VE «MIS BONOS»', $n->codigo === 200 && str_contains($n->cuerpo, 'id="bono-yapa"') && !str_contains($n->cuerpo, 'id="como-van"'));
ok('los apagados no le salen', !str_contains($n->cuerpo, 'id="bono-sin_freno"'));
ok('ni «Por pagar»', !str_contains($n->cuerpo, 'id="por-pagar"'));
ok('LA CACERÍA SIN LANZAR NO SE ENSEÑA COMO UN BONO QUE VA', !str_contains($n->cuerpo, 'Te faltan 5 para'));
foreach (['/configuracion/bonos', '/configuracion/metas', '/bonos/caceria'] as $r5) {
    $n->ir($r5, null, false);
    es("el asesor no entra a $r5", 403, $n->codigo);
}
$n->ir('/bonos/pagar', ['_t' => $n->testigoValido(), 'id' => 1], false);
es('NI MARCA UN PREMIO COMO PAGADO', 403, $n->codigo);
$n->ir('/bonos/rachas');
ok('sus rachas abren', $n->codigo === 200 && str_contains($n->cuerpo, 'id="mi-racha"') && str_contains($n->cuerpo, 'id="racha-escalera"'));
$n->ir('/equipo');
ok('EL MODO TEAM ABRE CON SU EQUIPO', $n->codigo === 200 && str_contains($n->cuerpo, 'id="team-tabla"') && str_contains($n->cuerpo, 'Equipo Uno'));
$n->ir('/equipo?e=999');
ok('y no puede pedir el de otro (se queda con el suyo)', $n->codigo === 200 && str_contains($n->cuerpo, 'id="team-personas"'));

grupo('5a · la racha salta al registrar la venta');
$CLI5 = insertar('clientes', ['pais_id' => $PE5, 'asesor_id' => (int)$F['usuarios']['asesor'], 'tipo_doc' => 'DNI', 'documento' => '70636555',
    'nombre' => 'Lucía', 'apellidos' => 'Prueba', 'email' => 'cli-racha@waka.test', 'celular' => '900111555', 'canal_item_id' => (int)$F['canal'], 'activo' => 1]);
$antes5 = racha_dia((int)$F['usuarios']['asesor']);
$n->ir('/pedidos/nuevo', ['_t' => $n->testigoValido(), 'tipo' => 'inmediata', 'cliente_id' => $CLI5, 'entrega' => 'recojo',
    'canal_item_id' => (int)$F['canal'], 'l_desc' => ['Silla Gamer'], 'l_sku' => [''], 'l_cant' => ['1'], 'l_precio' => ['379'],
    'fecha' => date('Y-m-d'), 'modo_pago' => 'completo', 'pago_monto' => '379.00',
    'pago_metodo_item_id' => (int)$F['efectivo'], 'pago_fecha' => date('Y-m-d')]);
ok('la venta se registra', str_contains($n->cuerpo, 'Pedido registrado'));
es('un cliente distinto más en la racha de hoy', $antes5 + 1, racha_dia((int)$F['usuarios']['asesor']));
ok('LA RACHA SALTA ENCIMA DE «PEDIDO REGISTRADO», SIN QUITARLO', str_contains($n->cuerpo, 'id="racha-pop"') && str_contains($n->cuerpo, 'x' . ($antes5 + 1)) && str_contains($n->cuerpo, 'Pedido registrado'));
$n->ir('/pedidos/ficha?id=' . (int) valor('SELECT MAX(id) FROM pedidos') . '&nuevo=1');
ok('y una sola vez', !str_contains($n->cuerpo, 'id="racha-pop"'));

grupo('5a · el CEO lanza la Cacería y la ven al entrar');
$n->salir(); $n->entrar('ceo@waka.test', 'Clave-Larga-1');
$n->ir('/bonos');
ok('EL CEO VE «CÓMO VAN LOS BONOS»', str_contains($n->cuerpo, 'id="como-van"') && str_contains($n->cuerpo, 'id="bono-total"'));
ok('con el botón para lanzar la de hoy', str_contains($n->cuerpo, 'LANZAR EL BONO DEL DÍA'));
$n->ir('/bonos/caceria');
ok('la pantalla de lanzar abre, con «Otra frase» y la vista previa', $n->codigo === 200 && str_contains($n->cuerpo, 'id="otra-frase"') && str_contains($n->cuerpo, 'id="c-previa"'));
$n->ir('/bonos/caceria', ['_t' => $n->testigoValido(), 'titulo' => 'Martes de caza', 'frase' => 'A buscarla',
                          'e_desde' => ['2', '4'], 'e_premio' => ['20', '50'], 'destino' => 'todos']);
ok('SE LANZA', (bool) caceria_de($PE5, date('Y-m-d')) && str_contains($n->cuerpo, 'Lanzada'));
$n->ir('/bonos/caceria');
ok('la segunda vez solo corrige el texto (la escalera ya no cambia)', str_contains($n->cuerpo, 'ya se lanzó') && str_contains($n->cuerpo, 'disabled'));
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/inicio');
ok('AL ENTRAR, EL ASESOR VE LA PILA CON LA CACERÍA', str_contains($n->cuerpo, 'id="pila-novedades"') && str_contains($n->cuerpo, 'Martes de caza'));
ok('una sola X', substr_count($n->cuerpo, 'pila-nov__x') === 1);
$n->ir('/novedades/vista', ['_t' => $n->testigoValido(), 'claves' => ['cac-' . date('Y-m-d')]]);
ok('marcarla vista contesta', ($n->codigo === 200) && str_contains($n->cuerpo, '"ok":true'));
$n->ir('/inicio');
ok('Y YA NO SALE OTRA VEZ', !str_contains($n->cuerpo, 'id="pila-novedades"'));
$n->ir('/bonos');
ok('en Mis bonos la Cacería de hoy ya se ve', str_contains($n->cuerpo, 'id="bono-caceria"'));
$n->ir('/novedades/vista', null, false);
es('la marca de visto solo por POST', 404, $n->codigo);

grupo('5a · Administración configura y paga');
$n->salir(); $n->entrar('admin@waka.test', 'Clave-Larga-1');
$n->ir('/configuracion/bonos');
ok('LOS 7 BONOS, CADA UNO CON SU INTERRUPTOR', $n->codigo === 200 && substr_count($n->cuerpo, 'name="accion" value="encender"') >= 7);
$n->ir('/configuracion/bonos?id=' . (int)$Y5['id']);
ok('la ficha de la Yapa, con sus niveles', $n->codigo === 200 && str_contains($n->cuerpo, 'name="n_cuota[]"'));
$post = ['_t' => $n->testigoValido(), 'accion' => 'guardar', 'id' => (int)$Y5['id'], 'nombre' => 'La Yapa', 'nombre_anterior' => 'Bono Cuota',
         'mostrar_anterior' => '1', 'n_nombre' => ['Uno', 'Dos'], 'n_cuota' => ['1000', '2000'], 'n_premio' => ['100', '200'],
         'rapido_dias' => '5', 'rapido_premio' => '50', 'duo_premio' => '100'];
$n->ir('/configuracion/bonos?id=' . (int)$Y5['id'], $post);
es('GUARDA SIN PROGRAMADOR', [$S5 = 100000, 200000], array_column(bono_de((int)$Y5['id'])['reglas']['niveles'], 'cuota'));
$n->ir('/configuracion/bonos?id=' . (int)$Y5['id'], array_merge($post, ['_t' => $n->testigoValido(), 'n_cuota' => ['2000', '1000']]));
ok('y un error se dice en palabras', str_contains($n->cuerpo, 'cada nivel pide más que el anterior'));
$ajeno5 = bono_tipo((int)$F['MX'], 'yapa');
$n->ir('/configuracion/bonos?id=' . (int)$ajeno5['id'], null, false);
es('UN BONO DE OTRO PAÍS NO SE ABRE', 404, $n->codigo);
$n->ir('/configuracion/bonos', ['_t' => $n->testigoValido(), 'accion' => 'ajustes', 'poco_pct' => '80', 'cierre_horas' => '12', 'aviso_desde' => '3',
                                'racha_avisos' => '1', 'lobos_titulo' => 'Los lobos', 'frases_caceria' => "Uno\nDos", 'racha_n2' => 'Tibio']);
ajustes_olvidar();
es('los ajustes se guardan', ['80', 'Los lobos', 'Tibio'], [(string) ajuste('bonos_poco_pct'), (string) ajuste('los_lobos_titulo'), racha_nombre(2)]);
$n->ir('/configuracion/metas');
ok('la meta general se pone aquí', $n->codigo === 200);
/* Un premio cerrado para pagar. */
bono_cerrar(bono_de((int)$Y5['id']), '2026-08-01', '2026-08-15', '2026-08-16 12:00:00');
$R5 = insertar('bono_resultados', ['bono_id' => (int)$Y5['id'], 'pais_id' => $PE5, 'usuario_id' => (int)$F['usuarios']['asesor'], 'equipo_id' => 0,
    'periodo_inicio' => date('Y-m-d', strtotime('-12 days')), 'periodo_fin' => date('Y-m-d', strtotime('-2 days')), 'valor' => 2500000, 'ganado' => 1, 'premio_centimos' => 24000,
    'puesto' => null, 'detalle' => '{"texto":"S/ 25,000 de S/ 20,000"}', 'pagado' => 0, 'cerrado_en' => date('Y-m-d H:i:s')]);
$n->ir('/bonos');
ok('«POR PAGAR» LO ENSEÑA', str_contains($n->cuerpo, 'id="por-pagar"') && str_contains($n->cuerpo, 'name="id" value="' . $R5 . '"'));
$n->ir('/bonos/pagar', ['_t' => $n->testigoValido(), 'id' => $R5]);
es('PAGADO', 1, (int) valor('SELECT pagado FROM bono_resultados WHERE id = ?', [$R5]));
ok('y sale en «Lo pagado este mes»', str_contains($n->cuerpo, 'id="pagado-mes"') && str_contains($n->cuerpo, 'S/ 240'));
$n->ir('/bonos/pagar', ['_t' => $n->testigoValido(), 'id' => $R5, 'deshacer' => '1']);
es('se deshace el mismo día', 0, (int) valor('SELECT pagado FROM bono_resultados WHERE id = ?', [$R5]));
$n->salir(); $n->entrar('adminmx@waka.test', 'Clave-Larga-1');
$n->ir('/bonos/pagar', ['_t' => $n->testigoValido(), 'id' => $R5]);
ok('EL DE OTRO PAÍS NO LO PAGA', (int) valor('SELECT pagado FROM bono_resultados WHERE id = ?', [$R5]) === 0 && str_contains($n->cuerpo, 'Ese premio no existe'));
$n->salir(); $n->entrar('asesor@waka.test', 'Clave-Larga-1');
$n->ir('/bonos');
ok('EL ASESOR VE LO QUE YA SE CERRÓ, CON «COMPARTIR»', str_contains($n->cuerpo, 'id="bonos-resultados"') && str_contains($n->cuerpo, 'logro-compartir'));

/* Para la auditoría del celular (movil.cjs): lo que hay que abrir. */
file_put_contents(sys_get_temp_dir() . '/waka-3i.json', json_encode(['pedgar' => $PG3, 'gar' => $GA3, 'gar2' => $GB3, 'rep' => $JOY3, 'maq' => $MAQ3,
                                                                    'lote3j' => $L3J, 'pv3j' => $PV3J,
                                                                    'bonos' => array_map(fn($b) => (int)$b['id'], bonos_del_pais((int)$F['PE']))]));

exit(marcador());
