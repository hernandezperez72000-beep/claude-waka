<?php
declare(strict_types=1);

/**
 * LOS REPUESTOS (3i).
 *
 * Decidido por el usuario (tarjetas del 2026-09-27 y 28):
 *   · un repuesto es un PRODUCTO MÁS, colgado de SU MÁQUINA (`productos.padre_id`);
 *   · un kit se guarda PIEZA POR PIEZA, cada pieza con su stock;
 *   · se vende como cualquier producto si tiene precio; sin precio, solo
 *     sale por garantía (el buscador de la venta no ofrece lo que no tiene precio);
 *   · SU STOCK: si el repuesto está en compraenwaka usa el de la web, como todo;
 *     si no, lo lleva el HUB: entra cuando su lote marca «Llegó» y lo corrige
 *     Almacén. Todo lo del stock del HUB vive aquí: una sola regla.
 *   · los repuestos no se venden en pre venta: se venden cuando llegan.
 */

final class StockHubAgotado extends DomainException {}

/** ¿El HUB ya tiene lo de la 3i? (actualizar.php pendiente). */
function repuestos_listo(): bool
{
    return tabla_existe('stock_hub') && tabla_existe('stock_hub_mov')
        && columna_existe('productos', 'padre_id') && columna_existe('lote_lineas', 'maquina');
}

/** ¿Es un repuesto? La única definición: cuelga de una máquina. */
function producto_es_repuesto(?array $p): bool
{
    return $p !== null && !empty($p['padre_id']);
}

/**
 * ¿Su stock lo lleva el HUB? Un repuesto que NO está en la web. El que está en
 * la web usa el de la web (tarjeta del 2026-09-28). La única definición.
 */
function stock_hub_aplica(?array $p): bool
{
    return producto_es_repuesto($p) && empty($p['woo_id']);
}

/**
 * ¿Frena la falta de stock? Con el «Control de stock» apagado (arranque,
 * recarga de inventario) se apunta el movimiento y no se frena nada: la misma
 * regla que la web.
 */
function stock_hub_exige(): bool
{
    return control_stock();
}

/** La máquina de un repuesto (id, nombre, sku), o null. */
function repuesto_maquina(?array $p): ?array
{
    if (!producto_es_repuesto($p)) return null;
    return una('SELECT id, nombre, sku FROM productos WHERE id = ?', [(int)$p['padre_id']]);
}

/** Los repuestos de unas máquinas (encendidos), ordenados por máquina y nombre. */
function repuestos_de(array $maquinas): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $maquinas))));
    if (!$ids || !repuestos_listo()) return [];
    $en = implode(',', array_fill(0, count($ids), '?'));
    return todas("SELECT r.*, m.nombre AS maquina_nombre, m.sku AS maquina_sku
                    FROM productos r JOIN productos m ON m.id = r.padre_id
                   WHERE r.padre_id IN ($en) AND r.activo = 1
                   ORDER BY m.nombre, r.nombre", $ids);
}

/** Todos los repuestos encendidos de mi país (lo que Administración puede añadir a una garantía). */
function repuestos_todos(): array
{
    if (!repuestos_listo()) return [];
    return todas('SELECT r.*, m.nombre AS maquina_nombre, m.sku AS maquina_sku
                    FROM productos r JOIN productos m ON m.id = r.padre_id
                   WHERE r.activo = 1 AND ' . sql_producto_de_mi_pais('r') . '
                   ORDER BY m.nombre, r.nombre');
}

/**
 * CUELGA UN PRODUCTO DE SU MÁQUINA (o lo descuelga con null). Una máquina no
 * puede ser repuesto de otra, ni un repuesto tener repuestos: un solo nivel.
 * → ['ok', 'error']
 */
function repuesto_colgar(int $producto_id, ?int $maquina_id): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e];
    if (!repuestos_listo()) return $mal('Falta terminar la actualización.');
    $p = producto_de($producto_id);
    if (!$p) return $mal('Ese producto no existe.');
    if ($maquina_id === null || $maquina_id === 0) {
        if (!empty($p['padre_id'])) {
            actualizar('productos', $producto_id, ['padre_id' => null]);
            bitacora('catalogo.repuesto', 'producto', $producto_id, ['maquina' => null]);
        }
        return ['ok' => true, 'error' => ''];
    }
    if ($maquina_id === $producto_id) return $mal('Un producto no puede ser repuesto de sí mismo.');
    if (valor('SELECT 1 FROM productos WHERE padre_id = ?', [$producto_id])) {
        return $mal('«' . $p['nombre'] . '» tiene sus propios repuestos: no puede ser repuesto de otra.');
    }
    $m = producto_de($maquina_id);
    if (!$m) return $mal('Esa máquina no está en el catálogo.');
    if (!empty($m['padre_id'])) return $mal('«' . $m['nombre'] . '» ya es un repuesto: elige la máquina.');
    if ((int)($p['padre_id'] ?? 0) !== $maquina_id) {
        actualizar('productos', $producto_id, ['padre_id' => $maquina_id]);
        bitacora('catalogo.repuesto', 'producto', $producto_id, ['maquina' => $maquina_id]);
    }
    return ['ok' => true, 'error' => ''];
}

