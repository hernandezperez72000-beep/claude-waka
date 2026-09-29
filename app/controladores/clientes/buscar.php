<?php
declare(strict_types=1);

/**
 * Buscador de clientes para el formulario de pedido. Devuelve JSON.
 *
 * Filtra por el mismo ámbito que la lista: si un asesor no puede ver un
 * cliente en la lista, tampoco puede encontrarlo por aquí. Un buscador con
 * JSON es exactamente el sitio por donde se filtra la cartera de al lado.
 */
$q = pedir('q', 'get');
if (mb_strlen($q) < 2) json(['ok' => true, 'clientes' => []]);

/* SOLO LOS CLIENTES A LOS QUE SE LES PUEDE VENDER (3j). Antes buscaba en todo
   lo que uno puede VER: el líder de equipo encontraba a los clientes de los
   suyos, los elegía y la venta le contestaba «Ese cliente es de otro asesor»
   (usuario, 2026-09-28). Lo que sale aquí es lo que se puede vender. */
[$sql_ambito, $par] = sql_puedo_venderle('c');
$like = '%' . like_seguro($q) . '%';
array_push($par, $like, $like, $like, $like);

$filas = todas(
    "SELECT c.id, c.nombre, c.apellidos, c.documento, c.tipo_doc, c.celular, c.email
       FROM clientes c
       LEFT JOIN usuarios ua ON ua.id = c.asesor_id
      WHERE $sql_ambito AND c.activo = 1
        AND (c.documento LIKE ? OR c.nombre LIKE ? OR c.apellidos LIKE ? OR c.celular LIKE ?)
      ORDER BY c.nombre LIMIT 12", $par);

$out = [];
foreach ($filas as $f) {
    $out[] = [
        'id'    => (int)$f['id'],
        'texto' => trim($f['nombre'] . ' ' . (string)$f['apellidos']),
        'sub'   => $f['tipo_doc'] . ' ' . $f['documento'] . ($f['celular'] ? ' · ' . $f['celular'] : ''),
        'cashback' => soles(cashback_saldo((int)$f['id'])),
        'cashback_centimos' => cashback_saldo((int)$f['id']),
    ];
}
json(['ok' => true, 'clientes' => $out]);
