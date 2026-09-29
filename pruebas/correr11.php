<?php
declare(strict_types=1);
/**
 * 5a · MÓDULO 5: BONOS, METAS Y RACHAS.
 *
 * El motor de los siete bonos (niveles, escalera, puestos, primeros, equipo),
 * los períodos, el cierre congelado, el Dúo y el Rápido de la Yapa, la
 * Cacería del Día, pagar y deshacer, la historia de niveles, los tres
 * contadores de racha y sus avisos, la pila de novedades, el modo team y la
 * meta general. Las pantallas las prueba correr3.
 */
require_once __DIR__ . '/comun.php';
banco_crear(sys_get_temp_dir() . '/waka-pruebas11.sqlite');
migraciones_tras_semillas();

$PERU = (int) valor("SELECT id FROM paises WHERE codigo = 'PE'");
$OTRO_PAIS = insertar('paises', ['nombre' => 'Chile', 'codigo' => 'CL', 'moneda' => 'CLP', 'simbolo' => '$']);
es('un país nuevo recibe sus bonos, apagados', true, str_contains(bonos_sembrar(), '7 bono(s) creados APAGADOS'));
$efectivo = (int) valor("SELECT i.id FROM lista_items i JOIN listas l ON l.id=i.lista_id WHERE l.clave='metodos_pago' AND i.valor='Efectivo'");
$OFI = insertar('oficinas', ['pais_id' => $PERU, 'nombre' => 'Lima Centro']);
$OFI2 = insertar('oficinas', ['pais_id' => $PERU, 'nombre' => 'Arequipa']);
$EQ1 = insertar('equipos', ['pais_id' => $PERU, 'nombre' => 'Los Lobos']);
$EQ2 = insertar('equipos', ['pais_id' => $PERU, 'nombre' => 'Las Águilas']);
$EQ3 = insertar('equipos', ['pais_id' => $PERU, 'nombre' => 'Los Pumas']);

$CEO   = banco_usuario('direccion');
$ADMIN = banco_usuario('administracion');
$A = banco_usuario('asesor', ['nombre' => 'Ana',   'equipo_id' => $EQ1, 'oficina_id' => $OFI]);
$B = banco_usuario('asesor', ['nombre' => 'Beto',  'equipo_id' => $EQ1, 'oficina_id' => $OFI]);
$C = banco_usuario('asesor', ['nombre' => 'Carla', 'equipo_id' => $EQ2, 'oficina_id' => $OFI]);
$D = banco_usuario('asesor', ['nombre' => 'Dani',  'equipo_id' => $EQ2, 'oficina_id' => $OFI2]);
$E = banco_usuario('asesor', ['nombre' => 'Eva',   'equipo_id' => $EQ3, 'oficina_id' => $OFI2]);

$clientes = [];
$cli = function (int $n) use (&$clientes, $PERU, $A) {
    if (isset($clientes[$n])) return $clientes[$n];
    return $clientes[$n] = insertar('clientes', ['pais_id' => $PERU, 'asesor_id' => $A, 'tipo_doc' => 'DNI',
        'documento' => sprintf('7%07d', $n), 'nombre' => 'Cliente' . $n, 'apellidos' => 'Prueba',
        'email' => "c11-$n@waka.test", 'celular' => sprintf('9%08d', $n), 'activo' => 1]);
};
/* Una venta: pedido registrado en $creado, con sus pagos [fecha, céntimos, confirmado]. */
$venta = function (int $asesor, int $cliente, int $total, string $creado, array $pagos = [], string $tipo = 'inmediata') use ($PERU, $efectivo, $cli) {
    $cobrado = 0;
    foreach ($pagos as $p) if (($p[2] ?? true)) $cobrado += $p[1];
    $id = insertar('pedidos', ['codigo' => 'b-' . bin2hex(random_bytes(5)), 'pais_id' => $PERU, 'cliente_id' => $cli($cliente),
        'asesor_id' => $asesor, 'tipo' => $tipo, 'estado_id' => (int) estado_por_clave(estado_inicial($tipo))['id'],
        'fecha' => substr($creado, 0, 10), 'entrega' => 'recojo', 'total_centimos' => $total, 'cobrado_centimos' => $cobrado,
        'creado_en' => $creado]);
    foreach ($pagos as $p) {
        insertar('pagos', ['pedido_id' => $id, 'monto_centimos' => $p[1], 'metodo_item_id' => $efectivo, 'fecha' => $p[0],
                           'verificado' => ($p[2] ?? true) ? 1 : 0, 'anulado' => 0]);
    }
    return $id;
};
$S = fn(float $s) => (int) round($s * 100);
$fila = fn(array $ev, $k) => $ev['filas'][$k] ?? null;

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · la base');
ok('las tablas del módulo 5 están', bonos_listo() && tabla_existe('novedades_vistas') && tabla_existe('racha_avisos'));
es('los 7 bonos de fábrica, en cada país', 7, (int) valor('SELECT COUNT(*) FROM bonos WHERE pais_id = ?', [$PERU]));
ok('y también en el otro país', (int) valor('SELECT COUNT(*) FROM bonos WHERE pais_id = ?', [$OTRO_PAIS]) === 7);
es('TODOS NACEN APAGADOS (decisión del 2026-09-29)', 0, (int) valor('SELECT COUNT(*) FROM bonos WHERE activo = 1'));
es('sembrar otra vez no duplica', '', bonos_sembrar());
es('las formas del motor', ['niveles', 'escalera', 'puestos', 'primeros', 'puestos', 'equipo', 'puestos'],
   array_column(bonos_del_pais($PERU), 'forma'));
ok('los permisos nuevos existen', (bool) valor("SELECT 1 FROM permisos WHERE clave = 'bonos.seguir'") && (bool) valor("SELECT 1 FROM permisos WHERE clave = 'bonos.pagar'"));
ok('Dirección sigue los bonos', (bool) valor("SELECT 1 FROM rol_permiso rp JOIN roles r ON r.id = rp.rol_id JOIN permisos p ON p.id = rp.permiso_id WHERE r.clave = 'direccion' AND p.clave = 'bonos.seguir'"));
ok('Administración paga los bonos', (bool) valor("SELECT 1 FROM rol_permiso rp JOIN roles r ON r.id = rp.rol_id JOIN permisos p ON p.id = rp.permiso_id WHERE r.clave = 'administracion' AND p.clave = 'bonos.pagar'"));
ok('el asesor NO paga ni configura', !valor("SELECT 1 FROM rol_permiso rp JOIN roles r ON r.id = rp.rol_id JOIN permisos p ON p.id = rp.permiso_id WHERE r.clave = 'asesor' AND p.clave IN ('bonos.pagar','bonos.configurar','bonos.seguir')"));
es('el umbral de «Dale con todo» se configura', '70', (string) ajuste('bonos_poco_pct'));
$Y = bono_tipo($PERU, 'yapa');
es('la Yapa: 7 niveles', 7, count($Y['reglas']['niveles']));
es('el nombre anterior no se enseña si no se pide', 'La Yapa Quincenal', bono_nombre($Y));
es('y se enseña entre paréntesis si se pide', 'La Yapa Quincenal (Bono Cuota Quincenal)', bono_nombre(['nombre' => 'La Yapa Quincenal', 'nombre_anterior' => 'Bono Cuota Quincenal', 'mostrar_anterior' => 1]));

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · los períodos');
es('semana: de lunes a domingo', ['2026-09-28', '2026-10-04'], bono_periodo('semana', '2026-09-29'));
es('el domingo es de su semana', ['2026-09-28', '2026-10-04'], bono_periodo('semana', '2026-10-04'));
es('1.ª quincena', ['2026-09-01', '2026-09-15'], bono_periodo('quincena', '2026-09-15'));
es('2.ª quincena hasta fin de mes', ['2026-09-16', '2026-09-30'], bono_periodo('quincena', '2026-09-16'));
es('febrero: la 2.ª termina el 28', ['2026-02-16', '2026-02-28'], bono_periodo('quincena', '2026-02-20'));
es('semestre 1', ['2026-01-01', '2026-06-30'], bono_periodo('semestre', '2026-06-30'));
es('semestre 2', ['2026-07-01', '2026-12-31'], bono_periodo('semestre', '2026-07-01'));
es('el día', ['2026-09-29', '2026-09-29'], bono_periodo('dia', '2026-09-29'));
es('la quincena que sigue', ['2026-10-01', '2026-10-15'], bono_periodo_siguiente('quincena', '2026-09-30'));
es('quedan días contando hoy', 2, bono_dias_quedan('2026-09-30', '2026-09-29'));
es('período terminado: 0', 0, bono_dias_quedan('2026-09-28', '2026-09-29'));
es('el texto de una quincena', 'del 16 al 30 de setiembre', bono_periodo_texto('quincena', '2026-09-16', '2026-09-30'));
es('el de una semana entre dos meses', 'del 28 de setiembre al 4 de octubre', bono_periodo_texto('semana', '2026-09-28', '2026-10-04'));

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · los niveles de cuota tienen historia');
banco_entrar($ADMIN);
$hoy = date('Y-m-d');
[$q_ini, $q_fin] = bono_periodo('quincena', $hoy);
$q_sig = bono_periodo_siguiente('quincena', $q_fin)[0];
es('sin nivel: 0', 0, nivel_de($E, $hoy));
es('EL PRIMER NIVEL VALE DESDE LA QUINCENA QUE VA (no se espera dos semanas)', $q_ini, usuario_nivel_poner($E, 2));
es('ya lo tiene', 2, nivel_de($E, $hoy));
es('UN CAMBIO RIGE DESDE LA QUINCENA SIGUIENTE', $q_sig, usuario_nivel_poner($E, 4));
es('la que va no cambia a mitad de camino', 2, nivel_de($E, $hoy));
es('la siguiente sí', 4, nivel_de($E, $q_sig));
usuario_nivel_poner($E, 2);
es('volver al que tenía deshace el cambio programado', 2, nivel_de($E, $q_sig));
es('el texto del nivel', 'Nivel Impulso · Meta #2', nivel_texto($PERU, 2));
es('sin nivel', 'Sin nivel', nivel_texto($PERU, 0));
/* Para las pruebas del motor: niveles fijos desde siempre. */
q('DELETE FROM usuario_niveles');
foreach ([$A => 1, $B => 1, $C => 3, $E => 2] as $u => $n) insertar('usuario_niveles', ['usuario_id' => $u, 'desde' => '2000-01-01', 'nivel' => $n]);
es('la historia manda por fecha', 3, nivel_de($C, '2026-08-01'));

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · La Yapa (niveles, Rápido y Dúo)');
/* Agosto 2026, 1.ª quincena. A (Arranque, S/20k) llega el día 3: Rápido.
   B (Arranque) llega a S/26k el día 12: gana Impulso, los niveles no son techo.
   C (Firme, S/30k) hace S/26k: no gana un nivel de abajo. D no tiene nivel. */
