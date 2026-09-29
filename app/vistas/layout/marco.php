<?php
/** El marco con menú: barra lateral en computadora, barra inferior en celular. */
require_once HUB_VISTAS . '/layout/iconos.php';
$op       = $__opciones ?? [];
$sin_menu = !empty($op['sin_menu']);
$menu     = $sin_menu ? [] : menu_de($usuario);
$barra    = $sin_menu ? [] : barra_movil($usuario);
$mis_avisos = avisos();
?><!doctype html>
<?php
/* EL MODO NOCHE SE APAGABA SOLO. El JS tiene un temporizador que cada minuto
   corrige el tema si la persona lo dejó en «auto» y la página estuvo abierta
   cruzando las 7 p. m. Ese temporizador se protege con dos banderas —
   data-auto y data-forzado— que LEÍA pero que aquí no las escribía nadie:
   `undefined !== '0'` es verdadero, así que la guarda no frenaba nunca y a los
   sesenta segundos la pantalla se ponía en blanco en mitad de un formulario,
   aunque la persona hubiera elegido «oscuro» a propósito. */
$tema_pref = (string)($usuario['tema'] ?? 'auto');
?>
<html lang="es" data-theme="<?= e(tema()) ?>"
      data-auto="<?= $tema_pref === 'auto' ? '1' : '0' ?>"
      data-forzado="<?= $tema_pref === 'auto' ? '0' : '1' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= tema() === 'oscuro' ? '#0F0F0D' : '#FAD91A' ?>">
<title><?= e(($op['titulo'] ?? 'Inicio') . ' · HUB Waka') ?></title>
<link rel="stylesheet" href="<?= e(activo('assets/css/hub.css')) ?>">
<link rel="manifest" href="<?= e(url('assets/manifest.webmanifest')) ?>">
<link rel="icon" href="<?= e(url('assets/img/icono-192.png')) ?>" >
<link rel="apple-touch-icon" href="<?= e(url('assets/img/icono-180.png')) ?>">
</head>
<?php
/* El aviso de pagos nuevos viaja en el <body> para que funcione en CUALQUIER
   pantalla, no solo en la bandeja: facturación puede estar mirando un pedido
   cuando entra una venta y tiene que enterarse igual.
   A Dirección no se le pone, aunque pueda confirmar: ver le_avisamos_de_pagos(). */
$avisa_pagos = !$sin_menu && le_avisamos_de_pagos($usuario);
$pagos_ahora = $avisa_pagos
    ? pagos_por_validar_resumen($usuario)
    : ['n' => 0, 'ultimo' => 0];

/* El gemelo, para el otro lado del mismo trámite: al asesor le avisamos cuando
   facturación le desbloquea una venta. Nadie lleva los dos sondeos —al asesor
   no le avisamos de pagos y a facturación no le toca despachar—, así que cada
   persona hace UNA petición cada pocos segundos, no dos. En hosting compartido
   esa diferencia es real. */
$avisa_desp  = !$sin_menu && le_avisamos_de_despacho($usuario);
$desp_ahora  = $avisa_desp
    ? pedidos_por_despachar_resumen($usuario)
    : ['n' => 0, 'ultimo' => 0];

/* Los pagos suyos que facturación dejó en espera o denegó. Viajan en el MISMO
   sondeo del despacho —ver nuevosdespacho.php— así que aquí solo hace falta la
   marca de agua de arranque. */
$avisa_trab  = !$sin_menu && le_avisamos_de_trabados($usuario);
/* La respuesta a sus descuentos (3d) viaja en el mismo sondeo: se enciende
   para quien registra ventas. */
$avisa_desc  = !$sin_menu && $usuario && puede_el($usuario, 'pedidos.crear') && descuentos_disponibles();
$trab_ahora  = $avisa_trab ? pagos_trabados_resumen($usuario) : ['n' => 0, 'ultimo' => 0];

/* 5b · LAS NOTIFICACIONES. Quien no tenía sondeo (Almacén, Dirección,
   Marketing) pregunta por las suyas; los demás las reciben en el suyo. Lo
   «emergente» que falta ver (el aviso general, la pre venta nueva) sale al
   entrar, en una ventana encima de la pantalla, una sola vez. */
$con_notif   = !$sin_menu && $usuario && function_exists('notif_listo') && notif_listo();
$notif_sondea = $con_notif && !$avisa_pagos && !($avisa_desp || $avisa_trab || $avisa_desc);
$notif_al_entrar = $con_notif ? notif_para_mi($usuario, true, 3) : [];
$push_clave = '';
if ($con_notif && push_listo()) {
    try { $push_clave = push_vapid()['publica']; } catch (Throwable $ex) { $push_clave = ''; }
}
?>
<body data-raiz="<?= e(url('')) ?>"
      <?php /* De quién es la marca guardada entre páginas: la usan los dos sondeos. */ ?>
      <?php if ($avisa_pagos || $avisa_desp || $avisa_trab || $avisa_desc || $con_notif): ?>
        data-usuario="<?= (int)($usuario['id'] ?? 0) ?>"
      <?php endif; ?>
      <?php if ($notif_sondea): ?>data-notif-sondea="1"<?php endif; ?>
      <?php if ($con_notif): ?>data-notif="1" data-t="<?= e(csrf()) ?>"<?php endif; ?>
      <?php if ($push_clave !== ''): ?>data-push-clave="<?= e($push_clave) ?>"<?php endif; ?>
      <?php if ($avisa_pagos): ?>
        data-avisa-pagos="1"
        data-pagos-n="<?= (int)$pagos_ahora['sin_revisar'] ?>"
        data-pagos-ultimo="<?= (int)$pagos_ahora['ultimo'] ?>"
        data-reclamos-ultimo="<?= (int) reclamos_marca() ?>"
      <?php endif; ?>
      <?php /* El sondeo del asesor lleva las dos noticias. Se enciende si le toca
               CUALQUIERA de las dos: colgar los trabados del permiso de
               despacho dejaba a un asesor sin ese permiso viendo la lista en
               Inicio y sin recibir jamás el aviso. */ ?>
      <?php if ($avisa_desp || $avisa_trab || $avisa_desc): ?>
        data-avisa-despacho="1"
        data-desc-ultimo="<?= (int) reclamos_marca() ?>"
        data-despacho-n="<?= (int)$desp_ahora['n'] ?>"
        data-despacho-ultimo="<?= (int)$desp_ahora['ultimo'] ?>"
        data-trabados-n="<?= (int)$trab_ahora['n'] ?>"
        <?php /* Las rachas del equipo (5a) viajan en este mismo sondeo. */ ?>
        data-racha-ultimo="<?= function_exists('racha_avisos_desde') && tabla_existe('racha_avisos') ? (int) racha_avisos_desde($usuario, -1)['ultimo'] : -1 ?>"
      <?php endif; ?>>
