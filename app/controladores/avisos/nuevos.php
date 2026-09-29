<?php
declare(strict_types=1);

/**
 * LAS NOTIFICACIONES NUEVAS DE QUIEN PREGUNTA (5b). JSON.
 *
 * Lo sondean solo quienes no tenían ya otro sondeo (Almacén, Dirección,
 * Marketing): al asesor y a Facturación les viajan en el suyo, para que nadie
 * haga dos peticiones. Lo entregado queda visto: no vuelve a salir en otra
 * pestaña ni en el celular.
 */
$u = yo();
json([
    'ok'     => true,
    'notifs' => notif_para_mi($u),
    /* El contador de «Por alistar», para que el chip del menú no se quede viejo. */
    'alistar'=> function_exists('le_avisamos_de_alistar') && le_avisamos_de_alistar($u) ? alistar_pendientes_n($u) : null,
]);
