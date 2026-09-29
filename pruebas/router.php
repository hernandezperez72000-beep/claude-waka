<?php
/**
 * Router del servidor de pruebas: hace lo que hace el .htaccess en el hosting.
 * Un archivo que existe se sirve tal cual; todo lo demás entra por index.php.
 */
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$archivo = __DIR__ . '/../' . ltrim($ruta, '/');

/* Lo que el .htaccess bloquea en producción, aquí también. Si se añade una
   carpeta cerrada allá, hay que añadirla aquí o la prueba dejaría de medirla:
     · app/ y pruebas/  → RedirectMatch 404 en el .htaccess de la raíz
     · uploads/vouchers/ → Require all denied en su propio .htaccess.
       Los vouchers llevan el banco y el monto de un cliente: se sirven solo
       por /pagos/voucher, que pregunta antes quién está pidiendo. */
if (preg_match('#^/(app|pruebas|docs)/#', $ruta))  { http_response_code(404); exit; }
if (preg_match('#^/uploads/vouchers/#', $ruta))    { http_response_code(403); exit; }

if ($ruta !== '/' && is_file($archivo)) return false;
require __DIR__ . '/../index.php';
