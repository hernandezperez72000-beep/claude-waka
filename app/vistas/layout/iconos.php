<?php
/** Los íconos son SVG en línea: cero peticiones extra y heredan el color. */
function ico(string $nombre, int $tam = 18): string
{
    $p = match ($nombre) {
        'casa'     => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.7V21h13V9.7"/>',
        'personas' => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M17 11a3 3 0 1 0 0-6"/>',
        'caja'     => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/>',
        'cubo'     => '<path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M3 7.5 12 12l9-4.5"/>',
        'medalla'  => '<circle cx="12" cy="9" r="5.2"/><path d="M8.4 13.4 7 21.5l5-2.6 5 2.6-1.4-8.1"/>',
        'puerta'   => '<path d="M3 21V9l9-6 9 6v12"/><path d="M9 21v-6h6v6"/>',
        'tarjeta'  => '<path d="M3 8h18v10H3z"/><path d="M3 12h18"/><circle cx="7.5" cy="15" r="1.2"/>',
        'barras'   => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20H2"/>',
        'rueda'    => '<circle cx="12" cy="12" r="3.2"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 7 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3 14.2a2 2 0 1 1 0-4 1.6 1.6 0 0 0 1.6-2.4l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 9.8 3a2 2 0 1 1 4 0 1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7 2 2 0 1 1 0 4h-.1"/>',
        'chat'     => '<path d="M20 12a8 8 0 0 1-11.6 7.1L4 20l1-4.1A8 8 0 1 1 20 12Z"/>',
        'puntos'   => '<circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/>',
        'ojo'      => '<circle cx="12" cy="12" r="3"/><path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z"/>',
        'lupa'     => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'mas'      => '<path d="M12 5v14M5 12h14"/>',
        'flecha'   => '<path d="m9 6 6 6-6 6"/>',
        'atras'    => '<path d="m15 6-6 6 6 6"/>',
        'salir'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'candado'  => '<rect x="4" y="10.5" width="16" height="10" rx="2.4"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>',
        'lapiz'    => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'reloj'    => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4l2.5 2"/>',
        'alerta'   => '<path d="M12 9v5"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.9 18.4A2 2 0 0 0 3.6 21.4h16.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
        'escudo'   => '<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.9 7.5-9.5V6z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
        'check'    => '<path d="m5 13 4 4L19 7"/>',
        'luna'     => '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a6.6 6.6 0 0 0 10.5 10.5Z"/>',
        'copiar'   => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'camion'   => '<path d="M3 16V6h11v10"/><path d="M14 9h4l3 3.5V16h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        /* Módulo 5: la racha (rayo) y el premio (trofeo). */
        'rayo'     => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
        'trofeo'   => '<path d="M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M7 6H4a3 3 0 0 0 3 4M17 6h3a3 3 0 0 1-3 4M12 14v4M8 21h8"/>',
        'camara'   => '<path d="M3 8h3l1.5-2h9L18 8h3v11H3z"/><circle cx="12" cy="13" r="3.6"/>',
        /* 3h · la pre venta: el barco que trae el contenedor. */
        'filtro'   => '<path d="M4 6h16"/><path d="M7 12h10"/><path d="M10 18h4"/>',
        'barco'    => '<path d="M3 15h18l-2.5 4.5h-13z"/><path d="M6 15V9h9v6"/><path d="M9 9V5h3v4"/><path d="M2 21c1.5 0 1.5-1 3-1s1.5 1 3 1 1.5-1 3-1 1.5 1 3 1 1.5-1 3-1 1.5 1 3 1"/>',
        default    => '<circle cx="12" cy="12" r="9"/>',
    };
    return '<svg width="' . $tam . '" height="' . $tam . '" viewBox="0 0 24 24" fill="none"'
         . ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round"'
         . ' stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** El avatar: foto si la hay, iniciales si no. Nunca un hueco. */
function avatar(?array $u, int $tam = 40, bool $marca = false): string
{
    $clase = 'av av--' . $tam . ($marca ? ' av--marca' : '');
    $foto  = foto_de($u);
    if ($foto) {
        return '<img class="' . $clase . '" src="' . e($foto) . '" alt="" width="' . $tam . '" height="' . $tam . '">';
    }
    $ini = $u ? iniciales((string)$u['nombre'], (string)($u['apellidos'] ?? '')) : '··';
    return '<span class="' . $clase . '">' . e($ini) . '</span>';
}
