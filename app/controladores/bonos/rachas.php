<?php
declare(strict_types=1);
seccion_activa('bonos');

/**
 * RACHAS (módulo 5): la escalera del día, la semana y «en racha ahora».
 * Se llega tocando la racha del Inicio. PC y celular.
 */
$u = yo();
$es_asesor = (string)$u['rol'] === 'asesor';
pagina('bonos/rachas', [
    'es_asesor' => $es_asesor,
    'hoy_n' => $es_asesor ? racha_dia((int)$u['id']) : 0,
    'racha' => $es_asesor ? racha_dias((int)$u['id']) : null,
    'mejor_dia' => $es_asesor ? max(racha_mejor_dia((int)$u['id']), racha_dia((int)$u['id'])) : 0,
    'niveles' => racha_niveles(),
    'ahora' => en_racha_ahora((int)$u['pais_id']),
    'montos_bloqueado' => (string) ajuste('logro_montos_bloqueado', '0') === '1',
], ['titulo' => 'Rachas', 'subtitulo' => 'Clientes distintos, no pedidos · no multiplican dinero']);
