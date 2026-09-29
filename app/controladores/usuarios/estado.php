<?php
declare(strict_types=1);
/**
 * Encender o apagar una cuenta. Apagar NO borra: la persona deja de entrar y
 * sus ventas siguen contando en los reportes históricos.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') ir('/usuarios');

$yo_soy = yo();
$id = pedir_int('id');
if (!$id) ir('/usuarios');
if ($id === (int)$yo_soy['id']) {
    avisar('error', 'No puedes apagar tu propia cuenta.');
    ir('/usuarios');
}

$fila = una('SELECT us.*, r.clave AS rol_clave FROM usuarios us
               JOIN roles r ON r.id = us.rol_id WHERE us.id = ?', [$id]);
if (!$fila) cortar(404, 'Ese usuario no existe');
if (!cruza_paises($yo_soy)
    && (int)$fila['pais_id'] !== (int)$yo_soy['pais_id']) {
    cortar(403, 'Esto no es tuyo');
}
// Y no se apaga una cuenta de tu mismo nivel o por encima: si no, Administración
// dejaría al CEO fuera del HUB con un solo clic.
if (!puedo_tocar_rol((string)$fila['rol_clave'], $yo_soy)) {
    cortar(403, 'Esa cuenta no es tuya',
                'Solo Dirección puede apagar esa cuenta.');
}

$nuevo = (int)$fila['activo'] === 1 ? 0 : 1;
actualizar('usuarios', $id, ['activo' => $nuevo]);
if ($nuevo === 0) q('DELETE FROM sesiones WHERE usuario_id = ?', [$id]);

bitacora($nuevo ? 'usuario.encender' : 'usuario.apagar', 'usuario', $id);
avisar('ok', primer_nombre($fila['nombre']) . ($nuevo ? ' vuelve a tener acceso.' : ' ya no puede entrar. Sus ventas siguen en los reportes.'));
ir('/usuarios');
