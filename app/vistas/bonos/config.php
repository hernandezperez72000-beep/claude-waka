<?php /* CONFIGURACIÓN › BONOS (módulo 5). La lista y, abierto, el bono con sus
         campos según su forma. Ver nucleo/bonos.php. */
$s = fn(int $c) => $c > 0 ? soles($c, false) : '0.00';
$enlace = fn(string $extra = '') => url('/configuracion/bonos' . (($qp . $extra) !== '' ? '?' . ltrim($qp . $extra, '&') : ''));
?>
<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px" id="bono-errores">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>

<?php if ($paises): ?>
  <div class="filtros" style="margin-bottom:12px">
    <?php foreach ($paises as $pa): ?>
      <a class="<?= (int)$pa['id'] === $pais ? 'on' : '' ?>" href="<?= e(url('/configuracion/bonos?p=' . (int)$pa['id'])) ?>"><?= e((string)$pa['nombre']) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!$bono): ?>
  <?php if ($con_asesores_sin_nivel > 0): ?>
    <div class="aviso aviso--amarillo" style="margin-bottom:14px" id="aviso-sin-nivel">
      <span><?= ico('alerta',17) ?></span>
      <span><strong><?= plural($con_asesores_sin_nivel, 'asesor no tiene', 'asesores no tienen') ?> nivel de cuota.</strong>
        Sin nivel no compiten en La Yapa. Se pone en <a href="<?= e(url('/usuarios')) ?>" style="text-decoration:underline">Usuarios</a>.</span>
    </div>
  <?php endif; ?>
  <div class="tarjeta" id="lista-bonos">
    <div class="tarjeta__cab"><h2>Los bonos</h2><span class="mini">Se miden por lo cobrado. Lo ya cerrado no cambia.</span></div>
    <?php foreach ($bonos as $b): $on = (int)$b['activo'] === 1; ?>
      <div class="fila bono-fila" id="bono-<?= (int)$b['id'] ?>">
        <a class="fila__crece" href="<?= e($enlace('&id=' . (int)$b['id'])) ?>" style="color:inherit;min-width:0">
          <span class="fila__t"><?= e(bono_nombre($b)) ?></span>
          <span class="fila__s"><?= e(bono_resumen($b)) ?></span>
        </a>
        <span class="chip <?= $on ? 'chip--verde' : 'chip--gris' ?>"><?= $on ? 'Encendido' : 'Apagado' ?></span>
        <form method="post" style="display:inline">
          <?= campo_csrf() ?>
          <input type="hidden" name="accion" value="encender">
          <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
          <?php if ($qp): ?><input type="hidden" name="p" value="<?= $pais ?>"><?php endif; ?>
          <button class="chip <?= $on ? 'chip--suave' : 'chip--marca' ?>" type="submit" style="cursor:pointer"><?= $on ? 'Apagar' : 'Encender' ?></button>
        </form>
      </div>
    <?php endforeach; ?>
    <p class="mini" style="margin:10px 0 0">Al encender uno, cuenta desde el período que va (la quincena, la semana…). La Cacería del Día, además, se lanza cada día desde Bonos.</p>
  </div>

  <?php $rn = racha_niveles(); ?>
  <div class="tarjeta" style="margin-top:12px" id="ajustes-bonos">
    <div class="tarjeta__cab"><h2>Ajustes</h2></div>
    <form method="post" class="form">
      <?= campo_csrf() ?>
      <input type="hidden" name="accion" value="ajustes">
      <?php if ($qp): ?><input type="hidden" name="p" value="<?= $pais ?>"><?php endif; ?>
      <div class="form__fila">
        <label>Título del podio <input type="text" name="lobos_titulo" maxlength="60" value="<?= e((string) ajuste('los_lobos_titulo', 'Los lobos del mes')) ?>"></label>
        <label>«Dale con todo» desde el <span class="con-sufijo"><input type="number" name="poco_pct" min="1" max="99" value="<?= (int) ajuste('bonos_poco_pct', 70) ?>"> %</span>
          <span class="ayuda">De avance. Por debajo dice «No pierdas el ritmo».</span></label>
      </div>
      <div class="form__fila">
        <label>Espera antes de cerrar un bono <span class="con-sufijo"><input type="number" name="cierre_horas" min="0" max="72" value="<?= bonos_gracia_horas() ?>"> horas</span>
          <span class="ayuda">Para que Facturación confirme los pagos del último día.</span></label>
        <label>Avisar al equipo de una racha desde <span class="con-sufijo">x<input type="number" name="aviso_desde" min="2" max="20" value="<?= max(2, (int) ajuste('racha_aviso_desde', 3)) ?>"></span></label>
      </div>
      <label class="check"><input type="checkbox" name="racha_avisos" value="1" <?= rachas_avisos_encendidos() ? 'checked' : '' ?>> Avisar al equipo de las rachas</label>
      <label class="check"><input type="checkbox" name="montos_bloqueado" value="1" <?= (string) ajuste('logro_montos_bloqueado', '0') === '1' ? 'checked' : '' ?>> No dejar poner montos en soles en la imagen de un logro</label>
      <div class="form__fila" style="margin-top:8px">
        <?php foreach ($rn as $k => $t): ?>
          <label>Racha x<?= $k ?><?= $k === 5 ? ' o más' : '' ?> <input type="text" name="racha_n<?= $k ?>" maxlength="30" value="<?= e($t) ?>"></label>
        <?php endforeach; ?>
      </div>
      <h3 class="bono-sub">Frases (una por línea)</h3>
      <div class="form__fila">
        <label>Para la Cacería del Día <textarea name="frases_caceria" rows="4"><?= e(implode("\n", bono_frases('caceria'))) ?></textarea></label>
        <label>Modo team · el equipo va primero <textarea name="frases_team_primero" rows="4"><?= e(implode("\n", bono_frases('team_primero'))) ?></textarea></label>
      </div>
      <div class="form__fila">
        <label>Modo team · no va primero <textarea name="frases_team_medio" rows="4"><?= e(implode("\n", bono_frases('team_medio'))) ?></textarea></label>
        <label>Modo team · va último <textarea name="frases_team_ultimo" rows="4"><?= e(implode("\n", bono_frases('team_ultimo'))) ?></textarea></label>
      </div>
      <div class="acciones"><button class="btn btn--negro" type="submit">GUARDAR</button></div>
    </form>
  </div>

