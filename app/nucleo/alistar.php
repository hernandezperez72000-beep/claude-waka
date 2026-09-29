<?php
declare(strict_types=1);

/**
 * POR ALISTAR (3g) — lo que Almacén prepara.
 *
 * Pedido por el usuario el 2026-09-26: «hay errores en que se olvidan de
 * enviar cosas o envían otro modelo». Cuando el asesor MANDA la venta a
 * despacho, entra aquí con todo lo que lleva y las indicaciones del envío.
 * Almacén la prepara y pulsa ALISTADO con la FOTO de lo alistado y QUIÉN lo
 * alistó, elegido de la lista «Equipo de despacho» (Configuración). Más
 * adelante cada uno tendrá su cuenta y tachará producto por producto.
 *
 * Todo lo del alistado vive aquí: una sola definición de «por alistar».
 */

/** ¿El HUB ya tiene las columnas del alistado? (actualizar.php pendiente). */
function alistado_listo(): bool
{
    return tabla_existe('pedidos') && columna_existe('pedidos', 'alistado_en')
        && columna_existe('pedidos', 'despacho_veces');
}

/** La condición en SQL. `$a` es el alias de `pedidos`. */
function sql_por_alistar(string $a = 'pe'): string
{
    return "$a.anulado_en IS NULL AND $a.despacho_veces > 0 AND $a.alistado_en IS NULL";
}

/**
 * El país que mira quien alista. Almacén ve todo su país (no tiene nada
 * «suyo»); quien cruza países (Dirección, Desarrollador) ve todos.
 * → [sql, parámetros]
 */
function alistar_ambito(string $a = 'pe', ?array $u = null): array
{
    $u ??= yo();
    if (!$u) return ['1 = 0', []];
    if (cruza_paises($u)) return ['1 = 1', []];
    return ["$a.pais_id = ?", [(int)$u['pais_id']]];
}

