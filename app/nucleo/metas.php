<?php
declare(strict_types=1);

/**
 * La meta del mes.
 *
 * Hay dos niveles: la general del país (una fila con usuario_id = 0) y la
 * excepción de una persona (usuarios.meta_mensual_centimos). Vive aquí y no
 * dentro de una pantalla porque la miran el panel, la lista de usuarios y,
 * desde el módulo de reportes, los rankings: una sola definición o acaban
 * enseñando cifras distintas.
 *
 * El dinero es siempre entero de céntimos.
 */

/** Meta general del país para ese periodo (AAAA-MM). */
function meta_general(int $pais_id, string $periodo): int
{
    /* La general vale desde el mes en que se puso hasta que se cambie (5a):
       la más reciente que no sea posterior al mes pedido. Un mes pasado sigue
       con la que tenía. */
    $m = valor('SELECT monto_centimos FROM metas WHERE pais_id = ? AND usuario_id = 0 AND periodo <= ?
                 ORDER BY periodo DESC LIMIT 1', [$pais_id, $periodo]);
    // Ojo con ?: — una meta puesta a cero a propósito ("este mes no compite")
    // es un valor válido, y con ?: se convertiría en la general de 30.000.
    return (int) ($m !== null && $m !== false ? $m : ajuste('meta_mensual_general', 3000000));
}

/** Meta de una persona: su excepción si la tiene, si no la general de su país. */
function meta_de(array $u, string $periodo): int
{
    return $u['meta_mensual_centimos'] !== null
        ? (int) $u['meta_mensual_centimos']
        : meta_general((int)$u['pais_id'], $periodo);
}

/**
 * CAMBIA LA META GENERAL del país desde este mes (los meses pasados no se
 * tocan). → ['ok', 'error']
 */
function meta_general_poner(int $pais_id, int $centimos): array
{
    if ($centimos < 0 || !dinero_razonable($centimos)) return ['ok' => false, 'error' => 'Esa meta no se entiende.'];
    $periodo = date('Y-m');
    $ya = valor('SELECT id FROM metas WHERE pais_id = ? AND usuario_id = 0 AND periodo = ?', [$pais_id, $periodo]);
    if ($ya) actualizar('metas', (int)$ya, ['monto_centimos' => $centimos]);
    else insertar('metas', ['pais_id' => $pais_id, 'usuario_id' => 0, 'periodo' => $periodo, 'monto_centimos' => $centimos]);
    bitacora('meta.general', 'pais', $pais_id, ['centimos' => $centimos, 'desde' => $periodo]);
    return ['ok' => true, 'error' => ''];
}
