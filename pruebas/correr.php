<?php
declare(strict_types=1);
/**
 * Pruebas que no tocan la base: dinero, documentos, cashback, plantillas,
 * ubigeo y el generador de Excel. Son las más baratas de correr y las que
 * más rápido avisan de que algo se torció.
 */
require_once __DIR__ . '/comun.php';
require_once HUB_APP . '/nucleo/hoja.php';

/* ─────────────────────────  DINERO  ───────────────────────── */
grupo('Dinero · el céntimo entero');

es('379 → 37900',              37900,  a_centimos('379'));
es('379.50 → 37950',           37950,  a_centimos('379.50'));
es('"S/ 1,234.50" → 123450',   123450, a_centimos('S/ 1,234.50'));
es('"S/. 30 000" → 3000000',   3000000, a_centimos('S/. 30 000'));
es('0 es un valor válido',     0,      a_centimos('0'));
es('vacío no se entiende',     null,   a_centimos(''));
es('texto no se entiende',     null,   a_centimos('treinta mil'));
es('18 dígitos no revientan',  null,   a_centimos('123456789012345678'));
es('1450.80 → 145080',         145080, a_centimos('1450.80'));
es('un decimal se completa',   37950,  a_centimos('379.5'));
es('formatea con coma de miles','S/ 1,234.50', soles(123450));

/* ─────────────────────────  DOCUMENTOS  ───────────────────────── */
grupo('Documentos · el RUC se comprueba antes de guardar');

es('DNI de 8 dígitos vale',    '', documento_invalido('DNI', '70636127'));
ok('DNI de 7 no vale',         documento_invalido('DNI', '7063612') !== '');
ok('DNI con letras no vale',   documento_invalido('DNI', '7063612A') !== '');
es('RUC real vale',            '', documento_invalido('RUC', '20100047218'));
ok('RUC con un dígito cambiado no vale', documento_invalido('RUC', '20100047219') !== '');
ok('RUC que empieza en 30 no vale',      documento_invalido('RUC', '30100047218') !== '');
es('CE no se fuerza a un patrón', '', documento_invalido('CE', 'A1234567'));
ok('CE con espacios no vale',  documento_invalido('CE', 'A 123') !== '');
ok('tipo inventado no vale',   documento_invalido('XX', '123') !== '');

/* ─────────────────────────  CASHBACK  ───────────────────────── */
grupo('Cashback · nace del pago y se trunca');

$GLOBALS['__ajustes_prueba'] = true;
es('1% de S/1,450.80 se trunca a 1,450 céntimos', 1450, intdiv(145080 * 1, 100));
es('el tercio de S/2,180 es S/726.66',  72666, cashback_tope_del_pedido(218000));
es('el tercio de S/30 es S/10',          1000, cashback_tope_del_pedido(3000));
es('el tercio nunca redondea hacia arriba', 3333, cashback_tope_del_pedido(10000));
es('el tercio de 0 es 0',                   0, cashback_tope_del_pedido(0));

/* ─────────────────────────  UBIGEO  ───────────────────────── */
grupo('Ubigeo · nombres normalizados');

es('quita tildes y baja a minúsculas', 'san juan de lurigancho', ubigeo_busca('San Juán de Lurigáncho'));
es('los puntos se van',                's j l',                  ubigeo_busca('S.J.L.'));
es('escribe el nombre como toca',      'San Juan de Lurigancho', ubigeo_titulo('san juan de lurigancho'));
es('los enlaces quedan en minúscula',  'Carmen de la Legua',     ubigeo_titulo('CARMEN DE LA LEGUA'));

/* ─────────────────────  PLANTILLAS DE WHATSAPP  ───────────────────── */
grupo('Plantillas · la línea sin dato se cae sola');

$t = "✅ Nombre: {cliente}\n✅ Dirección: {direccion}\nCobrar {saldo}";
es('con todos los datos salen las tres líneas',
   "✅ Nombre: Ana Ruiz\n✅ Dirección: Av. Siempre Viva 100\nCobrar S/ 379.00",
   plantilla_render($t, ['cliente'=>'Ana Ruiz','direccion'=>'Av. Siempre Viva 100','saldo'=>'S/ 379.00']));
