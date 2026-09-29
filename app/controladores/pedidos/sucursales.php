<?php
declare(strict_types=1);

/**
 * Las sucursales que ya se han usado con esa agencia en esa ciudad. JSON.
 *
 * Solo del país de quien pregunta, como el buscador de distritos: nadie tiene
 * que ver por dónde despacha otro país.
 */
$u = yo();
$ag  = pedir_int('ag', 'get');
$ubi = pedir_int('ubigeo', 'get');

json(['ok' => true, 'sucursales' => sucursales_de((int)$u['pais_id'], $ag, $ubi)]);
