<?php
/**
 * Nuevo pedido — el orden nuevo, pedido por el usuario el 2026-09-10.
 *
 *   1 tipo de venta · 2 a dónde va · 3 cliente · 4 qué lleva ·
 *   5 datos de envío · 6 el pago
 *
 * «A dónde va» sube al puesto 2 porque decide la mitad del formulario. Antes
 * se preguntaba al final, cuando el asesor ya había llenado campos que no
 * aplicaban a su caso, y el paso de envío pedía siempre lo mismo daba igual si
 * el pedido iba a San Isidro o a una agencia de Cusco.
 *
 * El lateral se queda con dos cosas: el resumen de la venta y la nota.
 */
$v   = fn(string $k, $x = '') => e((string)($d[$k] ?? $x));
$sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
$tipo = (string)($d['tipo'] ?? 'inmediata');

/* Si el formulario vuelve con errores hay que repintar la rama correcta desde
   el servidor: si no, el asesor pierde la agencia que ya había puesto porque
   el bloque arranca escondido. El distrito manda sobre lo que se marcó arriba. */
$ubi_ini  = $d['ubigeo_id'] ?? null;
$destino  = (string)($d['destino'] ?? '');
if (!in_array($destino, ['lima', 'provincia', 'oficina'], true)) {
    $destino = $ubi_ini ? (ubigeo_es_lima((int)$ubi_ini) ? 'lima' : 'provincia') : 'lima';
}
$modo_envio = !empty($d['recojo_agencia']) ? 'agencia' : (string)($_POST['envio_modo'] ?? 'agencia');
if (!in_array($modo_envio, ['agencia', 'domicilio'], true)) $modo_envio = 'agencia';

/* Y lo mismo con el modo de pago. Si esto se pintara siempre en «todavía no
   paga», al volver el formulario con un error el JS vaciaba el monto que el
   asesor ya había escrito y escondía el bloque: corregía el error, enviaba, y
   el pedido se registraba SIN el pago, sin un solo aviso. */
/* Sin pago no hay pedido, así que no hay tercera opción. Y el que viene
   marcado depende del tipo de venta: una entrega inmediata se paga entera —el
   producto está aquí y se lo lleva— y una pre venta se separa con el 10%.
   Así el asesor no elige nada en el caso normal. */
$modo_ini = (string)($_POST['modo_pago'] ?? '');
if (!in_array($modo_ini, ['completo', 'adelanto'], true)) {
    $modo_ini = $tipo === 'preventa' ? 'adelanto' : 'completo';
}
?>
<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>

<?php /* NOVALIDATE a propósito. En el celular los pasos se pliegan con
         display:none, y un campo obligatorio dentro de un bloque oculto hace
         que Chrome ABORTE el envío en silencio: escribe «an invalid form
         control is not focusable» en una consola que el asesor no ve y no
         pinta ninguna burbuja. El botón dejaba de funcionar sin decir por qué.
         Todo lo valida el servidor, que además es donde hay que validarlo. */ ?>
