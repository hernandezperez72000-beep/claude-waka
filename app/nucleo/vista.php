<?php
declare(strict_types=1);

/** Pinta una vista dentro del layout con menú. */
function pagina(string $vista, array $datos = [], array $opciones = []): never
{
    $datos['__vista']    = $vista;
    $datos['__opciones'] = $opciones;
    extract($datos, EXTR_SKIP);
    $usuario = yo();
    require HUB_VISTAS . '/layout/marco.php';
    exit;
}

/** Pinta una vista suelta, sin menú (acceso, bienvenida, celebraciones). */
function pagina_sola(string $vista, array $datos = []): never
{
    extract($datos, EXTR_SKIP);
    require HUB_VISTAS . '/layout/limpio.php';
    exit;
}

/** Incluye un trozo de vista. */
function parte(string $nombre, array $datos = []): void
{
    extract($datos, EXTR_SKIP);
    require HUB_VISTAS . '/' . $nombre . '.php';
}

function json(array $datos, int $codigo = 200): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Tema visual. El modo noche se enciende solo de 19:00 a 6:00,
 * salvo que la persona lo haya fijado en su perfil.
 */
function tema(): string
{
    $u = yo();
    $pref = $u['tema'] ?? 'auto';
    if ($pref === 'claro' || $pref === 'oscuro') return $pref;
    return es_de_noche() ? 'oscuro' : 'claro';
}

/** Ruta de la foto de perfil, o cadena vacía si no tiene. */
function foto_de(?array $u): string
{
    if (!$u || empty($u['foto'])) return '';
    return url('uploads/fotos/' . $u['foto']);
}
