<?php
declare(strict_types=1);

/**
 * PRE VENTA Y LOTES (3h) — la regla entera.
 *
 * Decidido por el usuario (2026-09-27):
 *   · El CEO llena el lote en un FORMULARIO con campos claros; traerlo del
 *     Excel de ingresos de carga es un plus para lo pasado.
 *   · El asesor ve «Disponible para pre venta»: la lista ordenada con precios
 *     y stock en la misma pantalla.
 *   · STOCK DE PRE VENTA CON CORTE DURO (2026-09-09): no se vende más de lo
 *     que trae el lote. El stock es UNA cuenta: lo que trae la fila del lote
 *     MENOS lo vendido en pedidos vivos. Anular devuelve solo, sin contadores
 *     aparte que puedan descuadrarse.
 *   · El interruptor «disponible» se puede encender con productos sin precio:
 *     esos quedan ocultos para el asesor y el CEO lo ve avisado.
 *   · Al marcar el lote como LLEGÓ, sus pedidos pasan a «Producto llegó».
 */

final class PreventaAgotada extends DomainException {}

/**
 * El producto que SOLO es de pre venta: está en algún lote y no en la tienda.
 * Su precio es el del lote, así que no cuenta como «sin precio» en el catálogo.
 */
function sql_producto_solo_preventa(string $alias = 'p'): string
{
    if (!tabla_existe('lote_lineas') || !columna_existe('lote_lineas', 'modelo')) return '1 = 0';
    /* Solo mientras su lote viene en camino: cuando llega (o se cancela), lo
       que sobra se vende de la tienda y necesita su precio de tienda. */
    return "($alias.woo_id IS NULL AND EXISTS (SELECT 1 FROM lote_lineas llp JOIN lotes lp ON lp.id = llp.lote_id
                                             WHERE llp.producto_id = $alias.id AND lp.estado NOT IN ('recibido','cancelado')))";
}

/** ¿El HUB ya tiene lo de la 3h? (actualizar.php pendiente). */
function preventa_lista(): bool
{
    return tabla_existe('lotes') && columna_existe('lote_lineas', 'modelo')
        && columna_existe('pedido_lineas', 'lote_linea_id');
}

/* ─────────────────────────  LOS NOMBRES  ───────────────────────── */

/** Estado de la carga → texto. La columna es la de siempre (módulo 2). */
function lote_estados(): array
{
    return [
        'borrador'  => 'Por confirmar',
        'en_camino' => 'En camino',
        'en_aduana' => 'En aduana',
        'recibido'  => 'Llegó',
        'cancelado' => 'Cancelado',
    ];
}

/** El canal de aduana. '' = todavía no se sabe. */
function lote_canales(): array
{
    return ['' => 'Por confirmar', 'verde' => 'Verde', 'amarillo' => 'Amarillo', 'rojo' => 'Rojo'];
}

/* ─────────────────────────  LEER  ───────────────────────── */

/** Un lote de MI país (o de cualquiera si cruzo países). null si no. */
function lote_de(int $id): ?array
{
    if (!tabla_existe('lotes') || $id <= 0) return null;
    $l = una('SELECT * FROM lotes WHERE id = ?', [$id]);
    if (!$l) return null;
    $u = yo();
    if ($u && !cruza_paises($u) && (int)$l['pais_id'] !== (int)$u['pais_id']) return null;
    return $l;
}

