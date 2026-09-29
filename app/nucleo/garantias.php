<?php
declare(strict_types=1);

/**
 * LA GARANTÍA (3i). Todo lo de la garantía vive aquí: una sola definición.
 *
 * Decidido por el usuario (tarjetas del 2026-09-27 y 28):
 *   · VIGENCIA: desde la PRIMERA vez que el pedido salió a despacho, más los
 *     meses de la garantía del pedido. Una venta vieja sin fecha de despacho
 *     cuenta desde el día de la venta, y se dice.
 *   · LA PIDE EL ASESOR desde el pedido original: las piezas (repuestos de las
 *     máquinas de ese pedido), el motivo y la foto de la falla (una o varias).
 *     Vencida también se puede pedir: se avisa y decide Administración.
 *   · LA APRUEBA ADMINISTRACIÓN, que puede añadir otra pieza. Al aprobar sale
 *     del stock y entra sola en «Por alistar», con envío gratis.
 *   · NO ES UNA VENTA: no suma a la meta, no da cashback, no se cobra. Por eso
 *     vive en su tabla y no en `pedidos`: un pedido a cero rebajaría el
 *     promedio del asesor y ensuciaría el podio.
 */

/** Cuántas fotos de la falla se pueden subir. La primera es obligatoria. */
const GARANTIA_FOTOS_MAX = 5;

/** ¿El HUB ya tiene lo de la 3i? */
function garantias_listo(): bool
{
    return tabla_existe('garantias') && tabla_existe('garantia_lineas') && tabla_existe('garantia_fotos')
        && columna_existe('lista_items', 'meses') && columna_existe('pedidos', 'despacho_primero_en')
        && repuestos_listo();
}

