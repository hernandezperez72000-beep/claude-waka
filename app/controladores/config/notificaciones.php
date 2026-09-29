<?php
declare(strict_types=1);
seccion_activa('config');

/**
 * Configuración › Notificaciones (5b).
 *
 * «Además de los avisos de clientes, también necesitamos una opción de
 * notificación general. Un texto para la notificación móvil, push. Y en
 * escritorio tipo un pop up emergente» (usuario, 2026-09-29).
 *
 * Se escribe el título y el texto (con su máximo de caracteres, que es lo que
 * cabe en el celular sin cortarse), una imagen opcional con sus medidas, y a
 * quién le llega. Al mandarlo: push a los celulares que lo activaron y una
 * ventana al entrar al HUB, una sola vez por persona.
 */
if (!notif_listo()) {
    pagina('pedidos/sinmigrar', [], ['titulo' => 'Notificaciones', 'subtitulo' => 'Disponible en cuanto se termine la actualización']);
    return;
}
$u = yo();
$pais = (int)$u['pais_id'];
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $img = notif_imagen_guardar($_FILES['imagen'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
    if (!$img['ok']) {
        $errores[] = $img['error'];
    } else {
        $r = notif_general_mandar($pais, (string) pedir('titulo'), (string) pedir('texto'), (string) pedir('para'),
                                  $img['archivo'], (string) pedir('enlace'));
        if ($r['ok']) {
            avisar('ok', 'Aviso mandado a ' . plural((int)$r['n'], 'persona', 'personas') . '. Le llega al celular a quien activó las notificaciones, y a todos en una ventana al entrar.');
            ir('/configuracion/notificaciones');
        }
        if ($img['archivo'] !== '') @unlink(HUB_SUBIDAS . '/avisos/' . $img['archivo']);
        $errores[] = $r['error'];
    }
}

pagina('config/notificaciones', [
    'errores'   => $errores,
    'audiencias'=> notif_audiencias($pais),
    'recientes' => notif_generales_recientes($pais),
    'con_push'  => push_listo() ? (int) valor('SELECT COUNT(DISTINCT usuario_id) FROM push_suscripciones ps JOIN usuarios u ON u.id = ps.usuario_id
                                                 WHERE u.pais_id = ? AND u.activo = 1 AND ps.fallos < 5', [$pais]) : 0,
    'activos'   => (int) valor('SELECT COUNT(*) FROM usuarios WHERE pais_id = ? AND activo = 1', [$pais]),
], ['titulo' => 'Notificaciones', 'migaja' => 'Configuración', 'sin_titulo' => true]);
