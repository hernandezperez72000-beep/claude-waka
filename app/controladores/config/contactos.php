<?php
declare(strict_types=1);
seccion_activa('config');

/**
 * Configuración › Contactos del HUB.
 *
 * Hoy tiene un solo campo, y existe porque el usuario lo pidió con esas
 * palabras: el botón «HABLAR CON FACTURACIÓN» del pedido trabado tiene que
 * llevar a WhatsApp y el «número editable» (usuario, 2026-09-11).
 *
 * Sin esta pantalla ese número solo se podía poner con un UPDATE a mano en la
 * base, así que el botón no se pintaba NUNCA y media banda de acción del
 * pedido denegado era código muerto. Lo encontró la auditoría del parche 2j.
 *
 * El número vive en `ajustes`, que no tiene país: hoy Waka factura desde un
 * solo sitio. El día que México tenga el suyo, esto pasa a ser una fila por
 * país — y este comentario es el aviso.
 */
$errores = [];
$wa = (string) ajuste('whatsapp_facturacion', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* La ruta ya pide 'listas.gestionar'; esto es el cinturón por si alguien
       cambia la tabla de rutas y no esta pantalla. */
    if (!puede('listas.gestionar')) cortar(403, 'Esto no es para tu perfil');

    /* Se guardan SOLO los dígitos: el asesor pega «+51 987 654 321» y wa.me no
       entiende ni el más ni los espacios. Se limpia al guardar y no al pintar,
       para que lo guardado sea exactamente lo que se va a usar. */
    $nuevo = preg_replace('/\D+/', '', pedir('whatsapp_facturacion')) ?? '';

    if ($nuevo === '' && trim(pedir('whatsapp_facturacion')) !== '') {
        /* Escribió ALGO y no quedó ni un dígito. Vaciar el campo es una
           decisión válida; teclear «no sé» no lo es, y apagar el botón por eso
           y anunciarlo como un acierto es peor que no hacer nada. */
        $errores[] = 'Ahí no hay ningún número. Si querías borrarlo, deja el campo vacío del todo.';
        /* Se deja lo GUARDADO en pantalla, no el vacío: borrar el campo al
           rebotar se lee como que el número ya no está, y sí está. */
    } elseif ($nuevo === '') {
        /* Vaciarlo es una decisión válida: apaga el botón. */
        guardar_ajuste('whatsapp_facturacion', '');
        ajustes_olvidar();
        avisar('ok', 'Número borrado. El botón de «hablar con facturación» deja de salir en los pedidos.');
        ir('/configuracion/contactos');
    } elseif (strlen($nuevo) < 8 || strlen($nuevo) > 15) {
        $errores[] = 'Ese número no parece un WhatsApp. Va con el código del país y sin el «+»: '
                   . 'un celular peruano queda como 51987654321.';
        $wa = $nuevo;
    } else {
        guardar_ajuste('whatsapp_facturacion', $nuevo);
        ajustes_olvidar();
        avisar('ok', 'Guardado. El botón de «hablar con facturación» ya lleva a ese número.');
        ir('/configuracion/contactos');
    }
}

pagina('config/contactos', [
    'wa' => $wa, 'errores' => $errores,
    'puedo_tocar' => puede('listas.gestionar'),
], ['titulo' => 'Contactos', 'migaja' => 'Configuración', 'sin_titulo' => true]);
