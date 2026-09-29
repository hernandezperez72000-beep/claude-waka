<?php
declare(strict_types=1);
seccion_activa('reportes');

/* Índice de Reportes. Nace con uno solo construido —el de pagos— y los demás
   marcados «Pronto», en vez de esconderlos: si no se ven, nadie sabe que
   existen y se acaban pidiendo por WhatsApp. */
$secciones = [
    ['i'=>'PA','n'=>'Pagos ingresados','d'=>'El dinero que entró, por día, semana o mes · con descarga a Excel','ruta'=>'/reportes/pagos','listo'=>true,'chip'=>0],
    /* La bitácora solo se le enseña a quien puede abrirla: pintar una tarjeta
       que contesta 403 enseña a desconfiar de todas las demás. */
    ...(puede('usuarios.ver') ? [['i'=>'QQ','n'=>'Quién hizo qué','d'=>'Todo lo que se hizo, por persona, fecha y tipo','ruta'=>'/reportes/bitacora','listo'=>true,'chip'=>0]] : []),
    ['i'=>'VE','n'=>'Detalle de ventas','d'=>'Lo vendido, con el corte por tipo de venta','ruta'=>'/reportes/ventas','listo'=>false,'chip'=>0],
    ['i'=>'ZO','n'=>'Por zonas','d'=>'Las 25 zonas y su detalle','ruta'=>'/reportes/zonas','listo'=>false,'chip'=>0],
    ['i'=>'AS','n'=>'Por asesor','d'=>'Cobrado, vendido y avance de meta','ruta'=>'/reportes/asesores','listo'=>false,'chip'=>0],
];

pagina('config/indice', ['secciones' => $secciones],
       ['titulo' => 'Reportes',
        'subtitulo' => 'Ojo con la diferencia: «vendido» y «cobrado» casi nunca coinciden']);