$venta($A, 1, $S(20000), '2026-08-03 10:00:00', [['2026-08-03', $S(20000)]]);
$venta($A, 2, $S(1000), '2026-08-10 10:00:00', [['2026-08-10', $S(1000)]]);
$venta($B, 3, $S(26000), '2026-08-12 10:00:00', [['2026-08-12', $S(26000)]]);
$venta($C, 4, $S(26000), '2026-08-12 11:00:00', [['2026-08-12', $S(26000)]]);
$venta($D, 5, $S(30000), '2026-08-12 12:00:00', [['2026-08-12', $S(30000)]]);
/* Un pago SIN confirmar no cuenta. */
$venta($C, 6, $S(9000), '2026-08-13 11:00:00', [['2026-08-13', $S(9000), false]]);
/* Un pago de otra quincena no cuenta en esta. */
$venta($B, 7, $S(5000), '2026-07-31 10:00:00', [['2026-07-31', $S(5000)]]);
$ev = bono_evaluar($Y, '2026-08-01', '2026-08-15', '2026-08-20');
es('A: cobró S/21,000', $S(21000), $fila($ev, $A)['valor']);
ok('A cumplió y llegó en los primeros 5 días: Rápido', $fila($ev, $A)['cumplio'] && $fila($ev, $A)['rapido']);
es('A: S/240 + S/50 del Rápido', $S(290), $fila($ev, $A)['premio']);
es('B: EL NIVEL NO ES TECHO — gana el de Impulso', $S(280), $fila($ev, $B)['premio']);
ok('B llegó tarde: sin Rápido', !$fila($ev, $B)['rapido']);
es('C: el pago sin confirmar no cuenta', $S(26000), $fila($ev, $C)['valor']);
ok('C no gana un nivel de abajo del suyo', !$fila($ev, $C)['gana'] && $fila($ev, $C)['premio'] === 0);
es('C: el cerrado dice «Ya fue»', BONO_FRASE_FUERA, $fila($ev, $C)['frase']);
ok('D sin nivel: no gana y se le dice qué hacer', !empty($fila($ev, $D)['sin_nivel']) && !$fila($ev, $D)['gana']);
es('se paga: 290 + 280', $S(570), $ev['se_paga']);
es('ganadores', 2, $ev['ganadores']);
$ev_vivo = bono_evaluar($Y, '2026-08-01', '2026-08-15', '2026-08-10');
es('A a mitad de camino ya la hizo', BONO_FRASE_HECHO, $fila($ev_vivo, $A)['frase']);
es('C con 0 de 30k el día 10: «No pierdas el ritmo»', BONO_FRASE_RITMO, $fila($ev_vivo, $C)['frase']);
ok('lo que falta dice cuánto sube', str_contains($fila($ev, $B)['falta'], 'sube de'));
/* EL DÚO: A también cumple la 2.ª quincena. */
$venta($A, 8, $S(20500), '2026-08-22 10:00:00', [['2026-08-22', $S(20500)]]);
$venta($B, 9, $S(19000), '2026-08-20 10:00:00', [['2026-08-20', $S(19000)]]);
$ev_off = bono_evaluar($Y, '2026-08-16', '2026-08-31', '2026-09-02');
ok('SI EN LA 1.ª QUINCENA EL BONO ESTABA APAGADO, NO HAY DÚO (auditoría 5a)', empty($fila($ev_off, $A)['duo']));
$Yd = $Y; $Yd['activo_desde'] = '2026-08-01';
$ev2 = bono_evaluar($Yd, '2026-08-16', '2026-08-31', '2026-09-02');
ok('A: cumplió las dos quincenas → Dúo', !empty($fila($ev2, $A)['duo']));
es('A: 240 + 100 del Dúo (el Rápido no: llegó el día 22)', $S(340), $fila($ev2, $A)['premio']);
ok('B no cumple la 2.ª: sin Dúo', empty($fila($ev2, $B)['duo']) && !$fila($ev2, $B)['gana']);
$ev1q = bono_evaluar($Yd, '2026-08-01', '2026-08-15', '2026-09-02');
ok('en la 1.ª quincena no hay Dúo', !isset($fila($ev1q, $A)['duo']));

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · por puesto (Alfa): clasifica, empata y los tramos son para todos');
$AS = bono_tipo($PERU, 'alfa_semana');
$r = $AS['reglas'];
$r['clasifica_monto'] = $S(1000); $r['clasifica_ops'] = 2; $r['premios'] = [$S(900), $S(600), 0];
$r['tramos'] = [['desde' => $S(5000), 'extra' => $S(400)], ['desde' => $S(8000), 'extra' => $S(400)]];
/* Semana del lunes 7 al domingo 13 de setiembre de 2026. */
foreach ([[$A, 9000, 2], [$B, 6000, 2], [$C, 6000, 2], [$D, 5500, 1], [$E, 900, 3]] as [$u, $monto, $ops]) {
    for ($i = 0; $i < $ops; $i++) {
        $m = $S($monto / $ops);
        $venta($u, 100 + $u * 10 + $i, $m, '2026-09-08 10:00:00', [['2026-09-08', $m]]);
    }
}
/* La pre venta no cuenta en Alfa de la Semana; una venta chica tampoco. */
$venta($E, 150, $S(3000), '2026-09-08 10:00:00', [['2026-09-08', $S(3000)]], 'preventa');
$venta($E, 151, $S(150), '2026-09-08 10:00:00', [['2026-09-08', $S(150)]]);
$ev = bono_evaluar($AS, '2026-09-07', '2026-09-13', '2026-09-14', $r);
es('A primero', 1, $fila($ev, $A)['puesto']);
es('A: S/900 + los dos tramos', $S(1700), $fila($ev, $A)['premio']);
es('B y C EMPATAN: comparten el 2.º', [2, 2], [$fila($ev, $B)['puesto'], $fila($ev, $C)['puesto']]);
es('los dos cobran el 2.º + el tramo de S/5,000', [$S(1000), $S(1000)], [$fila($ev, $B)['premio'], $fila($ev, $C)['premio']]);
ok('D no clasifica: una sola operación', !$fila($ev, $D)['clasifica'] && str_contains($fila($ev, $D)['falta'], '1 operación'));
ok('D no cobra el tramo aunque pase los S/5,000 (primero hay que clasificar)', $fila($ev, $D)['premio'] === 0);
es('E: la pre venta y la venta chica no cuentan', $S(900), $fila($ev, $E)['valor']);
ok('E no clasifica por monto', !$fila($ev, $E)['clasifica'] && str_contains($fila($ev, $E)['falta'], 'S/ 100'));
es('el orden', [$A, $B, $C], $ev['orden']);
es('se paga todo', $S(3700), $ev['se_paga']);
ok('el de puesto nunca dice «Ya la hiciste»', $fila($ev, $A)['frase'] === 'Quedaste 1.º');
$ev_v = bono_evaluar($AS, '2026-09-07', '2026-09-13', '2026-09-09', $r);
es('en curso: «puede cambiar»', 'Vas 1.º · puede cambiar', $fila($ev_v, $A)['frase']);
/* Un tercer puesto con premio 0 y sin tramo no gana. */
$r3 = $r; $r3['tramos'] = [];
$ev = bono_evaluar($AS, '2026-09-07', '2026-09-13', '2026-09-14', $r3);
es('sin tramos: 900 + 600 + 600', $S(2100), $ev['se_paga']);

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · Sin Freno: los primeros en llegar a N clientes distintos');
$SF = bono_tipo($PERU, 'sin_freno');
$r = $SF['reglas']; $r['meta'] = 3; $r['minimo'] = 0; $r['premios'] = [$S(300), $S(150)];
/* Semana del 14 al 20 de setiembre. */
$venta($B, 201, $S(100), '2026-09-14 09:00:00', [['2026-09-14', $S(100)]]);
$venta($B, 202, $S(100), '2026-09-14 09:10:00', [['2026-09-14', $S(100)]]);
$venta($B, 203, $S(100), '2026-09-15 09:00:00', [['2026-09-15', $S(100)]]);      // B llega el martes
$venta($A, 204, $S(100), '2026-09-14 08:00:00', [['2026-09-14', $S(100)]]);
$venta($A, 205, $S(100), '2026-09-14 08:10:00', [['2026-09-14', $S(100)]]);
$venta($A, 205, $S(100), '2026-09-14 08:20:00', [['2026-09-14', $S(100)]]);      // mismo cliente: no suma
$venta($A, 206, $S(100), '2026-09-16 08:00:00', [['2026-09-16', $S(100)]]);      // A llega el miércoles
$venta($C, 207, $S(100), '2026-09-14 08:00:00', [['2026-09-14', $S(100)]]);
$venta($C, 208, $S(100), '2026-09-15 08:00:00', [['2026-09-15', $S(100)]]);
$venta($C, 209, $S(100), '2026-09-17 08:00:00', [['2026-09-17', $S(100)]]);      // C llega el jueves
$venta($D, 210, $S(100), '2026-09-14 08:00:00', []);                               // sin cobro: no cuenta
$venta($B, 211, $S(100), '2026-09-19 08:00:00', [['2026-09-19', $S(100)]]);      // B sigue vendiendo: llegó el martes igual
foreach ([212, 213, 214] as $k) $venta($E, $k, $S(100), '2026-09-14 07:00:00', [['2026-09-14', $S(100)]], 'preventa');   // pre venta: no
$ev = bono_evaluar($SF, '2026-09-14', '2026-09-20', '2026-09-21', $r);
es('el mismo cliente dos veces cuenta una', 3, $fila($ev, $A)['valor']);
es('B llegó primero (martes), A segundo (miércoles)', [1, 2], [$fila($ev, $B)['puesto'], $fila($ev, $A)['puesto']]);
es('premios', [$S(300), $S(150)], [$fila($ev, $B)['premio'], $fila($ev, $A)['premio']]);
ok('C llegó tercero: «Ya fue», sin premio', !$fila($ev, $C)['gana'] && $fila($ev, $C)['frase'] === BONO_FRASE_FUERA);
es('D: una venta sin cobro no cuenta', 0, $fila($ev, $D)['valor']);
es('el orden de llegada', [$B, $A, $C], $ev['orden']);
es('SEGUIR VENDIENDO NO CAMBIA CUÁNDO LLEGÓ', '2026-09-15 09:00:00', $fila($ev, $B)['llego_en']);
es('la pre venta no suma clientes en Sin Freno', 0, $fila($ev, $E)['valor']);
$ev_m = bono_evaluar($SF, '2026-09-14', '2026-09-20', '2026-09-15', $r);
ok('el martes, B ya la hizo y el resto sigue', $fila($ev_m, $B)['frase'] === BONO_FRASE_HECHO && $fila($ev_m, $A)['falta'] === 'Te faltan 1');

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · La Cacería del Día (escalera por gestiones)');
$CB = bono_tipo($PERU, 'caceria');
$ev = bono_evaluar($CB, '2026-09-22', '2026-09-22', '2026-09-23');
ok('SIN LANZAR NO EXISTE', $ev['lanzado'] === false && !$ev['filas'] && $ev['se_paga'] === 0);
insertar('caceria_dias', ['pais_id' => $PERU, 'fecha' => '2026-09-22', 'titulo' => 'Martes de caza', 'frase' => 'Vamos',
    'escalera' => json_encode([['desde' => 2, 'premio' => $S(20)], ['desde' => 4, 'premio' => $S(60)]]),
    'destino' => 'equipo:' . $EQ1, 'lanzada_en' => '2026-09-22 08:00:00']);
