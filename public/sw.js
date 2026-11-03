/* FoneFlex PWA service worker.
 * Security rule: never cache authenticated HTML, query-string routes, POSTs,
 * API responses, customer records, KYC images, statements or payment data.
 */
const VERSION = 'foneflex-pwa-v09';
const SHELL_CACHE = `${VERSION}-shell`;
const STATIC_CACHE = `${VERSION}-static`;
const SHELL = [
  './offline.html',
  './assets/app.css',
  './assets/app.js',
  './assets/icon.svg',
  './assets/icon-192.png',
  './assets/icon-512.png',
  './assets/apple-touch-icon.png',
  './manifest.webmanifest'
];

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(SHELL_CACHE);
    await cache.addAll(SHELL);
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter(key => !key.startsWith(VERSION)).map(key => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener('message', event => {
  if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // API and private storage are always network-only.
  if (url.pathname.includes('/api/') || url.pathname.includes('/storage/')) return;

  // Dynamic application routes (including query-string routes) are network-only;
  // show a generic offline page if navigation cannot reach the server.
  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        return await fetch(request, { cache: 'no-store' });
      } catch (_) {
        const cache = await caches.open(SHELL_CACHE);
        return (await cache.match('./offline.html')) || Response.error();
      }
    })());
    return;
  }

  // Never cache query-string responses or any dynamic route payload.
  if (url.search) return;

  // Cache only the static application shell. Never runtime-cache private data.
  if (/\.(?:css|js|png|svg|webmanifest)$/.test(url.pathname) || url.pathname.endsWith('/offline.html')) {
    event.respondWith((async () => {
      const cache = await caches.open(STATIC_CACHE);
      const cached = await cache.match(request);
      if (cached) return cached;
      try {
        const response = await fetch(request);
        if (response.ok && response.type === 'basic') cache.put(request, response.clone());
        return response;
      } catch (_) {
        return (await caches.match(request)) || Response.error();
      }
    })());
  }
});
