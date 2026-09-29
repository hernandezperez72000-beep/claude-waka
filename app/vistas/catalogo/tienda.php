<?php
/* Stock y pre venta › Traer de la tienda (módulo 3b). */
$s = $plan['resumen'] ?? null;
$hay_algo = $s && ($s['crear'] + $s['actualizar'] + $s['var_crear'] + $s['var_actualizar'] + ($s['fotos'] ?? 0)) > 0;
$se_puede_guardar = $lectura && $lectura['estado'] === 'leida' && !$cambio && $hay_algo && !$otro_pais;

/* Qué cambia en un producto, en palabras. */
$que_cambia = function (array $p): array {
    $t = [];
    foreach ($p['cambios'] as $k => [$antes, $ahora]) {
        $t[] = match ($k) {
            'nombre'    => 'Nombre: «' . $antes . '» → «' . $ahora . '»',
            'categoria' => 'Categoría: ' . ($antes !== '' ? $antes : 'sin categoría') . ' → ' . $ahora,
            'enlace'    => $antes !== '' ? 'Se vuelve a enlazar con la tienda' : 'Queda enlazado con la tienda',
            default     => '',
        };
    }
    $vc = count(array_filter($p['variantes'], fn($v) => $v['accion'] === 'crear'));
    $va = count(array_filter($p['variantes'], fn($v) => $v['accion'] === 'actualizar'));
    if ($p['accion'] !== 'crear' && ($p['tramos'] ?? [])) {
        $t[] = 'Estaba sin precio: toma el de la web, ' . soles((int)$p['tramos'][0]['precio'])
             . (count($p['tramos']) > 1 ? ' (algunos colores con otro)' : '');
    }
    if ($vc) $t[] = plural($vc, 'color o modelo nuevo', 'colores o modelos nuevos');
    if ($va) $t[] = plural($va, 'color o modelo cambia', 'colores o modelos cambian');
    return array_values(array_filter($t));
};
$nuevos  = $plan ? array_values(array_filter($plan['productos'], fn($p) => $p['accion'] === 'crear')) : [];
/* LA PRIMERA VEZ que un producto de aquí se une a uno de la tienda va aparte y
   abierto: es donde un código repetido por error juntaría dos productos
   distintos, y el usuario pidió verlo antes de guardar (2026-09-24). */
$es_primer_enlace = fn(array $p) => $p['accion'] !== 'crear' && isset($p['cambios']['enlace']) && $p['cambios']['enlace'][0] === '';
$enlazan = $plan ? array_values(array_filter($plan['productos'], $es_primer_enlace)) : [];
/* LO QUE LA WEB DICE DISTINTO (3c): con el conector manda el HUB; esto no se
   guarda solo, se decide aquí. */
$difieren = $plan ? array_values(array_filter($plan['productos'], fn($p) => (bool)($p['difiere'] ?? []))) : [];
$puedo_web = tienda_web_puede('datos');
$cambian = $plan ? array_values(array_filter($plan['productos'],
                   fn($p) => $p['accion'] !== 'crear' && !$es_primer_enlace($p) && $que_cambia($p))) : [];
?>
<header class="cabecera">
  <div>
    <div class="cabecera__sub"><a href="<?= e(url('/stock')) ?>">Stock y pre venta</a></div>
    <h1>Traer de la tienda</h1>
    <div class="cabecera__sub">Los productos y sus colores de compraenwaka</div>
  </div>
  <?php if ($cfg['listo'] && !$falta_actualizar && !$otro_pais): ?>
    <div class="cabecera__acciones">
      <form method="post" id="form-leer">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="leer">
        <button class="btn <?= $lectura ? 'btn--linea' : 'btn--negro' ?>" type="submit" data-espera="Leyendo la tienda…">
          <?= ico('flecha',16) ?> <?= $lectura ? 'LEER OTRA VEZ' : 'LEER LA TIENDA' ?></button>
      </form>
    </div>
  <?php endif; ?>
</header>

<?php if ($falta_actualizar): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:12px">
    <span><?= ico('alerta',17) ?></span>
    <span>Falta terminar la actualización para usar esta sección.</span>
  </div>
<?php elseif ($cfg['listo'] && $otro_pais): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:12px" id="aviso-otro-pais">
    <span><?= ico('alerta',17) ?></span>
    <span>La tienda está conectada para otro país. Desde aquí no se puede traer.</span>
  </div>
<?php elseif (!$cfg['listo']): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:12px">
    <span><?= ico('alerta',17) ?></span>
    <span>Primero hay que conectar la tienda
      <?php if ($puedo_config): ?>en <a href="<?= e(url('/configuracion/tienda')) ?>" style="text-decoration:underline">Configuración › Tienda</a><?php else: ?>: pídeselo a Administración<?php endif; ?>.</span>
  </div>