foreach (range(1, 4) as $i) $venta($A, 300 + $i, $S(500), "2026-09-22 1$i:00:00", [['2026-09-22', $S(500)]]);
$venta($A, 305, $S(500), '2026-09-22 16:00:00', []);                              // espera a Facturación
$venta($B, 306, $S(500), '2026-09-22 10:00:00', [['2026-09-22', $S(500)]]);
$venta($B, 307, $S(500), '2026-09-22 11:00:00', [['2026-09-22', $S(500)]], 'preventa');   // pre venta: no
$anulada = $venta($B, 308, $S(500), '2026-09-22 12:00:00', [['2026-09-22', $S(500)]]);
actualizar('pedidos', $anulada, ['anulado_en' => '2026-09-22 13:00:00']);          // anulada: no
foreach (range(1, 5) as $i) $venta($C, 320 + $i, $S(500), '2026-09-22 10:00:00', [['2026-09-22', $S(500)]]);
$ev = bono_evaluar($CB, '2026-09-22', '2026-09-22', '2026-09-22');
es('SOLO PARA A QUIEN SE LANZÓ (el equipo de A y B)', [$A, $B], array_keys($ev['filas']));
es('A: 4 cuentan, 1 espera', [4, 1], [$fila($ev, $A)['valor'], $fila($ev, $A)['extra']]);
ok('y el asesor lo ve', str_contains($fila($ev, $A)['texto'], '1 esperan que confirmen'));
es('A: la escalera DE ESE DÍA (4 → S/60)', $S(60), $fila($ev, $A)['premio']);
es('B: la pre venta y la anulada no cuentan', 1, $fila($ev, $B)['valor']);
es('B: le falta 1 para S/20', 'Te faltan 1 para S/ 20', $fila($ev, $B)['falta']);
es('destinatarios por oficina', [$D, $E], caceria_destinatarios(bono_asesores($PERU), 'oficina:' . $OFI2));

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · La Manada (equipo): cada uno un mínimo y el equipo junto');
$MA = bono_tipo($PERU, 'manada');
$r = $MA['reglas']; $r['min_integrante'] = $S(1000); $r['min_equipo'] = $S(3000); $r['premio_integrante'] = $S(500);
/* Octubre 2026, 1.ª quincena. Lobos (A, B): 2,500 + 1,500 = 4,000.
   Águilas (C, D): 3,000 + 1,100 = 4,100 → gana. Pumas (E): 5,000 pero equipo 5,000 > 3,000 → cumple, gana por más. */