/** Una máquina por su código, en mi país. null si no está (o si es un repuesto). */
function maquina_por_codigo(string $codigo, ?int $pais = null): ?array
{
    $codigo = mb_strtoupper(trim($codigo));
    if ($codigo === '') return null;
    $m = una('SELECT id, nombre, sku, pais_id, padre_id FROM productos WHERE sku = ?', [$codigo]);
    if (!$m || !empty($m['padre_id'])) return null;
    $pais ??= catalogo_pais();
    if ($m['pais_id'] !== null && (int)$m['pais_id'] !== $pais) return null;
    return $m;
}

/**
 * LAS MÁQUINAS QUE SE PUEDEN ELEGIR en la casilla «Es repuesto» del lote (3j,
 * usuario 2026-09-28: «no entiendo bien el uso de la columna "repuesto de"»).
 * Ya no se escribe un código: se elige la máquina. Primero las del mismo lote,
 * después las del catálogo del país. → ['lote' => [[sku, nombre]], 'catalogo' => [...]]
 */
function maquinas_para_elegir(?int $lote_id, int $pais, int $tope = 2000): array
{
    $lote = [];
    $vistos = [];
    if ($lote_id) {
        foreach (lote_filas($lote_id) as $f) {
            if (!empty($f['es_repuesto'])) continue;
            $sku = (string)($f['producto_sku'] ?? '');
            if ($sku === '' || isset($vistos[$sku])) continue;
            $vistos[$sku] = true;
            $lote[] = ['sku' => $sku, 'nombre' => (string)$f['producto_nombre']];
        }
    }
    $cat = [];
    $padre = repuestos_listo() ? ' AND padre_id IS NULL' : '';
    foreach (todas('SELECT sku, nombre FROM productos
                     WHERE activo = 1' . $padre . ' AND (pais_id IS NULL OR pais_id = ?)
                     ORDER BY nombre LIMIT ' . (int)$tope, [$pais]) as $m) {
        if (isset($vistos[(string)$m['sku']])) continue;
        $cat[] = ['sku' => (string)$m['sku'], 'nombre' => (string)$m['nombre']];
    }
    return ['lote' => $lote, 'catalogo' => $cat];
}

/* ─────────────────────────  EL STOCK DEL HUB  ───────────────────────── */

/** Lo que hay de unos productos en un país → [producto_id => cantidad]. Sin fila = 0. */
function stock_hub_de(array $ids, ?int $pais = null): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids || !repuestos_listo()) return [];
    $pais ??= catalogo_pais();
    $en = implode(',', array_fill(0, count($ids), '?'));
    $out = array_fill_keys($ids, 0);
    foreach (todas("SELECT producto_id, cantidad FROM stock_hub WHERE pais_id = ? AND producto_id IN ($en)",
                   array_merge([$pais], $ids)) as $f) {
        $out[(int)$f['producto_id']] = (int)$f['cantidad'];
    }
    return $out;
}

/** El stock del HUB en palabras, con la misma forma que stock_web_texto(). */
function stock_hub_texto(int $cantidad): array
{
    if ($cantidad <= 0) return ['texto' => $cantidad < 0 ? 'Agotado (' . $cantidad . ')' : 'Agotado', 'tono' => 'rojo'];
    return ['texto' => $cantidad . ' en almacén', 'tono' => $cantidad <= stock_web_poco() ? 'ambar' : 'verde'];
}

