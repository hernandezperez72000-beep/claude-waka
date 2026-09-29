<?php
// La contraseña temporal se enseña una sola vez y solo en el regreso del alta:
// se compara el id, y se borra de la sesión SIEMPRE que se pinta esta lista,
// se haya llegado a mostrar o no.
$nuevo_id = pedir_int('nuevo', 'get');
$temporal = $_SESSION['clave_temporal'] ?? null;
unset($_SESSION['clave_temporal']);
if (!$temporal || !$nuevo_id || (int)($temporal['id'] ?? 0) !== $nuevo_id) $temporal = null;
?>

<header class="cabecera">
  <div>
    <div class="cabecera__sub">Configuración</div>
    <h1>Usuarios y roles</h1>
    <div class="cabecera__sub">
      <?= plural($resumen['total'], 'cuenta', 'cuentas') ?> ·
      <?= plural($resumen['asesores'], 'asesor activo', 'asesores activos') ?>
      <?php if ($resumen['sin_equipo']): ?>
        · <?= plural($resumen['sin_equipo'], 'sin equipo', 'sin equipo') ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="cabecera__acciones">
    <?php if (puede('usuarios.gestionar')): ?>
      <a class="btn btn--negro" href="<?= e(url('/usuarios/nuevo')) ?>"><?= ico('mas',16) ?> NUEVO USUARIO</a>
    <?php endif; ?>
  </div>
</header>

<?php if ($temporal): ?>
  <?php
    // Mensaje listo para pegar en WhatsApp: son 19 altas, y escribirlo a mano
    // cada vez es justo donde se cuelan los errores de dictado.
    $wa = "Hola " . primer_nombre($temporal['nombre']) . ", ya tienes tu acceso al HUB de Waka.\n\n"
        . "Entra a: " . (($_SERVER['HTTP_HOST'] ?? 'wakalatamcorp.com')) . "\n"
        . "Correo: " . $temporal['email'] . "\n"
        . "Contraseña temporal: " . $temporal['clave'] . "\n\n"
        . "Al entrar te va a pedir que la cambies por una tuya. Desde ahí nadie más la conoce.";
  ?>
  <div class="velo" id="velo-clave" role="dialog" aria-modal="true" aria-labelledby="clave-titulo">
    <div class="clave-caja">
      <div class="clave-caja__icono"><?= ico('candado', 22) ?></div>

      <h2 id="clave-titulo">
        <?= !empty($temporal['reset']) ? 'Contraseña nueva' : 'Cuenta creada' ?>
      </h2>
      <p class="clave-caja__quien">
        <?= e($temporal['nombre']) ?> · <?= e($temporal['email']) ?>
        <?php if (!empty($temporal['reset'])): ?>
          <br>La anterior dejó de funcionar y sus sesiones quedaron cerradas.
        <?php endif; ?>
      </p>

      <div class="clave-valor">
        <span class="clave-valor__rotulo">Contraseña temporal</span>
        <code id="clave-texto"><?= e($temporal['clave']) ?></code>
      </div>

      <div class="clave-caja__botones">
        <button type="button" class="btn btn--linea" data-copiar="clave">Copiar contraseña</button>
        <button type="button" class="btn btn--amarillo" data-copiar="wa">Copiar mensaje</button>
      </div>
      <textarea id="clave-wa" hidden><?= e($wa) ?></textarea>

      <div class="clave-caja__ojo">
        <strong>Esta es la única vez que verás esta contraseña.</strong>
        Si se pierde, genera otra con <strong>Nueva contraseña</strong> en su fila.
      </div>

      <div class="clave-caja__cerrar">
        <a href="<?= e(url('/usuarios')) ?>" id="clave-cerrar">Ya la copié, cerrar</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($resumen['sin_equipo']): ?>
  <div class="aviso aviso--gris" style="margin-bottom:14px">
    <span><?= ico('personas',17) ?></span>
    <span><strong><?= plural($resumen['sin_equipo'],'asesor sin equipo','asesores sin equipo') ?>.</strong>
      Trabajan igual: sus ventas cuentan en meta, podio y reportes. Solo no salen en el
      ranking por equipos.</span>
  </div>
<?php endif; ?>

<form method="get" class="filtros" style="align-items:center">
  <?php foreach ([['activos','Activos'],['todos','Todos'],['sin_equipo','Sin equipo'],['apagados','Apagados']] as [$k,$t]): ?>
    <a class="<?= $filtro === $k ? 'on' : '' ?>" href="?f=<?= e($k) ?><?= $busca ? '&q=' . urlencode($busca) : '' ?>"><?= e($t) ?></a>
  <?php endforeach; ?>
  <span style="flex-grow:1"></span>
  <input type="hidden" name="f" value="<?= e($filtro) ?>">
  <input type="search" name="q" value="<?= e($busca) ?>" placeholder="Buscar por nombre o correo"
         style="border:1.5px solid var(--linea);background:var(--tarjeta);border-radius:999px;padding:9px 16px;font-size:12.5px;min-width:230px">
