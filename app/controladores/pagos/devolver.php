<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');

$id = pedir_int('id');
$pg = $id ? una('SELECT * FROM pagos WHERE id = ?', [$id]) : null;
if (!$pg) cortar(404, 'Ese pago no existe');

$p = pedido_de((int)$pg['pedido_id']);
if (!$p) cortar(404, 'Ese pedido no existe');

/* Devolver dinero ya cobrado es de Administración, no del asesor: el permiso
   pagos.anular ya lo dice, y aquí se comprueba además que sea de su país. */
exigir_editar((int)$p['asesor_id'], (int)$p['pais_id']);

/* Dos botones, la misma aritmética, distinta historia — y la persona tiene que
   ver escrito cuál de las dos hizo:
     · «Se confirmó por error»  → ese dinero nunca entró (voucher falso que se
       descubrió tarde, o un clic equivocado). No salió nada de la caja.
     · «Devolver al cliente»    → el dinero se le regresó de verdad.
   La palabra «devolución» a secas las mezclaba, y quien lee el movimiento tres
   semanas después no puede saber si hubo que sacar plata o no. */
$clase = pedir('clase') === 'error' ? 'error' : 'cliente';
$por_defecto = $clase === 'error'
    ? 'Se confirmó por error'
    : 'Devolución al cliente';

$r = pago_devolver($id, pedir('motivo') ?: $por_defecto, $clase);
avisar($r['ok'] ? 'ok' : 'error', $r['ok']
    ? ($clase === 'error'
        ? 'Confirmación deshecha. El pedido vuelve a quedar con ese saldo por cobrar, la meta del '
        . 'asesor baja este mes —no el mes en que se confirmó— y el cashback se revirtió.'
        : 'Devolución anotada con la fecha de hoy. La meta del mes en que entró el dinero no cambia: '
        . 'baja la de este mes, que es cuando ocurre la reversión. El cashback de ese pago se revirtió.')
    : $r['error']);
ir('/pedidos/ficha?id=' . (int)$pg['pedido_id']);
