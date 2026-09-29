<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');

/** «Probar en este equipo» (Mi perfil): una notificación solo para mí. */
$u = yo();
notificar(['pais_id' => (int)$u['pais_id'], 'para_usuario_id' => (int)$u['id'], 'tipo' => 'general', 'emergente' => 0,
           'titulo' => 'Notificaciones activadas', 'texto' => 'Así te van a llegar los avisos.', 'url' => '/mi-perfil']);
avisar('ok', 'Listo: en unos segundos te llega la prueba.');
ir('/mi-perfil');
