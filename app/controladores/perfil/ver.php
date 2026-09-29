<?php
declare(strict_types=1);
seccion_activa('mas');
$u = yo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Solo estos cuatro campos. El rol, el ámbito, el país y el equipo NO se
    // tocan desde aquí: cada quien cambia su celular y sus avisos, no su poder.
    $celular = mb_substr(trim(pedir('celular')), 0, 25);

    $cambios = [
        'celular'          => $celular !== '' ? $celular : null,
        'avisos_rachas'    => isset($_POST['avisos_rachas']) ? 1 : 0,
        'avisos_recepcion' => isset($_POST['avisos_recepcion']) ? 1 : 0,
        'tema'             => in_array(pedir('tema'), ['auto','claro','oscuro'], true) ? pedir('tema') : 'auto',
    ];

    // Foto: la sube el admin al crear la cuenta, y cada quien puede cambiarla.
    if (!empty($_FILES['foto']['name'])) {
        $r = guardar_foto($_FILES['foto'], (int)$u['id']);
        if ($r['ok']) $cambios['foto'] = $r['archivo'];
        else avisar('error', $r['error']);
    }

    actualizar('usuarios', (int)$u['id'], $cambios);
    bitacora('perfil.editar', 'usuario', (int)$u['id']);
    avisar('ok', 'Guardado.');
    ir('/mi-perfil');
}

pagina('perfil/ver', ['u' => $u], ['titulo' => 'Mi perfil']);
