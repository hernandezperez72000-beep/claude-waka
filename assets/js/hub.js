/* ══════════════════════════════════════════════════════════════════════
   EL SONIDO DE WAKA

   Dos avisos distintos tienen que sonar distinto, o a la semana nadie sabe si
   lo que sonó le toca a él. Son motivos cortos de tres y dos notas, hechos con
   el propio navegador: sin archivo que descargar y sin depender de nadie de
   fuera, que en un hosting compartido importa.

     · «pago»     — sube: entró algo que hay que mirar.  (Facturación)
     · «despacho» — dos notas abiertas: se te desbloqueó algo. (Asesor)
     · «reclamo»  — la misma nota dos veces: alguien espera. (Facturación)

   El navegador bloquea el sonido hasta que la persona haya tocado algo en la
   página; a partir de ahí suena. Si no puede, el aviso se sigue VIENDO: el
   sonido es un extra, nunca la única puerta.
   ══════════════════════════════════════════════════════════════════════ */
window.wakaSonido = function (cual) {
  try {
    var Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    var ctx = new Ctx();
    if (ctx.state === 'suspended') { ctx.close(); return; }

    var notas = cual === 'despacho' ? [[523.25, 0.00], [784.00, 0.13]]          // do–sol
              : cual === 'reclamo'  ? [[880.00, 0.00], [880.00, 0.16], [880.00, 0.32]]   // la–la–la
              /* 5b · «almacen»: cuatro notas que suben, para que en el
                 almacén se oiga distinto de todo lo demás. */
              : cual === 'almacen'  ? [[392.00, 0.00], [523.25, 0.12], [659.25, 0.24], [783.99, 0.36]]
              : [[659.25, 0.00], [880.00, 0.09], [1174.66, 0.18]];                        // mi–la–re

    notas.forEach(function (n) {
      var osc = ctx.createOscillator(), vol = ctx.createGain();
      var t0 = ctx.currentTime + n[1];
      osc.type = 'triangle';
      osc.frequency.value = n[0];
      vol.gain.setValueAtTime(0.0001, t0);
      vol.gain.exponentialRampToValueAtTime(0.16, t0 + 0.02);
      vol.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.26);
      osc.connect(vol); vol.connect(ctx.destination);
      osc.start(t0); osc.stop(t0 + 0.28);
    });
    setTimeout(function () { try { ctx.close(); } catch (e) {} }, 900);
  } catch (e) { /* sin sonido, pero el aviso se sigue viendo */ }
};

/* HUB Waka — JavaScript propio y mínimo.
   Sin librerías: el HUB tiene que abrir rápido en un celular con señal regular. */
(function () {
  'use strict';

  /* ── El service worker cachea el esqueleto para que la segunda apertura
        sea inmediata. Solo se registra sobre HTTPS. ───────────────────── */
  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    window.addEventListener('load', function () {
      var raizSW = document.body.dataset.raiz || '/';
      navigator.serviceWorker.register(raizSW + 'sw.js').catch(function () { /* sin drama */ });

      // Al salir, el celular no se queda con nada de la persona anterior.
      // Se espera a serviceWorker.ready: en la primera visita, y justo después
      // de registrarlo, controller todavía es null y el mensaje se perdería.
      if (document.body.dataset.limpiar === '1') {
        navigator.serviceWorker.ready.then(function (reg) {
          var dest = navigator.serviceWorker.controller || reg.active;
          if (dest) dest.postMessage('olvidar-todo');
        }).catch(function () { /* sin drama */ });
      }
    });
  }

  /* ── Aviso de "sin conexión" ──────────────────────────────────────────
        El HUB no borra lo que ya cargó ni expulsa a nadie: se queda con la
        última foto y dice de cuándo es. */
  var barra = null;
  function avisoSinSenal(hay) {
    if (hay) { if (barra) { barra.remove(); barra = null; } return; }
    if (barra) return;
    barra = document.createElement('div');
    barra.setAttribute('role', 'status');
    barra.style.cssText = 'position:fixed;left:0;right:0;top:0;z-index:80;background:#000;' +
      'color:#FAD91A;font:600 12.5px Poppins,system-ui,sans-serif;padding:10px 16px;text-align:center';
    barra.textContent = 'Sin conexión. Puedes seguir mirando; para registrar algo necesitas señal.';
    document.body.appendChild(barra);
  }
  window.addEventListener('online',  function () { avisoSinSenal(true); });
  window.addEventListener('offline', function () { avisoSinSenal(false); });
  if (!navigator.onLine) avisoSinSenal(false);

  /* ── Un solo envío por formulario: evita pedidos y pagos duplicados
        cuando la señal va lenta y la persona toca dos veces. ─────────── */
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (!(f instanceof HTMLFormElement) || f.dataset.multiple === '1') return;
    if (f.dataset.enviando === '1') { ev.preventDefault(); return; }
    f.dataset.enviando = '1';
    var b = f.querySelector('button[type=submit], button:not([type])');
    if (b) {
      b.disabled = true;
      var txt = b.textContent;
      b.dataset.txt = txt;
      b.textContent = b.dataset.espera || 'Un momento…';
      /* Lo que tarda de verdad (leer la tienda entera puede llevar un minuto)
         lo dice el botón con data-espera, y no se rehabilita a los 8 s: un
         segundo toque volvería a empezar la lectura desde cero. */
      if (b.dataset.espera) return;
      // Si el envío no llega a irse (validación del navegador), se rehabilita
      // para que la persona no se quede con el botón muerto.
      setTimeout(function () {
        if (f.dataset.enviando !== '1') return;
        if (document.visibilityState !== 'visible') return;
        b.disabled = false; b.textContent = b.dataset.txt || txt; f.dataset.enviando = '';
      }, 8000);
    }
  });
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) return;
    document.querySelectorAll('form[data-enviando="1"]').forEach(function (f) {
      f.dataset.enviando = '';
      var b = f.querySelector('button[type=submit]');
      if (b && b.dataset.txt) { b.disabled = false; b.textContent = b.dataset.txt; }
    });
  });

  /* ── Confirmación de lo que no se puede deshacer.
        Cualquier botón con data-confirmar="texto" pregunta antes. ─────── */
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-confirmar]');
    if (!el) return;
    if (!window.confirm(el.getAttribute('data-confirmar'))) ev.preventDefault();
  });

  /* ── Abrir la franja de «en espera / denegar» de una fila ───────────
        Es un <tr hidden> que vive justo debajo. Sin este JS los dos botones
        no hacen nada, pero la franja lleva dentro un enlace a la venta entera
        y la pantalla de facturar sigue teniendo las dos salidas: ninguna se
        pierde, solo cuesta un clic más. */
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-abre]');
    if (!b) return;
    var fila = document.getElementById(b.getAttribute('data-abre'));
    if (!fila) return;
    fila.hidden = !fila.hidden;
    if (fila.hidden) return;
    // El cursor cae en la casilla del botón que se pulsó, no siempre en la
    // primera: quien pulsa «Denegar» quiere escribir en «Denegar».
    var sel = b.getAttribute('data-foco') === 'denegar' ? '[data-denegar]' : 'input[type=text]';
    var campo = fila.querySelector(sel) || fila.querySelector('input[type=text]');
    if (campo) campo.focus();
  });

  /* ── El modo noche automático: si el usuario lo dejó en "auto" y el
        servidor pintó un tema, se respeta. Aquí solo se corrige cuando
        la página estuvo abierta cruzando las 7 p. m. o las 6 a. m. ───── */
  setInterval(function () {
    var raiz = document.documentElement;
    /* Solo se corrige en «auto», y se exige que la bandera esté puesta de
       verdad: comparar con !== '0' daba verdadero también cuando el atributo
       no existía, y entonces esto le apagaba el modo noche a quien lo había
       elegido a propósito, a media pantalla y sin avisar. */
    if (raiz.dataset.forzado === '1') return;
    if (raiz.dataset.auto !== '1') return;
    var h = new Date().getHours();
    var deberia = (h >= 19 || h < 6) ? 'oscuro' : 'claro';
    if (raiz.dataset.theme !== deberia) raiz.dataset.theme = deberia;
  }, 60000);

  /* ── Aviso de contraseña temporal ─────────────────────────────────────
        Se pinta desde el HTML, así que sin JavaScript igual se ve y se
        puede cerrar con el enlace. Esto solo añade copiar y cerrar con Esc. */
  var velo = document.getElementById('velo-clave');
  if (velo) {
    var cerrar = document.getElementById('clave-cerrar');
    if (cerrar) cerrar.focus();

    function copiarTexto(txt, boton) {
      var antes = boton.dataset.antes || boton.textContent;
      boton.dataset.antes = antes;
      function ok(msg) {
        boton.textContent = msg;
        setTimeout(function () { boton.textContent = antes; }, 1900);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(function () { ok('Copiado'); })
          .catch(function () { ok('Cópialo a mano'); });
      } else {
        ok('Cópialo a mano');
      }
    }

    velo.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-copiar]');
      if (!b) return;
      var wa = document.getElementById('clave-wa');
      var cl = document.getElementById('clave-texto');
      copiarTexto(b.dataset.copiar === 'wa' ? (wa ? wa.value : '') : (cl ? cl.textContent : ''), b);
    });

    // Escape cierra, igual que el enlace. No se cierra tocando fuera:
    // esta contraseña no se vuelve a mostrar y un clic despistado la perdería.
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && cerrar) window.location.href = cerrar.href;
    });
  }

})();