/** «G-00012». */
function garantia_codigo(int $id): string
{
    return 'G-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

/** Los estados, en palabras. */
function garantia_estados(): array
{
    return ['pedida' => 'Por aprobar', 'aprobada' => 'Aprobada', 'denegada' => 'No aprobada', 'anulada' => 'Anulada'];
}

/* ─────────────────────────  LOS MESES  ───────────────────────── */

/**
 * «12 meses» → 12 · «1 año» → 12 · «No aplica» → 0 · lo que no se entienda → null.
 * Solo para rellenar la primera vez: después manda el número de Configuración.
 */
function garantia_meses_de_texto(string $t): ?int
{
    $l = lista_texto_llano($t);
    if (preg_match('~(\d+)\s*mes~', $l, $m)) return (int)$m[1];
    if (preg_match('~(\d+)\s*an~', $l, $m)) return 12 * (int)$m[1];
    if (preg_match('~^un\s+ano~', $l)) return 12;
    if (str_contains($l, 'no aplica') || str_contains($l, 'sin garantia')) return 0;
    return null;
}

/** Los meses de una garantía de la lista. null = no se sabe (sin poner). */
function garantia_meses(?int $item_id): ?int
{
    if (!$item_id) return null;
    $i = lista_item($item_id);
    if (!$i) return null;
    if (isset($i['meses']) && $i['meses'] !== null) return (int)$i['meses'];
    return garantia_meses_de_texto((string)$i['valor']);
}

/* ─────────────────────────  LA VIGENCIA  ───────────────────────── */

/**
 * DESDE CUÁNDO CUENTA. La primera salida a despacho; si no hay y la venta es
 * vieja (de antes de la 3i) o ya se entregó, el día de la venta.
 * → ['fecha' => 'Y-m-d'|null, 'de' => 'despacho'|'venta'|'']
 */
function pedido_garantia_desde(array $p): array
{
    $primero = (string)($p['despacho_primero_en'] ?? '');
    if ($primero === '' && (int)($p['despacho_veces'] ?? 0) > 0) $primero = (string)($p['despacho_en'] ?? '');
    if ($primero !== '') return ['fecha' => substr($primero, 0, 10), 'de' => 'despacho'];
    $arranque = garantia_arranque();
    $vieja = $arranque !== '' && (string)($p['creado_en'] ?? '') !== '' && (string)$p['creado_en'] < $arranque;
    if ($vieja || (string)($p['estado'] ?? '') === 'entregado') {
        $f = (string)($p['fecha'] ?? '') !== '' ? (string)$p['fecha'] : substr((string)($p['creado_en'] ?? ''), 0, 10);
        if ($f !== '') return ['fecha' => $f, 'de' => 'venta'];
    }
    return ['fecha' => null, 'de' => ''];
}

/** El día en que se empezó a contar la garantía desde el despacho (la 3i). '' = sin marca: ninguna venta es «vieja». */
function garantia_arranque(): string
{
    return (string) ajuste('garantia_arranque', '');
}

/** Suma meses a una fecha sin saltar de mes: 31/01 + 1 mes = 28/02 (o 29). */
function fecha_mas_meses(string $fecha, int $meses): string
{
    $d = new DateTimeImmutable($fecha);
    $dia = (int)$d->format('j');
    $base = $d->modify('first day of this month')->modify('+' . $meses . ' months');
    $ult = (int)$base->format('t');
    return $base->setDate((int)$base->format('Y'), (int)$base->format('n'), min($dia, $ult))->format('Y-m-d');
}

/**
 * ¿SIGUE EN GARANTÍA? La única cuenta. La usan la ficha del pedido, la ficha
 * del cliente, el buscador de «¿Sigue en garantía?» y la solicitud.
 * → ['estado' => 'vigente'|'vencida'|'sin'|'no_empieza'|'anulado',
 *    'desde', 'de', 'hasta', 'meses', 'dias', 'texto', 'detalle']
 */
function pedido_garantia_vigencia(array $p, ?string $hoy = null): array
{
    $hoy ??= date('Y-m-d');
    $r = ['estado' => '', 'desde' => null, 'de' => '', 'hasta' => null, 'meses' => null, 'dias' => 0, 'texto' => '', 'detalle' => ''];
    if ((string)($p['estado'] ?? '') === 'anulado' || !empty($p['anulado_en'])) {
        return array_merge($r, ['estado' => 'anulado', 'texto' => 'Pedido anulado: no tiene garantía']);
    }
    $item = (int)($p['garantia_item_id'] ?? 0);
    $meses = garantia_meses($item ?: null);
    $r['meses'] = $meses;
    if ($item && $meses === null) {
        /* Una garantía cuyos meses nadie ha puesto: no se inventan. */
        return array_merge($r, ['estado' => 'sin', 'texto' => 'Falta decir cuántos meses dura «' . lista_texto($item) . '»',
                                'detalle' => 'Se pone en Configuración › Garantías.']);
    }
    if (!$item || $meses <= 0) {
        return array_merge($r, ['estado' => 'sin', 'texto' => $item ? 'Sin garantía (' . lista_texto($item) . ')' : 'Sin garantía registrada']);
    }
    $desde = pedido_garantia_desde($p);
    if ($desde['fecha'] === null) {
        return array_merge($r, ['estado' => 'no_empieza', 'texto' => 'Empieza cuando el pedido salga a despacho',
                                'detalle' => plural($meses, 'mes', 'meses') . ' desde que salga']);
    }
    $hasta = fecha_mas_meses($desde['fecha'], $meses);
    $r = array_merge($r, ['desde' => $desde['fecha'], 'de' => $desde['de'], 'hasta' => $hasta]);
    $r['detalle'] = plural($meses, 'mes', 'meses') . ' desde ' . ($desde['de'] === 'despacho' ? 'que salió a despacho' : 'la venta')
                  . ' (' . fecha_corta($desde['fecha']) . ')'
                  . ($desde['de'] === 'venta' ? ': no tiene fecha de despacho' : '');
    if ($hoy <= $hasta) {
        $dias = (int) (new DateTimeImmutable($hoy))->diff(new DateTimeImmutable($hasta))->days;
        return array_merge($r, ['estado' => 'vigente', 'dias' => $dias,
                                'texto' => 'Vigente hasta el ' . fecha_corta($hasta) . ' · ' . ($dias === 0 ? 'vence hoy' : plural($dias, 'día', 'días') . ' más')]);
    }
    return array_merge($r, ['estado' => 'vencida', 'texto' => 'Venció el ' . fecha_corta($hasta)]);
}

/** El tono del chip de la vigencia. */
function garantia_vigencia_tono(string $estado): string
{
    return match ($estado) { 'vigente' => 'verde', 'vencida' => 'rojo', 'no_empieza' => 'ambar', default => 'gris' };
}

/* ─────────────────────────  LAS PIEZAS  ───────────────────────── */

/** Las máquinas de un pedido (los productos de sus líneas). */
function pedido_maquinas(int $pedido_id): array
{
    return array_values(array_unique(array_filter(array_map('intval', array_column(
        todas('SELECT producto_id FROM pedido_lineas WHERE pedido_id = ? AND producto_id IS NOT NULL', [$pedido_id]), 'producto_id')))));
}

/**
 * LO QUE SE PUEDE PEDIR por garantía desde un pedido: los repuestos de las
 * máquinas que compró (y la propia pieza, si lo que compró era un repuesto).
 * Con lo que hay de cada uno.
 */
function pedido_garantia_piezas(int $pedido_id, ?int $pais = null): array
{
    $maq = pedido_maquinas($pedido_id);
    $piezas = repuestos_de($maq);
    /* Si compró un repuesto suelto, ese mismo también se puede cambiar. */
    foreach ($maq as $pid) {
        $p = una('SELECT r.*, m.nombre AS maquina_nombre, m.sku AS maquina_sku FROM productos r
                   JOIN productos m ON m.id = r.padre_id WHERE r.id = ? AND r.activo = 1', [$pid]);
        if ($p && !in_array($pid, array_map(fn($x) => (int)$x['id'], $piezas), true)) $piezas[] = $p;
    }
    return garantia_con_stock($piezas, $pais);
}

/** Le pone a cada pieza lo que hay (del HUB o de la web) en palabras. */
function garantia_con_stock(array $piezas, ?int $pais = null): array
{
    $hub = stock_hub_de(array_map(fn($x) => (int)$x['id'], $piezas), $pais);
    $web = stock_web_de(array_map(fn($x) => (int)$x['id'], array_filter($piezas, fn($x) => !empty($x['woo_id']))));
    foreach ($piezas as &$x) {
        $x['en_hub'] = stock_hub_aplica($x);
        $x['stock'] = $x['en_hub'] ? stock_hub_texto($hub[(int)$x['id']] ?? 0) : stock_web_texto($web[(int)$x['id']] ?? null);
        $x['hay'] = $x['en_hub'] ? (int)($hub[(int)$x['id']] ?? 0) : null;
    }
    unset($x);
    return $piezas;
}

/* ─────────────────────────  LEER  ───────────────────────── */

/** Una garantía con su pedido, su cliente y quién la pidió. null si no existe o no es de mi país. */
function garantia_de(int $id): ?array
{
    if (!garantias_listo()) return null;
    $g = una('SELECT g.*, pe.codigo AS pedido_codigo, c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos,
                     c.documento AS cliente_documento, c.celular AS cliente_celular,
                     a.nombre AS asesor_nombre, a.apellidos AS asesor_apellidos, a.equipo_id AS asesor_equipo,
                     pp.nombre AS pedida_por_nombre, rr.nombre AS resuelta_por_nombre
                FROM garantias g
                JOIN pedidos pe ON pe.id = g.pedido_id
                JOIN clientes c ON c.id = g.cliente_id
                JOIN usuarios a ON a.id = g.asesor_id
                LEFT JOIN usuarios pp ON pp.id = g.pedida_por
                LEFT JOIN usuarios rr ON rr.id = g.resuelta_por
               WHERE g.id = ?', [$id]);
    return $g ?: null;
}

/** Las piezas de una garantía, con su máquina. */
function garantia_lineas(int $garantia_id): array
{
    return todas('SELECT gl.*, r.nombre, r.sku, r.woo_id, r.padre_id, m.nombre AS maquina_nombre
                    FROM garantia_lineas gl JOIN productos r ON r.id = gl.producto_id
                    LEFT JOIN productos m ON m.id = r.padre_id
                   WHERE gl.garantia_id = ? ORDER BY gl.id', [$garantia_id]);
}

/** Las fotos de la falla. */
function garantia_fotos(int $garantia_id): array
{
    return todas('SELECT * FROM garantia_fotos WHERE garantia_id = ? ORDER BY orden, id', [$garantia_id]);
}

/** Las garantías de un pedido, la última arriba: «a esta máquina ya le cambiamos el joystick dos veces». */
function garantias_del_pedido(int $pedido_id): array
{
    if (!garantias_listo()) return [];
    $gs = todas('SELECT * FROM garantias WHERE pedido_id = ? ORDER BY id DESC', [$pedido_id]);
    foreach ($gs as &$g) $g['lineas'] = garantia_lineas((int)$g['id']);
    unset($g);
    return $gs;
}

/** «2 × Joystick · 1 × Botón». */
function garantia_piezas_texto(array $lineas): string
{
    if (!$lineas) return 'Pieza por elegir';
    return implode(' · ', array_map(fn($l) => (int)$l['cantidad'] . ' × ' . $l['nombre'], $lineas));
}

/**
 * La lista de garantías que ve cada uno: el asesor las suyas, Administración
 * las de su país, Dirección todas. Filtros: estado, texto (código, cliente,
 * documento).
 */
function garantias_lista(array $f = [], int $tope = 100, ?array $u = null): array
{
    if (!garantias_listo()) return [];
    $u ??= yo();
    [$amb, $par] = filtro_ambito('g.asesor_id', 'g.pais_id', 'a.equipo_id', $u);
    $w = [$amb];
    if (!empty($f['estado']) && isset(garantia_estados()[$f['estado']])) { $w[] = 'g.estado = ?'; $par[] = $f['estado']; }
    if (!empty($f['por_alistar'])) $w[] = "g.estado = 'aprobada' AND g.alistado_en IS NULL";
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . catalogo_escapar_like($q) . '%';
        $w[] = "(pe.codigo LIKE ? OR c.nombre LIKE ? OR c.apellidos LIKE ? OR c.documento LIKE ?)";
        array_push($par, $like, $like, $like, $like);
    }
    $filas = todas('SELECT g.*, pe.codigo AS pedido_codigo, c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos,
                           a.nombre AS asesor_nombre
                      FROM garantias g JOIN pedidos pe ON pe.id = g.pedido_id JOIN clientes c ON c.id = g.cliente_id
                      JOIN usuarios a ON a.id = g.asesor_id
                     WHERE ' . implode(' AND ', $w) . "
                     ORDER BY CASE g.estado WHEN 'pedida' THEN 0 ELSE 1 END, g.id DESC LIMIT " . max(1, $tope), $par);
    foreach ($filas as &$g) $g['lineas'] = garantia_lineas((int)$g['id']);
    unset($g);
    return $filas;
}

/** Cuántas esperan aprobación en mi país (el chip del menú y la bandeja). */
function garantias_por_aprobar_n(?array $u = null): int
{
    if (!garantias_listo()) return 0;
    $u ??= yo();
    if (!$u) return 0;
    $sql = "SELECT COUNT(*) FROM garantias WHERE estado = 'pedida'";
    return cruza_paises($u) ? (int) valor($sql) : (int) valor($sql . ' AND pais_id = ?', [(int)$u['pais_id']]);
}

/**
 * «¿SIGUE EN GARANTÍA?»: los pedidos de un cliente por su código, su nombre o
 * su documento, cada uno con su vigencia. Con el ámbito de quien mira.
 */
function garantia_buscar_pedidos(string $q, int $tope = 10): array
{
    $q = trim($q);
    if (mb_strlen($q) < 3) return [];
    [$amb, $par] = filtro_ambito('pe.asesor_id', 'pe.pais_id', 'u.equipo_id');
    $like = '%' . catalogo_escapar_like($q) . '%';
    $filas = todas("SELECT pe.id FROM pedidos pe JOIN clientes c ON c.id = pe.cliente_id JOIN usuarios u ON u.id = pe.asesor_id
                     WHERE $amb AND (pe.codigo LIKE ? OR c.documento LIKE ? OR c.nombre LIKE ? OR c.apellidos LIKE ? OR c.celular LIKE ?)
                     ORDER BY pe.id DESC LIMIT " . max(1, $tope), array_merge($par, [$like, $like, $like, $like, $like]));
    $out = [];
    foreach ($filas as $f) {
        $p = pedido_de((int)$f['id']);
        if (!$p) continue;
        $out[] = ['p' => $p, 'v' => pedido_garantia_vigencia($p), 'lineas' => pedido_lineas((int)$p['id'])];
    }
    return $out;
}

/* ─────────────────────────  PEDIRLA  ───────────────────────── */

/**
 * ¿Se puede pedir garantía de este pedido? '' = sí; si no, por qué.
 * Pedirla es escribir sobre el pedido: el asesor, del suyo; Administración, de su país.
 */
function garantia_se_puede_pedir(array $p): string
{
    if (!garantias_listo()) return 'Falta terminar la actualización.';
    if (!puede('garantias.pedir') || !puedo_editar((int)$p['asesor_id'], (int)$p['pais_id'])) return 'Tu cuenta no pide garantías de este pedido.';
    $v = pedido_garantia_vigencia($p);
    if ($v['estado'] === 'anulado') return 'Este pedido está anulado: no tiene garantía.';
    if ($v['estado'] === 'no_empieza') return 'La garantía empieza cuando el pedido salga a despacho.';
    return '';
}

/**
 * PIDE LA GARANTÍA. $piezas = [producto_id => cantidad]; $fotos = archivos ya
 * guardados (ver voucher_del_formulario). → ['ok', 'error', 'id']
 */
function garantia_pedir(int $pedido_id, array $piezas, string $motivo, array $fotos, bool $sin_pieza = false): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e, 'id' => 0];
    $p = pedido_de($pedido_id);
    if (!$p) return $mal('Ese pedido no existe.');
    if (($no = garantia_se_puede_pedir($p)) !== '') return $mal($no);

    $validas = [];
    foreach (pedido_garantia_piezas($pedido_id, (int)$p['pais_id']) as $x) $validas[(int)$x['id']] = $x;
    $limpias = [];
    foreach ($piezas as $pid => $n) {
        $pid = (int)$pid; $n = (int)$n;
        if ($n <= 0) continue;
        if (!isset($validas[$pid])) return $mal('Esa pieza no es de las máquinas de este pedido. Vuelve a elegirla.');
        if ($n > 20) return $mal('Pides demasiadas unidades de «' . $validas[$pid]['nombre'] . '»: como mucho 20.');
        $limpias[$pid] = $n;
    }
    if (!$limpias && !$sin_pieza) return $mal('Elige la pieza que hay que cambiar, o marca «No sé qué pieza es».');
    $motivo = trim(preg_replace('/\s+/u', ' ', $motivo) ?? '');
    if (mb_strlen($motivo) < 5) return $mal('Cuenta qué falla: con eso Administración decide.');
    $fotos = array_values(array_filter($fotos, fn($f) => is_string($f) && $f !== ''));
    if (!$fotos) return $mal('Falta la foto de la falla.');
    if (count($fotos) > GARANTIA_FOTOS_MAX) return $mal('Como mucho ' . GARANTIA_FOTOS_MAX . ' fotos.');
    foreach ($fotos as $f) if (ruta_voucher($f) === '' || str_ends_with($f, '.pdf')) return $mal('Una de las fotos no llegó bien. Vuelve a subirla.');

    $v = pedido_garantia_vigencia($p);
    $u = yo();
    $id = en_transaccion(function () use ($p, $limpias, $motivo, $fotos, $sin_pieza, $v, $u) {
        $gid = insertar('garantias', [
            'pais_id' => (int)$p['pais_id'], 'pedido_id' => (int)$p['id'], 'cliente_id' => (int)$p['cliente_id'],
            'asesor_id' => (int)$p['asesor_id'], 'estado' => 'pedida', 'motivo' => mb_substr($motivo, 0, 500),
            'sin_pieza' => $limpias ? 0 : 1, 'fuera_plazo' => $v['estado'] === 'vigente' ? 0 : 1,
            'vence_en' => $v['hasta'], 'pedida_por' => (int)$u['id'], 'pedida_en' => date('Y-m-d H:i:s'),
        ]);
        foreach ($limpias as $pid => $n) insertar('garantia_lineas', ['garantia_id' => $gid, 'producto_id' => $pid, 'cantidad' => $n, 'anadida' => 0]);
        foreach ($fotos as $k => $f) insertar('garantia_fotos', ['garantia_id' => $gid, 'archivo' => $f, 'orden' => $k]);
        return $gid;
    });
    $txt = garantia_piezas_texto(garantia_lineas($id));
    pedido_evento($pedido_id, 'garantia', 'Pidió garantía ' . garantia_codigo($id) . ': ' . $txt
        . ($v['estado'] === 'vigente' ? '' : ' · fuera de plazo'));
    bitacora('garantia.pedir', 'garantia', $id, ['pedido' => $pedido_id, 'piezas' => $limpias, 'fuera_plazo' => $v['estado'] !== 'vigente']);
    return ['ok' => true, 'error' => '', 'id' => $id];
}

/* ─────────────────────────  ADMINISTRACIÓN  ───────────────────────── */

/** ¿Puedo resolver esta garantía? Permiso y país. */
function garantia_puedo_resolver(array $g): bool
{
    $u = yo();
    return $u !== null && puede('garantias.aprobar') && (cruza_paises($u) || (int)$g['pais_id'] === (int)$u['pais_id'])
        && ($u['ambito'] ?? '') !== 'equipo';
}

/** Añade una pieza (cualquier repuesto de mi país) mientras espera aprobación. → ['ok', 'error'] */
function garantia_anadir_pieza(int $garantia_id, int $producto_id, int $cantidad): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e];
    $g = garantia_de($garantia_id);
    if (!$g || !garantia_puedo_resolver($g)) return $mal('Esa garantía no existe.');
    $r = producto_de($producto_id);
    if (!producto_es_repuesto($r) || (int)$r['activo'] !== 1) return $mal('Elige un repuesto de la lista.');
    if ($cantidad < 1 || $cantidad > 20) return $mal('La cantidad va de 1 a 20.');
    /* Con la garantía bloqueada: si otra persona la está aprobando, espera y
       ve que ya se resolvió. */
    $e = en_transaccion(function () use ($garantia_id, $producto_id, $cantidad) {
        bloquear_fila('garantias', $garantia_id);
        if ((string) valor('SELECT estado FROM garantias WHERE id = ?', [$garantia_id]) !== 'pedida') return 'Esta garantía ya se resolvió.';
        $ya = una('SELECT id, cantidad FROM garantia_lineas WHERE garantia_id = ? AND producto_id = ?', [$garantia_id, $producto_id]);
        if ($ya) actualizar('garantia_lineas', (int)$ya['id'], ['cantidad' => min(20, (int)$ya['cantidad'] + $cantidad)]);
        else insertar('garantia_lineas', ['garantia_id' => $garantia_id, 'producto_id' => $producto_id, 'cantidad' => $cantidad, 'anadida' => 1]);
        return '';
    });
    if ($e !== '') return $mal($e);
    bitacora('garantia.pieza', 'garantia', $garantia_id, ['producto' => $producto_id, 'cantidad' => $cantidad]);
    return ['ok' => true, 'error' => ''];
}

/** Quita una pieza mientras espera aprobación. */
function garantia_quitar_pieza(int $garantia_id, int $linea_id): array
{
    $g = garantia_de($garantia_id);
    if (!$g || !garantia_puedo_resolver($g)) return ['ok' => false, 'error' => 'Esa garantía no existe.'];
    $e = en_transaccion(function () use ($garantia_id, $linea_id) {
        bloquear_fila('garantias', $garantia_id);
        if ((string) valor('SELECT estado FROM garantias WHERE id = ?', [$garantia_id]) !== 'pedida') return 'Esta garantía ya se resolvió.';
        if (q('DELETE FROM garantia_lineas WHERE id = ? AND garantia_id = ?', [$linea_id, $garantia_id])->rowCount() !== 1) return 'Esa pieza ya no está en la garantía.';
        return '';
    });
    if ($e !== '') return ['ok' => false, 'error' => $e];
    bitacora('garantia.quitar', 'garantia', $garantia_id, ['linea' => $linea_id]);
    return ['ok' => true, 'error' => ''];
}

/**
 * APRUEBA: saca las piezas del stock (del HUB o de la web) y la garantía entra
 * en «Por alistar». Todo o nada: si falta una pieza, no sale ninguna.
 * → ['ok', 'error', 'aviso']
 */
function garantia_aprobar(int $garantia_id, string $nota = ''): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e, 'aviso' => ''];
    $g = garantia_de($garantia_id);
    if (!$g || !garantia_puedo_resolver($g)) return $mal('Esa garantía no existe.');
    if ($g['estado'] !== 'pedida') return $mal('Esta garantía ya se resolvió.');
    $lineas = garantia_lineas($garantia_id);
    if (!$lineas) return $mal('Añade la pieza que sale antes de aprobarla.');
    $pais = (int)$g['pais_id'];

    /* Lo que va a la web primero (como la venta): se llama a la tienda antes
       de la transacción; si algo falla después, se devuelve. */
    $a_web = [];
    foreach ($lineas as $l) {
        if (!empty($l['woo_id'])) $a_web[] = ['producto_id' => (int)$l['producto_id'], 'variante_id' => 0, 'cantidad' => (int)$l['cantidad']];
    }
    $prep = stock_venta_preparar('inmediata', $pais, $a_web);
    if (!$prep['ok']) return $mal($prep['error']);
    $piden = [];
    foreach ($lineas as $l) {
        if (stock_hub_aplica($l)) $piden[(int)$l['producto_id']] = ($piden[(int)$l['producto_id']] ?? 0) + (int)$l['cantidad'];
    }
    try {
        $firma = fn(array $ls) => implode(',', array_map(fn($l) => (int)$l['producto_id'] . 'x' . (int)$l['cantidad'], $ls));
        $antes = $firma($lineas);
        en_transaccion(function () use ($garantia_id, $piden, $pais, $nota, $prep, $firma, $antes, $g) {
            /* El pedido primero y la garantía después: el mismo orden que la
               anulación del pedido, para no esperarse la una a la otra. */
            bloquear_fila('pedidos', (int)$g['pedido_id']);
            bloquear_fila('garantias', $garantia_id);
            if ((string) valor('SELECT estado FROM garantias WHERE id = ?', [$garantia_id]) !== 'pedida') {
                throw new DomainException('Esta garantía ya se resolvió.');
            }
            if (valor('SELECT anulado_en FROM pedidos WHERE id = ?', [(int)$g['pedido_id']]) !== null) {
                throw new DomainException('El pedido de esta garantía se anuló.');
            }
            /* Con la garantía bloqueada, las piezas tienen que ser las que se
               miraron: si otra persona añadió o quitó una mientras tanto, no se
               aprueba a ciegas. */
            if ($firma(garantia_lineas($garantia_id)) !== $antes) {
                throw new DomainException('Las piezas cambiaron mientras la aprobabas. Mira la lista y vuelve a aprobarla.');
            }
            stock_hub_sacar($piden, $pais, 'garantia', 'garantia', $garantia_id);
            if (($prep['estado'] ?? '') !== '') {
                $cid = stock_cola_nueva(null, 'descuento', $prep['clave'], $prep['movs'], true, $prep['estado'], null,
                                        (string)($prep['fallo'] ?? ''), !empty($prep['revisar']));
                q('UPDATE stock_web_cola SET garantia_id = ? WHERE id = ?', [$garantia_id, $cid]);
            }
            $u = yo();
            q("UPDATE garantias SET estado = 'aprobada', resuelta_por = ?, resuelta_en = ?, nota = ? WHERE id = ?",
              [(int)$u['id'], date('Y-m-d H:i:s'), trim($nota) !== '' ? mb_substr(trim($nota), 0, 300) : null, $garantia_id]);
        });
    } catch (DomainException $ex) {
        stock_venta_deshacer($prep, 'La garantía no se aprobó');
        return $mal($ex->getMessage());
    } catch (Throwable $ex) {
        stock_venta_deshacer($prep, 'La garantía no se aprobó');
        throw $ex;
    }
    pedido_evento((int)$g['pedido_id'], 'garantia', 'Garantía ' . garantia_codigo($garantia_id) . ' aprobada: '
        . garantia_piezas_texto($lineas) . '. Pasa a «Por alistar».');
    bitacora('garantia.aprobar', 'garantia', $garantia_id, ['nota' => $nota]);
    return ['ok' => true, 'error' => '', 'aviso' => (string)($prep['aviso'] ?? '')];
}

