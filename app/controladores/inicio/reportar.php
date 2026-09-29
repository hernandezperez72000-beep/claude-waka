<?php
declare(strict_types=1);
/**
 * Reportar un error desde cualquier pantalla. El contexto (quién, dónde, con
 * qué equipo, a qué hora) se manda solo: el asesor no tiene que explicarlo.
 */
$u = yo();
$enviado = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $texto   = pedir('texto');
    $seccion = pedir('seccion');
    if (mb_strlen($texto) < 10) {
        $error = 'Cuéntanos un poco más: con una línea es difícil arreglarlo.';
    } else {
        insertar('reportes_error', [
            'usuario_id' => $u['id'],
            'seccion'    => $seccion ?: null,
            'texto'      => $texto,
            'contexto'   => json_encode([
                'pantalla'  => pedir('pantalla') ?: ($_SERVER['HTTP_REFERER'] ?? ''),
                'navegador' => navegador(),
                'oficina'   => $u['oficina'] ?? '',
                'rol'       => $u['rol'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        bitacora('error.reportar', 'reporte');
        avisar('ok', 'Gracias. Ya llegó a administración; te avisamos por aquí cuando esté resuelto.');
        $enviado = true;
    }
}

pagina('inicio/reportar', [
    'enviado' => $enviado,
    'error'   => $error,
    'secciones' => ['Stock','Pedidos','Clientes','Bonos','Cashback','Recepción','Otra cosa'],
], ['titulo' => 'Reportar un error']);
