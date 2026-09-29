<?php
declare(strict_types=1);
seccion_activa('bonos');

/**
 * BONOS (módulo 5). El asesor ve «Mis bonos»: si clasifica a cada uno SIN
 * HACER CÁLCULOS (pedido explícito). Dirección y Administración ven «Cómo van
 * los bonos»: quién va ganando, cuánto se va a pagar, lo pagado y lo que
 * falta pagar. Nada dice «ya la hiciste» si depende de un puesto.
 */
if (!bonos_listo()) {
    pagina('pedidos/sinmigrar', [], ['titulo' => 'Bonos', 'subtitulo' => 'Disponible en cuanto se termine la actualización']);
    return;
}
bonos_cerrar_de_paso();
$u = yo();
$pais = (int)$u['pais_id'];
$hoy = date('Y-m-d');

if (puede('bonos.seguir') && pedir('v', 'get') !== 'mios') {
    /* Quien cruza países (Dirección) elige de cuál mira, como en Configuración. */
    $paises = cruza_paises($u) ? todas('SELECT id, nombre FROM paises ORDER BY id') : [];
    if ($paises) {
        $p = (int) pedir_int('p', 'get');
        if ($p && valor('SELECT 1 FROM paises WHERE id = ?', [$p])) $pais = $p;
    }
    $filas = [];
    $total = 0;
    foreach (bonos_del_pais($pais) as $b) {
        [$ini, $fin] = bono_periodo((string)$b['periodo'], $hoy);
        $ev = (int)$b['activo'] === 1 ? bono_evaluar($b, $ini, $fin, $hoy) : null;
        $lider = null; $cerca = null;
        if ($ev) {
            $ordenados = $ev['filas'];
            uasort($ordenados, fn($a, $b2) => [!empty($b2['gana']), (float)$b2['pct'], (int)$b2['valor']] <=> [!empty($a['gana']), (float)$a['pct'], (int)$a['valor']]);
            foreach ($ordenados as $f) { if (!empty($f['gana'])) { $lider = $f; break; } }
            if (!$lider) $lider = reset($ordenados) ?: null;
            foreach ($ordenados as $f) { if (empty($f['gana']) && (int)$f['valor'] > 0) { $cerca = $f; break; } }
            $total += (int)$ev['se_paga'];
        }
        $filas[] = ['bono' => $b, 'inicio' => $ini, 'fin' => $fin, 'ev' => $ev, 'lider' => $lider, 'cerca' => $cerca];
    }
    pagina('bonos/panel', [
        'filas' => $filas, 'total' => $total,
        'caceria_hoy' => caceria_de($pais, $hoy),
        'caceria_bono' => bono_tipo($pais, 'caceria'),
        'por_pagar' => puede('bonos.pagar') ? bonos_por_pagar($pais) : [],
        'pagado_mes' => bonos_pagado_mes($pais, date('Y-m')),
        'pagados_hoy' => puede('bonos.pagar') ? todas('SELECT br.id, br.premio_centimos, b.nombre AS bono_nombre, u.nombre AS u_nombre, eq.nombre AS eq_nombre
                                                          FROM bono_resultados br JOIN bonos b ON b.id = br.bono_id
                                                          LEFT JOIN usuarios u ON u.id = br.usuario_id LEFT JOIN equipos eq ON eq.id = br.equipo_id
                                                         WHERE br.pais_id = ? AND br.pagado = 1 AND br.pagado_en >= ? ORDER BY br.pagado_en DESC LIMIT 30',
                                                       [$pais, $hoy . ' 00:00:00']) : [],
        'es_asesor' => puede('pedidos.crear') && (string)($u['rol'] ?? '') === 'asesor',
        'paises' => count($paises) > 1 ? $paises : [], 'pais' => $pais,
    ], ['titulo' => 'Cómo van los bonos', 'subtitulo' => 'Todo por lo cobrado · un bono ya cerrado no cambia']);
    return;
}

$mis = mis_bonos($u, $hoy);
pagina('bonos/mios', [
    'mis' => $mis,
    'garra' => metele_garra($u, $mis),
    'racha_hoy' => racha_dia((int)$u['id']),
    'racha' => racha_dias((int)$u['id']),
    'nivel' => nivel_de((int)$u['id'], $hoy),
    'resultados' => todas('SELECT br.*, b.nombre AS bono_nombre, b.tipo, b.periodo FROM bono_resultados br JOIN bonos b ON b.id = br.bono_id
                            WHERE br.usuario_id = ? AND br.periodo_fin >= ?
                            ORDER BY br.periodo_fin DESC, b.orden LIMIT 20',
                          [(int)$u['id'], date('Y-m-d', strtotime('-45 days'))]),
    'montos_bloqueado' => (string) ajuste('logro_montos_bloqueado', '0') === '1',
], ['titulo' => 'Mis bonos', 'sin_titulo' => true]);
