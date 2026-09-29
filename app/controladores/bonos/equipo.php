<?php
declare(strict_types=1);
seccion_activa('inicio');

/**
 * MODO TEAM (módulo 5): se abre tocando el podio «Los lobos del mes». El
 * grito de guerra, cómo va cada uno del propio equipo y la tabla de los
 * equipos (de los rivales, solo el total). Todo por lo cobrado del mes.
 */
$u = yo();
$pais = (int)$u['pais_id'];
$mes = date('Y-m');
$es_asesor = (string)$u['rol'] === 'asesor';
/* El asesor ve a las personas de SU equipo; quien sigue los bonos de todos
   (Administración, Dirección) puede mirar el de cualquiera. */
$ver = $es_asesor ? (int)($u['equipo_id'] ?? 0) : (int) pedir_int('e', 'get');
if (!$es_asesor && !puede('bonos.seguir')) $ver = 0;
if ($ver && !valor('SELECT 1 FROM equipos WHERE id = ? AND pais_id = ?', [$ver, $pais])) $ver = 0;
$t = modo_team($pais, $mes, $ver ?: null, (int)$u['id']);
$manada = null;
if ($ver && bonos_listo() && ($bm = bono_tipo($pais, 'manada')) && (int)$bm['activo'] === 1) {
    [$ini, $fin] = bono_periodo('quincena', date('Y-m-d'));
    $manada = ['bono' => $bm, 'fila' => bono_evaluar($bm, $ini, $fin)['filas']['e' . $ver] ?? null, 'inicio' => $ini, 'fin' => $fin];
}
pagina('bonos/equipo', $t + ['es_asesor' => $es_asesor, 'ver' => $ver, 'manada' => $manada, 'puede_elegir' => !$es_asesor && puede('bonos.seguir')],
       ['titulo' => 'Modo team', 'subtitulo' => 'Cómo van los equipos este mes · por lo cobrado']);
