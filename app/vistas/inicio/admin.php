<?php /** Inicio de Administración y Dirección: una bandeja, no un tablero. */
$total_bandeja = 0; foreach ($bandeja as $b) $total_bandeja += (int)$b['n'];
$ve_bitacora    = $ve_bitacora ?? true;
$ve_recepcion   = $ve_recepcion ?? true;
$cruza          = $cruza_paises ?? false;
/* El rótulo tiene que decir lo que la cifra cuenta. Decía «en tu país» para
   todo el mundo mientras el chip de arriba decía «todos los países», y con
   Perú y México encendidos eso son dos afirmaciones contrarias a diez
   centímetros de distancia.
   El dinero no cruza —soles y pesos no se suman— así que sus tarjetas nombran
   el país. Lo que se cuenta en unidades sí cruza para quien cruza. */
$pais_dinero    = $pais_dinero ?? ($usuario['pais'] ?? 'Perú');
$en_dinero      = $cruza ? 'en ' . $pais_dinero : 'en tu país';
$donde          = $cruza ? 'en todos los países' : 'en tu país';
?>
<header class="cabecera">
  <div>
    <div class="cabecera__sub"><?= e($fecha) ?></div>
    <h1><?= e($saludo) ?></h1>
    <?php if ($frase): ?><div class="cabecera__sub"><?= e($frase) ?></div><?php endif; ?>
    <?php /* La línea de datos se calculaba para todo el mundo y solo la pintaba
             el panel del asesor: cuatro consultas por página que nadie leía.
             Aquí es donde tiene sentido —«2 pagos esperando confirmación»— y
             cada pieza suya ya va por permiso, así que a nadie le habla de una
             pantalla que le va a contestar 403. */ ?>
    <?php if ($linea): ?><div class="cabecera__sub"><?= e($linea) ?></div><?php endif; ?>
  </div>
  <div class="cabecera__acciones">
    <?php /* Administración PUEDE registrar una venta —tiene el permiso desde el
             primer día— pero su Inicio no tenía por dónde empezar una: había
             que ir a Pedidos y buscar el botón. Lo reportó el usuario el
             2026-09-10. Va por permiso y no por rol: a Dirección y a
             Facturación, que no venden, no les sale. */ ?>
    <?php if (puede('pedidos.crear')): ?>
      <a class="btn btn--negro" href="<?= e(url('/pedidos/nuevo')) ?>"><?= ico('mas', 16) ?> NUEVO PEDIDO</a>
    <?php endif; ?>
    <?php /* Por cruza_paises() y no por rol: el Desarrollador también cruza, y
             con la comprobación por rol el chip decía «Perú» mientras las
             tarjetas de abajo decían «en todos los países». */ ?>
    <span class="chip chip--linea"><?= e($usuario['pais'] ?? 'Perú') ?><?= $cruza ? ' · todos los países' : '' ?></span>
  </div>
</header>

<?php if (!empty($cumplen)): ?>
  <?php /* El cumpleaños del equipo, el día que toca. Una línea, y solo hoy. */ ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px">
    <span><?= ico('medalla',17) ?></span>
    <span><strong>Hoy cumple años
      <?= e(implode(' y ', array_map(fn($c) => trim((string)$c['nombre'] . ' ' . (string)$c['apellidos']), $cumplen))) ?>.</strong>
      Un saludo del equipo se agradece.</span>
  </div>
<?php endif; ?>

