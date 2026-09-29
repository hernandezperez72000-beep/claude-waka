<?php
declare(strict_types=1);

/**
 * EL DESCUENTO DEL ASESOR (3d, 2026-09-25).
 *
 * Decidido por el usuario en tarjetas:
 *   · se escribe EN SOLES, sobre el total de la venta (así se dice con el
 *     cliente: «te dejo 20 soles»); la pantalla enseña al lado qué % es;
 *   · hay un TOPE en % que se pone en Configuración › Descuentos; hasta el tope
 *     la venta sale sola, por encima queda ESPERANDO que Administración lo
 *     apruebe, y a Administración le suena el aviso;
 *   · el MOTIVO es obligatorio.
 *
 * CÓMO VIVE EN EL PEDIDO — una sola cifra de total.
 * `descuento_centimos` es el descuento que resta del total, y lo resta DESDE
 * QUE SE REGISTRA, también mientras espera aprobación: el asesor le dijo al
 * cliente un precio y el cliente paga ese precio. Si Administración lo
 * RECHAZA, el descuento se quita, el total sube y queda saldo por cobrar (y si
 * el pedido estaba «Pagado», vuelve atrás solo, como con un pago devuelto).
 * Mientras espera, la venta NO SALE a despacho ni se le emite comprobante:
 * lo que cobra el repartidor y lo que dice la boleta dependen de la respuesta.
 *
 * Dos cifras de total para el mismo pedido —«con» y «sin» el descuento que
 * espera— es el fallo que más veces ha mordido a este proyecto, y por eso no
 * existen: el total es uno, y el estado dice si se puede fiar de él.
 *
 * `descuento_pedido_centimos` guarda lo que PIDIÓ el asesor, que sigue ahí
 * aunque se rechace: sin eso, un rechazo borraba el rastro de lo que se pidió.
 *
 * Estados (`descuento_estado`): '' sin descuento · 'ok' vale · 'pendiente'
 * espera a Administración · 'rechazado' Administración dijo que no.
 *
 * 3h.1 (usuario 2026-09-28): «que no requiera permiso de adm, pero que quede
 * registrado». La aprobación pasa a ser un interruptor de Configuración ›
 * Descuentos, APAGADO de fábrica: con él apagado todo descuento nace 'ok' y
 * queda igual de anotado (monto, motivo, quién, la línea de tiempo y la
 * bitácora). Lo que ya esperaba sigue esperando hasta que se conteste.
 *
 * NO HAY DESCUENTO EN LIQUIDACIÓN: el precio ya es el descuento (la misma
 * regla que el cashback, usuario 2026-09-10).
 */

const DESCUENTO_MOTIVO_MAX = 160;

/** El tope en %, de Configuración. 0 = cualquier descuento pide aprobación. */
function descuento_tope_pct(): int
{
    return max(0, min(100, (int) ajuste('descuento_tope_pct', 5)));
}

/** ¿Un descuento por encima del tope espera a Administración? (Configuración) */
function descuento_pide_aprobacion(): bool
{
    return (bool)(int) ajuste('descuento_pide_aprobacion', 0);
}

/** ¿Se puede usar cashback y descuento en la misma venta? (Configuración) */
function descuento_con_cashback(): bool
{
    return (bool)(int) ajuste('cashback_con_descuento', 0);
}

/**
 * ¿ESTE descuento pasa del tope? El % se mide sobre lo que suman los productos
 * (el subtotal): es el precio del que se está rebajando. En enteros, sin
 * redondeos: 5 % de S/ 379 son S/ 18.95 y S/ 18.95 no pasa.
 */
function descuento_pasa_tope(int $descuento, int $subtotal): bool
{
    if ($descuento <= 0) return false;
    if ($subtotal <= 0) return true;
    return $descuento * 100 > $subtotal * descuento_tope_pct();
}

/** El % que es, para enseñarlo: «4.9 %». */
function descuento_pct_texto(int $descuento, int $subtotal): string
{
    if ($subtotal <= 0 || $descuento <= 0) return '0 %';
    $x = round($descuento * 100 / $subtotal, 1);
    return rtrim(rtrim(number_format($x, 1, '.', ''), '0'), '.') . ' %';
}

/** ¿Puede esta persona aprobar descuentos? */
function puede_aprobar_descuentos(?array $u = null): bool
{
    $u ??= yo();
    return $u !== null && puede_el($u, 'descuentos.aprobar');
}

/** ¿Las columnas están? (HUB con el ZIP subido y sin actualizar.php) */
function descuentos_disponibles(): bool
{
    static $s = null;
    return $s ??= columna_existe('pedidos', 'descuento_estado');
}

/**
 * COMPRUEBA EL DESCUENTO DE UNA VENTA NUEVA. Devuelve '' o la frase.
 * $cashback es el que se va a usar en la misma venta.
 */