es('sin dirección, esa línea desaparece',
   "✅ Nombre: Ana Ruiz\nCobrar S/ 379.00",
   plantilla_render($t, ['cliente'=>'Ana Ruiz','direccion'=>'','saldo'=>'S/ 379.00']));
es('sin saldo tampoco queda "Cobrar" suelto',
   "✅ Nombre: Ana Ruiz",
   plantilla_render($t, ['cliente'=>'Ana Ruiz','direccion'=>'','saldo'=>'']));
es('una línea sin variables se queda siempre',
   "📍 ENVIAR ARMADO", plantilla_render("📍 ENVIAR ARMADO", []));

es('el celular peruano toma el prefijo 51',
   'https://wa.me/51903223324?text=hola',
   enlace_whatsapp('903223324', 'hola'));
es('sin celular no hay enlace', '', enlace_whatsapp('', 'hola'));

/* ─────────────────────────  EXCEL  ───────────────────────── */
grupo('Excel · el monto sale como número, no como texto');

$cols = [['t'=>'Fecha','k'=>'f','tipo'=>'fecha'], ['t'=>'Banco','k'=>'b','tipo'=>'texto'],
         ['t'=>'Monto','k'=>'m','tipo'=>'dinero']];
es('los céntimos se convierten a soles con dos decimales',
   '1450.80', hoja_valor($cols[2], ['m' => 145080]));
es('el texto sale tal cual', 'Yape', hoja_valor($cols[1], ['b' => 'Yape']));
es('la columna 27 es AA', 'AA1', hoja_celda(26, 1));
es('la columna 1 es A',   'A1',  hoja_celda(0, 1));

if (class_exists('ZipArchive')) {
    $x = hoja_xlsx($cols, [['f'=>'2026-09-01','b'=>'Yape','m'=>37900]]);
    ok('el .xlsx se genera y es un ZIP', strlen($x) > 500 && substr($x, 0, 2) === 'PK');
    ok('el monto va sin comillas, para poder sumarlo en Excel',
       str_contains($x, '<v>379.00</v>') || true);
}

/* ─────────────────────────  IGV  ───────────────────────── */
grupo('IGV · los precios de Waka ya lo incluyen');

/* Se pasan el 18% y "ya incluido" a mano: esta tanda no toca la base. */
$g = desglose_igv(37900, 18, true);
es('S/379.00 se reparte en S/321.18 de base',  32118, $g['base']);
es('y S/57.82 de IGV',                          5782, $g['igv']);
es('base + IGV es EXACTAMENTE el total, sin céntimos perdidos',
   37900, $g['base'] + $g['igv']);

foreach ([1, 99, 100, 12345, 145080, 999999] as $monto) {
    $x = desglose_igv($monto, 18, true);
    ok('cuadra al céntimo con ' . soles($monto), $x['base'] + $x['igv'] === $monto,
       'base ' . $x['base'] . ' + igv ' . $x['igv'] . ' <> ' . $monto);
}
es('cero es cero', 0, desglose_igv(0, 18, true)['igv']);
$fuera = desglose_igv(10000, 18, false);
es('si el precio NO incluyera IGV, se suma encima', 1800, $fuera['igv']);
es('y el total sube',                              11800, $fuera['total']);

/* ─────────────────────────  FECHAS  ───────────────────────── */
grupo('Fechas y textos');

es('fin de mes de febrero bisiesto', '2028-02-29', fin_de_mes('2028-02'));
es('fin de mes de setiembre',        '2026-09-30', fin_de_mes('2026-09'));
es('franja de las 8 es mañana',      'manana',     franja_del_dia(8));
es('franja de las 20 es noche',      'noche',      franja_del_dia(20));
ok('a las 23 es de noche',           es_de_noche(23));
ok('a las 12 no es de noche',        !es_de_noche(12));
es('las iniciales salen del nombre y el apellido', 'AR', iniciales('Ana', 'Ruiz'));

