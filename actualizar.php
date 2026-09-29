<?php
/**
 * HUB Waka — actualizar.
 *
 * El instalador se apaga solo en cuanto escribe la configuración, así que un
 * HUB ya instalado nunca volvería a ver ni una tabla nueva ni un permiso nuevo.
 * Este archivo es el camino para eso: se sube junto con la versión nueva, lo
 * abre una persona con sesión de Administración o Dirección, y aplica lo que
 * falte. Es idempotente: pasarlo dos veces no rompe nada.
 *
 * Cuando termina, BÓRRALO, igual que instalar.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/app/nucleo/arranque.php';
require_once HUB_APP . '/nucleo/esquema.php';
require_once HUB_APP . '/nucleo/esquema2.php';
require_once HUB_APP . '/nucleo/esquema3.php';
require_once HUB_APP . '/nucleo/semillas.php';

sesion_iniciar();

if (!hub_instalado()) {
    http_response_code(409);
    exit('El HUB todavía no está instalado. Abre instalar.php primero.');
}

// Solo alguien que ya entró y manda de verdad. No basta con conocer la URL.
$u = yo();
if (!$u || !in_array($u['rol'], ['administracion', 'direccion', 'desarrollador'], true)) {
    http_response_code(403);
    exit('Para actualizar el HUB hay que entrar primero con una cuenta de Administración o Dirección.');
}

$hecho   = [];
$errores = [];
$correr  = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($correr) exigir_csrf();

if ($correr) {
    try {
        // 1) Tablas que no existan. Todo el DDL es CREATE TABLE IF NOT EXISTS.
        foreach (esquema_sql() as $sql)         { bd()->exec($sql); }
        foreach (esquema_sql_negocio() as $sql) { bd()->exec($sql); }
        foreach (esquema_sql_modulo2() as $sql) { bd()->exec($sql); }
        $hecho[] = 'Tablas revisadas: se creó lo que faltaba (módulo 2: ubigeo y cola de la tienda).';

        // 1.b) Columnas nuevas del módulo 2 sobre tablas que ya existían.
        //      Cada una se añade solo si falta, así que esto se puede pasar
        //      dos veces seguidas sin romper nada.
        $cols = migraciones_modulo2();
        $hecho[] = $cols
            ? 'Módulo 2 · columnas añadidas: ' . implode(', ', $cols)
            : 'Módulo 2 · las columnas ya estaban todas.';

        // 2) Columnas que cambiaron de NULL a NOT NULL DEFAULT 0.
        //    Se pasan a 0 los NULL antiguos ANTES de apretar la columna, o el
        //    ALTER fallaría y, peor, las metas viejas dejarían de encontrarse.
        foreach ([
            ['metas',            'usuario_id', "INT UNSIGNED NOT NULL DEFAULT 0"],
            ['bono_resultados',  'usuario_id', "INT UNSIGNED NOT NULL DEFAULT 0"],
            ['bono_resultados',  'equipo_id',  "INT UNSIGNED NOT NULL DEFAULT 0"],
        ] as [$tabla, $col, $tipo]) {
            // columna_existe() sabe preguntar igual en MySQL y en el banco de
            // pruebas; information_schema solo existe en MySQL y aquí dejaba
            // esta migración sin poder probarse.
            if (!columna_existe($tabla, $col)) continue;
            $nulos = (int) valor("SELECT COUNT(*) FROM `$tabla` WHERE `$col` IS NULL");
            if ($nulos) {
                q("UPDATE `$tabla` SET `$col` = 0 WHERE `$col` IS NULL");
                $hecho[] = "$tabla.$col: $nulos fila(s) con NULL pasaron a 0.";
            }
            q("ALTER TABLE `$tabla` MODIFY `$col` $tipo");
        }
        $hecho[] = 'Metas y resultados de bonos: 0 en vez de NULL para "general".';

        // 3) Roles, permisos, listas, frases, ajustes y estados nuevos.
        //    sembrar_todo() no pisa nada: solo añade lo que falta.
        sembrar_todo();
        $hecho[] = 'Roles, permisos y listas al día (incluye los permisos nuevos).';

        // 4) Y lo que necesitaba que los roles nuevos ya existieran. Va aquí y
        //    no arriba porque arriba todavía no había rol de Facturación.
        foreach (migraciones_tras_semillas() as $x) $hecho[] = ucfirst($x) . '.';

        bitacora('hub.actualizar', 'usuario', (int)$u['id']);

    } catch (Throwable $ex) {
        error_log('[HUB actualizar] ' . $ex->getMessage());
        $errores[] = 'Algo falló a mitad. El detalle quedó en el log de errores de cPanel. '
                   . 'Lo más común es que al usuario de la base le falten permisos de ALTER.';
    }
}

$titulo = 'Actualizar el HUB';
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · HUB Waka</title>
<link rel="stylesheet" href="<?= e(activo('assets/css/hub.css')) ?>">
</head>
<body class="pantalla-sola">
<main class="caja" style="max-width:640px;margin:8vh auto;padding:0 20px">
  <h1 style="font-size:24px;margin-bottom:6px"><?= e($titulo) ?></h1>
  <p class="mini" style="margin-bottom:20px">
    Aplica al HUB que ya está funcionando las tablas, las columnas, los permisos
    y las listas que trae esta versión. No borra nada y se puede pasar más de
    una vez. <strong>El módulo 2 trae tablas y columnas nuevas, así que esta
    página hay que abrirla sí o sí después de subir el ZIP.</strong>
  </p>

  <?php foreach ($errores as $x): ?>
    <div class="aviso aviso--rojo" style="margin-bottom:12px"><span><?= e($x) ?></span></div>
  <?php endforeach; ?>

  <?php if ($hecho): ?>
    <div class="aviso aviso--verde" style="margin-bottom:12px">
      <span><strong>Listo.</strong> Ya puedes volver al HUB.</span>
    </div>
    <ul class="mini" style="margin:0 0 18px 18px">
      <?php foreach ($hecho as $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
    </ul>
    <p class="mini"><strong>Ahora borra este archivo</strong> (actualizar.php) desde el
       Administrador de archivos de cPanel.</p>
    <p style="margin-top:16px"><a class="btn btn--negro" href="<?= e(url('/inicio')) ?>">VOLVER AL HUB</a></p>
  <?php else: ?>
    <form method="post">
      <?= campo_csrf() ?>
      <button class="btn btn--negro" type="submit">ACTUALIZAR AHORA</button>
      <a class="btn" href="<?= e(url('/inicio')) ?>" style="margin-left:8px">Cancelar</a>
    </form>
  <?php endif; ?>
</main>
</body></html>
