/**
 * FOTOS DE TODAS LAS PANTALLAS, para mirarlas.
 *
 * El revisor de movil.cjs mide números —lo que se sale, la letra chica, el
 * botón que no se puede tocar— y eso no ve si dos bloques distintos se leen
 * como uno solo. Esto saca la foto entera de cada pantalla, por rol y por
 * ancho, y la deja en una carpeta para revisarla con los ojos.
 *
 *   bash pruebas/servidor.sh start
 *   WAKA_ANCHO=390 node pruebas/fotos.cjs /tmp/fotos
 */
const { chromium } = require('/home/claude/.npm-global/lib/node_modules/playwright');
const B = process.env.WAKA_URL || 'http://127.0.0.1:8123';
const ANCHO = parseInt(process.env.WAKA_ANCHO || '390', 10);
const DIR = process.argv[2] || '/tmp/fotos';
const SOLO = (process.env.WAKA_ROL || '').trim();
const fs = require('fs');

const ROLES = {
  asesor:        'asesor@waka.test',
  facturacion:   'factu@waka.test',
  administracion:'admin@waka.test',
  direccion:     'ceo@waka.test',
};
const RUTAS = [
  '/inicio', '/mas', '/clientes', '/clientes/ficha?id={cli}', '/clientes/editar?id={cli}',
  '/pedidos', '/pedidos/nuevo', '/pedidos/ficha?id={ped}', '/pedidos/ficha?id={ped}&nuevo=1',
  '/pedidos/mensaje?id={ped}', '/pedidos/despacho?id={pedlisto}', '/pedidos/por-despachar',
  '/pedidos/facturar?id={ped}',
  '/pagos/por-validar', '/pagos/por-validar?hecho=aprobado&ped={ped}',
  '/reportes', '/reportes/pagos', '/reportes/bitacora',
  '/usuarios', '/configuracion', '/configuracion/equipos', '/configuracion/contactos',
  '/configuracion/facturacion', '/configuracion/tienda', '/stock', '/stock/producto', '/stock/tienda', '/stock/tienda?l={lect}', '/bonos', '/recepcion', '/cashback', '/mi-perfil',
];

(async () => {
  fs.mkdirSync(DIR, { recursive: true });
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
    categories: [{ id: 1, name: 'Belleza y cuidado personal', slug: 'b' }] });
  vars[70003] = ['Rojo', 'Azul', 'Verde agua', 'Negro mate', 'Blanco perla'].map((c, k) =>
    ({ id: 71000 + k, sku: '', attributes: [{ id: 0, name: 'Color', option: c }, { id: 0, name: 'Tamaño', option: '120 × 60 cm' }] }));
  prods.push({ id: 70099, name: 'Repetido', sku: 'PRD-070002', type: 'simple', categories: [] });
  fs.writeFileSync(TIENDA, JSON.stringify({ productos: prods, variaciones: vars, key: CK, secret: CS,
                                            modos: ['basica'], llamadas: [],
                                            fotos: Object.fromEntries(prods.map((p) => ['https://img.compraenwaka.test/p' + (p.id - 70000) + '.jpg', FOTO])) }));
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
    await ctx0.close();
  }
  for (const [rol, email] of Object.entries(ROLES)) {
    if (SOLO && SOLO !== rol) continue;
    const ctx = await b.newContext({ viewport: { width: ANCHO, height: 900 }, deviceScaleFactor: 1 });
    const p = await ctx.newPage();
    await p.goto(B + '/entrar');
    await p.fill('input[name=email]', email);
    await p.fill('input[name=clave]', 'Clave-Larga-1');
    await p.click('button[type=submit]');
    await p.waitForLoadState('networkidle');

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
    // una lectura de la tienda para ver su vista previa (solo quien puede traer)
    let lect = '';
    await p.goto(B + '/stock/tienda');
    if (await p.$('#form-leer')) {
      await Promise.all([p.waitForNavigation(), p.click('#form-leer button')]);
      const m = p.url().match(/[?&]l=(\d+)/); if (m) lect = m[1];
    }
    for (const r0 of RUTAS) {
      const r = r0.replace('{ped}', ped).replace('{cli}', cli).replace('{pedlisto}', pedlisto).replace('{lect}', lect);
      if (/\{/.test(r)) continue;
      const resp = await p.goto(B + r, { waitUntil: 'networkidle' }).catch(() => null);
      if (!resp || resp.status() !== 200) continue;
      const nombre = rol + '_' + ANCHO + '_' + r.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') + '.png';
      await p.screenshot({ path: DIR + '/' + nombre, fullPage: true });
    }
    await ctx.close();
  }
  await b.close();
  try { fs.unlinkSync(TIENDA); } catch (e) {}
})();
