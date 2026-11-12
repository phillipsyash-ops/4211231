// Loyal app service worker: fast start (static files from cache) and an offline screen.
// Wallet pages and data always come from the server — balances are never shown from cache.
const VER = 'loyal-app-1';
const CORE = ['offline.html', 'assets/app/icon-192.png', 'assets/logo/coin-128.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VER).then((c) => c.addAll(CORE)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== VER).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  // pages: always from the network; offline screen if there is no connection
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('offline.html')));
    return;
  }
  // static files (css/js/images/fonts in /assets/): cache first, refresh in the background
  if (url.pathname.includes('/assets/') && !url.pathname.includes('/assets/video/')) {
    e.respondWith(caches.open(VER).then(async (c) => {
      const hit = await c.match(req);
      const net = fetch(req).then((r) => { if (r.ok) c.put(req, r.clone()); return r; }).catch(() => hit);
      return hit || net;
    }));
  }
});