foreach ([[$A, 2500], [$B, 1500], [$C, 3000], [$D, 1100], [$E, 900]] as [$u, $m]) {
    $venta($u, 400 + $u, $S($m), '2026-10-05 10:00:00', [['2026-10-05', $S($m)]]);
}
$ev = bono_evaluar($MA, '2026-10-01', '2026-10-15', '2026-10-16', $r);
ok('Pumas: Eva no llega al mínimo de S/1,000', !$ev['filas']['e' . $EQ3]['cumple'] && str_contains($ev['filas']['e' . $EQ3]['falta'], 'le faltan S/ 100'));
ok('Lobos y Águilas cumplen', $ev['filas']['e' . $EQ1]['cumple'] && $ev['filas']['e' . $EQ2]['cumple']);
ok('UN SOLO EQUIPO GANA: el de más cobrado (Águilas)', $ev['filas']['e' . $EQ2]['gana'] && !$ev['filas']['e' . $EQ1]['gana']);
es('S/500 por integrante', $S(1000), $ev['filas']['e' . $EQ2]['premio']);
es('se paga', $S(1000), $ev['se_paga']);
es('Lobos, 2.º', 2, $ev['filas']['e' . $EQ1]['puesto']);
$r_eq = $r; $r_eq['min_equipo'] = $S(4050);
$ev = bono_evaluar($MA, '2026-10-01', '2026-10-15', '2026-10-16', $r_eq);
ok('el equipo junto debe pasar el monto', !$ev['filas']['e' . $EQ1]['cumple'] && $ev['filas']['e' . $EQ2]['cumple']);
$r_mi = $r; $r_mi['min_integrante'] = $S(1200);
$ev = bono_evaluar($MA, '2026-10-01', '2026-10-15', '2026-10-16', $r_mi);
ok('UNO POR DEBAJO DEL MÍNIMO Y EL EQUIPO NO CUMPLE, AUNQUE JUNTOS PASEN', !$ev['filas']['e' . $EQ2]['cumple'] && $ev['filas']['e' . $EQ1]['cumple']);

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · encender, cerrar y congelar');
banco_entrar($ADMIN);
es('encender: ok', true, bono_encender((int)$Y['id'], true)['ok']);
es('cuenta desde hoy', date('Y-m-d'), (string) valor('SELECT activo_desde FROM bonos WHERE id = ?', [(int)$Y['id']]));
q('UPDATE bonos SET activo_desde = ? WHERE id = ?', ['2026-01-05', (int)$Y['id']]);
bono_encender((int)$Y['id'], true);
es('ENCENDER LO YA ENCENDIDO NO MUEVE LA FECHA (una segunda pestaña)', '2026-01-05', (string) valor('SELECT activo_desde FROM bonos WHERE id = ?', [(int)$Y['id']]));
/* Para probar el cierre: la Yapa y la Manada cuentan desde agosto. */
q('UPDATE bonos SET activo = 1, activo_desde = ?, cerrar_desde = ? WHERE id IN (?, ?)', ['2026-08-01', '2026-08-01', (int)$Y['id'], (int)$MA['id']]);
q('UPDATE bonos SET reglas = ? WHERE id = ?', [json_encode($r, JSON_UNESCAPED_UNICODE), (int)$MA['id']]);
es('ANTES DE LAS HORAS DE ESPERA NO SE CIERRA', 0, bonos_cerrar_vencidos('2026-08-16 11:00:00'));
es('pasadas las 12 horas, se cierra la 1.ª quincena (Yapa y Manada)', 2, bonos_cerrar_vencidos('2026-08-16 12:00:00'));
es('llamarlo otra vez no hace nada', 0, bonos_cerrar_vencidos('2026-08-16 12:30:00'));
$resA = una('SELECT * FROM bono_resultados WHERE bono_id = ? AND usuario_id = ? AND periodo_inicio = ?', [(int)$Y['id'], $A, '2026-08-01']);
es('el resultado de A quedó guardado', [$S(290), 1], [(int)$resA['premio_centimos'], (int)$resA['ganado']]);
ok('con lo que llevaba y el Rápido, en su detalle', !empty(json_decode($resA['detalle'], true)['rapido']));
ok('D (sin nivel, con ventas) queda sin premio', (int) valor('SELECT premio_centimos FROM bono_resultados WHERE bono_id = ? AND usuario_id = ?', [(int)$Y['id'], $D]) === 0);
ok('E (sin ventas) no se guarda', !valor('SELECT 1 FROM bono_resultados WHERE bono_id = ? AND usuario_id = ? AND periodo_inicio = ?', [(int)$Y['id'], $E, '2026-08-01']));
$reglas_cierre = json_decode((string) valor('SELECT reglas FROM bono_cierres WHERE bono_id = ? AND periodo_inicio = ?', [(int)$Y['id'], '2026-08-01']), true);
es('el cierre guardó las reglas con que se calculó', $S(240), $reglas_cierre['niveles'][0]['premio']);
/* Se cambia la regla DESPUÉS: lo cerrado no se mueve. */
$rn = $Y['reglas']; $rn['niveles'][0]['premio'] = $S(999); $rn['duo_premio'] = $S(100);
q('UPDATE bonos SET reglas = ? WHERE id = ?', [json_encode($rn), (int)$Y['id']]);
es('CAMBIAR EL BONO NO TOCA LO CERRADO', $S(290), (int) valor('SELECT premio_centimos FROM bono_resultados WHERE id = ?', [(int)$resA['id']]));
/* El Dúo de la 2.ª quincena se lee del CIERRE de la 1.ª. Para probarlo, se
   borra la venta de B de la 1.ª: en vivo ya no cumpliría, pero el cierre dice que sí. */
es('se cierra la 2.ª quincena', 1, bonos_cerrar_vencidos('2026-09-01 12:00:00') >= 1 ? 1 : 0);
$resA2 = una('SELECT * FROM bono_resultados WHERE bono_id = ? AND usuario_id = ? AND periodo_inicio = ?', [(int)$Y['id'], $A, '2026-08-16']);
ok('A: con Dúo (la 1.ª, del cierre)', !empty(json_decode($resA2['detalle'], true)['duo']));
es('y con la regla NUEVA para este período (999 + 100)', $S(1099), (int)$resA2['premio_centimos']);
/* Manada: se paga por integrante, la fila del equipo no lleva premio. */
$manada = todas('SELECT usuario_id, equipo_id, premio_centimos, ganado FROM bono_resultados WHERE bono_id = ? AND periodo_inicio = ? ORDER BY equipo_id, usuario_id', [(int)$MA['id'], '2026-08-01']);
ok('la Manada: una fila por equipo y una por integrante', count($manada) === 3 + 5, count($manada) . ' filas');
es('ninguna fila de equipo lleva premio', 0, (int) valor('SELECT COALESCE(SUM(premio_centimos),0) FROM bono_resultados WHERE bono_id = ? AND usuario_id = 0', [(int)$MA['id']]));
/* 1.ª de agosto: Águilas (Carla 26k + Dani 30k) le ganan a los Lobos (47k). */
es('en agosto ganan las Águilas: la fila del equipo y sus dos integrantes', 3, (int) valor('SELECT COUNT(*) FROM bono_resultados WHERE bono_id = ? AND ganado = 1 AND periodo_inicio = ?', [(int)$MA['id'], '2026-08-01']));
/* Octubre: ganan las Águilas → Carla y Dani cobran S/500 cada una. */
bonos_cerrar_vencidos('2026-10-16 12:00:00');
$oct = todas('SELECT id, usuario_id, premio_centimos FROM bono_resultados WHERE bono_id = ? AND periodo_inicio = ? AND usuario_id > 0 AND ganado = 1 ORDER BY usuario_id', [(int)$MA['id'], '2026-10-01']);
es('LA MANADA SE PAGA POR INTEGRANTE: Carla y Dani, S/500 cada una', [[$C, $S(500)], [$D, $S(500)]], array_map(fn($x) => [(int)$x['usuario_id'], (int)$x['premio_centimos']], $oct));
ok('los períodos cerrados van seguidos, sin saltos', (int) valor('SELECT COUNT(*) FROM bono_cierres WHERE bono_id = ? AND periodo_inicio >= ? AND periodo_inicio <= ?', [(int)$Y['id'], '2026-08-01', '2026-10-01']) === 5);
ok('UN BONO APAGADO NO SE CIERRA', !valor('SELECT 1 FROM bono_cierres WHERE bono_id = ?', [(int)$AS['id']]));
/* LO YA TERMINADO SE CIERRA Y SE PAGA AUNQUE SE APAGUE (auditoría 5a):
   Alfa de la Semana encendido desde el 6 de julio; se apaga en la semana del
   20: la del 13 (que ya había terminado) se cierra igual; la del 20 no cuenta. */