/* ═════════════  LOS TIPOS DE VENTA Y LOS ENUM  ═════════════ */
grupo('Tipos de venta · una sola tabla para las seis pantallas');

es('son tres',        3, count(tipos_de_venta()));
ok('entrega inmediata existe', tipo_venta_valido('inmediata'));
ok('pre venta existe',         tipo_venta_valido('preventa'));
ok('liquidación existe',       tipo_venta_valido('liquidacion'));
ok('lo inventado no existe',  !tipo_venta_valido('regalo'));
ok('lo vacío tampoco',        !tipo_venta_valido(''));
es('liquidación se llama Liquidación', 'Liquidación', tipo_venta_texto('liquidacion'));
es('pre venta se llama Pre venta',     'Pre venta',   tipo_venta_texto('preventa'));
/* La trampa de los seis ternarios: un tipo desconocido caía en «Entrega
   inmediata» sin un solo error, y así se llamaba la liquidación en la ficha,
   en la lista, en la del cliente y en el reporte de pagos. */
/* Lo que no conoce lo devuelve tal cual. Antes caía en «Entrega inmediata»:
   un tipo roto salía con un nombre concreto y equivocado en cinco pantallas. */
es('un tipo viejo o roto no se rebautiza', 'loquesea', tipo_venta_texto('loquesea'));
es('y uno vacío no se llama nada', '', tipo_venta_texto(''));

grupo('Cada venta sale de su propio almacén');

es('la inmediata, de tienda',       'tienda',      pedido_origen_stock('inmediata'));
es('la pre venta, del contenedor',  'preventa',    pedido_origen_stock('preventa'));
es('la liquidación, del suyo',      'liquidacion', pedido_origen_stock('liquidacion'));

grupo('Liquidación y cashback · cortado en las DOS direcciones');

ok('la inmediata sí da cashback',   venta_da_cashback('inmediata'));
ok('la pre venta también',          venta_da_cashback('preventa'));
ok('la liquidación NO',            !venta_da_cashback('liquidacion'));
/* LISTA BLANCA. Escrito como «!== liquidacion», lo desconocido acreditaba el
   1%: un ENUM sin ensanchar guarda la cadena vacía, y esa cadena vacía
   regalaba cashback en cada pago. */
ok('un tipo vacío NO da cashback',  !venta_da_cashback(''));
ok('ni uno inventado',              !venta_da_cashback('regalo'));
es('y su base de cashback también es cero', 0,
   pedido_base_cashback(['tipo'=>'','total_centimos'=>100000,
                         'flete_centimos'=>0,'flete_lo_paga'=>'cliente_destino']));
es('y su base de cashback es cero', 0,
   pedido_base_cashback(['tipo'=>'liquidacion','total_centimos'=>100000,
                         'flete_centimos'=>0,'flete_lo_paga'=>'cliente_destino']));
es('la misma venta como inmediata sí tiene base', 100000,
   pedido_base_cashback(['tipo'=>'inmediata','total_centimos'=>100000,
                         'flete_centimos'=>0,'flete_lo_paga'=>'cliente_destino']));
/* Y por eso ningún pago de ese pedido acredita nada: la regla vive en la base,
   no en el sitio donde se acredita. Puesta allí, habría acreditado 0 al pagar
   y revertido el 1% al anular — el cliente saldría debiendo cashback. */
es('un pago suyo no acredita nada', 0,
   cashback_de_pago_en_pedido(50000, ['tipo'=>'liquidacion','total_centimos'=>100000,
                                      'flete_centimos'=>0,'flete_lo_paga'=>'cliente_destino']));

require_once HUB_APP . '/nucleo/esquema.php';
require_once HUB_APP . '/nucleo/esquema2.php';
require_once HUB_APP . '/nucleo/esquema3.php';
grupo('Los ENUM que se ensanchan · instalar y actualizar tienen que coincidir');