/** NO LA APRUEBA, con su motivo. → ['ok', 'error'] */
function garantia_denegar(int $garantia_id, string $nota): array
{
    $g = garantia_de($garantia_id);
    if (!$g || !garantia_puedo_resolver($g)) return ['ok' => false, 'error' => 'Esa garantía no existe.'];
    $nota = trim($nota);
    if (mb_strlen($nota) < 3) return ['ok' => false, 'error' => 'Escribe por qué no se aprueba: el asesor se lo dirá al cliente.'];
    $u = yo();
    $n = q("UPDATE garantias SET estado = 'denegada', resuelta_por = ?, resuelta_en = ?, nota = ? WHERE id = ? AND estado = 'pedida'",
           [(int)$u['id'], date('Y-m-d H:i:s'), mb_substr($nota, 0, 300), $garantia_id])->rowCount();
    if ($n !== 1) return ['ok' => false, 'error' => 'Esta garantía ya se resolvió.'];
    pedido_evento((int)$g['pedido_id'], 'garantia', 'Garantía ' . garantia_codigo($garantia_id) . ' no aprobada: ' . $nota);
    bitacora('garantia.denegar', 'garantia', $garantia_id, ['nota' => $nota]);
    return ['ok' => true, 'error' => ''];
}

/**
 * ANULA UNA GARANTÍA APROBADA que todavía no salió: las piezas vuelven al
 * stock (y a la web, si salieron de allá). Una ya alistada no: ya salió.
 */