<?php endif; ?>

<?php if (($fotos_faltan ?? 0) > 0 && $cfg['listo'] && !$otro_pais): ?>
  <?php /* LAS FOTOS QUE FALTAN, de 40 en 40, sin volver a leer la tienda
           (3b.5). Con JavaScript sigue sola hasta el final y dice cuántas van. */ ?>
  <div class="tarjeta" style="margin-bottom:12px" id="tarjeta-fotos">
    <p style="margin:0 0 10px"><strong>Faltan <?= e(plural((int)$fotos_faltan, 'foto', 'fotos')) ?> de la web.</strong>
      <span class="mini">Se traen de 40 en 40; puedes seguir trabajando en otra pestaña.</span></p>
    <form method="post" id="form-fotos" data-fotos-auto="1" data-multiple="1">
      <?= campo_csrf() ?>
      <input type="hidden" name="accion" value="fotos">
      <button class="btn btn--negro" type="submit">TRAER LAS FOTOS QUE FALTAN</button>
      <span class="mini" id="fotos-progreso" role="status" style="margin-left:10px"></span>
    </form>
  </div>
<?php endif; ?>

<?php if (!$lectura): ?>
  <div class="tarjeta">
    <?php parte('inicio/vacio', [
      'ico' => 'TI', 'marca' => false,
      'titulo' => 'Trae el catálogo de compraenwaka',
      'texto'  => 'Se leen los productos publicados y se emparejan por su código. '
                . 'Antes de guardar ves cuántos se crean y cuántos se actualizan.',
      'boton'  => '', 'ruta' => '',
    ]); ?>
  </div>