q('UPDATE bonos SET activo = 0, activo_desde = ?, cerrar_desde = ?, apagado_desde = ? WHERE id = ?', ['2026-07-06', '2026-07-06', '2026-07-20', (int)$AS['id']]);
$venta($A, 900, $S(30000), '2026-07-14 10:00:00', [['2026-07-14', $S(30000)]]);
$venta($A, 901, $S(30000), '2026-07-21 10:00:00', [['2026-07-21', $S(30000)]]);
es('apagado: se cierran las dos semanas que terminaron antes', 2, bonos_cerrar_vencidos('2026-08-05 12:00:00'));
ok('la del 13, con su resultado', (bool) valor('SELECT 1 FROM bono_resultados WHERE bono_id = ? AND periodo_inicio = ?', [(int)$AS['id'], '2026-07-13']));
ok('LA SEMANA EN QUE SE APAGÓ NO SE CIERRA', !valor('SELECT 1 FROM bono_cierres WHERE bono_id = ? AND periodo_inicio >= ?', [(int)$AS['id'], '2026-07-20']));
banco_entrar($ADMIN);
bono_encender((int)$AS['id'], false);
es('APAGAR LO YA APAGADO NO MUEVE LA FECHA (lo de en medio no se paga)', '2026-07-20', (string) valor('SELECT apagado_desde FROM bonos WHERE id = ?', [(int)$AS['id']]));
bono_encender((int)$AS['id'], true);
[$ini_hoy] = bono_periodo('semana', date('Y-m-d'));
es('al volver a encender, lo de en medio es una pausa', [['2026-07-20', $ini_hoy]], json_decode((string) valor('SELECT pausas FROM bonos WHERE id = ?', [(int)$AS['id']]), true));
bonos_cerrar_vencidos();
es('LA PAUSA NO SE PAGA NI SE CIERRA', 0, (int) valor('SELECT COUNT(*) FROM bono_cierres WHERE bono_id = ? AND periodo_inicio >= ?', [(int)$AS['id'], '2026-07-20']));
es('y el cierre sigue en la semana que va', $ini_hoy, (string) valor('SELECT cerrar_desde FROM bonos WHERE id = ?', [(int)$AS['id']]));
ok('la semana que va cuenta', bono_periodo_cuenta(bono_de((int)$AS['id']), $ini_hoy) && !bono_periodo_cuenta(bono_de((int)$AS['id']), '2026-07-27'));
bono_encender((int)$AS['id'], false);
ok('apagarlo otra vez: la que va deja de contar', !bono_periodo_cuenta(bono_de((int)$AS['id']), $ini_hoy));

/* UN BONO APAGADO DE ANTES (sin «apagado_desde»): no sigue cerrando como si
   estuviera encendido (verificación 5a). */
$AQ = bono_tipo($PERU, 'alfa_quincena');
q('UPDATE bonos SET activo = 0, activo_desde = ?, cerrar_desde = NULL, apagado_desde = NULL WHERE id = ?', ['2026-08-01', (int)$AQ['id']]);
bonos_cerrar_vencidos('2026-09-29 10:00:00');
es('APAGADO DE ANTES: NO CIERRA NADA', 0, (int) valor('SELECT COUNT(*) FROM bono_cierres WHERE bono_id = ?', [(int)$AQ['id']]));
es('y queda marcado desde cuándo no cuenta', '2026-08-01', (string) valor('SELECT apagado_desde FROM bonos WHERE id = ?', [(int)$AQ['id']]));
q('UPDATE bonos SET activo_desde = NULL, apagado_desde = NULL, cerrar_desde = NULL WHERE id = ?', [(int)$AQ['id']]);

/* APAGAR Y ENCENDER DENTRO DE LAS HORAS DE ESPERA NO BORRA NADA (auditoría 5a):
   la Cacería de ayer tiene premio; se apaga y se enciende hoy temprano. */
$ayer_c = date('Y-m-d', strtotime('-1 day'));
q('UPDATE bonos SET activo = 1, activo_desde = ?, cerrar_desde = ? WHERE id = ?', [$ayer_c, $ayer_c, (int)$CB['id']]);
insertar('caceria_dias', ['pais_id' => $PERU, 'fecha' => $ayer_c, 'titulo' => 'Ayer', 'escalera' => json_encode([['desde' => 1, 'premio' => $S(10)]]),
                          'destino' => 'todos', 'lanzada_en' => $ayer_c . ' 08:00:00']);
$venta($E, 950, $S(500), $ayer_c . ' 10:00:00', [[$ayer_c, $S(500)]]);
bono_encender((int)$CB['id'], false);
bono_encender((int)$CB['id'], true);
ok('no se inventó un cierre vacío', !valor('SELECT 1 FROM bono_cierres WHERE bono_id = ?', [(int)$CB['id']]));
bonos_cerrar_vencidos($ayer_c . ' 23:59:00');
bonos_cerrar_vencidos(date('Y-m-d H:i:s', strtotime($ayer_c . ' +1 day 12:00')));
es('LA CACERÍA DE AYER SE PAGA', $S(10), (int) valor('SELECT premio_centimos FROM bono_resultados WHERE bono_id = ? AND usuario_id = ? AND periodo_inicio = ?', [(int)$CB['id'], $E, $ayer_c]));
q('UPDATE bonos SET activo = 0, activo_desde = NULL, cerrar_desde = NULL, apagado_desde = NULL WHERE id = ?', [(int)$CB['id']]);

/* CAMBIAR LAS REGLAS EN LAS HORAS DE ESPERA (auditoría 5a): la semana que
   terminó se cierra con las reglas que regían al terminar, no con las nuevas. */
[$sem_ini] = bono_periodo('semana', date('Y-m-d', strtotime('-7 days')));
q('UPDATE bonos SET activo = 1, activo_desde = ?, cerrar_desde = ? WHERE id = ?', [$sem_ini, $sem_ini, (int)$SF['id']]);
$r_vieja = bono_de((int)$SF['id'])['reglas'];
es('se cambia el premio antes del cierre', true, bono_guardar((int)$SF['id'], ['nombre' => 'Sin Freno', 'meta' => '22', 'premios' => ['999', '1']])['ok']);
$ra = json_decode((string) valor('SELECT reglas_antes FROM bonos WHERE id = ?', [(int)$SF['id']]), true);
es('las de antes quedan guardadas hasta esta semana', [$ini_hoy, $r_vieja['premios']], [$ra[0]['hasta'] ?? null, $ra[0]['reglas']['premios'] ?? null]);
bono_guardar((int)$SF['id'], ['nombre' => 'Sin Freno', 'meta' => '22', 'premios' => ['500']]);
es('un segundo cambio no pisa las que regían', $r_vieja['premios'], json_decode((string) valor('SELECT reglas_antes FROM bonos WHERE id = ?', [(int)$SF['id']]), true)[0]['reglas']['premios']);
bonos_cerrar_vencidos();
es('LA SEMANA PASADA SE CIERRA CON LAS REGLAS DE ENTONCES', $r_vieja['premios'],
   json_decode((string) valor('SELECT reglas FROM bono_cierres WHERE bono_id = ? AND periodo_inicio = ?', [(int)$SF['id'], $sem_ini]), true)['premios'] ?? null);
ok('y ya no hacen falta', valor('SELECT reglas_antes FROM bonos WHERE id = ?', [(int)$SF['id']]) === null);
es('la que va, con las nuevas', [$S(500)], bono_reglas_de_periodo(bono_de((int)$SF['id']), $ini_hoy)['premios']);
q('UPDATE bonos SET activo = 0 WHERE id = ?', [(int)$SF['id']]);

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · pagar (Administración) y deshacer');
banco_entrar($ADMIN);
$pp = bonos_por_pagar($PERU);
ok('por pagar: la Yapa de A y la Manada de Carla y Dani', in_array((int)$resA['id'], array_map('intval', array_column($pp, 'id')), true)
   && count(array_filter($pp, fn($x) => (int)$x['usuario_id'] === $C && $x['tipo'] === 'manada' && $x['periodo_inicio'] === '2026-10-01')) === 1);
