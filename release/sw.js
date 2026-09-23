const CACHE = 'tmr-static-v1';
const STATIC = [
  '/offline.html',
  '/assets/pwa/icon-192.png',
  '/assets/pwa/icon-512.png',
  '/assets/pwa/icon-maskable-512.png',
  '/assets/tmr-logo.svg',
  '/favicon.svg?v=2',
  '/assets/responsive.css?v=4'
];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(STATIC.filter(Boolean))).catch(() => {}));
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Nunca cacheia endpoints dinâmicos/autenticados.
  if (url.pathname.endsWith('.php') || url.pathname.startsWith('/api/')) {
    if (req.mode === 'navigate') {
      event.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
    }
    return;
  }

  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
    return;
  }

  // Cache-first apenas para recursos estáticos versionados.
  if (
    url.pathname.startsWith('/assets/') ||
    url.pathname === '/favicon.svg' ||
    url.pathname === '/manifest.webmanifest'
  ) {
    event.respondWith(
      caches.match(req).then(cached => cached || fetch(req).then(res => {
        if (res && res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then(cache => cache.put(req, copy));
        }
        return res;
      }))
    );
  }
});