/**
 * La fila del stock, BLOQUEADA hasta el final de la transacción. Si no existe
 * se crea a cero (dos a la vez: la segunda choca con la clave y la relee).
 * → la cantidad de ahora.
 */
function stock_hub_bloquear(int $producto_id, int $pais): int
{
    /* La fila se crea ANTES de bloquearla, mirando sin candado: en MySQL, un
       SELECT … FOR UPDATE sobre una fila que no existe bloquea el hueco, y dos
       ventas a la vez que después insertan se bloquean la una a la otra
       (error 1213). Insertar primero: la segunda espera y choca con la clave,
       que se trata como «ya estaba». */
    if (valor('SELECT 1 FROM stock_hub WHERE producto_id = ? AND pais_id = ?', [$producto_id, $pais]) === null) {
        try {
            q('INSERT INTO stock_hub (producto_id, pais_id, cantidad, actualizado_en) VALUES (?, ?, 0, ?)',
              [$producto_id, $pais, date('Y-m-d H:i:s')]);
        } catch (Throwable $ex) {
            if (!es_choque_de_unico($ex)) throw $ex;
        }
    }
    $sql = 'SELECT cantidad FROM stock_hub WHERE producto_id = ? AND pais_id = ?' . (bd_es_sqlite() ? '' : ' FOR UPDATE');
    return (int) valor($sql, [$producto_id, $pais]);
}

/**
 * MUEVE EL STOCK DEL HUB: suma (entra) o resta (sale) y deja el rastro. La
 * única función que escribe `stock_hub`. Llamarla dentro de una transacción
 * cuando haya que comprobar antes (stock_hub_asegurar()).
 * → lo que queda.
 */
function stock_hub_mover(int $producto_id, int $pais, int $cantidad, string $motivo,
                         ?string $ref_tipo = null, ?int $ref_id = null, string $nota = ''): int
{
    return en_transaccion(function () use ($producto_id, $pais, $cantidad, $motivo, $ref_tipo, $ref_id, $nota) {
        $antes = stock_hub_bloquear($producto_id, $pais);
        $queda = $antes + $cantidad;
        q('UPDATE stock_hub SET cantidad = ?, actualizado_en = ? WHERE producto_id = ? AND pais_id = ?',
          [$queda, date('Y-m-d H:i:s'), $producto_id, $pais]);
        insertar('stock_hub_mov', [
            'producto_id' => $producto_id, 'pais_id' => $pais, 'cantidad' => $cantidad, 'queda' => $queda,
            'motivo' => mb_substr($motivo, 0, 20), 'ref_tipo' => $ref_tipo, 'ref_id' => $ref_id,
            'nota' => $nota !== '' ? mb_substr($nota, 0, 200) : null,
            'usuario_id' => $_SESSION['usuario_id'] ?? null, 'creado_en' => date('Y-m-d H:i:s'),
        ]);
        return $queda;
    });
}

/**
 * EL CORTE DURO: con las filas bloqueadas (en orden de producto, para que dos
 * salidas no se esperen la una a la otra), comprueba que haya y las saca.
 * $piden = [producto_id => cantidad]. Con el control de stock apagado no frena:
 * solo apunta. Lanza StockHubAgotado con el texto para quien lo pide.
 * Tiene que correr DENTRO de la transacción de quien saca.
 */
function stock_hub_sacar(array $piden, int $pais, string $motivo, string $ref_tipo, int $ref_id): void
{
    ksort($piden);
    $exige = stock_hub_exige();
    /* Primero se mira todo, con todo bloqueado; después se saca. Si una pieza
       falta, no se saca ninguna. */
    $hay = [];
    foreach ($piden as $pid => $n) {
        $hay[$pid] = stock_hub_bloquear((int)$pid, $pais);
        if ($exige && (int)$n > $hay[$pid]) {
            $nom = (string) valor('SELECT nombre FROM productos WHERE id = ?', [(int)$pid]);
            throw new StockHubAgotado($hay[$pid] <= 0 ? 'De «' . $nom . '» no queda en el almacén.'
                                                     : 'De «' . $nom . '» quedan ' . $hay[$pid] . ' en el almacén y pides ' . (int)$n . '.');
        }
    }
    foreach ($piden as $pid => $n) {
        if ((int)$n > 0) stock_hub_mover((int)$pid, $pais, -(int)$n, $motivo, $ref_tipo, $ref_id);
    }
}

