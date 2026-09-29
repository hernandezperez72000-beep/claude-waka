<?php
declare(strict_types=1);
seccion_activa('pedidos');

/**
 * LOS DESCUENTOS QUE ESPERAN RESPUESTA (3d). A esta pantalla lleva el aviso
 * que le suena a Administración. Se aprueba o se rechaza aquí mismo, sin tener
 * que abrir venta por venta.
 */
pagina('pedidos/descuentos', [
    'filas'    => descuentos_pendientes(yo(), 100),
    'tope_pct' => descuento_tope_pct(),
], ['titulo' => 'Descuentos por aprobar', 'migaja' => 'Pedidos', 'sin_titulo' => true]);
