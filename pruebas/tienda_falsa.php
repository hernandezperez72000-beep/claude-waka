<?php
declare(strict_types=1);

/**
 * UNA TIENDA WOOCOMMERCE DE MENTIRA (módulo 3b).
 *
 * El banco no puede llamar a compraenwaka de verdad, así que se sustituye el
 * transporte —igual que con NUBEFACT— por esta función, que contesta como la
 * API v3 de WooCommerce: pagina de 100 en 100, manda X-WP-Total y
 * X-WP-TotalPages, y rechaza con 401 las claves o la forma de mandarlas que
 * no acepta.
 *
 * $T (por referencia):
 *   productos   => lista de productos tal como los devuelve WooCommerce
 *   variaciones => [id_producto => lista de variaciones]
 *   key, secret => las claves buenas
 *   modos       => las formas de mandar las claves que acepta ('basica', 'consulta')
 *   caida       => true: no contesta; o un número de página a partir de la cual se cae
 *   http        => si viene, contesta ese código sin más (404, 500…)
 *   cuerpo_raro => contesta 200 con esto (una página de WordPress, un objeto)
 *   sin_paginas => sin cabeceras de total, y 400 en la página de más
 *   paginas_falsas / total_falso => las cabeceras mienten (para el tope de páginas)
 *   llamadas    => se apunta cada petición: [url, modo]
 */
function tienda_falsa(array &$T): callable
{
    return function (string $url, string $key, string $secret, string $modo) use (&$T): array {
        $T['llamadas'][] = [$url, $modo];
        $nada = ['http' => 0, 'cuerpo' => null, 'total' => null, 'paginas' => null, 'red' => ''];
        $partes = parse_url($url);
        parse_str($partes['query'] ?? '', $qs);
        $pag = max(1, (int)($qs['page'] ?? 1));
        $por = max(1, (int)($qs['per_page'] ?? 10));

        if (($T['caida'] ?? false) === true
            || (is_int($T['caida'] ?? null) && $pag >= $T['caida'])) {
            return ['red' => 'Could not resolve host'] + $nada;
        }
        if (!empty($T['http'])) return ['http' => (int)$T['http']] + $nada;
        if (!in_array($modo, $T['modos'] ?? ['basica'], true)
            || $key !== ($T['key'] ?? '') || $secret !== ($T['secret'] ?? '')) {
            return ['http' => 401, 'cuerpo' => ['code' => 'woocommerce_rest_cannot_view']] + $nada;
        }

        $camino = preg_replace('#^.*/wp-json/wc/v3/#', '', (string)($partes['path'] ?? ''));
        if ($camino === 'products') {
            $todo = $T['productos'] ?? [];
        } elseif (preg_match('#^products/(\d+)/variations$#', $camino, $m)) {
            $todo = $T['variaciones'][(int)$m[1]] ?? [];
        } else {
            return ['http' => 404, 'cuerpo' => ['code' => 'rest_no_route']] + $nada;
        }
        if (isset($T['cuerpo_raro'])) return ['http' => 200, 'cuerpo' => $T['cuerpo_raro']] + $nada;
        $total = count($todo);
        $paginas = (int) max(1, ceil($total / $por));
        /* Un proxy que se come las cabeceras: sin total ni páginas, y la página
           de más contesta 400, como WooCommerce. */
        if (!empty($T['sin_paginas'])) {
            if ($pag > $paginas) return ['http' => 400, 'cuerpo' => ['code' => 'rest_post_invalid_page_number']] + $nada;
            return ['http' => 200, 'red' => '', 'cuerpo' => array_values(array_slice($todo, ($pag - 1) * $por, $por)),
                    'total' => null, 'paginas' => null];
        }
        return ['http' => 200, 'red' => '',
                'cuerpo'  => array_values(array_slice($todo, ($pag - 1) * $por, $por)),
                'total'   => $T['total_falso'] ?? $total, 'paginas' => $T['paginas_falsas'] ?? $paginas];
    };
}
