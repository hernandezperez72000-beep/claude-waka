<?php
declare(strict_types=1);
/**
 * Generar una contraseña temporal nueva para alguien.
 *
 * Hace falta por dos motivos que van a pasar de verdad: que a quien dio el alta
 * se le pase el recuadro donde la clave se enseña una sola vez, y que alguien
 * olvide la suya y no pueda esperar al correo de recuperación.
 *
 * Nadie ve la contraseña de nadie: la anterior no se puede leer, esta es nueva,
 * se enseña una vez, y quien entra con ella está obligado a cambiarla.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') ir('/usuarios');

$yo_soy = yo();
$id = pedir_int('id');
if (!$id) ir('/usuarios');

if ($id === (int)$yo_soy['id']) {
    avisar('error', 'Para cambiar tu propia contraseña usa Mi perfil.');
    ir('/usuarios');
}

$fila = una('SELECT us.*, r.clave AS rol_clave FROM usuarios us
               JOIN roles r ON r.id = us.rol_id WHERE us.id = ?', [$id]);
if (!$fila) cortar(404, 'Ese usuario no existe');

if (!cruza_paises($yo_soy) && (int)$fila['pais_id'] !== (int)$yo_soy['pais_id']) {
    cortar(403, 'Esto no es tuyo', 'Ese usuario es de otro país.');
}
if (!puedo_tocar_rol((string)$fila['rol_clave'], $yo_soy)) {
    cortar(403, 'Esa cuenta no es tuya',
                'Solo Dirección puede darle una contraseña nueva a esa cuenta.');
}

$clave = poner_clave_temporal($id);

bitacora('usuario.clave_nueva', 'usuario', $id, ['email' => $fila['email']]);

$_SESSION['clave_temporal'] = [
    'id'     => $id,
    'nombre' => $fila['nombre'],
    'email'  => $fila['email'],
    'clave'  => $clave,
    'reset'  => true,
];
ir('/usuarios?nuevo=' . $id);
