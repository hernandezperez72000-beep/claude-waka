<?php
declare(strict_types=1);
/**
 * LA RUTA DE ACTUALIZACIÓN.
 *
 * El HUB de Waka ya está instalado y funcionando, así que el módulo 2 no llega
 * a una base vacía: llega encima de una que ya tiene datos. Esta tanda arma un
 * HUB «viejo» —solo con el esquema del módulo 1— y le pasa por encima lo mismo
 * que hace actualizar.php.
 *
 * Lo que comprueba, y que ninguna otra tanda puede comprobar:
 *   · que un HUB actualizado y uno recién instalado quedan IGUALES,
 *     en tablas, en columnas, en índices y en DATOS de arranque;
 *   · que pasarlo dos veces no rompe nada;
 *   · que los datos que ya había siguen ahí.
 *
 * Aquí se pilló que los ajustes de los métodos de pago no convergían: en el
 * HUB actualizado todos los métodos contaban al instante, así que una
 * transferencia habría sumado a la meta sin que nadie la confirmara en el
 * banco. El código era el mismo en los dos sitios; los datos, no.
 */
require_once __DIR__ . '/comun.php';

require_once HUB_APP . '/nucleo/esquema.php';
require_once HUB_APP . '/nucleo/esquema2.php';
require_once HUB_APP . '/nucleo/esquema3.php';
require_once HUB_APP . '/nucleo/semillas.php';

