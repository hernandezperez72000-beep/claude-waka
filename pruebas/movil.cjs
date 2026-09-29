/**
 * AUDITORÍA DEL CELULAR — las 98 pantallas de los cuatro roles a 390 px.
 *
 * No es una prueba del banco de PHP: hace falta un navegador de verdad, porque
 * lo que se mide es dónde CAE cada elemento después de que el navegador aplique
 * el CSS. Ninguna prueba de PHP iba a descubrir que «En espera» y «Denegar»
 * quedaban en el píxel 484 y 575 de una pantalla de 390 — y con eso, que desde
 * el celular Facturación solo podía validar. Lo reportó el usuario el
 * 2026-09-14 y esto es lo que lo encontró.
 *
 * Mide tres cosas y no perdona ninguna:
 *   1. lo que se sale por la derecha y NO está dentro de algo que se desliza;
 *   2. la letra por debajo de 11 px;
 *   3. las zonas de toque por debajo de 34 px de alto o 26 de ancho.
 *
 * CÓMO SE CORRE
 *   bash pruebas/servidor.sh start
 *   node pruebas/movil.cjs          → imprime JSON; vacío («[]») es aprobado
 *
 * Necesita Playwright instalado (`npm i -g playwright`). Si la ruta del módulo
 * de abajo no existe en tu máquina, cámbiala: es lo único que hay que tocar.
 *
 * PASAR ESTO ANTES DE ENTREGAR CUALQUIER PANTALLA NUEVA. El HUB lleva
 * `box-sizing: border-box` desde el módulo 1 y aun así se salían tres
 * pantallas: el desborde casi nunca viene del ancho de una caja, viene de un
 * flex que no envuelve o de una celda de grid sin `min-width: 0`.
 */
const fs = require('fs');
const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const B = process.env.WAKA_URL || 'http://127.0.0.1:8123';
// El ancho se pasa por fuera: `WAKA_ANCHO=1101 node pruebas/movil.cjs`.
// 390 es el celular; 1101 y 1280 son los dos anchos donde el panel de dos
// columnas está a punto de plegarse y donde apareció el recorte de escritorio.
const ANCHO = parseInt(process.env.WAKA_ANCHO || '390', 10);

const ROLES = {
  asesor:        'asesor@waka.test',
  facturacion:   'factu@waka.test',
  administracion:'admin@waka.test',
  direccion:     'ceo@waka.test',
  /* 3g: los dos roles que no venden. */
  almacen:       'almacen@waka.test',
  marketing:     'marketing@waka.test',
};