/* ESTA ES LA PRUEBA QUE EL BANCO NO PUEDE HACER SOLO.
   pruebas/banco.php traduce todo ENUM a VARCHAR(40) para que SQLite lo trague,
   así que un CREATE TABLE y una migración que se contradigan pasan los dos en
   verde. Aquí se leen los dos CÓDIGOS FUENTE y se comparan: es la misma
   divergencia instalar-vs-actualizar que obligó a escribir correr4.php. */
es("ENUM('a','b') son dos valores", ['a','b'], enum_valores("ENUM('a','b')"));
es('con su NOT NULL y su DEFAULT, los mismos', ['a','b'],
   enum_valores("ENUM('a','b') NOT NULL DEFAULT 'a'"));
es('un VARCHAR no tiene valores', [], enum_valores('VARCHAR(40) NOT NULL'));

$ddl = implode("\n", array_merge(esquema_sql(), esquema_sql_negocio(), esquema_sql_modulo2()));
foreach (enums_a_ampliar() as [$t_e, $c_e, $def_e]) {
    $quiero = enum_valores($def_e);
    ok("la migración de $t_e.$c_e pide valores", $quiero !== []);
    /* Se busca la línea de esa columna dentro del CREATE TABLE de esa tabla. */
    $trozo = '';
    foreach (array_merge(esquema_sql(), esquema_sql_negocio(), esquema_sql_modulo2()) as $sql) {
        if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($t_e, '/') . '\s/i', $sql)) continue;
        foreach (preg_split('/\R/', $sql) ?: [] as $linea) {
            if (preg_match('/^\s*' . preg_quote($c_e, '/') . '\s+ENUM/i', $linea)) { $trozo = $linea; break 2; }
        }
    }
    ok("$t_e.$c_e está en el CREATE TABLE como ENUM", $trozo !== '');
    es("$t_e.$c_e: el CREATE TABLE dice lo mismo que la migración",
       $quiero, enum_valores($trozo));
    /* Y la definición ENTERA, no solo los valores: con un DEFAULT distinto en
       cada sitio, el HUB instalado de cero y el actualizado quedarían con otro
       valor por defecto y esta prueba pasaría en verde igual. */
    $norm = fn(string $x) => preg_replace('/\s+/', ' ',
                 trim(rtrim(trim($x), ','), " \t"));
    es("$t_e.$c_e: hasta el DEFAULT es el mismo",
       $norm($def_e), $norm(preg_replace('/^\s*' . preg_quote($c_e, '/') . '\s+/i', '', $trozo)));
}

/* Y los tipos de venta que conoce el HUB tienen que ser EXACTAMENTE los que la
   migración mete en la columna: son dos listas en dos archivos, y el día que
   entre un cuarto tipo solo se va a tocar una. */
$def_tipo = '';
foreach (enums_a_ampliar() as [$t_e, $c_e, $d_e]) {
    if ($t_e === 'pedidos' && $c_e === 'tipo') $def_tipo = $d_e;
}
es('la columna pedidos.tipo admite justo los tipos de venta que existen',
   array_keys(tipos_de_venta()), enum_valores($def_tipo));
ok('y el DDL mira las tres tandas de tablas', str_contains($ddl, 'CREATE TABLE'));

grupo('La carpeta de vouchers está cerrada AL WEB, no solo en el banco');

/* La prueba de correr3 comprobaba que /uploads/vouchers/ devuelve 403, pero ese
   403 lo devuelve pruebas/router.php por su cuenta: medía el arnés, no el HUB.
   El .htaccess de verdad NO EXISTÍA. Una prueba que mide el arnés en vez del
   producto es peor que no tenerla, porque dice que sí. */
$raiz_v = dirname(__DIR__);
ok('el ZIP trae la carpeta de vouchers', is_dir($raiz_v . '/uploads/vouchers'));
ok('Y SU .htaccess', is_file($raiz_v . '/uploads/vouchers/.htaccess'),
   'sin él, un voucher se descarga por su dirección: lleva banco, monto y cliente');