<div class="app">

<?php if (!$sin_menu): ?>
  <nav class="lateral">
    <?php /* El logo lleva al Inicio (usuario, 2026-09-23): es lo primero que
             se pulsa cuando uno quiere volver al principio, y no llevaba a
             ningún sitio. */ ?>
    <a class="lateral__inicio" href="<?= e(url('/inicio')) ?>" title="Ir al inicio">
      <img class="lateral__logo" src="<?= e(url('assets/img/logo-waka.png')) ?>" alt="Waka Importaciones · ir al inicio">
    </a>
    <div class="lateral__lista">
      <?php foreach ($menu as $m): ?>
        <a class="nv<?= $m['activo'] ? ' on' : '' ?>" href="<?= e($m['url']) ?>">
          <?= ico($m['icono']) ?>
          <span><?= e($m['texto']) ?></span>
          <?php if (!empty($m['contador'])): ?>
            <span class="nv__chip"><?= (int)$m['contador'] ?></span>
          <?php elseif (!empty($m['solo_lectura'])): ?>
            <span class="nv__ojo" title="Solo miras"><?= ico('ojo', 13) ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <a class="lateral__yo" href="<?= e(url('/mi-perfil')) ?>">
      <?= avatar($usuario, 32, true) ?>
      <span>
        <b><?= e(primer_nombre($usuario['nombre'])) ?></b>
        <span><?= e($usuario['rol_nombre']) ?><?= $usuario['oficina'] ? ' · ' . e($usuario['oficina']) : '' ?></span>
      </span>
    </a>
    <div class="lateral__pie">
      Waka Importaciones · Todos los derechos reservados <?= date('Y') ?>
      <b>Desarrollado por Bloomit.pe</b>
      <?php /* La versión subida, para no tener que adivinar si un fallo es del
               código o de la subida. Ver HUB_VERSION en arranque.php. */ ?>
      <span class="lateral__version">versión <?= e(HUB_VERSION) ?>
        · <?= e(fecha_corta(HUB_VERSION_FECHA)) ?></span>
    </div>
  </nav>
<?php endif; ?>

  <main class="principal">
    <div class="contenido">

      <?php if (!empty($op['titulo']) && empty($op['sin_titulo'])): ?>
        <header class="cabecera">
          <div>
            <?php if (!empty($op['migaja'])): ?>
              <div class="cabecera__sub"><?= e($op['migaja']) ?></div>
            <?php endif; ?>
            <h1><?= e($op['titulo']) ?></h1>
            <?php if (!empty($op['subtitulo'])): ?>
              <div class="cabecera__sub"><?= e($op['subtitulo']) ?></div>
            <?php endif; ?>
          </div>
          <?php if (!empty($op['acciones'])): ?>
            <?php /* HTML de confianza: lo escribe el controlador, NUNCA datos de la base. */ ?>
            <div class="cabecera__acciones"><?= $op['acciones'] ?></div>
          <?php endif; ?>
        </header>
      <?php endif; ?>

      <?php if ($mis_avisos): ?>
        <div class="avisos">
          <?php foreach ($mis_avisos as $a): ?>
            <div class="aviso aviso--<?= $a['tipo'] === 'error' ? 'rojo' : ($a['tipo'] === 'ok' ? 'verde' : 'gris') ?>">
              <?= e($a['texto']) ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php require HUB_VISTAS . '/' . $__vista . '.php'; ?>
    </div>
  </main>
</div>

<?php if (!$sin_menu): ?>
  <nav class="barra">
    <?php foreach ($barra as $m): ?>
      <a href="<?= e($m['url']) ?>" class="<?= $m['activo'] ? 'on' : '' ?>">
        <span class="barra__ico"><?= ico($m['icono']) ?></span>
        <?php /* En la barra de abajo manda el rótulo CORTO si la sección tiene
                 uno: «Pagos por validar» partía en dos renglones y empujaba la
                 barra entera. El corto vive en menu.php, con el resto de la
                 sección, para que no haya dos sitios que decidan cómo se
                 llama una cosa. */ ?>
        <?= e($m['corto'] ?? $m['texto']) ?>
        <?php if (!empty($m['contador'])): ?>
          <span class="barra__chip"><?= (int)$m['contador'] ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
<?php endif; ?>

<?php if ($notif_al_entrar): ?>
  <?php /* Los avisos que faltaba ver, uno detrás de otro, en la misma ventana. */ ?>
  <script type="application/json" id="notif-al-entrar"><?= json_encode($notif_al_entrar, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>

<script src="<?= e(activo('assets/js/hub.js')) ?>" defer></script>
</body>
</html>