function garantia_anular(int $garantia_id, string $nota): array
{
    $g = garantia_de($garantia_id);
    if (!$g || !garantia_puedo_resolver($g)) return ['ok' => false, 'error' => 'Esa garantía no existe.'];
    $nota = trim($nota);
    if (mb_strlen($nota) < 3) return ['ok' => false, 'error' => 'Escribe por qué se anula.'];
    $r = en_transaccion(function () use ($garantia_id, $nota) {
        bloquear_fila('garantias', $garantia_id);
        $a = una('SELECT estado, alistado_en FROM garantias WHERE id = ?', [$garantia_id]);
        if (!$a || $a['estado'] !== 'aprobada') return 'Solo se anula una garantía aprobada.';
        if ($a['alistado_en'] !== null) return 'Esta garantía ya se alistó: la pieza salió.';
        garantia_devolver_y_anular($garantia_id, $nota);
        return '';
    });
    if ($r !== '') return ['ok' => false, 'error' => $r];
    garantias_mandar_devoluciones([$garantia_id]);
    pedido_evento((int)$g['pedido_id'], 'garantia', 'Garantía ' . garantia_codigo($garantia_id) . ' anulada: ' . $nota . '. Las piezas vuelven al stock.');
    bitacora('garantia.anular', 'garantia', $garantia_id, ['nota' => $nota]);
    return ['ok' => true, 'error' => ''];
}

