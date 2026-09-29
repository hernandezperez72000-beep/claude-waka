<?php
/** Marco sin menú: acceso, bienvenida y celebraciones. */
require_once HUB_VISTAS . '/layout/iconos.php';
$oscuro = $oscuro ?? es_de_noche();
?><!doctype html>
<html lang="es" data-theme="<?= $oscuro ? 'oscuro' : 'claro' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= $oscuro ? '#000000' : '#FAD91A' ?>">
<title>HUB Waka</title>
<link rel="stylesheet" href="<?= e(activo('assets/css/hub.css')) ?>">
<link rel="manifest" href="<?= e(url('assets/manifest.webmanifest')) ?>">
<link rel="icon" href="<?= e(url('assets/img/icono-192.png')) ?>" >
<link rel="apple-touch-icon" href="<?= e(url('assets/img/icono-180.png')) ?>">
</head>
<body class="pantalla-sola" data-raiz="<?= e(url('')) ?>"<?= !empty($limpiar_cache) ? ' data-limpiar="1"' : '' ?>>
<?php require HUB_VISTAS . '/' . $vista . '.php'; ?>
<script src="<?= e(activo('assets/js/hub.js')) ?>" defer></script>
</body>
</html>
