<?php
declare(strict_types=1);
seccion_activa('bonos');

/**
 * LANZAR LA CACERÍA DEL DÍA (módulo 5, acción del CEO). Tres pasos: título y
 * frase (con «Otra frase»), la escalera de hoy (cambiable solo por hoy) y a
 * quién le llega. Una vez al día; después se corrigen título y frase. Cierra
 * sola a medianoche y el resultado sale al día siguiente en la pila.
 */
if (!bonos_listo()) cortar(404, 'Falta terminar la actualización');
$u = yo();
$pais = (int)$u['pais_id'];
$b = bono_tipo($pais, 'caceria');
if (!$b) cortar(404, 'Falta terminar la actualización');
$hoy = date('Y-m-d');
$ya = caceria_de($pais, $hoy);
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = caceria_lanzar($pais, $_POST);
    if ($r['ok']) {
        avisar('ok', $r['nueva'] ? 'Lanzada. Ya les sale a los asesores al entrar.' : 'Corregido.');
        ir('/bonos');
    }
    $errores[] = $r['error'];
}
$frases = bono_frases('caceria');
pagina('bonos/caceria', [
    'bono' => $b, 'ya' => $ya, 'errores' => $errores,
    'frases' => $frases,
    'frase_sugerida' => $ya['frase'] ?? bono_frase_del_dia('caceria'),
    'escalera' => $ya['escalera'] ?? (array)($b['reglas']['escalera'] ?? []),
    'oficinas' => todas('SELECT id, nombre FROM oficinas WHERE pais_id = ? ORDER BY orden, nombre', [$pais]),
    'equipos' => todas('SELECT id, nombre FROM equipos WHERE pais_id = ? AND activo = 1 ORDER BY nombre', [$pais]),
    'cuantos' => count(bono_asesores($pais)),
], ['titulo' => $ya ? 'La Cacería de hoy' : 'Lanzar la Cacería del Día', 'migaja' => 'Bonos']);