/** Los lotes de mi país, el que llega antes arriba; los llegados y cancelados al final. */
function lotes_lista(?array $u = null): array
{
    if (!tabla_existe('lotes')) return [];
    $u ??= yo();
    $par = [];
    $donde = '1 = 1';
    if ($u && !cruza_paises($u)) { $donde = 'l.pais_id = ?'; $par[] = (int)$u['pais_id']; }
    return todas("SELECT l.* FROM lotes l WHERE $donde
                   ORDER BY CASE l.estado WHEN 'recibido' THEN 2 WHEN 'cancelado' THEN 3 ELSE 1 END,
                            CASE WHEN l.fecha_llegada_est IS NULL THEN 1 ELSE 0 END,
                            l.fecha_llegada_est ASC, l.id DESC", $par);
}

/** Lo vendido de una fila del lote, en SQL: pedidos vivos. Una sola definición. */
function sql_lote_vendidas(string $col_linea): string
{
    return '(SELECT COALESCE(SUM(pl.cantidad), 0) ' . sql_lote_vendidas_de($col_linea) . ')';
}

/** El FROM/WHERE de lo vendido de una fila: la misma cuenta para leer y para bloquear. */
function sql_lote_vendidas_de(string $col_linea): string
{
    return "FROM pedido_lineas pl JOIN pedidos pe ON pe.id = pl.pedido_id
             WHERE pl.lote_linea_id = $col_linea AND pe.anulado_en IS NULL";
}

/**
 * Lo vendido de una fila, leído DENTRO de una transacción con bloqueo: en
 * MariaDB una lectura normal ve la foto del principio de la transacción y no
 * las ventas que otro acaba de confirmar; la lectura con bloqueo ve lo último
 * (auditoría del 3h).
 */
function lote_vendidas_ya(int $lote_linea_id): int
{
    $bloqueo = bd_es_sqlite() ? '' : ' LOCK IN SHARE MODE';
    return (int) valor('SELECT COALESCE(SUM(pl.cantidad), 0) ' . sql_lote_vendidas_de('?') . $bloqueo, [$lote_linea_id]);
}

/** Las filas de un lote, con su producto, lo vendido y lo que queda. */
function lote_filas(int $lote_id): array
{
    if (!preventa_lista()) return [];
    $rep = function_exists('repuestos_listo') && repuestos_listo();
    $filas = todas('SELECT ll.*, p.nombre AS producto_nombre, p.sku AS producto_sku, p.woo_id AS producto_woo,
                           ' . ($rep ? 'pm.nombre AS maquina_nombre, p.padre_id AS producto_padre, ' : '') . sql_lote_vendidas('ll.id') . ' AS vendidas
                      FROM lote_lineas ll LEFT JOIN productos p ON p.id = ll.producto_id
                      ' . ($rep ? 'LEFT JOIN productos pm ON pm.id = p.padre_id' : '') . '
                     WHERE ll.lote_id = ?
                     ORDER BY ll.orden, ll.id', [$lote_id]);
    foreach ($filas as &$f) {
        $f['vendidas'] = (int)$f['vendidas'];
        $f['quedan'] = max(0, (int)$f['unidades'] - $f['vendidas']);
        $f['maquina'] = (string)($f['maquina'] ?? '');
        /* Es de repuestos si la fila lo dice o si su producto ya es un
           repuesto: una sola definición para el lote (3i). */
        $f['es_repuesto'] = $f['maquina'] !== '' || !empty($f['producto_padre']);
    }
    unset($f);
    return $filas;
}

/** Los productos del lote (uno por producto), con sus filas y sus tramos. */
function lote_productos(int $lote_id): array
{
    $out = [];
    foreach (lote_filas($lote_id) as $f) {
        $pid = (int)$f['producto_id'];
        /* Los repuestos no se venden en pre venta (3i): no llevan precio de
           lote ni cuentan como «sin precio». Se venden cuando llegan. */
        if (!$pid || $f['es_repuesto']) continue;
        $out[$pid] ??= ['id' => $pid, 'nombre' => (string)$f['producto_nombre'], 'sku' => (string)$f['producto_sku'],
                        'nuevo' => false, 'filas' => [], 'unidades' => 0, 'quedan' => 0,
                        'tramos' => producto_tramos($pid, null, $lote_id)];
        $out[$pid]['filas'][] = $f;
        $out[$pid]['unidades'] += (int)$f['unidades'];
        $out[$pid]['quedan'] += (int)$f['quedan'];
        if (!empty($f['nuevo'])) $out[$pid]['nuevo'] = true;
    }
    foreach ($out as &$p) $p['completo'] = (bool)$p['tramos'];
    unset($p);
    return array_values($out);
}

/** Cuántos productos del lote tienen precio y cuántos no (el aviso del interruptor). */
function lote_completitud(int $lote_id): array
{
    $con = $sin = 0;
    foreach (lote_productos($lote_id) as $p) $p['completo'] ? $con++ : $sin++;
    return ['con' => $con, 'sin' => $sin];
}

/**
 * EL PRECIO DE PRE VENTA de un producto en un lote, para esa cantidad.
 * La cuenta es precio_segun_tramos(), la misma de la tienda.
 */
function precio_de_lote(int $producto_id, int $lote_id, int $cantidad): ?int
{
    return precio_segun_tramos(producto_tramos($producto_id, null, $lote_id), $cantidad);
}

/* ─────────────────────────  LA BARRA DEL BARCO  ───────────────────────── */

/**
 * Cuánto lleva el viaje: % y el texto de debajo. Es una ESTIMACIÓN con las
 * fechas; el estado de la carga es la verdad (lo marca el CEO).
 * → ['pct' => 0..100, 'texto' => '…', 'tono' => 'gris|amarillo|mostaza|verde|rojo']
 */
function lote_travesia(array $l, ?string $hoy = null): array
{
    $hoy ??= date('Y-m-d');
    $sal = (string)($l['fecha_salida'] ?? '');
    $lle = (string)($l['fecha_llegada_est'] ?? '');
    $est = (string)$l['estado'];
    $f = fn(string $d) => date('d/m', strtotime($d));
    if ($est === 'cancelado') return ['pct' => 0, 'texto' => 'Cancelado', 'tono' => 'gris'];
    if ($est === 'recibido') {
        $real = (string)($l['fecha_llegada_real'] ?? '');
        return ['pct' => 100, 'texto' => 'Llegó' . ($real !== '' ? ' el ' . $f($real) : ''), 'tono' => 'verde'];
    }
    if ($est === 'en_aduana') {
        return ['pct' => 100, 'texto' => 'En el puerto, esperando la aduana', 'tono' => 'amarillo'];
    }
    if ($sal === '' || $sal > $hoy) {
        return ['pct' => 0, 'texto' => $sal !== '' ? 'Sin zarpar · sale el ' . $f($sal) : 'Sin fecha de salida', 'tono' => 'gris'];
    }
    if ($lle === '') return ['pct' => 10, 'texto' => 'En camino · falta la fecha de llegada', 'tono' => 'amarillo'];
    $total = max(1, (int) round((strtotime($lle) - strtotime($sal)) / 86400));
    $van   = max(0, (int) round((strtotime($hoy) - strtotime($sal)) / 86400));
    if ($hoy > $lle) {
        $tarde = (int) round((strtotime($hoy) - strtotime($lle)) / 86400);
        return ['pct' => 92, 'texto' => 'Debió llegar el ' . $f($lle) . ' · ' . ($tarde === 1 ? 'un día' : $tarde . ' días') . ' de retraso',
                'tono' => 'mostaza'];
    }
    $faltan = $total - $van;
    return ['pct' => min(96, (int) round($van * 100 / $total)),
            'texto' => 'Día ' . $van . ' de ' . $total . ' · ' . ($faltan === 0 ? 'llega hoy' : ($faltan === 1 ? 'falta un día' : 'faltan ' . $faltan . ' días')),
            'tono' => 'amarillo'];
}

/* ─────────────────────────  ESCRIBIR  ───────────────────────── */

/** Una fecha «dd/mm/aaaa», «aaaa-mm-dd» o vacía → 'aaaa-mm-dd' o null. false si no se entiende. */
function lote_fecha(string $t): string|null|false
{
    $t = trim($t);
    if ($t === '' || stripos($t, 'confirmar') !== false) return null;
    if (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $t, $m)) return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $t : false;
    if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{2,4})$~', $t, $m)) {
        $a = (int)$m[3]; if ($a < 100) $a += 2000;
        return checkdate((int)$m[2], (int)$m[1], $a) ? sprintf('%04d-%02d-%02d', $a, $m[2], $m[1]) : false;
    }
    return false;
}

/**
 * Guarda el VIAJE de un lote (bloque 1). $id null = lote nuevo, en «Por
 * confirmar» y apagado. → ['ok', 'error', 'id']
 */
function lote_guardar(?int $id, array $d): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e, 'id' => $id ?? 0];
    if (!preventa_lista()) return $mal('Falta terminar la actualización.');
    $u = yo();
    if ($id && !lote_de($id)) return $mal('Ese lote no existe.');

    $nombre = trim(preg_replace('/\s+/', ' ', (string)($d['nombre'] ?? '')) ?? '');
    if (mb_strlen($nombre) < 2) return $mal('Ponle un nombre al lote (por ejemplo «Contenedor 2 · 40HQ»).');
    $fechas = [];
    foreach (['fecha_salida' => 'la fecha de salida', 'fecha_llegada_est' => 'la llegada estimada', 'fecha_almacen' => 'la fecha de almacén'] as $k => $txt) {
        $v = lote_fecha((string)($d[$k] ?? ''));
        if ($v === false) return $mal('No se entiende ' . $txt . '. Escríbela así: 26/09/2026.');
        $fechas[$k] = $v;
    }
    if ($fechas['fecha_salida'] && $fechas['fecha_llegada_est'] && $fechas['fecha_llegada_est'] < $fechas['fecha_salida']) {
        return $mal('La llegada no puede ser antes de la salida.');
    }
    $canal = (string)($d['canal'] ?? '');
    if (!array_key_exists($canal, lote_canales())) $canal = '';
    $pais = $id ? (int) valor('SELECT pais_id FROM lotes WHERE id = ?', [$id]) : (int)$u['pais_id'];
    $agencia = (int)($d['agencia_item_id'] ?? 0);
    if ($agencia && !lista_valida('agencias_carga', $agencia, $pais)) return $mal('Elige la agencia de carga de la lista.');
    $resp = (int)($d['responsable_id'] ?? 0);
    if ($resp && !valor('SELECT id FROM usuarios WHERE id = ? AND pais_id = ? AND activo = 1', [$resp, $pais])) {
        return $mal('Elige el responsable de la lista.');
    }
    $txt = fn(string $k, int $n) => ($v = mb_substr(trim((string)($d[$k] ?? '')), 0, $n)) === '' || stripos($v, 'por confirmar') !== false ? null : $v;
    $datos = [
        'nombre' => mb_substr($nombre, 0, 140),
        'agencia_item_id' => $agencia ?: null,
        'agencia_ref' => $txt('agencia_ref', 120), 'bl' => $txt('bl', 80), 'factura' => $txt('factura', 80),
        'canal' => $canal, 'almacen' => $txt('almacen', 120), 'notas' => $txt('notas', 500),
        'responsable_id' => $resp ?: null,
        'puerto_origen' => $txt('puerto_origen', 60), 'puerto_destino' => $txt('puerto_destino', 60),
    ] + $fechas;

    if ($id) {
        actualizar('lotes', $id, $datos);
        bitacora('lote.editar', 'lote', $id, ['nombre' => $datos['nombre']]);
        return ['ok' => true, 'error' => '', 'id' => $id];
    }
    $nuevo = insertar('lotes', $datos + ['pais_id' => $pais, 'codigo' => 'tmp-' . bin2hex(random_bytes(5)),
                                         'estado' => 'borrador', 'disponible' => 0, 'creado_por' => (int)$u['id']]);
    actualizar('lotes', $nuevo, ['codigo' => 'LT-' . str_pad((string)$nuevo, 5, '0', STR_PAD_LEFT)]);
    bitacora('lote.crear', 'lote', $nuevo, ['nombre' => $datos['nombre']]);
    return ['ok' => true, 'error' => '', 'id' => $nuevo];
}