/** Los pedidos por alistar, el que salió primero arriba. */
function pedidos_por_alistar(?array $u = null, int $tope = 200): array
{
    if (!alistado_listo()) return [];
    [$amb, $par] = alistar_ambito('pe', $u);
    return todas(
        'SELECT pe.*, c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos
           FROM pedidos pe JOIN clientes c ON c.id = pe.cliente_id
          WHERE ' . sql_por_alistar('pe') . ' AND ' . $amb . '
          ORDER BY CASE WHEN pe.entrega_fecha IS NULL THEN 1 ELSE 0 END,
                   pe.entrega_fecha ASC, pe.despacho_en ASC, pe.id ASC
          LIMIT ' . (int)$tope, $par);
}

/** Cuántos hay por alistar (el chip del menú y el Inicio). Misma consulta. */
function pedidos_por_alistar_n(?array $u = null): int
{
    if (!alistado_listo()) return 0;
    [$amb, $par] = alistar_ambito('pe', $u);
    return (int) valor('SELECT COUNT(*) FROM pedidos pe WHERE ' . sql_por_alistar('pe') . ' AND ' . $amb, $par);
}

/** TODO lo que Almacén tiene por alistar: pedidos y garantías aprobadas (3i). El chip y el Inicio. */
function alistar_pendientes_n(?array $u = null): int
{
    return pedidos_por_alistar_n($u) + (function_exists('garantias_por_alistar_n') ? garantias_por_alistar_n($u) : 0);
}

/**
 * Lo alistado hoy, lo último arriba: para ver la foto o deshacer. Con los que
 * se ANULARON después de alistarlos (la caja hay que desarmarla) y los que el
 * asesor VOLVIÓ A MANDAR (pueden haber cambiado las indicaciones).
 * Solo los que tienen foto: los que salieron antes de la 3g no se alistaron aquí.
 */
function pedidos_alistados_hoy(?array $u = null): array
{
    if (!alistado_listo()) return [];
    [$amb, $par] = alistar_ambito('pe', $u);
    $par = array_merge([date('Y-m-d') . ' 00:00:00'], $par);
    return todas(
        'SELECT pe.id, pe.codigo, pe.alistado_en, pe.alistado_quien_id, pe.alistado_foto,
                pe.anulado_en, pe.despacho_en,
                c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos
           FROM pedidos pe JOIN clientes c ON c.id = pe.cliente_id
          WHERE pe.alistado_en >= ? AND pe.alistado_foto IS NOT NULL AND ' . $amb . '
          ORDER BY pe.alistado_en DESC LIMIT 100', $par);
}

/**
 * LO QUE YA HABÍA SALIDO ANTES DE LA 3g no entra en «Por alistar»: se da por
 * alistado (sin foto ni quién) la primera vez que corre la actualización.
 * Sin esto, cada venta mandada a despacho desde el primer día —las ya
 * entregadas también— le llegaba a Almacén como pendiente (auditoría del 3g).
 * Una sola vez: la marca `alistar_arranque` lo impide después.
 */
function alistado_arranque(): string
{
    if (!alistado_listo() || !tabla_existe('ajustes')) return '';
    if ((string) ajuste('alistar_arranque', '') === '1') return '';
    $n = q('UPDATE pedidos SET alistado_en = COALESCE(despacho_en, creado_en)
             WHERE despacho_veces > 0 AND alistado_en IS NULL')->rowCount();
    guardar_ajuste_tecnico('alistar_arranque', '1', 'Lo mandado a despacho antes de la 3g ya no se alista');
    ajustes_olvidar();
    return $n ? "$n pedido(s) que ya habían salido no entran en «Por alistar»" : '';
}

/** ¿A esta persona se le pinta el contador? A quien alista y no vende: Almacén. */
function le_avisamos_de_alistar(?array $u = null): bool
{
    $u ??= yo();
    return $u !== null && puede_el($u, 'pedidos.alistar') && !puede_el($u, 'pedidos.crear');
}

/**
 * MARCA EL PEDIDO COMO ALISTADO: con quién lo alistó y la foto (ya guardada,
 * ver voucher_del_formulario()). → ['ok', 'error']
 */
function pedido_alistar(int $pedido_id, ?int $quien_id, ?string $foto): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e];
    if (!alistado_listo()) return $mal('Falta terminar la actualización.');
    $p = una('SELECT id, pais_id, anulado_en, despacho_veces, alistado_en FROM pedidos WHERE id = ?', [$pedido_id]);
    $u = yo();
    if (!$p || !$u || (!cruza_paises($u) && (int)$p['pais_id'] !== (int)$u['pais_id'])) return $mal('Ese pedido no existe.');
    if ($p['anulado_en'] !== null) return $mal('Ese pedido se anuló: ya no hay que alistarlo.');
    if ((int)$p['despacho_veces'] === 0) return $mal('Ese pedido todavía no se mandó a despacho.');
    if ($p['alistado_en'] !== null) return $mal('Ese pedido ya estaba alistado.');
    if (!$quien_id || !lista_valida('equipo_despacho', $quien_id, (int)$p['pais_id'])) return $mal('Elige quién lo alistó.');
    if ($foto === null || $foto === '' || ruta_voucher($foto) === '') return $mal('Falta la foto de lo alistado.');

    /* Solo si sigue por alistar: dos personas pulsando a la vez no lo marcan dos veces. */
    $st = q('UPDATE pedidos SET alistado_en = ?, alistado_por = ?, alistado_quien_id = ?, alistado_foto = ?
              WHERE id = ? AND alistado_en IS NULL AND anulado_en IS NULL',
            [date('Y-m-d H:i:s'), (int)$u['id'], $quien_id, $foto, $pedido_id]);
    if ($st->rowCount() !== 1) return $mal('Ese pedido ya estaba alistado.');
    /* Con la foto: si alguien deshace y vuelve a marcar, la primera sigue a la vista. */
    bitacora('pedido.alistado', 'pedido', $pedido_id, ['quien' => lista_texto($quien_id), 'foto' => $foto]);
    pedido_evento($pedido_id, 'alistado', 'Almacén lo alistó' . (lista_texto($quien_id) !== '' ? ' (' . lista_texto($quien_id) . ')' : ''));
    /* La etiqueta pasa a «Alistado» (5b). */
    pedido_estado_auto($pedido_id);
    return ['ok' => true, 'error' => ''];
}

/**
 * Deshace el alistado (se equivocó de pedido o de foto): vuelve a la lista.
 * SOLO EL MISMO DÍA y solo si sigue vivo: lo de ayer ya salió, y deshacerlo
 * borraría la prueba de qué se mandó. La foto de antes queda en la bitácora.
 */
function pedido_alistado_deshacer(int $pedido_id): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e];
    if (!alistado_listo()) return $mal('Falta terminar la actualización.');
    $p = una('SELECT id, pais_id, anulado_en, alistado_en, alistado_por, alistado_quien_id, alistado_foto FROM pedidos WHERE id = ?', [$pedido_id]);
    $u = yo();
    if (!$p || !$u || (!cruza_paises($u) && (int)$p['pais_id'] !== (int)$u['pais_id'])) return $mal('Ese pedido no existe.');
    if ($p['alistado_en'] === null || $p['alistado_foto'] === null) return $mal('Ese pedido no estaba alistado.');
    if ($p['anulado_en'] !== null) return $mal('Ese pedido se anuló: ya no vuelve a la lista.');
    /* Lo entregado ya no se desarma (3j): primero se deshace la entrega. */
    if (entrega_lista() && valor('SELECT entregado_en FROM pedidos WHERE id = ?', [$pedido_id]) !== null) {
        return $mal('Ese pedido ya se entregó: primero deshaz «entregado».');
    }
    $hoy = date('Y-m-d') . ' 00:00:00';
    $st = q('UPDATE pedidos SET alistado_en = NULL, alistado_por = NULL, alistado_quien_id = NULL, alistado_foto = NULL
              WHERE id = ? AND alistado_en >= ? AND alistado_foto IS NOT NULL AND anulado_en IS NULL', [$pedido_id, $hoy]);
    if ($st->rowCount() !== 1) return $mal('Solo se deshace el mismo día.');
    bitacora('pedido.alistado_deshecho', 'pedido', $pedido_id, [
        'quien' => lista_texto($p['alistado_quien_id'] !== null ? (int)$p['alistado_quien_id'] : null),
        'foto' => $p['alistado_foto'], 'por' => $p['alistado_por'], 'en' => $p['alistado_en']]);
    pedido_evento($pedido_id, 'alistado_deshecho', 'Se deshizo «alistado»');
    pedido_estado_auto($pedido_id);
    return ['ok' => true, 'error' => ''];
}

