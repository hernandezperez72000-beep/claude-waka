<?php
declare(strict_types=1);
seccion_activa('pagos');

/**
 * La pantalla de facturación.
 *
 * Aquí trabaja quien confirma el dinero y emite el comprobante: todos los
 * datos de la venta, cada uno con su botón de copiar, y al lado el voucher y
 * el botón de confirmar. Se copia CAMPO POR CAMPO y no en bloque, porque esos
 * datos se escriben uno a uno en Tumifactura: un bloque de texto no sirve para
 * llenar un formulario.
 */
$u  = yo();
$id = pedir_int('id', 'get');
if (!$id) cortar(404, 'Falta el pedido');

$p = pedido_de($id);
if (!$p) cortar(404, 'Ese pedido no existe');

/* La MISMA comprobación que la ficha del pedido, ni una más floja.
   Esta pantalla enseña más datos que la ficha —documento, dirección, correo,
   desglose de IGV—, así que si aquí solo se mirara el país, una cuenta con
   ámbito «lo suyo» recibiría 403 en /pedidos/ficha y 200 aquí, con el mismo
   pedido delante. El país lo comprueba puedo_ver() por dentro; el ámbito
   también. El equipo sale del asesor de HOY, como en la ficha. */
$equipo_asesor = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
exigir_ver((int)$p['asesor_id'], (int)$p['pais_id'],
           $equipo_asesor !== null ? (int)$equipo_asesor : null);

$cliente = una('SELECT * FROM clientes WHERE id = ?', [(int)$p['cliente_id']]);
$ubi     = ubigeo_de($p['ubigeo_id'] ? (int)$p['ubigeo_id'] : null);

pagina('pedidos/facturar', [
    'p'        => $p,
    'cliente'  => $cliente,
    'ubi'      => $ubi,
    'lineas'   => pedido_lineas($id),
    'pagos'    => pagos_del_pedido($id),
    'igv'      => desglose_igv((int)$p['total_centimos']),
    /* Con la escrita a mano: facturación necesita saber por dónde va, y una
       agencia que todavía no está en la lista sigue siendo la agencia. SIN el
       «(por autorizar)»: esta pantalla existe para copiar y pegar el valor tal
       cual, y ese rótulo es cosa nuestra, no del comprobante. */
    'agencia'  => pedido_agencia_texto($p, false),
    /* Cuál viene marcado Y POR QUÉ. Sale de comprobante_marcado(), que es la
       misma definición que usa la ventana del pago: escrita aquí a mano, esta
       pantalla se saltaba la solicitud del asesor y premarcaba lo que decía la
       ficha del cliente — con la razón social lista para copiar. Un clic
       emitía el comprobante que nadie pidió, y eso va a SUNAT. */
    'marcado'  => comprobante_marcado($p, $cliente),
    /* LA PREGUNTA DEL COMPROBANTE YA NO SE PINTA AQUÍ. Era una tarjeta propia
       que salía con ?comprobante=1 en la dirección, y desde que confirmar un
       pago abre la ventana superpuesta (parche 2j) NADIE generaba ya esa
       dirección: quedó código muerto, y muerto había empezado a divergir —su
       tercera salida decía «OMITIR POR AHORA» y no anotaba nada, mientras la
       de la ventana dice «ESTA NO LLEVA» y cierra la venta—. Dos botones en el
       mismo sitio haciendo lo contrario. Ahora esta pantalla enseña la MISMA
       ventana que las otras dos. */
    'resultado' => resultado_de_pago(),
], ['titulo' => 'Facturar ' . $p['codigo'], 'migaja' => 'Pagos por validar', 'sin_titulo' => true]);
