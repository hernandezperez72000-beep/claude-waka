<?php
/**
 * HUB Waka — borrón y cuenta nueva.
 *
 * Vacía los datos de PRUEBA para estrenar el HUB: ventas, clientes, pagos,
 * cashback, vouchers y avisos. NO toca a los usuarios, los equipos, las metas
 * ni la configuración: no hay que volver a dar de alta a nadie.
 *
 * Se sube, se abre UNA vez y SE BORRA, igual que instalar.php y actualizar.php.
 * Que el archivo no viva en el servidor es la protección de verdad: un botón
 * de «borrar todas las ventas» dentro de un HUB en marcha es un accidente
 * esperando a pasar, y aquí no hay botón, hay un archivo que tú subes.
 *
 * Además pide dos llaves, y hacen falta las dos:
 *   1. TU contraseña, otra vez. Una sesión abierta en una máquina prestada no
 *      basta para borrar la operación entera.
 *   2. Escribir la palabra BORRAR. Un clic se da sin querer; una palabra no.
 */
declare(strict_types=1);
require_once __DIR__ . '/app/nucleo/arranque.php';
require_once HUB_APP . '/nucleo/limpieza.php';

sesion_iniciar();

if (!hub_instalado()) {
    http_response_code(409);
    exit('El HUB todavía no está instalado.');
}

$u = yo();
if (!$u || !in_array($u['rol'], ['administracion', 'direccion', 'desarrollador'], true)) {
    http_response_code(403);
    exit('Para vaciar el HUB hay que entrar primero con una cuenta de Administración o Dirección.');
}

$errores = [];
$hecho   = [];
$borrado = [];
$vouchers = 0;

/* La casilla del catálogo se mira por GET para PREVISUALIZAR (recargar la
   página y ver cuántas filas se llevaría) y por POST al ejecutar. Enviar el
   formulario solo para marcar una casilla dispararía las dos comprobaciones
   vacías y pintaría dos errores rojos por curiosear. */
$catalogo = ($_POST['catalogo'] ?? $_GET['catalogo'] ?? '') === '1';
$correr   = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if ($correr) {
    exigir_csrf();

    $palabra = trim((string)($_POST['palabra'] ?? ''));
    $clave   = (string)($_POST['clave'] ?? '');

    if ($palabra !== 'BORRAR') {
        $errores[] = 'Para confirmar hay que escribir la palabra BORRAR, en mayúsculas.';
    }
    /* Se comprueba contra el hash de ESTA cuenta y no se pasa por el contador
       de intentos fallidos: quien está aquí ya entró, y bloquearse a sí mismo
       a mitad de un borrado sería la peor forma de fallar. */
    $fila = una('SELECT password_hash FROM usuarios WHERE id = ?', [(int)$u['id']]);
    if (!$fila || !password_verify($clave, (string)$fila['password_hash'])) {
        $errores[] = 'Esa no es tu contraseña.';
    }

    if (!$errores) {
        try {
            $borrado  = limpieza_ejecutar($catalogo);
            $vouchers = limpieza_borrar_vouchers();
            $fotos_c  = $catalogo ? limpieza_borrar_fotos_catalogo() : 0;
            $hecho[]  = $borrado
                ? 'Se vaciaron ' . count($borrado) . ' tablas.'
                : 'No había nada que borrar: el HUB ya estaba limpio.';
            if ($vouchers > 0) {
                $hecho[] = 'Se borraron ' . $vouchers . ' archivos del disco (vouchers y comprobantes subidos).';
            }
            if ($fotos_c > 0) $hecho[] = 'Se borraron ' . $fotos_c . ' fotos de productos.';
            $hecho[] = 'Los contadores vuelven a empezar: la próxima venta será P-00001.';
            bitacora('hub.limpiado', 'sistema', 0,
                     ['tablas' => array_keys($borrado), 'catalogo' => $catalogo]);
        } catch (Throwable $e) {
            $errores[] = 'No se pudo completar: ' . $e->getMessage()
                       . ' — no se borró nada a medias, la operación va en una sola transacción.';
        }
    }
}