/**
 * LOS PRODUCTOS DEL LOTE (bloque 2), la tabla entera de una vez.
 * Cada fila: ['id'?, 'codigo', 'nombre', 'modelo', 'unidades', 'nuevo', 'revisado'?].
 *   · El producto se busca por su CÓDIGO, entre los del país del lote: si ya
 *     existe, se enlaza; si no, se crea. Un código de otro país no vale.
 *   · En «Modelo / color» se puede escribir «12 moradas, 15 naranjas»: si los
 *     números suman las unidades, la fila se parte en una por color
 *     ($partir = false para lo que ya viene partido, como el Excel).
 *   · Una fila con ventas no se borra, no baja de lo vendido y no cambia de
 *     producto ni de color. Se comprueba DENTRO de la transacción, con las
 *     filas bloqueadas: una venta que entra mientras se guarda no se pierde.
 *   · Una fila marcada «Revisa» sigue así hasta que alguien la cambia o marca
 *     «Ya lo revisé»; mientras tanto el asesor no la ve.
 * → ['ok', 'error', 'avisos']
 */
function lote_filas_guardar(int $lote_id, array $filas, bool $partir = true): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e, 'avisos' => []];
    $l = lote_de($lote_id);
    if (!$l) return $mal('Ese lote no existe.');
    $antes = [];
    foreach (lote_filas($lote_id) as $f) $antes[(int)$f['id']] = $f;

    $limpias = [];
    $n = 0;
    foreach ($filas as $f) {
        $codigo = mb_strtoupper(trim((string)($f['codigo'] ?? '')));
        $nombre = trim(preg_replace('/\s+/', ' ', (string)($f['nombre'] ?? '')) ?? '');
        $modelo = trim((string)($f['modelo'] ?? ''));
        $und_t  = trim((string)($f['unidades'] ?? ''));
        $fid    = (int)($f['id'] ?? 0);
        /* REPUESTO (3i): la fila lleva el código de SU MÁQUINA. */
        $maquina = mb_strtoupper(trim((string)($f['maquina'] ?? '')));
        if ($codigo === '' && $nombre === '' && $modelo === '' && ($und_t === '' || $und_t === '0')) continue;   // vacía = se quita
        /* «Fila N» cuenta solo las escritas (3h.1): al volver a pintar tras un
           error, las filas en blanco ya no están y la N tiene que seguir
           señalando la misma fila. */
        $n++;
        if ($nombre === '' && $codigo === '') return $mal("Fila $n: falta el producto.");
        if (!preg_match('/^\d+$/', $und_t) || (int)$und_t <= 0 || (int)$und_t > 1000000) return $mal("Fila $n: las unidades son un número de 1 para arriba.");
        if ($codigo !== '' && !sku_valido($codigo)) return $mal("Fila $n: el código «{$codigo}» no vale (letras, números y guiones).");
        if ($fid && !isset($antes[$fid])) return $mal("Fila $n: esa fila ya no está en el lote. Vuelve a abrirlo.");
        if ($maquina !== '' && !sku_valido($maquina)) return $mal("Fila $n: el código de la máquina «{$maquina}» no vale.");
        if ($maquina !== '' && $codigo === $maquina) return $mal("Fila $n: ese código es el de la máquina. Deja vacío el código del repuesto: se genera solo.");
        $partes = [['modelo' => $modelo, 'unidades' => (int)$und_t]];
        /* UN KIT EN UNA FILA NUEVA (3i): «2 botones, 2 joysticks» → una fila
           por pieza, cada una su producto. */
        if ($partir && !$fid && $maquina !== '' && $modelo !== '' && function_exists('repuestos_listo') && repuestos_listo()) {
            $kit = repuesto_partir_kit($modelo, (int)$und_t);
            if (count($kit) > 1) {
                /* EL HUB PROPONE Y EL CEO CONFIRMA (tarjeta del 2026-09-28): las
                   piezas nacen «Revisa», también aquí, y hasta que se confirmen
                   no entran al almacén. */
                foreach ($kit as $pz) {
                    $limpias[] = ['id' => 0, 'codigo' => '', 'nombre' => $pz['nombre'], 'modelo' => '', 'unidades' => (int)$pz['unidades'],
                                  'nuevo' => !empty($f['nuevo']) ? 1 : 0, 'texto' => $modelo, 'revisado' => false, 'maquina' => $maquina,
                                  'revisa' => 'Pieza de un kit: confirma sus unidades'];
                }
                continue;
            }
        }
        /* «12 moradas, 15 naranjas» en una fila nueva: una fila por color. */
        if ($partir && !$fid && $modelo !== '' && $maquina === '') {
            $pc = preventa_partir_colores($modelo, (int)$und_t);
            if ($pc['estado'] === 'unica') $partes = [['modelo' => '', 'unidades' => (int)$und_t]];
            elseif ($pc['estado'] === 'listo') $partes = $pc['partes'];
        }
        foreach ($partes as $k => $pa) {
            $limpias[] = ['id' => $k === 0 ? $fid : 0, 'codigo' => $codigo, 'nombre' => $nombre,
                          'modelo' => mb_substr(trim((string)$pa['modelo']), 0, 120), 'unidades' => (int)$pa['unidades'],
                          'nuevo' => !empty($f['nuevo']) ? 1 : 0, 'texto' => $modelo, 'revisado' => !empty($f['revisado']), 'maquina' => $maquina];
        }
    }

    $avisos = [];
    try {
    en_transaccion(function () use ($lote_id, $l, $limpias, $antes, &$avisos) {
        /* Las filas del lote, bloqueadas, y lo vendido leído con bloqueo. */
        if (!bd_es_sqlite()) todas('SELECT id FROM lote_lineas WHERE lote_id = ? ORDER BY id FOR UPDATE', [$lote_id]);
        $vend = [];
        foreach ($antes as $aid => $a) $vend[$aid] = lote_vendidas_ya($aid);
        $quedan_ids = array_filter(array_map(fn($x) => (int)$x['id'], $limpias));
        foreach ($antes as $aid => $a) {
            if (!in_array($aid, $quedan_ids, true) && $vend[$aid] > 0) {
                throw new DomainException('«' . $a['producto_nombre'] . ($a['modelo'] !== '' ? ' · ' . $a['modelo'] : '') . '» ya tiene '
                          . $vend[$aid] . ' vendidas: no se puede quitar del lote.');
            }
        }
        $orden = 0;
        $vistos = [];
        $pais = (int)$l['pais_id'];
        /* Filas sin código con el MISMO nombre son el mismo producto (un color
           por fila): se busca entre los del lote y los creados en esta pasada. */
        $por_nombre = [];
        /* Un «Joystick» de la máquina A no es el de la B: la clave lleva la máquina. */
        $clave_nombre = fn(string $nom, string $maq) => ($maq !== '' ? repuesto_clave_nombre($nom) : lista_texto_llano($nom)) . '|' . $maq;
        foreach ($antes as $a) $por_nombre[$clave_nombre((string)$a['producto_nombre'], (string)($a['maquina'] ?? ''))] ??= (int)$a['producto_id'];
        $con_repuestos = repuestos_listo();
        foreach ($limpias as $f) {
            $orden += 10;
            $pid = 0;
            if ($f['codigo'] !== '') {
                $pr = una('SELECT id, pais_id, woo_id, nombre FROM productos WHERE sku = ?', [$f['codigo']]);
                if ($pr && $pr['pais_id'] !== null && (int)$pr['pais_id'] !== $pais) {
                    throw new DomainException('El código ' . $f['codigo'] . ' es de un producto de otro país.');
                }
                if ($pr) $pid = (int)$pr['id'];
            }
            if (!$pid && $f['id'] && isset($antes[$f['id']]) && $f['codigo'] === '') $pid = (int)$antes[$f['id']]['producto_id'];
            /* CORREGIR EL NOMBRE en una fila que ya estaba (el formulario enseña
               el nombre del producto): se cambia en el producto, salvo si es de
               la web, cuyo nombre lo manda la web. */
            if ($pid && $f['id'] && isset($antes[$f['id']]) && (int)$antes[$f['id']]['producto_id'] === $pid && $f['nombre'] !== ''
                && $f['nombre'] !== (string)$antes[$f['id']]['producto_nombre'] && $antes[$f['id']]['producto_woo'] === null) {
                actualizar('productos', $pid, ['nombre' => mb_substr($f['nombre'], 0, 180)]);
                bitacora('catalogo.editar', 'producto', $pid, ['nombre' => $f['nombre']]);
            }
            if (!$pid && $f['codigo'] === '' && $f['nombre'] !== '') $pid = (int)($por_nombre[$clave_nombre($f['nombre'], $f['maquina'])] ?? 0);
            /* LA MÁQUINA DEL REPUESTO (3i): si está en el catálogo, el repuesto
               cuelga de ella; un repuesto sin código que ya existe bajo esa
               máquina (mismo nombre) es el mismo. Si la máquina no está, la
               fila se queda «Revisa» hasta que se ponga bien el código. */
            $maq = null;
            if ($f['maquina'] !== '' && $con_repuestos) {
                $maq = maquina_por_codigo($f['maquina'], $pais);
                if ($maq && !$pid && $f['codigo'] === '' && $f['nombre'] !== '') {
                    foreach (todas('SELECT id, nombre FROM productos WHERE padre_id = ?', [(int)$maq['id']]) as $hermano) {
                        if (repuesto_clave_nombre((string)$hermano['nombre']) === repuesto_clave_nombre($f['nombre'])) { $pid = (int)$hermano['id']; break; }
                    }
                }
            }
            $creado = false;
            if (!$pid) {
                $creado = true;
                $r = producto_guardar(null, ['nombre' => $f['nombre'] !== '' ? $f['nombre'] : $f['codigo'], 'sku' => $f['codigo'], 'activo' => 1]);
                if (!$r['ok']) throw new DomainException($r['error']);
                $pid = (int)$r['id'];
                if (columna_existe('productos', 'pais_id')) actualizar('productos', $pid, ['pais_id' => $pais]);
                $avisos[] = 'Producto nuevo: ' . ($f['nombre'] !== '' ? $f['nombre'] : $f['codigo']) . '.';
            }
            if ($f['nombre'] !== '') $por_nombre[$clave_nombre($f['nombre'], $f['maquina'])] ??= $pid;
            $fila = ['lote_id' => $lote_id, 'producto_id' => $pid, 'modelo' => $f['modelo'], 'unidades' => $f['unidades'],
                     'codigo' => $f['codigo'] ?: null, 'nuevo' => $f['nuevo'], 'orden' => $orden];
            $aviso_rep = '';
            if ($con_repuestos) {
                $fila['maquina'] = $f['maquina'] !== '' ? mb_substr($f['maquina'], 0, 60) : null;
                if ($maq) {
                    $ya = una('SELECT padre_id FROM productos WHERE id = ?', [$pid]);
                    /* Un producto que YA ESTABA en el catálogo y no es repuesto
                       de esta máquina no se cuelga desde el lote (un código mal
                       escrito convertiría una máquina en repuesto de otra): se
                       cuelga a propósito desde su ficha. Uno nuevo, sí. */
                    if ($creado || (int)($ya['padre_id'] ?? 0) === (int)$maq['id']) {
                        if (!$ya || empty($ya['padre_id'])) {
                            $rc = repuesto_colgar($pid, (int)$maq['id']);
                            if (!$rc['ok']) throw new DomainException('«' . $f['nombre'] . '»: ' . $rc['error']);
                        }
                    } else {
                        $aviso_rep = '«' . ($f['nombre'] !== '' ? $f['nombre'] : $f['codigo']) . '» ya está en el catálogo: cuélgalo de su máquina desde su ficha';
                    }
                }
            }
            $clave = $pid . '|' . variante_clave($f['modelo'], '') . '|' . $f['maquina'];
            if (isset($vistos[$clave])) throw new DomainException('«' . ($f['nombre'] ?: $f['codigo']) . ($f['modelo'] !== '' ? ' · ' . $f['modelo'] : '')
                                                                 . '» está dos veces en el lote. Junta las dos filas en una.');
            $vistos[$clave] = true;
            if ($f['id']) {
                $a = $antes[$f['id']];
                $v = $vend[$f['id']] ?? 0;
                if ($f['unidades'] < $v) {
                    throw new DomainException('«' . $a['producto_nombre'] . '» ya tiene ' . $v . ' vendidas: no puede quedar con menos.');
                }
                if ($v > 0 && ((int)$a['producto_id'] !== $pid || (string)$a['modelo'] !== $f['modelo'])) {
                    throw new DomainException('«' . $a['producto_nombre'] . '» ya tiene ventas: no se cambia de producto ni de color. Cambia solo las unidades.');
                }
                /* «Revisa» sigue hasta que la fila cambie o alguien diga que ya la revisó. */
                $cambio = (int)$a['producto_id'] !== $pid || (string)$a['modelo'] !== $f['modelo'] || (int)$a['unidades'] !== $f['unidades'];
                $cambio = $cambio || (string)($a['maquina'] ?? '') !== $f['maquina'];
                if ((int)$a['resuelto'] === 0 && ($cambio || $f['revisado'])) { $fila['resuelto'] = 1; $fila['aviso'] = null; }
                /* Una máquina que no está en el catálogo no se da por revisada. */
                if ($f['maquina'] !== '' && $con_repuestos && !$maq) { $fila['resuelto'] = 0; $fila['aviso'] = 'No encuentro la máquina ' . $f['maquina'] . ': revisa su código'; }
                if ($aviso_rep !== '') { $fila['resuelto'] = 0; $fila['aviso'] = $aviso_rep; }
                actualizar('lote_lineas', $f['id'], $fila);
            } else {
                $fila['texto_origen'] = mb_substr($f['texto'], 0, 300) ?: null;
                $fila['resuelto'] = 1;
                if (!empty($f['revisa'])) { $fila['resuelto'] = 0; $fila['aviso'] = mb_substr((string)$f['revisa'], 0, 200); }
                if ($f['maquina'] !== '' && $con_repuestos && !$maq) { $fila['resuelto'] = 0; $fila['aviso'] = 'No encuentro la máquina ' . $f['maquina'] . ': revisa su código'; }
                if ($aviso_rep !== '') { $fila['resuelto'] = 0; $fila['aviso'] = $aviso_rep; }
                insertar('lote_lineas', $fila);
            }
        }
        $ids_nuevos = array_filter(array_map(fn($x) => (int)$x['id'], $limpias));
        foreach ($antes as $aid => $a) {
            if (!in_array($aid, $ids_nuevos, true)) q('DELETE FROM lote_lineas WHERE id = ? AND lote_id = ?', [$aid, $lote_id]);
        }
        actualizar('lotes', $lote_id, ['unidades_totales' => (int) valor('SELECT COALESCE(SUM(unidades),0) FROM lote_lineas WHERE lote_id = ?', [$lote_id]),
                                       'productos_leidos' => (int) valor('SELECT COUNT(DISTINCT producto_id) FROM lote_lineas WHERE lote_id = ?', [$lote_id]),
                                       'filas_con_aviso' => (int) valor('SELECT COUNT(*) FROM lote_lineas WHERE lote_id = ? AND resuelto = 0', [$lote_id])]);
    });
    } catch (DomainException $ex) {
        return $mal($ex->getMessage());
    }
    bitacora('lote.productos', 'lote', $lote_id, ['filas' => count($limpias)]);
    /* En un lote que YA LLEGÓ, lo corregido (una fila nueva, una máquina bien
       escrita, una fila revisada, otras unidades) se refleja en el almacén (3i). */
    if ((string) valor('SELECT estado FROM lotes WHERE id = ?', [$lote_id]) === 'recibido' && function_exists('stock_hub_lote_al_dia')) {
        $mov = en_transaccion(function () use ($lote_id) { bloquear_fila('lotes', $lote_id); return stock_hub_lote_al_dia($lote_id); });
        if ($mov !== 0) $avisos[] = 'El almacén quedó al día con este lote (' . ($mov > 0 ? '+' : '') . $mov . ').';
    }
    return ['ok' => true, 'error' => '', 'avisos' => $avisos];
}