/**
 * Devuelve lo que salió por una referencia (una venta anulada, una garantía
 * anulada): lo neto que sigue fuera, producto por producto. Si ya se devolvió,
 * no hace nada. → unidades devueltas.
 */
function stock_hub_devolver(string $ref_tipo, int $ref_id, string $motivo): int
{
    if (!repuestos_listo()) return 0;
    $n = 0;
    foreach (todas('SELECT producto_id, pais_id, SUM(cantidad) AS neto FROM stock_hub_mov
                     WHERE ref_tipo = ? AND ref_id = ? GROUP BY producto_id, pais_id ORDER BY producto_id',
                   [$ref_tipo, $ref_id]) as $f) {
        $neto = (int)$f['neto'];
        if ($neto >= 0) continue;
        stock_hub_mover((int)$f['producto_id'], (int)$f['pais_id'], -$neto, $motivo, $ref_tipo, $ref_id);
        $n += -$neto;
    }
    return $n;
}

/** Lo que sale de una VENTA: las líneas cuyo producto lleva stock en el HUB. → [producto_id => cantidad] */
function stock_hub_de_lineas(array $lineas, string $tipo): array
{
    if (!repuestos_listo() || $tipo === 'preventa') return [];
    $out = [];
    foreach ($lineas as $l) {
        $pid = (int)($l['producto_id'] ?? 0);
        if (!$pid || !empty($l['lote_linea_id'])) continue;
        $p = una('SELECT id, padre_id, woo_id FROM productos WHERE id = ?', [$pid]);
        if (!stock_hub_aplica($p)) continue;
        $out[$pid] = ($out[$pid] ?? 0) + (int)($l['cantidad'] ?? 0);
    }
    return $out;
}

/** La venta saca sus repuestos del almacén (dentro de su transacción). */
function stock_hub_venta(int $pedido_id, array $lineas, string $tipo, int $pais): void
{
    $piden = stock_hub_de_lineas($lineas, $tipo);
    if ($piden) stock_hub_sacar($piden, $pais, 'venta', 'pedido', $pedido_id);
}

/**
 * ALMACÉN PONE LO QUE HAY DE VERDAD (un conteo): se apunta la diferencia.
 * → ['ok', 'error']
 */
function stock_hub_poner(int $producto_id, int $cantidad, string $motivo): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e];
    if (!repuestos_listo()) return $mal('Falta terminar la actualización.');
    $p = producto_de($producto_id);
    if (!stock_hub_aplica($p)) return $mal('El stock de este producto no se lleva aquí.');
    if ($cantidad < 0 || $cantidad > 999999) return $mal('Escribe cuántas hay: un número de 0 para arriba.');
    $motivo = trim($motivo);
    if ($motivo === '') return $mal('Escribe el motivo: conteo, llegó mercadería, dañados…');
    $pais = catalogo_pais();
    en_transaccion(function () use ($producto_id, $pais, $cantidad, $motivo) {
        $antes = stock_hub_bloquear($producto_id, $pais);
        if ($cantidad !== $antes) stock_hub_mover($producto_id, $pais, $cantidad - $antes, 'ajuste', null, null, $motivo);
    });
    bitacora('stock.hub', 'producto', $producto_id, ['cantidad' => $cantidad, 'motivo' => $motivo]);
    return ['ok' => true, 'error' => ''];
}

/** Los últimos movimientos de un producto, para su ficha. */
function stock_hub_movimientos(int $producto_id, int $tope = 10): array
{
    if (!repuestos_listo()) return [];
    return todas('SELECT m.*, u.nombre AS usuario FROM stock_hub_mov m LEFT JOIN usuarios u ON u.id = m.usuario_id
                   WHERE m.producto_id = ? AND m.pais_id = ? ORDER BY m.id DESC LIMIT ' . max(1, $tope),
                 [$producto_id, catalogo_pais()]);
}

