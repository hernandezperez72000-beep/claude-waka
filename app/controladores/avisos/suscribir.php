<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');

/**
 * ACTIVAR (O QUITAR) EL PUSH EN ESTE EQUIPO (5b). Lo llama la pantalla con
 * la suscripción que da el navegador: su dirección y sus dos claves. Cada
 * persona solo da de alta sus propios equipos.
 */
$u = yo();
if (pedir('quitar') === '1') {
    push_quitar((string) pedir('endpoint'));
    json(['ok' => true]);
}
$r = push_suscribir((int)$u['id'], (string) pedir('endpoint'), (string) pedir('p256dh'), (string) pedir('auth'),
                    (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
if ($r['ok']) bitacora('push.activar', 'usuario', (int)$u['id']);
json(['ok' => $r['ok'], 'error' => $r['error']], $r['ok'] ? 200 : 422);
