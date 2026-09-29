<?php
declare(strict_types=1);

/**
 * ¿Le desbloquearon alguna venta para mandar a despacho? Responde JSON.
 *
 * Es el gemelo de /pagos/nuevos y sigue sus dos lecciones, que costaron una
 * auditoría cada una:
 *
 *  · `ultimo` es una MARCA DE AGUA, no un contador. Sale del id más alto de la
 *    cola, y se guarda aunque no se avise: si no, el mismo pedido volvería a
 *    dispararse en cada sondeo.
 *  · Quien decide si hay algo que avisar es `nuevos`, y solo él. «Tienes 7
 *    pendientes» y «se desbloqueó 1» son cosas distintas, y confundirlas hace
 *    que el aviso mienta cada vez que quedan pendientes de antes.
 *
 * No enseña ni un dato del pedido: cuántos hay y cuál es el de id más alto.
 */
$u = yo();

/* Cada noticia tiene su propia puerta: la ruta solo pide sesión porque las dos
   mitades no son del mismo permiso. */
$r = le_avisamos_de_despacho($u)
    ? pedidos_por_despachar_resumen($u)
    : ['n' => 0, 'ultimo' => 0];

$desde = max(0, (int) pedir_int('desde', 'get', 0));

/* Cuántos de la cola son MÁS NUEVOS que lo último que vio la pantalla. Va por
   id de pedido y no por «cuándo se confirmó el pago», que es lo que de verdad
   desbloquea: una venta vieja confirmada hoy no subiría la marca y no avisaría.
   Se acepta a propósito —el chip y la lista sí la cuentan desde el primer
   momento, y el aviso sonoro es un extra, no la única puerta. */
/* El mismo ámbito que el resumen y que la pantalla: lo que uno puede MANDAR,
   no lo que puede ver. Y la misma guarda de columna, que este sondeo corre
   cada 30 segundos en un HUB al que puede faltarle actualizar.php. */
[$ambito, $par] = filtro_ambito_despacho('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id', $u);
$nuevos = (!le_avisamos_de_despacho($u) || !columna_existe('pedidos', 'despacho_veces')) ? 0 : (int) valor(
    'SELECT COUNT(*)
       FROM pedidos pe
       LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
      WHERE pe.anulado_en IS NULL
        AND pe.despacho_veces = 0
        AND pe.id > ?
        AND ' . sql_despacho_listo() . '
        AND ' . $ambito, array_merge([$desde], $par));

/* ── Y de paso, los pagos suyos que quedaron trabados ────────────────
   Va en ESTA respuesta y no en un sondeo aparte a propósito: el asesor ya
   pregunta aquí cada 30 s, y montarle un segundo temporizador sería duplicarle
   las peticiones en un hosting compartido para contarle dos noticias que caben
   en la misma respuesta. Es la misma razón por la que él no sondea pagos y
   facturación no sondea despacho.

   Misma mecánica: `trabados_ultimo` es la marca de agua y sube siempre;
   `trabados_nuevos` es lo único que decide si se avisa. */
/* Los trabados van por CUENTA, no por marca de agua. Con marca de agua el
   primer pago trabado no avisaba nunca —la marca arranca en 0 y la primera
   vuelta calla— y uno con id menor que otro ya trabado tampoco, porque el
   máximo no subía; y facturación revisa su cola en el orden que quiere. Son
   pocos y todos son tarea pendiente: si el número sube, hay noticia. */
$trabados = le_avisamos_de_trabados($u) ? pagos_trabados_resumen($u)['n'] : 0;

/* ── Y la respuesta a sus descuentos (3d) ───────────────────────
   Marca de agua de la bitácora, como el reclamo: Administración aprobó o
   rechazó un descuento de ESTE asesor desde la última vuelta. */
$dmarca = reclamos_marca();
$dres = descuentos_resueltos_desde(max(0, (int) pedir_int('ddesde', 'get', 0)), $dmarca, $u);

/* ── Y las rachas del equipo (5a): quién puso la marca del día. Sin sonido. */
$rach = (function_exists('racha_avisos_desde') && tabla_existe('racha_avisos'))
    ? racha_avisos_desde($u, max(-1, (int) pedir_int('rdesde', 'get', -1))) : ['ultimo' => 0, 'avisos' => []];

json([
    'ok'       => true,
    'racha_ultimo' => $rach['ultimo'],
    'rachas'       => $rach['avisos'],
    'desc_ultimo' => $dmarca,
    'desc_n'      => $dres['n'],
    'desc_codigo' => $dres['codigo'],
    'desc_id'     => $dres['id'],
    'desc_ok'     => $dres['aprobado'],
    'n'        => $r['n'],
    'nuevos'   => $desde > 0 ? $nuevos : 0,   // la primera vuelta nunca avisa
    'ultimo'   => $r['ultimo'],
    'trabados' => $trabados,
    /* 5b: las notificaciones (el pago confirmado, la pre venta nueva, el
       aviso general) viajan en este mismo sondeo. */
    'notifs'   => function_exists('notif_para_mi') ? notif_para_mi($u) : [],
]);