/** Un movimiento en palabras: «Llegó el lote», «Venta P-00012»… */
function stock_hub_mov_texto(array $m): string
{
    $ref = '';
    if ($m['ref_tipo'] === 'pedido' && $m['ref_id']) $ref = ' ' . (string) valor('SELECT codigo FROM pedidos WHERE id = ?', [(int)$m['ref_id']]);
    if ($m['ref_tipo'] === 'garantia' && $m['ref_id']) $ref = ' ' . garantia_codigo((int)$m['ref_id']);
    if ($m['ref_tipo'] === 'lote' && $m['ref_id']) $ref = ' ' . (string) valor('SELECT nombre FROM lotes WHERE id = ?', [(int)$m['ref_id']]);
    return match ((string)$m['motivo']) {
        'lote'            => 'Llegó el lote' . $ref,
        'venta'           => 'Venta' . $ref,
        'anulacion'       => 'Se anuló la venta' . $ref,
        'garantia'        => 'Garantía' . $ref,
        'garantia_vuelve' => 'Se anuló la garantía' . $ref,
        'ajuste'          => 'Conteo' . ((string)($m['nota'] ?? '') !== '' ? ': ' . $m['nota'] : ''),
        default           => (string)$m['motivo'] . $ref,
    };
}

/**
 * EL ALMACÉN AL DÍA CON UN LOTE QUE LLEGÓ. Lo llama «Llegó» y lo vuelve a
 * llamar cada vez que se guardan los productos de un lote ya llegado. Deja
 * entrado, producto por producto, exactamente:
 *   las unidades de sus filas YA REVISADAS − lo que se vendió en pre venta,
 * de los repuestos que no están en la web. Lo que ya entró no vuelve a entrar;
 * una fila que se corrige después (otras unidades, una máquina bien escrita,
 * una fila que se da por revisada) entra o sale solo por la diferencia.
 * Tiene que correr con el lote bloqueado. → unidades movidas (neto).
 */