/* ══════════════════════════════════════════════════════════════════════
   AVISO DE PAGOS NUEVOS POR CONFIRMAR

   Facturación tiene que enterarse en el momento de que entró una venta.
   Se pregunta cada pocos segundos en vez de mantener una conexión abierta
   (SSE o websockets): en un hosting compartido cada conexión abierta ocupa
   un proceso de PHP, y con varias personas a la vez el sitio se cae para
   todos. Una consulta que cuenta y devuelve un id es barata.

   Y NO se recarga la página sola. Facturación está escribiendo números de
   operación: una recarga automática le borraría lo tecleado a media palabra.
   Se avisa, y quien decide cuándo mirar es ella.
   ══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var cuerpo = document.body;
  if (!cuerpo || cuerpo.dataset.avisaPagos !== '1') return;

  var raiz    = cuerpo.dataset.raiz || '/';
  /* LA MARCA SOBREVIVE AL CAMBIO DE PÁGINA (3b.5). Cada página trae la marca
     de «ahora»: si un reclamo o un pago entraba justo antes de cambiar de
     pantalla, la página nueva ya lo daba por visto y no sonaba nunca. Se
     sigue desde lo último que se preguntó de verdad en esta pestaña; si la
     guardada es mayor que la de la página (se vació la base), manda la página. */
  /* Solo vale la marca de la MISMA persona y de hace poco (2 min): después de
     salir y volver a entrar, o con otra cuenta en la misma pestaña, se
     empieza desde la página, sin una ráfaga de avisos viejos. */
  var yoId = cuerpo.dataset.usuario || '';
  function marca(clave, dePagina) {
    var g = 0;
    try {
      var x = JSON.parse(window.sessionStorage.getItem(clave) || 'null');
      if (x && x.u === yoId && (Date.now() - x.t) < 120000) g = parseInt(x.v, 10) || 0;
    } catch (e) { g = 0; }
    return (g > 0 && g <= dePagina) ? g : dePagina;
  }
  function recordar(clave, v) {
    try { window.sessionStorage.setItem(clave, JSON.stringify({u: yoId, v: v, t: Date.now()})); } catch (e) {}
  }
  var ultimoPagina  = parseInt(cuerpo.dataset.pagosUltimo || '0', 10) || 0;
  var rUltimoPagina = parseInt(cuerpo.dataset.reclamosUltimo || '0', 10) || 0;
  var ultimo  = marca('waka-pagos-ultimo', ultimoPagina);
  var rUltimo = marca('waka-reclamos-ultimo', rUltimoPagina);
  var atrasado = ultimo < ultimoPagina || rUltimo < rUltimoPagina;
  var rBarra  = null;
  var rAcum   = 0;
  var tituloOriginal = document.title;
  var barra   = null;
  var fallos  = 0;
  var acumulados = 0;      // cuántos han entrado desde que se enseñó el aviso

  function pintarTitulo(n) {
    document.title = n > 0 ? '(' + n + ') ' + tituloOriginal : tituloOriginal;
  }
  pintarTitulo(parseInt(cuerpo.dataset.pagosN || '0', 10) || 0);

  /* Un pitido corto hecho con el propio navegador: sin archivo que descargar
     y sin depender de ningún servidor de fuera. El navegador lo bloquea hasta
     que la persona haya tocado algo en la página; a partir de ahí suena. */
  function pitar() { window.wakaSonido('pago'); }

  function texto(n) {
    return n === 1 ? 'Entró un pago por confirmar'
                   : 'Entraron ' + n + ' pagos por confirmar';
  }

  function avisar(n) {
    if (barra) { barra.querySelector('strong').textContent = texto(n); return; }
    barra = document.createElement('div');
    barra.className = 'aviso-vivo';
    barra.setAttribute('role', 'status');

    var t = document.createElement('strong');
    t.textContent = texto(n);

    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = 'Verlos';
    b.addEventListener('click', function () {
      // Recarga solo cuando ella lo pide: nunca por debajo de sus manos.
      window.location.href = raiz + 'pagos/por-validar';
    });

    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'aviso-vivo__x';
    x.setAttribute('aria-label', 'Cerrar el aviso');
    x.textContent = '×';
    x.addEventListener('click', function () {
      barra.remove(); barra = null; acumulados = 0;
    });

    barra.appendChild(t); barra.appendChild(b); barra.appendChild(x);
    (document.getElementById('avisos-pila') || document.body).appendChild(barra);
    pitar();
  }

  /* EL RECLAMO (3b.5): el asesor espera una respuesta. Barra propia, roja,
     con su propio sonido: no es «entró un pago», es «alguien está esperando». */
  function avisarReclamo(n, codigo, asesor) {
    var txt = n === 1
      ? (asesor ? asesor + ' reclama ' : 'Reclaman ') + (codigo || 'una venta')
      : n + ' reclamos de pago esperando';
    if (rBarra) { rBarra.querySelector('strong').textContent = txt; window.wakaSonido('reclamo'); return; }
    rBarra = document.createElement('div');
    rBarra.className = 'aviso-vivo aviso-vivo--reclamo';
    rBarra.setAttribute('role', 'alert');
    var t = document.createElement('strong');
    t.textContent = txt;
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = 'Verlo';
    b.addEventListener('click', function () { window.location.href = raiz + 'pagos/por-validar'; });
    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'aviso-vivo__x';
    x.setAttribute('aria-label', 'Cerrar el aviso');
    x.textContent = '×';
    x.addEventListener('click', function () { rBarra.remove(); rBarra = null; rAcum = 0; });
    rBarra.appendChild(t); rBarra.appendChild(b); rBarra.appendChild(x);
    (document.getElementById('avisos-pila') || document.body).appendChild(rBarra);
    window.wakaSonido('reclamo');
  }

  /* EL DESCUENTO QUE PASA DEL TOPE (3d): un asesor espera la respuesta
     para decírsela al cliente. Barra propia, con el sonido del reclamo. */
  var dBarra = null, dAcum = 0;
  function avisarDescuento(n, codigo, asesor) {
    var txt = n === 1
      ? (asesor ? asesor + ' pide un descuento en ' : 'Piden un descuento en ') + (codigo || 'una venta')
      : n + ' descuentos esperando tu respuesta';
    if (dBarra) { dBarra.querySelector('strong').textContent = txt; window.wakaSonido('reclamo'); return; }
    dBarra = document.createElement('div');
    dBarra.className = 'aviso-vivo aviso-vivo--reclamo';
    dBarra.setAttribute('role', 'alert');
    var t = document.createElement('strong');
    t.textContent = txt;
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = 'Verlo';
    b.addEventListener('click', function () { window.location.href = raiz + 'pedidos/descuentos'; });
    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'aviso-vivo__x';
    x.setAttribute('aria-label', 'Cerrar el aviso');
    x.textContent = '×';
    x.addEventListener('click', function () { dBarra.remove(); dBarra = null; dAcum = 0; });
    dBarra.appendChild(t); dBarra.appendChild(b); dBarra.appendChild(x);
    (document.getElementById('avisos-pila') || document.body).appendChild(dBarra);
    window.wakaSonido('reclamo');
  }

  /* LOS PAGOS SIGUEN SONANDO CADA 3 MINUTOS (usuario, 2026-09-29: «los
     avisos a facturación son importantes»). Mientras quede alguno sin
     revisar, cada 3 minutos vuelve a sonar y a decir cuántos hay. La hora del
     último aviso se comparte entre pestañas: con dos abiertas no suena doble. */
  var RECORDAR = 180000;
  function ultimoRecuerdo() {
    try { return parseInt(window.localStorage.getItem('waka-pagos-recuerdo') || '0', 10) || 0; } catch (e) { return 0; }
  }
  function marcarRecuerdo() {
    try { window.localStorage.setItem('waka-pagos-recuerdo', String(Date.now())); } catch (e) {}
  }
  if (!ultimoRecuerdo()) marcarRecuerdo();
  var barraRec = null;
  function recordarPendientes(n) {
    var txt = n === 1 ? 'Tienes 1 pago por confirmar' : 'Tienes ' + n + ' pagos por confirmar';
    if (barraRec) barraRec.remove();
    barraRec = document.createElement('div');
    barraRec.className = 'aviso-vivo';
    barraRec.setAttribute('role', 'status');
    var t = document.createElement('strong'); t.textContent = txt;
    var b = document.createElement('button'); b.type = 'button'; b.textContent = 'Verlos';
    b.addEventListener('click', function () { window.location.href = raiz + 'pagos/por-validar'; });
    var x = document.createElement('button'); x.type = 'button'; x.className = 'aviso-vivo__x';
    x.setAttribute('aria-label', 'Cerrar el aviso'); x.textContent = '×';
    var esta = barraRec;
    x.addEventListener('click', function () { esta.remove(); if (barraRec === esta) barraRec = null; });
    esta.appendChild(t); esta.appendChild(b); esta.appendChild(x);
    (document.getElementById('avisos-pila') || document.body).appendChild(esta);
    pitar();
    if (window.wakaSistema) window.wakaSistema({titulo: txt, texto: 'Facturación: hay dinero esperando tu confirmación.', url: raiz + 'pagos/por-validar', tipo: 'pago'});
    marcarRecuerdo();
  }

  function preguntar() {
    // Se manda el último id visto: así el servidor puede decir cuántos ENTRARON,
    // que no es lo mismo que cuántos hay. Con siete pendientes de ayer y uno
    // nuevo hoy, el aviso tiene que decir "entró un pago", no "entraron ocho".
    fetch(raiz + 'pagos/nuevos?desde=' + encodeURIComponent(ultimo) + '&rdesde=' + encodeURIComponent(rUltimo),
          {headers: {'Accept': 'application/json'}})
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || !j.ok) return;
        fallos = 0;
        pintarTitulo(j.n);
        /* La marca del reclamo se guarda SIEMPRE que suba, se avise o no:
           si no, el mismo reclamo volvería a sonar en cada sondeo. */
        if (typeof j.reclamo_ultimo === 'number' && j.reclamo_ultimo > rUltimo) {
          rUltimo = j.reclamo_ultimo;
          if (j.reclamos > 0) { rAcum += j.reclamos; avisarReclamo(rAcum, j.reclamo_codigo, j.reclamo_asesor); }
        }
        /* Los descuentos que pasan del tope (3d): la misma marca de la
           bitácora que el reclamo, pero con su propia condición, para que no
           dependan de que el reclamo suba. */
        if (j.descuentos > 0) { dAcum += j.descuentos; avisarDescuento(dAcum, j.descuento_codigo, j.descuento_asesor); }
        if (typeof j.descuento_ultimo === 'number' && j.descuento_ultimo > rUltimo) rUltimo = j.descuento_ultimo;
        recordar('waka-pagos-ultimo', Math.max(ultimo, j.ultimo || 0));
        recordar('waka-reclamos-ultimo', Math.max(rUltimo, j.reclamo_ultimo || 0));
        if (j.ultimo > ultimo) {
          /* La marca de agua sube con CUALQUIER pago nuevo, también con la
             fila negativa de una devolución. Quien decide si hay algo que
             avisar es j.nuevos, y solo él: aquí hubo un plan B que sumaba 1
             cuando j.nuevos era 0, y con la marca de agua nueva ese plan B
             gritaba «entró un pago por confirmar» justo cuando alguien acababa
             de devolver uno — y al pulsar «Verlos» había uno MENOS.
             La marca se guarda igual aunque no se avise, o el mismo movimiento
             volvería a dispararse en cada sondeo. */
          ultimo = j.ultimo;
          if (j.nuevos > 0) {
            acumulados += j.nuevos;
            avisar(acumulados);
            marcarRecuerdo();
            if (window.wakaSistema) window.wakaSistema({titulo: texto(acumulados), texto: '', url: raiz + 'pagos/por-validar', tipo: 'pago'});
          }
        }
        /* El recordatorio: quedan sin revisar y hace 3 minutos que no suena nada. */
        if (j.n > 0 && Date.now() - ultimoRecuerdo() >= RECORDAR) recordarPendientes(j.n);
        if (j.n <= 0 && barraRec) { barraRec.remove(); barraRec = null; }
        if (j.notifs && j.notifs.length && window.wakaNotifs) window.wakaNotifs(j.notifs);
      })
      .catch(function () {
        // Si se cae la señal no se llena la consola ni se machaca al servidor:
        // se espera más entre intentos y se sigue solo cuando vuelva.
        fallos++;
      });
  }

  var CADA = 10000;
  setInterval(function () {
    if (document.visibilityState === 'hidden' && fallos > 0) return;
    if (fallos > 5 && (fallos % 6) !== 0) { fallos++; return; }
    preguntar();
  }, CADA);
  // Si quedó algo por preguntar de la página anterior, se pregunta ya.
  if (atrasado) setTimeout(preguntar, 1500);

  // Al volver a la pestaña, se mira de inmediato en vez de esperar el turno.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') preguntar();
  });
})();

