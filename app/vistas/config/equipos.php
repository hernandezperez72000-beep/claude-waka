<?php
$sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
$sin_equipo = array_values(array_filter($asesores, fn($a) => !$a['equipo_id']));
?>
<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>

<?php if (!$equipos): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px">
    <span><?= ico('personas',17) ?></span>
    <span><strong>Empieza por aquí.</strong> Son <strong>tres equipos</strong>: dos de Lima y uno
      de Arequipa. Créalos ahora y, al dar de alta a cada asesor, su equipo ya sale en el
      desplegable: te ahorras entrar a las 19 fichas una segunda vez.</span>
  </div>
<?php endif; ?>

<div class="rejilla rejilla--panel">
  <div style="display:flex;flex-direction:column;gap:12px">

    <div class="tarjeta">
      <div class="tarjeta__cab">
        <h2>Los equipos</h2>
        <a class="chip chip--linea" href="<?= e(url('/configuracion/equipos?nuevo=1')) ?>">
          <?= ico('mas',13) ?> Nuevo equipo</a>
      </div>

      <?php if (!$equipos): ?>
        <p class="mini">Todavía no hay ninguno.</p>
      <?php else: ?>
        <div class="tabla__caja">
          <table class="tabla">
            <thead><tr><th>Equipo</th><th>Oficina</th><th>Líder</th>
              <th class="der">Gente</th><th class="der">Pedidos</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($equipos as $eq): ?>
              <tr class="<?= (int)$eq['activo'] === 0 ? 'apagada' : '' ?>">
                <td class="principal">
                  <div class="fila__t"><?= e($eq['nombre']) ?></div>
                  <?php if ($eq['pais']): ?><div class="fila__s"><?= e($eq['pais']) ?></div><?php endif; ?>
                </td>
                <td data-k="Oficina"><?= e($eq['oficina'] ?: '—') ?></td>
                <td data-k="Líder">
                  <?php if ($eq['lider_nombre']): ?>
                    ★ <?= e(trim((string)$eq['lider_nombre'] . ' ' . (string)$eq['lider_apellidos'])) ?>
                  <?php else: ?>
                    <span class="muted">Sin líder</span>
                  <?php endif; ?>
                </td>
                <td class="der num" data-k="Gente"><?= (int)$eq['gente'] ?></td>
                <td class="der num" data-k="Pedidos"><?= (int)$eq['pedidos'] ?></td>
                <td class="der">
                  <a class="chip chip--linea" href="<?= e(url('/configuracion/equipos?editar=' . (int)$eq['id'])) ?>">Editar</a>
                  <form method="post" style="display:inline">
                    <?= campo_csrf() ?>
                    <input type="hidden" name="accion" value="estado">
                    <input type="hidden" name="id" value="<?= (int)$eq['id'] ?>">
                    <button class="chip chip--linea" type="submit" style="cursor:pointer">
                      <?= (int)$eq['activo'] === 1 ? 'Apagar' : 'Encender' ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="mini" style="margin-top:10px">
          <strong>Apagar no borra.</strong> El equipo deja de salir en las fichas nuevas, pero sus
          ventas siguen en los reportes.
        </p>
      <?php endif; ?>
    </div>

    <!-- Asignación en bloque ─────────────────────────────────────── -->
    <?php if ($asesores): ?>
      <div class="tarjeta">
        <div class="tarjeta__cab"><h2>Quién va en cada equipo</h2></div>
        <?php if ($sin_equipo): ?>
          <div class="aviso aviso--gris" style="margin-bottom:12px">
            <span><?= ico('personas',17) ?></span>
            <span><strong><?= plural(count($sin_equipo),'asesor sin equipo','asesores sin equipo') ?>.</strong>
              Trabajan igual: sus ventas cuentan en meta, podio y reportes. Solo no salen en el
              ranking por equipos.</span>
          </div>
        <?php endif; ?>

        <form method="post">
          <?= campo_csrf() ?>
          <input type="hidden" name="accion" value="asignar">
          <div class="tabla__caja">
            <table class="tabla">
              <thead><tr><th>Asesor</th><th>Oficina</th><th>Equipo</th></tr></thead>
              <tbody>
              <?php foreach ($asesores as $a): ?>
                <tr>
                  <td class="principal">
                    <div style="display:flex;align-items:center;gap:10px">
                      <?= avatar($a, 32) ?>
                      <div>
                        <div class="fila__t"><?= e(trim($a['nombre'] . ' ' . (string)$a['apellidos'])) ?></div>
                        <?php if (($a['ambito'] ?? '') === 'equipo'): ?>
                          <div class="fila__s">★ Líder · ve a su equipo, solo lectura</div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td data-k="Oficina"><span class="mini"><?= e($a['oficina'] ?: '—') ?></span></td>
                  <td data-k="Equipo">
                    <select name="equipo_de[<?= (int)$a['id'] ?>]">
                      <option value="0">Sin equipo</option>
                      <?php foreach ($equipos as $eq): ?>
                        <?php if ((int)$eq['activo'] === 0 && (int)$a['equipo_id'] !== (int)$eq['id']) continue; ?>
                        <option value="<?= (int)$eq['id'] ?>" <?= $sel($a['equipo_id'] ?? '', $eq['id']) ?>>
                          <?= e($eq['nombre']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="acciones" style="margin-top:12px">
            <button class="btn btn--negro" type="submit">GUARDAR LOS EQUIPOS</button>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <!-- Formulario de un equipo ────────────────────────────────────── -->
  <div style="display:flex;flex-direction:column;gap:12px">
    <?php if ($fila || $nuevo): ?>
      <div class="tarjeta">
        <div class="tarjeta__cab"><h2><?= $fila ? 'Editar equipo' : 'Nuevo equipo' ?></h2></div>
        <form method="post" class="form">
          <?= campo_csrf() ?>
          <input type="hidden" name="accion" value="guardar">
          <?php if ($fila): ?><input type="hidden" name="id" value="<?= (int)$fila['id'] ?>"><?php endif; ?>

          <label>Nombre
            <input type="text" name="nombre" required maxlength="80"
                   value="<?= e((string)($fila['nombre'] ?? '')) ?>"
                   placeholder="Los nombres los eligen ustedes">
          </label>

          <label>Oficina
            <select name="oficina_id">
              <option value="">Sin oficina</option>
              <?php foreach ($oficinas as $o): ?>
                <option value="<?= (int)$o['id'] ?>" <?= $sel($fila['oficina_id'] ?? '', $o['id']) ?>>
                  <?= e($o['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="ayuda">Hoy: dos equipos de Lima y uno de Arequipa. La oficina agrupa;
              no aísla información.</span>
          </label>

          <?php if ($fila): ?>
            <label>Líder ★
              <select name="lider_usuario_id">
                <option value="">Sin líder</option>
                <?php foreach ($candidatos as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= $sel($fila['lider_usuario_id'] ?? '', $c['id']) ?>>
                    <?= e(trim($c['nombre'] . ' ' . (string)$c['apellidos'])) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="ayuda">
                <?php if (!$candidatos): ?>
                  Primero pon gente en el equipo, abajo. El líder sale de sus miembros.
                <?php else: ?>
                  Solo uno por equipo, y tiene que ser de este equipo.
                <?php endif; ?>
              </span>
            </label>
          <?php endif; ?>

          <div class="acciones" style="margin-top:10px">
            <button class="btn btn--negro" type="submit"><?= $fila ? 'GUARDAR' : 'CREAR EL EQUIPO' ?></button>
            <a class="btn btn--linea" href="<?= e(url('/configuracion/equipos')) ?>">Cancelar</a>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <div class="aviso aviso--gris">
      <span><?= ico('ojo',17) ?></span>
      <span><strong>El líder es un asesor que ve a su equipo.</strong> Ve sus pedidos con
        Míos / Mi equipo, en <strong>solo lectura</strong>: no los edita, no los anula y no registra sus pagos.<br>
        Marcar la estrella aquí le pone ese ámbito; quitarla se lo devuelve a «lo suyo».</span>
    </div>
  </div>
</div>
