<?php
declare(strict_types=1);

/**
 * Buscador de distritos para el pedido y la ficha. Devuelve JSON.
 * Solo del país de quien pregunta: nadie tiene que ver la geografía de otro.
 */
$u = yo();
$q = pedir('q', 'get');
/* Se piden 26 para 25: si vuelve la de más, es que hay más de las que caben y
   la pantalla lo dice. Con 79 distritos nunca pasaba; con el país entero,
   «santa rosa» o «san juan» desbordan el tope y el asesor se quedaba mirando
   una lista cortada sin saber que estaba cortada. */
$tope  = 25;
$filas = ubigeo_buscar_distritos((int)$u['pais_id'], $q, $tope + 1);
$mas   = count($filas) > $tope;
if ($mas) array_pop($filas);

/* LO QUE SE PARECE, cuando no casa nada. «Que salgan similares cuando se
   escriba algo incorrecto» (usuario, 2026-09-20): un dedazo —«lurigacho»,
   «piuar»— dejaba la lista vacía y el asesor volvía a teclear lo mismo. Solo
   cuando no hay resultados exactos: mientras los haya, mandan ellos. */
$aprox = false;
if (!$filas) {
    $filas = ubigeo_buscar_parecidos((int)$u['pais_id'], $q, 8);
    $aprox = (bool)$filas;
}

$out = [];
foreach ($filas as $f) {
    $out[] = [
        'id'    => (int)$f['id'],
        /* Con la capital al lado cuando se llama distinto: quien buscó «la
           merced» tiene que reconocer lo que le sale, y «Chanchamayo» a secas
           no se parece a lo que escribió. */
        'texto' => (string)$f['distrito']
                 . ($f['capital'] ? ' (' . (string)$f['capital'] . ')' : ''),
        'sub'   => trim((string)$f['provincia'] . ' · ' . (string)$f['departamento'], ' ·'),
        // Lima y Callao llevan envío gratis y dirección; el resto del país
        // lleva agencia, sucursal y flete que paga el cliente en destino.
        // Lo decide el servidor, no la pantalla: el formulario se puede tocar
        // y esto cambia si el flete entra o no en el total.
        'lima'  => in_array(mb_strtolower((string)$f['provincia'], 'UTF-8'), ['lima','callao'], true),
    ];
}
json(['ok' => true, 'sitios' => $out, 'mas' => $mas, 'aprox' => $aprox]);