/** Enciende o apaga la venta del lote. → ['ok', 'error', 'aviso'] */
function lote_interruptor(int $lote_id, bool $encender): array
{
    $l = lote_de($lote_id);
    if (!$l) return ['ok' => false, 'error' => 'Ese lote no existe.', 'aviso' => ''];
    if ($encender && in_array((string)$l['estado'], ['cancelado', 'recibido'], true)) {
        return ['ok' => false, 'error' => $l['estado'] === 'recibido' ? 'Este lote ya llegó: lo que sobra se vende de la tienda.'
                                                                          : 'Un lote cancelado no se pone a la venta.', 'aviso' => ''];
    }
    if ($encender && !lote_filas($lote_id)) return ['ok' => false, 'error' => 'El lote no tiene productos todavía.', 'aviso' => ''];
    actualizar('lotes', $lote_id, ['disponible' => $encender ? 1 : 0]);
    bitacora($encender ? 'lote.encender' : 'lote.apagar', 'lote', $lote_id, []);
    /* 5b: «Nueva pre venta disponible», a todos los que venden (una vez por
       hora aunque se apague y se vuelva a encender). */
    $avisados = 0;
    if ($encender && (int)$l['disponible'] !== 1 && function_exists('notif_preventa_nueva')) {
        $avisados = notif_preventa_nueva($l) > 0 ? 1 : 0;
    }
    $c = lote_completitud($lote_id);
    $rev = (int) valor('SELECT COUNT(*) FROM lote_lineas WHERE lote_id = ? AND resuelto = 0', [$lote_id]);
    $partes = [];
    if ($c['sin'] > 0) $partes[] = plural($c['sin'], 'producto sin precio', 'productos sin precio');
    if ($rev > 0) $partes[] = plural($rev, 'fila por revisar', 'filas por revisar');
    $aviso = $encender && $partes ? 'Tienes ' . implode(' y ', $partes) . '. Hasta que lo arregles, eso no lo ven los asesores.' : '';
    return ['ok' => true, 'error' => '', 'aviso' => $aviso, 'avisados' => $avisados];
}

