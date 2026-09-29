/* LOS AVISOS EN VIVO, EJECUTADOS DE VERDAD (3d).
 *
 * El sondeo del asesor llamaba a dos funciones que vivían en OTRO bloque del
 * archivo: el error saltaba dentro de la promesa, el .catch lo contaba como
 * fallo de red y el aviso de «ya puedes despachar», el de «te pararon un pago»
 * —y ahora el de la respuesta al descuento— no salían NUNCA. Ninguna prueba
 * de PHP puede ver eso. Aquí se carga hub.js con un documento de mentira, se
 * le contesta al sondeo y se mira que la barra aparezca.
 *
 *   node pruebas/avisos.cjs      → imprime «[]» si todo bien
 */
'use strict';
const fs = require('fs');
const vm = require('vm');
const codigo = fs.readFileSync(__dirname + '/../assets/js/hub.js', 'utf8');

function elemento(tag) {
  return {
    tag, hijos: [], dataset: {}, style: {}, className: '', textContent: '', attrs: {},
    appendChild(h) { this.hijos.push(h); h.padre = this; return h; },
    remove() { if (this.padre) this.padre.hijos = this.padre.hijos.filter(x => x !== this); },
    setAttribute(k, v) { this.attrs[k] = v; }, getAttribute(k) { return this.attrs[k]; },
    addEventListener() {}, querySelector(sel) {
      const t = sel.replace(/[^a-z]/g, '');
      const busca = (n) => { for (const h of n.hijos) { if (h.tag === t) return h; const r = busca(h); if (r) return r; } return null; };
      return busca(this);
    },
    querySelectorAll() { return []; }, closest() { return null; },
  };
}
function texto(n) { return (n.textContent || '') + n.hijos.map(texto).join(' '); }

async function correr(dataset, respuesta, urlEsperada, guardado) {
  const body = elemento('body');
  Object.assign(body.dataset, dataset);
  const pedidas = [];
  const doc = {
    body, documentElement: elemento('html'), visibilityState: 'visible', title: 'HUB',
    createElement: elemento, getElementById: (id) => null,
    querySelector: () => null, querySelectorAll: () => [], addEventListener() {},
  };
  const intervalos = [];
  const tempranos = [];
  const ctx = {
    document: doc, console, JSON, Math, Date, parseInt, encodeURIComponent,
    setTimeout: (f) => { tempranos.push(f); return 0; }, setInterval: (f) => { intervalos.push(f); return 0; },
    fetch: (url) => { pedidas.push(url); return Promise.resolve({ ok: true, json: () => Promise.resolve(respuesta) }); },
    location: { href: '' },
    sessionStorage: { getItem: (k) => (guardado && guardado[k]) || null, setItem() {} },
    navigator: {}, AudioContext: undefined,
  };
  ctx.window = ctx;
  ctx.addEventListener = () => {};
  ctx.matchMedia = () => ({ matches: false, addEventListener() {}, addListener() {} });
  ctx.requestAnimationFrame = (f) => 0;
  ctx.window.wakaSonido = () => {};
  vm.createContext(ctx);
  try { vm.runInContext(codigo, ctx); } catch (e) { return { t: '', pedidas: [], mal: ['hub.js revienta al cargar: ' + e.message] }; }
  ctx.window.wakaSonido = () => {};
  const soloTempranos = !!(guardado && guardado.__soloTempranos);
  (soloTempranos ? tempranos : intervalos).forEach(f => { try { f(); } catch (e) { /* otros relojes de la página: no son de esta prueba */ } });
  await new Promise(r => setImmediate(r)); await new Promise(r => setImmediate(r));
  await new Promise(r => setImmediate(r));
  const t = texto(body);
  const mal = [];
  if (!pedidas.some(u => u.includes(urlEsperada))) mal.push('no preguntó a ' + urlEsperada + ' (' + pedidas.join(', ') + ')');
  return { t, mal, pedidas };
}