<form method="post" class="form" enctype="multipart/form-data" id="form-pedido" novalidate>
  <?php /* El identificador de ESTE formulario. Viaja en cada reenvío para que
           el voucher a medio adjuntar siga siendo suyo aunque cambie el
           cliente. Ver $ctx_voucher en el controlador. */ ?>
  <input type="hidden" name="f_id" value="<?= e($f_id) ?>">
  <?= campo_csrf() ?>
  <input type="hidden" name="destino" id="destino" value="<?= e($destino) ?>">

  <div class="rejilla rejilla--panel">
    <?php /* Sin <legend>: el título de un fieldset vive en el borde y en el
             celular se queda flotando arriba, dejando debajo una caja vacía
             que parece un campo por llenar. La cabecera es un div propio, con
             el número en un círculo — así los seis pasos dejan de leerse
             iguales. Lo pidió el usuario el 2026-09-11.

             Con errores NO se pliega nada: el mensaje dice «falta la sucursal»
             y la sucursal estaría dentro de un paso cerrado. Y el paso 1 nace
             abierto desde PHP para que, si el JavaScript se cae por lo que
             sea, el asesor vea un formulario y no seis cabeceras mudas. */ ?>
    <div class="pasos<?= $errores ? ' pasos--todos' : '' ?>" id="pasos">

      <!-- 1 ─ Tipo de venta ─────────────────────────────────────── -->
      <fieldset class="bloque paso paso--abierto" data-paso="1">
        <legend class="solo-lectores">1 · Selecciona el tipo de venta</legend>
        <div class="paso__cab">
          <span class="paso__n" aria-hidden="true">1</span>
          <span class="paso__t">Selecciona el tipo de venta</span>
          <span class="paso__v"></span>
          <span class="paso__f"></span>
        </div>
        <?php /* Los tipos salen de tipos_de_venta(), que es de donde salen
                 también el filtro de la lista y todas las etiquetas. Escritos
                 a mano aquí, añadir «Liquidación» era tocar seis pantallas y
                 olvidarse de cuatro. */ ?>
        <div class="opciones">
          <?php /* OFRECIDOS, no todos: entre subir el ZIP y pasar
                   actualizar.php la columna todavía no admite «liquidacion», y
                   guardarla ahí no da error — MySQL escribe la cadena vacía y
                   el pedido sale mal contado y sin aparecer en ningún filtro. */ ?>
          <?php foreach (tipos_de_venta_ofrecidos() as $tv_k => $tv): ?>
            <label class="opcion">
              <input type="radio" name="tipo" value="<?= e($tv_k) ?>" <?= $tipo === $tv_k ? 'checked' : '' ?>>
              <span><strong><?= e($tv['nombre']) ?></strong>
                <em><?= e($tv['pista']) ?></em></span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <!-- 2 ─ A dónde va ────────────────────────────────────────── -->
      <fieldset class="bloque paso" data-paso="2">
        <legend class="solo-lectores">2 · A dónde va</legend>
        <div class="paso__cab">
          <span class="paso__n" aria-hidden="true">2</span>
          <span class="paso__t">A dónde va</span>
          <span class="paso__v"></span>
          <span class="paso__f"></span>
        </div>
        <div class="opciones">
          <label class="opcion">
            <input type="radio" name="destino_r" value="lima" <?= $destino === 'lima' ? 'checked' : '' ?>>
            <span><strong>Lima</strong><em>Se lo llevamos a su dirección.</em></span>
          </label>
          <label class="opcion">
            <input type="radio" name="destino_r" value="provincia" <?= $destino === 'provincia' ? 'checked' : '' ?>>
            <span><strong>Provincia</strong><em>Envío por agencia.</em></span>
          </label>
          <label class="opcion">
            <input type="radio" name="destino_r" value="oficina" <?= $destino === 'oficina' ? 'checked' : '' ?>>
            <span><strong>Recoge en oficina</strong><em>Cliente compra en oficina.</em></span>
          </label>
        </div>
        <span class="ayuda">Selecciona según corresponda.</span>
      </fieldset>

      <!-- 3 ─ Cliente ───────────────────────────────────────────── -->
      <fieldset class="bloque paso" data-paso="3">
        <legend class="solo-lectores">3 · Selecciona o registra al cliente</legend>
        <div class="paso__cab">
          <span class="paso__n" aria-hidden="true">3</span>
          <span class="paso__t">Selecciona o registra al cliente</span>
          <span class="paso__v"></span>
          <span class="paso__f"></span>
        </div>
        <?php if ($cliente): ?>
          <?php /* CON `id`, igual que la rama de abajo. Sin él, el JavaScript
                   preguntaba por `#cliente_id`, recibía null, daba por hecho que
                   no había cliente y ESCONDÍA la caja del cashback justo en los
                   dos caminos normales: entrar desde la ficha del cliente, y
                   volver el formulario con un error. Y peor: al volver con
                   error, el campo conservaba lo que el asesor había escrito y
                   se enviaba escondido. */ ?>
          <input type="hidden" name="cliente_id" id="cliente_id" value="<?= (int)$cliente['id'] ?>">
          <div class="fila" style="padding:10px 0">
            <span class="fila__crece">
              <span class="fila__t"><?= e(cliente_nombre($cliente)) ?></span>
              <span class="fila__s"><?= e($cliente['tipo_doc']) ?> <?= e($cliente['documento']) ?>
                <?= $cliente['celular'] ? ' · ' . e($cliente['celular']) : '' ?></span>
            </span>
            <a class="chip chip--linea" href="<?= e(url('/pedidos/nuevo')) ?>">Cambiar</a>
          </div>
        <?php else: ?>
          <input type="hidden" name="cliente_id" id="cliente_id" value="<?= $v('cliente_id') ?>">

          <div id="caja-buscar-cliente">
            <label>Busca al cliente
              <input type="search" id="buscar-cliente" autocomplete="off"
                     placeholder="Documento, nombre o celular">
            </label>
            <div id="res-cliente" class="sugerencias" hidden></div>
            <?php /* Amarillo y con el verbo delante (usuario, 2026-09-22): es
                     la salida cuando el cliente no aparece en la búsqueda, y en
                     gris se leía como un texto de ayuda. */ ?>
            <button type="button" class="btn btn--amarillo" id="abrir-alta">
              <?= ico('mas',15) ?> Registrar nuevo cliente</button>
          </div>

          <div class="fila" id="cliente-elegido" hidden style="padding:10px 0">
            <span class="fila__crece">
              <span class="fila__t" id="elegido-nombre"></span>
              <span class="fila__s" id="elegido-sub"></span>
            </span>
            <button type="button" class="chip chip--linea" id="cambiar-cliente">Cambiar</button>
          </div>

          <?php /* Alta rápida: pide EXACTAMENTE lo mismo que la pantalla de
                   Clientes y lo comprueba el mismo código del servidor. Si
                   pidiera menos, en dos semanas habría dos calidades de ficha
                   según por dónde entró el cliente. */ ?>
          <div id="alta-cliente" hidden style="border-top:1px solid var(--linea);padding-top:14px;margin-top:4px">
            <div class="form__fila">
              <label>Tipo de documento
                <select id="c_tipo_doc">
                  <?php foreach (CLIENTE_TIPOS_DOC as $k => $t): ?>
                    <option value="<?= e($k) ?>"><?= e($t) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Número de documento
                <input type="text" id="c_documento" inputmode="numeric" autocomplete="off">
              </label>
            </div>
            <div class="form__fila">
              <label>Nombres   <input type="text" id="c_nombre"></label>
              <label>Apellidos <input type="text" id="c_apellidos"></label>
            </div>
            <div class="form__fila">
              <label>Correo
                <input type="email" id="c_email" autocomplete="off">
                <span class="ayuda">Obligatorio: sin correo el cliente nunca ve su Cashback Waka.</span>
              </label>
              <label>Celular <input type="tel" id="c_celular" inputmode="tel"></label>
            </div>
            <label>¿Dónde nos conoció?
              <select id="c_canal">
                <option value="">Elige una</option>
                <?php foreach ($canales as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e($c['valor']) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="ayuda">Se copia solo al canal de la venta.</span>
            </label>
            <div id="alta-errores" class="aviso aviso--rojo" hidden style="margin-top:10px"><span></span></div>
            <div class="acciones" style="margin-top:12px">
              <button type="button" class="btn btn--amarillo" id="guardar-alta">GUARDAR Y SEGUIR</button>
              <button type="button" class="btn btn--linea" id="cerrar-alta">Cancelar</button>
            </div>
            <p class="mini" style="margin:10px 0 0">El resto de la ficha —factura, rubro, notas—
              se completa después desde Clientes. Aquí va lo imprescindible.</p>
          </div>
        <?php endif; ?>

        <?php if ($asesores): ?>
          <label style="margin-top:10px">Asesor de la venta
            <select name="asesor_id">
              <?php foreach ($asesores as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= $sel($d['asesor_id'] ?? '', $a['id']) ?>>
                  <?= e(trim($a['nombre'] . ' ' . (string)$a['apellidos'])) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="ayuda">De quién es la venta y la meta. Queda anotado que la registraste tú.</span>
          </label>
        <?php endif; ?>
      </fieldset>

      <!-- 4 ─ Productos ─────────────────────────────────────────── -->
      <fieldset class="bloque paso" data-paso="4">
        <legend class="solo-lectores">4 · Selecciona los productos a vender</legend>
        <div class="paso__cab">
          <span class="paso__n" aria-hidden="true">4</span>
          <span class="paso__t">Selecciona los productos a vender</span>
          <span class="paso__v"></span>
          <span class="paso__f"></span>
        </div>
        <div id="lineas">
          <?php
            $ld = (array)($_POST['l_desc'] ?? ['']);
            foreach ($ld as $i => $texto):
          ?>
            <div class="linea">
              <?php /* EL PRODUCTO SALE DEL CATÁLOGO (módulo 3a-1): se escribe
                       el nombre y se elige de la lista, y entonces el precio lo
                       pone la empresa, no la memoria de quien vende. Mientras
                       el catálogo se llena se puede escribir a mano, y esa
                       línea queda marcada como «fuera de catálogo». */ ?>
              <div class="linea__buscar">
                <input type="text" name="l_desc[]" placeholder="Producto (escribe y elige del catálogo)"
                       autocomplete="off" value="<?= e((string)$texto) ?>" class="linea__desc">
                <input type="hidden" name="l_producto[]" class="linea__producto"
                       value="<?= e((string)($_POST['l_producto'][$i] ?? '')) ?>">
                <input type="hidden" name="l_variante[]" class="linea__variante"
                       value="<?= e((string)($_POST['l_variante'][$i] ?? '')) ?>">
                <?php /* La fila del lote, en pre venta (3h). */ ?>
                <input type="hidden" name="l_lote[]" class="linea__lote"
                       value="<?= e((string)($_POST['l_lote'][$i] ?? '')) ?>" data-pv="<?= !empty($_POST['l_lote'][$i]) ? '1' : '' ?>">
                <div class="sugerencias linea__sug" hidden></div>
              </div>
              <input type="text" name="l_modelo[]" placeholder="Modelo / color" class="linea__modelo"
                     value="<?= e((string)($_POST['l_modelo'][$i] ?? '')) ?>">
              <select class="linea__var" hidden aria-label="Modelo o color"></select>
              <input type="text" name="l_sku[]" placeholder="Código" class="linea__sku"
                     value="<?= e((string)($_POST['l_sku'][$i] ?? '')) ?>">
              <input type="number" name="l_cant[]" min="1" step="1" placeholder="Cant."
                     class="linea__cant" value="<?= e((string)($_POST['l_cant'][$i] ?? '1')) ?>">
              <input type="text" name="l_precio[]" inputmode="decimal" placeholder="Precio unit."
                     class="linea__precio" value="<?= e((string)($_POST['l_precio'][$i] ?? '')) ?>">
              <button type="button" class="chip chip--linea quitar-linea" title="Quitar">×</button>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn--linea" id="mas-linea" style="margin-top:8px">
          <?= ico('mas',15) ?> Añadir producto</button>
        <div class="total-vivo">Subtotal <strong id="subtotal">S/ 0.00</strong></div>

        <?php /* El canal de venta vive aquí, pegado al producto, y escrito como
                 se pregunta de verdad. Antes estaba en un bloque aparte al
                 final, junto a la comisión de pasarela y la fecha: tres cosas
                 que no tienen nada que ver entre sí. */ ?>
        <div class="form__fila" style="margin-top:12px">
          <label>¿Dónde nos conoció?
            <select name="canal_item_id">
              <option value="">Elige una</option>
              <?php foreach ($canales as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $sel($d['canal_item_id'] ?? '', $c['id']) ?>>
                  <?= e($c['valor']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="ayuda">Como se enteró de nosotros.</span>
          </label>
          <?php /* LA FECHA DEL PEDIDO SOLO PARA QUIEN PUEDE FECHAR HACIA ATRÁS.
                   Al asesor se le preguntaba y nunca tenía nada que contestar:
                   el pedido se registra hoy, y punto (usuario, 2026-09-11). La
                   que sí importa —y sí se puede mover— es la del PAGO, en el
                   paso 6. Sin este campo, el valor lo pone el servidor. */ ?>
          <?php if ($puede_fechar): ?>
            <label>Fecha del pedido
                                          <?php /* El mínimo es EL MISMO que aplica el servidor —un año
                       atrás—, no el 1 de enero: con el tope de año, cada 1 de
                       enero «una venta de ayer» quedaba fuera del calendario
                       (auditoría del 2r). Lo que se frena es el dedazo del año,
                       y con un año de margen se frena igual. */ ?>
              <input type="date" name="fecha" value="<?= $v('fecha', date('Y-m-d')) ?>"
                     min="<?= date('Y-m-d', strtotime('-365 days')) ?>" max="<?= date('Y-m-d') ?>">
              <span class="ayuda">Solo Administración la ve. Por si hay que meter una venta de ayer.</span>
            </label>
          <?php endif; ?>

          <?php /* EL COMPROBANTE YA NO SE PREGUNTA AQUÍ (usuario, 2026-09-12).
                   Era un campo más en un formulario largo, y encima uno que el
                   asesor casi nunca sabe todavía cuando está tecleando la
                   venta. Ahora es una SOLICITUD que manda cuando la tiene: en
                   la ventana que sale al registrar el pedido, o después desde
                   el menú ⋮ de la lista de pedidos. Y no pedir nada significa
                   que esa venta no lleva comprobante, que es lo normal aquí.
                   Ver pedido_solicitar_comprobante(). */ ?>
          <?php /* La garantía va en el PEDIDO, no en el producto: es lo que se
                   le prometió a ESE cliente. Viene marcada la primera de la
                   lista —hoy 12 meses—, así que en el caso normal no hay nada
                   que elegir. Sin lista todavía (un HUB al que no le han pasado
                   actualizar.php) el campo no sale y el pedido se guarda igual. */ ?>
          <?php if ($garantias): ?>
            <label>Garantía
              <?php /* `data-tipo` es el `extra` del item: a qué tipo de venta le
                       toca por defecto. Lo lee el JavaScript para que al marcar
                       «Liquidación» salte sola a «No aplica» — y deja de
                       hacerlo en cuanto el asesor elige él mismo. */ ?>
              <select name="garantia_item_id" id="garantia">
                <?php foreach ($garantias as $gi): ?>
                  <option value="<?= (int)$gi['id'] ?>"
                          data-tipo="<?= e((string)($gi['extra'] ?? '')) ?>"
                    <?= $sel($d['garantia_item_id'] ?? $garantia_ini, $gi['id']) ?>>
                    <?= e($gi['valor']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php /* SI EL ASESOR NO LA TOCA, MANDA EL PRODUCTO (módulo 3a-1):
                       los carritos llevan seis meses y la silla doce, y hasta
                       ahora había que acordarse en cada venta. Este campo dice
                       si la eligió él; el servidor solo hereda cuando no. */ ?>
              <input type="hidden" name="garantia_auto" id="garantia_auto"
                     value="<?= e((string)($_POST['garantia_auto'] ?? '1')) ?>">
              <span class="ayuda" id="ayuda-garantia">Sale del producto. Modificar en casos extraordinarios.</span>
            </label>
          <?php endif; ?>
        </div>
      </fieldset>

      <!-- 5 ─ Datos de envío ────────────────────────────────────── -->
      <fieldset class="bloque paso" data-paso="5">
        <legend class="solo-lectores">5 · Datos de envío</legend>
        <div class="paso__cab">
          <span class="paso__n" aria-hidden="true">5</span>
          <span class="paso__t">Datos de envío</span> <span class="chip chip--gris" id="chip-destino"></span>
          <span class="paso__v"></span>
          <span class="paso__f"></span>
        </div>

        <div id="caja-envio">
          <?php /* Cada opción lleva DÓNDE aplica. El desplegable enseñaba las
                   cuatro siempre, así que a una venta a Cusco le ofrecía «Envío
                   gratis Lima». Lo filtra el JS y lo comprueba el servidor. */ ?>
          <label id="caja-tipo-envio">Tipo de envío
            <select name="tipo_envio_item_id" id="tipo_envio">
              <option value="">—</option>
              <?php foreach ($tipos_envio as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $sel($d['tipo_envio_item_id'] ?? '', $t['id']) ?>
                        data-cobra="<?= tipo_envio_cobra_flete((int)$t['id']) ? 1 : 0 ?>"
                        data-ambito="<?= e(tipo_envio_ambito((int)$t['id'])) ?>">
                  <?= e($t['valor']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="ayuda" id="ayuda-tipo-envio">Selecciona el tipo de envío acordado con el cliente.</span>
          </label>

          <?php /* El costo SOLO cuando lo cobra Waka. A provincia el flete se lo
                   paga el cliente a la agencia en destino: no es dinero de la
                   empresa, no entra en el total y no genera cashback — pedirlo
                   era pedirle al asesor un dato que a nadie le sirve. Y con eso
                   cae «¿quién lo paga?»: el único flete que se escribe va
                   siempre dentro del total. */ ?>
          <div id="caja-flete" hidden>
            <label style="max-width:320px">Costo del envío
              <?php /* EN LA PRE VENTA ES OPCIONAL (3j): se pone después desde el
                       pedido o al mandarlo a despacho. */ ?>
              <span class="opcional-marca" id="flete-opcional" hidden>Opcional · puedes llenarlo luego</span>
              <input type="text" name="flete" inputmode="decimal" value="<?= $v('flete') ?>"
                     placeholder="0.00">
              <span class="ayuda">Se suma al total del pedido. No genera cashback.</span>
            </label>
          </div>

          <label>Destino
            <input type="search" id="buscar-ubigeo" autocomplete="off"
                   placeholder="Escribe el distrito"
                   value="<?= e(ubigeo_texto($d['ubigeo_id'] ?? null)) ?>">
            <span class="ayuda">Selecciona el destino de envío. Si hay dos distritos con el
              mismo nombre, escribe también la provincia: «santa rosa lima».</span>
          </label>
          <input type="hidden" name="ubigeo_id" id="ubigeo_id" value="<?= $v('ubigeo_id') ?>">
          <div id="res-ubigeo" class="sugerencias" hidden></div>

          <!-- Provincia: cómo lo recibe ahí -->
          <div id="caja-modo-envio" hidden style="margin-top:10px">
            <div class="opciones">
              <label class="opcion">
                <input type="radio" name="envio_modo" value="agencia" <?= $modo_envio === 'agencia' ? 'checked' : '' ?>>
                <span><strong>Recoge en la agencia</strong>
                  <em>El cliente pasa por la agencia. Sin dirección.</em></span>
              </label>
              <label class="opcion">
                <input type="radio" name="envio_modo" value="domicilio" <?= $modo_envio === 'domicilio' ? 'checked' : '' ?>>
                <span><strong>Envío a domicilio</strong>
                  <em>La agencia se lo lleva. Aquí sí hace falta la dirección.</em></span>
              </label>
            </div>
          </div>

          <div id="caja-agencia" hidden style="margin-top:10px">
            <div class="form__fila">
              <label>Agencia
                <select name="agencia_item_id" id="agencia">
                  <option value="">Elige la agencia</option>
                  <?php foreach ($agencias as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= $sel($d['agencia_item_id'] ?? '', $a['id']) ?>>
                      <?= e($a['valor']) ?></option>
                  <?php endforeach; ?>
                  <?php /* La que no está. No traba la venta: se manda con el
                           pedido y Administración decide si entra a la lista. */ ?>
                  <option value="otra" <?= !empty($d['agencia_otra']) ? 'selected' : '' ?>>
                    Otra · no está en la lista</option>
                </select>
              </label>
              <?php /* LA SUCURSAL SE SUGIERE SOLA (usuario, 2026-09-20). La
                       lista se aprende de lo que los asesores escriben: al
                       elegir agencia y distrito salen las que ya se usaron ahí.
                       Sigue siendo un campo de texto: si la sucursal es nueva,
                       se escribe y a partir de la siguiente venta se ofrece. */ ?>
              <label id="caja-sucursal">Sucursal
                <input type="text" name="sucursal" value="<?= $v('sucursal') ?>"
                       list="lista-sucursales"
                       placeholder="La sucursal donde lo recoge">
                <datalist id="lista-sucursales"></datalist>
                <span class="ayuda" id="ayuda-sucursal">Si no está en la lista, escríbela.</span></label>
            </div>
            <label id="caja-agencia-otra" hidden style="margin-top:10px">¿Cuál?
              <input type="text" name="agencia_otra" value="<?= $v('agencia_otra') ?>"
                     maxlength="60" placeholder="Escribe el nombre de la agencia">
              <span class="ayuda">Se manda con el pedido y sale en la lista cuando Administración
                la apruebe. Si ya está, elígela arriba.</span>
            </label>
          </div>

          <div id="caja-direccion" hidden style="margin-top:10px">
            <label>Dirección
              <input type="text" name="direccion_txt" placeholder="Calle, número, interior"
                     value="<?= $v('direccion_txt', (string)($ultima_dir['direccion'] ?? '')) ?>">
            </label>
            <div class="form__fila">
              <label>Referencia
                <input type="text" name="referencia_txt" placeholder="Frente al parque, portón verde"
                       value="<?= $v('referencia_txt', (string)($ultima_dir['referencia'] ?? '')) ?>"></label>
              <label>Quién recibe
                <input type="text" name="recibe" value="<?= $v('recibe', (string)($ultima_dir['recibe'] ?? '')) ?>"></label>
            </div>
            <?php /* OBLIGATORIO EN LIMA, donde reparte Waka: sin el mapa, quien
                     lleva el producto anda buscando una dirección escrita a
                     mano. Fuera de Lima el bloque entero ni se pinta — lo lleva
                     la agencia y ese enlace no lo abre nadie. */ ?>
            <label id="caja-gps">Ubicación de Google Maps <span id="gps-obliga">*</span>
              <input type="text" name="gps" placeholder="Pega aquí el enlace" maxlength="255"
                     value="<?= $v('gps') ?>">
              <span class="ayuda" id="ayuda-gps">Compártela desde la app de Maps y pégala aquí. Es
                lo que abre quien va a entregar.</span></label>
          </div>

                    <?php /* ENVIAR ARMADO ES DE LIMA Y NO ES DE LIQUIDACIÓN (usuario,
                   2026-09-20). A provincia lo arma la agencia o el cliente, y
                   un producto de liquidación va como está. Nace escondida si el
                   formulario vuelve con una de esas dos respuestas. */ ?>
          <label class="check" id="caja-armado" style="margin-top:10px"
                 <?= ($destino === 'lima' && ($d['tipo'] ?? '') !== 'liquidacion') ? '' : 'hidden' ?>>
            <input type="checkbox" name="envio_armado" value="1" <?= !empty($d['envio_armado']) ? 'checked' : '' ?>>
            <span>Enviar armado</span>
          </label>
        </div>

        <?php /* CUÁNDO LO ENTREGAMOS. Solo en Lima: a provincia el horario lo
                 pone la agencia, y prometerle una hora al cliente en nombre de
                 un tercero es una promesa que Waka no puede cumplir. Lo pidió
                 el usuario el 2026-09-10.
                 Va plegado: la mayoría de las ventas no tienen día ni hora, y
                 tres campos vacíos en mitad del formulario se leen como tres
                 cosas que faltan por llenar. */ ?>
        <details class="desplegable" id="caja-cuando" style="margin-top:14px"
                 <?= (!empty($d['entrega_fecha']) || !empty($d['entrega_hora_desde'])
                      || !empty($d['entrega_recepcion'])) ? 'open' : '' ?>>
                    <summary>📅 ¿Quedaron en un día u hora para entregarlo?
            <span class="mini">Opcional · desde mañana</span></summary>
          <div style="margin-top:10px">
            <div class="form__fila">
                            <label>Día de entrega
                <?php /* DESDE MAÑANA Y DE ESTE AÑO (usuario, 2026-09-20): la
                         ruta de hoy ya está armada cuando se registra la venta,
                         y una entrega del año que viene es un dedazo. El
                         servidor comprueba lo mismo. */ ?>
                                <input type="date" name="entrega_fecha"
                       min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                       max="<?= date('Y-m-d', strtotime('+12 months')) ?>"
                       value="<?= e((string)($d['entrega_fecha'] ?? '')) ?>"></label>
              <label>Desde
                <input type="time" name="entrega_hora_desde"
                       value="<?= e((string)($d['entrega_hora_desde'] ?? '')) ?>"></label>
              <label>Hasta
                <input type="time" name="entrega_hora_hasta"
                       value="<?= e((string)($d['entrega_hora_hasta'] ?? '')) ?>"></label>
            </div>
                        <span class="ayuda">Es una sugerencia para despacho, no una hora cerrada:
              la ruta del día la arma despacho. El mismo día no se puede.</span>
            <label class="check" style="margin-top:8px">
              <input type="checkbox" name="entrega_recepcion" value="1"
                     <?= !empty($d['entrega_recepcion']) ? 'checked' : '' ?>>
              <span>Se puede dejar en recepción
                <em>Cliente confirmó que puede dejarse en recepción.</em></span>
            </label>
          </div>
        </details>

        <div class="aviso aviso--gris" id="aviso-oficina" hidden style="margin-top:12px">
          <span><?= ico('check',17) ?></span>
          <span>Recoge en oficina: no hay nada que llenar aquí.</span>
        </div>
      </fieldset>

      <!-- 6 ─ El pago ───────────────────────────────────────────── -->
      <fieldset class="bloque paso" data-paso="6">
        <legend class="solo-lectores">6 · Registra el pago</legend>
        <div class="paso__cab">
          <span class="paso__n" aria-hidden="true">6</span>
          <span class="paso__t">Registra el pago</span>
          <span class="paso__v"></span>
          <span class="paso__f"></span>
        </div>

        <?php /* Dos opciones, no tres: un pedido no se registra sin dinero
                 (usuario, 2026-09-11). Y viene marcada la que toca según el
                 tipo de venta, para que en el caso normal no haya nada que
                 elegir. */ ?>
        <?php /* A provincia se cobra todo antes de despachar: no hay contra
                 entrega (usuario, 2026-09-20). El aviso lo enciende la pantalla
                 según el destino; el servidor comprueba lo mismo. */ ?>
        <div class="aviso aviso--gris" id="aviso-provincia-total" hidden style="margin-bottom:10px">
          <span><?= ico('caja',17) ?></span>
          <span><strong>A provincia se cobra todo antes de despachar.</strong>
            La agencia no cobra al entregar.</span>
        </div>
        <div class="opciones" id="modo-pago">
          <label class="opcion">
            <input type="radio" name="modo_pago" value="completo" <?= $modo_ini === 'completo' ? 'checked' : '' ?>>
            <span><strong>Pagó todo</strong><em>Realizó el pago al 100%.</em></span>
          </label>
          <label class="opcion">
            <input type="radio" name="modo_pago" value="adelanto" <?= $modo_ini === 'adelanto' ? 'checked' : '' ?>>
            <span><strong id="rotulo-adelanto">Pagó una parte</strong>
              <em id="pista-adelanto">El resto queda por cobrar</em></span>
          </label>
        </div>

        <div id="datos-pago" style="margin-top:12px">
          <div class="form__fila">
            <label>Monto
              <input type="text" name="pago_monto" id="pago_monto" inputmode="decimal"
                     value="<?= e((string)($_POST['pago_monto'] ?? '')) ?>" placeholder="0.00">
              <span class="ayuda" id="ayuda-monto"></span>
            </label>
            <label>Método
              <select name="pago_metodo_item_id" id="pago_metodo" data-metodo-fotos>
                <option value="">Elige el método</option>
                <?php foreach ($metodos as $m): ?>
                  <option value="<?= (int)$m['id'] ?>"
                          data-comprobante="<?= (int)($m['pide_comprobante'] ?? 0) ?>"
                          data-dni="<?= metodo_pide_dni((int)$m['id']) ? 1 : 0 ?>"
                          data-instante="<?= (int)($m['al_instante'] ?? 0) ?>"
                          data-recargo="<?= (int) metodo_recargo_centesimas((int)$m['id']) ?>"
                          data-efectivo="<?= metodo_es_efectivo((int)$m['id']) ? 1 : 0 ?>"
                          <?= $sel($_POST['pago_metodo_item_id'] ?? '', $m['id']) ?>>
                    <?= e($m['valor']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div class="form__fila">
            <label>Fecha del pago
                                          <?php /* Treinta días atrás para el asesor, que es lo que admite
                       el servidor; quien confirma pagos no tiene tope y aquí
                       tampoco, para poder meter un pago viejo de otro año. */ ?>
              <input type="date" name="pago_fecha" value="<?= e((string)($_POST['pago_fecha'] ?? date('Y-m-d'))) ?>"
                     <?= puede('pagos.verificar') ? '' : 'min="' . date('Y-m-d', strtotime('-30 days')) . '"' ?>
                     max="<?= date('Y-m-d') ?>">
              <span class="ayuda">La fecha en que el cliente pagó. El N.º de operación lo pone facturación.</span>
            </label>
                        <?php /* LA COMISIÓN SOLO SALE CON POS. Es un campo que no aplica a
                     un pago en efectivo ni a una transferencia, y ahí solo
                     invitaba a escribir un número que no era de nadie (usuario,
                     2026-09-20). «POS» aquí es «método con recargo configurado»,
                     que es lo mismo pero editable desde Configuración.
                     Nace escondida y la decide el método elegido: al volver con
                     errores, el HTML ya sale con la respuesta buena, y sin
                     JavaScript el servidor calcula el recargo igual. */ ?>
            <label id="caja-comision"<?= metodo_recargo_centesimas(pedir_int('pago_metodo_item_id')) > 0 ? '' : ' hidden' ?>>Comisión de pasarela
              <input type="text" name="comision" id="comision" inputmode="decimal" value="<?= $v('comision') ?>" placeholder="0.00">
              <span class="ayuda" id="ayuda-comision">El % que cobra el POS, puesto solo. Cámbialo si el banco cobró otra cosa.</span>
            </label>
          </div>
          <?php /* LA CUENTA DEL VUELTO. Sale solo con efectivo, y NO SE GUARDA
                   nada: es una ayuda para el asesor, no un dato del pedido.
                   El día que el HUB lleve la caja —ingresos y salidas, pedidos
                   de dinero, su reporte— esto es la primera pieza: «paga con
                   700, se le devuelven 100» pasa a ser un movimiento sin tocar
                   esta pantalla. */ ?>
          <div class="form__fila" id="caja-vuelto" hidden>
            <label>Paga con
              <input type="text" id="paga_con" inputmode="decimal" placeholder="0.00" autocomplete="off">
              <span class="ayuda">Solo para calcular. No se guarda en el pedido.</span>
            </label>
            <label>Vuelto
              <div class="vuelto" id="vuelto">S/ 0.00</div>
            </label>
          </div>

          <?php /* EL VOUCHER Y, SI EL MÉTODO LO PIDE, LA FOTO DEL DNI (3f). Cada
                   uno con su botón de cámara: ver pagos/foto_campo. El mismo
                   contexto que usa el controlador para guardarlos. */ ?>
          <?php parte('pagos/foto_campo', ['campo' => 'voucher', 'titulo' => 'Voucher',
                'ayuda' => 'JPG, PNG o PDF, hasta 5 MB.', 'pend' => voucher_pendiente($ctx_voucher)]); ?>
          <?php $m_dni = metodo_pide_dni((int)($_POST['pago_metodo_item_id'] ?? 0)); ?>
          <?php parte('pagos/foto_campo', ['campo' => 'foto_dni', 'titulo' => 'Foto del DNI de quien paga',
                'ayuda' => 'Este método de pago la pide.', 'oculto' => !$m_dni,
                'pend' => voucher_pendiente(voucher_contexto($ctx_voucher, 'foto_dni'))]); ?>
          <div class="aviso aviso--gris" id="aviso-pendiente" style="margin-top:10px">
            <span><?= ico('reloj',17) ?></span>
            <span><strong>Suma a tu meta cuando facturación lo confirme</strong>, en el mes de la
              <strong>fecha del pago</strong>. Facturación recibe el aviso al instante: no hace falta que le escribas.</span>
          </div>
        </div>
      </fieldset>
    </div>

    <!-- Lateral: el resumen y la nota. Nada más. ──────────────────── -->
    <div class="lateral-pega" style="display:flex;flex-direction:column;gap:12px">
      <div class="tarjeta" id="resumen">
        <div class="tarjeta__cab"><h2>Resumen de la venta</h2></div>
        <div class="dato"><span class="dato__k">Producto</span><span class="dato__t" id="r-subtotal">S/ 0.00</span></div>
        <div class="dato" id="fila-cashback"><span class="dato__k">Cashback usado</span><span class="dato__t" id="r-cashback">S/ 0.00</span></div>
        <div class="dato" id="fila-desc" hidden><span class="dato__k">Descuento</span><span class="dato__t" id="r-desc">S/ 0.00</span></div>
        <div class="dato"><span class="dato__k">Envío</span><span class="dato__t" id="r-flete">—</span></div>
        <div class="dato"><span class="dato__k"><strong>Total</strong></span>
          <span class="dato__t"><strong id="r-total">S/ 0.00</strong></span></div>
        <div class="dato"><span class="dato__k">Queda por cobrar</span><span class="dato__t" id="r-saldo">S/ 0.00</span></div>

        <?php /* Lo que GANA el cliente, que es el argumento con el que se
                 cierra la venta. Se calcula sobre el PAGO que se está
                 registrando, no sobre el total: el cashback nace del pago. Una
                 pre venta de S/5.000 separada con el 10% acredita S/5, no S/50,
                 y decir «ganará S/50» era una cifra que el asesor le repetía al
                 cliente por WhatsApp y que el HUB nunca iba a abonar. */ ?>
        <div class="aviso aviso--verde" id="caja-gana" hidden style="margin-top:12px">
          <span><?= ico('check',17) ?></span>
          <span><strong id="r-gana">S/ 0.00</strong> <span id="r-gana-txt">de Cashback Waka</span>.
            <em style="font-style:normal;color:var(--tx-2)" id="r-gana-nota">Se le acredita cuando
              facturación confirme el pago, no ahora.</em></span>
        </div>

        <?php /* El campo del cashback existe SIEMPRE, aunque el cliente todavía
                 no esté elegido: solo salía cuando se entraba desde la ficha
                 del cliente, así que quien buscaba al cliente en el paso 3 no
                 podía usar su saldo nunca. Lo enciende el JavaScript al
                 elegirlo, con el saldo que devuelve el buscador. */ ?>
        <label id="caja-usar-cb" style="margin-top:12px" <?= $cliente ? '' : 'hidden' ?>>
          Usar su cashback en este pedido
          <input type="text" name="cashback" id="cashback" inputmode="decimal"
                 value="<?= e((string)($_POST['cashback'] ?? '')) ?>" placeholder="0.00">
          <span class="ayuda" id="ayuda-cashback">
            Se usa desde <?= e(soles(cashback_minimo())) ?> y como máximo la tercera parte
            del pedido.</span>
        </label>
        <p class="mini" id="sin-cliente-cb" style="margin:10px 0 0" <?= $cliente ? 'hidden' : '' ?>>
          Elige al cliente para ver su saldo de cashback.</p>

        <?php /* EL DESCUENTO DEL ASESOR (3d): en soles, sobre el total, con
                 motivo. Hasta el tope sale sola; por encima espera a
                 Administración. En liquidación no hay (el precio ya es el
                 descuento): el JavaScript esconde la caja y el servidor lo
                 comprueba igual. */ ?>
        <?php if ($desc_listo): ?>
        <div id="caja-desc" style="margin-top:12px">
          <label>Descuento (<?= e(simbolo_moneda()) ?>)
            <input type="text" name="descuento" id="descuento" inputmode="decimal"
                   value="<?= e((string)($_POST['descuento'] ?? '')) ?>" placeholder="0.00">
          </label>
          <?php /* Visible de entrada: sin JavaScript tiene que poder escribirse. El
                   JavaScript lo esconde mientras no haya descuento. */ ?>
          <label id="caja-desc-motivo" style="margin-top:8px">Motivo del descuento
            <input type="text" name="descuento_motivo" id="descuento_motivo" maxlength="<?= (int) DESCUENTO_MOTIVO_MAX ?>"
                   value="<?= e((string)($_POST['descuento_motivo'] ?? '')) ?>"
                   placeholder="Cliente frecuente, compra 3 unidades…">
          </label>
          <span class="ayuda" id="ayuda-desc">
            <?php if (!$desc_pide): ?>Queda anotado con su motivo.
            <?php elseif ($desc_aprueba): ?>Tú puedes aprobarlo: no espera a nadie.
            <?php else: ?>Hasta el <?= (int)$desc_tope_pct ?> % sale solo. Más, espera que Administración lo apruebe.<?php endif; ?>
          </span>
        </div>
        <?php endif; ?>
      </div>

      <div class="tarjeta">
        <div class="tarjeta__cab"><h2>Nota</h2></div>
        <textarea name="nota" rows="4" style="width:100%"
                  placeholder="Solicitudes del cliente o cosas a tener en cuenta. Despacho lee esto."><?= $v('nota') ?></textarea>
      </div>

      <div class="acciones">
        <button class="btn btn--negro" type="submit">REGISTRAR EL PEDIDO</button>
        <a class="btn btn--linea" href="<?= e(url('/pedidos')) ?>">Cancelar</a>
      </div>
    </div>
  </div>

  <?php /* La barra del móvil: el total y lo que queda por cobrar, siempre a la
           vista encima del botón. En escritorio no existe (la esconde el CSS),
           porque ahí el resumen ya está en el lateral. */ ?>
  <div class="barra-movil" id="barra-movil">
    <div class="barra-movil__c">
      <span class="barra-movil__k">Total</span>
      <strong class="barra-movil__t" id="m-total">S/ 0.00</strong>
      <span class="barra-movil__crece"></span>
      <span class="barra-movil__k">Por cobrar</span>
      <strong class="barra-movil__s" id="m-saldo">S/ 0.00</strong>
    </div>
    <button class="btn btn--negro" type="submit" style="width:100%">REGISTRAR EL PEDIDO</button>
  </div>
</form>

<script>
(function () {
  'use strict';
  var raiz = document.body.dataset.raiz || '/';
  var MIN_CASHBACK = <?= (int) cashback_minimo() ?>;
  var SALDO_CB     = <?= (int) $cashback_cliente ?>;
  /* El porcentaje NO se escribe a mano aquí: es un ajuste que Administración
     puede cambiar, y con un 1 clavado en el JavaScript la pantalla mentiría al
     día siguiente de subirlo al 2%. Lo pone PHP, que es donde vive. */
  var CB_PCT = <?= (int) cashback_porcentaje() ?>;
  /* El tope del cashback, de PHP: era un 3 escrito a mano aquí, al lado de la
     misma regla en cashback_tope_del_pedido(). */
  var CB_PARTES = <?= (int) CASHBACK_TOPE_PARTES ?>;
  /* El descuento del asesor: el tope y quién puede saltárselo, de PHP. La
     regla es descuento_pasa_tope(); esto solo la dice en pantalla. */
  var DESC_TOPE = <?= (int) $desc_tope_pct ?>;
  var DESC_APRUEBA = <?= $desc_aprueba ? 'true' : 'false' ?>;
  /* Con la aprobación apagada (3h.1) nada «pasa del tope»: sale y queda anotado. */
  var DESC_PIDE = <?= $desc_pide ? 'true' : 'false' ?>;
  var DESC_CON_CB = <?= $desc_con_cb ? 'true' : 'false' ?>;
  /* El símbolo sale del país, no escrito a mano: en México no es «S/». */
  var MONEDA = <?= json_encode(simbolo_moneda()) ?>;
  var form = document.getElementById('form-pedido');

  function cent(txt) {
    var s = String(txt || '').replace(/[^\d.]/g, '');
    if (s === '') return 0;
    var p = s.split('.');
    var ent = parseInt(p[0] || '0', 10) || 0;
    var dec = ((p[1] || '') + '00').slice(0, 2);
    return ent * 100 + (parseInt(dec, 10) || 0);
  }
  function soles(c) {
    return 'S/ ' + (c / 100).toLocaleString('es-PE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }
  function conDecimales(c) { return (c / 100).toFixed(2); }
  function $(id) { return document.getElementById(id); }

  /* ── Líneas de producto ─────────────────────────────────────── */
  var cajaLineas = $('lineas');
  $('mas-linea').addEventListener('click', function () {
    var base = cajaLineas.querySelector('.linea');
    var nueva = base.cloneNode(true);
    nueva.querySelectorAll('input').forEach(function (i) {
      i.value = i.classList.contains('linea__cant') ? '1' : '';
      if (i.classList.contains('linea__precio')) { i.readOnly = false; i.title = ''; }
    });
    var sugN = nueva.querySelector('.linea__sug');
    if (sugN) { sugN.innerHTML = ''; sugN.hidden = true; }
    var varN = nueva.querySelector('.linea__var');
    if (varN) { varN.hidden = true; varN.innerHTML = ''; }
    var modN = nueva.querySelector('.linea__modelo');
    if (modN) modN.hidden = false;
    var ltN = nueva.querySelector('.linea__lote');
    if (ltN) ltN.dataset.pv = '';
    delete nueva.dataset.cat;
    cajaLineas.appendChild(nueva);
    nueva.querySelector('.linea__desc').focus();
  });
  /* ── EL BUSCADOR DEL CATÁLOGO, LÍNEA A LÍNEA (módulo 3a-1) ────
     Se escribe el nombre, se elige el producto y el precio sale del tramo que
     le toque a la cantidad. Sin JavaScript se escribe a mano y la venta se
     registra igual: el servidor vuelve a calcular el precio de lo que venga
     del catálogo, así que lo que decide el dinero nunca es esta pantalla. */
  var CATALOGO = {};          // id de producto → lo que devolvió el servidor
  /* Con el control de stock encendido (3e), en una entrega inmediata lo
     agotado no se puede elegir. Lo dice el servidor en cada búsqueda. */
  var CONTROL_STOCK = false;
  function frenaAgotado() {
    var r = document.querySelector('[name=tipo]:checked');
    return CONTROL_STOCK && (!r || r.value === 'inmediata');
  }
  var tiempoCat = null;

  function precioTramo(prod, cant, variante) {
    var t = (variante && variante.tramos && variante.tramos.length) ? variante.tramos : (prod.tramos || []);
    for (var i = 0; i < t.length; i++) {
      if (cant >= t[i].desde && (t[i].hasta === null || cant <= t[i].hasta)) return t[i].precio;
    }
    return t.length ? t[t.length - 1].precio : null;
  }

  function pintarPrecio(linea) {
    var idp = linea.querySelector('.linea__producto').value;
    var prod = CATALOGO[linea.dataset.cat || idp];
    var elP = linea.querySelector('.linea__precio');
    if (!prod) { elP.readOnly = false; elP.title = ''; return; }
    var cant = parseInt(linea.querySelector('.linea__cant').value || '1', 10) || 1;
    /* EL TRAMO VA POR EL PRODUCTO, sumando sus colores: en pre venta (3h) y
       también en la venta de stock (3j): 5 negras + 5 azules = precio de 10. */
    cant = 0;
    cajaLineas.querySelectorAll('.linea').forEach(function (l) {
      if (l.dataset.cat === linea.dataset.cat) cant += parseInt(l.querySelector('.linea__cant').value || '0', 10) || 0;
    });
    cant = Math.max(1, cant);
    var vid = parseInt(linea.querySelector('.linea__variante').value || '0', 10) || 0;
    if (prod.lote) vid = 0;          /* en pre venta todos los colores llevan el precio del producto */
    var va = null;
    (prod.variantes || []).forEach(function (x) { if (x.id === vid) va = x; });
    var c = precioTramo(prod, cant, va);
    if (c !== null) elP.value = conDecimales(c);
    elP.readOnly = true;
    elP.title = 'El precio sale del catálogo';
    algoCambio();
  }

  function elegirProducto(linea, prod) {
    /* Un mismo producto puede venir de dos lotes: la clave lleva el lote. */
    var clave = prod.lote ? prod.id + '-L' + prod.lote : String(prod.id);
    CATALOGO[clave] = prod;
    linea.dataset.cat = clave;
    linea.querySelector('.linea__desc').value = prod.nombre;
    linea.querySelector('.linea__producto').value = prod.id;
    linea.querySelector('.linea__sku').value = prod.sku;
    var elVar = linea.querySelector('.linea__var');
    var elMod = linea.querySelector('.linea__modelo');
    var elVid = linea.querySelector('.linea__variante');
    var elLote = linea.querySelector('.linea__lote');
    elVid.value = '';
    /* PRE VENTA (3h): el color es una fila del lote; sin colores, la fila única. */
    if (elLote) { elLote.value = prod.lote ? String(prod.lote_linea || '') : ''; elLote.dataset.pv = prod.lote ? '1' : ''; }
    if (prod.variantes && prod.variantes.length) {
      elVar.innerHTML = '';
      var vacio = document.createElement('option');
      vacio.value = ''; vacio.textContent = 'Elige modelo o color';
      elVar.appendChild(vacio);
      prod.variantes.forEach(function (v) {
        var o = document.createElement('option');
        /* El stock de cada color va en el rótulo, pero lo que se guarda como
           modelo es solo el nombre (data-nombre). */
        o.value = v.id; o.dataset.nombre = v.nombre;
        o.textContent = v.nombre + (v.stock && v.stock.texto ? ' — ' + v.stock.texto : '');
        if (v.agotado && (frenaAgotado() || prod.lote)) o.disabled = true;
        elVar.appendChild(o);
      });
      elVar.hidden = false; elMod.hidden = true; elMod.value = '';
    } else {
      elVar.hidden = true; elVar.innerHTML = ''; elMod.hidden = false;
    }
    garantiaDelProducto(prod);
    pintarPrecio(linea);
    repintarHermanas(clave);
  }

  function soltarProducto(linea) {
    linea.querySelector('.linea__producto').value = '';
    linea.querySelector('.linea__variante').value = '';
    var elL = linea.querySelector('.linea__lote'); if (elL) { elL.value = ''; elL.dataset.pv = ''; }
    var eraCat = linea.dataset.cat;
    delete linea.dataset.cat;
    repintarHermanas(eraCat);
    var elVar = linea.querySelector('.linea__var');
    elVar.hidden = true;
    linea.querySelector('.linea__modelo').hidden = false;
    var elP = linea.querySelector('.linea__precio');
    elP.readOnly = false; elP.title = '';
  }

  /* Las otras líneas del mismo producto cambian de tramo con esta. */
  function repintarHermanas(cat) {
    if (!cat || !CATALOGO[cat]) return;
    cajaLineas.querySelectorAll('.linea').forEach(function (l) { if (l.dataset.cat === cat) pintarPrecio(l); });
  }

  function buscarCatalogo(linea) {
    var caja = linea.querySelector('.linea__sug');
    var texto = linea.querySelector('.linea__desc').value.trim();
    if (texto.length < 2) { caja.hidden = true; caja.innerHTML = ''; return; }
    fetch(raiz + 'stock/buscar?q=' + encodeURIComponent(texto) + '&tipo=' + encodeURIComponent(tipoVenta()), {headers: {'Accept': 'application/json'}})
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var r = j.r || [];
        CONTROL_STOCK = !!j.control;
        caja.innerHTML = '';
        if (!r.length) { caja.hidden = true; return; }
        var conFoto = r.some(function (x) { return !!x.foto; });
        r.forEach(function (prod) {
          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'sugerencia';
          var t = document.createElement('strong');
          t.textContent = prod.nombre;
          var s = document.createElement('span');
          s.textContent = (prod.maquina ? 'Repuesto de ' + prod.maquina + ' · ' : '') + prod.sku
                        + (prod.lote_nombre ? ' · ' + prod.lote_nombre : '')
                        + (prod.precio !== null ? ' · ' + MONEDA + ' ' + conDecimales(prod.precio) : '')
                        + (prod.stock && prod.stock.texto ? ' · ' + prod.stock.texto : '')
                        + (prod.variantes.length ? ' · ' + prod.variantes.length + ' modelos' : '')
                        + (prod.tramos.length > 1 ? ' · baja por cantidad' : '');
          /* La miniatura, si el producto la tiene: con ella el asesor no
             confunde dos productos de nombre parecido. */
          /* Si alguno trae foto, todos llevan su hueco: la lista queda alineada. */
          if (conFoto) {
            b.classList.add('sugerencia--foto');
            var im = document.createElement(prod.foto ? 'img' : 'span');
            if (prod.foto) { im.src = prod.foto; im.alt = ''; im.width = 40; im.height = 40; im.loading = 'lazy'; }
            im.className = prod.foto ? 'foto-prod' : 'foto-prod foto-prod--vacia';
            var tx = document.createElement('span');
            tx.className = 'sugerencia__txt';
            tx.appendChild(t); tx.appendChild(s);
            b.appendChild(im); b.appendChild(tx);
          } else {
            b.appendChild(t); b.appendChild(s);
          }
          /* Agotado en la web (o en la pre venta): se ve, pero no se elige. */
          if (prod.agotado && (frenaAgotado() || prod.lote || (prod.frena && tipoVenta() !== 'preventa'))) {
            b.disabled = true;
            b.classList.add('sugerencia--agotada');
            b.setAttribute('aria-disabled', 'true');
          }
          b.addEventListener('click', function () {
            if (b.disabled) return;
            elegirProducto(linea, prod);
            caja.hidden = true; caja.innerHTML = '';
          });
          caja.appendChild(b);
        });
        caja.hidden = false;
      })
      .catch(function () { caja.hidden = true; });
  }

  cajaLineas.addEventListener('input', function (ev) {
    var linea = ev.target.closest('.linea');
    if (!linea) return;
    if (ev.target.classList.contains('linea__desc')) {
      soltarProducto(linea);
      clearTimeout(tiempoCat);
      tiempoCat = setTimeout(function () { buscarCatalogo(linea); }, 220);
    }
    if (ev.target.classList.contains('linea__cant')) { pintarPrecio(linea); repintarHermanas(linea.dataset.cat); }
  });
  cajaLineas.addEventListener('change', function (ev) {
    var linea = ev.target.closest('.linea');
    if (!linea || !ev.target.classList.contains('linea__var')) return;
    var elLt = linea.querySelector('.linea__lote');
    if (elLt && elLt.dataset.pv === '1') { elLt.value = ev.target.value; linea.querySelector('.linea__variante').value = ''; }
    else linea.querySelector('.linea__variante').value = ev.target.value;
    var op = ev.target.options[ev.target.selectedIndex];
    linea.querySelector('.linea__modelo').value = ev.target.value ? (op.dataset.nombre || op.textContent) : '';
    /* El color puede tener su propio precio. */
    pintarPrecio(linea);
  });

  /* Cambiar entre pre venta y entrega inmediata: lo elegido del otro lado ya
     no vale (un producto de lote no se vende de la tienda, ni al revés). Se
     suelta y se vuelve a buscar con el nuevo tipo. */
  document.querySelectorAll('[name=tipo]').forEach(function (r) {
    r.addEventListener('change', function () {
      cajaLineas.querySelectorAll('.linea').forEach(function (l) {
        var lt = l.querySelector('.linea__lote');
        var esPv = lt && lt.dataset.pv === '1';
        if (l.querySelector('.linea__producto').value && (esPv !== (tipoVenta() === 'preventa'))) {
          soltarProducto(l);
          buscarCatalogo(l);
        }
      });
    });
  });

  cajaLineas.addEventListener('click', function (ev) {
    var b = ev.target.closest('.quitar-linea');
    if (!b) return;
    if (cajaLineas.querySelectorAll('.linea').length <= 1) return;
    var catQ = b.closest('.linea').dataset.cat;
    b.closest('.linea').remove();
    repintarHermanas(catQ);
    algoCambio();
  });

  function subtotal() {
    var t = 0;
    cajaLineas.querySelectorAll('.linea').forEach(function (l) {
      var c = parseInt(l.querySelector('.linea__cant').value || '0', 10) || 0;
      t += cent(l.querySelector('.linea__precio').value) * c;
    });
    return t;
  }

  /* ── A dónde va: lo que decide la mitad del formulario ────────── */
  var elDestino = $('destino');
  function destino() { return elDestino.value; }
  function ponerDestino(v) {
    if (destino() === v) return;
    elDestino.value = v;
    var r = form.querySelector('[name=destino_r][value="' + v + '"]');
    if (r) r.checked = true;
    pintarDestino();
  }

  var elFlete = document.querySelector('[name=flete]');
  var elCashback = $('cashback');
  var elDesc = $('descuento');
  var elPago = $('pago_monto');
  var elTipoEnvio = $('tipo_envio');

  function modoEnvio() {
    var r = form.querySelector('[name=envio_modo]:checked');
    return r ? r.value : 'agencia';
  }

  /* Cada rama pide SOLO lo suyo. Es lo mismo que comprueba el servidor: si
     esto y aquello se separan, la pantalla esconde un campo que el servidor
     sigue exigiendo y el asesor recibe un error por algo que no ve. */
  /* Cada tipo de envío dice dónde aplica. Enseñarlos todos siempre le ofrecía
     «Envío gratis Lima» a una venta a Cusco. Y si en la rama solo queda uno,
     se elige solo: no es una decisión, es un dato. */
  function pintarTipoEnvio() {
    if (!elTipoEnvio) return;
    var dst = destino();
    var quedan = [];
    Array.prototype.forEach.call(elTipoEnvio.options, function (o) {
      var a = o.getAttribute('data-ambito') || '';
      var vale = o.value === '' || a === '' || a === dst;
      o.hidden = !vale;
      o.disabled = !vale;
      if (vale && o.value !== '') quedan.push(o);
    });
    var ahora = elTipoEnvio.options[elTipoEnvio.selectedIndex];
    if (!ahora || ahora.disabled) elTipoEnvio.value = quedan.length === 1 ? quedan[0].value : '';
    if (quedan.length === 1 && elTipoEnvio.value === '') elTipoEnvio.value = quedan[0].value;

    var caja = $('caja-tipo-envio');
    if (caja) caja.hidden = quedan.length === 0;
    var ay = $('ayuda-tipo-envio');
    if (ay) {
      ay.textContent = quedan.length === 1
        ? 'Es el único que aplica aquí. Viene puesto.'
        : 'Selecciona el tipo de envío acordado con el cliente.';
    }
  }

  function pintarDestino() {
    var dst = destino();
    var lima = dst === 'lima';
    var prov = dst === 'provincia';
    var ofi  = dst === 'oficina';

    $('chip-destino').textContent = ofi ? 'Recoge en oficina' : (lima ? 'Lima' : 'Provincia');
    $('caja-envio').hidden   = ofi;
    $('aviso-oficina').hidden = !ofi;

    $('caja-modo-envio').hidden = !prov;
    $('caja-agencia').hidden    = !prov;
    /* Recoge en la agencia: sin dirección, sin referencia, sin mapa. Es el
       caso más usado a provincia y hasta ahora el formulario pedía igual una
       dirección que nadie iba a usar. */
    $('caja-direccion').hidden  = !(lima || (prov && modoEnvio() === 'domicilio'));
    $('caja-gps').hidden        = !lima;
        var haySucursal = prov && modoEnvio() === 'agencia';
    $('caja-sucursal').hidden   = !haySucursal;
    /* Al pasar a domicilio se vacía: si no, la dirección escrita en Sucursal
       viajaba igual y acababa en el mensaje del grupo. */
    if (!haySucursal) { var su = form.querySelector('[name=sucursal]'); if (su) su.value = ''; }
    if (prov) pintarSucursales();
    aplicarModoPago(false);
    pintarTipoEnvio();
    pintarAgencia();

        /* Enviar armado: solo Lima, y nunca en liquidación. */
    var armado = $('caja-armado');
    if (armado) {
      var valeArmado = lima && !esLiquidacion();
      armado.hidden = !valeArmado;
      if (!valeArmado) { var ch = armado.querySelector('input'); if (ch) ch.checked = false; }
    }

    /* La ventana de entrega solo donde entregamos nosotros. A provincia la
       pone la agencia. */
    var cuando = $('caja-cuando');
    cuando.hidden = !lima;
    if (!lima) {
      cuando.open = false;
      cuando.querySelectorAll('input').forEach(function (i) {
        if (i.type === 'checkbox') i.checked = false; else i.value = '';
      });
    }
    sincronizarFlete();
  }

  form.querySelectorAll('[name=destino_r]').forEach(function (r) {
    r.addEventListener('change', function () { ponerDestino(r.value); });
  });
  form.querySelectorAll('[name=envio_modo]').forEach(function (r) {
    r.addEventListener('change', pintarDestino);
  });

  /* ── LAS SUCURSALES DE ESA AGENCIA EN ESA CIUDAD ────────────────
     Se piden al servidor cuando cambia la agencia o el distrito. Si no hay
     ninguna apuntada todavía, el campo se queda como siempre: un texto libre.
     Sin JavaScript no hay sugerencias y la venta se registra igual. */
  var pedidasSuc = '';
  function pintarSucursales() {
    var lista = $('lista-sucursales');
    var elAg2 = $('agencia');
    if (!lista || !elAg2) return;
    var ag  = elAg2.value;
    var ubi = $('ubigeo_id').value;
    if (!ag || ag === 'otra') { lista.innerHTML = ''; return; }
    var llave = ag + '|' + ubi;
    if (llave === pedidasSuc) return;
    pedidasSuc = llave;
    fetch(raiz + 'sucursales/buscar?ag=' + encodeURIComponent(ag) + '&ubigeo=' + encodeURIComponent(ubi),
          {headers: {'Accept': 'application/json'}})
      .then(function (r) { return r.json(); })
      .then(function (j) {
        lista.innerHTML = '';
        (j.sucursales || []).forEach(function (s) {
          var o = document.createElement('option');
          o.value = s.nombre;
          lista.appendChild(o);
        });
        var ay = $('ayuda-sucursal');
        if (ay) {
          ay.textContent = (j.sucursales || []).length
            ? 'Elige una de la lista o escribe otra.'
            : 'Si no está en la lista, escríbela.';
        }
      })
      .catch(function () { /* sin sugerencias se sigue escribiendo a mano */ });
  }

  /* La agencia que no está en la lista: se escribe y se manda con el pedido. */
  var elAgencia = $('agencia');
  function pintarAgencia() {
    var caja = $('caja-agencia-otra');
    if (!caja || !elAgencia) return;
    caja.hidden = elAgencia.value !== 'otra';
  }
  if (elAgencia) elAgencia.addEventListener('change', function () {
    pintarAgencia();
    pintarSucursales();
  });

  /* ── El resumen ─────────────────────────────────────────────── */
  function totalPedido() {
    var sub = subtotal();
    var tope = Math.floor(sub / CB_PARTES);
    var cb = elCashback ? Math.min(cent(elCashback.value), tope, SALDO_CB) : 0;
    if (SALDO_CB < MIN_CASHBACK) cb = 0;
    /* Liquidación: ni se usa ni se gana. Se corta aquí, en la función que
       calcula el total, y no solo escondiendo la caja: si se quedara algo
       escrito en el campo, el resumen habría enseñado un total con descuento
       que el servidor no iba a aplicar. */
    if (esLiquidacion()) cb = 0;
    /* Si hay campo de flete, ese flete lo cobra Waka y va DENTRO del total:
       es el único que se escribe. */
    var cobra = !$('caja-flete').hidden;
    var flete = (cobra && elFlete) ? cent(elFlete.value) : 0;
    var fleteDentro = flete;
    /* El descuento: en soles, y nunca en liquidación (lo mismo que el
       servidor, descuento_problema()). */
    var desc = (elDesc && !esLiquidacion()) ? cent(elDesc.value) : 0;
    var total = Math.max(0, sub - cb - desc + fleteDentro);
    /* Lo que ganará el cliente. Dos reglas, las dos copiadas de PHP:
       · el envío NO genera cashback (pedido_base_cashback: total menos el
         flete que va dentro);
       · nace del PAGO, no del pedido (cashback_de_pago_en_pedido): del pago se
         mira qué parte es producto y de ahí sale el porcentaje.
       Los dos truncados van en el mismo orden que allí; si se hiciera uno solo
       la pantalla diría un céntimo más que el HUB. */
    var base = esLiquidacion() ? 0 : Math.max(0, total - fleteDentro);
    var pagoAhora = Math.min(cent(elPago.value), total);
    var gana = 0;
    if (base > 0 && total > 0 && pagoAhora > 0) {
      var parte = (base >= total) ? pagoAhora : Math.floor(pagoAhora * base / total);
      gana = Math.floor(parte * CB_PCT / 100);
    }
    /* Y lo que ganaría si lo pagara entero, para cuando todavía no paga nada. */
    var ganaTodo = Math.floor(base * CB_PCT / 100);
    return {sub: sub, tope: tope, cb: cb, desc: desc, flete: flete, fleteDentro: fleteDentro,
            total: total, gana: gana, ganaTodo: ganaTodo, pagoAhora: pagoAhora};
  }

  /* ── El costo del envío ───────────────────────────────────────────
     Lo decide el tipo de envío. En Lima lo entrega Waka y lo cobra Waka, así
     que no hay a quién elegir. A provincia sí hay dos casos, porque el flete
     se le puede pagar a la agencia en destino. */
  /* El campo del costo sale SOLO si el tipo de envío dice que lo cobra Waka.
     A provincia el flete se lo paga el cliente a la agencia en destino: no es
     dinero de la empresa y no pinta nada en el pedido. */
  function sincronizarFlete() {
    var caja = $('caja-flete');
    if (!caja) return;
    var op    = elTipoEnvio ? elTipoEnvio.options[elTipoEnvio.selectedIndex] : null;
    var cobra = !!(op && op.getAttribute('data-cobra') === '1');
    caja.hidden = !(destino() !== 'oficina' && cobra);
    if (caja.hidden && elFlete) elFlete.value = '';
    var opc = $('flete-opcional');
    if (opc) opc.hidden = !esPreventa();
    recalcular();
  }
  if (elTipoEnvio) elTipoEnvio.addEventListener('change', sincronizarFlete);

  function recalcular() {
    var t = totalPedido();
    var pago = cent(elPago.value);
    var saldo = Math.max(0, t.total - pago);

    $('subtotal').textContent   = soles(t.sub);
    $('r-subtotal').textContent = soles(t.sub);
    $('r-cashback').textContent = t.cb > 0 ? '− ' + soles(t.cb) : soles(0);
    pintarDescuento(t);
    $('r-flete').textContent = t.flete > 0 ? soles(t.flete)
      : (destino() === 'provincia' ? 'Lo paga en la agencia' : '—');
    $('r-total').textContent = soles(t.total);
    $('r-saldo').textContent = soles(saldo);
    $('m-total').textContent = soles(t.total);
    $('m-saldo').textContent = soles(saldo);

    /* Con pago, lo que gana por ESE pago. Sin pago, lo que ganaría al pagarlo
       entero, dicho como lo que es: una condición, no una promesa. */
    var conPago = t.pagoAhora > 0;
    $('caja-gana').hidden = (conPago ? t.gana : t.ganaTodo) <= 0;
    $('r-gana').textContent   = soles(conPago ? t.gana : t.ganaTodo);
    $('r-gana-txt').textContent = conPago
      ? 'de Cashback Waka por este pago'
      : 'de Cashback Waka cuando pague el pedido completo';
    $('r-gana-nota').textContent = conPago
      ? 'Se le acredita cuando facturación confirme el pago, no ahora.'
      : 'El cashback nace de cada pago: si adelanta una parte, gana la parte.';

    /* La caja del cashback se esconde entera en liquidación, y el campo se
       vacía: dejarlo escrito y escondido es lo que hace que un formulario
       mande lo que el asesor ya no ve. */
    var liq = esLiquidacion();
    var cajaCb = $('caja-usar-cb'), sinCli = $('sin-cliente-cb'), filaCb = $('fila-cashback');
    if (liq && elCashback && elCashback.value !== '') elCashback.value = '';
    if (filaCb) filaCb.hidden = liq;
    /* Por NOMBRE, no por id: el nombre lo garantiza el formulario —es lo que
       se envía— y el id se puede olvidar en una de las dos ramas de la vista,
       que es exactamente lo que pasó. */
    var campoCli = form.querySelector('[name=cliente_id]');
    var hayCliente = !!(campoCli && campoCli.value);
    if (cajaCb) cajaCb.hidden = liq || !hayCliente;
    if (sinCli) sinCli.hidden = liq || hayCliente;

    var ayuda = $('ayuda-cashback');
    if (ayuda && t.sub > 0 && elCashback) {
      ayuda.textContent = SALDO_CB < MIN_CASHBACK
        ? 'Tiene ' + soles(SALDO_CB) + '. Todavía no llega al mínimo de '
          + soles(MIN_CASHBACK) + ': su saldo sigue acumulándose.'
        : 'Tiene ' + soles(SALDO_CB) + '. En un pedido de ' + soles(t.sub)
          + ' puede usar hasta ' + soles(Math.min(t.tope, SALDO_CB)) + '.';
    }
    resumirPasos();
  }

  /* ── El descuento: el %, el tope y el motivo ───────────────────── */
  function pintarDescuento(t) {
    var caja = $('caja-desc');
    if (!caja) return;
    var liq = esLiquidacion();
    caja.hidden = liq;
    if (liq && elDesc.value !== '') elDesc.value = '';
    var fila = $('fila-desc');
    fila.hidden = t.desc <= 0;
    $('r-desc').textContent = '− ' + soles(t.desc);
    $('caja-desc-motivo').hidden = t.desc <= 0;
    var ayuda = $('ayuda-desc');
    if (t.desc <= 0 || t.sub <= 0) {
      ayuda.textContent = !DESC_PIDE ? 'Queda anotado con su motivo.'
        : DESC_APRUEBA ? 'Tú puedes aprobarlo: no espera a nadie.'
        : 'Hasta el ' + DESC_TOPE + ' % sale solo. Más, espera que Administración lo apruebe.';
      ayuda.style.color = '';
      return;
    }
    var pct = Math.round(t.desc * 1000 / t.sub) / 10;
    var pasa = DESC_PIDE && t.desc * 100 > t.sub * DESC_TOPE;
    var txt = 'Es el ' + pct + ' % del precio.';
    if (t.cb > 0 && !DESC_CON_CB) {
      txt = 'No se puede usar cashback y descuento en la misma venta: quita uno de los dos.';
    } else if (t.desc + t.cb >= t.sub) {
      txt = 'El descuento no puede dejar los productos en cero.';
    } else if (pasa && !DESC_APRUEBA) {
      txt += ' Pasa del tope (' + DESC_TOPE + ' %): la venta queda esperando que Administración lo apruebe. '
           + 'Hasta entonces no sale a despacho.';
    } else if (pasa) {
      txt += ' Pasa del tope (' + DESC_TOPE + ' %), pero tú puedes aprobarlo.';
    }
    ayuda.textContent = txt;
    ayuda.style.color = (pasa && !DESC_APRUEBA) || (t.cb > 0 && !DESC_CON_CB) ? 'var(--rojo)' : '';
  }

  /* ── Cómo paga: completo, adelanto o nada ─────────────────────── */
  function modoPago() {
    var r = document.querySelector('[name=modo_pago]:checked');
    return r ? r.value : 'completo';
  }
  function esPreventa() {
    return tipoVenta() === 'preventa';
  }
  function tipoVenta() {
    var r = document.querySelector('[name=tipo]:checked');
    return r ? r.value : 'inmediata';
  }
  /* En liquidación no hay cashback en ninguna de las dos direcciones: ni se
     gana ni se puede pagar con él. El servidor lo comprueba igual —un campo
     escondido sigue viajando—, pero esconderlo es la mitad del encargo: «no
     mostrar cosas que no usarán los asesores». */
  function esLiquidacion() {
    return tipoVenta() === 'liquidacion';
  }

  function aplicarModoPago(reescribirMonto) {
    var modo = modoPago();
    var t = totalPedido();
    var pre = esPreventa();

    /* A PROVINCIA NO HAY PAGO CONTRA ENTREGA: se cobra todo antes de que la
       caja salga (usuario, 2026-09-20). Se esconde «pagó una parte» y se
       marca «pagó todo», salvo en pre venta, donde el adelanto ES el trato.
       El servidor comprueba lo mismo: esconder no es impedir. */
    var soloCompleto = destino() === 'provincia' && !pre;
    var opParte = form.querySelector('[name=modo_pago][value=adelanto]');
    if (opParte) {
      var cajaParte = opParte.closest('.opcion');
      if (cajaParte) cajaParte.hidden = soloCompleto;
      if (soloCompleto && opParte.checked) {
        var opTodo = form.querySelector('[name=modo_pago][value=completo]');
        if (opTodo) { opTodo.checked = true; modo = 'completo'; }
      }
    }
    var avisoProv = $('aviso-provincia-total');
    if (avisoProv) avisoProv.hidden = !soloCompleto;

    $('rotulo-adelanto').textContent = pre ? 'Separa con el 10%' : 'Pagó una parte';
    $('pista-adelanto').textContent = pre
      ? 'El saldo se cobra cuando llegue el contenedor'
      : 'El resto queda por cobrar';

    var ayuda = $('ayuda-monto');

    if (modo === 'completo') {
      elPago.value = conDecimales(t.total);
      elPago.readOnly = true;
      ayuda.textContent = 'Es el total del pedido. Si cambias los productos, se ajusta solo.';
    } else {
      elPago.readOnly = false;
      if (reescribirMonto || elPago.value === '') {
        elPago.value = pre && t.total > 0 ? conDecimales(Math.round(t.total / 10)) : '';
      }
      ayuda.textContent = pre
        ? 'El 10% ya está puesto. Puedes cambiarlo si adelantó otra cantidad.'
        : 'Como mucho ' + soles(t.total) + ', que es el total del pedido.';
    }
    recalcular();
  }

  /* ── La garantía sigue al tipo de venta ───────────────────────────
     Hasta que el asesor la elija él. En cuanto la toca una vez, manda él:
     cambiar de tipo no puede pisarle lo que acaba de pactar con el cliente. */
  var elGarantia = $('garantia');
  var garantiaTocada = false;
  var elGarantiaAuto = $('garantia_auto');
  if (elGarantia) {
    elGarantia.addEventListener('change', function () {
      garantiaTocada = true;
      if (elGarantiaAuto) elGarantiaAuto.value = '0';
    });
    if (elGarantiaAuto && elGarantiaAuto.value === '0') garantiaTocada = true;
  }

  /* La garantía del producto elegido, mientras el asesor no elija otra. */
  function garantiaDelProducto(prod) {
    if (!elGarantia || garantiaTocada || !prod || !prod.garantia_item_id) return;
    for (var i = 0; i < elGarantia.options.length; i++) {
      if (parseInt(elGarantia.options[i].value, 10) === prod.garantia_item_id) {
        elGarantia.value = elGarantia.options[i].value;
        return;
      }
    }
  }
  function pintarGarantia() {
    if (!elGarantia || garantiaTocada) return;
    var t = tipoVenta(), suya = null;
    for (var i = 0; i < elGarantia.options.length; i++) {
      if (elGarantia.options[i].getAttribute('data-tipo') === t) { suya = elGarantia.options[i]; break; }
    }
    elGarantia.value = (suya || elGarantia.options[0]).value;
  }

  document.querySelectorAll('[name=modo_pago]').forEach(function (r) {
    r.addEventListener('change', function () { aplicarModoPago(true); });
  });
    document.querySelectorAll('[name=tipo]').forEach(function (r) {
    /* pintarDestino() también: «enviar armado» no aplica en liquidación. */
    r.addEventListener('change', function () { pintarGarantia(); aplicarModoPago(true); pintarDestino(); sincronizarFlete(); });
  });

  /* UNA SOLA DEFINICIÓN DE «ALGO CAMBIÓ». Estaba escrita tres veces —el
     `input`, el `change` y el quitar una línea— y las tres hacían cosas
     distintas: quitar un producto con la × no dispara ni input ni change, así
     que el resumen bajaba a S/ 500 y el monto del pago se quedaba en S/ 800,
     en un campo de solo lectura que el asesor no podía corregir. Al enviar, el
     servidor le echaba la culpa de algo que hizo la pantalla. */
  function algoCambio() {
    if (modoPago() === 'completo') elPago.value = conDecimales(totalPedido().total);
    recalcular();
    pintarRecargo();
    pintarVuelto();
  }
  form.addEventListener('input',  algoCambio);
  form.addEventListener('change', algoCambio);

  /* ── El método de pago: el aviso, el recargo y el vuelto ──────────── */
  var elComision = $('comision');
  var elPagaCon  = $('paga_con');
  /* Si el asesor escribe la comisión a mano, manda él: el recargo deja de
     recalcularse en ese pedido. Con un banco que cobró otra cosa, que la
     pantalla se la vuelva a pisar es peor que no calcular nada. */
  var comisionTocada = (elComision && elComision.value.trim() !== '');
  if (elComision) elComision.addEventListener('input', function () { comisionTocada = true; });

  function metodoElegido() {
    var s = $('pago_metodo');
    return s ? s.options[s.selectedIndex] : null;
  }

  /* El recargo del POS: el 4% del MONTO TOTAL PAGADO (usuario, 2026-09-11).
     La tasa NO se escribe aquí: viene del método de pago, que se edita en
     Configuración. Con un 4 clavado en el JavaScript, el día que el banco baje
     al 3.5% la pantalla mentiría. Y la cuenta va en enteros —céntimos por
     centésimas de punto, entre 10.000— igual que en PHP, para que las dos
     digan el mismo céntimo. */
  function pintarRecargo() {
    if (!elComision || comisionTocada) return;
    var o = metodoElegido();
    var pct = o ? (parseInt(o.getAttribute('data-recargo'), 10) || 0) : 0;
    if (pct <= 0) { elComision.value = ''; return; }
    var monto = cent(elPago.value);
    elComision.value = monto > 0 ? conDecimales(Math.floor(monto * pct / 10000)) : '';
  }

    function pintarAyudaComision() {
    var o = metodoElegido();
    var pct = o ? (parseInt(o.getAttribute('data-recargo'), 10) || 0) : 0;
    /* La caja entera sale o no sale: un campo de comisión delante de un pago
       en efectivo es un campo que nadie sabe para qué es. */
    var caja = $('caja-comision');
    if (caja) {
      caja.hidden = pct <= 0;
      if (pct <= 0 && elComision) { elComision.value = ''; comisionTocada = false; }
    }
    var a = $('ayuda-comision');
    if (!a) return;
    if (pct > 0) a.textContent = 'El ' + (pct / 100) + '% de lo que pasa por el POS, puesto solo. Cámbialo si el banco te cobró otra cosa.';
  }

  /* El vuelto. No se guarda nada: es una cuenta para el asesor. */
  function pintarVuelto() {
    var caja = $('caja-vuelto');
    if (!caja) return;
    var o = metodoElegido();
    var esEfectivo = !!(o && o.getAttribute('data-efectivo') === '1');
    caja.hidden = !esEfectivo;
    if (!esEfectivo) { if (elPagaCon) elPagaCon.value = ''; return; }

    var cobrar = cent(elPago.value);
    var paga   = elPagaCon ? cent(elPagaCon.value) : 0;
    var caj    = $('vuelto');
    if (!caj) return;
    if (paga <= 0)       { caj.textContent = 'S/ 0.00';  caj.className = 'vuelto'; }
    else if (paga < cobrar) { caj.textContent = 'Faltan ' + soles(cobrar - paga); caj.className = 'vuelto vuelto--falta'; }
    else                 { caj.textContent = soles(paga - cobrar); caj.className = 'vuelto vuelto--ok'; }
  }
  if (elPagaCon) elPagaCon.addEventListener('input', pintarVuelto);

  function pintarMetodo() {
    /* El aviso nace VISIBLE en el HTML: hoy ningún método cuenta al instante,
       así que la respuesta por defecto es la buena, y sin JavaScript el asesor
       veía el formulario sin una palabra de que su venta no suma todavía. */
    var o = metodoElegido();
    $('aviso-pendiente').hidden = !!o && o.dataset.instante === '1';
    pintarAyudaComision();
    pintarRecargo();
    pintarVuelto();
  }
  $('pago_metodo').addEventListener('change', pintarMetodo);

  /* ── Buscadores ─────────────────────────────────────────────── */
  function buscador(inputId, cajaId, url, alElegir) {
    var input = $(inputId);
    var caja  = $(cajaId);
    if (!input || !caja) return;
    var espera = null;

    input.addEventListener('input', function () {
      clearTimeout(espera);
      var q = input.value.trim();
      if (q.length < 2) { caja.hidden = true; return; }
      espera = setTimeout(function () {
        fetch(raiz + url + '?q=' + encodeURIComponent(q), {headers: {'Accept': 'application/json'}})
          .then(function (r) { return r.json(); })
          .then(function (j) {
            var items = j.clientes || j.sitios || [];
            caja.innerHTML = '';
            /* Cuando lo escrito no casa con nada, el servidor devuelve lo que
               se le PARECE. Se dice, para que nadie elija Puno creyendo que
               escribió Puno (usuario, 2026-09-20). */
            if (j.aprox && items.length) {
              var cab = document.createElement('div');
              cab.className = 'sugerencia sugerencia--vacia';
              cab.textContent = '¿Quisiste decir…?';
              caja.appendChild(cab);
            }
            if (!items.length) {
              var vacio = document.createElement('div');
              vacio.className = 'sugerencia sugerencia--vacia';
              vacio.textContent = 'Nada con ese nombre.';
              caja.appendChild(vacio);
            }
            items.forEach(function (it) {
              var b = document.createElement('button');
              b.type = 'button';
              b.className = 'sugerencia';
              var t = document.createElement('strong'); t.textContent = it.texto;
              var s = document.createElement('span');   s.textContent = it.sub || '';
              b.appendChild(t); b.appendChild(s);
              b.addEventListener('click', function () { alElegir(it); caja.hidden = true; });
              caja.appendChild(b);
            });
            /* Con el país entero hay búsquedas que desbordan el tope. Decirlo
               vale más que enseñar una lista cortada como si fuera completa. */
            if (j.mas) {
              var mas = document.createElement('div');
              mas.className = 'sugerencia sugerencia--vacia';
              mas.textContent = 'Hay más. Escribe también la provincia.';
              caja.appendChild(mas);
            }
            caja.hidden = false;
          })
          .catch(function () { caja.hidden = true; });
      }, 220);
    });
    document.addEventListener('click', function (ev) {
      if (!caja.contains(ev.target) && ev.target !== input) caja.hidden = true;
    });
  }

  /* ── El cliente elegido, venga de donde venga ────────────────── */
  var elegido = $('cliente-elegido');

  function ponerCliente(it) {
    var campo = $('cliente_id');
    /* Y la ficha del elegido. Antes bastaba con preguntar por el campo, porque
       solo la rama SIN cliente le ponía id; desde que las dos lo llevan, esa
       guarda dejó de guardar y lo que protege de verdad es esto. */
    if (!campo || !$('elegido-nombre')) return;
    campo.value = it.id;
    SALDO_CB = it.cashback_centimos || 0;
    var caja = $('caja-usar-cb'), aviso = $('sin-cliente-cb');
    if (caja)  caja.hidden = false;
    if (aviso) aviso.hidden = true;
    $('elegido-nombre').textContent = it.texto;
    $('elegido-sub').textContent = it.sub || '';
    elegido.hidden = false;
    $('caja-buscar-cliente').hidden = true;
    $('alta-cliente').hidden = true;
    /* Elegir cliente SÍ es contestar el paso 3, pero se hace escribiendo
       `value` desde JavaScript y eso no dispara `input` ni `change`: con el
       cliente ya elegido desde su ficha, el paso se quedaba sin ✓ teniendo el
       nombre escrito debajo. */
    anotarPaso(3);
    recalcular();
  }

  if (elegido) {
    $('cambiar-cliente').addEventListener('click', function () {
      $('cliente_id').value = '';
      SALDO_CB = 0;
      if ($('cashback')) $('cashback').value = '';
      if ($('caja-usar-cb'))  $('caja-usar-cb').hidden = true;
      if ($('sin-cliente-cb')) $('sin-cliente-cb').hidden = false;
      elegido.hidden = true;
      $('caja-buscar-cliente').hidden = false;
      $('buscar-cliente').value = '';
      recalcular();
    });
  }

  buscador('buscar-cliente', 'res-cliente', 'clientes/buscar', ponerCliente);

  buscador('buscar-ubigeo', 'res-ubigeo', 'ubigeo/buscar', function (it) {
    $('ubigeo_id').value = it.id;
    $('buscar-ubigeo').value = it.texto + ' · ' + it.sub;
    /* El DISTRITO manda. Si el asesor marcó «Lima» arriba y elige Wanchaq, la
       pantalla se corrige sola en vez de dejarle rellenando campos de Lima
       para que el servidor le rebote el pedido al final. */
    ponerDestino(it.lima ? 'lima' : 'provincia');
    pintarDestino();
    pintarSucursales();
    aplicarModoPago(false);
  });

  /* ── Alta rápida del cliente, sin salir del pedido ─────────────── */
  var caja_alta = $('alta-cliente');
  if (caja_alta) {
    var errores = $('alta-errores');

    $('abrir-alta').addEventListener('click', function () {
      caja_alta.hidden = false;
      $('c_documento').focus();
    });
    $('cerrar-alta').addEventListener('click', function () {
      caja_alta.hidden = true;
      errores.hidden = true;
    });

    function pintarErrores(lista, existe) {
      var caja = errores.querySelector('span');
      caja.textContent = '';
      lista.forEach(function (x) {
        var dv = document.createElement('div');
        dv.textContent = x;
        caja.appendChild(dv);
      });
      // Solo se ofrece «usar esa ficha» si el servidor dice que esa ficha es
      // suya. Ofrecer una que no lo es acaba en un 403 a pantalla completa con
      // todo el pedido ya tecleado perdido.
      if (existe && existe.usable) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'chip chip--linea';
        b.style.marginTop = '8px';
        b.textContent = 'Usar la ficha de ' + existe.texto;
        b.addEventListener('click', function () {
          ponerCliente({id: existe.id, texto: existe.texto, sub: 'ficha que ya existía',
                        cashback_centimos: existe.cashback_centimos || 0});
          errores.hidden = true;
        });
        caja.appendChild(b);
      }
      errores.hidden = false;
    }

    $('guardar-alta').addEventListener('click', function () {
      var boton = this;
      var datos = new URLSearchParams();
      datos.append('_t', form.querySelector('[name=_t]').value);
      datos.append('tipo_doc',      $('c_tipo_doc').value);
      datos.append('documento',     $('c_documento').value);
      datos.append('nombre',        $('c_nombre').value);
      datos.append('apellidos',     $('c_apellidos').value);
      datos.append('email',         $('c_email').value);
      datos.append('celular',       $('c_celular').value);
      datos.append('canal_item_id', $('c_canal').value);

      boton.disabled = true;
      var antes = boton.textContent;
      boton.textContent = 'Guardando…';

      fetch(raiz + 'clientes/rapido', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
        body: datos.toString()
      })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          boton.disabled = false; boton.textContent = antes;
          if (j.ok) {
            errores.hidden = true;
            /* El canal de la VENTA hereda el «dónde nos conoció» del alta, pero
               solo si nadie lo había elegido ya: lo que escribió la persona
               manda siempre sobre lo que el HUB deduce. */
            var canalVenta = form.querySelector('[name=canal_item_id]');
            var canalAlta  = $('c_canal');
            if (canalVenta && canalAlta && canalAlta.value && !canalVenta.value) {
              canalVenta.value = canalAlta.value;
            }
            ponerCliente(j.cliente);
          }
          else pintarErrores(j.errores || ['No se pudo guardar.'], j.existe);
        })
        .catch(function () {
          boton.disabled = false; boton.textContent = antes;
          pintarErrores(['No se pudo guardar. Revisa la conexión y vuelve a intentarlo.'], null);
        });
    });
  }

  /* ── El acordeón del móvil ────────────────────────────────────────
     En un celular caben dos campos a la vez: con los seis pasos abiertos el
     formulario es un scroll sin final y el asesor no sabe por dónde va. Cada
     paso se pliega enseñando lo que ya se llenó y se abre uno a la vez.
     En escritorio NO se pliega nada: ahí caben tres bloques de un vistazo, y
     plegar solo cobraría clics. Lo decidió el usuario el 2026-09-10. */
  var pasos = Array.prototype.slice.call(document.querySelectorAll('.paso'));
  var movil = window.matchMedia('(max-width: 820px)');

  pasos.forEach(function (p) {
    var cab = p.querySelector('.paso__cab');
    if (!cab) return;
    function alternar(ev) {
      if (!movil.matches || $('pasos').classList.contains('pasos--todos')) return;
      /* Solo se para el evento cuando de verdad se pliega: en escritorio la
         barra espaciadora tiene que seguir haciendo scroll. */
      ev.preventDefault();
      var abierto = p.classList.contains('paso--abierto');
      pasos.forEach(function (q) { q.classList.remove('paso--abierto'); });
      if (!abierto) p.classList.add('paso--abierto');
      marcarAbiertos();
    }
    cab.addEventListener('click', alternar);
    /* Con teclado también. Es un div, así que Enter y Espacio hay que
       atenderlos a mano: sin esto, en el celular los pasos cerrados salen del
       orden de tabulación y no había forma de abrirlos sin dedo. */
    cab.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' || ev.key === ' ' || ev.key === 'Spacebar') alternar(ev);
    });
  });

  function texto(sel) {
    var el = form.querySelector(sel);
    if (!el) return '';
    if (el.tagName === 'SELECT') return el.selectedIndex > 0 ? el.options[el.selectedIndex].text : '';
    return el.value || '';
  }

  /* HASTA DÓNDE HA LLEGADO EL ASESOR.
     Los pasos 1 y 2 vienen con respuesta por defecto —«Entrega inmediata» y
     «Lima»—, así que un formulario recién abierto se pintaba con sus dos
     primeros pasos en ✓ y con su valor escrito debajo: parecía que él ya los
     había llenado. «Puede confundir al asesor» (usuario, 2026-09-14), y tiene
     razón: el ✓ tiene que significar «esto ya lo contestaste tú».
     Se marca un paso cuando lo tocó, o cuando tocó uno POSTERIOR —llegar al
     cliente quiere decir que el tipo de venta y el destino quedaron como
     estaban—. Y si el formulario vuelve con errores, todo lo que mandó cuenta
     como tocado: lo llenó de verdad. */
  var MAX_TOCADO = <?= $errores ? 9 : 0 ?>;
  form.addEventListener('input',  anotarTocado, true);
  form.addEventListener('change', anotarTocado, true);
  function anotarTocado(ev) {
    var p = ev.target && ev.target.closest ? ev.target.closest('.paso') : null;
    if (!p) return;
    anotarPaso(parseInt(p.getAttribute('data-paso') || '0', 10) || 0);
  }
  function anotarPaso(k) {
    if (k > MAX_TOCADO) { MAX_TOCADO = k; resumirPasos(); }
  }

  /* VOLVER ATRÁS CON EL BOTÓN DEL NAVEGADOR. El navegador repone los valores
     sin disparar ningún evento, así que el formulario volvía LLENO y sin un
     solo ✓ — y en el celular el acordeón lo mandaba otra vez al paso 1. Lo que
     ya tiene respuesta escrita cuenta como contestado. */
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) return;
    MAX_TOCADO = 9; resumirPasos(); ordenarAcordeon();
  });

  function resumirPasos() {
    var t = totalPedido();
    var cli = $('elegido-nombre');
    var n = cajaLineas.querySelectorAll('.linea').length;
    var marcado = form.querySelector('[name=tipo]:checked');
    var dst = destino();

    var v = {
      1: marcado ? marcado.parentNode.querySelector('strong').textContent : '',
      2: dst === 'oficina' ? 'Recoge en oficina' : (dst === 'lima' ? 'Lima' : 'Provincia'),
      3: (cli && cli.textContent) || (form.querySelector('.fila__t') ? form.querySelector('.fila__t').textContent : ''),
      4: t.sub > 0 ? n + (n === 1 ? ' producto · ' : ' productos · ') + soles(t.sub) : '',
      5: dst === 'oficina' ? 'Sin envío' : texto('#buscar-ubigeo'),
      6: cent(elPago.value) > 0 ? soles(cent(elPago.value)) : ''
    };
    pasos.forEach(function (p) {
      var k = p.getAttribute('data-paso');
      /* Y el resumen también se calla hasta entonces: enseñar «Entrega
         inmediata» debajo del paso 1 en un formulario en blanco es la otra
         mitad de lo que lo hacía parecer lleno. */
      var lleno = !!v[k] && parseInt(k, 10) <= MAX_TOCADO;
      var sp = p.querySelector('.paso__v');
      if (sp) sp.textContent = lleno ? v[k] : '';
      p.classList.toggle('paso--lleno', lleno);
      var num = p.querySelector('.paso__n');
      if (num) num.textContent = lleno ? '✓' : k;
    });
  }

  /* aria-expanded dice la verdad: plegado solo se pliega en el celular. */
  /* En escritorio NO se pliega nada, así que la cabecera no es un botón: con
     role y tabindex fijos en el HTML, el asesor de escritorio ganaba seis
     paradas de tabulación muertas y un lector de pantalla anunciaba «botón,
     expandido» sobre algo que no lo es. Se pone y se quita con el acordeón. */
  function marcarAbiertos() {
    var pliega = movil.matches && !$('pasos').classList.contains('pasos--todos');
    pasos.forEach(function (p) {
      var cab = p.querySelector('.paso__cab');
      if (!cab) return;
      if (pliega) {
        cab.setAttribute('role', 'button');
        cab.setAttribute('tabindex', '0');
        cab.setAttribute('aria-expanded', p.classList.contains('paso--abierto') ? 'true' : 'false');
      } else {
        cab.removeAttribute('role');
        cab.removeAttribute('tabindex');
        cab.removeAttribute('aria-expanded');
      }
    });
  }

  function ordenarAcordeon() {
    if ($('pasos').classList.contains('pasos--todos')) return;
    if (!movil.matches) {
      pasos.forEach(function (p) { p.classList.remove('paso--abierto'); });
      marcarAbiertos();
      return;
    }
    /* Abre por donde HAY QUE EMPEZAR. En un formulario en blanco eso es el
       paso 1, que es donde el asesor espera empezar; cuando vuelve con errores
       —y entonces todo cuenta como tocado— abre el primero que quedó sin
       contestar. */
    pasos.forEach(function (p) { p.classList.remove('paso--abierto'); });
    var primero = pasos.filter(function (p) { return !p.classList.contains('paso--lleno'); })[0] || pasos[0];
    primero.classList.add('paso--abierto');
    marcarAbiertos();
  }
  if (movil.addEventListener) movil.addEventListener('change', ordenarAcordeon);

  /* ── EL RESUMEN ANTES DE CONFIRMAR ────────────────────────────────
     «Al finalizar una venta, que salga un resumen como para validar, toda la
     info resumida y ahí recién confirmar» (usuario, 2026-09-20).

     Va DESPUÉS de la validación del navegador —el evento `submit` solo se
     dispara cuando el formulario ya está completo—, así que el asesor no ve el
     resumen de algo que va a rebotar. Se arma leyendo el formulario, no una
     copia: lo que sale aquí es exactamente lo que se va a guardar.
     Sin JavaScript no hay ventana y el pedido se registra como siempre: el
     resumen es una red, no un paso obligatorio. */
  var confirmado = false;
  function filaResumen(k, v) {
    if (!v) return '';
    var fila = document.createElement('div');
    fila.className = 'resumen__f';
    var a = document.createElement('span'); a.className = 'resumen__k'; a.textContent = k;
    var b = document.createElement('span'); b.className = 'resumen__v'; b.textContent = v;
    fila.appendChild(a); fila.appendChild(b);
    return fila;
  }
  function productosResumen() {
    var out = [];
    cajaLineas.querySelectorAll('.linea').forEach(function (l) {
      var d = l.querySelector('.linea__desc').value.trim();
      if (!d) return;
      var c = l.querySelector('.linea__cant').value || '1';
      var mo = l.querySelector('.linea__modelo');
      var m = mo ? mo.value.trim() : '';
      out.push(c + ' × ' + d + (m ? ' · ' + m : ''));
    });
    return out;
  }
  /* El guardia de hub.js apaga el botón de enviar en CUALQUIER `submit` para
     que nadie mande el mismo pedido dos veces. Aquí todavía no se manda nada
     —solo se abre el resumen—, así que se rearma: si no, quien pulsa «Volver a
     revisar» se encuentra el botón muerto y «Un momento…» ocho segundos. */
  function rearmarEnvio() {
    form.dataset.enviando = '';
    form.querySelectorAll('button[type=submit]').forEach(function (b) {
      b.disabled = false;
      if (b.dataset.txt) b.textContent = b.dataset.txt;
    });
  }

  form.addEventListener('submit', function (ev) {
    if (confirmado) return;
    ev.preventDefault();
    setTimeout(rearmarEnvio, 0);   // después del guardia, que corre en `document`

    var t = totalPedido();
    var dst = destino();
    var cli = $('elegido-nombre');
    var marcado = form.querySelector('[name=tipo]:checked');

    var caja = document.createElement('div');
    caja.className = 'tapa';
    caja.id = 'tapa-resumen';
    var dentro = document.createElement('div');
    dentro.className = 'tapa__caja';
    caja.appendChild(dentro);

    var tit = document.createElement('div');
    tit.className = 'tapa__t'; tit.textContent = 'Revisa antes de registrar';
    var sub = document.createElement('div');
    sub.className = 'tapa__s'; sub.textContent = 'Si algo no está bien, vuelve y corrígelo.';
    dentro.appendChild(tit); dentro.appendChild(sub);

    var lista = document.createElement('div');
    lista.className = 'resumen';
    [
      ['Venta',    marcado ? marcado.parentNode.querySelector('strong').textContent : ''],
      ['Cliente',  (cli && cli.textContent) || texto('#c_nombre')],
      /* Sin el distrito todavía elegido, «Lima · » dejaba un punto suelto
         colgando: las partes vacías no se pegan. */
      ['Entrega',  dst === 'oficina' ? 'Recoge en oficina'
                   : [dst === 'lima' ? 'Lima' : 'Provincia', texto('#buscar-ubigeo')]
                       .filter(Boolean).join(' · ')],
      ['Dirección', dst === 'oficina' ? '' : texto('[name=direccion_txt]')],
            /* Con «Otra», lo que hay que revisar es lo que se escribió, no la
         palabra «Otra»: justo el dato que ninguna lista valida. */
      ['Agencia',   dst === 'provincia'
                    ? (elAgencia && elAgencia.value === 'otra'
                       ? texto('[name=agencia_otra]') : texto('#agencia')) : ''],
      ['Sucursal',  dst === 'provincia' ? texto('[name=sucursal]') : ''],
      ['Productos', productosResumen().join(' · ')],
      ['Garantía',  texto('#garantia')],
      ['Entregar',  texto('[name=entrega_fecha]')
                    + (texto('[name=entrega_hora_desde]') ? ' ' + texto('[name=entrega_hora_desde]') : '')],
      ['Descuento', t.desc > 0 ? '− ' + soles(t.desc) + ' · ' + texto('[name=descuento_motivo]')
                    + (DESC_PIDE && t.desc * 100 > t.sub * DESC_TOPE && !DESC_APRUEBA ? ' · espera aprobación' : '') : ''],
      ['Total',     soles(t.total)],
      ['Paga ahora', cent(elPago.value) > 0
                     ? [soles(cent(elPago.value)), texto('#pago_metodo')].filter(Boolean).join(' · ')
                     : 'Nada todavía'],
      ['Por cobrar', soles(Math.max(0, t.total - cent(elPago.value)))],
      ['Nota',      texto('[name=nota]')]
    ].forEach(function (par) {
      var f = filaResumen(par[0], par[1]);
      if (f) lista.appendChild(f);
    });
    dentro.appendChild(lista);

    var acc = document.createElement('div');
    acc.className = 'acciones';
    acc.style.marginTop = '18px';
    var bien = document.createElement('button');
    bien.type = 'button'; bien.className = 'btn btn--negro';
    bien.textContent = 'CONFIRMAR Y REGISTRAR';
    var mal = document.createElement('button');
    mal.type = 'button'; mal.className = 'btn btn--linea';
    mal.textContent = 'Volver a revisar';
    acc.appendChild(bien); acc.appendChild(mal);
    dentro.appendChild(acc);

    mal.addEventListener('click', function () { caja.remove(); });
    bien.addEventListener('click', function () {
      /* Doble pulsación: se apaga el botón antes de mandar, que en el celular
         con poca señal el asesor pulsa dos veces y la venta entraba dos veces. */
      bien.disabled = true;
      bien.textContent = 'REGISTRANDO…';
      confirmado = true;
      form.dataset.enviando = '1';
      form.submit();
    });
    document.body.appendChild(caja);
    bien.focus();
  });

  pintarDestino(); pintarAgencia(); pintarMetodo(); aplicarModoPago(false);
  resumirPasos(); ordenarAcordeon(); marcarAbiertos();
})();
</script>
