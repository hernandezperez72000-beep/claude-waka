<?php
declare(strict_types=1);
/** Marca pagado un premio de bono (o lo deshace el mismo día). Administración. */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');
$id = (int) pedir_int('id');
$r = pedir('deshacer') === '1' ? bono_resultado_despagar($id) : bono_resultado_pagar($id);
avisar($r['ok'] ? 'ok' : 'error', $r['ok'] ? (pedir('deshacer') === '1' ? 'Vuelve a «Por pagar».' : 'Marcado como pagado.') : $r['error']);
$p = (int) valor('SELECT pais_id FROM bono_resultados WHERE id = ?', [$id]);
ir('/bonos' . ($p && cruza_paises(yo()) ? '?p=' . $p : '') . '#por-pagar');