$conteos = $hecho ? [] : limpieza_conteos($catalogo);
$total   = array_sum($conteos);
$titulo  = 'Borrón y cuenta nueva';
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · HUB Waka</title>
<link rel="stylesheet" href="<?= e(activo('assets/css/hub.css')) ?>">
</head>
<body class="pantalla-sola">
<main class="caja" style="max-width:680px;margin:6vh auto;padding:0 20px 60px">
  <h1 style="font-size:24px;margin-bottom:6px"><?= e($titulo) ?></h1>
  <p class="mini" style="margin-bottom:20px">
    Deja el HUB sin los datos de prueba, listo para la primera venta de verdad.
    <strong>Esto no se puede deshacer.</strong>
  </p>

  <?php foreach ($errores as $x): ?>
    <div class="aviso aviso--rojo" style="margin-bottom:12px"><span><?= e($x) ?></span></div>
  <?php endforeach; ?>

  <?php if ($hecho): ?>
    <div class="aviso aviso--verde" style="margin-bottom:12px">
      <span><strong>Listo. El HUB está limpio.</strong></span>
    </div>
    <ul class="mini" style="margin:0 0 14px 18px">
      <?php foreach ($hecho as $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
      <?php foreach ($borrado as $t => $n): ?>
        <li><?= e($t) ?>: <?= (int)$n ?> <?= $n === 1 ? 'fila' : 'filas' ?></li>
      <?php endforeach; ?>
    </ul>
    <div class="aviso aviso--amarillo" style="margin-bottom:14px">
      <span><strong>Ahora borra este archivo</strong> (limpiar.php) desde el Administrador
        de archivos de cPanel. Mientras esté ahí, cualquiera que entre con una cuenta
        de Administración puede vaciar el HUB otra vez.</span>
    </div>
    <a class="btn btn--negro" href="<?= e(url('/inicio')) ?>">VOLVER AL HUB</a>

  <?php else: ?>

    <?php /* 3e: lo que las ventas de prueba descontaron de la web no vuelve
             solo al borrarlas. Se dice antes. */ ?>
    <?php $cola_pend = function_exists('stock_cola_pendientes_n') ? stock_cola_pendientes_n() : 0;
          $cola_hecha = tabla_existe('stock_web_cola') ? (int) valor("SELECT COUNT(*) FROM stock_web_cola WHERE tipo = 'descuento' AND estado = 'hecho'
                          AND pedido_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM stock_web_cola d WHERE d.depende_de = stock_web_cola.id AND d.estado = 'hecho')") : 0; ?>
    <?php if ($cola_pend > 0 || $cola_hecha > 0): ?>
      <div class="aviso aviso--amarillo" style="margin-bottom:14px" id="aviso-limpiar-stock">
        <span><strong>El stock de la web no vuelve solo.</strong>
          <?php if ($cola_hecha > 0): ?><?= $cola_hecha ?> <?= $cola_hecha === 1 ? 'venta descontó' : 'ventas descontaron' ?> stock de la web.<?php endif; ?>
          <?php if ($cola_pend > 0): ?><?= $cola_pend ?> <?= $cola_pend === 1 ? 'movimiento espera' : 'movimientos esperan' ?> a la tienda y se perderán.<?php endif; ?>
          Si eran de prueba, anúlalas antes, o corrige el stock en la web después.</span>
      </div>
    <?php endif; ?>

    <div class="tarjeta" style="margin-bottom:14px">
      <div class="tarjeta__cab"><h2>Lo que se va</h2>
        <span class="mini"><?= (int)$total ?> <?= $total === 1 ? 'fila' : 'filas' ?> en total</span></div>
      <?php if (!$total): ?>
        <p class="mini" style="margin:0">Nada: el HUB ya está limpio.</p>
      <?php else: ?>
        <div class="tabla__caja">
          <table class="tabla">
            <thead><tr><th>Tabla</th><th class="der">Filas</th></tr></thead>
            <tbody>
            <?php foreach ($conteos as $t => $n): ?>
              <tr<?= $n === 0 ? ' style="opacity:.45"' : '' ?>>
                <td class="principal"><?= e($t) ?></td>
                <td class="der num"><?= (int)$n ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <p class="mini" style="margin:10px 0 0">También se borran del disco las fotos de los
        <strong>vouchers</strong> y las <strong>boletas y facturas</strong> que se hayan
        subido: sin su pago y sin su pedido, son archivos con el nombre, el documento y lo
        que compró alguien dentro, ocupando sitio y sin dueño. Las fotos de perfil no se
        tocan.<?php if ($catalogo): ?> Y como se va el catálogo, también las <strong>fotos de
        los productos</strong>.<?php endif; ?></p>
    </div>

    <div class="tarjeta" style="margin-bottom:14px">
      <div class="tarjeta__cab"><h2>Lo que se queda</h2>
        <span class="mini">No hay que volver a dar de alta a nadie</span></div>
      <ul class="mini" style="margin:0 0 0 18px">
        <?php foreach (limpieza_se_conserva() as $q): ?><li><?= e($q) ?></li><?php endforeach; ?>
      </ul>
    </div>

    <form method="post" class="form">
      <?= campo_csrf() ?>

      <?php /* El catálogo va aparte y apagado: si ya cargaste lotes o productos
               de verdad, borrarlos sería la sorpresa más cara de todas. */ ?>
      <input type="hidden" name="catalogo" value="<?= $catalogo ? '1' : '0' ?>">
      <div class="aviso <?= $catalogo ? 'aviso--amarillo' : 'aviso--gris' ?>" style="margin-bottom:14px">
        <span>
          <?php if ($catalogo): ?>
            <strong>El catálogo TAMBIÉN se borra</strong> (lotes, productos, variantes,
            precios y stock), y está contado arriba.
            <a href="<?= e(url('/limpiar.php')) ?>" style="text-decoration:underline">No borrarlo</a>.
          <?php else: ?>
            <strong>El catálogo NO se toca.</strong> Lotes, productos, variantes, precios y
            stock se quedan como están.
            <a href="?catalogo=1" style="text-decoration:underline">Borrarlo también</a>
            — se recarga la página y verás cuántas filas se llevaría antes de decidir.
          <?php endif; ?>
        </span>
      </div>

      <?php /* El <strong> dentro del <label> lo partía en tres renglones: la
               etiqueta es una caja en columna y cada trozo caía en su propia
               línea. La palabra en negrita va debajo, en la ayuda. */ ?>
      <label>Confirmación
        <input type="text" name="palabra" autocomplete="off" placeholder="BORRAR"
               style="text-transform:uppercase"></label>
      <span class="ayuda">Escribe la palabra <strong>BORRAR</strong>, en mayúsculas.
        Un clic se da sin querer; una palabra no.</span>

      <label style="margin-top:10px">Tu contraseña
        <input type="password" name="clave" autocomplete="current-password"></label>

      <div class="acciones" style="margin-top:16px">
        <button class="btn btn--rojo" type="submit">BORRAR Y EMPEZAR DE CERO</button>
        <a class="btn btn--linea" href="<?= e(url('/inicio')) ?>">Cancelar</a>
      </div>
    </form>
  <?php endif; ?>
</main>
</body></html>
