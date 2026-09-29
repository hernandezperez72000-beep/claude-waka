<?php
declare(strict_types=1);

if (hay_sesion()) ir('/inicio');

$email  = pedir('email');
$error  = '';
$restantes = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clave  = $_POST['clave'] ?? '';
    $recuerda = !empty($_POST['recordarme']);

    if ($email === '' || $clave === '') {
        $error = 'Escribe tu correo y tu contraseña.';
    } else {
        $r = entrar($email, $clave, $recuerda);
        if ($r['ok']) {
            $destino = destino_seguro($_SESSION['despues_de_entrar'] ?? '/inicio');
            unset($_SESSION['despues_de_entrar']);
            $u = yo();
            if ($u && (int)$u['debe_cambiar_password'] === 1) ir('/mi-perfil/contrasena');
            ir($destino);
            exit;
        }
        $error     = $r['error'];
        $restantes = $r['restantes'];
    }
}

pagina_sola('acceso/entrar', [
    'email'     => $email,
    'error'     => $error,
    'restantes' => $restantes,
    'oscuro'    => es_de_noche(),
    // Se viene de cerrar sesión: el celular tiene que olvidar lo guardado.
    'limpiar_cache' => pedir('limpio', 'get') === '1',
]);
