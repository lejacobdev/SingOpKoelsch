/* Sing op Kölsch — Service Worker (offline cache for the iOS app shell)
   Strategy: network-first for page navigations (fresh online, cached offline,
   offline.html as last resort), cache-first (stale-while-revalidate) for static
   assets. Only registered inside the app (UA gate), so browser users are unaffected. */
const CACHE = 'sok-v5';
// '/' (start page) + song list are always precached so the basics work offline.
const PRECACHE = ['/', '/lieder.php', '/offline.html', '/style.css', '/logo.png', '/apple-touch-icon.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE)
      .then((c) => c.addAll(PRECACHE.map((u) => new Request(u, { cache: 'reload' }))))
      .catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;                 // never cache POST/PUT/DELETE
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;  // only our own origin

  const accept = req.headers.get('accept') || '';
  const isPage = req.mode === 'navigate' || accept.includes('text/html');

  if (isPage) {
    // Network-first: fresh when online, cached copy when offline, else offline page.
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
          return res;
        })
        .catch(() =>
          caches.match(req).then((cached) => cached || caches.match('/offline.html'))
        )
    );
  } else {
    // Cache-first with background refresh for static assets.
    event.respondWith(
      caches.match(req).then((cached) => {
        const net = fetch(req)
          .then((res) => {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
            return res;
          })
          .catch(() => cached);
        return cached || net;
      })
    );
  }
});

// ── Web Push: show notification ──────────────────────────────────────
self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; }
  catch (e) { data = { body: event.data ? event.data.text() : '' }; }
  const title = data.title || 'Sing op Kölsch';
  event.waitUntil(self.registration.showNotification(title, {
    body: data.body || '',
    icon: '/icon-192.png',
    badge: '/icon-192.png',
    data: { url: data.url || '/' }
  }));
});

// ── Tap a notification → open / focus the right page ─────────────────
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const c of list) {
        if ('focus' in c) { c.navigate(url); return c.focus(); }
      }
      return clients.openWindow(url);
    })
  );
});

// ── Full offline: cache ALL songs (triggered only by the native app) ─────
let cachingAll = false;
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'cacheAll' && !cachingAll) {
    cachingAll = true;
    event.waitUntil(cacheAllSongs(event.source).finally(() => { cachingAll = false; }));
  }
});

async function cacheAllSongs(client) {
  try {
    const res = await fetch('/offline_manifest.php', { cache: 'no-store' });
    if (!res.ok) return;
    const data = await res.json();
    const urls = (data && data.urls) || [];
    const cache = await caches.open(CACHE);
    let done = 0;
    for (const u of urls) {
      try {
        const hit = await cache.match(u);
        if (!hit) {
          const r = await fetch(u, { credentials: 'same-origin' });
          if (r && r.ok) await cache.put(u, r.clone());
        }
      } catch (e) { /* skip a failed page, keep going */ }
      done++;
      if (client && done % 15 === 0) client.postMessage({ type: 'cacheProgress', done: done, total: urls.length });
    }
    if (client) client.postMessage({ type: 'cacheDone', total: urls.length });
  } catch (e) { /* offline or manifest error — try again next launch */ }
}