/** Quién y cuándo: «por Juan · hace 5 min», o '' si no está alistado. */
function pedido_alistado_detalle(array $p): string
{
    if (empty($p['alistado_en'])) return '';
    $quien = lista_texto(isset($p['alistado_quien_id']) ? (int)$p['alistado_quien_id'] : null);
    return ($quien !== '' ? 'por ' . $quien . ' · ' : '') . hace((string)$p['alistado_en']);
}

/* ─────────────────────────  ENTREGADO (3j)  ─────────────────────────
   Lo pidió el usuario el 2026-09-28: el estado cambia «cuando despacho
   entregue». Quien alista (Almacén, o Administración) marca ENTREGADO con la
   FOTO DE LA ENTREGA (o del envío en la agencia). Esa foto la ve el asesor en
   «Enviados». Todo lo de la entrega vive aquí. */

/** ¿El HUB ya tiene las columnas de la entrega? */
function entrega_lista(): bool
{
    return alistado_listo() && columna_existe('pedidos', 'entregado_en');
}

/** Lo alistado que todavía no se entregó. `$a` es el alias de `pedidos`. */
function sql_por_entregar(string $a = 'pe'): string
{
    return "$a.anulado_en IS NULL AND $a.despacho_veces > 0 AND $a.alistado_en IS NOT NULL AND $a.entregado_en IS NULL";
}