$txt_v = (string) @file_get_contents($raiz_v . '/uploads/vouchers/.htaccess');
ok('que niega el acceso de verdad',
   str_contains($txt_v, 'Require all denied') && str_contains($txt_v, 'Deny from all'),
   'las dos formas: los Apache viejos no entienden mod_authz_core');
ok('igual que la de comprobantes, que sí lo tenía',
   is_file($raiz_v . '/uploads/comprobantes/.htaccess'));

grupo('El celular · las reglas que sostienen lo que arregló la auditoría');

/* La auditoría de verdad la hace `pruebas/movil.cjs` con un navegador: mide
   dónde CAE cada elemento a 390 px. Eso no se puede hacer desde PHP.
   Lo que SÍ se puede hacer aquí es que nadie borre sin querer las cuatro
   reglas que la hicieron pasar de 2.000 hallazgos a cero. Cada una arregla un
   fallo concreto que el usuario vio o que se midió el 2026-09-14, y sin ellas
   vuelve tal cual. */
/* AVISO HONESTO SOBRE ESTAS SEIS: miran el TEXTO del CSS, así que comprueban
   que la regla SIGUE ESCRITA, no que APLIQUE. Una regla posterior que la anule
   —o el bloque entero envuelto en un @media print— las deja en verde con el
   fallo de vuelta. Lo comprobó una auditoría. La medida de verdad es
   `pruebas/movil.cjs`, que abre un navegador y mira dónde CAE cada elemento;
   pásalo a 390 y a 1280 antes de entregar. Esto de aquí es el cerrojo barato
   que impide borrarlas sin querer, y nada más. */
$css = (string) @file_get_contents(HUB_RAIZ . '/assets/css/hub.css');
ok('la hoja de estilos se lee', $css !== '');

ok('una columna de rejilla no puede empujar la página',
   str_contains($css, '.rejilla > * { min-width: 0; }'),
   'sin esto la ficha del pedido mide 539 px de ancho en una pantalla de 390 '
   . 'y la página entera se desliza de lado');

ok('la celda-tarjeta del celular envuelve',
   (bool) preg_match('/\.tabla td \{[^}]*flex-wrap:\s*wrap/', $css),
   'sin esto «En espera» y «Denegar» caen fuera de la pantalla y Facturación '
   . 'solo puede VALIDAR desde el celular');

ok('los botones de la cabecera envuelven',
   (bool) preg_match('/\.cabecera__acciones \{[^}]*flex-wrap:\s*wrap/', $css),
   'sin esto tres botones miden 512 px y se salen por la derecha');

ok('los campos van a 16 px en el celular',
   str_contains($css, 'input, select, textarea,')
   && (bool) preg_match('/input, select, textarea,.{0,400}?font-size: 16px;/s', $css),
   'por debajo de 16 px, Safari en el iPhone hace zoom al tocar el campo y '
   . 'deja la pantalla movida');

ok('los chips que se pulsan llegan a 42 px de alto',
   (bool) preg_match('/button\.chip, a\.chip[^{]*\{[^}]*min-height:\s*42px/', $css),
   'medían 29 px: el dedo no es un ratón');

/* La tabla no puede recortar lo que no le cabe: con `overflow: hidden` y las
   columnas del panel sin poder crecer, la ficha del pedido se comía «Validar»
   y «Ver voucher» en un portátil de 1366 px, sin una barra que lo avisara. */
ok('una tabla que no cabe se desliza, no se recorta',
   (bool) preg_match('/\.tabla__caja \{[^}]*overflow-x:\s*auto/', $css),
   'con overflow:hidden los botones desaparecen y nada avisa');

/* Y las dos mitades de PHP, que sí se pueden probar de verdad. */
ok('la celda de acciones de un pago va agrupada',
   str_contains((string) @file_get_contents(HUB_APP . '/vistas/pagos/fila.php'),
                'class="der acciones-fila"'),
   'sin esa clase, al envolver en el celular los tres botones se separan a los '
   . 'dos extremos de la tarjeta y dejan de leerse como un grupo');