</form>

<?php if (!$usuarios): ?>
  <div class="tarjeta">
    <?php parte('inicio/vacio', [
      'ico' => '01', 'marca' => true,
      'titulo' => $busca ? 'Nada coincide con lo que buscas' : 'Todavía no hay usuarios',
      'texto'  => $busca
          ? 'Revisa el nombre o el correo, o prueba con menos filtros.'
          : 'Da de alta a los asesores con su equipo, su oficina y su nivel. Se crean con una contraseña temporal que cada uno cambia al entrar.',
      'boton'  => $busca ? 'QUITAR LOS FILTROS' : 'NUEVO USUARIO',
      'ruta'   => $busca ? '/usuarios' : '/usuarios/nuevo',
    ]); ?>
  </div>
<?php else: ?>
  <div class="tabla__caja">
    <table class="tabla">
      <thead>
        <tr>
          <th>Persona</th><th>Rol</th><th>Equipo</th><th>Oficina</th>
          <th>Ámbito</th><th class="der">Meta</th><th class="der">Último acceso</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($usuarios as $x): ?>
        <tr class="<?= (int)$x['activo'] === 0 ? 'apagada' : '' ?>">
          <td class="principal">
            <div style="display:flex;align-items:center;gap:11px">
              <?= avatar($x, 32) ?>
              <div style="min-width:0">
                <div class="fila__t recorta"><?= e(trim($x['nombre'] . ' ' . ($x['apellidos'] ?? ''))) ?></div>
                <div class="fila__s recorta"><?= e($x['email']) ?></div>
              </div>
            </div>
          </td>
          <td data-k="Rol"><span class="chip chip--gris"><?= e($x['rol_nombre']) ?></span></td>
          <td data-k="Equipo"><?= $x['equipo'] ? e($x['equipo']) : '<span class="muted">Sin equipo</span>' ?></td>
          <td data-k="Oficina"><?= e($x['oficina'] ?: '—') ?></td>
          <td data-k="Ámbito">
            <?php $amb = ['propio'=>'Lo suyo','equipo'=>'Su equipo','todo'=>'Todo']; ?>
            <span class="mini"><?= e($amb[$x['ambito']] ?? $x['ambito']) ?><?= $x['ambito']==='equipo' ? ' · solo lee' : '' ?></span>
          </td>
          <td class="der num" data-k="Meta">
            <?php if ($x['rol'] !== 'asesor'): ?>
              <span class="muted">—</span>
            <?php elseif ($x['meta_mensual_centimos']): ?>
              <?= e(soles_corto((int)$x['meta_mensual_centimos'])) ?>
              <span class="mini">excepción</span>
            <?php elseif ($meta_general !== null): ?>
              <?= e(soles_corto($meta_general)) ?>
            <?php else: ?>
              <span class="mini">la general de su país</span>
            <?php endif; ?>
          </td>
          <td class="der" data-k="Último acceso">
            <span class="mini"><?= $x['ultimo_acceso'] ? e(hace($x['ultimo_acceso'])) : 'nunca entró' ?></span>
          </td>
          <td class="der">
            <?php
              // Los botones solo salen donde el servidor los va a aceptar: nadie
              // toca una cuenta de su mismo nivel o por encima. El servidor lo
              // comprueba igual (esconder no es seguridad), pero ofrecer un botón
              // que va a devolver 403 es una promesa que el HUB no puede cumplir.
              $mia   = (int)$x['id'] === (int)$usuario['id'];
              $tocar = puede('usuarios.gestionar') && ($mia || puedo_tocar_rol((string)$x['rol']));
            ?>
            <?php if ($tocar): ?>
              <a class="chip chip--linea" href="<?= e(url('/usuarios/editar?id=' . (int)$x['id'])) ?>">Editar</a>
              <?php if (!$mia): ?>
                <form method="post" action="<?= e(url('/usuarios/estado')) ?>" style="display:inline">
                  <?= campo_csrf() ?>
                  <input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
                  <button class="chip chip--suave" type="submit" style="cursor:pointer">
                    <?= (int)$x['activo'] === 1 ? 'Apagar' : 'Encender' ?>
                  </button>
                </form>
                <form method="post" action="<?= e(url('/usuarios/clave')) ?>" style="display:inline">
                  <?= campo_csrf() ?>
                  <input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
                  <button class="chip chip--suave" type="submit" style="cursor:pointer">Nueva contraseña</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="mini" style="margin-top:12px">
    <strong>Apagar no borra.</strong> La persona deja de entrar, pero sus ventas siguen en los reportes.
  </p>
<?php endif; ?>
