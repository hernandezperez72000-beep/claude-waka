<?php
/**
 * Las tareas del HUB que no las dispara nadie abriendo una pantalla.
 *
 * Hoy solo hay una: anular los pedidos cuyo pago denegó facturación y nadie
 * arregló en 48 horas. Se decidió ANULAR y no borrar —un pago denegado es
 * justo el caso que después hace falta poder mirar— el 2026-09-11.
 *
 * SE PONE EN CRON, en cPanel → Trabajos cron, una vez al día:
 *
 *     php /home/USUARIO/public_html/tareas.php
 *
 * Y otra, cada hora, para el stock de la web (3b.5):
 *
 *     php /home/USUARIO/public_html/tareas.php stock
 *
 * Y otra, cada 5 minutos, para lo que las ventas no pudieron mover en la web
 * cuando la tienda no respondía (3e):
 *
 *     php /home/USUARIO/public_html/tareas.php cola
 *
 * Y si no se pone, tampoco pasa nada: la lista de pedidos hace el mismo
 * barrido como mucho una vez por hora. El cron solo hace que ocurra a su hora
 * en vez de cuando alguien entre.
 *
 * Por web NO se abre: no es una pantalla y no hay nada que ver. Se corta con
 * 404 para no anunciar que existe.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('No encontrado');
}

require_once __DIR__ . '/app/nucleo/arranque.php';

/* La base, ANTES de nada. Si no responde, el HUB corta con una página de error
   pensada para un navegador: por cron eso es un correo con HTML al
   administrador y un código de salida 0, o sea «todo bien». Aquí se comprueba
   a mano para poder salir con 1, que es lo único que cron entiende. */
try {
    if (!hub_instalado()) {
        fwrite(STDERR, "El HUB todavía no está instalado.\n");
        exit(1);
    }
    /* EL STOCK DE LA WEB (3b.5), con su propia línea de cron, cada hora:
         php /home/USUARIO/public_html/tareas.php stock
       Lee la tienda y guarda solo el stock, para el país de la tienda. */
    /* LA COLA DEL STOCK DE LA WEB (3e), cada 5 minutos:
         php /home/USUARIO/public_html/tareas.php cola
       Descuenta o devuelve en la web lo que no se pudo al vender o anular.
       Sin esta línea también se mueve, pero solo cuando alguien abre la
       lista de pedidos (y en la de cada hora, la del stock). */
    if (($argv[1] ?? '') === 'cola') {
        $n = stock_cola_procesar(null, 50);
        echo date('Y-m-d H:i:s'), "  stock de la web movido: $n · esperan: ", stock_cola_pendientes_n(), "\n";
        exit(0);
    }
    if (($argv[1] ?? '') === 'stock') {
        /* Primero lo que espera: si no, el stock recién leído no contaría
           las ventas que están por descontarse. */
        try { stock_cola_procesar(null, 50); } catch (Throwable $ex) { fwrite(STDERR, 'Cola: ' . $ex->getMessage() . "\n"); }
        $pais = (int) ajuste('woo_pais', 0);
        $r = stock_web_actualizar($pais);
        if (!$r['ok']) {
            fwrite(STDERR, 'No se pudo leer el stock: ' . $r['error'] . "\n");
            exit(1);
        }
        echo date('Y-m-d H:i:s'), "  stock de la web leído: {$r['productos']} productos\n";
        exit(0);
    }
    $n = tareas_del_hub();
} catch (Throwable $ex) {
    fwrite(STDERR, 'No se pudieron correr las tareas: ' . $ex->getMessage() . "\n");
    exit(1);
}

echo date('Y-m-d H:i:s'), "  pedidos anulados por pago denegado: $n\n";
exit(0);