/** Los pedidos por entregar, el que salió primero arriba. */
function pedidos_por_entregar(?array $u = null, int $tope = 100): array
{
    if (!entrega_lista()) return [];
    [$amb, $par] = alistar_ambito('pe', $u);
    return todas(
        'SELECT pe.*, c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos
           FROM pedidos pe JOIN clientes c ON c.id = pe.cliente_id
          WHERE ' . sql_por_entregar('pe') . ' AND ' . $amb . '
          ORDER BY pe.despacho_en ASC, pe.id ASC LIMIT ' . (int)$tope, $par);
}

function pedidos_por_entregar_n(?array $u = null): int
{
    if (!entrega_lista()) return 0;
    [$amb, $par] = alistar_ambito('pe', $u);
    return (int) valor('SELECT COUNT(*) FROM pedidos pe WHERE ' . sql_por_entregar('pe') . ' AND ' . $amb, $par);
}

/** Lo entregado hoy, lo último arriba: para ver la foto o deshacer. */
function pedidos_entregados_hoy(?array $u = null): array
{
    if (!entrega_lista()) return [];
    [$amb, $par] = alistar_ambito('pe', $u);
    $par = array_merge([date('Y-m-d') . ' 00:00:00'], $par);
    return todas(
        'SELECT pe.id, pe.codigo, pe.entregado_en, pe.entregado_foto, pe.anulado_en,
                c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos
           FROM pedidos pe JOIN clientes c ON c.id = pe.cliente_id
          WHERE pe.entregado_en >= ? AND pe.entregado_foto IS NOT NULL AND ' . $amb . '
          ORDER BY pe.entregado_en DESC LIMIT 100', $par);
}

/**
 * MARCA EL PEDIDO COMO ENTREGADO, con la foto (ya guardada). El estado pasa a
 * «Entregado» solo. → ['ok', 'error']
 */
function pedido_entregado_marcar(int $pedido_id, ?string $foto): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e];
    if (!entrega_lista()) return $mal('Falta terminar la actualización.');
    $p = una('SELECT id, pais_id, anulado_en, despacho_veces, alistado_en, entregado_en FROM pedidos WHERE id = ?', [$pedido_id]);
    $u = yo();
    if (!$p || !$u || (!cruza_paises($u) && (int)$p['pais_id'] !== (int)$u['pais_id'])) return $mal('Ese pedido no existe.');
    if ($p['anulado_en'] !== null) return $mal('Ese pedido se anuló.');
    if ((int)$p['despacho_veces'] === 0) return $mal('Ese pedido todavía no se mandó a despacho.');
    if ($p['alistado_en'] === null) return $mal('Primero hay que alistarlo.');
    if ($p['entregado_en'] !== null) return $mal('Ese pedido ya estaba entregado.');
    if ($foto === null || $foto === '' || ruta_voucher($foto) === '') return $mal('Falta la foto de la entrega.');

    $st = q('UPDATE pedidos SET entregado_en = ?, entregado_por = ?, entregado_foto = ?
              WHERE id = ? AND entregado_en IS NULL AND anulado_en IS NULL AND alistado_en IS NOT NULL',
            [date('Y-m-d H:i:s'), (int)$u['id'], $foto, $pedido_id]);
    if ($st->rowCount() !== 1) return $mal('Ese pedido ya estaba entregado.');
    pedido_evento($pedido_id, 'entregado', 'Se entregó');
    bitacora('pedido.entregado', 'pedido', $pedido_id, ['foto' => $foto]);
    pedido_estado_auto($pedido_id);
    return ['ok' => true, 'error' => ''];
}

/** Deshace la entrega (foto o pedido equivocado). SOLO EL MISMO DÍA. */
function pedido_entregado_deshacer(int $pedido_id): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e];
    if (!entrega_lista()) return $mal('Falta terminar la actualización.');
    $p = una('SELECT id, pais_id, anulado_en, entregado_en, entregado_foto, entregado_por FROM pedidos WHERE id = ?', [$pedido_id]);
    $u = yo();
    if (!$p || !$u || (!cruza_paises($u) && (int)$p['pais_id'] !== (int)$u['pais_id'])) return $mal('Ese pedido no existe.');
    if ($p['entregado_en'] === null || $p['entregado_foto'] === null) return $mal('Ese pedido no estaba entregado.');
    if ($p['anulado_en'] !== null) return $mal('Ese pedido se anuló.');
    $st = q('UPDATE pedidos SET entregado_en = NULL, entregado_por = NULL, entregado_foto = NULL
              WHERE id = ? AND entregado_en >= ? AND entregado_foto IS NOT NULL AND anulado_en IS NULL',
            [$pedido_id, date('Y-m-d') . ' 00:00:00']);
    if ($st->rowCount() !== 1) return $mal('Solo se deshace el mismo día.');
    pedido_evento($pedido_id, 'entregado_deshecho', 'Se deshizo «entregado»');
    bitacora('pedido.entregado_deshecho', 'pedido', $pedido_id,
             ['foto' => $p['entregado_foto'], 'por' => $p['entregado_por'], 'en' => $p['entregado_en']]);
    pedido_estado_auto($pedido_id);
    return ['ok' => true, 'error' => ''];
}

