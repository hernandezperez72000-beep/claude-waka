/* HUB Waka — service worker.
   Cachea el esqueleto (CSS, JS, logo, íconos) para que la apertura sea
   inmediata después de la primera vez. Las páginas SIEMPRE se piden a la
   red: en un HUB de ventas mostrar una página vieja es peor que esperar. */
const CACHE = 'waka-hub-v4';

/* Solo se precachean archivos que NUNCA cambian de contenido bajo el mismo
   nombre. El CSS y el JS no van aquí: llegan con ?v=<fecha> pegado, así que
   cada versión nueva es una dirección nueva y no puede servirse la vieja.
   Antes estaban en esta lista y por eso, tras subir una versión, la pantalla
   salía a medio pintar hasta que alguien limpiaba la caché a mano. */
const ESQUELETO = [
  'assets/img/logo-waka.png',
  'assets/img/icono-192.png',
  'assets/manifest.webmanifest',
];

self.addEventListener('install', (ev) => {
  // Si algún archivo del esqueleto falla, la instalación no se cae entera.
  ev.waitUntil(
    caches.open(CACHE)
      .then((c) => Promise.all(ESQUELETO.map((u) => c.add(u).catch(() => null))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (ev) => {
  ev.waitUntil(
    caches.keys()
      .then((ks) => Promise.all(ks.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (ev) => {
  const req = ev.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== location.origin) return;

  // Las fotos de perfil NUNCA se guardan: son caras de personas, y el celular
  // de un asesor se comparte y se pierde. Solo se cachea el esqueleto del HUB.
  if (url.pathname.indexOf('/uploads/') !== -1) { ev.respondWith(fetch(req)); return; }

  // Estáticos: primero la caché, y se refresca por detrás.
  if (/\.(css|js|png|jpg|jpeg|svg|webmanifest|woff2?)$/.test(url.pathname)) {
    ev.respondWith(
      caches.match(req).then((hit) => {
        const red = fetch(req).then((res) => {
          if (res && res.ok) caches.open(CACHE).then((c) => c.put(req, res.clone()));
          return res;
        }).catch(() => hit);
        return hit || red;
      })
    );
    return;
  }

  // Las PÁGINAS no se guardan nunca. Llevan datos de la persona que entró
  // —cifras de ventas, y en el alta de usuarios hasta una contraseña temporal—
  // y el celular se comparte o se pierde. Sin señal se avisa, no se inventa.
  ev.respondWith(fetch(req));
});

/* Al salir, index.php pide desde /salir que se tire todo lo guardado. */
self.addEventListener('message', (ev) => {
  if (ev.data === 'olvidar-todo') {
    ev.waitUntil(caches.keys().then((ks) => Promise.all(ks.map((k) => caches.delete(k)))));
  }
});

/* ── 5b · EL PUSH: lo que llega con el HUB cerrado ──────────────────────
   El servidor manda {t: título, b: texto, u: ruta, i: imagen, g: tipo}. Las
   rutas vienen relativas al HUB: se completan con la carpeta de este service
   worker, así sirve igual en /hub/ que en la raíz. */
self.addEventListener('push', (ev) => {
  let d = {};
  try { d = ev.data ? ev.data.json() : {}; } catch (e) { d = { t: 'HUB Waka', b: ev.data ? ev.data.text() : '' }; }
  const base = self.registration.scope;
  const op = {
    body: d.b || '',
    icon: new URL('assets/img/icono-192.png', base).href,
    badge: new URL('assets/img/icono-192.png', base).href,
    tag: 'waka-' + (d.id || d.g || Date.now()),
    data: { url: new URL(d.u || 'inicio', base).href },
    vibrate: [180, 80, 180],
  };
  if (d.i) op.image = new URL(d.i, base).href;
  ev.waitUntil(self.registration.showNotification(d.t || 'HUB Waka', op));
});

/* Tocar la notificación abre (o trae al frente) el HUB en esa pantalla. */
self.addEventListener('notificationclick', (ev) => {
  ev.notification.close();
  const url = (ev.notification.data && ev.notification.data.url) || self.registration.scope;
  ev.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((ls) => {
      for (const c of ls) {
        if (c.url.indexOf(self.registration.scope) === 0 && 'focus' in c) {
          if ('navigate' in c) c.navigate(url).catch(() => null);
          return c.focus();
        }
      }
      return self.clients.openWindow(url);
    })
  );
});