/**
 * Cambia el estado de la carga. Al pasar a LLEGÓ, los pedidos de este lote
 * pasan a «Producto llegó» (los que puedan: uno pagado o entregado no vuelve
 * atrás). → ['ok', 'error', 'pedidos' => cuántos avanzaron]
 */
function lote_estado_cambiar(int $lote_id, string $estado): array
{
    $l = lote_de($lote_id);
    if (!$l) return ['ok' => false, 'error' => 'Ese lote no existe.', 'pedidos' => 0];
    if (!array_key_exists($estado, lote_estados())) return ['ok' => false, 'error' => 'Ese estado no existe.', 'pedidos' => 0];
    if ($estado === (string)$l['estado']) return ['ok' => true, 'error' => '', 'pedidos' => 0];
    /* «Llegó» es el final: sus pedidos ya pasaron a «Producto llegó». */
    if ((string)$l['estado'] === 'recibido') return ['ok' => false, 'error' => 'Este lote ya llegó: su estado ya no cambia.', 'pedidos' => 0];
    /* Cancelar con ventas no: se cuenta con las filas bloqueadas, para que una
       venta que entra en ese momento no quede colgando de un lote cancelado. */
    if ($estado === 'cancelado') {
        $con_ventas = en_transaccion(function () use ($lote_id) {
            if (!bd_es_sqlite()) todas('SELECT id FROM lote_lineas WHERE lote_id = ? ORDER BY id FOR UPDATE', [$lote_id]);
            foreach (todas('SELECT id FROM lote_lineas WHERE lote_id = ?', [$lote_id]) as $fx) {
                if (lote_vendidas_ya((int)$fx['id']) > 0) return true;
            }
            q("UPDATE lotes SET estado = 'cancelado', disponible = 0 WHERE id = ?", [$lote_id]);
            return false;
        });
        if ($con_ventas) return ['ok' => false, 'error' => 'Este lote tiene ventas: antes de cancelarlo hay que anularlas o pasarlas a otro lote.', 'pedidos' => 0];
    }
    $cambio = ['estado' => $estado];
    if ($estado === 'recibido') $cambio['fecha_llegada_real'] = date('Y-m-d');
    /* Llegado o cancelado, deja de venderse como pre venta: lo que sobre de un
       lote llegado se vende de la tienda. */
    if (in_array($estado, ['recibido', 'cancelado'], true)) $cambio['disponible'] = 0;
    $rep_n = 0;
    if ($estado === 'recibido') {
        /* «LLEGÓ» CON CANDADO (3i): el lote bloqueado y su estado mirado otra
           vez. Dos clics a la vez no meten los repuestos dos veces; y el
           cambio de estado y la entrada al almacén van juntos: o todo, o nada. */
        $rep_n = en_transaccion(function () use ($lote_id, $cambio) {
            bloquear_fila('lotes', $lote_id);
            if ((string) valor('SELECT estado FROM lotes WHERE id = ?', [$lote_id]) === 'recibido') return -1;
            actualizar('lotes', $lote_id, $cambio);
            return function_exists('stock_hub_lote_al_dia') ? stock_hub_lote_al_dia($lote_id) : 0;
        });
        if ($rep_n === -1) return ['ok' => false, 'error' => 'Este lote ya llegó: su estado ya no cambia.', 'pedidos' => 0];
    } else {
        actualizar('lotes', $lote_id, $cambio);
    }
    bitacora('lote.estado', 'lote', $lote_id, ['de' => $l['estado'], 'a' => $estado]);
    /* Sus pedidos toman el estado que les toca (3j): «En camino» cuando el
       lote viaja, «Producto llegó» al llegar. Lo mueve el HUB, no una persona. */
    $n = lote_pedidos_al_dia($lote_id, $estado === 'recibido' ? 'Llegó el lote ' . $l['nombre'] : '');
    return ['ok' => true, 'error' => '', 'pedidos' => $n, 'repuestos' => $rep_n];
}