ok('ninguna fila por pagar de premio 0', !array_filter($pp, fn($x) => (int)$x['premio_centimos'] <= 0));
es('pagar', true, bono_resultado_pagar((int)$resA['id'])['ok']);
es('dos veces, no', 'Ese premio ya estaba pagado.', bono_resultado_pagar((int)$resA['id'])['error']);
ok('ya no está por pagar', !in_array((int)$resA['id'], array_map('intval', array_column(bonos_por_pagar($PERU), 'id')), true));
es('lo pagado del mes, por bono', ['La Yapa Quincenal' => $S(290)], bonos_pagado_mes($PERU, date('Y-m')));
$sin = (int) valor('SELECT id FROM bono_resultados WHERE premio_centimos = 0 LIMIT 1');
es('un resultado sin premio no se paga', 'Ese resultado no tiene premio.', bono_resultado_pagar($sin)['error']);
es('deshacer el mismo día', true, bono_resultado_despagar((int)$resA['id'])['ok']);
bono_resultado_pagar((int)$resA['id']);
q('UPDATE bono_resultados SET pagado_en = ? WHERE id = ?', ['2026-09-01 10:00:00', (int)$resA['id']]);
es('OTRO DÍA YA NO SE DESHACE', 'Solo se deshace el mismo día.', bono_resultado_despagar((int)$resA['id'])['error']);
/* Otro país: no se paga desde aquí. */
$gerente = banco_usuario('administracion', ['pais_id' => $OTRO_PAIS]);
banco_entrar($gerente);
if (!cruza_paises(yo())) es('el de otro país no lo encuentra', 'Ese premio no existe.', bono_resultado_pagar((int)$oct[0]['id'] ?? 0)['error'] ?? '');
banco_entrar($ADMIN);

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · configurar un bono (sin programador)');
banco_entrar($ADMIN);
$base = ['nombre' => 'La Yapa', 'nombre_anterior' => 'Bono Cuota', 'mostrar_anterior' => '1', 'minimo' => '',
         'n_nombre' => ['Uno', 'Dos'], 'n_cuota' => ['1,000', '2000'], 'n_premio' => ['100', '200'],
         'rapido_dias' => '5', 'rapido_premio' => '50', 'duo_premio' => '100'];
es('guardar', true, bono_guardar((int)$Y['id'], $base)['ok']);
$Yb = bono_de((int)$Y['id']);
es('los niveles, en céntimos', [$S(1000), $S(2000)], array_column($Yb['reglas']['niveles'], 'cuota'));
es('el nombre con el anterior', 'La Yapa (Bono Cuota)', bono_nombre($Yb));
es('cada nivel pide más que el anterior', 'Nivel «Dos»: cada nivel pide más que el anterior.', bono_guardar((int)$Y['id'], array_merge($base, ['n_cuota' => ['2000', '1000']]))['error']);
es('sin nombre', 'Ponle un nombre al bono.', bono_guardar((int)$Y['id'], array_merge($base, ['nombre' => ' ']))['error']);
es('escalera con dos escalones iguales', 'Dos escalones empiezan en 5.', bono_guardar((int)$CB['id'], ['nombre' => 'Cacería', 'e_desde' => ['5', '5'], 'e_premio' => ['50', '60']])['error']);
es('escalera ordenada', true, bono_guardar((int)$CB['id'], ['nombre' => 'Cacería del Día', 'e_desde' => ['10', '5'], 'e_premio' => ['100', '50'], 'sin_preventa' => '1'])['ok']);
es('queda de menor a mayor', [5, 10], array_column(bono_de((int)$CB['id'])['reglas']['escalera'], 'desde'));
es('Sin Freno sin meta', '¿A cuántos clientes hay que llegar?', bono_guardar((int)$SF['id'], ['nombre' => 'Sin Freno', 'meta' => '', 'premios' => ['300']])['error']);
$WK = bono_tipo($PERU, 'wakaciones');
es('Wakaciones con su destino', true, bono_guardar((int)$WK['id'], ['nombre' => 'Wakaciones', 'clasifica_monto' => '500000', 'premios' => ['0'], 'destino' => 'Punta Cana', 'premio_texto' => 'Viaje para dos'])['ok']);
es('el destino se guarda', 'Punta Cana', bono_de((int)$WK['id'])['reglas']['destino']);
es('la Manada', true, bono_guardar((int)$MA['id'], ['nombre' => 'La Manada', 'min_integrante' => '15000', 'min_equipo' => '140000', 'premio_integrante' => '500'])['ok']);
ok('el resumen en una línea', str_contains(bono_resumen(bono_de((int)$MA['id'])), 'S/ 500 por integrante'));
$ajeno = bono_tipo($OTRO_PAIS, 'yapa');
es('UN BONO DE OTRO PAÍS NO SE TOCA', 'Ese bono no existe.', bono_guardar((int)$ajeno['id'], $base)['error']);
es('ni se enciende', 'Ese bono no existe.', bono_encender((int)$ajeno['id'], true)['error']);

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · lanzar la Cacería de hoy');
banco_entrar($CEO);
q('UPDATE bonos SET activo = 0 WHERE id = ?', [(int)$CB['id']]);
$form = ['titulo' => 'Hoy se caza', 'frase' => 'Vamos todos', 'e_desde' => ['3', '6'], 'e_premio' => ['30', '70'], 'destino' => 'todos'];
ok('APAGADA NO SE LANZA', str_contains(caceria_lanzar($PERU, $form)['error'], 'apagada'));
q('UPDATE bonos SET activo = 1, activo_desde = ? WHERE id = ?', [date('Y-m-d'), (int)$CB['id']]);
es('sin título', 'Ponle un título a la Cacería de hoy.', caceria_lanzar($PERU, array_merge($form, ['titulo' => '']))['error']);
es('un destino de otro país no vale', 'Elige a quién le llega de la lista.', caceria_lanzar($PERU, array_merge($form, ['destino' => 'oficina:999']))['error']);
$l = caceria_lanzar($PERU, $form);
ok('lanzada, nueva', $l['ok'] && $l['nueva']);
$l2 = caceria_lanzar($PERU, array_merge($form, ['titulo' => 'Corregido', 'e_desde' => ['1'], 'e_premio' => ['999'], 'destino' => 'oficina:' . $OFI2]));
ok('la segunda vez corrige, no lanza otra', $l2['ok'] && !$l2['nueva']);
$hoyc = caceria_de($PERU, date('Y-m-d'));
es('el título cambia', 'Corregido', $hoyc['titulo']);
es('LA ESCALERA Y EL DESTINO NO (ya hay quien caza con esas reglas)', [[3, 6], 'todos'], [array_column($hoyc['escalera'], 'desde'), $hoyc['destino']]);
es('destino: texto', 'Oficina Arequipa', caceria_destino_texto('oficina:' . $OFI2));
es('destino: equipo', 'Los Lobos', caceria_destino_texto('equipo:' . $EQ1));

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · las rachas: tres contadores, no dos');
ajustes_olvidar();
$hoy = date('Y-m-d');
$ayer = date('Y-m-d', strtotime('-1 day'));
$R1 = banco_usuario('asesor', ['nombre' => 'Rita',  'oficina_id' => $OFI, 'equipo_id' => $EQ1]);
$R2 = banco_usuario('asesor', ['nombre' => 'Raúl',  'oficina_id' => $OFI, 'equipo_id' => $EQ1]);
$R3 = banco_usuario('asesor', ['nombre' => 'Rocío', 'oficina_id' => $OFI2, 'equipo_id' => $EQ2]);
es('sin ventas: 0', 0, racha_dia($R1));
$venta($R1, 500, $S(100), $hoy . ' 09:00:00');
$venta($R1, 500, $S(100), $hoy . ' 09:30:00');
es('CLIENTES DISTINTOS, NO PEDIDOS: partir una venta no sube nada', 1, racha_dia($R1));
$venta($R1, 501, $S(100), $hoy . ' 10:00:00');
$x = $venta($R1, 502, $S(100), $hoy . ' 10:30:00');
es('x3', 3, racha_dia($R1));
actualizar('pedidos', $x, ['anulado_en' => date('Y-m-d H:i:s')]);
es('ANULAR UN PEDIDO BAJA EL CONTADOR', 2, racha_dia($R1));
es('los nombres de la escalera', ['', 'Calentando', 'En racha', 'Encendido', 'Imparable', 'Imparable'],
   array_map('racha_nombre', [1, 2, 3, 4, 5, 9]));
