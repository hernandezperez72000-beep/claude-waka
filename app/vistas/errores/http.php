<?php
$titulos = [
  403 => 'Esto no es tuyo', 404 => 'Esta página no existe',
  419 => 'La página caducó', 503 => 'Sin servicio por un momento',
];
$t = $datos['titulo'] ?: ($titulos[$datos['codigo']] ?? 'Algo salió mal');
$x = $datos['texto'] ?: 'Vuelve al inicio y prueba otra vez.';
$oscuro = function_exists('es_de_noche') ? es_de_noche() : false;
?><!doctype html>
<html lang="es" data-theme="<?= $oscuro ? 'oscuro' : 'claro' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($t) ?> · HUB Waka</title>
<link rel="stylesheet" href="<?= e(function_exists('activo') ? activo('assets/css/hub.css') : url('assets/css/hub.css')) ?>">
</head>
<body>
<div class="error-http">
  <div class="error-http__caja">
    <img src="<?= e(url('assets/img/logo-waka.png')) ?>" alt="Waka" style="width:130px<?= $oscuro ? ';filter:invert(1)' : '' ?>">
    <div class="error-http__n">Error <?= (int)$datos['codigo'] ?></div>
    <h1><?= e($t) ?></h1>
    <p><?= e($x) ?></p>
    <div style="display:flex;gap:10px;margin-top:6px">
      <a class="btn btn--negro" href="<?= e(url('/inicio')) ?>">Volver al inicio</a>
      <a class="btn btn--linea" href="<?= e(url('/reportar-error')) ?>">Reportar un error</a>
    </div>
  </div>
</div>
</body>
</html>