function descuento_problema(int $descuento, string $motivo, int $subtotal, int $cashback, string $tipo): string
{
    if ($descuento === 0 && trim($motivo) === '') return '';
    if ($descuento < 0) return 'El descuento no se entiende. Escríbelo en soles, así: 20 o 20.50';
    if ($descuento === 0) return '';
    if (!descuentos_disponibles()) {
        return 'Todavía no se pueden hacer descuentos: falta terminar la actualización. Avisa a Administración.';
    }
    if (!venta_da_cashback($tipo)) {
        return 'En una venta de liquidación no se hace descuento: el precio ya es el descuento.';
    }
    $m = trim($motivo);
    if ($m === '') return 'Escribe el motivo del descuento: «cliente frecuente», «compra 3 unidades»…';
    if (mb_strlen($m) < 3) return 'El motivo del descuento es muy corto: di por qué, en pocas palabras.';
    if ($cashback > 0 && !descuento_con_cashback()) {
        return 'En la misma venta no se puede usar cashback y hacer descuento. Quita uno de los dos.';
    }
    if ($descuento + $cashback >= $subtotal) {
        return 'El descuento no puede dejar los productos en cero: el máximo es '
             . soles(max(0, $subtotal - $cashback - 1)) . '.';
    }
    return '';
}

/**
 * El estado con el que nace: 'ok' si la aprobación está apagada, si está
 * dentro del tope o si quien registra puede aprobarlo él mismo; si no,
 * 'pendiente'. Sin descuento, ''.
 */
function descuento_estado_inicial(int $descuento, int $subtotal, ?array $u = null): string
{
    if ($descuento <= 0) return '';
    if (!descuento_pide_aprobacion()) return 'ok';
    if (!descuento_pasa_tope($descuento, $subtotal)) return 'ok';
    return puede_aprobar_descuentos($u) ? 'ok' : 'pendiente';
}

/** ¿Este pedido tiene un descuento esperando aprobación? */
function pedido_descuento_pendiente(array $p): bool
{
    /* Hay pantallas que pasan una fila recortada (la bandeja de facturación,
       la de despacho): si no trae la columna, se pregunta a la base. Sin esto,
       la frase diría «ya sale» y el SQL «no sale» para el mismo pedido. */
    if (!array_key_exists('descuento_estado', $p)) {
        if (empty($p['id']) || !descuentos_disponibles()) return false;
        return (string) valor('SELECT descuento_estado FROM pedidos WHERE id = ?', [(int)$p['id']]) === 'pendiente';
    }
    return (string)$p['descuento_estado'] === 'pendiente';
}

/**
 * APROBAR o RECHAZAR. La misma función para los dos, con la fila bloqueada:
 * dos personas pulsando a la vez no pueden aprobar y rechazar el mismo.
 * → ['ok', 'error']
 */
function descuento_resolver(int $pedido_id, bool $aprueba, string $nota = '', ?array $u = null): array
{
    $u ??= yo();
    $mal = fn(string $e) => ['ok' => false, 'error' => $e];
    if (!descuentos_disponibles()) return $mal('Falta terminar la actualización. Avisa a Administración.');
    if (!puede_aprobar_descuentos($u)) return $mal('Aprobar descuentos no es para tu perfil.');
    $nota = trim(mb_substr($nota, 0, DESCUENTO_MOTIVO_MAX));

    $r = en_transaccion(function () use ($pedido_id, $aprueba, $nota, $u, $mal) {
        bloquear_fila('pedidos', $pedido_id);
        $p = pedido_de($pedido_id);
        if (!$p) return $mal('Ese pedido no existe.');
        /* Contestar es ESCRIBIR: el ámbito «equipo» es de solo lectura en todo el HUB. */
        if (!puedo_editar((int)$p['asesor_id'], (int)$p['pais_id'])) return $mal('Esta venta no te toca contestarla.');
        if ((string)$p['estado'] === 'anulado') return $mal('Este pedido está anulado.');
        if (!pedido_descuento_pendiente($p)) {
            $ya = (string)($p['descuento_estado'] ?? '');
            return $mal($ya === 'ok' ? 'Ese descuento ya estaba aprobado.'
                     : ($ya === 'rechazado' ? 'Ese descuento ya se rechazó.' : 'Este pedido no tiene un descuento esperando.'));
        }
        $monto = (int)$p['descuento_pedido_centimos'];
        $cambios = ['descuento_estado' => $aprueba ? 'ok' : 'rechazado',
                    'descuento_revisado_por' => (int)$u['id'],
                    'descuento_revisado_en'  => date('Y-m-d H:i:s'),
                    'descuento_nota'         => $nota !== '' ? $nota : null];
        if (!$aprueba) $cambios['descuento_centimos'] = 0;
        actualizar('pedidos', $pedido_id, $cambios);
        pedido_recalcular($pedido_id);
        if ($aprueba) {
            pedido_evento($pedido_id, 'descuento', 'Se aprobó el descuento de ' . soles($monto));
            bitacora('pedido.descuento_ok', 'pedido', $pedido_id, ['monto' => $monto]);
        } else {
            pedido_evento($pedido_id, 'descuento', 'Se rechazó el descuento de ' . soles($monto)
                          . ($nota !== '' ? ' · ' . $nota : '') . '. El total vuelve a subir.');
            bitacora('pedido.descuento_no', 'pedido', $pedido_id, ['monto' => $monto, 'nota' => $nota]);
        }
        return ['ok' => true, 'error' => ''];
    });
    if ($r['ok']) {
        /* El estado del cobro sigue al total nuevo: aprobado puede dejarlo
           cobrado al 100 %; rechazado puede sacarlo de «Pagado». Las mismas dos
           funciones que usan los pagos, fuera de la transacción como allí. */
        pedido_avanzar_si_esta_pagado($pedido_id);
        pedido_retroceder_si_debe($pedido_id);
    }
    return $r;
}