/* ══════════════════════════════════════════════════════════════════════
   EL OTRO LADO DEL MISMO TRÁMITE: «ya puedes mandarlo a despacho»

   Facturación se entera de que entró un pago; el asesor tiene que enterarse
   de que se lo confirmaron. Si no, el HUB le frena el envío una vez y no le
   dice nunca que quedó libre: la venta se queda en el aire.

   Mismo patrón que el aviso de pagos y con las mismas dos lecciones —la marca
   de agua se guarda siempre, y quien decide si hay algo que avisar es
   `nuevos`— pero MÁS LENTO a propósito: un pago hay que mirarlo en el momento
   y un despacho puede esperar medio minuto. Y nadie lleva los dos sondeos a la
   vez, así que cada persona hace una petición, no dos.
   ══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var cuerpo = document.body;
  if (!cuerpo || cuerpo.dataset.avisaDespacho !== '1') return;

  var raiz   = cuerpo.dataset.raiz || '/';
  var ultimo = parseInt(cuerpo.dataset.despachoUltimo || '0', 10) || 0;
  var barra  = null;
  var fallos = 0;
  var acumulados = 0;

  /* Los pagos trabados viajan en esta misma respuesta. Su aviso es OTRO: dice
     lo contrario que el del despacho —uno desbloquea y el otro frena— y
     mezclarlos en una sola barra haría que el asesor leyera «listo» cuando en
     realidad tiene algo parado. */
  /* Los trabados se cuentan, no se marcan por id. Con marca de agua el PRIMER
     pago trabado no avisaba nunca —la marca arranca en 0 y el servidor calla
     la primera vuelta— y uno con id menor que otro ya trabado tampoco, porque
     el máximo no subía. Facturación revisa su cola en el orden que quiere, así
     que el desorden es la norma. Aquí el total es pequeño y siempre es tarea
     pendiente: si sube, hay noticia. */
  var previoTrab = parseInt(cuerpo.dataset.trabadosN || '0', 10) || 0;
  var barraTrab  = null;

  /* La respuesta de Administración a sus descuentos (3d). LA MARCA SOBREVIVE
     AL CAMBIO DE PÁGINA, como la del sondeo de pagos: si la respuesta llegaba
     entre dos vueltas y el asesor cambiaba de pantalla, la página nueva ya la
     daba por vista y no avisaba nunca. Solo vale la de la misma persona y de
     hace menos de 2 minutos. */
  var yoIdD = cuerpo.dataset.usuario || '';
  var dUltimoPagina = parseInt(cuerpo.dataset.descUltimo || '0', 10) || 0;
  var dUltimo = dUltimoPagina;
  try {
    var dg = JSON.parse(window.sessionStorage.getItem('waka-desc-ultimo') || 'null');
    if (dg && dg.u === yoIdD && (Date.now() - dg.t) < 120000) {
      var dv = parseInt(dg.v, 10) || 0;
      if (dv > 0 && dv <= dUltimoPagina) dUltimo = dv;
    }
  } catch (e) { dUltimo = dUltimoPagina; }
  function recordarDesc(v) {
    try { window.sessionStorage.setItem('waka-desc-ultimo', JSON.stringify({u: yoIdD, v: v, t: Date.now()})); } catch (e) {}
  }
  var barraDesc = null;
  function avisarDesc(ok, codigo, id, n) {
    var txt = n > 1 ? 'Respondieron ' + n + ' de tus descuentos'
      : (ok ? 'Aprobaron el descuento de ' : 'No aprobaron el descuento de ') + (codigo || 'tu venta');
    if (barraDesc) barraDesc.remove();
    barraDesc = document.createElement('div');
    barraDesc.className = 'aviso-vivo' + (ok && n === 1 ? '' : ' aviso-vivo--alto');
    barraDesc.setAttribute('role', 'status');
    var t = document.createElement('strong');
    t.textContent = txt;
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = 'Ver la venta';
    b.addEventListener('click', function () {
      window.location.href = raiz + (id ? 'pedidos/ficha?id=' + id : 'pedidos');
    });
    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'aviso-vivo__x';
    x.setAttribute('aria-label', 'Cerrar el aviso');
    x.textContent = '×';
    x.addEventListener('click', function () { barraDesc.remove(); barraDesc = null; });
    barraDesc.appendChild(t); barraDesc.appendChild(b); barraDesc.appendChild(x);
    pila().appendChild(barraDesc);
    window.wakaSonido('despacho');
  }

  /* -1 = todavía sin marca: la primera vuelta solo la toma. */
  var rUltimo = parseInt(cuerpo.dataset.rachaUltimo || '-1', 10);
  if (isNaN(rUltimo)) rUltimo = -1;
  var barraRacha = null;
  function avisarRacha(txt) {
    if (barraRacha) barraRacha.remove();
    barraRacha = document.createElement('div');
    barraRacha.className = 'aviso-vivo aviso-vivo--racha';
    barraRacha.setAttribute('role', 'status');
    var t = document.createElement('strong');
    t.textContent = '⚡ ' + txt;
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = 'Ver rachas';
    b.addEventListener('click', function () { window.location.href = raiz + 'bonos/rachas'; });
    var x = document.createElement('button');
    x.type = 'button'; x.className = 'aviso-vivo__x'; x.setAttribute('aria-label', 'Cerrar el aviso'); x.textContent = '×';
    var esta = barraRacha;   // cada aviso se va con SU reloj, no con el del anterior
    x.addEventListener('click', function () { esta.remove(); if (barraRacha === esta) barraRacha = null; });
    esta.appendChild(t); esta.appendChild(b); esta.appendChild(x);
    pila().appendChild(esta);
    setTimeout(function () { esta.remove(); if (barraRacha === esta) barraRacha = null; }, 12000);
  }

  /* Los dos avisos van fijos en la misma esquina, así que si salen a la vez se
     tapan. Se apilan. */
  function pila() {
    var p = document.getElementById('avisos-pila');
    if (!p) {
      p = document.createElement('div');
      p.id = 'avisos-pila';
      p.className = 'avisos-pila';
      document.body.appendChild(p);
    }
    return p;
  }

  function textoTrab(n) {
    return n === 1
      ? 'Facturación paró 1 de tus pagos'
      : 'Facturación paró ' + n + ' de tus pagos';
  }

  function quitarTrab() {
    if (!barraTrab) return;
    barraTrab.remove(); barraTrab = null;
  }

  function avisarTrab(n) {
    if (barraTrab) { barraTrab.querySelector('strong').textContent = textoTrab(n); return; }
    barraTrab = document.createElement('div');
    barraTrab.className = 'aviso-vivo aviso-vivo--alto';
    barraTrab.setAttribute('role', 'status');

    var t = document.createElement('strong');
    t.textContent = textoTrab(n);

    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = 'Ver por qué';
    b.addEventListener('click', function () { window.location.href = raiz + 'inicio'; });

    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'aviso-vivo__x';
    x.setAttribute('aria-label', 'Cerrar el aviso');
    x.textContent = '×';
    x.addEventListener('click', quitarTrab);

    barraTrab.appendChild(t); barraTrab.appendChild(b); barraTrab.appendChild(x);
    pila().appendChild(barraTrab);
  }

  function texto(n) {
    return n === 1
      ? 'Pago confirmado · 1 venta lista para despacho'
      : 'Pago confirmado · ' + n + ' ventas listas para despacho';
  }

  function avisar(n) {
    if (barra) { barra.querySelector('strong').textContent = texto(n); return; }
    barra = document.createElement('div');
    barra.className = 'aviso-vivo';
    barra.setAttribute('role', 'status');

    var t = document.createElement('strong');
    t.textContent = texto(n);

    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = 'Mandarlas';
    b.addEventListener('click', function () {
      window.location.href = raiz + 'pedidos/por-despachar';
    });

    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'aviso-vivo__x';
    x.setAttribute('aria-label', 'Cerrar el aviso');
    x.textContent = '×';
    x.addEventListener('click', function () {
      barra.remove(); barra = null; acumulados = 0;
    });

    barra.appendChild(t); barra.appendChild(b); barra.appendChild(x);
    pila().appendChild(barra);
    window.wakaSonido('despacho');
  }

  function preguntar() {
    fetch(raiz + 'pedidos/nuevos-despacho?desde=' + encodeURIComponent(ultimo)
          + '&ddesde=' + encodeURIComponent(dUltimo) + '&rdesde=' + encodeURIComponent(rUltimo),
          {headers: {'Accept': 'application/json'}})
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || !j.ok) return;
        fallos = 0;
        /* Aquí había dos llamadas a recordar() y a rUltimo, que son del sondeo
           de PAGOS y no existen en este: lanzaban un error dentro de la
           promesa, el .catch lo contaba como fallo de red y este aviso —el del
           despacho y el de los pagos parados— no salía NUNCA (auditoría 3d). */
        if (typeof j.desc_ultimo === 'number' && j.desc_ultimo > dUltimo) {
          dUltimo = j.desc_ultimo;
          if (j.desc_n > 0) avisarDesc(j.desc_ok, j.desc_codigo, j.desc_id, j.desc_n);
        }
        recordarDesc(dUltimo);
        /* LAS RACHAS DEL EQUIPO (5a): «Viviana está en racha x4». Sin sonido. */
        if (typeof j.racha_ultimo === 'number' && j.racha_ultimo > rUltimo) {
          rUltimo = j.racha_ultimo;
          if (j.rachas && j.rachas.length) avisarRacha(j.rachas[0].texto);
        }
        /* 5b: el «Pago confirmado» nuevo dice de qué venta es. Si llegó en
           esta vuelta, la barra de antes («1 venta lista para despacho»)
           no sale además: sería el mismo aviso dos veces. */
        var notifs = j.notifs || [];
        var hayPagoOk = notifs.some(function (n) { return n.tipo === 'pago_ok'; });
        if (j.ultimo > ultimo) {
          // La marca sube siempre; avisar solo si de verdad entró algo nuevo.
          ultimo = j.ultimo;
          if (j.nuevos > 0 && !hayPagoOk) { acumulados += j.nuevos; avisar(acumulados); }
        }
        if (notifs.length && window.wakaNotifs) window.wakaNotifs(notifs);
        /* El aviso enseña CUÁNTOS hay ahora, no un acumulado: acumular dejaba
           la barra diciendo «paró 1» después de que el asesor lo arreglara, y
           al entrar el siguiente decía «paró 2» habiendo uno. Y cuando no
           queda ninguno, la barra se va sola: un aviso que sobrevive a su
           motivo enseña a no leer los avisos. */
        var nTrab = parseInt(j.trabados || 0, 10) || 0;
        if (nTrab <= 0)              quitarTrab();
        else if (nTrab > previoTrab) avisarTrab(nTrab);
        else if (barraTrab)          avisarTrab(nTrab);
        previoTrab = nTrab;
      })
      .catch(function () { fallos++; });
  }

  /* 15 s (5b): el pago confirmado le tiene que llegar al asesor «inmediato». */
  var CADA = 15000;
  setInterval(function () {
    if (document.visibilityState === 'hidden' && fallos > 0) return;
    if (fallos > 5 && (fallos % 6) !== 0) { fallos++; return; }
    preguntar();
  }, CADA);
  /* Sin pregunta extra al cargar: casi cualquier cosa sube la marca de la
     página, así que sería una petición más en CADA pantalla. La vuelta normal
     ya pregunta desde la marca guardada y trae lo que se quedó por avisar. */

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') preguntar();
  });
})();