guardar_ajuste('racha_nombres', json_encode([2 => 'Tibio', 3 => '', 4 => '', 5 => ''])); ajustes_olvidar();
es('se renombran (y lo vacío vuelve al de fábrica)', ['Tibio', 'En racha'], [racha_nombre(2), racha_nombre(3)]);
guardar_ajuste('racha_nombres', ''); ajustes_olvidar();
/* Días seguidos: R2 vendió los 4 días anteriores, hoy todavía no. */
foreach ([1, 2, 3, 4] as $d) $venta($R2, 600 + $d, $S(100), date('Y-m-d', strtotime("-$d days")) . ' 12:00:00');
$rd = racha_dias($R2);
es('LA RACHA DE AYER SIGUE VIVA HASTA QUE TERMINE HOY', [4, false], [$rd['dias'], $rd['hoy']]);
es('la mejor marca queda guardada', 4, (int) valor("SELECT mejor FROM rachas WHERE usuario_id = ? AND tipo = 'dias'", [$R2]));
es('la semana: 7 días', 7, count($rd['semana']));
$venta($R2, 610, $S(100), $hoy . ' 08:00:00');
es('vende hoy: 5', 5, racha_dias($R2)['dias']);
/* R3: vendió anteayer y 3 días antes, ayer no. */
foreach ([2, 3, 4] as $d) $venta($R3, 700 + $d, $S(100), date('Y-m-d', strtotime("-$d days")) . ' 12:00:00');
es('SE CORTÓ AYER: la de 3 días', 3, racha_cortada_ayer($R3));
es('y la racha de hoy es 0', 0, racha_dias($R3)['dias']);
es('R2 no se cortó', 0, racha_cortada_ayer($R2));
es('una de 2 días cortada no se anuncia', 0, racha_cortada_ayer($R1));

grupo('5a · los avisos de racha al equipo');
q('DELETE FROM racha_avisos');
/* R1 va x2 hoy; al llegar a x3 avisa (es la más alta de su oficina). */
$venta($R1, 503, $S(100), $hoy . ' 11:00:00');
$t = racha_tras_venta($R1);
es('R1: x3, En racha', [3, 'En racha'], [$t['n'], $t['nombre']]);
ok('EL PRIMER DÍA DE ALGUIEN NO ES «RÉCORD»', !$t['record']);
es('avisa: la marca más alta del día en su oficina', 1, (int) valor("SELECT COUNT(*) FROM racha_avisos WHERE usuario_id = ? AND tipo = 'dia'", [$R1]));
racha_tras_venta($R1);
es('la misma marca no avisa dos veces', 1, (int) valor('SELECT COUNT(*) FROM racha_avisos WHERE usuario_id = ?', [$R1]));
/* R2 llega a x3 también: no supera a R1 → no avisa. */
$venta($R2, 611, $S(100), $hoy . ' 09:00:00'); $venta($R2, 612, $S(100), $hoy . ' 10:00:00');
racha_tras_venta($R2);
es('IGUALAR LA MARCA DE LA OFICINA NO AVISA', 0, (int) valor('SELECT COUNT(*) FROM racha_avisos WHERE usuario_id = ?', [$R2]));
$venta($R2, 613, $S(100), $hoy . ' 11:00:00');
racha_tras_venta($R2);
es('superarla, sí', 1, (int) valor('SELECT COUNT(*) FROM racha_avisos WHERE usuario_id = ?', [$R2]));
/* R3 en otra oficina, x2: por debajo del umbral. */
$venta($R3, 710, $S(100), $hoy . ' 09:00:00'); $venta($R3, 711, $S(100), $hoy . ' 10:00:00');
racha_tras_venta($R3);
es('x2 no avisa (desde x3)', 0, (int) valor('SELECT COUNT(*) FROM racha_avisos WHERE usuario_id = ?', [$R3]));
/* UN RÉCORD PROPIO SIEMPRE AVISA: R3 tuvo un día de x2 antes; hoy x3 no es la marca de su oficina… */
$venta($R3, 712, $S(100), date('Y-m-d', strtotime('-5 days')) . ' 09:00:00');
$venta($R3, 713, $S(100), date('Y-m-d', strtotime('-5 days')) . ' 10:00:00');
insertar('racha_avisos', ['pais_id' => $PERU, 'oficina_id' => $OFI2, 'usuario_id' => $E, 'nivel' => 7, 'tipo' => 'dia', 'fecha' => $hoy, 'creado_en' => date('Y-m-d H:i:s')]);
$venta($R3, 714, $S(100), $hoy . ' 11:00:00');
$t = racha_tras_venta($R3);
ok('R3: x3 rompe su marca de x2 → récord', $t['record']);
es('y avisa como récord aunque otro vaya x7', 1, (int) valor("SELECT COUNT(*) FROM racha_avisos WHERE usuario_id = ? AND tipo = 'record'", [$R3]));
$yo_r1 = una('SELECT * FROM usuarios WHERE id = ?', [$R1]);
$av = racha_avisos_desde($yo_r1, 1);
ok('R1 ve los avisos de los demás, nunca el suyo', $av['avisos'] && !array_filter($av['avisos'], fn($a) => $a['nombre'] === 'Rita'));
ok('el récord se dice como récord', (bool) array_filter($av['avisos'], fn($a) => str_contains($a['texto'], 'rompió su récord')));
es('la primera vez (-1) solo toma la marca (no avisa lo viejo)', [], racha_avisos_desde($yo_r1, -1)['avisos']);
ok('CON LA PÁGINA ABIERTA SIN AVISOS (0), EL PRIMERO DEL DÍA SÍ SALE', (bool) racha_avisos_desde($yo_r1, 0)['avisos']);
$propio = insertar('racha_avisos', ['pais_id' => $PERU, 'oficina_id' => $OFI, 'usuario_id' => $R1, 'nivel' => 9, 'tipo' => 'dia', 'fecha' => $hoy, 'creado_en' => date('Y-m-d H:i:s')]);
es('SU PROPIA RACHA NO SE LE AVISA', [], racha_avisos_desde($yo_r1, $propio - 1)['avisos']);
q('DELETE FROM racha_avisos WHERE id = ?', [$propio]);
$yo_r1['avisos_rachas'] = 0;
es('quien los apagó para sí, no los ve', [], racha_avisos_desde($yo_r1, 1)['avisos']);
guardar_ajuste('racha_avisos', '0'); ajustes_olvidar();
$n_antes = (int) valor('SELECT COUNT(*) FROM racha_avisos');
$venta($R1, 504, $S(100), $hoy . ' 12:00:00'); $venta($R1, 505, $S(100), $hoy . ' 12:10:00');
racha_tras_venta($R1);
es('APAGADOS PARA TODOS: no se crean', $n_antes, (int) valor('SELECT COUNT(*) FROM racha_avisos'));
guardar_ajuste('racha_avisos', '1'); ajustes_olvidar();
/* LA MARCA DE LA OFICINA CUENTA LOS RÉCORDS (auditoría 5a). */
$OFI3 = insertar('oficinas', ['pais_id' => $PERU, 'nombre' => 'Trujillo']);
$X1 = banco_usuario('asesor', ['nombre' => 'Xime', 'oficina_id' => $OFI3]);
$X2 = banco_usuario('asesor', ['nombre' => 'Xavi', 'oficina_id' => $OFI3]);
insertar('racha_avisos', ['pais_id' => $PERU, 'oficina_id' => $OFI3, 'usuario_id' => $X1, 'nivel' => 5, 'tipo' => 'record', 'fecha' => $hoy, 'creado_en' => date('Y-m-d H:i:s')]);
foreach ([1, 2, 3] as $i) $venta($X2, 960 + $i, $S(100), $hoy . " 0$i:00:00");
racha_tras_venta($X2);
es('x3 no avisa si alguien de su oficina ya va x5 con récords', 0, (int) valor('SELECT COUNT(*) FROM racha_avisos WHERE usuario_id = ?', [$X2]));
/* Mirar si ayer se cortó no reescribe la racha de hoy. */
$antes_r = una("SELECT valor, ultima_fecha FROM rachas WHERE usuario_id = ? AND tipo = 'dias'", [$R2]);
racha_cortada_ayer($R2);
es('MIRAR LA RACHA DE AYER NO REESCRIBE LA DE HOY', $antes_r, una("SELECT valor, ultima_fecha FROM rachas WHERE usuario_id = ? AND tipo = 'dias'", [$R2]));
$en = en_racha_ahora($PERU);
es('«en racha ahora», el más encendido arriba', $R1, $en[0]['id']);
ok('solo desde x2', !array_filter($en, fn($x) => $x['n'] < 2));

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · la pila de novedades');
$ua = una('SELECT * FROM usuarios WHERE id = ?', [$A]);
$nov = novedades_de($ua, '2026-09-02');
$claves = array_column($nov, 'clave');
ok('lo cerrado de los últimos 7 días sale', in_array('res-' . (int)$resA2['id'], $claves, true));
$tA = array_values(array_filter($nov, fn($x) => $x['clave'] === 'res-' . (int)$resA2['id']))[0] ?? null;
ok('ganó: en verde, con cuánto y para compartir', $tA && $tA['tono'] === 'verde' && str_contains($tA['titulo'], 'Ganaste') && $tA['logro']);
ok('LA IMAGEN PARA COMPARTIR NO LLEVA SOLES EN EL TEXTO (van aparte y apagados)', $tA && !str_contains($tA['logro']['dato'], 'S/') && $tA['logro']['monto'] !== '');
foreach (todas('SELECT br.*, b.nombre AS bono_nombre, b.tipo, b.periodo FROM bono_resultados br JOIN bonos b ON b.id = br.bono_id WHERE br.ganado = 1') as $gx) {
    if (str_contains(bono_logro($gx, 'Ana')['dato'], 'S/')) { ok('ningún logro con soles en el texto: ' . $gx['tipo'], false); }
}
es('el logro de un puesto', 'Quedé 2.º de la semana', bono_logro(['tipo' => 'alfa_semana', 'puesto' => 2, 'periodo' => 'semana', 'periodo_inicio' => '2026-09-07', 'periodo_fin' => '2026-09-13', 'bono_nombre' => 'Alfa'], 'Ana')['dato']);
q('UPDATE bono_resultados SET cerrado_en = ? WHERE id = ?', ['2026-08-16 12:00:00', (int)$resA['id']]);
ok('lo de hace más de 7 días ya no', !in_array('res-' . (int)$resA['id'], array_column(novedades_de($ua, '2026-09-02'), 'clave'), true));
novedad_vista($A, 'res-' . (int)$resA2['id']);
ok('SE VE UNA SOLA VEZ', !in_array('res-' . (int)$resA2['id'], array_column(novedades_de($ua, '2026-09-02'), 'clave'), true));
es('marcar dos veces no duplica', false, novedad_vista($A, 'res-' . (int)$resA2['id']));
/* La Cacería de hoy: para todos (se lanzó arriba). */
$nov = novedades_de($ua);
$cac = array_values(array_filter($nov, fn($x) => !empty($x['caceria'])));
ok('LA CACERÍA DE HOY, AL FINAL DE LA PILA', $cac && end($nov)['clave'] === 'cac-' . date('Y-m-d'));
ok('en amarillo, con su escalera', $cac && $cac[0]['tono'] === 'amarillo' && str_contains(implode(' ', $cac[0]['lineas']), '3 → S/ 30'));
$ur3 = una('SELECT * FROM usuarios WHERE id = ?', [$R3]);
$rota = array_values(array_filter(novedades_de($ur3), fn($x) => str_starts_with($x['clave'], 'rota-')));
ok('la racha cortada ayer (con el día de hace 5, eran 4)', $rota && str_contains($rota[0]['titulo'], '4 días'));
/* Wakaciones: el destino es el del cierre. */
$ini_s = '2026-01-01';
insertar('bono_cierres', ['bono_id' => (int)$WK['id'], 'periodo_inicio' => $ini_s, 'periodo_fin' => '2026-06-30',
    'reglas' => json_encode(['destino' => 'Cancún', 'premio_texto' => 'Todo incluido']), 'cerrado_en' => '2026-07-01 12:00:00']);
