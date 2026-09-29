<?php
declare(strict_types=1);
/**
 * Recuperar contraseña. El asesor la pide; Administración aprueba desde
 * Configuración › Accesos y el sistema manda un enlace de un solo uso que
 * caduca en 24 horas. Nadie, ni el admin, llega a ver la contraseña de otro.
 *
 * La respuesta es SIEMPRE la misma, exista o no el correo.
 */
$enviado = false;
$email   = pedir('email');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (correo_valido($email)) {
        $u = una('SELECT id FROM usuarios WHERE email = ? AND activo = 1', [mb_strtolower($email)]);
        if ($u) {
            $pendiente = valor(
                "SELECT id FROM solicitudes_password WHERE usuario_id = ? AND estado = 'pendiente'",
                [$u['id']]
            );
            if (!$pendiente) {
                insertar('solicitudes_password', [
                    'usuario_id' => $u['id'],
                    'nota'       => 'Pedida desde la pantalla de acceso',
                ]);
                bitacora('acceso.pide_contrasena', 'usuario', (int)$u['id']);
            }
        }
    }
    $enviado = true;   // Se responde igual exista o no: no se confirma nada.
}

pagina_sola('acceso/recuperar', [
    'enviado' => $enviado,
    'email'   => $email,
    'oscuro'  => es_de_noche(),
]);
