<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');

$id = pedir_int('id');
$pg = $id ? una('SELECT * FROM pagos WHERE id = ?', [$id]) : null;
if (!$pg) cortar(404, 'Ese pago no existe');

$p = pedido_de((int)$pg['pedido_id']);
if (!$p) cortar(404, 'Ese pedido no existe');

/* Validar es decir «este voucher es bueno». Quien lo dice tiene que poder
   MIRARLO, así que aquí se pide exactamente lo mismo que para abrir el
   voucher, la ficha del pedido y la pantalla de facturar: exigir_ver(), que
   comprueba el país y el ámbito de una vez.

   Antes solo se miraba el país. Con el ámbito estrecho —un UPDATE a mano, un
   respaldo viejo, un rol nuevo mal dado de alta— la cuenta confirmaba el pago
   con un 302 y luego recibía 403 en los tres sitios donde se ve qué acababa de
   confirmar. Que el alta fije el ámbito de quien confirma (ambito_forzado_de_rol)
   evita llegar a ese estado; esto lo cierra aunque se llegue. */
$equipo_asesor = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
exigir_ver_para_escribir((int)$p['asesor_id'], (int)$p['pais_id'],
                         $equipo_asesor !== null ? (int)$equipo_asesor : null);

$r = pago_validar($id, pedir('operacion'));
/* EL AVISO SOLO CUANDO ALGO SALE MAL. Cuando sale bien lo cuenta la ventana
   que se abre al volver —incluido el cashback—, y el aviso quedaba DETRÁS del
   velo de la ventana: la misma frase escrita dos veces en la misma pantalla,
   una de ellas invisible, y `avisos()` la consume en esa petición, así que al
   cerrar la ventana ya no estaba. Ver la vista pagos/resultado. */
if (!$r['ok']) avisar('error', $r['error']);

$volver = volver_del_pago(pedir('volver'), (int)$pg['pedido_id']);

/* CONFIRMADO EL PAGO, TOCA EL COMPROBANTE — PERO SIN MOVER A NADIE DE SITIO.
   Es la secuencia real de facturación —«ya entró el dinero, ahora emito»— y
   preguntarlo al momento es la diferencia entre que se emita y que se quede
   pendiente para siempre: nadie vuelve a una pantalla por su cuenta a anotar
   algo que ya hizo en otro programa (usuario, 2026-09-09).

   Hasta hoy la pregunta se hacía LLEVÁNDOTE a /pedidos/facturar. Con la
   bandeja recibiendo el 100% de los pagos, cada confirmación te sacaba de la
   cola y había que volver a buscar por dónde ibas. Ahora se vuelve a la MISMA
   pantalla y la pregunta sale en una ventana encima (usuario, 2026-09-11).
   Ver resultado_de_pago() y la vista pagos/resultado. */
/* Y el pago que se acaba de confirmar viaja en la dirección para que la lista
   de abajo lo pinte en verde un momento: «aquí está lo que validaste», sin una
   sola palabra más (usuario, 2026-09-23). */
ir($volver . (str_contains($volver, '?') ? '&' : '?')
   . 'hecho=' . ($r['ok'] ? 'aprobado' : 'error') . '&ped=' . (int)$pg['pedido_id']
   . ($r['ok'] ? '&val=' . (int)$id : ''));