/**
 * LO QUE YA HABÍA SALIDO ANTES DE LA 3j: lo que estaba en «Entregado» guarda
 * cuándo (el día de ese cambio); y lo que salió hace más de una semana se da
 * por entregado, sin foto, para que «Por entregar» no nazca con meses de
 * pedidos viejos. Una sola vez (marca `entrega_arranque`). Después, todos los
 * pedidos vivos toman el estado que les toca.
 */
function entrega_arranque(): string
{
    if (!entrega_lista() || !tabla_existe('ajustes')) return '';
    if ((string) ajuste('entrega_arranque', '') !== '') return '';
    tiempo_extra(0);
    $ahora = date('Y-m-d H:i:s');
    $ent = (int) valor("SELECT id FROM pedido_estados WHERE clave = 'entregado'");
    $n1 = 0; $n2 = 0; $n3 = 0;
    if ($ent) {
        $n1 = q("UPDATE pedidos SET entregado_en = COALESCE(
                    (SELECT MAX(ev.creado_en) FROM pedido_eventos ev WHERE ev.pedido_id = pedidos.id AND ev.tipo = 'estado'),
                    despacho_en, creado_en)
                  WHERE estado_id = ? AND entregado_en IS NULL", [$ent])->rowCount();
    }
    $n2 = q('UPDATE pedidos SET entregado_en = despacho_en
              WHERE entregado_en IS NULL AND anulado_en IS NULL AND despacho_veces > 0
                AND despacho_en IS NOT NULL AND despacho_en < ?', [date('Y-m-d H:i:s', strtotime('-7 days'))])->rowCount();
    /* Los lotes que ya llegaron quedan listos para entrega: su mercadería ya
       se estaba repartiendo. */
    if (columna_existe('lotes', 'listo_en')) {
        q("UPDATE lotes SET listo_en = COALESCE(fecha_llegada_real, creado_en) WHERE estado = 'recibido' AND listo_en IS NULL");
    }
    /* Todos los pedidos vivos, con el estado que les toca. Puede tardar: la
       marca va AL FINAL, así que si se corta, la próxima vez sigue (lo ya
       hecho no cambia otra vez). */
    foreach (todas('SELECT id FROM pedidos WHERE anulado_en IS NULL') as $pp) {
        if (pedido_estado_auto((int)$pp['id'], 'al actualizar') !== '') $n3++;
    }
    guardar_ajuste_tecnico('entrega_arranque', $ahora, 'Desde cuándo el estado de los pedidos cambia solo. No tocar.');
    ajustes_olvidar();
    $partes = [];
    if ($n1 + $n2) $partes[] = ($n1 + $n2) . ' pedido(s) que ya habían salido quedan entregados';
    if ($n3) $partes[] = "$n3 pedido(s) toman el estado que les toca";
    return implode('; ', $partes);
}

/* ─────────────────────────  ENVIADOS (3j)  ─────────────────────────
   Lo pidió el usuario el 2026-09-28: que en «Por despachar» el asesor vea lo
   que ya mandó, con filtro de fecha, Lima o provincia y un buscador por
   cliente, producto y DNI, con todas las fotos: lo alistado, los vouchers, el
   rótulo y la entrega. */

/**
 * Los filtros de «Enviados», limpios. Por defecto, los últimos 30 días.
 * → ['desde' => Y-m-d, 'hasta' => Y-m-d, 'z' => ''|lima|provincia, 'q' => texto]
 */
function enviados_filtros(array $g): array
{
    $f = fn($x) => is_string($x) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $x) && strtotime($x) ? $x : '';
    $desde = $f($g['ed'] ?? '') ?: date('Y-m-d', strtotime('-30 days'));
    $hasta = $f($g['eh'] ?? '') ?: date('Y-m-d');
    if ($hasta < $desde) [$desde, $hasta] = [$hasta, $desde];
    $z = in_array((string)($g['ez'] ?? ''), ['lima', 'provincia'], true) ? (string)$g['ez'] : '';
    $q = mb_substr(trim((string)($g['eq'] ?? '')), 0, 80);
    return ['desde' => $desde, 'hasta' => $hasta, 'z' => $z, 'q' => $q];
}

