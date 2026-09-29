<?php
$v = fn(string $k, $d = '') => e((string)($fila[$k] ?? $d));
$sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
?>
<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>

<form method="post" class="form" enctype="multipart/form-data">
  <?= campo_csrf() ?>

  <div class="rejilla rejilla--panel">
    <div style="display:flex;flex-direction:column;gap:12px">

      <fieldset class="bloque">
        <legend>Quién es</legend>
        <div class="form__fila">
          <label>Nombre <input type="text" name="nombre" value="<?= $v('nombre') ?>" required></label>
          <label>Apellidos <input type="text" name="apellidos" value="<?= $v('apellidos') ?>"></label>
        </div>
        <div class="form__fila">
          <label>Correo
            <input type="email" name="email" value="<?= $v('email') ?>" required autocomplete="off">
            <span class="ayuda">Con este correo inicia sesión.</span>
          </label>
          <label>Celular <input type="tel" name="celular" value="<?= $v('celular') ?>" inputmode="tel"></label>
        </div>
        <div class="form__fila">
          <label>Documento <input type="text" name="documento" value="<?= $v('documento') ?>"></label>
          <?php if (columna_existe('usuarios', 'fecha_nacimiento')): ?>
            <label>Fecha de nacimiento
              <input type="date" name="fecha_nacimiento" value="<?= $v('fecha_nacimiento') ?>"
                     max="<?= date('Y-m-d') ?>">
              <span class="ayuda">El día que toque, el Inicio de quien lleva al equipo lo recuerda.</span>
            </label>
          <?php endif; ?>
        </div>
      </fieldset>

      <fieldset class="bloque">
        <legend>Dónde trabaja</legend>
        <div class="form__fila">
          <label>País
            <select name="pais_id" id="pais" required>
              <?php foreach ($paises as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $sel($fila['pais_id'] ?? ($paises[0]['id'] ?? ''), $p['id']) ?>>
                  <?= e($p['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Oficina
            <select name="oficina_id" id="oficina">
              <option value="">Sin oficina</option>
              <?php foreach ($oficinas as $o): ?>
                <option value="<?= (int)$o['id'] ?>" data-pais="<?= (int)$o['pais_id'] ?>"
                        <?= $sel($fila['oficina_id'] ?? '', $o['id']) ?>><?= e($o['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="ayuda">Solo salen las oficinas del país elegido.</span>
          </label>
        </div>
        <div class="form__fila">
          <label>Equipo
            <select name="equipo_id" id="equipo">
              <option value="">Sin equipo</option>
              <?php foreach ($equipos as $q): ?>
                <option value="<?= (int)$q['id'] ?>" data-pais="<?= (int)$q['pais_id'] ?>"
                        <?= $sel($fila['equipo_id'] ?? '', $q['id']) ?>><?= e($q['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="ayuda">Un asesor sin equipo trabaja igual: solo no sale en el ranking por equipos.</span>
          </label>
          <label>Nivel de cuota
            <select name="nivel_cuota">
              <option value="">Sin nivel</option>
              <?php /* De La Yapa del país (5a): nombre y cuota, editables en Configuración › Bonos. */
                    foreach (($niveles ?? []) as $n => $nv): ?>
                <option value="<?= (int)$n ?>" <?= $sel($fila['nivel_cuota'] ?? '', $n) ?>><?= e($nv['nombre'] . ' · Meta #' . $n . ' · ' . soles_corto((int)$nv['cuota'])) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!empty($nivel_prox)): ?>
              <span class="ayuda">Cambia a <?= e(($niveles[(int)$nivel_prox['nivel']]['nombre'] ?? 'sin nivel')) ?> desde el <?= e(fecha_corta((string)$nivel_prox['desde'])) ?>.</span>
            <?php else: ?>
              <span class="ayuda">Un cambio vale desde la quincena siguiente.</span>
            <?php endif; ?>
          </label>
        </div>
      </fieldset>

      <fieldset class="bloque">
        <legend>Qué puede hacer y cuánto ve</legend>
        <div class="form__fila">
          <label>Rol
            <select name="rol_id" id="rol_id" required>
              <?php foreach ($roles as $r): $fijo = ambito_forzado_de_rol((int)$r['id']); ?>
                <option value="<?= (int)$r['id'] ?>" <?= $sel($fila['rol_id'] ?? '', $r['id']) ?>
                        <?= $fijo ? 'data-ambito-fijo="' . e($fijo) . '"' : '' ?>><?= e($r['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="ayuda">El rol dice qué puede <em>hacer</em>.</span>
          </label>
          <label>Ámbito
            <select name="ambito" id="ambito">
              <option value="propio" <?= $sel($fila['ambito'] ?? 'propio','propio') ?>>Lo suyo</option>
              <option value="equipo" <?= $sel($fila['ambito'] ?? '','equipo') ?>>Su equipo · solo lectura</option>
              <option value="todo"   <?= $sel($fila['ambito'] ?? '','todo') ?>>Todo</option>
            </select>
            <span class="ayuda" id="ambito_ayuda">El ámbito dice cuánto puede <em>ver</em>.</span>
          </label>
        </div>
        <?php if (!empty($soy_yo)): ?>
          <div class="aviso aviso--gris">
            <span><?= ico('candado',17) ?></span>
            <span><strong>Tu propio rol, ámbito y país no se cambian desde aquí.</strong>
              Pídeselo a otra persona con acceso a Usuarios.</span>
          </div>
        <?php endif; ?>
        <div class="aviso aviso--gris">
          <span><?= ico('ojo',17) ?></span>
          <span><strong>El líder es un asesor con ámbito «su equipo».</strong> Ve los pedidos de
            su equipo con Míos / Mi equipo, en solo lectura.</span>
        </div>
      </fieldset>

      <fieldset class="bloque">
        <legend>Su meta</legend>
        <label>Meta mensual
          <input type="text" name="meta" inputmode="decimal"
                 value="<?= $fila['meta_mensual_centimos'] ?? null ? e(soles((int)$fila['meta_mensual_centimos'], false)) : '' ?>"
                 placeholder="Déjalo vacío para usar la general">
          <span class="ayuda">Vacío = la meta general del mes.</span>
        </label>
      </fieldset>
    </div>

    <div style="display:flex;flex-direction:column;gap:12px">
      <div class="tarjeta">
        <div class="tarjeta__cab"><h2>Foto de perfil</h2></div>
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:12px">
          <?= avatar($fila ?: null, 96, true) ?>
          <div class="mini" style="line-height:1.6">
            <strong style="color:var(--tx)">JPG o PNG</strong><br>
            Cuadrada<br>
            Mínimo 400 × 400 px<br>
            Máximo 2 MB
          </div>
        </div>
        <label style="font-size:12.5px;font-weight:700;display:flex;flex-direction:column;gap:6px">
          Elegir imagen
          <input type="file" name="foto" accept="image/jpeg,image/png"
                 style="border:1.5px dashed var(--linea);background:var(--campo);border-radius:12px;padding:12px;font-size:12px">
        </label>
        <p class="mini" style="margin:9px 0 0">Se recorta al centro. Sin foto, se muestran sus iniciales.</p>
      </div>

      <?php if (!$id): ?>
        <div class="aviso aviso--amarillo">
          <span><?= ico('candado',17) ?></span>
          <span><strong>La contraseña se genera sola.</strong> Al guardar la verás una sola vez,
            para pasársela por WhatsApp. La persona la cambia al entrar.</span>
        </div>
      <?php endif; ?>

      <div class="acciones">
        <button class="btn btn--negro" type="submit"><?= $id ? 'GUARDAR CAMBIOS' : 'CREAR LA CUENTA' ?></button>
        <a class="btn btn--linea" href="<?= e(url('/usuarios')) ?>">Cancelar</a>
      </div>
    </div>
  </div>
</form>

<script>
/* La oficina y el equipo se filtran por el país elegido. */
(function () {
  var pais = document.getElementById('pais');
  if (!pais) return;
  function filtrar(id) {
    var sel = document.getElementById(id); if (!sel) return;
    Array.prototype.forEach.call(sel.options, function (o) {
      if (!o.dataset.pais) return;
      var cabe = o.dataset.pais === pais.value;
      o.hidden = !cabe; o.disabled = !cabe;
      if (!cabe && o.selected) sel.value = '';
    });
  }
  function todo() { filtrar('oficina'); filtrar('equipo'); }
  pais.addEventListener('change', todo);
  todo();
})();

/* Hay roles donde el ámbito no se elige: Facturación confirma el dinero de
   todos, así que tiene que VER a todos; Almacén y Marketing no venden, así que
   no tienen nada «suyo». El servidor lo fija igual al guardar
   —esto es solo para que no se elija una cosa y se guarde otra. */
(function () {
  var rol = document.getElementById('rol_id');
  var amb = document.getElementById('ambito');
  var ayuda = document.getElementById('ambito_ayuda');
  if (!rol || !amb || !ayuda) return;
  var normal = ayuda.innerHTML;

  /* Lo que se restaura al soltar el candado NO puede ser el valor que había en
     pantalla: si la cuenta YA era de un rol que fuerza el ámbito, ese valor es
     «todo», y cambiarla a Asesor la dejaba de asesor viendo todos los clientes
     y todos los pedidos del pa\u00eds — un ensanchamiento que nadie eligi\u00f3, se lo
     puso el rol anterior. Se guarda «propio», que es donde nace un alta nueva,
     y solo se conserva lo que hab\u00eda si el rol de partida no forzaba nada. */
  var arrancaFijo = (function () {
    var o = rol.options[rol.selectedIndex];
    return !!(o && o.getAttribute('data-ambito-fijo'));
  })();
  var previo = arrancaFijo ? 'propio' : null;

  function mirar() {
    var o = rol.options[rol.selectedIndex];
    var fijo = o ? o.getAttribute('data-ambito-fijo') : null;
    if (fijo) {
      if (previo === null) previo = amb.value;
      amb.value = fijo;
      amb.disabled = true;
      ayuda.innerHTML = 'En este rol no se elige: su trabajo pide verlo todo.';
    } else {
      if (amb.disabled) { amb.disabled = false; amb.value = previo || 'propio'; }
      ayuda.innerHTML = normal;
    }
  }
  rol.addEventListener('change', mirar);
  mirar();
})();
</script>