// Rutas GET que pinta una pantalla. {id} se sustituye por un pedido/cliente real.
const RUTAS = [
  '/inicio', '/mas', '/mi-perfil', '/mi-perfil/contrasena',
  '/clientes', '/clientes/nuevo', '/clientes/ficha?id={cli}', '/clientes/editar?id={cli}',
  '/pedidos', '/pedidos/nuevo', '/pedidos/ficha?id={ped}', '/pedidos/ficha?id={ped}&nuevo=1',
  '/pedidos/mensaje?id={ped}', '/pedidos/despacho?id={pedlisto}', '/pedidos/por-despachar',
  '/pedidos/facturar?id={ped}',
  '/pagos/por-validar', '/pagos/por-validar?hecho=aprobado&ped={ped}',
  '/pagos/por-validar?hecho=espera&ped={ped}', '/pagos/por-validar?hecho=denegado&ped={ped}',
  '/reportes', '/reportes/pagos', '/reportes/bitacora',
  '/usuarios', '/usuarios/nuevo', '/configuracion', '/configuracion/equipos',
  '/configuracion/contactos', '/configuracion/facturacion', '/configuracion/tienda', '/reportar-error', '/stock', '/stock/producto',
  '/stock/tienda', '/stock/tienda?l={lect}', '/bonos', '/recepcion', '/cashback',
  /* 3d · el descuento: la lista de los que esperan, su configuración y la
     ficha de uno esperando (con los botones de aprobar y rechazar). */
  '/pedidos/descuentos', '/configuracion/descuentos', '/pedidos/ficha?id={peddesc}',
  /* 3e · lo que las ventas tienen que mover en la web (correr3 deja una
     esperando y una para revisar). */
  '/stock/pendientes',
  /* 3f · los métodos de pago, y la venta encima de la bandeja de pagos. */
  '/configuracion/metodos-pago', '/pagos/por-validar?ver={ped}',
  /* 3g · lo que Almacén alista, y su equipo en Configuración. */
  '/pedidos/por-alistar', '/configuracion/equipo-despacho',
  /* 3h · la pre venta: los lotes, uno abierto, el Excel, lo disponible y las agencias. */
  '/stock/lotes', '/stock/lote?id={lote}', '/stock/lote', '/stock/lote/excel', '/preventa', '/configuracion/agencias-carga',
  /* 3i · repuestos y garantía: la lista con el buscador de vigencia, pedirla,
     una pedida y una aprobada, su configuración, un repuesto y el filtro. */
  '/garantias', '/garantias?e=pedida', '/garantias?v=70636127', '/garantias/pedir?pedido={pedgar}',
  '/garantias/ver?id={gar}', '/garantias/ver?id={gar2}', '/configuracion/garantias',
  '/stock/producto?id={rep}', '/stock/producto?id={maq}', '/stock?v=repuestos', '/pedidos/ficha?id={pedgar}',
  /* 3j · las pestañas de Pedidos, «Enviados», el lote con su siguiente paso y
     la casilla de repuesto, y la pre venta entregada (con su foto). */
  '/pedidos?tab=preventa', '/pedidos/por-despachar?eq=silla', '/stock/lote?id={lote3j}', '/pedidos/ficha?id={pv3j}',
  /* 5a · bonos, metas y rachas: Mis bonos, el panel del CEO, lanzar la
     Cacería, las rachas, el modo team y la ficha de cada uno de los 7 bonos. */
  '/bonos?v=mios', '/bonos/rachas', '/bonos/caceria', '/equipo', '/configuracion/bonos', '/configuracion/metas',
  '/configuracion/bonos?id={bono0}', '/configuracion/bonos?id={bono1}', '/configuracion/bonos?id={bono2}', '/configuracion/bonos?id={bono3}',
  '/configuracion/bonos?id={bono4}', '/configuracion/bonos?id={bono5}', '/configuracion/bonos?id={bono6}',
];
/* Lo que deja correr3 para el 3i (si no está, esas rutas se saltan). */
let G3I = {};
try { G3I = JSON.parse(fs.readFileSync(require('os').tmpdir() + '/waka-3i.json', 'utf8')); } catch (e) {}

const MEDIR = () => {
  const doc = document.documentElement;
  const W = doc.clientWidth;
  const out = { cortados: [], chicos: [], toques: [], scroll: doc.scrollWidth, ancho: W, alto: document.body.scrollHeight };

  // ¿este elemento vive dentro de un contenedor que SÍ puede deslizarse de lado?
  function enScroller(el) {
    for (let p = el.parentElement; p; p = p.parentElement) {
      const s = getComputedStyle(p);
      if ((s.overflowX === 'auto' || s.overflowX === 'scroll') && p.scrollWidth > p.clientWidth + 1) return true;
      if (p === document.body) break;
    }
    return false;
  }
  function visible(el) {
    const s = getComputedStyle(el);
    if (s.display === 'none' || s.visibility === 'hidden' || +s.opacity === 0) return false;
    const b = el.getBoundingClientRect();
    /* Lo que está escondido para lectores de pantalla (.solo-lectores) mide
       1×1 px con overflow:hidden: es texto para quien no ve la pantalla, no
       algo recortado. */
    return b.width > 3 && b.height > 3;
  }
  function señas(el) {
    return { tag: el.tagName.toLowerCase(), cls: (el.className||'').toString().slice(0,44),
             txt: (el.textContent||'').trim().replace(/\s+/g,' ').slice(0,34) };
  }

  // 1 · lo que se sale por la derecha y no se puede alcanzar
  document.querySelectorAll('button, a, input, select, textarea, .chip, .btn, td, th, .tarjeta').forEach(el => {
    if (!visible(el)) return;
    const b = el.getBoundingClientRect();
    if (b.right > W + 1 && !enScroller(el)) {
      out.cortados.push({ ...señas(el), right: Math.round(b.right), sobra: Math.round(b.right - W) });
    }
  });

  // 1b · LO QUE SE RECORTA SIN DECIRLO.
  // El modo de fallo que esta auditoría NO vio la primera vez: una caja con
  // `overflow: hidden` cuyo contenido no cabe. No hay barra, no hay desborde
  // de página, no hay nada — el botón simplemente no está. Es peor que el
  // desborde, porque el desborde al menos se nota.
  out.recortados = [];
  document.querySelectorAll('*').forEach(el => {
    if (!visible(el)) return;
    const s = getComputedStyle(el);
    if (s.overflowX !== 'hidden' && s.overflow !== 'hidden') return;
    if (el.scrollWidth > el.clientWidth + 2 && el.clientWidth > 0) {
      // ¿hay algo que se pulse ahí dentro y quede fuera del recorte?
      const dentro = [...el.querySelectorAll('button, a[href], input, select')]
        .filter(x => visible(x) && x.getBoundingClientRect().right
                     > el.getBoundingClientRect().left + el.clientWidth + 1)
        .map(x => (x.textContent||x.name||x.tagName).trim().slice(0,22));
      out.recortados.push({ ...señas(el), pierde: el.scrollWidth - el.clientWidth,
                            sinAlcance: dentro.slice(0, 6) });
    }
  });

  // 2 · letra demasiado chica para leerse en un celular (solo en el celular:
  //     en un monitor a 1.280 px estos tamaños se leen bien y el ratón acierta)
  const ESCELULAR = W <= 900;
  if (ESCELULAR)
  document.querySelectorAll('button, a, input, select, textarea, label, td, th, p, span, div').forEach(el => {
    if (!visible(el)) return;
    if (!el.childNodes.length) return;
    let propio = false;
    el.childNodes.forEach(n => { if (n.nodeType === 3 && n.textContent.trim().length > 2) propio = true; });
    if (!propio) return;
    const px = parseFloat(getComputedStyle(el).fontSize);
    if (px < 11) out.chicos.push({ ...señas(el), px: Math.round(px * 10) / 10 });
  });

  // 3 · zonas de toque demasiado pequeñas
  if (ESCELULAR) document.querySelectorAll('button, a[href], input[type=submit], select, [role=button]').forEach(el => {
    if (!visible(el)) return;
    const b = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    if (s.display === 'inline' && el.tagName === 'A') return;     // enlaces dentro de un párrafo
    if (b.height < 34 || b.width < 26) {
      out.toques.push({ ...señas(el), w: Math.round(b.width), h: Math.round(b.height) });
    }
  });
  return out;
};