/** Lo enviado por quien mira (su ámbito de despacho), lo último arriba. */
function pedidos_enviados(array $fl, ?array $u = null, int $tope = 100): array
{
    if (!columna_existe('pedidos', 'despacho_veces')) return [];
    $u ??= yo();
    [$amb, $par] = filtro_ambito_despacho('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id', $u);
    $col_fecha = columna_existe('pedidos', 'despacho_primero_en') ? 'COALESCE(pe.despacho_primero_en, pe.despacho_en)' : 'pe.despacho_en';
    $where = ['pe.despacho_veces > 0', $amb, "$col_fecha >= ?", "$col_fecha < ?"];
    $par[] = $fl['desde'] . ' 00:00:00';
    $par[] = date('Y-m-d', strtotime($fl['hasta'] . ' +1 day')) . ' 00:00:00';
    if ($fl['z'] !== '' && columna_existe('pedidos', 'ubigeo_id')) {
        $where[] = "pe.entrega = 'envio' AND pe.ubigeo_id IS NOT NULL AND " . ($fl['z'] === 'lima' ? '' : 'NOT ') . sql_pedido_es_lima('pe');
    }
    if ($fl['q'] !== '') {
        $like = '%' . like_seguro($fl['q']) . '%';
        $where[] = '(pe.codigo LIKE ? OR c.nombre LIKE ? OR c.apellidos LIKE ? OR c.documento LIKE ?
                     OR EXISTS (SELECT 1 FROM pedido_lineas xl WHERE xl.pedido_id = pe.id AND xl.descripcion LIKE ?))';
        array_push($par, $like, $like, $like, $like, $like);
    }
    $sel_ali = alistado_listo() ? 'pe.alistado_en, pe.alistado_foto' : 'NULL AS alistado_en, NULL AS alistado_foto';
    $sel_ent = entrega_lista() ? 'pe.entregado_en, pe.entregado_foto' : 'NULL AS entregado_en, NULL AS entregado_foto';
    return todas("SELECT pe.id, pe.codigo, pe.entrega, pe.anulado_en, pe.despacho_veces, $col_fecha AS enviado_en,
                         " . (columna_existe('pedidos', 'recojo_agencia') ? 'pe.recojo_agencia' : '0 AS recojo_agencia') . ",
                         " . (columna_existe('pedidos', 'ubigeo_id') ? 'pe.ubigeo_id' : 'NULL AS ubigeo_id') . ",
                         $sel_ali, $sel_ent,
                         es.nombre AS estado_nombre, es.color AS estado_color,
                         c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos, c.documento
                    FROM pedidos pe
                    JOIN clientes c ON c.id = pe.cliente_id
                    JOIN pedido_estados es ON es.id = pe.estado_id
                    LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
                   WHERE " . implode(' AND ', $where) . "
                   ORDER BY enviado_en DESC, pe.id DESC LIMIT " . (int)$tope, $par);
}

/** Los vouchers de unos pedidos, de una vez → [pedido_id => [pago_id, …]]. */
function pedidos_vouchers_de(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $en = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    foreach (todas("SELECT id, pedido_id FROM pagos
                     WHERE pedido_id IN ($en) AND anulado = 0 AND voucher IS NOT NULL AND voucher <> ''
                     ORDER BY id", $ids) as $pg) {
        $out[(int)$pg['pedido_id']][] = (int)$pg['id'];
    }
    return $out;
}