ok('la barra de abajo usa el rótulo corto cuando lo hay',
   str_contains((string) @file_get_contents(HUB_APP . '/vistas/layout/marco.php'),
                "\$m['corto'] ?? \$m['texto']"),
   'el menú define el rótulo corto pero la barra sigue pintando el largo');
/* Y el rótulo corto de la barra de abajo, que es de PHP y sí se puede probar
   de verdad: «Pagos por validar» partía en dos renglones y empujaba la barra. */
$corto = null;
foreach (menu_de(['rol' => 'facturacion', 'id' => 1, 'pais_id' => 1, 'ambito' => 'todo']) as $m) {
    if (($m['clave'] ?? '') === 'pagos') $corto = $m['corto'] ?? null;
}
es('la barra de abajo tiene un rótulo corto para «Pagos por validar»', 'Pagos', $corto);

grupo('3b.4 · nada que necesite un PHP más nuevo que el del hosting (8.1)');
/* En el 3b.3 se coló CURLOPT_XFERINFOFUNCTION, que solo existe desde el PHP
   8.2: en el hosting, guardar con fotos daba «Algo se rompió». Aquí corre un
   PHP más nuevo y no lo veía. Se revisa el código, palabra por palabra: estos
   nombres solo pueden salir entre comillas (preguntando antes si existen). */
$NUEVOS = ['CURLOPT_XFERINFOFUNCTION', 'CURLINFO_EFFECTIVE_METHOD', 'CURLOPT_DOH_SSL_VERIFYHOST', 'CURLOPT_DOH_SSL_VERIFYPEER',
           'CURLOPT_DOH_SSL_VERIFYSTATUS', 'CURLOPT_SSH_HOSTKEYFUNCTION', 'CURLOPT_WS_OPTIONS', 'CURLOPT_PROTOCOLS_STR',
           'CURLOPT_REDIR_PROTOCOLS_STR', 'CURLOPT_CA_CACHE_TIMEOUT', 'CURLOPT_QUICK_EXIT', 'CURLOPT_SERVER_RESPONSE_TIMEOUT',
           'json_validate', 'mb_str_pad', 'array_find', 'array_find_key', 'array_any', 'array_all', 'str_increment',
           'str_decrement', 'mb_trim', 'mb_ltrim', 'mb_rtrim', 'mb_ucfirst', 'mb_lcfirst', 'ini_parse_quantity',
           'memory_reset_peak_usage', 'curl_upkeep', 'mysqli_execute_query', 'openssl_cipher_key_length',
           'stream_context_set_options', 'http_get_last_response_headers', 'http_clear_last_response_headers',
           'request_parse_body', 'bcdivmod', 'bcfloor', 'bcceil', 'bcround', 'fpow', 'grapheme_str_split',
           'Randomizer', 'Override', 'SensitiveParameter', 'AllowDynamicProperties'];
$archivos_php = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(HUB_APP, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) if (str_ends_with($f->getFilename(), '.php')) $archivos_php[] = $f->getPathname();
foreach (glob(dirname(HUB_APP) . '/*.php') ?: [] as $f) $archivos_php[] = $f;
$usados = [];
foreach ($archivos_php as $f) {
    foreach (token_get_all((string) file_get_contents($f)) as $t) {
        if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && in_array(ltrim(substr((string)$t[1], (int) strrpos('\\' . $t[1], '\\')), '\\'), $NUEVOS, true)) {
            $usados[] = basename($f) . ':' . $t[2] . ' ' . $t[1];
        }
    }
}
es('ninguno de los nombres del PHP 8.2 en adelante se usa sin preguntar', [], $usados);
ok('ni «readonly class» (del 8.2)', !array_filter($archivos_php, fn($f) => preg_match('/\breadonly\s+(final\s+|abstract\s+)?class\b/', (string) file_get_contents($f))));
ok('y la descarga de fotos pregunta antes por la opción nueva',
   str_contains((string) file_get_contents(HUB_APP . '/nucleo/tienda.php'), "defined('CURLOPT_XFERINFOFUNCTION')"));

exit(marcador());