(async () => {
  const b = await chromium.launch();
  /* Sin salida a internet (el banco en la nube), las fuentes de Google dejan
     cada página esperando su tiempo muerto. Se cortan al momento: el HUB ya
     trae su letra de reserva, y es la que se mide. */
  const __ctx = b.newContext.bind(b);
  b.newContext = async (o) => { const c = await __ctx(o);
    await c.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort()); return c; };
  /* LA TIENDA DE MENTIRA (módulo 3b): para ver la vista previa del importador
     con datos —nombres largos, las tres listas— hace falta una tienda que
     conteste. pruebas/inyectar.php la carga si existe este archivo. */
  const FOTO = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNjAK/9sAQwANCQoLCggNCwoLDg4NDxMgFRMSEhMnHB4XIC4pMTAuKS0sMzpKPjM2RjcsLUBXQUZMTlJTUjI+WmFaUGBKUVJP/9sAQwEODg4TERMmFRUmTzUtNU9PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09P/8AAEQgAyAEsAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A3qKKK+NPWCiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKAP/Z';
  const TIENDA = require('os').tmpdir() + '/waka-tienda-falsa-' + (new URL(B).port || '80') + '.json';
  const CK = 'ck_' + '6c'.repeat(20), CS = 'cs_' + '1f'.repeat(20);
  const prods = [], vars = {};
  for (let i = 1; i <= 14; i++) prods.push({ id: 70000 + i, type: i === 3 ? 'variable' : 'simple',
    name: i === 1 ? 'CAMILLA HEAD SPA LUXURY CON CALENTADOR Y VIBRACIÓN PROFESIONAL EDICIÓN 2026' : 'Producto de muestra ' + i,
    sku: i === 5 ? '' : (i === 6 ? 'COD: PRD-0' + (70000 + i) : 'PRD-0' + (70000 + i)),
    regular_price: i % 2 ? '1299.90' : '',
    images: i % 3 ? [{ id: 90000 + i, src: 'https://img.compraenwaka.test/p' + i + '.jpg' }] : [],
    /* 3b.5: el stock de la web, con todos sus casos a la vista. */
    manage_stock: i % 4 !== 0, stock_quantity: i % 4 !== 0 ? (i % 5) * 7 : null,
    stock_status: i % 5 === 0 ? 'outofstock' : (i === 8 ? 'onbackorder' : 'instock'),
    categories: [{ id: 1, name: 'Belleza y cuidado personal', slug: 'b' }] });
  vars[70003] = ['Rojo', 'Azul', 'Verde agua', 'Negro mate', 'Blanco perla'].map((c, k) =>
    ({ id: 71000 + k, sku: '', manage_stock: true, stock_quantity: k * 2, stock_status: k ? 'instock' : 'outofstock',
       attributes: [{ id: 0, name: 'Color', option: c }, { id: 0, name: 'Tamaño', option: '120 × 60 cm' }] }));
  prods.push({ id: 70099, name: 'Repetido', sku: 'PRD-070002', type: 'simple', categories: [] });
  /* 3e · uno agotado CON precio, para ver que el buscador no deja elegirlo. */
  prods.push({ id: 70015, name: 'Producto agotado', sku: 'PRD-070015', type: 'simple', regular_price: '99.00', images: [],
               manage_stock: true, stock_quantity: 0, stock_status: 'outofstock', categories: [] });
  fs.writeFileSync(TIENDA, JSON.stringify({ productos: prods, variaciones: vars, key: CK, secret: CS,
                                            modos: ['basica'], llamadas: [],
                                            /* Dos fotos no llegan: la tarjeta de «Faltan fotos» sale a la vista. */
                                            fotos: Object.fromEntries(prods.filter((p) => ![2, 4].includes(p.id - 70000))
                                              .map((p) => ['https://img.compraenwaka.test/p' + (p.id - 70000) + '.jpg', FOTO])) }));
  {
    const ctx0 = await b.newContext(); const p0 = await ctx0.newPage();
    await p0.goto(B + '/entrar');
    await p0.fill('input[name=email]', 'admin@waka.test');
    await p0.fill('input[name=clave]', 'Clave-Larga-1');
    await p0.click('button[type=submit]'); await p0.waitForLoadState('networkidle');
    await p0.goto(B + '/configuracion/tienda');
    if (await p0.$('#form-tienda')) {
      await p0.fill('#form-tienda input[name=ruta]', 'https://compraenwaka.test');
      await p0.fill('#form-tienda input[name=key]', CK);
      await p0.fill('#form-tienda input[name=secret]', CS);
      await p0.click('#form-tienda button[type=submit]'); await p0.waitForLoadState('networkidle');
    }
    /* 3c: el conector puesto, para medir su tarjeta, la del stock en la ficha
       y la lista de «En la web dice otra cosa». */
    const CLAVE_C = 'c0'.repeat(24);
    fs.writeFileSync(require('os').tmpdir() + '/waka-wp-falso-' + (new URL(B).port || '80') + '.json',
      JSON.stringify({ opciones: { waka_hub_clave: CLAVE_C }, transitorios: {}, productos: {} }));
    await p0.goto(B + '/configuracion/tienda');
    if (await p0.$('#form-conector')) {
      await p0.fill('#form-conector input[name=conector_clave]', CLAVE_C);
      await p0.click('#form-conector button[type=submit]'); await p0.waitForLoadState('networkidle');
    }
    /* Y se guarda una vez, para que el catálogo, la ficha y el buscador del
       pedido tengan fotos que medir. */
    p0.on('dialog', (d) => d.accept());
    await p0.goto(B + '/stock/tienda');
    if (await p0.$('#form-leer')) {
      await Promise.all([p0.waitForNavigation(), p0.click('#form-leer button')]);
      if (await p0.$('#form-guardar')) await Promise.all([p0.waitForNavigation(), p0.click('#form-guardar button')]);
    }
    await ctx0.close();
  }
  /* 3d · un pedido con el descuento esperando: lo deja correr3 al final. Se
     busca con Administración, que es quien tiene la lista. */
  let peddesc = '';
  {
    const ctxd = await b.newContext({ viewport: { width: ANCHO, height: 900 } });
    const pd = await ctxd.newPage();
    await pd.goto(B + '/entrar');
    await pd.fill('input[name=email]', 'admin@waka.test');
    await pd.fill('input[name=clave]', 'Clave-Larga-1');
    await pd.click('button[type=submit]');
    await pd.waitForLoadState('networkidle');
    await pd.goto(B + '/pedidos/descuentos');
    peddesc = await pd.evaluate(() => { const a = document.querySelector('#lista-descuentos a[href*="/pedidos/ficha?id="]');
      return a ? a.getAttribute('href').match(/id=(\d+)/)[1] : ''; });
    await ctxd.close();
  }
  const hallazgos = [];
  for (const [rol, email] of Object.entries(ROLES)) {
    const ctx = await b.newContext({ viewport: { width: ANCHO, height: 900 }, deviceScaleFactor: 1 });
    const p = await ctx.newPage();
    await p.goto(B + '/entrar');
    await p.fill('input[name=email]', email);
    await p.fill('input[name=clave]', 'Clave-Larga-1');
    await p.click('button[type=submit]');
    await p.waitForLoadState('networkidle');

    // ids reales visibles para ESTE rol
    await p.goto(B + '/pedidos');
    const ped = await p.evaluate(() => { const a = document.querySelector('a[href*="/pedidos/ficha?id="]');
      return a ? a.getAttribute('href').match(/id=(\d+)/)[1] : ''; });
    await p.goto(B + '/clientes');
    const cli = await p.evaluate(() => { const a = document.querySelector('a[href*="/clientes/ficha?id="]');
      return a ? a.getAttribute('href').match(/id=(\d+)/)[1] : ''; });
    let pedlisto = ped;
    await p.goto(B + '/pedidos/por-despachar');
    if (p.url().includes('por-despachar')) {
      const x = await p.evaluate(() => { const a = document.querySelector('form.form-despacho input[name="id"]');
        return a ? a.value : ''; });
      if (x) pedlisto = x;
    }

    // un pedido con el descuento esperando (lo deja correr3), el que ESTE rol puede ver
    // (sale de la lista de Administración, arriba: el resto de roles lo abre si lo puede ver)

    // un lote de pre venta que ESTE rol puede abrir (3h)
    let lote = '';
    await p.goto(B + '/stock/lotes');
    if (p.url().includes('/stock/lotes')) {
      lote = await p.evaluate(() => { const a = document.querySelector('a[href*="/stock/lote?id="]');
        return a ? a.getAttribute('href').match(/id=(\d+)/)[1] : ''; });
    }

    // una lectura de la tienda para ver su vista previa (solo quien puede traer)
    let lect = '';
    await p.goto(B + '/stock/tienda');
    if (await p.$('#form-leer')) {
      await Promise.all([p.waitForNavigation(), p.click('#form-leer button')]);
      const m = p.url().match(/[?&]l=(\d+)/); if (m) lect = m[1];
    }
    for (const r0 of RUTAS) {
      const r = r0.replace('{ped}', ped).replace('{cli}', cli).replace('{pedlisto}', pedlisto).replace('{lect}', lect).replace('{peddesc}', peddesc).replace('{lote}', lote)
        .replace('{pedgar}', G3I.pedgar || '{x}').replace('{gar2}', G3I.gar2 || '{x}').replace('{gar}', G3I.gar || '{x}')
        .replace('{rep}', G3I.rep || '{x}').replace('{maq}', G3I.maq || '{x}')
        .replace('{lote3j}', G3I.lote3j || '{x}').replace('{pv3j}', G3I.pv3j || '{x}')
        .replace(/\{bono(\d)\}/, (x, i) => (G3I.bonos || [])[+i] || '{x}');
      if (/\{/.test(r)) continue;
      let cod = 0;
      const resp = await p.goto(B + r, { waitUntil: 'networkidle' }).catch(() => null);
      cod = resp ? resp.status() : 0;
      if (cod !== 200) continue;
      const m = await p.evaluate(MEDIR);
      if (m.cortados.length || m.recortados.length || m.chicos.length || m.toques.length || m.scroll > m.ancho + 1) {
        hallazgos.push({ ancho: ANCHO, rol, ruta: r, ...m });
      }
    }
    /* 3e · LO AGOTADO NO SE ELIGE: con el control de stock encendido (lo
       deja así correr3) y una entrega inmediata, el buscador del pedido
       enseña lo agotado pero no deja elegirlo — ni el producto sin colores
       («Producto agotado», en la tienda de mentira) ni el color agotado
       («Rojo» de «muestra 3»). Ninguna prueba de PHP puede ver esto. */
    if (rol === 'asesor') {
      await p.goto(B + '/pedidos/nuevo', { waitUntil: 'networkidle' });
      const inp = await p.$('.linea__desc');
      /* En el celular el formulario es un acordeón y los productos empiezan
         cerrados; lo que se prueba aquí no depende del ancho. */
      if (inp && await inp.isVisible()) {
        const buscar = async (t) => { await inp.fill(''); await inp.type(t); await p.waitForTimeout(700); };
        await buscar('Producto agotado');
        const dis5 = await p.evaluate(() => { const x = [...document.querySelectorAll('.linea__sug .sugerencia')]
          .find((b) => /^Producto agotado$/.test(b.querySelector('strong').textContent.trim())); return x ? x.disabled : null; });
        if (dis5 !== true) hallazgos.push({ ancho: ANCHO, rol, ruta: '/pedidos/nuevo (agotado)', recortados: ['un producto agotado se puede elegir: ' + dis5] });
        await buscar('muestra 3');
        const b3 = await p.evaluateHandle(() => [...document.querySelectorAll('.linea__sug .sugerencia')]
          .find((b) => /muestra 3$/.test(b.querySelector('strong').textContent.trim())) || null);
        const el3 = b3.asElement();
        if (el3) {
          await el3.click();
          const rojo = await p.evaluate(() => { const o = [...document.querySelectorAll('.linea__var option')].find((x) => x.dataset.nombre && /^Rojo/.test(x.dataset.nombre));
            return o ? o.disabled : null; });
          const azul = await p.evaluate(() => { const o = [...document.querySelectorAll('.linea__var option')].find((x) => x.dataset.nombre && /^Azul/.test(x.dataset.nombre));
            return o ? o.disabled : null; });
          if (rojo !== true || azul !== false) hallazgos.push({ ancho: ANCHO, rol, ruta: '/pedidos/nuevo (color agotado)', recortados: ['Rojo agotado: ' + rojo + ' · Azul con stock: ' + azul] });
        } else {
          hallazgos.push({ ancho: ANCHO, rol, ruta: '/pedidos/nuevo (color agotado)', recortados: ['no aparece «muestra 3» en el buscador'] });
        }
      }
    }
    /* 3f · LA FOTO DEL DNI SALE CON LOS MÉTODOS QUE LA PIDEN: con el POS a la
       vista, con el efectivo escondida. Es JavaScript: ninguna prueba de PHP
       lo ve. */
    if (rol === 'asesor') {
      await p.goto(B + '/pedidos/nuevo', { waitUntil: 'networkidle' });
      const dni = await p.evaluate(() => {
        const s = document.querySelector('select[data-metodo-fotos]'); const c = document.querySelector('[data-foto-campo="foto_dni"]');
        if (!s || !c) return 'falta el campo';
        const elegir = (re) => { const o = [...s.options].find((x) => re.test(x.textContent)); if (!o) return false;
          s.value = o.value; s.dispatchEvent(new Event('change', { bubbles: true })); return true; };
        if (!elegir(/POS/)) return 'no hay POS';
        const conPos = !c.hidden;
        elegir(/Efectivo/);
        return conPos && c.hidden ? 'ok' : 'con POS: ' + conPos + ' · con efectivo escondida: ' + c.hidden;
      });
      if (dni !== 'ok') hallazgos.push({ ancho: ANCHO, rol, ruta: '/pedidos/nuevo (foto del DNI)', recortados: [dni] });
    }
    /* EL MENÚ «···» DE UNA FILA TIENE QUE VERSE (usuario, 2026-09-25): con una
       sola fila, la caja de la tabla lo recortaba y «no salía nada». Se busca
       un pedido, se abre su menú y se mira que su primera opción esté a la
       vista y se pueda tocar. */
    await p.goto(B + '/pedidos?v=todos', { waitUntil: 'networkidle' });
    const cod1 = await p.evaluate(() => { const a = document.querySelector('td.principal .fila__t'); return a ? a.textContent.trim() : ''; });
    if (cod1) {
      await p.goto(B + '/pedidos?v=todos&q=' + encodeURIComponent(cod1), { waitUntil: 'networkidle' });
      const s = await p.$('.menu-fila > summary');
      if (s) {
        await s.click();
        await p.waitForTimeout(200);       // el menú se coloca en el evento «toggle», que llega después del clic
        const vis = await p.evaluate(() => {
          const c = document.querySelector('.menu-fila[open] .menu-chip__caja');
          if (!c) return false;
          const a = c.querySelector('a, button'); const q = a.getBoundingClientRect();
          const el = document.elementFromPoint(q.left + q.width / 2, q.top + q.height / 2);
          return !!el && c.contains(el);
        });
        if (!vis) hallazgos.push({ ancho: ANCHO, rol, ruta: '/pedidos (menú ··· de la fila)', recortados: ['el menú de la fila no se ve'] });
      }
    }
    await ctx.close();
  }
  console.log(JSON.stringify(hallazgos, null, 1));
  await b.close();
  try { fs.unlinkSync(TIENDA); } catch (e) {}
  try { fs.unlinkSync(require('os').tmpdir() + '/waka-wp-falso-' + (new URL(B).port || '80') + '.json'); } catch (e) {}
})();