function stock_hub_lote_al_dia(int $lote_id): int
{
    if (!repuestos_listo()) return 0;
    $l = lote_de($lote_id);
    if (!$l || (string)$l['estado'] !== 'recibido') return 0;
    /* Un lote que llegó ANTES de la 3i no mete nada: esas unidades ya se
       vendieron o ya están contadas (auditoría de la 3i). */
    $arr = substr(garantia_arranque(), 0, 10);
    if ($arr !== '' && (string)($l['fecha_llegada_real'] ?? '') !== '' && (string)$l['fecha_llegada_real'] < $arr) return 0;
    $pais = (int)$l['pais_id'];
    $debe = [];
    $aplica = [];
    foreach (lote_filas($lote_id) as $f) {
        $pid = (int)$f['producto_id'];
        if (!$pid) continue;
        $aplica[$pid] ??= stock_hub_aplica(una('SELECT id, padre_id, woo_id FROM productos WHERE id = ?', [$pid]));
        if (!$aplica[$pid] || (int)$f['resuelto'] !== 1) continue;
        $debe[$pid] = ($debe[$pid] ?? 0) + max(0, (int)$f['unidades'] - (int)$f['vendidas']);
    }
    $entro = [];
    foreach (todas("SELECT producto_id, SUM(cantidad) AS n FROM stock_hub_mov WHERE ref_tipo = 'lote' AND ref_id = ?
                     GROUP BY producto_id", [$lote_id]) as $x) {
        $entro[(int)$x['producto_id']] = (int)$x['n'];
    }
    $ids = array_unique(array_merge(array_keys($debe), array_keys($entro)));
    sort($ids);
    $neto = 0;
    foreach ($ids as $pid) {
        /* Lo que dejó de llevarse aquí (pasó a la web, o ya no es repuesto) no se toca. */
        if (!isset($debe[$pid])) {
            $aplica[$pid] ??= stock_hub_aplica(una('SELECT id, padre_id, woo_id FROM productos WHERE id = ?', [$pid]));
            if (!$aplica[$pid]) continue;
        }
        $delta = ($debe[$pid] ?? 0) - ($entro[$pid] ?? 0);
        if ($delta === 0) continue;
        /* Si Almacén ya contó ese producto después de la llegada, manda su
           conteo: el lote no lo vuelve a mover (se contaría dos veces). */
        if ((string)($l['fecha_llegada_real'] ?? '') !== '' && valor("SELECT 1 FROM stock_hub_mov WHERE producto_id = ? AND pais_id = ?
                     AND motivo = 'ajuste' AND creado_en >= ?", [$pid, $pais, $l['fecha_llegada_real'] . ' 00:00:00'])) continue;
        stock_hub_mover($pid, $pais, $delta, 'lote', 'lote', $lote_id, $delta < 0 ? 'Corrección del lote' : '');
        $neto += $delta;
    }
    return $neto;
}

/**
 * CUÁNDO DOS PIEZAS SON LA MISMA: sin tildes, sin mayúsculas y en singular
 * («Botones» = «botón», «Joysticks» = «Joystick»). Solo para comparar: el
 * nombre se guarda como se escribió.
 */
function repuesto_clave_nombre(string $nombre): string
{
    $pal = preg_split('~\s+~u', lista_texto_llano($nombre)) ?: [];
    $pal = array_map(function (string $w): string {
        if (mb_strlen($w) <= 3) return $w;
        if (str_ends_with($w, 'ces')) return mb_substr($w, 0, -3) . 'z';            // luces → luz
        /* Se quita la «s» y después la «e»: botones → boton, cables y cable →
           cabl, motores → motor. No es gramática: es una llave para comparar. */
        if (str_ends_with($w, 's') && !str_ends_with($w, 'ss')) $w = mb_substr($w, 0, -1);
        if (str_ends_with($w, 'e') && mb_strlen($w) > 3) $w = mb_substr($w, 0, -1);
        return $w;
    }, array_filter($pal, fn($w) => $w !== ''));
    return implode(' ', $pal);
}

/* ─────────────────────────  EL KIT, PIEZA POR PIEZA  ───────────────────────── */

/**
 * «2 botones, 2 joysticks, 2 cables»: una pieza por trozo, con su número.
 * El HUB PROPONE y el CEO confirma (tarjeta del 2026-09-28): las filas nacen
 * «Revisa». Si no se entiende ninguna pieza, una sola fila con el texto.
 * → [['nombre', 'unidades'], …]
 */
function repuesto_partir_kit(string $texto, int $unidades): array
{
    $t = trim($texto);
    if ($t === '' || preg_match('~^[/\-–\s]+$~u', $t)) return [];
    $out = [];
    /* Se parte por comas, saltos de línea, «;» y la palabra «y». El punto no:
       «Cable USB 3.0» es una pieza. */
    foreach (preg_split('~\s*(?:\r?\n|,|;|\s+y\s+)\s*~u', $t) ?: [] as $tr) {
        $tr = trim(preg_replace('~\([^)]*\)~u', '', $tr) ?? $tr);
        if ($tr === '') continue;
        $n = null;
        $nom = $tr;
        /* LA CANTIDAD SOLO SE LEE DONDE VA UNA CANTIDAD: al principio («2
           botones», «2x motor», «3 unidades de palanca») o al final («botón
           x2», «palanca: 4», «motor 2 unidades»). Un número dentro del nombre
           («Motor 12V», «Cable USB 3.0») es parte del nombre. */
        if (preg_match('~^(\d{1,4})(?:\s*[xX]\s+|\s+(?:(?:unidades?|unds?|uds?|pzas?|piezas?)\b\s*(?:de\s+)?)?)(?=\pL)(.+)$~u', $tr, $m)) {
            $n = (int)$m[1]; $nom = $m[2];
        } elseif (preg_match('~^(.+?)\s*(?:[xX:]\s*)(\d{1,4})\s*(?:unidades?|unds?|uds?|pzas?|piezas?)?\.?$~u', $tr, $m)) {
            $nom = $m[1]; $n = (int)$m[2];
        } elseif (preg_match('~^(.+?)\s+(\d{1,4})\s*(?:unidades?|unds?|uds?|pzas?|piezas?)\.?$~u', $tr, $m)) {
            $nom = $m[1]; $n = (int)$m[2];
        }
        $nom = trim(preg_replace('~\s+~u', ' ', trim($nom, " \t:.-")) ?? $nom);
        if (mb_strlen($nom) < 2 || !preg_match('~\pL~u', $nom)) continue;
        $nom = mb_strtoupper(mb_substr($nom, 0, 1)) . mb_substr($nom, 1);
        $out[] = ['nombre' => mb_substr($nom, 0, 120), 'unidades' => $n !== null && $n > 0 ? $n : max(1, $unidades)];
    }
    return $out;
}