/* ══════════════════════════════════════════════════════════════════════
   LAS FOTOS QUE FALTAN (3b.5)

   El botón trae 40 y la pantalla vuelve a pedir otras 40 sola, hasta que no
   quede ninguna, diciendo cuántas van. Se para si una vuelta no trae nada
   (la tienda no las da ahora): así no se queda dando vueltas para siempre.
   Sin JavaScript, el botón trae una tanda y la página vuelve.
   ══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var form = document.querySelector('form[data-fotos-auto="1"]');
  if (!form || !window.fetch || !window.FormData) return;
  var boton = form.querySelector('button[type="submit"]');
  var prog  = document.getElementById('fotos-progreso');
  var total = 0, malas = 0;

  function decir(t) { if (prog) prog.textContent = t; }

  function vuelta() {
    fetch(form.action || window.location.href, {
      method: 'POST', body: new FormData(form), credentials: 'same-origin',
      headers: {'Accept': 'application/json'}
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || !j.ok) {
          decir((j && j.error) || 'Se cortó. Vuelve a pulsar para seguir.');
          boton.disabled = false; return;
        }
        total += j.traidas; malas += j.malas;
        var hecho = total + (total === 1 ? ' foto traída' : ' fotos traídas')
                  + (malas ? ' · ' + malas + ' no ' + (malas === 1 ? 'sirve' : 'sirven') : '');
        if (j.quedan > 0 && (j.traidas + j.malas) > 0) {
          decir(hecho + ' · faltan ' + j.quedan + '…');
          vuelta();
        } else if (j.quedan > 0) {
          decir(hecho + ' · ' + j.quedan + ' no se pudieron traer ahora. Prueba más tarde.');
          boton.disabled = false;
        } else {
          decir(hecho + '. ¡Listo!');
          boton.textContent = 'LISTO';
        }
      })
      .catch(function () { decir('Se cortó la conexión. Vuelve a pulsar para seguir.'); boton.disabled = false; });
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (boton.disabled) return;
    boton.disabled = true;
    decir('Trayendo fotos…');
    vuelta();
  });
})();

/* ══════════════════════════════════════════════════════════════════════
   EL MENÚ «···» DE UNA FILA, SIEMPRE A LA VISTA (usuario, 2026-09-25)

   La tabla va dentro de una caja que recorta lo que se sale (para deslizarse
   de lado si no cabe), y el menú de la fila se abría DENTRO de esa caja: con
   una o dos filas quedaba recortado y «no salía nada». Al abrirse, el menú
   se pinta fijo en la pantalla, junto a su botón, y hacia arriba si abajo no
   cabe. Al deslizar la página, lo sigue.
   ══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  function colocar(d) {
    var caja = d.querySelector('.menu-chip__caja');
    var boton = d.querySelector('summary');
    if (!caja || !boton) return;
    var r = boton.getBoundingClientRect();
    caja.style.position = 'fixed';
    caja.style.maxWidth = (window.innerWidth - 16) + 'px';
    caja.style.marginTop = '0';
    caja.style.left = 'auto';
    caja.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
    caja.style.top = (r.bottom + 6) + 'px';
    caja.style.maxHeight = '';
    caja.style.overflowY = '';
    var alto = caja.offsetHeight;
    /* La barra de abajo del celular también tapa: se mide lo que queda libre. */
    var barra = document.querySelector('nav.barra');
    /* La barra va fija: su offsetParent es null aunque se vea. Se pregunta
       si se pinta de verdad. */
    var tapa = barra && window.getComputedStyle(barra).display !== 'none' ? barra.getBoundingClientRect().height : 0;
    var libreAbajo = window.innerHeight - tapa;
    var abajo = libreAbajo - (r.bottom + 6) - 8, arriba = r.top - 6 - 8;
    if (alto > abajo && arriba > abajo) {
      /* Arriba hay más sitio: se abre hacia arriba, y si tampoco cabe entero,
         con su propia barra para deslizar. */
      var h = Math.min(alto, arriba);
      if (h < alto) { caja.style.maxHeight = h + 'px'; caja.style.overflowY = 'auto'; }
      caja.style.top = (r.top - 6 - h) + 'px';
    } else if (alto > abajo) {
      caja.style.maxHeight = Math.max(120, abajo) + 'px'; caja.style.overflowY = 'auto';
    }
    var izq = caja.getBoundingClientRect().left;
    if (izq < 8) { caja.style.right = 'auto'; caja.style.left = '8px'; }
  }
  function soltar(d) {
    var caja = d.querySelector('.menu-chip__caja');
    if (caja) {
      ['position', 'top', 'right', 'left', 'marginTop', 'maxHeight', 'overflowY', 'maxWidth'].forEach(function (k) { caja.style[k] = ''; });
    }
  }
  document.addEventListener('toggle', function (ev) {
    var d = ev.target;
    if (!(d instanceof HTMLDetailsElement) || !d.classList.contains('menu-fila')) return;
    if (d.open) {
      /* Uno abierto a la vez. */
      document.querySelectorAll('details.menu-fila[open]').forEach(function (o) { if (o !== d) o.open = false; });
      colocar(d);
    } else {
      soltar(d);
    }
  }, true);
  function cerrarTodos() {
    document.querySelectorAll('details.menu-fila[open]').forEach(function (o) { o.open = false; });
  }
  /* Al deslizar, el menú sigue a su botón (en el celular la barra del
     navegador aparece y desaparece al deslizar). */
  function recolocar() { document.querySelectorAll('details.menu-fila[open]').forEach(colocar); }
  window.addEventListener('resize', recolocar);
  window.addEventListener('scroll', recolocar, true);
  /* pointerdown y no click: en el iPhone, tocar una zona sin nada pulsable
     no llega como click. Y Escape cierra, para quien usa el teclado. */
  document.addEventListener('pointerdown', function (ev) {
    if (!ev.target.closest('details.menu-fila')) cerrarTodos();
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') cerrarTodos();
  });
  window.addEventListener('pageshow', cerrarTodos);
})();