$wid = insertar('bono_resultados', ['bono_id' => (int)$WK['id'], 'pais_id' => $PERU, 'usuario_id' => $A, 'equipo_id' => 0,
    'periodo_inicio' => $ini_s, 'periodo_fin' => '2026-06-30', 'valor' => $S(510000), 'ganado' => 1, 'premio_centimos' => 0,
    'puesto' => 1, 'detalle' => '{}', 'pagado' => 0, 'cerrado_en' => date('Y-m-d H:i:s')]);
$w = novedad_wakaciones($ua);
es('WAKACIONES: EL DESTINO DEL CIERRE, no el de hoy (Punta Cana)', 'Cancún', $w['destino'] ?? null);
ok('va aparte: no entra en la pila', !in_array('res-' . $wid, array_column(novedades_de($ua), 'clave'), true));
novedad_vista($A, 'wak-' . $wid);
es('y se ve una vez', null, novedad_wakaciones($ua));
ok('un premio 0 (el viaje) no sale en por pagar', !in_array($wid, array_map('intval', array_column(bonos_por_pagar($PERU), 'id')), true));

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · el modo team: de tu equipo ves a las personas; de los otros, el total');
$mes = '2026-10';
$t = modo_team($PERU, $mes, $EQ1, $A);
es('tres equipos', 3, count($t['equipos']));
es('Águilas primero (S/4,100)', ['Las Águilas', $S(4100)], [$t['equipos'][0]['nombre'], $t['equipos'][0]['cobrado']]);
$ids_personas = array_column($t['personas'], 'id');
ok('LAS PERSONAS SON SOLO DE MI EQUIPO', !array_diff($ids_personas, [$A, $B, $R1, $R2]) && in_array($A, $ids_personas, true) && !in_array($C, $ids_personas, true));
ok('de los otros equipos, ni nombres ni personas', !isset($t['equipos'][0]['personas']));
ok('me marca a mí', (bool) array_filter($t['personas'], fn($p) => $p['id'] === $A && $p['yo']));
es('mi equipo va 2.º', 2, $t['mio']['puesto']);
ok('el dato: cuánto les saca el primero', str_contains($t['dato'], 'Las Águilas va primero y les saca S/ 100'));
ok('la frase sale de las del medio', in_array($t['frase'], bono_frases('team_medio'), true));
ok('y cuántos pedidos para pasar al primero', str_contains($t['para_pasar'], 'pasan al primero'));
$t1 = modo_team($PERU, $mes, $EQ2, $C);
ok('el primero: frase de primero y cuánto le saca al 2.º', in_array($t1['frase'], bono_frases('team_primero'), true) && str_contains($t1['dato'], 'Le sacan S/ 100 a Los Lobos'));
$t0 = modo_team($PERU, $mes, null);
es('sin equipo pedido, sin personas', [], $t0['personas']);

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · la meta general vale desde el mes en que se pone');
$antes = meta_general($PERU, '2020-01');
es('poner la meta', true, meta_general_poner($PERU, $S(40000))['ok']);
es('este mes', $S(40000), meta_general($PERU, date('Y-m')));
es('LOS MESES QUE VIENEN SIGUEN CON ELLA', $S(40000), meta_general($PERU, date('Y-m', strtotime('+2 months'))));
es('un mes pasado sigue con la que tenía', $antes, meta_general($PERU, '2020-01'));
es('una meta imposible no', false, meta_general_poner($PERU, -5)['ok']);

/* ───────────────────────────────────────────────────────────────────── */
grupo('5a · frases y «Métele garra»');
guardar_ajuste('frases_caceria', "Una\n\nDos\n"); ajustes_olvidar();
es('las frases se editan (una por línea, sin vacías)', ['Una', 'Dos'], bono_frases('caceria'));
es('la del día no cambia en el día', bono_frase_del_dia('caceria', '2026-09-29'), bono_frase_del_dia('caceria', '2026-09-29'));
guardar_ajuste('frases_caceria', ''); ajustes_olvidar();
ok('vacío: las de fábrica', count(bono_frases('caceria')) === 4);
/* Garra: A con la Yapa encendida y un nivel; le falta para la cuota. */
q('UPDATE bonos SET reglas = ? WHERE id = ?', [json_encode($Y['reglas']), (int)$Y['id']]);
$R4 = banco_usuario('asesor', ['nombre' => 'Rosa']);
insertar('usuario_niveles', ['usuario_id' => $R4, 'desde' => '2000-01-01', 'nivel' => 1]);
$venta($R4, 800, $S(5000), date('Y-m-d H:i:s'), [[date('Y-m-d'), $S(5000)]]);
$u4 = una('SELECT * FROM usuarios WHERE id = ?', [$R4]);
$mis = mis_bonos($u4);
ok('Mis bonos: solo los encendidos', count($mis) === (int) valor('SELECT COUNT(*) FROM bonos WHERE pais_id = ? AND activo = 1', [$PERU]));
$g = metele_garra($u4, $mis);
es('Métele garra: le faltan S/15,000', $S(15000), $g['falta'] ?? null);
es('son 3 ventas de su ticket (S/5,000)', 3, $g['ventas'] ?? null);

exit(marcador());