/** Los descuentos que esperan, los que esta persona puede ver. */
function descuentos_pendientes(?array $u = null, int $tope = 50): array
{
    $u ??= yo();
    if (!$u || !descuentos_disponibles() || !puede_aprobar_descuentos($u)) return [];
    [$amb, $par] = filtro_ambito('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id', $u);
    return todas("SELECT pe.id, pe.codigo, pe.subtotal_centimos, pe.total_centimos,
                         pe.descuento_pedido_centimos, pe.descuento_motivo, pe.creado_en,
                         c.nombre AS cliente, c.apellidos AS cliente_ap,
                         ua.nombre AS asesor, ua.apellidos AS asesor_ap
                    FROM pedidos pe
                    JOIN clientes c       ON c.id = pe.cliente_id
                    LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
                   WHERE pe.descuento_estado = 'pendiente' AND pe.anulado_en IS NULL AND $amb
                   ORDER BY pe.id
                   LIMIT " . max(1, $tope), $par);
}

function descuentos_pendientes_n(?array $u = null): int
{
    $u ??= yo();
    if (!$u || !descuentos_disponibles() || !puede_aprobar_descuentos($u)) return 0;
    [$amb, $par] = filtro_ambito('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id', $u);
    return (int) valor("SELECT COUNT(*) FROM pedidos pe LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
                         WHERE pe.descuento_estado = 'pendiente' AND pe.anulado_en IS NULL AND $amb", $par);
}

/**
 * PARA EL AVISO DE ADMINISTRACIÓN: los descuentos que se pidieron desde la
 * marca de agua (la de la bitácora, la misma del reclamo) y SIGUEN esperando.
 * → ['n', 'codigo', 'asesor']
 */
function descuentos_pedidos_desde(int $desde, int $marca, ?array $u = null): array
{
    $vacio = ['n' => 0, 'codigo' => '', 'asesor' => ''];
    $u ??= yo();
    if (!$u || $desde <= 0 || $marca <= $desde || !descuentos_disponibles() || !puede_aprobar_descuentos($u)) return $vacio;
    [$amb, $par] = filtro_ambito('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id', $u);
    $f = todas("SELECT pe.codigo, ub.nombre AS asesor
                  FROM bitacora b
                  JOIN pedidos pe       ON pe.id = b.entidad_id
                  LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
                  LEFT JOIN usuarios ub ON ub.id = b.usuario_id
                 WHERE b.id > ? AND b.id <= ? AND b.accion = 'pedido.descuento_pide' AND b.entidad = 'pedido'
                   AND pe.descuento_estado = 'pendiente' AND pe.anulado_en IS NULL
                   AND $amb
                 ORDER BY b.id DESC", array_merge([$desde, $marca], $par));
    if (!$f) return $vacio;
    return ['n' => count($f), 'codigo' => (string)$f[0]['codigo'], 'asesor' => (string)($f[0]['asesor'] ?? '')];
}

/**
 * PARA EL AVISO DEL ASESOR: la respuesta a SUS descuentos desde la marca.
 * → ['n', 'codigo', 'id', 'aprobado' (bool, del más nuevo)]
 */
function descuentos_resueltos_desde(int $desde, int $marca, ?array $u = null): array
{
    $vacio = ['n' => 0, 'codigo' => '', 'id' => 0, 'aprobado' => false];
    $u ??= yo();
    if (!$u || $desde <= 0 || $marca <= $desde || !descuentos_disponibles()) return $vacio;
    $f = todas("SELECT b.accion, pe.id, pe.codigo
                  FROM bitacora b
                  JOIN pedidos pe ON pe.id = b.entidad_id
                 WHERE b.id > ? AND b.id <= ? AND b.entidad = 'pedido'
                   AND b.accion IN ('pedido.descuento_ok', 'pedido.descuento_no')
                   AND pe.asesor_id = ?
                 ORDER BY b.id DESC", [$desde, $marca, (int)$u['id']]);
    if (!$f) return $vacio;
    return ['n' => count($f), 'codigo' => (string)$f[0]['codigo'], 'id' => (int)$f[0]['id'],
            'aprobado' => (string)$f[0]['accion'] === 'pedido.descuento_ok'];
}