/* ══════════════════════════════════════════════════════════════════════
   LAS FOTOS DEL PAGO (3f): el voucher y la del DNI.

   Cada foto tiene dos botones —TOMAR FOTO (la cámara) y ELEGIR ARCHIVO—, que
   son dos campos. Al elegir en uno se vacía el otro: el servidor tomaría el
   primero que viniera, y el asesor vería una cosa y se guardaría otra.

   Y la del DNI sale solo con los métodos que la piden (data-dni en cada
   opción del método; se marca en Configuración › Métodos de pago). El
   servidor la exige igual.
   ══════════════════════════════════════════════════════════════════════ */
(function () {
  document.addEventListener('change', function (ev) {
    var inp = ev.target;
    if (inp.classList && inp.classList.contains('foto-campo__in')) {
      var caja = inp.closest('.foto-campo');
      if (!caja) return;
      caja.querySelectorAll('.foto-campo__in').forEach(function (o) { if (o !== inp) o.value = ''; });
      var n = caja.querySelector('.foto-campo__n');
      var f = inp.files && inp.files[0];
      if (n) n.textContent = f ? 'Lista: ' + f.name : '';
      caja.classList.toggle('foto-campo--lista', !!f);
      if (f) achicar(inp, f);
      return;
    }
    if (inp.matches && inp.matches('select[data-metodo-fotos]')) mostrarDni(inp);
  });
  /* UNA FOTO DE LA CÁMARA PESA 3 A 8 MB, y el pago lleva dos (auditoría del
     3f): juntas pasaban de lo que acepta el servidor y el formulario entero se
     perdía. Se achica aquí, en el celular, a 1600 px (el servidor la deja en
     1400 igual): pesa unas diez veces menos y llega derecha —el navegador
     aplica el giro de la cámara al dibujarla—. Si el navegador no puede, se
     manda tal cual. */
  var ACHICAR_DESDE = 1200 * 1024, LADO = 1600;
  function achicar(inp, f) {
    if (!/^image\/(jpeg|png)$/.test(f.type) || f.size < ACHICAR_DESDE) return;
    if (!window.DataTransfer || !window.URL || !HTMLCanvasElement.prototype.toBlob) return;
    var img = new Image(), url = URL.createObjectURL(f);
    img.onload = function () {
      URL.revokeObjectURL(url);
      var k = Math.min(1, LADO / Math.max(img.naturalWidth, img.naturalHeight));
      var c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(img.naturalWidth * k));
      c.height = Math.max(1, Math.round(img.naturalHeight * k));
      var g = c.getContext('2d');
      g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
      g.drawImage(img, 0, 0, c.width, c.height);
      c.toBlob(function (b) {
        if (!b || b.size >= f.size) return;
        try {
          var dt = new DataTransfer();
          dt.items.add(new File([b], f.name.replace(/\.[^.]+$/, '') + '.jpg', {type: 'image/jpeg'}));
          inp.files = dt.files;
        } catch (e) {}
      }, 'image/jpeg', 0.85);
    };
    img.onerror = function () { URL.revokeObjectURL(url); };
    img.src = url;
  }
  function mostrarDni(sel) {
    var form = sel.form || document;
    var caja = form.querySelector('[data-foto-campo="foto_dni"]');
    if (!caja) return;
    var o = sel.options[sel.selectedIndex];
    caja.hidden = !(o && o.getAttribute('data-dni') === '1');
  }
  /* Al volver con el formulario lleno (un error), se pinta según lo elegido. */
  document.querySelectorAll('select[data-metodo-fotos]').forEach(mostrarDni);
})();

