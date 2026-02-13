/**
 * Service Worker — Cybokron PWA
 * Basic offline caching for static assets
 */

const CACHE_NAME = 'cybokron-v2';
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

  e.respondWith(
    caches.match(e.request).then((cached) => {
      if (cached) return cached;
      return fetch(e.request).then((res) => {
        const clone = res.clone();
        if (res.ok && (url.pathname.endsWith('.css') || url.pathname.endsWith('.js') || url.pathname.endsWith('.html') || url.pathname === '/' || url.pathname.endsWith('.php'))) {
          caches.open(CACHE_NAME).then((cache) => cache.put(e.request, clone));
        }
        return res;
      }).catch(() => {
        if (e.request.mode === 'navigate') {
          return caches.match('/index.php') || caches.match('/');
        }
        return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
      });
    })
  );
});