/** Las piezas vuelven (al almacén y a la web) y la garantía queda anulada. Dentro de una transacción. */
function garantia_devolver_y_anular(int $garantia_id, string $nota): void
{
    stock_hub_devolver('garantia', $garantia_id, 'garantia_vuelve');
    stock_garantia_devolver($garantia_id);
    q("UPDATE garantias SET estado = 'anulada', nota = ? WHERE id = ?", [mb_substr($nota, 0, 300), $garantia_id]);
}

/** Manda ya a la web las devoluciones de unas garantías (fuera de la transacción; si la web no responde, la cola lo reintenta). */
function garantias_mandar_devoluciones(array $ids): void
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids || !columna_existe('stock_web_cola', 'garantia_id')) return;
    try {
        $en = implode(',', array_fill(0, count($ids), '?'));
        $cola = array_map('intval', array_column(todas("SELECT id FROM stock_web_cola WHERE garantia_id IN ($en)
                                                         AND tipo = 'devolucion' AND estado = 'pendiente'", $ids), 'id'));
        if ($cola) stock_cola_procesar(null, count($cola) + 1, $cola);
    } catch (Throwable $ex) { error_log('[HUB stock] garantía anulada: ' . $ex->getMessage()); }
}

/**
 * EL PEDIDO SE ANULÓ: sus garantías que no salieron se anulan con él (las
 * piezas vuelven), y las que esperaban aprobación también. Una ya alistada ya
 * salió: se queda como está. Dentro de la transacción de la anulación.
 * → las garantías anuladas.
 */
function garantias_al_anular_pedido(int $pedido_id): array
{
    if (!garantias_listo()) return [];
    $hechas = [];
    foreach (todas("SELECT id, estado FROM garantias WHERE pedido_id = ? AND estado IN ('pedida','aprobada') AND alistado_en IS NULL
                     ORDER BY id", [$pedido_id]) as $g) {
        bloquear_fila('garantias', (int)$g['id']);
        if ($g['estado'] === 'aprobada') garantia_devolver_y_anular((int)$g['id'], 'Se anuló el pedido');
        else q("UPDATE garantias SET estado = 'anulada', nota = 'Se anuló el pedido' WHERE id = ? AND estado = 'pedida'", [(int)$g['id']]);
        $hechas[] = (int)$g['id'];
    }
    return $hechas;
}

/* ─────────────────────────  POR ALISTAR  ───────────────────────── */

/** Las garantías aprobadas que Almacén tiene que preparar (mismo país que quien alista). */
function garantias_por_alistar(?array $u = null, int $tope = 100): array
{
    if (!garantias_listo()) return [];
    [$amb, $par] = alistar_ambito('g', $u);
    $gs = todas("SELECT g.*, pe.codigo AS pedido_codigo, c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos
                   FROM garantias g JOIN pedidos pe ON pe.id = g.pedido_id JOIN clientes c ON c.id = g.cliente_id
                  WHERE g.estado = 'aprobada' AND g.alistado_en IS NULL AND pe.anulado_en IS NULL AND $amb
                  ORDER BY g.resuelta_en ASC, g.id ASC LIMIT " . max(1, $tope), $par);
    foreach ($gs as &$g) $g['lineas'] = garantia_lineas((int)$g['id']);
    unset($g);
    return $gs;
}

function garantias_por_alistar_n(?array $u = null): int
{
    if (!garantias_listo()) return 0;
    [$amb, $par] = alistar_ambito('g', $u);
    return (int) valor("SELECT COUNT(*) FROM garantias g JOIN pedidos pe ON pe.id = g.pedido_id
                         WHERE g.estado = 'aprobada' AND g.alistado_en IS NULL AND pe.anulado_en IS NULL AND $amb", $par);
}

/** Lo alistado hoy de garantías (para ver la foto o deshacer). */
function garantias_alistadas_hoy(?array $u = null): array
{
    if (!garantias_listo()) return [];
    [$amb, $par] = alistar_ambito('g', $u);
    return todas("SELECT g.*, pe.codigo AS pedido_codigo, c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos
                    FROM garantias g JOIN pedidos pe ON pe.id = g.pedido_id JOIN clientes c ON c.id = g.cliente_id
                   WHERE g.alistado_en >= ? AND g.alistado_foto IS NOT NULL AND $amb
                   ORDER BY g.alistado_en DESC LIMIT 100", array_merge([date('Y-m-d') . ' 00:00:00'], $par));
}

/** ALISTADA: con quién y la foto, como un pedido. → ['ok', 'error'] */
function garantia_alistar(int $garantia_id, ?int $quien_id, ?string $foto): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e];
    $g = garantias_listo() ? una('SELECT * FROM garantias WHERE id = ?', [$garantia_id]) : null;
    $u = yo();
    if (!$g || !$u || (!cruza_paises($u) && (int)$g['pais_id'] !== (int)$u['pais_id'])) return $mal('Esa garantía no existe.');
    if ($g['estado'] !== 'aprobada') return $mal('Esa garantía ya no está aprobada: no hay que alistarla.');
    if (valor('SELECT anulado_en FROM pedidos WHERE id = ?', [(int)$g['pedido_id']]) !== null) return $mal('El pedido de esta garantía se anuló: no sale.');
    if ($g['alistado_en'] !== null) return $mal('Esa garantía ya estaba alistada.');
    if (!$quien_id || !lista_valida('equipo_despacho', $quien_id, (int)$g['pais_id'])) return $mal('Elige quién la alistó.');
    if ($foto === null || $foto === '' || ruta_voucher($foto) === '') return $mal('Falta la foto de lo alistado.');
    $st = q("UPDATE garantias SET alistado_en = ?, alistado_por = ?, alistado_quien_id = ?, alistado_foto = ?
              WHERE id = ? AND alistado_en IS NULL AND estado = 'aprobada'",
            [date('Y-m-d H:i:s'), (int)$u['id'], $quien_id, $foto, $garantia_id]);
    if ($st->rowCount() !== 1) return $mal('Esa garantía ya estaba alistada.');
    pedido_evento((int)$g['pedido_id'], 'garantia', 'Garantía ' . garantia_codigo($garantia_id) . ' alistada por ' . lista_texto($quien_id) . '.');
    bitacora('garantia.alistada', 'garantia', $garantia_id, ['quien' => lista_texto($quien_id), 'foto' => $foto]);
    return ['ok' => true, 'error' => ''];
}

/** Deshace el alistado de una garantía, solo el mismo día. */
function garantia_alistado_deshacer(int $garantia_id): array
{
    $g = garantias_listo() ? una('SELECT * FROM garantias WHERE id = ?', [$garantia_id]) : null;
    $u = yo();
    if (!$g || !$u || (!cruza_paises($u) && (int)$g['pais_id'] !== (int)$u['pais_id'])) return ['ok' => false, 'error' => 'Esa garantía no existe.'];
    if ($g['alistado_en'] === null) return ['ok' => false, 'error' => 'Esa garantía no estaba alistada.'];
    $st = q('UPDATE garantias SET alistado_en = NULL, alistado_por = NULL, alistado_quien_id = NULL, alistado_foto = NULL
              WHERE id = ? AND alistado_en >= ?', [$garantia_id, date('Y-m-d') . ' 00:00:00']);
    if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'Solo se deshace el mismo día.'];
    bitacora('garantia.alistado_deshecho', 'garantia', $garantia_id, ['foto' => $g['alistado_foto'], 'por' => $g['alistado_por']]);
    /* Si su pedido se anuló mientras tanto, ya no sale: se anula y la pieza vuelve. */
    if ($g['estado'] === 'aprobada' && valor('SELECT anulado_en FROM pedidos WHERE id = ?', [(int)$g['pedido_id']]) !== null) {
        en_transaccion(function () use ($garantia_id) { bloquear_fila('garantias', $garantia_id); garantia_devolver_y_anular($garantia_id, 'Se anuló el pedido'); });
        garantias_mandar_devoluciones([$garantia_id]);
        return ['ok' => true, 'error' => '', 'aviso' => 'El pedido se anuló: la garantía se anula y la pieza vuelve al stock.'];
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * LO QUE EL ASESOR TIENE QUE SABER: sus garantías resueltas en los últimos días
 * (aprobadas o no), para su Inicio.
 */
function garantias_resueltas_recientes(array $u, int $dias = 7, int $tope = 5): array
{
    if (!garantias_listo()) return [];
    return todas("SELECT g.id, g.estado, g.nota, g.resuelta_en, pe.codigo AS pedido_codigo, c.nombre AS cliente_nombre
                    FROM garantias g JOIN pedidos pe ON pe.id = g.pedido_id JOIN clientes c ON c.id = g.cliente_id
                   WHERE g.asesor_id = ? AND g.estado IN ('aprobada','denegada') AND g.resuelta_en >= ?
                   ORDER BY g.resuelta_en DESC, g.id DESC LIMIT " . max(1, $tope),
                 [(int)$u['id'], date('Y-m-d H:i:s', time() - $dias * 86400)]);
}
