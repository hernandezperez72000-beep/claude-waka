<?php
declare(strict_types=1);
seccion_activa('config');

/**
 * CONFIGURACIÓN › BONOS (módulo 5). Cada bono con su regla, su nombre, su
 * nombre anterior y su interruptor. Nacen APAGADOS (tarjeta del 2026-09-29):
 * se revisan los montos y los niveles de cada asesor, y se encienden. Lo ya
 * cerrado no cambia: cada cierre guardó sus reglas.
 */
if (!bonos_listo()) {
    pagina('pedidos/sinmigrar', [], ['titulo' => 'Bonos', 'subtitulo' => 'Disponible en cuanto se termine la actualización']);
    return;
}
$u = yo();
/* Quien cruza países (Dirección) elige de cuál son los bonos que mira. */
$paises = cruza_paises($u) ? todas('SELECT id, nombre FROM paises ORDER BY id') : [];
$pais = (int) (cruza_paises($u) ? (pedir_int('p', 'get') ?: pedir_int('p') ?: (int)$u['pais_id']) : (int)$u['pais_id']);
if (cruza_paises($u) && !valor('SELECT 1 FROM paises WHERE id = ?', [$pais])) $pais = (int)$u['pais_id'];
$qp = cruza_paises($u) ? '&p=' . $pais : '';
$errores = [];
$id = (int) (pedir_int('id', 'get') ?: pedir_int('id'));
$bono = $id ? bono_de($id) : null;
if ($id && (!$bono || (int)$bono['pais_id'] !== $pais)) cortar(404, 'Ese bono no existe');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = pedir('accion');
    if ($bono && $accion === 'encender') {
        $r = bono_encender($id, pedir('on') === '1');
        avisar($r['ok'] ? 'ok' : 'error', $r['ok'] ? (pedir('on') === '1' ? bono_nombre($bono) . ' ya cuenta, desde el período que va.' : bono_nombre($bono) . ' quedó apagado: lo que va de este período ya no cuenta. Lo ya terminado se paga igual.') : $r['error']);
        $q = ltrim($qp . (pedir('volver') === 'ficha' ? '&id=' . $id : ''), '&');
        ir('/configuracion/bonos' . ($q !== '' ? '?' . $q : ''));
    }
    if ($bono && $accion === 'guardar') {
        $r = bono_guardar($id, $_POST);
        if ($r['ok']) { avisar('ok', 'Guardado. Lo ya cerrado no cambia.'); ir('/configuracion/bonos?id=' . $id . $qp); }
        $errores[] = $r['error'];
    }
    if ($accion === 'ajustes' && puede('bonos.configurar')) {
        $pct = pedir_int('poco_pct'); $hrs = pedir_int('cierre_horas'); $desde = pedir_int('aviso_desde');
        if ($pct === null || $pct < 1 || $pct > 99) $errores[] = '«Dale con todo» va de 1 a 99 %.';
        elseif ($hrs === null || $hrs < 0 || $hrs > 72) $errores[] = 'Las horas de espera van de 0 a 72.';
        elseif ($desde === null || $desde < 2 || $desde > 20) $errores[] = 'El aviso de racha va desde x2.';
        else {
            foreach (['bonos_poco_pct' => (string)$pct, 'bonos_cierre_horas' => (string)$hrs, 'racha_aviso_desde' => (string)$desde,
                      'racha_avisos' => pedir('racha_avisos') === '1' ? '1' : '0',
                      'logro_montos_bloqueado' => pedir('montos_bloqueado') === '1' ? '1' : '0',
                      'los_lobos_titulo' => mb_substr(trim((string) pedir('lobos_titulo')), 0, 60) ?: 'Los lobos del mes'] as $k => $v) {
                guardar_ajuste($k, $v);
            }
            foreach (['caceria', 'team_primero', 'team_medio', 'team_ultimo'] as $g) {
                $lineas = array_slice(array_values(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 140),
                                      preg_split('/\r?\n/', (string) pedir('frases_' . $g)) ?: []))), 0, 30);
                guardar_ajuste('frases_' . $g, implode("\n", $lineas));
            }
            $nom = [];
            foreach ([2, 3, 4, 5] as $k) $nom[$k] = mb_substr(trim((string) pedir('racha_n' . $k)), 0, 30);
            guardar_ajuste('racha_nombres', json_encode($nom, JSON_UNESCAPED_UNICODE));
            ajustes_olvidar();
            avisar('ok', 'Guardado.');
            ir('/configuracion/bonos' . ($qp ? '?' . ltrim($qp, '&') : '') . '#ajustes-bonos');
        }
    }
}

pagina('bonos/config', [
    'bonos'   => bonos_del_pais($pais),
    'bono'    => $bono,
    'errores' => $errores,
    'paises'  => $paises, 'pais' => $pais, 'qp' => $qp,
    'con_asesores_sin_nivel' => (function () use ($pais) {
        $n = 0;
        foreach (bono_asesores($pais) as $a) if (nivel_de((int)$a['id'], date('Y-m-d')) === 0) $n++;
        return $n;
    })(),
], ['titulo' => $bono ? bono_nombre($bono) : 'Bonos', 'migaja' => $bono ? 'Bonos' : null]);