<?php if ($bandeja): ?>
  <div class="tira" style="margin-bottom:14px">
    <div>
      <div class="tira__k">Tu bandeja</div>
      <div class="tira__t"><?= plural($total_bandeja, 'cosa espera por ti', 'cosas esperan por ti') ?></div>
    </div>
    <div class="tira__chips">
      <?php foreach ($bandeja as $b): ?>
        <a class="tira__chip <?= $b['urgente'] ? 'urgente' : '' ?>" href="<?= e(url($b['ruta'])) ?>">
          <?= (int)$b['n'] ?> <?= e($b['texto']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php else: ?>
  <div class="aviso aviso--verde" style="margin-bottom:14px">
    <span><?= ico('check', 17) ?></span>
    <span><strong>Bandeja limpia.</strong> No hay errores reportados, ni contraseñas por aprobar,
          ni pagos sin verificar.</span>
  </div>
<?php endif; ?>

<div class="rejilla rejilla--4" style="margin-bottom:14px">
  <div class="cifra">
    <span class="cifra__k">Vendido hoy</span>
    <span class="cifra__v"><?= e(soles_corto($vendido_hoy)) ?></span>
    <span class="cifra__d"><?= e($en_dinero) ?></span>
  </div>
  <div class="cifra">
    <span class="cifra__k">Cobrado hoy</span>
    <span class="cifra__v verde"><?= e(soles_corto($cobrado_hoy)) ?></span>
    <span class="cifra__d verde"><?= $vendido_hoy > 0 ? round($cobrado_hoy*100/max(1,$vendido_hoy)) . '% de lo vendido' : 'sin ventas todavía' ?></span>
  </div>
  <div class="cifra">
    <span class="cifra__k">Por cobrar</span>
    <span class="cifra__v ambar"><?= e(soles_corto($por_cobrar)) ?></span>
    <span class="cifra__d">de los pedidos abiertos <?= e($en_dinero) ?></span>
  </div>
  <div class="cifra">
    <span class="cifra__k">Clientes nuevos</span>
    <span class="cifra__v"><?= (int)$clientes_semana ?></span>
    <span class="cifra__d">esta semana <?= e($donde) ?></span>
  </div>
</div>

<div class="rejilla rejilla--panel">
  <div>
    <div class="tarjeta" style="margin-bottom:12px">
      <div class="tarjeta__cab">
        <h2>Los equipos este mes · cobrado<?= $cruza ? ' · ' . e($usuario['pais'] ?? 'Perú') : '' ?></h2>
        <a class="mini" href="<?= e(url('/reportes')) ?>" style="text-decoration:underline">Ver el reporte</a>
      </div>
      <?php if (!$equipos): ?>
        <?php parte('inicio/vacio', ['ico'=>'EQ','titulo'=>'Todavía no hay equipos',
              'texto'=>'Los tres equipos se crean en Configuración y se llenan al dar de alta a cada asesor.',
              'boton'=>'IR A USUARIOS','ruta'=>'/usuarios']); ?>
      <?php else: $tope = max(1, (int)($equipos[0]['cobrado'] ?? 1)); ?>
        <?php foreach ($equipos as $i => $eq): ?>
          <div style="padding:9px 0;border-bottom:1px solid var(--linea-suave)">
            <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px">
              <span class="fila__t"><?= $i+1 ?>.º <?= e($eq['nombre']) ?></span>
              <span class="mini"><?= plural((int)$eq['gente'], 'asesor', 'asesores') ?></span>
              <span class="fila__t num"><?= e(soles_corto((int)$eq['cobrado'])) ?></span>
            </div>
            <div class="pista" style="margin-top:7px">
              <div class="pista__llena <?= $i === 0 ? 'marca' : '' ?>" style="width:<?= (int)round(((int)$eq['cobrado'])*100/$tope) ?>%"></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($top): ?>
        <div class="tarjeta__cab" style="margin:14px 0 8px"><h2 style="font-size:13.5px">Los que más cobran este mes</h2></div>
        <?php $tope2 = max(1, (int)($top[0]['cobrado'] ?? 1)); ?>
        <?php foreach ($top as $i => $p): ?>
          <div style="display:flex;align-items:center;gap:12px;padding:5px 0">
            <span class="mini" style="width:22px"><?= $i+1 ?>.º</span>
            <span class="fila__t" style="width:110px" ><?= e(primer_nombre($p['nombre'])) ?></span>
            <span class="pista pista--fina" style="flex-grow:1">
              <span class="pista__llena" style="display:block;width:<?= (int)round(((int)$p['cobrado'])*100/$tope2) ?>%"></span>
            </span>
            <span class="fila__t num" style="width:86px;text-align:right"><?= e(soles_corto((int)$p['cobrado'])) ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <?php if ($ve_recepcion): ?>
    <div class="tarjeta" style="margin-bottom:12px">
      <div class="tarjeta__cab">
        <h2>En recepción ahora<?= $cruza ? ' · todas las oficinas' : '' ?></h2>
        <?php if ($visitas): ?><span class="chip chip--marca"><?= count($visitas) ?></span><?php endif; ?>
      </div>
      <?php if (!$visitas): ?>
        <?php parte('inicio/vacio', ['ico'=>'RC','titulo'=>'No hay nadie esperando',
              'texto'=>'Cuando recepción avise que llegó un cliente aparece aquí, y al asesor le llega la notificación con sonido.',
              'boton'=>'AVISAR QUE LLEGÓ UN CLIENTE','ruta'=>'/recepcion']); ?>
      <?php else: ?>
        <?php foreach ($visitas as $v): ?>
          <div class="fila">
            <span class="av av--40" style="border-radius:10px;background:#000;color:var(--marca);font-size:11px"><?= e($v['modulo']) ?></span>
            <div class="fila__crece">
              <div class="fila__t recorta"><?= e($v['cliente_nombre'] ?: 'Cliente') ?></div>
              <div class="fila__s"><?= $v['asesor'] ? 'Viene por ' . e($v['asesor']) : 'Sin asesor asignado' ?></div>
            </div>
            <span class="mini"><?= e(hace($v['creado_en'])) ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$ve_recepcion && !$ve_bitacora): ?>
      <?php /* Facturación no ve ni recepción ni la bitácora. Sin esto la
               columna derecha se queda vacía, y una columna vacía se lee como
               "algo no cargó", no como "aquí no hay nada para ti". */ ?>
      <div class="tarjeta">
        <div class="tarjeta__cab"><h2>Atajos</h2></div>
        <a class="fila" href="<?= e(url('/pagos/por-validar')) ?>"><?= ico('tarjeta') ?><span class="fila__crece fila__t">Pagos sin revisar</span><?= ico('flecha',14) ?></a>
        <a class="fila" href="<?= e(url('/reportes/pagos')) ?>"><?= ico('barras') ?><span class="fila__crece fila__t">Reporte de pagos · Excel</span><?= ico('flecha',14) ?></a>
        <a class="fila" href="<?= e(url('/pedidos')) ?>"><?= ico('caja') ?><span class="fila__crece fila__t">Ver pedidos</span><?= ico('flecha',14) ?></a>
        <a class="fila" href="<?= e(url('/reportar-error')) ?>"><?= ico('alerta') ?><span class="fila__crece fila__t">Reportar un error</span><?= ico('flecha',14) ?></a>
      </div>
    <?php endif; ?>

    <?php if ($ve_bitacora): ?>
    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Lo último que se tocó</h2>
        <a class="mini" href="<?= e(url('/reportes/bitacora')) ?>" style="text-decoration:underline">Ver todo</a></div>
      <?php if (!$bitacora): ?>
        <p class="mini" style="margin:0">Todavía no hay movimientos registrados.</p>
      <?php else: foreach ($bitacora as $b): ?>
        <?php /* Cada línea dice SOBRE QUÉ. «Pedido · comprobante — Paul, hace
                 3 min» no decía de qué pedido, y una bitácora que no nombra el
                 sujeto es ruido con fecha. */ ?>
        <?php $suj = bitacora_sujeto($b); ?>
        <div style="padding:7px 0;border-bottom:1px solid var(--linea-suave)">
          <div class="fila__t" style="line-height:1.35">
            <?php if ($suj['ruta'] !== ''): ?>
              <a href="<?= e(url($suj['ruta'])) ?>" style="color:inherit"><?= e(bitacora_frase($b)) ?></a>
            <?php else: ?><?= e(bitacora_frase($b)) ?><?php endif; ?>
          </div>
          <?php if ($suj['texto'] !== ''): ?>
            <div class="fila__s"><strong><?= e($suj['texto']) ?></strong></div>
          <?php endif; ?>
          <div class="fila__s"><?= e($b['nombre'] ? primer_nombre($b['nombre']) : (str_starts_with((string)$b['accion'], 'acceso.') ? 'Sin sesión' : 'Automático')) ?> · <?= e(hace($b['creado_en'])) ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
/** La bitácora se lee en castellano, no en claves. */
/* texto_bitacora() se mudó a app/nucleo/bitacora.php como bitacora_frase():
   ahora la usan la tarjeta del panel y el reporte completo, y una frase que se
   escribe en dos sitios acaba diciendo dos cosas distintas del mismo hecho. */
?>
