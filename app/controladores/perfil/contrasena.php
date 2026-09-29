<?php
declare(strict_types=1);
seccion_activa('mas');
$u = yo();
$obligatorio = (int)$u['debe_cambiar_password'] === 1;
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actual = $_POST['actual'] ?? '';
    $nueva  = $_POST['nueva'] ?? '';
    $nueva2 = $_POST['nueva2'] ?? '';

    if (!password_verify($actual, $u['password_hash'])) {
        $errores[] = 'La contraseña de ahora no es correcta.';
    }
    if ($nueva !== $nueva2) {
        $errores[] = 'Las dos contraseñas nuevas no coinciden.';
    }
    foreach (pega_la_contrasena($nueva) as $falta) $errores[] = $falta . '.';
    if (!$errores && password_verify($nueva, $u['password_hash'])) {
        $errores[] = 'La nueva contraseña tiene que ser distinta de la anterior.';
    }

    if (!$errores) {
        guardar_contrasena((int)$u['id'], $nueva);
        avisar('ok', 'Contraseña cambiada. Se cerraron las sesiones recordadas en otros equipos.');
        ir('/inicio');
    }
}

pagina('perfil/contrasena', [
    'u' => $u, 'errores' => $errores, 'obligatorio' => $obligatorio,
], ['titulo' => 'Cambiar mi contraseña', 'sin_menu' => $obligatorio]);