<?php else: ?>

  <?php if ($lectura['estado'] === 'guardada'): ?>
    <div class="aviso aviso--verde" style="margin-bottom:12px" id="aviso-guardada">
      <span><?= ico('check',17) ?></span>
      <span>Esta lectura ya se guardó en el catálogo el <?= e(fecha_hora($lectura['guardada_en'])) ?></span>
    </div>
  <?php elseif ($cambio): ?>
    <div class="aviso aviso--amarillo" style="margin-bottom:12px" id="aviso-cambio">
      <span><?= ico('alerta',17) ?></span>
      <span>El catálogo cambió desde que leíste la tienda. Vuelve a leerla antes de guardar.</span>
    </div>
  <?php endif; ?>

  <p class="mini" style="margin:0 0 10px">Leída el <?= e(fecha_hora($lectura['creado_en'])) ?></p>

  <div class="rejilla rejilla--4" style="margin-bottom:12px" id="cifras-tienda">
    <div class="cifra" data-cifra="crear">
      <span class="cifra__k">Nuevos</span>
      <span class="cifra__v verde"><?= (int)$s['crear'] ?></span>
      <span class="cifra__d"><?= $s['var_crear'] ? plural((int)$s['var_crear'], 'color o modelo nuevo', 'colores o modelos nuevos') : 'productos que se crean' ?></span>
    </div>
    <div class="cifra" data-cifra="actualizar">
      <span class="cifra__k">Se actualizan</span>
      <span class="cifra__v"><?= (int)$s['actualizar'] ?></span>
      <span class="cifra__d"><?= $enlazan ? plural(count($enlazan), 'se une por primera vez', 'se unen por primera vez')
        : ($s['var_actualizar'] ? plural((int)$s['var_actualizar'], 'color o modelo cambia', 'colores o modelos cambian') : 'nombre o categoría') ?></span>
    </div>
    <div class="cifra" data-cifra="igual">
      <span class="cifra__k">Sin cambios</span>
      <span class="cifra__v"><?= (int)$s['igual'] ?></span>
      <span class="cifra__d">ya estaban al día</span>
    </div>
    <div class="cifra" data-cifra="fuera">
      <span class="cifra__k">No entran</span>
      <span class="cifra__v <?= $s['fuera'] ? 'ambar' : '' ?>"><?= (int)$s['fuera'] ?></span>
      <span class="cifra__d"><?= $s['fuera'] ? 'mira por qué abajo' : 'ninguno' ?></span>
    </div>
  </div>

  <?php if ($se_puede_guardar): ?>
    <div class="tarjeta" style="margin-bottom:12px">
      <p class="mini" style="margin:0 0 10px">En lo que ya existe, los precios y la garantía no se tocan.
        <?php if ($s['crear'] || ($s['precio_web'] ?? 0)): ?>Los que no tienen precio toman el normal de la web como único tramo.<?php endif; ?>
        <?php if ($s['fotos'] ?? 0): ?><span id="aviso-fotos">Se traen <?= e(plural((int)$s['fotos'], 'foto', 'fotos')) ?> de la web.</span><?php endif; ?>
        <?php if ($s['sin_precio'] ?? 0): ?><strong id="aviso-sin-precio"><?= e(plural((int)$s['sin_precio'], 'nuevo no tiene', 'nuevos no tienen')) ?>
          precio en la web:</strong> el asesor no <?= (int)$s['sin_precio'] === 1 ? 'lo' : 'los' ?> ve hasta que les pongas uno.<?php endif; ?></p>
      <form method="post" id="form-guardar">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="lectura" value="<?= (int)$lectura['id'] ?>">
        <button class="btn btn--negro" type="submit"
                data-confirmar="<?= e(($s['crear'] || $s['actualizar'] || !($s['fotos'] ?? 0))
                    ? 'Se guardan ' . (int)$s['crear'] . ' nuevos y ' . (int)$s['actualizar'] . ' actualizados. ¿Seguimos?'
                    : 'Se traen ' . plural((int)$s['fotos'], 'foto', 'fotos') . '. ¿Seguimos?') ?>">
          GUARDAR EN EL CATÁLOGO</button>
      </form>
    </div>
  <?php elseif ($lectura['estado'] === 'leida' && !$cambio && !$hay_algo): ?>
    <div class="aviso aviso--gris" style="margin-bottom:12px" id="aviso-al-dia">
      <span><?= ico('check',17) ?></span>
      <span>El catálogo ya está al día con la tienda. No hay nada que guardar.</span>
    </div>
  <?php endif; ?>

  <?php if ($nuevos): ?>
    <details class="desplegable" style="margin-bottom:10px" id="lista-nuevos" open>
      <summary>Nuevos · <?= count($nuevos) ?></summary>
      <div class="tabla__caja" style="margin-top:10px">
        <table class="tabla tabla--envuelve">
          <thead><tr><th>Producto</th><th>Colores y modelos</th><th class="der">Precio</th></tr></thead>
          <tbody>
          <?php foreach ($nuevos as $p): ?>
            <tr>
              <td class="principal"><div class="fila__t"><?= e($p['fila']['nombre']) ?></div>
                <div class="fila__s"><?= e($p['fila']['sku']) ?><?= $p['fila']['categoria'] !== '' ? ' · ' . e($p['fila']['categoria']) : '' ?></div></td>
              <td data-k="Colores"><span class="mini">
                <?php $vs = array_map(fn($v) => variante_nombre($v['fila']), $p['variantes']); ?>
                <?= $vs ? e(implode(' · ', array_slice($vs, 0, 4))) . (count($vs) > 4 ? ' +' . (count($vs) - 4) : '') : 'Única' ?>
              </span></td>
              <td class="der num" data-k="Precio">
                <?php $tr = $p['tramos'] ?? []; ?>
                <?php if (!$tr): ?><span class="chip chip--ambar">Sin precio</span>
                <?php else: ?><?= e(soles((int)$tr[0]['precio'])) ?><?= count($tr) > 1 ? '<span class="mini"> · algunos colores con otro</span>' : '' ?><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  <?php endif; ?>

  <?php if ($enlazan): ?>
    <details class="desplegable" style="margin-bottom:10px" id="lista-enlazan" open>
      <summary>Se unen a uno de la tienda · <?= count($enlazan) ?></summary>
      <p class="mini" style="margin:10px 0 0">Ya estaban aquí con el mismo código. Revisa que sean el mismo producto:
        <?= conector_listo() ? 'desde ahora el nombre, el código y el precio se cambian aquí.' : 'desde ahora mandan el nombre y la categoría de la tienda.' ?></p>
      <div class="tabla__caja" style="margin-top:10px">
        <table class="tabla tabla--envuelve">
          <thead><tr><th>En la tienda</th><th>Aquí se llamaba</th></tr></thead>
          <tbody>
          <?php foreach ($enlazan as $p): ?>
            <tr>
              <td class="principal"><div class="fila__t"><?= e($p['fila']['nombre']) ?></div>
                <div class="fila__s"><?= e($p['fila']['sku']) ?></div></td>
              <td data-k="Aquí"><span class="mini"><?= e($p['cambios']['nombre'][0] ?? $p['fila']['nombre']) ?></span>
                <?php if ($p['tramos'] ?? []): ?><div class="mini">Estaba sin precio: toma el de la web,
                  <?= e(soles((int)$p['tramos'][0]['precio'])) ?></div><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  <?php endif; ?>

  <?php if ($cambian): ?>
    <details class="desplegable" style="margin-bottom:10px" id="lista-cambian">
      <summary>Se actualizan · <?= count($cambian) ?></summary>
      <div class="tabla__caja" style="margin-top:10px">
        <table class="tabla tabla--envuelve">
          <thead><tr><th>Producto</th><th>Qué cambia</th></tr></thead>
          <tbody>
          <?php foreach ($cambian as $p): ?>
            <tr>
              <td class="principal"><div class="fila__t"><?= e($p['fila']['nombre']) ?></div>
                <div class="fila__s"><?= e($p['fila']['sku']) ?></div></td>
              <td data-k="Cambia"><span class="mini"><?= e(implode(' · ', $que_cambia($p))) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  <?php endif; ?>

  <?php if ($difieren): ?>
    <details class="desplegable" style="margin-bottom:10px" id="lista-difieren" open>
      <summary>En la web dice otra cosa · <?= count($difieren) ?></summary>
      <p class="mini" style="margin:10px 0 0">Manda lo de aquí: guardar no lo cambia. Decide en cada uno.</p>
      <div class="tabla__caja" style="margin-top:10px">
        <table class="tabla tabla--envuelve">
          <thead><tr><th>Producto</th><th>Qué es distinto</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($difieren as $p): $d = $p['difiere']; ?>
            <tr data-difiere="<?= (int)$p['fila']['woo_id'] ?>">
              <td class="principal"><div class="fila__t"><?= e($p['fila']['nombre']) ?></div>
                <div class="fila__s"><?= e((string) valor('SELECT sku FROM productos WHERE id = ?', [(int)$p['id']], '')) ?></div></td>
              <td data-k="Distinto"><span class="mini">
                <?php if (isset($d['nombre'])): ?><div>Nombre en la web: «<?= e($d['nombre'][1]) ?>»</div><?php endif; ?>
                <?php if (isset($d['codigo'])): ?><div>Código en la web: <?= e($d['codigo'][1]) ?> (aquí <?= e($d['codigo'][0]) ?>)</div><?php endif; ?>
                <?php if (isset($d['precio'])): ?><div>Precio de 1 unidad: aquí <?= e(soles((int)$d['precio'][0])) ?>,
                  en la web <?= e(soles((int)$d['precio'][1])) ?><?= (int)$d['precio'][2] > 1 ? ' (' . (int)$d['precio'][2] . ' colores)' : '' ?></div><?php endif; ?>
              </span></td>
              <td class="der">
                <?php if ($lectura['estado'] === 'leida'): ?>
                  <?php if ($puedo_web): ?>
                    <form method="post" style="display:inline">
                      <?= campo_csrf() ?>
                      <input type="hidden" name="accion" value="corregir_web">
                      <input type="hidden" name="lectura" value="<?= (int)$lectura['id'] ?>">
                      <input type="hidden" name="woo" value="<?= (int)$p['fila']['woo_id'] ?>">
                      <button class="chip chip--linea" type="submit">Corregir la web</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($puedo_web && (isset($d['nombre']) || isset($d['codigo']))): ?>
                    <form method="post" style="display:inline">
                      <?= campo_csrf() ?>
                      <input type="hidden" name="accion" value="usar_web">
                      <input type="hidden" name="lectura" value="<?= (int)$lectura['id'] ?>">
                      <input type="hidden" name="woo" value="<?= (int)$p['fila']['woo_id'] ?>">
                      <button class="chip chip--linea" type="submit">Usar el de la web</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  <?php endif; ?>

  <?php if ($plan['fuera']): ?>
    <details class="desplegable" style="margin-bottom:10px" id="lista-fuera" <?= $nuevos ? '' : 'open' ?>>
      <summary>No entran · <?= count($plan['fuera']) ?></summary>
      <p class="mini" style="margin:10px 0 0">Se corrigen en la tienda y entran la próxima vez que la leas.</p>
      <div class="tabla__caja" style="margin-top:10px">
        <table class="tabla tabla--envuelve">
          <thead><tr><th>Producto</th><th>Código en la tienda</th><th>Por qué</th></tr></thead>
          <tbody>
          <?php foreach ($plan['fuera'] as $f): ?>
            <tr>
              <td class="principal"><div class="fila__t"><?= e($f['nombre']) ?></div></td>
              <td data-k="Código"><span class="mini"><?= $f['codigo'] !== '' ? e($f['codigo']) : '—' ?></span></td>
              <td data-k="Por qué"><span class="mini"><?= e($f['motivo']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  <?php endif; ?>

<?php endif; ?>