/** Los pedidos vivos de un lote, con su estado puesto al día. → cuántos cambiaron. */
function lote_pedidos_al_dia(int $lote_id, string $nota = ''): int
{
    $n = 0;
    $peds = todas('SELECT DISTINCT pe.id FROM pedidos pe JOIN pedido_lineas pl ON pl.pedido_id = pe.id
                    JOIN lote_lineas ll ON ll.id = pl.lote_linea_id
                   WHERE ll.lote_id = ? AND pe.anulado_en IS NULL', [$lote_id]);
    foreach ($peds as $pp) {
        if (pedido_estado_auto((int)$pp['id'], $nota) !== '') $n++;
    }
    return $n;
}

/**
 * «LISTO PARA ENTREGA» (3j). Lo pidió el usuario el 2026-09-28: una pre venta
 * no sale a despacho hasta que se confirma la descarga del contenedor y está
 * disponible para reparto. El CEO lo marca en el lote que ya llegó; desde ese
 * momento a los asesores les sale «Mandar». → ['ok', 'error', 'pedidos']
 */
function lote_listo_marcar(int $lote_id): array
{
    if (!columna_existe('lotes', 'listo_en')) return ['ok' => false, 'error' => 'Falta terminar la actualización.', 'pedidos' => 0];
    $r = en_transaccion(function () use ($lote_id) {
        bloquear_fila('lotes', $lote_id);
        $l = una('SELECT id, estado, listo_en FROM lotes WHERE id = ?', [$lote_id]);
        if (!$l) return 'Ese lote no existe.';
        if ((string)$l['estado'] !== 'recibido') return 'Primero marca que el lote llegó.';
        if ($l['listo_en'] !== null) return 'Este lote ya estaba listo para entrega.';
        actualizar('lotes', $lote_id, ['listo_en' => date('Y-m-d H:i:s'), 'listo_por' => $_SESSION['usuario_id'] ?? null]);
        return '';
    });
    if ($r !== '') return ['ok' => false, 'error' => $r, 'pedidos' => 0];
    bitacora('lote.listo', 'lote', $lote_id, []);
    return ['ok' => true, 'error' => '', 'pedidos' => lote_pedidos_al_dia($lote_id, 'el lote está listo para entrega')];
}

/**
 * Deshace «listo para entrega» (se marcó antes de tiempo). Solo mientras
 * ninguna de sus pre ventas haya salido a despacho: lo que ya salió, salió.
 */
function lote_listo_deshacer(int $lote_id): array
{
    if (!columna_existe('lotes', 'listo_en')) return ['ok' => false, 'error' => 'Falta terminar la actualización.', 'pedidos' => 0];
    $r = en_transaccion(function () use ($lote_id) {
        bloquear_fila('lotes', $lote_id);
        $l = una('SELECT id, listo_en FROM lotes WHERE id = ?', [$lote_id]);
        if (!$l || $l['listo_en'] === null) return 'Este lote no estaba listo para entrega.';
        $salieron = (int) valor('SELECT COUNT(DISTINCT pe.id) FROM pedidos pe JOIN pedido_lineas pl ON pl.pedido_id = pe.id
                                   JOIN lote_lineas ll ON ll.id = pl.lote_linea_id
                                  WHERE ll.lote_id = ? AND pe.anulado_en IS NULL AND pe.despacho_veces > 0', [$lote_id]);
        if ($salieron > 0) return ($salieron === 1 ? 'Ya salió 1 pedido' : 'Ya salieron ' . $salieron . ' pedidos') . ' de este lote: no se puede deshacer.';
        actualizar('lotes', $lote_id, ['listo_en' => null, 'listo_por' => null]);
        return '';
    });
    if ($r !== '') return ['ok' => false, 'error' => $r, 'pedidos' => 0];
    bitacora('lote.listo_deshecho', 'lote', $lote_id, []);
    return ['ok' => true, 'error' => '', 'pedidos' => lote_pedidos_al_dia($lote_id)];
}

/**
 * EL SIGUIENTE PASO DEL LOTE (3j, usuario 2026-09-28: «que el siguiente cuadro
 * a revisar se resalte»). Una sola definición del orden en que se llena un
 * lote: productos → revisar → precios → a la venta → llegó → listo para
 * entrega. → ['paso' => clave|'' , 'ancla' => id del cuadro, 'texto' => frase]
 */
function lote_siguiente_paso(?array $l): array
{
    $r = fn(string $paso, string $ancla, string $texto) => ['paso' => $paso, 'ancla' => $ancla, 'texto' => $texto];
    if (!$l) return $r('viaje', 'viaje', 'Pon el nombre del lote y los datos del viaje.');
    $id = (int)$l['id'];
    if ((string)$l['estado'] === 'cancelado') return $r('', '', '');
    $filas = lote_filas($id);
    if (!$filas) return $r('productos', 'productos', 'Pon los productos que trae el lote.');
    foreach ($filas as $k => $f) {
        if ((int)$f['resuelto'] === 0) return $r('revisar', 'fila-' . (int)$f['id'], 'Revisa las filas marcadas «Revisa».');
    }
    if ((string)$l['estado'] !== 'recibido') {
        foreach (lote_productos($id) as $p) {
            if (!$p['completo']) return $r('precios', 'precio-' . (int)$p['id'], 'Ponle precio a «' . $p['nombre'] . '».');
        }
        if ((int)$l['disponible'] !== 1) return $r('venta', 'form-interruptor', 'Pon el lote a la venta.');
        return $r('llego', 'form-estado', 'Cuando llegue el contenedor, marca «Llegó».');
    }
    if (columna_existe('lotes', 'listo_en') && empty($l['listo_en'])) {
        return $r('listo', 'form-listo', 'Cuando esté descargado, marca «Listo para entrega».');
    }
    return $r('', '', '');
}

/* ─────────────────────────  LO QUE VE EL ASESOR  ───────────────────────── */

/**
 * «DISPONIBLE PARA PRE VENTA»: los lotes encendidos de mi país con sus
 * productos CON PRECIO, ordenados por nombre. Lo sin precio no sale.
 */
function preventa_disponible(?string $q = null): array
{
    if (!preventa_lista()) return [];
    $out = [];
    foreach (lotes_lista() as $l) {
        if ((int)$l['disponible'] !== 1 || in_array($l['estado'], ['cancelado', 'recibido'], true)) continue;
        /* Sin precio no sale; y una fila marcada «Revisa» tampoco, hasta que el
           CEO la revise (puede ser un color mal leído o unidades inventadas). */
        $prods = [];
        foreach (lote_productos((int)$l['id']) as $p) {
            if (!$p['completo']) continue;
            $p['filas'] = array_values(array_filter($p['filas'], fn($f) => (int)$f['resuelto'] === 1));
            if (!$p['filas']) continue;
            $p['quedan'] = array_sum(array_column($p['filas'], 'quedan'));
            $prods[] = $p;
        }
        if ($q !== null && trim($q) !== '') {
            $qq = lista_texto_llano($q);
            $prods = array_values(array_filter($prods, fn($p) => str_contains(lista_texto_llano($p['nombre'] . ' ' . $p['sku']), $qq)));
        }
        usort($prods, fn($a, $b) => strcasecmp($a['nombre'], $b['nombre']));
        if ($prods) $out[] = ['lote' => $l, 'productos' => $prods];
    }
    return $out;
}

/**
 * Cuántos lotes en camino tienen algún producto sin precio: el chip del menú
 * y la línea del saludo del CEO. Una sola cuenta ($pais null = todos).
 */
function lotes_sin_precio_n(?int $pais): int
{
    if (!preventa_lista()) return 0;
    $sql = "SELECT l.id FROM lotes l WHERE l.estado NOT IN ('recibido','cancelado')
              AND EXISTS (SELECT 1 FROM lote_lineas ll WHERE ll.lote_id = l.id" . (columna_existe('lote_lineas', 'maquina') ? " AND (ll.maquina IS NULL OR ll.maquina = '')
                           AND NOT EXISTS (SELECT 1 FROM productos pr WHERE pr.id = ll.producto_id AND pr.padre_id IS NOT NULL)" : '') . "
                           AND NOT EXISTS (SELECT 1 FROM precios x WHERE x.producto_id = ll.producto_id AND x.lote_id = l.id AND x.activo = 1))";
    return count($pais === null ? todas($sql) : todas($sql . ' AND l.pais_id = ?', [$pais]));
}

/** ¿Hay algún lote a la venta en mi país? Entonces la pre venta sale SOLO de lotes. */
function preventa_con_lotes(): bool
{
    if (!preventa_lista()) return false;
    $u = yo();
    return (bool) valor("SELECT 1 FROM lotes WHERE disponible = 1 AND estado NOT IN ('cancelado','recibido') AND pais_id = ?", [(int)($u['pais_id'] ?? 0)]);
}

/**
 * El buscador del formulario en PRE VENTA: los productos de los lotes a la
 * venta, con sus colores (una opción por fila del lote, con lo que queda) y
 * los tramos del lote. Misma forma que catalogo_buscar() para la pantalla.
 */
function preventa_buscar(string $q, int $limite = 8): array
{
    if (mb_strlen(trim($q)) < 2) return [];
    $out = [];
    $mi_pais = (int)(yo()['pais_id'] ?? 0);
    foreach (preventa_disponible($q) as $g) {
        /* Se vende lo del país de quien vende (quien cruza países ve todos en la
           lista, pero no puede venderlos desde aquí). */
        if ((int)$g['lote']['pais_id'] !== $mi_pais) continue;
        foreach ($g['productos'] as $p) {
            $tramos = array_map(fn($t) => ['desde' => (int)$t['desde'], 'hasta' => $t['hasta'] === null ? null : (int)$t['hasta'],
                                           'precio' => (int)$t['precio_centimos'], 'alias' => (string)($t['alias'] ?? '')], $p['tramos']);
            $unica = count($p['filas']) === 1 && (string)$p['filas'][0]['modelo'] === '' ? $p['filas'][0] : null;
            $out[] = [
                'id' => (int)$p['id'], 'sku' => $p['sku'], 'nombre' => $p['nombre'],
                'lote' => (int)$g['lote']['id'], 'lote_nombre' => (string)$g['lote']['nombre'],
                'lote_linea' => $unica ? (int)$unica['id'] : 0,
                'stock' => ['texto' => $p['quedan'] > 0 ? 'Quedan ' . $p['quedan'] : 'Agotado'],
                'agotado' => $p['quedan'] <= 0,
                'precio' => $tramos[0]['precio'] ?? null, 'foto' => '', 'categoria' => '', 'garantia_item_id' => 0,
                'variantes' => $unica ? [] : array_map(fn($f) => [
                    'id' => (int)$f['id'], 'nombre' => (string)$f['modelo'] !== '' ? (string)$f['modelo'] : 'Única',
                    'stock' => ['texto' => $f['quedan'] > 0 ? 'quedan ' . $f['quedan'] : 'agotado'],
                    'agotado' => $f['quedan'] <= 0, 'tramos' => [],
                ], $p['filas']),
                'tramos' => $tramos,
            ];
            if (count($out) >= $limite) return $out;
        }
    }
    return $out;
}

/**
 * Una línea de pre venta del formulario → lo que se guarda, o el error.
 * El PRECIO y el MODELO salen del lote, no del navegador.
 * → ['ok', 'error', 'linea' => [...]]
 */
function preventa_linea(int $lote_linea_id, int $producto_id, int $cantidad, ?int $cantidad_tramo = null): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e, 'linea' => null];
    $f = una('SELECT ll.*, l.disponible, l.estado AS lote_estado, l.pais_id AS lote_pais, l.nombre AS lote_nombre,
                     p.nombre AS producto_nombre, p.sku AS producto_sku' . (columna_existe('productos', 'padre_id') ? ', p.padre_id AS producto_padre' : '') . '
                FROM lote_lineas ll JOIN lotes l ON l.id = ll.lote_id JOIN productos p ON p.id = ll.producto_id
               WHERE ll.id = ?', [$lote_linea_id]);
    $u = yo();
    if (!$f || ($producto_id && (int)$f['producto_id'] !== $producto_id) || (int)$f['lote_pais'] !== (int)($u['pais_id'] ?? 0)) {
        return $mal('Ese producto de pre venta ya no está. Vuelve a elegirlo.');
    }
    if ((int)$f['disponible'] !== 1 || in_array($f['lote_estado'], ['cancelado', 'recibido'], true)) return $mal('El lote de «' . $f['producto_nombre'] . '» ya no está a la venta.');
    if ((int)$f['resuelto'] !== 1) return $mal('«' . $f['producto_nombre'] . '» todavía se está revisando en su lote.');
    if ((string)($f['maquina'] ?? '') !== '' || !empty($f['producto_padre'])) return $mal('«' . $f['producto_nombre'] . '» es un repuesto: se vende cuando llega el lote.');
    /* EL TRAMO VA POR EL PRODUCTO, NO POR LA LÍNEA: 2 rojas + 2 azules son 4
       del mismo producto (todos los colores llevan el mismo precio). */
    $precio = precio_de_lote((int)$f['producto_id'], (int)$f['lote_id'], max(1, $cantidad_tramo ?? $cantidad));
    if ($precio === null) return $mal('«' . $f['producto_nombre'] . '» todavía no tiene precio de pre venta.');
    return ['ok' => true, 'error' => '', 'linea' => [
        'descripcion' => mb_substr((string)$f['producto_nombre'], 0, 220),
        'sku' => (string)$f['producto_sku'], 'modelo' => (string)$f['modelo'],
        'precio_unit_centimos' => $precio, 'producto_id' => (int)$f['producto_id'],
        'lote_linea_id' => (int)$f['id'], 'nombre_corto' => (string)$f['producto_nombre'] . ((string)$f['modelo'] !== '' ? ' · ' . $f['modelo'] : ''),
    ]];
}

/**
 * EL CORTE DURO, dentro de la transacción de la venta: con la fila del lote
 * bloqueada, lo vendido más lo que se pide no puede pasar de lo que trae.
 * Entre que el asesor vio «quedan 2» y pulsó guardar, otro pudo llevárselas.
 * Lanza PreventaAgotada con el texto para el asesor.
 */
function preventa_asegurar(array $lineas): void
{
    $pide = [];
    foreach ($lineas as $l) {
        if (!empty($l['lote_linea_id'])) $pide[(int)$l['lote_linea_id']] = ($pide[(int)$l['lote_linea_id']] ?? 0) + (int)$l['cantidad'];
    }
    /* Siempre en el mismo orden: dos ventas que bloquean las mismas filas en
       orden contrario se esperarían la una a la otra para siempre. */
    ksort($pide);
    foreach ($pide as $llid => $n) {
        $bloqueo = bd_es_sqlite() ? '' : ' FOR UPDATE';
        $f = una('SELECT unidades, modelo, producto_id, lote_id, resuelto FROM lote_lineas WHERE id = ?' . $bloqueo, [$llid]);
        if (!$f) throw new PreventaAgotada('Un producto de pre venta ya no está en su lote. Vuelve a elegirlo.');
        /* Con la fila bloqueada, el lote tiene que seguir a la venta. */
        $lo = una('SELECT disponible, estado FROM lotes WHERE id = ?', [(int)$f['lote_id']]);
        if (!$lo || (int)$lo['disponible'] !== 1 || in_array($lo['estado'], ['recibido', 'cancelado'], true) || (int)$f['resuelto'] !== 1) {
            throw new PreventaAgotada('Un producto de pre venta ya no está a la venta. Vuelve a elegirlo.');
        }
        $f['nombre'] = (string) valor('SELECT nombre FROM productos WHERE id = ?', [(int)$f['producto_id']]);
        $vendidas = lote_vendidas_ya($llid);
        $quedan = (int)$f['unidades'] - $vendidas;
        if ($n > $quedan) {
            $cual = $f['nombre'] . ((string)$f['modelo'] !== '' ? ' · ' . $f['modelo'] : '');
            throw new PreventaAgotada($quedan <= 0 ? '«' . $cual . '» ya se agotó en la pre venta.'
                                                  : 'De «' . $cual . '» quedan ' . $quedan . ' en la pre venta y pides ' . $n . '.');
        }
    }
}

/* ─────────────────────────  LOS COLORES ESCRITOS A MANO  ───────────────────────── */

/**
 * «12 unidades moradas, 15 unidades naranjas», «Negro:1 unidades», «Amarillo
 * 5 unidades», «Marrón», «/». La única lectura de esa columna: la usan el
 * formulario y el Excel.
 * → ['estado' => 'unica|listo|revisa', 'partes' => [['modelo', 'unidades'], …]]
 *   listo  = los números suman las unidades de la fila;
 *   revisa = no suman (o no hay números): se deja para que lo mire una persona.
 */
function preventa_partir_colores(string $texto, int $total): array
{
    $t = trim($texto);
    if ($t === '' || preg_match('~^[/\-–\s]+$~u', $t)) return ['estado' => 'unica', 'partes' => []];
    $trozos = preg_split('~\s*(?:\r?\n|,|;)\s*~u', $t) ?: [];
    $partes = [];
    $suma = 0;
    $con_numero = 0;
    foreach ($trozos as $tr) {
        $tr = trim($tr);
        if ($tr === '') continue;
        $tr = trim(preg_replace('~\([^)]*\)~u', '', $tr) ?? $tr);
        $n = null;
        if (preg_match('~(\d+)~', $tr, $m)) { $n = (int)$m[1]; $con_numero++; }
        $color = preg_replace('~\d+~', '', $tr) ?? '';
        $color = preg_replace('~\b(unidades|unidad|unds?|uds?|pzas?|piezas?)\b\.?~iu', '', $color) ?? $color;
        $color = trim(preg_replace('~\s+~u', ' ', str_replace([':', '.'], ' ', $color)) ?? $color);
        $color = $color !== '' ? mb_strtoupper(mb_substr($color, 0, 1)) . mb_substr($color, 1) : '';
        $partes[] = ['modelo' => mb_substr($color, 0, 120), 'unidades' => $n];
        $suma += (int)$n;
    }
    if (!$partes) return ['estado' => 'unica', 'partes' => []];
    /* Un solo color sin cantidad («Marrón»): se lleva toda la fila. */
    if (count($partes) === 1 && $partes[0]['unidades'] === null && $partes[0]['modelo'] !== '') {
        return ['estado' => 'listo', 'partes' => [['modelo' => $partes[0]['modelo'], 'unidades' => $total]]];
    }
    /* Un número sin color que es la fila entera («333 unidades (…)»): Única. */
    if (count($partes) === 1 && $partes[0]['modelo'] === '' && $partes[0]['unidades'] === $total) {
        return ['estado' => 'unica', 'partes' => []];
    }
    $todos = $con_numero === count($partes) && !array_filter($partes, fn($p) => $p['modelo'] === '');
    if ($todos && $suma === $total) return ['estado' => 'listo', 'partes' => $partes];
    return ['estado' => 'revisa', 'partes' => $partes];
}