<?php else: $b = $bono; $r = $b['reglas']; $on = (int)$b['activo'] === 1; ?>
  <div class="acciones" style="margin:0 0 12px">
    <a class="btn btn--linea btn--chico" href="<?= e($enlace()) ?>"><?= ico('flecha',14) ?> Todos los bonos</a>
    <span class="chip <?= $on ? 'chip--verde' : 'chip--gris' ?>"><?= $on ? 'Encendido desde el ' . e(fecha_corta((string)$b['activo_desde'])) : 'Apagado' ?></span>
    <form method="post" style="display:inline">
      <?= campo_csrf() ?>
      <input type="hidden" name="accion" value="encender">
      <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
      <input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
      <input type="hidden" name="volver" value="ficha">
      <?php if ($qp): ?><input type="hidden" name="p" value="<?= $pais ?>"><?php endif; ?>
      <button class="btn btn--chico <?= $on ? 'btn--linea' : 'btn--amarillo' ?>" type="submit"><?= $on ? 'APAGAR' : 'ENCENDER' ?></button>
    </form>
  </div>
  <form method="post" class="tarjeta form" id="form-bono">
    <?= campo_csrf() ?>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
    <?php if ($qp): ?><input type="hidden" name="p" value="<?= $pais ?>"><?php endif; ?>
    <div class="tarjeta__cab"><h2><?= e(bono_nombre($b)) ?></h2><span class="mini"><?= e(bono_resumen($b)) ?></span></div>
    <div class="form__fila">
      <label>Nombre <input type="text" name="nombre" maxlength="80" required value="<?= e((string)$b['nombre']) ?>"></label>
      <label>Nombre anterior <input type="text" name="nombre_anterior" maxlength="80" value="<?= e((string)($b['nombre_anterior'] ?? '')) ?>"></label>
    </div>
    <label class="check"><input type="checkbox" name="mostrar_anterior" value="1" <?= (int)$b['mostrar_anterior'] === 1 ? 'checked' : '' ?>> Mostrar el nombre anterior entre paréntesis</label>
    <div class="form__fila" style="margin-top:8px">
      <label class="check" style="align-self:end"><input type="checkbox" name="sin_preventa" value="1" <?= !empty($r['sin_preventa']) ? 'checked' : '' ?>> Solo venta de stock (sin pre venta)</label>
      <label>Cuenta solo ventas de más de (S/) <input type="text" name="minimo" inputmode="decimal" value="<?= e($s((int)($r['minimo'] ?? 0))) ?>"></label>
    </div>

    <?php if ($b['forma'] === 'niveles'): ?>
      <h3 class="bono-sub">Los niveles de cuota</h3>
      <p class="mini" style="margin:0 0 8px">Cada asesor tiene su nivel (se pone en su ficha). Gana el bono del nivel más alto que alcance, desde el suyo.</p>
      <div class="tabla__caja"><table class="tabla tabla--form" id="niveles">
        <thead><tr><th>Meta #</th><th>Nombre</th><th>Cuota (S/)</th><th>Bono (S/)</th></tr></thead>
        <tbody>
        <?php $niv = (array)($r['niveles'] ?? []); for ($i = 0; $i < max(count($niv) + 1, 7); $i++): $nv = $niv[$i] ?? null; ?>
          <tr><td data-k="Meta #" class="num">#<?= $i + 1 ?></td>
            <td data-k="Nombre"><input type="text" name="n_nombre[]" maxlength="30" value="<?= e((string)($nv['nombre'] ?? '')) ?>"></td>
            <td data-k="Cuota"><input type="text" name="n_cuota[]" inputmode="decimal" value="<?= $nv ? e($s((int)$nv['cuota'])) : '' ?>" style="max-width:130px"></td>
            <td data-k="Bono"><input type="text" name="n_premio[]" inputmode="decimal" value="<?= $nv ? e($s((int)$nv['premio'])) : '' ?>" style="max-width:110px"></td></tr>
        <?php endfor; ?>
        </tbody></table></div>
      <div class="form__fila" style="margin-top:8px">
        <label>Bono rápido: llega a su cuota en los primeros <span class="con-sufijo"><input type="number" name="rapido_dias" min="0" max="15" value="<?= (int)($r['rapido_dias'] ?? 0) ?>"> días</span></label>
        <label>Bono rápido (S/) <input type="text" name="rapido_premio" inputmode="decimal" value="<?= e($s((int)($r['rapido_premio'] ?? 0))) ?>"></label>
        <label>Bono dúo: cumple las dos quincenas (S/) <input type="text" name="duo_premio" inputmode="decimal" value="<?= e($s((int)($r['duo_premio'] ?? 0))) ?>"></label>
      </div>
    <?php elseif ($b['forma'] === 'escalera'): ?>
      <h3 class="bono-sub">La escalera</h3>
      <p class="mini" style="margin:0 0 8px">Una gestión es una venta de stock de ese día con el pago confirmado. Al lanzar la Cacería se puede cambiar solo por ese día.</p>
      <div class="tabla__caja"><table class="tabla tabla--form">
        <thead><tr><th>Desde (gestiones)</th><th>Premio (S/)</th></tr></thead><tbody>
        <?php $esc = (array)($r['escalera'] ?? []); for ($i = 0; $i < count($esc) + 2; $i++): $e = $esc[$i] ?? null; ?>
          <tr><td data-k="Desde"><input type="number" name="e_desde[]" min="1" value="<?= $e ? (int)$e['desde'] : '' ?>" style="max-width:100px"></td>
              <td data-k="Premio"><input type="text" name="e_premio[]" inputmode="decimal" value="<?= $e ? e($s((int)$e['premio'])) : '' ?>" style="max-width:120px"></td></tr>
        <?php endfor; ?>
      </tbody></table></div>
    <?php elseif ($b['forma'] === 'puestos'): ?>
      <h3 class="bono-sub">Para clasificar</h3>
      <div class="form__fila">
        <label>Cobrado mínimo (S/) <input type="text" name="clasifica_monto" inputmode="decimal" value="<?= e($s((int)($r['clasifica_monto'] ?? 0))) ?>"></label>
        <label>Y al menos <span class="con-sufijo"><input type="number" name="clasifica_ops" min="0" value="<?= (int)($r['clasifica_ops'] ?? 0) ?>"> operaciones</span></label>
      </div>
      <h3 class="bono-sub">Premio por puesto (S/)</h3>
      <div class="form__fila">
        <?php $pr = (array)($r['premios'] ?? []); for ($i = 0; $i < max(3, count($pr) + 1); $i++): ?>
          <label><?= $i + 1 ?>.º <input type="text" name="premios[]" inputmode="decimal" value="<?= isset($pr[$i]) ? e($s((int)$pr[$i])) : '' ?>"></label>
        <?php endfor; ?>
      </div>
      <h3 class="bono-sub">Tramos que suma todo el que llegue</h3>
      <div class="tabla__caja"><table class="tabla tabla--form">
        <thead><tr><th>Al llegar a (S/)</th><th>Suma (S/)</th></tr></thead><tbody>
        <?php $tr = (array)($r['tramos'] ?? []); for ($i = 0; $i < count($tr) + 2; $i++): $t = $tr[$i] ?? null; ?>
          <tr><td data-k="Al llegar a"><input type="text" name="t_desde[]" inputmode="decimal" value="<?= $t ? e($s((int)$t['desde'])) : '' ?>" style="max-width:130px"></td>
              <td data-k="Suma"><input type="text" name="t_extra[]" inputmode="decimal" value="<?= $t ? e($s((int)$t['extra'])) : '' ?>" style="max-width:110px"></td></tr>
        <?php endfor; ?>
      </tbody></table></div>
      <?php if (array_key_exists('destino', $r)): ?>
        <div class="form__fila" style="margin-top:8px">
          <label>Destino del viaje <input type="text" name="destino" maxlength="60" value="<?= e((string)($r['destino'] ?? '')) ?>"></label>
          <label>El premio, en palabras <input type="text" name="premio_texto" maxlength="120" value="<?= e((string)($r['premio_texto'] ?? '')) ?>"></label>
        </div>
      <?php endif; ?>
    <?php elseif ($b['forma'] === 'primeros'): ?>
      <div class="form__fila">
        <label>Llegar a <span class="con-sufijo"><input type="number" name="meta" min="1" value="<?= (int)($r['meta'] ?? 0) ?>"> clientes distintos</span></label>
      </div>
      <h3 class="bono-sub">Premio por puesto de llegada (S/)</h3>
      <div class="form__fila">
        <?php $pr = (array)($r['premios'] ?? []); for ($i = 0; $i < max(2, count($pr) + 1); $i++): ?>
          <label><?= $i + 1 .'.º' ?> <input type="text" name="premios[]" inputmode="decimal" value="<?= isset($pr[$i]) ? e($s((int)$pr[$i])) : '' ?>"></label>
        <?php endfor; ?>
      </div>
    <?php elseif ($b['forma'] === 'equipo'): ?>
      <div class="form__fila">
        <label>Cada integrante, mínimo (S/) <input type="text" name="min_integrante" inputmode="decimal" value="<?= e($s((int)($r['min_integrante'] ?? 0))) ?>"></label>
        <label>El equipo junto, más de (S/) <input type="text" name="min_equipo" inputmode="decimal" value="<?= e($s((int)($r['min_equipo'] ?? 0))) ?>"></label>
        <label>Premio por integrante (S/) <input type="text" name="premio_integrante" inputmode="decimal" value="<?= e($s((int)($r['premio_integrante'] ?? 0))) ?>"></label>
      </div>
      <p class="mini" style="margin:0">Gana un solo equipo: el que más cobró de los que cumplen las dos condiciones.</p>
    <?php endif; ?>
    <div class="acciones"><button class="btn btn--negro" type="submit">GUARDAR</button></div>
    <p class="mini" style="margin:8px 0 0">Un período ya cerrado no cambia: guardó las reglas con que se calculó.</p>
  </form>
<?php endif; ?>