/** Abre una base vacía y la deja como la conexión activa del HUB. */
function base_nueva(string $nombre): PDO
{
    $archivo = sys_get_temp_dir() . '/waka-' . $nombre . '.sqlite';
    if (is_file($archivo)) @unlink($archivo);
    $pdo = new PdoPruebas('sqlite:' . $archivo, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $GLOBALS['__pdo_instalacion'] = $pdo;
    return $pdo;
}

/** La foto de un HUB: sus tablas, sus columnas y sus datos de arranque. */
function retrato(): array
{
    $r = ['tablas' => [], 'metodos' => [], 'envios' => [], 'listas' => [], 'permisos' => [], 'estados' => [],
          'plantillas' => [], 'ajustes' => []];

    foreach (todas("SELECT name FROM sqlite_master WHERE type='table'
                     AND name NOT LIKE 'sqlite_%' ORDER BY name") as $t) {
        $cols = [];
        foreach (todas('PRAGMA table_info(`' . $t['name'] . '`)') as $c) $cols[] = $c['name'];
        sort($cols);
        $r['tablas'][$t['name']] = $cols;
    }
    /* Y con el `extra` y el `recargo_centesimas`, que son del parche 2j y son
       exactamente la forma de dato que ya divergió dos veces: nacen con su
       valor por defecto en un HUB actualizado y sembrados en uno nuevo. Sin
       capturarlos, «el POS sin su 4%» o «el efectivo que no se reconoce como
       efectivo» pasaban por esta prueba en verde. */
    foreach (todas("SELECT i.valor, i.pide_comprobante, i.al_instante,
                           COALESCE(i.extra,'') AS extra,
                           COALESCE(i.recargo_centesimas,0) AS recargo
                      FROM lista_items i JOIN listas l ON l.id = i.lista_id
                     WHERE l.clave = 'metodos_pago' ORDER BY i.valor") as $m) {
        $r['metodos'][$m['valor']] = (int)$m['pide_comprobante'] . '/' . (int)$m['al_instante']
                                   . '/' . (string)$m['extra'] . '/' . (int)$m['recargo'];
    }
    /* Los tipos de envío llevan su propia bandera desde el parche 2e, y una
       bandera nueva es exactamente lo que ya divergió una vez: la columna nace
       en 0 en un HUB actualizado y sembrada en uno nuevo. Contar los items no
       lo habría visto. */
    foreach (todas("SELECT i.valor, i.cobra_flete
                      FROM lista_items i JOIN listas l ON l.id = i.lista_id
                     WHERE l.clave = 'tipos_envio' ORDER BY i.valor") as $t) {
        $r['envios'][$t['valor']] = (int)$t['cobra_flete'];
    }
    foreach (todas("SELECT l.clave, COUNT(i.id) AS n FROM listas l
                      LEFT JOIN lista_items i ON i.lista_id = l.id
                     GROUP BY l.clave ORDER BY l.clave") as $l) {
        $r['listas'][$l['clave']] = (int)$l['n'];
    }
    /* LOS AJUSTES, con su valor. Faltaban, y son exactamente la forma de dato
       que esta prueba existe para vigilar: un ajuste sembrado en la
       instalación y no en la actualización deja al HUB actualizado sin él, y
       la función que lo lee cae a su valor por defecto escrito en el código —
       que es lo que este proyecto llama «dos definiciones de la misma cifra».
       Con `reclamo_minutos` (2k) pasaba sin que ninguna prueba lo viera. */
    foreach (todas('SELECT clave, valor FROM ajustes ORDER BY clave') as $aj) {
        $r['ajustes'][(string)$aj['clave']] = (string)$aj['valor'];
    }
    $r['permisos'] = array_column(todas('SELECT clave FROM permisos ORDER BY clave'), 'clave');
    $r['roles']    = array_column(todas('SELECT clave FROM roles ORDER BY clave'), 'clave');
    foreach (todas('SELECT r.clave, p.clave AS permiso FROM rol_permiso rp
                      JOIN roles r ON r.id = rp.rol_id
                      JOIN permisos p ON p.id = rp.permiso_id
                     ORDER BY r.clave, p.clave') as $rp) {
        $r['rol_permiso'][] = $rp['clave'] . '·' . $rp['permiso'];
    }
    foreach (todas('SELECT clave, dispara FROM pedido_estados ORDER BY clave') as $e) {
        $r['estados'][$e['clave']] = (string)$e['dispara'];
    }
    /* Dónde aplica cada tipo de envío: la columna nace vacía, que significa
       «en todas», así que un HUB sin el backfill seguiría ofreciendo «Envío
       gratis Lima» en una venta a Cusco y convergía en verde igual. */
    $r['envios_ambito'] = [];
    if (columna_existe('lista_items', 'ambito_envio')) {
        foreach (todas("SELECT i.valor, i.ambito_envio FROM lista_items i
                          JOIN listas l ON l.id = i.lista_id
                         WHERE l.clave = 'tipos_envio' ORDER BY i.valor") as $te) {
            $r['envios_ambito'][$te['valor']] = (string)$te['ambito_envio'];
        }
    }
    /* La marca que dice qué garantía le toca a cada tipo de venta. Es la misma
       trampa una vez más: sembrar_listas() solo la escribe al CREAR el item,
       así que un HUB donde «No aplica» ya existía se quedaba sin ella y la
       liquidación salía con doce meses de garantía. Contar los items de la
       lista —que es lo que hace $r['listas']— no lo habría visto. */
    $r['garantias'] = [];
    if (columna_existe('lista_items', 'extra')) {
        foreach (todas("SELECT i.valor, i.extra FROM lista_items i
                          JOIN listas l ON l.id = i.lista_id
                         WHERE l.clave = 'garantias' ORDER BY i.valor") as $g) {
            $r['garantias'][$g['valor']] = (string)($g['extra'] ?? '');
        }
    }
    $r['ubigeo'] = (int) valor('SELECT COUNT(*) FROM ubigeo');
    /* Y el CONTENIDO, no solo cuántos son. Contar filas dejaba pasar la
       divergencia de verdad: el mismo distrito escrito distinto en un HUB y en
       otro —«Villa el Salvador» contra «Villa El Salvador»— sale en el
       buscador, en la ficha y en el mensaje al cliente, y el recuento no se
       entera. Se guarda un resumen para no acarrear 2.114 nombres. */
    /* Con su provincia y su departamento: el nombre solo era ciego a un
       distrito colgado de otro padre, y hay 102 nombres de distrito repetidos
       en el país. Un «Miraflores» que cuelgue de Cusco en vez de Lima cambia
       si la venta lleva agencia y flete o envío gratis. */
    /* Con la capital: 357 distritos la tienen y es lo que permite encontrarlos.
       Sin incluirla, un HUB sin capitales convergía en verde con uno que sí las
       tiene, que es justo la divergencia que esta función existe para cazar. */
    $r['ubigeo_capitales'] = (int) valor('SELECT COUNT(*) FROM ubigeo WHERE busca_capital IS NOT NULL');
    $r['ubigeo_sitios'] = md5((string) valor(
        "SELECT GROUP_CONCAT(x) FROM (
            SELECT (d.tipo || '|' || d.nombre || '|' || COALESCE(d.capital,'')
                    || '|' || COALESCE(p.nombre,'') || '|' || COALESCE(dp.nombre,'')) AS x
              FROM ubigeo d
              LEFT JOIN ubigeo p  ON p.id  = d.padre_id
              LEFT JOIN ubigeo dp ON dp.id = p.padre_id
             ORDER BY 1) t"));
    /* Las plantillas de WhatsApp también: si una nueva no se sembrara en la
       ruta de actualización, el HUB actualizado daría un mensaje vacío y estas
       pruebas seguirían verdes sin enterarse. */
    /* El TEXTO, no su largo: dos plantillas distintas del mismo largo pasarían
       la comparación y el HUB actualizado mandaría un mensaje equivocado. */
    foreach (todas('SELECT clave, texto FROM plantillas_whatsapp ORDER BY clave') as $pl) {
        $r['plantillas'][$pl['clave']] = (string)$pl['texto'];
    }
    return $r;
}

/* ─────────────  UN HUB RECIÉN INSTALADO  ───────────── */
grupo('Instalación nueva');

$pdo = base_nueva('nuevo');
foreach (esquema_sql() as $sql)         $pdo->exec(ddl_a_sqlite($sql));
foreach (esquema_sql_negocio() as $sql) $pdo->exec(ddl_a_sqlite($sql));
foreach (esquema_sql_modulo2() as $sql) $pdo->exec(ddl_a_sqlite($sql));
migraciones_modulo2();
sembrar_todo();
migraciones_tras_semillas();

$nuevo = retrato();
/* 25 departamentos + 196 provincias + 1.893 distritos. La cifra va escrita a
   mano a propósito: si un día el padrón se siembra a medias, esta prueba lo
   dice en vez de dejar al asesor sin poder registrar una venta a provincia. */
es('trae el padrón entero del Perú', 2114, $nuevo['ubigeo']);
/* Cuatro desde el 2026-09-09: se añadió «Envío con costo en Lima» para la pre
   venta, donde el delivery sí se cobra. La cifra va escrita a mano a propósito
   —si un día se cae una opción de la semilla, esta prueba lo dice. */
ok('tiene los cuatro tipos de envío',       ($nuevo['listas']['tipos_envio'] ?? 0) === 4);
ok('tiene el permiso de devolver un pago',  in_array('pagos.anular', $nuevo['permisos'], true));
ok('trae el rol de Facturación',            in_array('facturacion', $nuevo['roles'], true));
ok('Facturación confirma pagos',            in_array('facturacion·pagos.verificar', $nuevo['rol_permiso'], true));
ok('y NO registra pedidos',                !in_array('facturacion·pedidos.crear', $nuevo['rol_permiso'], true));
ok('Dirección también confirma pagos',      in_array('direccion·pagos.verificar', $nuevo['rol_permiso'], true));
es('una transferencia NO cuenta al instante', '1/0//0', $nuevo['metodos']['Transferencia BCP']);
es('el POS pide voucher y cobra su 4%', '1/0//400', $nuevo['metodos']['POS / tarjeta']);
es('y el efectivo se reconoce como efectivo', '0/0/efectivo/0', $nuevo['metodos']['Efectivo']);
es('«Envío con costo en Lima» cobra flete', 1, $nuevo['envios']['Envío con costo en Lima'] ?? -1);
es('«Envío gratis Lima» no cobra flete',     0, $nuevo['envios']['Envío gratis Lima'] ?? -1);

/* ─────────────  UN HUB QUE YA ESTABA FUNCIONANDO  ───────────── */
grupo('El HUB que ya está en producción, actualizado');

$pdo = base_nueva('viejo');
foreach (esquema_sql() as $sql)         $pdo->exec(ddl_a_sqlite($sql));
foreach (esquema_sql_negocio() as $sql) $pdo->exec(ddl_a_sqlite($sql));
sembrar_todo();

// Datos que ya existían: una cuenta y una meta puesta a mano.
$peru   = (int) valor("SELECT id FROM paises WHERE codigo = 'PE'");
$asesor = banco_usuario('asesor');
insertar('metas', ['pais_id' => $peru, 'usuario_id' => 0,
                   'periodo' => date('Y-m'), 'monto_centimos' => 3000000]);
/* Y una cuenta de Facturación con el ámbito estrecho, como podría haber
   quedado una creada a mano o antes de que el alta lo fijara. */
$rol_factu_viejo = (int) valor("SELECT id FROM roles WHERE clave = 'facturacion'");
$factu_viejo = $rol_factu_viejo
    ? banco_usuario('facturacion', ['ambito' => 'propio'])
    : 0;
$usuarios_antes = (int) valor('SELECT COUNT(*) FROM usuarios');

ok('el HUB viejo todavía no tiene la tabla ubigeo', !tabla_existe('ubigeo'));
ok('ni la columna del tipo de pago',                !columna_existe('pagos', 'tipo'));

// Y aquí llega actualizar.php.
foreach (esquema_sql_modulo2() as $sql) $pdo->exec(ddl_a_sqlite($sql));
$cambios = migraciones_modulo2();
sembrar_todo();

/* El HUB que está en producción arrancó con 79 distritos: Lima, Callao y
   Arequipa. Aquí se deja en ese estado a propósito, porque sembrar_ubigeo()
   solo siembra con la tabla vacía y en un HUB vivo NUNCA lo está: sin la
   migración, el HUB de banahosting se habría quedado en 79 mientras una
   instalación nueva tenía 1.893, y un asesor en Cusco no habría podido
   registrar su venta. */
q("DELETE FROM ubigeo WHERE tipo = 'distrito' AND padre_id NOT IN
     (SELECT id FROM ubigeo WHERE tipo = 'provincia'
       AND nombre IN ('Lima', 'Callao', 'Arequipa'))");
/* Y sin las provincias que nunca tuvo: el HUB de producción tenía 19, no 196.
   Si aquí solo se borraran los distritos, la rama de la migración que CREA
   provincias no se ejercería nunca — y es justo la rama donde el índice único
   no protege, porque un departamento va con padre_id NULL. */
q("DELETE FROM ubigeo WHERE tipo = 'provincia'
     AND nombre NOT IN ('Lima', 'Callao', 'Arequipa')");
/* Y a uno de los que se quedan se le estropea la grafía, como la tiene hoy el
   HUB de banahosting: ubigeo_titulo() bajaba la ele de «Villa El Salvador». */
q("UPDATE ubigeo SET nombre = 'Villa el Salvador' WHERE busca = 'villa el salvador'");
/* Y se le devuelve a las plantillas su texto de antes. Sin esto las
   migraciones quirúrgicas —las que meten {nota_flete} y {como_recibe} en
   plantillas YA sembradas— no se ejercen nunca: sembrar_todo() le siembra al
   HUB «viejo» las plantillas nuevas y no hay nada que parchear. La prueba de
   convergencia pasaba en verde con la migración borrada del todo. */
q("UPDATE plantillas_whatsapp SET texto = REPLACE(texto,
     '✅ Cómo lo recibe: {como_recibe}
', '') WHERE clave = 'despacho'");
/* Y la del mapa, que la mete migraciones_tras_semillas() del parche 2j. Sin
   quitarla, ese bloque no se ejerce NUNCA aquí: sembrar_todo() le siembra al
   HUB «viejo» la plantilla ya nueva y no hay nada que parchear. Es el mismo
   agujero que estas dos líneas de arriba existen para tapar. */
q("UPDATE plantillas_whatsapp SET texto = REPLACE(texto,
     '📍 Mapa: {mapa}
', '') WHERE clave = 'despacho'");
ok('el HUB viejo se queda sin la línea del mapa en la plantilla de despacho',
   !str_contains((string) valor("SELECT texto FROM plantillas_whatsapp WHERE clave='despacho'"),
                 '{mapa}'));
/* Las capitales las carga migraciones_tras_semillas(), que todavía no ha
   corrido en esta ruta: se le quitan para que la migración tenga que ponerlas.
   El ámbito de los tipos de envío NO se toca aquí porque su backfill va pegado
   a la creación de la columna, en migraciones_modulo2(), que ya pasó. */
q("UPDATE ubigeo SET capital = NULL, busca_capital = NULL");
ok('el HUB viejo se queda sin las capitales de los distritos', 0 ===
   (int) valor('SELECT COUNT(*) FROM ubigeo WHERE busca_capital IS NOT NULL'));

q("UPDATE plantillas_whatsapp SET texto = REPLACE(texto, '{nota_flete}',
     '📦 El flete lo paga el cliente en destino') WHERE clave = 'venta_provincia'");

/* Y sin la línea de la garantía, que es el parche nuevo. Sin esto, ese bloque
   de migraciones_tras_semillas() no se ejerce NUNCA en esta tanda: sembrar_todo()
   le siembra al HUB «viejo» las plantillas ya nuevas, así que se podría borrar
   la migración entera y la convergencia seguiría en verde. Es el mismo agujero
   que ya se tapó para {nota_flete} y {como_recibe}. */
q("UPDATE plantillas_whatsapp SET texto = REPLACE(texto, '✅ Garantía: {garantia}
', '') WHERE clave IN ('venta_lima','venta_provincia','venta_preventa')");
ok('el HUB viejo tiene las tres plantillas de venta sin la garantía', 0 ===
   (int) valor("SELECT COUNT(*) FROM plantillas_whatsapp
                 WHERE texto LIKE '%{garantia}%'"));

/* Y la marca que dice qué garantía le toca a la liquidación. sembrar_listas()
   solo escribe `extra` al CREAR el item, así que en un HUB donde «No aplica» ya
   existía se quedaría sin marca y la liquidación saldría con doce meses. */
q("UPDATE lista_items SET extra = NULL
    WHERE lista_id = (SELECT id FROM listas WHERE clave = 'garantias')");
ok('el HUB viejo tiene las garantías sin marcar', 0 ===
   (int) valor("SELECT COUNT(*) FROM lista_items
                 WHERE extra IS NOT NULL AND extra <> ''
                   AND lista_id = (SELECT id FROM listas WHERE clave = 'garantias')"));
ok('el HUB viejo tiene la plantilla de despacho sin «cómo lo recibe»',
   !str_contains((string) valor("SELECT texto FROM plantillas_whatsapp WHERE clave='despacho'"),
                 '{como_recibe}'));
$sitios_viejos = (int) valor('SELECT COUNT(*) FROM ubigeo');
ok('el HUB viejo se queda con los sitios con los que arrancó', $sitios_viejos < 200);

$cambios = array_merge($cambios, migraciones_tras_semillas());

ok('y la actualización le carga el padrón completo',
   (bool) array_filter($cambios, fn($x) => str_contains($x, 'padrón')),
   implode(' · ', $cambios));

/* Y le mete la línea de la garantía en las tres plantillas de venta, diciendo
   en cuál. El nombre de la plantilla salía VACÍO —«mensaje «: dice la
   garantía»— porque el » se comía la variable en la interpolación: tres
   warnings en el log de cPanel y tres líneas idénticas e inútiles en pantalla,
   justo en la pasada más delicada. */
$msg_gar = array_values(array_filter($cambios, fn($x) => str_contains($x, 'la garantía que se prometió')));
es('se parchean las tres plantillas de venta', 3, count($msg_gar));
ok('y cada línea dice de cuál habla',
   (bool) array_filter($msg_gar, fn($x) => str_contains($x, 'venta_lima'))
   && (bool) array_filter($msg_gar, fn($x) => str_contains($x, 'venta_preventa')),
   implode(' · ', $msg_gar));
ok('la garantía de liquidación queda marcada en la actualización',
   (bool) array_filter($cambios, fn($x) => str_contains($x, 'No aplica')),
   implode(' · ', $cambios));
es('Wanchaq, en Cusco, ya existe en el HUB actualizado', 1,
   (int) valor("SELECT COUNT(*) FROM ubigeo d JOIN ubigeo p ON p.id = d.padre_id
                 WHERE d.tipo='distrito' AND d.nombre='Wanchaq' AND p.nombre='Cusco'"));
es('y Ancón sigue escrito con tilde, no duplicado', 1,
   (int) valor("SELECT COUNT(*) FROM ubigeo WHERE tipo='distrito' AND busca='ancon'"));
es('las provincias vuelven a estar las 196', 196,
   (int) valor("SELECT COUNT(*) FROM ubigeo WHERE tipo = 'provincia'"));
es('las capitales vuelven', 357,
   (int) valor('SELECT COUNT(*) FROM ubigeo WHERE busca_capital IS NOT NULL'));
es('y el nombre mal escrito se corrige', 'Villa El Salvador',
   (string) valor("SELECT nombre FROM ubigeo WHERE busca = 'villa el salvador'"));

/* Las plantillas que ya estaban NO se resiembran —Administración las edita—,
   así que sin el parche quirúrgico el HUB actualizado se quedaría sin la
   línea que le dice a despacho si el cliente pasa por la agencia. */
ok('y la plantilla de despacho recibe «cómo lo recibe»',
   str_contains((string) valor("SELECT texto FROM plantillas_whatsapp WHERE clave='despacho'"),
                '{como_recibe}'));
ok('y la de provincia recupera la línea del flete',
   str_contains((string) valor("SELECT texto FROM plantillas_whatsapp WHERE clave='venta_provincia'"),
                '{nota_flete}'));

/* La marca es el candado: una segunda pasada no puede volver a sembrar. */
$sitios_tras = (int) valor('SELECT COUNT(*) FROM ubigeo');
migraciones_tras_semillas();
es('y una segunda actualización no siembra el país otra vez',
   $sitios_tras, (int) valor('SELECT COUNT(*) FROM ubigeo'));

ok('la actualización hizo cambios', count($cambios) > 10);
ok('y arregló el ámbito de la cuenta de Facturación que ya existía',
   (bool) array_filter($cambios, fn($x) => str_contains($x, 'pasan a ver todo su país')),
   implode(' · ', $cambios));
es('esa cuenta ahora ve todo su país', 'todo',
   (string) valor('SELECT ambito FROM usuarios WHERE id = ?', [$factu_viejo]));
es('y al asesor no se le tocó el ámbito', 'propio',
   (string) valor('SELECT ambito FROM usuarios WHERE id = ?', [$asesor]));
$viejo = retrato();

grupo('Los dos HUB quedan IGUALES');

$faltan = array_diff(array_keys($nuevo['tablas']), array_keys($viejo['tablas']));
es('no falta ninguna tabla', [], array_values($faltan));

$distintas = [];
foreach ($nuevo['tablas'] as $tabla => $cols) {
    $suyas = $viejo['tablas'][$tabla] ?? [];
    if (array_diff($cols, $suyas)) $distintas[] = $tabla . ': faltan ' . implode(',', array_diff($cols, $suyas));
}
es('ninguna tabla se queda sin columnas', [], $distintas);

es('los métodos de pago quedan configurados igual', $nuevo['metodos'], $viejo['metodos']);
es('las listas traen los mismos items',             $nuevo['listas'],  $viejo['listas']);
es('y los tipos de envío cobran flete igual',      $nuevo['envios'],  $viejo['envios']);
es('los permisos son los mismos',                   $nuevo['permisos'],$viejo['permisos']);
es('los roles son los mismos',                      $nuevo['roles'],   $viejo['roles']);
es('y cada rol tiene exactamente los mismos permisos',
   $nuevo['rol_permiso'], $viejo['rol_permiso']);
es('los estados de pedido disparan lo mismo',       $nuevo['estados'], $viejo['estados']);
es('y las plantillas de WhatsApp son las mismas',  $nuevo['plantillas'], $viejo['plantillas']);
es('el ubigeo tiene los mismos sitios',             $nuevo['ubigeo'],  $viejo['ubigeo']);
es('y las mismas capitales',       $nuevo['ubigeo_capitales'], $viejo['ubigeo_capitales']);
es('los tipos de envío aplican en los mismos sitios', $nuevo['envios_ambito'], $viejo['envios_ambito']);
es('y las garantías llevan la misma marca de tipo de venta',
   $nuevo['garantias'], $viejo['garantias']);
ok('con «No aplica» marcada como la de liquidación',
   ($viejo['garantias']['No aplica'] ?? '') === 'liquidacion',
   'si no, una venta de liquidación sale con doce meses de garantía');
es('y escritos exactamente igual',       $nuevo['ubigeo_sitios'], $viejo['ubigeo_sitios']);

grupo('Y no se perdió nada de lo que ya había');

es('las cuentas siguen ahí',   $usuarios_antes, (int) valor('SELECT COUNT(*) FROM usuarios'));
es('la meta general se conserva', 3000000,
   (int) valor('SELECT monto_centimos FROM metas WHERE usuario_id = 0'));
ok('el asesor sigue siendo el mismo',
   (bool) valor('SELECT 1 FROM usuarios WHERE id = ?', [$asesor]));

grupo('Pasarlo dos veces no rompe nada');

$revento = '';
try {
    foreach (esquema_sql_modulo2() as $sql) $pdo->exec(ddl_a_sqlite($sql));
    $segunda = migraciones_modulo2();
    sembrar_todo();
    $segunda = array_merge($segunda, migraciones_tras_semillas());
} catch (Throwable $ex) { $revento = $ex->getMessage(); }
ok('la segunda pasada no revienta', $revento === '', $revento);
es('y no tiene nada que añadir',    [], $segunda ?? ['algo']);
es('los métodos siguen como estaban', $nuevo['metodos'], retrato()['metodos']);

grupo('El HUB al que NO le pasaron actualizar.php sigue en pie');

/* LO QUE ESTO EVITA: `pagos_por_validar_resumen()` y `pagos_trabados_resumen()`
   corren en el MARCO, o sea en cada render de cada página. Una columna que
   falte ahí no rompe una pantalla: las rompe TODAS y para todos los roles —
   ni siquiera se puede aterrizar después de entrar. Se comprueba contra una
   base con las tablas del módulo 2 pero SIN sus migraciones, que es donde
   quedaría un HUB si actualizar.php muriera a media pasada. */
$archivo_sm = sys_get_temp_dir() . '/waka-sinmigrar.sqlite';
@unlink($archivo_sm);
$pdo_sm = new PdoPruebas('sqlite:' . $archivo_sm, null, null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$GLOBALS['__pdo_instalacion'] = $pdo_sm;
$pdo_sm->exec('PRAGMA foreign_keys = ON');
foreach (esquema_sql() as $sql)         $pdo_sm->exec(ddl_a_sqlite($sql));
foreach (esquema_sql_negocio() as $sql) $pdo_sm->exec(ddl_a_sqlite($sql));
foreach (esquema_sql_modulo2() as $sql) $pdo_sm->exec(ddl_a_sqlite($sql));
sembrar_todo();                       // a propósito: SIN migraciones_modulo2()

ok('la base de prueba está de verdad sin migrar',
   !columna_existe('pagos', 'en_espera') && !columna_existe('pagos', 'denegado'));


/* Hace falta UNA persona: sin ella las dos funciones del marco se van por el
   `if (!$u)` y no llegan a tocar la base — la prueba pasaría en verde sin
   probar nada, que es peor que no tenerla. */
$id_sm = insertar('usuarios', [
    'pais_id' => (int) valor("SELECT id FROM paises WHERE codigo = 'PE'"),
    'rol_id'  => (int) valor("SELECT id FROM roles WHERE clave = 'administracion'"),
    'nombre'  => 'Sin', 'apellidos' => 'Migrar', 'email' => 'sinmigrar@waka.test',
    'password_hash' => password_hash('Clave-Larga-1', PASSWORD_DEFAULT),
    'ambito' => 'todo', 'activo' => 1,
]);
$u_sm = una('SELECT u.*, r.clave AS rol FROM usuarios u
               JOIN roles r ON r.id = u.rol_id WHERE u.id = ?', [$id_sm]);
ok('hay una persona con la que preguntar', !empty($u_sm['id']));
$_SESSION['usuario_id'] = (int)$u_sm['id'];

foreach ([
    /* Se llama DOS VECES: con `true` solo se vacía el cache y se vuelve sin
       tocar la base — llamarlo así medía el vaciado, no la consulta. */
    'el resumen de pagos por validar' => function () use ($u_sm) {
        pagos_por_validar_resumen($u_sm, true);
        return pagos_por_validar_resumen($u_sm);
    },
    'el resumen de pagos trabados'    => function () use ($u_sm) {
        pagos_trabados_resumen($u_sm, true);
        return pagos_trabados_resumen($u_sm);
    },
    'el estado del pago de un pedido' => fn() => valor('SELECT (' . sql_estado_pago('?') . ')', [1]),
    'la regla del despacho'           => fn() => valor('SELECT COUNT(*) FROM pedidos pe WHERE '
                                                       . sql_despacho_listo()),
    'la cola de comprobantes'         => fn() => comprobantes_solicitados($u_sm),
    'los pedidos por despachar'       => fn() => pedidos_por_despachar_resumen($u_sm),
    'las tareas de fondo'             => fn() => tareas_del_hub(true),
    /* PARCHE 2R · el filtro Lima / provincia de la lista de pedidos. La columna
       del distrito es de las migraciones, no del CREATE TABLE: sin guarda, el
       filtro tumbaba una lista que hasta ahora se sostenía. */
    'el filtro Lima / provincia'      => function () {
        if (!columna_existe('pedidos', 'ubigeo_id')) return 0;
        return valor('SELECT COUNT(*) FROM pedidos p WHERE ' . sql_pedido_es_lima('p'));
    },
] as $que => $fn) {
    $mal = '';
    try { $fn(); } catch (Throwable $ex) { $mal = $ex->getMessage(); }
    ok("$que no revienta sin las columnas", $mal === '', $mal);
}

/* Y LA PANTALLA, no solo la función. «Por mandar a despacho» es desde el 2n
   una de las cinco pestañas fijas del celular del asesor: sin la columna daba
   un 500 a pantalla completa —sin menú ni barra— y el asesor se lo encontraba
   solo. La función ya se defendía; el controlador no.
   Esto mira el CÓDIGO del controlador y no lo ejecuta: montar el marco entero
   contra una base sin migrar es más frágil que el fallo que vigila. Es el
   cerrojo barato; el de verdad es que la guarda esté ANTES de la consulta. */
$ctrl_pd = (string) @file_get_contents(HUB_APP . '/controladores/pedidos/pordespachar.php');
$pos_guarda  = strpos($ctrl_pd, "columna_existe('pedidos', 'despacho_veces')");
$pos_consulta = strpos($ctrl_pd, 'despacho_veces = 0');
ok('la pantalla de despacho comprueba la columna antes de consultarla',
   $pos_guarda !== false && $pos_consulta !== false && $pos_guarda < $pos_consulta,
   'sin la guarda, esa pestaña del celular contesta un 500 a pantalla completa');
ok('y tiene una pantalla que explicar en vez del error',
   is_file(HUB_APP . '/vistas/pedidos/sinmigrar.php'));

$ctrl_nd = (string) @file_get_contents(HUB_APP . '/controladores/pedidos/nuevosdespacho.php');
ok('y el sondeo de cada 30 segundos también la comprueba',
   str_contains($ctrl_nd, "columna_existe('pedidos', 'despacho_veces')"),
   'un 500 cada medio minuto en el registro del servidor');

/* LOS TEXTOS DE PANTALLA SON PARA QUIEN USA EL HUB, NO PARA QUIEN LO HIZO.
   Auditoría del 2026-09-19: había frases como «El HUB no emite», «falta pasar
   actualizar.php» o «uno que abre un chat con nadie es peor que no tenerlo»
   delante del asesor. Esto recorre cada cadena y cada trozo de HTML que puede
   llegar a una pantalla —los comentarios no, que son para el programador— y
   falla si vuelve alguna de esas muletillas. Los instaladores (esquema*,
   limpieza, arranque) hablan con quien instala y quedan fuera. */
grupo('Textos de pantalla sin jerga del programador');
$prohibido = '/\b(el|al|del|este|en el) HUB\b|actualizar\.php|programador|plugin|voucher falso|m[oó]dulo \d|se pinta|versión cifrada|el servidor\b/iu';
$fuera = ['esquema.php', 'esquema2.php', 'esquema3.php', 'limpieza.php', 'arranque.php',
          'bd.php'];   // bd.php: solo «falta el archivo de configuración», al instalar
/* El nombre del producto sí puede salir: «Entrar al HUB» y el WhatsApp de
   bienvenida («tu acceso al HUB de Waka»). Lo que no, es el HUB como actor. */
$permitido = '/Entrar al HUB|acceso al HUB de Waka|«Plugins» › «Añadir nuevo» › «Subir plugin»/u';   // el menú de WordPress, tal cual (3c)
$hallados = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(HUB_APP));
$archivos = [dirname(HUB_APP) . '/index.php'];
foreach ($it as $f) if (substr((string)$f, -4) === '.php') $archivos[] = (string)$f;
foreach ($archivos as $f) {
    if (in_array(basename($f), $fuera, true)) continue;
    foreach (token_get_all((string)file_get_contents($f)) as $t) {
        if (!is_array($t)) continue;
        if (!in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML, T_ENCAPSED_AND_WHITESPACE], true)) continue;
        $s = $t[0] === T_INLINE_HTML ? strip_tags($t[1]) : $t[1];
        // los comentarios del JavaScript en línea también son para el programador
        $s = preg_replace(['~/\*.*?\*/~s', '~(?<![:"\'])//[^\n]*~'], '', $s);
        $s = preg_replace($permitido, '', $s);
        if (str_starts_with(trim($s, "'\""), '[HUB]')) continue;           // error_log
        if (preg_match($prohibido, $s, $m)) $hallados[] = basename($f) . ':' . $t[2] . ' «' . $m[0] . '»';
    }
}
ok('ninguna pantalla le habla al asesor del HUB, de actualizar.php ni del programador',
   !$hallados, implode(' · ', array_slice($hallados, 0, 8)));
ok('el índice de Configuración ya no explica su criterio de diseño',
   !str_contains((string)file_get_contents(HUB_APP . '/vistas/config/indice.php'), 'El criterio'));
ok('la bitácora dice «Inició sesión» y no «Entró al HUB»',
   bitacora_frase(['accion' => 'acceso.entrar']) === 'Inició sesión');
ok('el bloqueo de despacho ya no habla del voucher falso',
   !str_contains((string)file_get_contents(HUB_APP . '/nucleo/pedidos.php'), "'voucher falso"));

/* Y la pantalla, no solo la función: el controlador de la lista tiene que
   preguntar por la columna ANTES de meter el filtro en la consulta. */
$ctrl_lista = (string) @file_get_contents(HUB_APP . '/controladores/pedidos/lista.php');
$pos_guarda_z = strpos($ctrl_lista, "columna_existe('pedidos', 'ubigeo_id')");
$pos_uso_z    = strpos($ctrl_lista, 'sql_pedido_es_lima');
ok('la lista comprueba la columna del distrito antes de filtrar por zona',
   $pos_guarda_z !== false && $pos_uso_z !== false && $pos_guarda_z < $pos_uso_z,
   'sin la guarda, pulsar «Lima» contesta un 500 en un HUB a medio actualizar');

/* Y lo que se aprende solo se borra con el borrón y cuenta nueva: las
   sucursales apuntadas durante las pruebas son datos de movimiento. */
require_once HUB_APP . '/nucleo/limpieza.php';
ok('el borrón y cuenta nueva se lleva las sucursales aprendidas',
   in_array('agencia_sucursales', limpieza_tablas(), true),
   'se quedarían las erratas de la etapa de pruebas decidiendo el orden');

/* MÓDULO 3b. La lectura de la tienda es parte del catálogo: se va con él en el
   borrón que se lleva el catálogo, y no en el que lo deja. */
ok('el borrón que se lleva el catálogo se lleva también lo leído de la tienda',
   in_array('tienda_lecturas', limpieza_tablas(true), true));
ok('y el que deja el catálogo, lo deja', !in_array('tienda_lecturas', limpieza_tablas(false), true));
ok('un HUB actualizado tiene la tabla de lecturas de la tienda',
   isset($viejo['tablas']['tienda_lecturas']) && isset($nuevo['tablas']['tienda_lecturas']));

/* 3b.5 · el stock de la web: su tabla y su ajuste llegan también por actualizar.php. */
ok('un HUB actualizado tiene la tabla del stock de la web',
   isset($viejo['tablas']['stock_web']) && isset($nuevo['tablas']['stock_web']));
ok('el borrón que se lleva el catálogo se lleva también el stock de la web',
   in_array('stock_web', limpieza_tablas(true), true));

exit(marcador());