/* ══════════════════════════════════════════════════════════════════════
   LAS NOTIFICACIONES (5b)

   Lo que el HUB le dice a cada uno sin que lo pida: el pago confirmado, la
   pre venta nueva, el pedido por alistar, la Cacería, el aviso general.
     · Con la pantalla a la vista: una barra con su sonido; lo «emergente»
       (el aviso general, la pre venta nueva), en una ventana encima.
     · Con la pestaña escondida: además, la notificación del sistema.
     · Con el celular cerrado: el push (lo manda el servidor; aquí solo se
       activa, una vez por equipo).
   El sondeo es el que cada uno ya hacía; quien no tenía (Almacén, Dirección,
   Marketing) pregunta a /avisos/nuevos.
   ══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var cuerpo = document.body;
  var raiz = (cuerpo && cuerpo.dataset.raiz) || '/';

  function pila() {
    var p = document.getElementById('avisos-pila');
    if (!p) {
      p = document.createElement('div');
      p.id = 'avisos-pila';
      p.className = 'avisos-pila';
      document.body.appendChild(p);
    }
    return p;
  }

  /* La notificación del SISTEMA, solo si la pestaña no se ve (con la
     pantalla delante ya está la barra) y la persona dio permiso. */
  window.wakaSistema = function (n) {
    try {
      if (document.visibilityState !== 'hidden') return;
      if (!('Notification' in window) || window.Notification.permission !== 'granted') return;
      var op = {body: n.texto || '', icon: raiz + 'assets/img/icono-192.png', badge: raiz + 'assets/img/icono-192.png',
                tag: 'waka-' + (n.id || n.tipo || 'aviso'), data: {url: n.url || raiz + 'inicio'}};
      if (n.imagen) op.image = n.imagen;
      if (navigator.serviceWorker && navigator.serviceWorker.getRegistration) {
        navigator.serviceWorker.getRegistration().then(function (reg) {
          if (reg && reg.showNotification) reg.showNotification(n.titulo, op);
          else new window.Notification(n.titulo, op);
        }).catch(function () {});
      } else {
        new window.Notification(n.titulo, op);
      }
    } catch (e) { /* sin notificación del sistema: la barra ya salió */ }
  };

  function barra(n) {
    var b = document.createElement('div');
    b.className = 'aviso-vivo aviso-vivo--notif' + (n.tipo === 'alistar' ? ' aviso-vivo--almacen' : '');
    b.setAttribute('role', 'status');
    var caja = document.createElement('span');
    caja.className = 'aviso-vivo__caja';
    var t = document.createElement('strong'); t.textContent = n.titulo;
    caja.appendChild(t);
    if (n.texto) { var s = document.createElement('span'); s.className = 'aviso-vivo__sub'; s.textContent = n.texto; caja.appendChild(s); }
    b.appendChild(caja);
    if (n.url) {
      var v = document.createElement('button'); v.type = 'button';
      v.textContent = n.tipo === 'pago_ok' && n.url.indexOf('por-despachar') >= 0 ? 'Despachar' : 'Ver';
      v.addEventListener('click', function () { window.location.href = n.url; });
      b.appendChild(v);
    }
    var x = document.createElement('button'); x.type = 'button'; x.className = 'aviso-vivo__x';
    x.setAttribute('aria-label', 'Cerrar el aviso'); x.textContent = '×';
    x.addEventListener('click', function () { b.remove(); });
    b.appendChild(x);
    pila().appendChild(b);
  }

  /* LA VENTANA: uno detrás de otro, con «Siguiente». */
  var cola = [], dlg = null;
  function ventana(lista) {
    cola = cola.concat(lista);
    if (dlg && dlg.open) return;
    siguiente();
  }
  function siguiente() {
    var n = cola.shift();
    if (!n) { if (dlg) { try { dlg.close(); } catch (e) {} } return; }
    if (!dlg) {
      dlg = document.createElement('dialog');
      dlg.className = 'emergente emergente--aviso';
      dlg.id = 'notif-ventana';
      dlg.setAttribute('aria-labelledby', 'notif-ventana-t');
      document.body.appendChild(dlg);
      dlg.addEventListener('cancel', function () { cola = []; });
    }
    dlg.innerHTML = '';
    if (n.imagen) {
      var im = document.createElement('img'); im.className = 'emergente__img'; im.alt = ''; im.src = n.imagen;
      dlg.appendChild(im);
    }
    var c = document.createElement('div'); c.className = 'emergente__cuerpo';
    var chip = document.createElement('span'); chip.className = 'emergente__chip';
    chip.textContent = n.tipo === 'preventa' ? 'Pre venta' : (n.tipo === 'caceria' ? 'Cacería del Día' : 'Aviso');
    c.appendChild(chip);
    var h = document.createElement('strong'); h.className = 'emergente__titulo'; h.id = 'notif-ventana-t'; h.textContent = n.titulo;
    c.appendChild(h);
    var p = document.createElement('p'); p.className = 'emergente__txt'; p.textContent = n.texto;
    c.appendChild(p);
    var ac = document.createElement('div'); ac.className = 'acciones';
    if (n.url) {
      var ver = document.createElement('a'); ver.className = 'btn btn--negro'; ver.href = n.url;
      ver.textContent = n.tipo === 'preventa' ? 'VER LA PRE VENTA' : 'VER';
      ac.appendChild(ver);
    }
    var ok = document.createElement('button'); ok.type = 'button'; ok.className = 'btn btn--amarillo';
    ok.textContent = cola.length ? 'SIGUIENTE' : 'ENTENDIDO';
    ok.addEventListener('click', siguiente);
    ac.appendChild(ok);
    c.appendChild(ac);
    dlg.appendChild(c);
    if (!dlg.open) { try { dlg.showModal(); } catch (e) { dlg.setAttribute('open', ''); } }
    try { ok.focus(); } catch (e) {}
  }

  /* Lo que trae cualquier sondeo pasa por aquí. */
  window.wakaNotifs = function (lista) {
    if (!lista || !lista.length) return;
    var emergentes = [], sono = false;
    lista.forEach(function (n) {
      if (n.emergente) emergentes.push(n); else barra(n);
      if (!sono && window.wakaSonido) { window.wakaSonido(n.sonido || 'pago'); sono = true; }
      window.wakaSistema(n);
    });
    if (emergentes.length) ventana(emergentes);
  };

  if (!cuerpo || cuerpo.dataset.notif !== '1') return;

  /* Lo que faltaba ver al entrar. */
  var alEntrar = document.getElementById('notif-al-entrar');
  if (alEntrar) {
    try { var l0 = JSON.parse(alEntrar.textContent || '[]'); if (l0.length) ventana(l0); } catch (e) {}
  }

  /* El sondeo de quien no tenía otro. */
  if (cuerpo.dataset.notifSondea === '1') {
    var fallos = 0;
    var preguntar = function () {
      fetch(raiz + 'avisos/nuevos', {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          if (!j || !j.ok) return;
          fallos = 0;
          window.wakaNotifs(j.notifs || []);
          /* El chip de «Por alistar» al día, sin recargar. */
          if (typeof j.alistar === 'number') {
            document.querySelectorAll('a[href$="pedidos/por-alistar"]').forEach(function (a) {
              var ch = a.querySelector('.nv__chip, .barra__chip');
              if (j.alistar > 0) {
                if (!ch) { ch = document.createElement('span'); ch.className = a.closest('.barra') ? 'barra__chip' : 'nv__chip'; a.appendChild(ch); }
                ch.textContent = String(j.alistar);
              } else if (ch) ch.remove();
            });
            var cab = document.getElementById('alistar-cuantos');
            if (cab) cab.textContent = String(j.alistar);
          }
        })
        .catch(function () { fallos++; });
    };
    setInterval(function () {
      if (document.visibilityState === 'hidden' && fallos > 0) return;
      if (fallos > 5 && (fallos % 6) !== 0) { fallos++; return; }
      preguntar();
    }, 20000);
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') preguntar();
    });
  }

  /* ── EL PUSH: activarlo en este equipo ─────────────────────────────── */
  var clave = cuerpo.dataset.pushClave || '';
  var yo = cuerpo.dataset.usuario || '';
  var puedePush = clave !== '' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

  function claveBytes(b64) {
    var s = (b64 + '===='.slice((b64.length % 4) || 4)).replace(/-/g, '+').replace(/_/g, '/');
    var raw = window.atob(s), out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }
  function guardarSuscripcion(sub) {
    var j = sub.toJSON ? sub.toJSON() : sub;
    var fd = new FormData();
    fd.append('_t', cuerpo.dataset.t || '');
    fd.append('endpoint', j.endpoint);
    fd.append('p256dh', (j.keys || {}).p256dh || '');
    fd.append('auth', (j.keys || {}).auth || '');
    return fetch(raiz + 'avisos/suscribir', {method: 'POST', body: fd, credentials: 'same-origin'})
      .then(function (r) {
        if (r.ok) { try { window.localStorage.setItem('waka-push-' + yo, j.endpoint); } catch (e) {} }
        return r.ok;
      });
  }
  function suscribir() {
    return navigator.serviceWorker.ready.then(function (reg) {
      return reg.pushManager.getSubscription().then(function (sub) {
        return sub || reg.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: claveBytes(clave)});
      });
    }).then(guardarSuscripcion);
  }
  window.wakaPushActivar = function () {
    if (!puedePush) return Promise.resolve(false);
    return window.Notification.requestPermission().then(function (p) {
      if (p !== 'granted') return false;
      return suscribir();
    }).catch(function () { return false; });
  };
  window.wakaPushEstado = function () {
    if (!puedePush) return 'no';
    return window.Notification.permission;   // default · granted · denied
  };

  if (puedePush) {
    /* Con permiso ya dado: se asegura la suscripción de ESTA persona en ESTE
       equipo (otra cuenta en el mismo celular, o el navegador la renovó). */
    if (window.Notification.permission === 'granted') {
      navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); })
        .then(function (sub) {
          var guardada = '';
          try { guardada = window.localStorage.getItem('waka-push-' + yo) || ''; } catch (e) {}
          if (!sub) return suscribir();
          if (guardada !== sub.endpoint) return guardarSuscripcion(sub);
        }).catch(function () {});
    } else if (window.Notification.permission === 'default') {
      /* La franja que invita a activarlas. Se esconde 7 días con la X. */
      var visto = 0;
      try { visto = parseInt(window.localStorage.getItem('waka-push-no') || '0', 10) || 0; } catch (e) {}
      if (Date.now() - visto > 7 * 86400000 && !document.getElementById('push-activar')) {
        var f = document.createElement('div');
        f.className = 'push-franja'; f.id = 'push-franja';
        var tx = document.createElement('span');
        tx.innerHTML = '<strong>Activa las notificaciones</strong> para que los avisos te lleguen al celular aunque la app esté cerrada.';
        var bt = document.createElement('button'); bt.type = 'button'; bt.className = 'btn btn--amarillo btn--chico'; bt.textContent = 'ACTIVAR';
        bt.addEventListener('click', function () {
          window.wakaPushActivar().then(function (ok) {
            f.remove();
            try { window.localStorage.setItem('waka-push-no', String(Date.now())); } catch (e) {}
            if (ok && window.wakaSonido) window.wakaSonido('despacho');
          });
        });
        var no = document.createElement('button'); no.type = 'button'; no.className = 'aviso-vivo__x push-franja__x';
        no.setAttribute('aria-label', 'Ahora no'); no.textContent = '×';
        no.addEventListener('click', function () {
          f.remove();
          try { window.localStorage.setItem('waka-push-no', String(Date.now())); } catch (e) {}
        });
        f.appendChild(tx); f.appendChild(bt); f.appendChild(no);
        var cont = document.querySelector('.contenido');
        if (cont) cont.insertBefore(f, cont.firstChild);
      }
    }
  }

  /* El botón de Mi perfil. */
  var bp = document.getElementById('push-activar');
  if (bp) {
    var est = document.getElementById('push-estado');
    var pinta = function () {
      var e = window.wakaPushEstado();
      if (!est) return;
      est.textContent = e === 'granted' ? 'Activadas en este equipo.'
        : e === 'denied' ? 'Bloqueadas en este navegador: actívalas desde el candado de la barra de direcciones.'
        : e === 'no' ? 'Este navegador no las admite. En iPhone, primero añade Waka a la pantalla de inicio (Compartir › Añadir a inicio) y ábrelo desde ahí.'
        : 'Todavía no están activadas en este equipo.';
      bp.hidden = e === 'granted' || e === 'no' || e === 'denied';
    };
    pinta();
    bp.addEventListener('click', function () { window.wakaPushActivar().then(pinta); });
  }
})();

/* ══════════════════════════════════════════════════════════════════════
   EL INICIO DEL ASESOR, SIEMPRE AL DÍA (5b)

   «El contador de ventas no cambia en la pantalla principal» (usuario,
   2026-09-29). La pantalla se quedaba con las cifras de cuando se abrió: al
   volver con «atrás» el navegador la saca de su memoria, y la app instalada
   vuelve del fondo sin pedirla otra vez. El Inicio no tiene nada que escribir,
   así que recargarlo no borra nada: se recarga al volver y, si se queda a la
   vista, cada dos minutos.
   ══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (!document.getElementById('inicio-vivo')) return;
  var cargada = Date.now(), escondida = 0;
  function recargar() {
    if (document.querySelector('dialog[open]')) return;   // no se corta una ventana abierta (la pila, un logro)
    window.location.reload();
  }
  window.addEventListener('pageshow', function (ev) { if (ev.persisted) recargar(); });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') { escondida = Date.now(); return; }
    if (escondida && Date.now() - escondida > 15000) recargar();
  });
  setInterval(function () {
    if (document.visibilityState === 'visible' && Date.now() - cargada > 120000) recargar();
  }, 30000);
})();
