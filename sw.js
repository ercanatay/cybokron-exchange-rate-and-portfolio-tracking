/**
 * Service Worker — Cybokron PWA
 * Network-first strategy with offline fallback
 */

const CACHE_NAME = 'cybokron-v3';
const STATIC_ASSETS = [
  '/',
  '/index.php',
  '/login.php',
  '/assets/css/style.css',
  '/assets/css/currency-icons.css',
  '/assets/js/bootstrap.js',
  '/assets/js/theme.js',
  '/assets/js/app.js',
  '/assets/js/converter.js',
  '/assets/js/chart.js',
  '/assets/js/lib/chart.umd.min.js',
  '/manifest.json'
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(STATIC_ASSETS).catch(() => {});
    }).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)));
    }).then(() => self.clients.claim())
  );
});


self.addEventListener('fetch', (e) => {
  if (e.request.method !== 'GET') return;
  const url = new URL(e.request.url);
  if (url.origin !== location.origin) return;
  if (url.pathname.includes('api.php') || url.pathname.includes('portfolio')) return;

  // Network-first: try network, fall back to cache, then offline page
  e.respondWith(
    fetch(e.request).then((res) => {
      if (res.ok && (url.pathname.endsWith('.css') || url.pathname.endsWith('.js') || url.pathname.endsWith('.html') || url.pathname === '/' || url.pathname.endsWith('.php'))) {
        const clone = res.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(e.request, clone));
      }
      return res;
    }).catch(() => {
      return caches.match(e.request).then((cached) => {
        if (cached) return cached;
        if (e.request.mode === 'navigate') {
          return caches.match('/index.php') || caches.match('/');
        }
        return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
      });
    })
  );
});
