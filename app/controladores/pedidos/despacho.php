<?php
declare(strict_types=1);
seccion_activa('pedidos');

/**
 * DESPACHO DE UN PEDIDO.
 *
 * 3h.1 (usuario, 2026-09-28): «que el botón de mandar a despacho pase directo
 * a despacho y ya no salga el texto que era para enviar por WhatsApp». El
 * botón es un formulario de un clic (vistas/pedidos/boton_despacho.php) que
 * llega aquí por POST, anota la salida y vuelve a donde estaba. Almacén lo ve
 * en «Por alistar» con todas las indicaciones.
 * La pantalla (GET) queda para lo que venga después: si ya salió, cambiar el
 * día de entrega, el rótulo, los textos para el cliente y volver a mandarlo.
 */
$id = pedir_int('id', 'get') ?: pedir_int('id');
$p  = $id ? pedido_de($id) : null;
if (!$p) cortar(404, 'Ese pedido no existe');

$equipo_asesor = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
exigir_ver((int)$p['asesor_id'], (int)$p['pais_id'],
           $equipo_asesor !== null ? (int)$equipo_asesor : null);

/* UN PEDIDO ANULADO NO SE DESPACHA. La comprobación estaba solo en el POST, y
   el POST no es lo que sale al grupo: lo que sale es el texto que la persona
   copia de esta pantalla. Un pedido anulado se despachaba con su mensaje
   entero, «COBRAR S/ 379.00 AL ENTREGAR» incluido — mercadería que sale y
   dinero cobrado sobre una venta que ya no existe. */
if ((string)$p['estado'] === 'anulado') {
    cortar(410, 'Este pedido está anulado',
                'Una venta anulada no se despacha. Si el cliente la retoma, regístrala otra vez.');
}

/* SOLO SALE A DESPACHO CON EL PAGO CONFIRMADO (usuario, 2026-09-09). Se corta
   ANTES de armar el texto y no después de pintarlo: lo que llega al grupo es
   el texto copiado, no la pantalla, así que un aviso rojo arriba del mensaje
   no frena nada — la persona copia, pega y no vuelve a mirar. Si no hay
   mensaje, no hay nada que pegar.
   El saldo contraentrega no bloquea: lo que bloquea es dinero YA registrado
   que nadie ha confirmado todavía. Ver pedido_despacho_bloqueo(). */
if ($bloqueo = pedido_despacho_bloqueo($p)) {
    cortar(409, 'Todavía no sale a despacho', $bloqueo);
}

/* Para MARCAR que se mandó hay que poder ESCRIBIR sobre el pedido. Es una
   anotación en la ficha de una venta ajena, así que va por la misma puerta que
   el resto de las escrituras: el dueño del pedido y Administración de su país.
   El ámbito «equipo» es de solo lectura en todo el HUB y aquí tampoco deja de
   serlo, y Dirección mira pero no toca. */
$puedo_marcar = puedo_editar((int)$p['asesor_id'], (int)$p['pais_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$puedo_marcar) {
        cortar(403, 'Solo lectura', 'Puedes verlo, pero no mandarlo a despacho.');
    }

    /* Corregir el día y la hora antes de mandarlo: los clientes reprograman, y
       el sitio donde uno se entera es justo este, al ir a mandarlo. Es una
       acción distinta de «anotar que salió», así que se distingue por el botón
       y no por adivinar qué campos vinieron. */
    if (pedir('accion') === 'entrega') {
        $r = pedido_entrega_guardar($id, [
            'fecha'      => pedir('entrega_fecha'),
            'hora_desde' => pedir('entrega_hora_desde'),
            'hora_hasta' => pedir('entrega_hora_hasta'),
            'recepcion'  => pedir('entrega_recepcion') === '1',
        ]);
        avisar($r['ok'] ? 'ok' : 'error',
               $r['ok'] ? 'Anotado cuándo hay que entregarlo.' : $r['error']);
        ir('/pedidos/despacho?id=' . $id);
    }

    /* «primera»: el botón de un clic. Un doble toque no lo manda dos veces. */
    /* El costo del envío que quedó pendiente en la pre venta (3j): el botón
       lo pide al lado cuando falta. */
    $flete_txt = trim((string) pedir('flete'));
    $r = pedido_despacho_marcar($id, pedir('primera') === '1',
                                $flete_txt !== '' ? (a_centimos($flete_txt) ?? 0) : null);
    if ($r['ok'] && !empty($r['ya'])) {
        avisar('info', 'Este pedido ya estaba en despacho.');
    } else {
        avisar($r['ok'] ? 'ok' : 'error', $r['ok']
            ? ((int)$r['veces'] === 1 ? 'Mandado a despacho. Almacén ya lo ve en «Por alistar».'
                                      : 'Vuelto a mandar a despacho. Almacén ya lo ve en «Por alistar».')
            : $r['error']);
    }
    $volver = match (pedir('volver')) {
        'ficha'        => '/pedidos/ficha?id=' . $id,
        /* A la lista, con los filtros con que estaba (solo los suyos). */
        'lista'        => '/pedidos' . (function (): string {
                              parse_str((string) pedir('volver_q'), $q0);
                              $q1 = [];
                              foreach (['v', 'q', 't', 'z', 'a', 'es', 'd', 'h', 'pagina', 'tab', 'lo', 'pv'] as $k0) {
                                  if (isset($q0[$k0]) && is_string($q0[$k0]) && $q0[$k0] !== '') $q1[$k0] = mb_substr($q0[$k0], 0, 120);
                              }
                              return $q1 ? '?' . http_build_query($q1) : '';
                          })(),
        'pordespachar' => '/pedidos/por-despachar',
        default        => '/pedidos/despacho?id=' . $id,
    };
    ir($volver);
}

$saldo = pedido_saldo($p);

pagina('pedidos/despacho', [
    'p'      => $p,
    /* Los textos para el cliente se mudaron aquí desde la cabecera de la ficha
       (usuario, 2026-09-22): es la pantalla donde el asesor ya está copiando y
       pegando en WhatsApp. */
    'plantillas' => plantillas_del_pedido($p),
    'saldo'  => $saldo,
    'puedo_marcar' => $puedo_marcar,
    'quien'  => $p['despacho_por']
                ? (string) valor('SELECT nombre FROM usuarios WHERE id = ?', [(int)$p['despacho_por']])
                : '',
], ['titulo' => 'Despacho', 'migaja' => (string)$p['codigo']]);
