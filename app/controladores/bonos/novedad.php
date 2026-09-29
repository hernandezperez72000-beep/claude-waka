<?php
declare(strict_types=1);
/**
 * «YA LO VI» de la pila de novedades (módulo 5). Se guarda en el servidor, no
 * en el navegador: desde la computadora no se vuelve a ver. Contesta JSON.
 */
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(404); echo '{"ok":false}'; exit; }
$u = yo();
$n = 0;
foreach (array_slice((array)($_POST['claves'] ?? []), 0, 30) as $c) {
    $c = (string)$c;
    if (!preg_match('/^[a-z0-9_.:-]{3,80}$/', $c)) continue;
    if (novedad_vista((int)$u['id'], $c)) $n++;
}
json(['ok' => true, 'n' => $n]);