(async () => {
  const hallazgos = [];
  // 1 · El asesor: le aprobaron un descuento y le desbloquearon una venta.
  let r = await correr({ avisaDespacho: '1', despachoUltimo: '5', descUltimo: '10', trabadosN: '0', raiz: '/' },
    { ok: true, n: 1, nuevos: 1, ultimo: 6, trabados: 1, desc_ultimo: 12, desc_n: 1,
      desc_codigo: 'P-00042', desc_id: 42, desc_ok: true }, 'pedidos/nuevos-despacho');
  hallazgos.push(...r.mal);
  if (!r.t.includes('Aprobaron el descuento de P-00042')) hallazgos.push('al asesor no le sale la respuesta al descuento');
  if (!r.t.includes('lista para despacho')) hallazgos.push('al asesor no le sale «lista para despacho»');
  if (!r.t.includes('Facturación paró 1')) hallazgos.push('al asesor no le sale el pago parado');
  if (!r.pedidas.some(u => u.includes('ddesde=10'))) hallazgos.push('el sondeo del asesor no manda su marca del descuento');

  // 2 · Administración: le piden un descuento.
  r = await correr({ avisaPagos: '1', pagosN: '0', pagosUltimo: '3', reclamosUltimo: '20', usuario: '7', raiz: '/' },
    { ok: true, n: 0, nuevos: 0, ultimo: 3, reclamos: 0, reclamo_ultimo: 25, descuentos: 1,
      descuento_codigo: 'P-00043', descuento_asesor: 'Rosa' }, 'pagos/nuevos');
  hallazgos.push(...r.mal);
  if (!r.t.includes('Rosa pide un descuento en P-00043')) hallazgos.push('a Administración no le sale el descuento por aprobar');

  // 3 · Administración: sin reclamo nuevo, el descuento suena igual.
  r = await correr({ avisaPagos: '1', pagosN: '0', pagosUltimo: '3', reclamosUltimo: '20', usuario: '7', raiz: '/' },
    { ok: true, n: 0, nuevos: 0, ultimo: 3, reclamos: 0, reclamo_ultimo: 20, descuentos: 1, descuento_ultimo: 21,
      descuento_codigo: 'P-00044', descuento_asesor: 'Rosa' }, 'pagos/nuevos');
  if (!r.t.includes('P-00044')) hallazgos.push('el descuento no suena si el reclamo no sube');

  // 4 · El asesor cambió de página justo antes de la respuesta: la vuelta siguiente pregunta desde la marca guardada.
  r = await correr({ avisaDespacho: '1', despachoUltimo: '5', descUltimo: '30', trabadosN: '0', usuario: '9', raiz: '/' },
    { ok: true, n: 0, nuevos: 0, ultimo: 5, trabados: 0, desc_ultimo: 30, desc_n: 1,
      desc_codigo: 'P-00045', desc_id: 45, desc_ok: false }, 'ddesde=22',
    { 'waka-desc-ultimo': JSON.stringify({ u: '9', v: 22, t: Date.now() }) });
  hallazgos.push(...r.mal);
  if (!r.t.includes('No aprobaron el descuento de P-00045')) hallazgos.push('la respuesta se pierde al cambiar de página');

  // 5 · 5a: la racha de un compañero. La página se abrió sin avisos hoy (marca 0):
  //     el primero del día SÍ sale, y la pregunta lleva la marca.
  r = await correr({ avisaDespacho: '1', despachoUltimo: '5', descUltimo: '10', trabadosN: '0', rachaUltimo: '0', raiz: '/' },
    { ok: true, n: 0, nuevos: 0, ultimo: 5, trabados: 0, desc_ultimo: 10, desc_n: 0,
      racha_ultimo: 3, rachas: [{ nombre: 'Viviana', nivel: 4, texto: 'Viviana está en racha x4' }] }, 'rdesde=0');
  hallazgos.push(...r.mal);
  if (!r.t.includes('Viviana está en racha x4')) hallazgos.push('el aviso de racha del compañero no sale');
  // 6 · Sin marca en la página: se pregunta con -1 (solo tomar la marca).
  r = await correr({ avisaDespacho: '1', despachoUltimo: '5', descUltimo: '10', trabadosN: '0', raiz: '/' },
    { ok: true, n: 0, nuevos: 0, ultimo: 5, trabados: 0, desc_ultimo: 10, desc_n: 0, racha_ultimo: 3, rachas: [] }, 'rdesde=-1');
  hallazgos.push(...r.mal);

  console.log(JSON.stringify(hallazgos));
})();
