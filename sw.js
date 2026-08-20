/**
 * Service Worker — Cybokron PWA
 *
 * Caches static assets only. HTML/PHP responses are never cached: they are
 * per-user documents whose markup depends on the session, so storing them in a
 * shared Cache Storage bucket both leaks one visit's state into the next and
 * makes a stale "logged out" page reappear while the session is still valid.
 */

const CACHE_NAME = 'cybokron-v5';

// Static, session-independent assets only. Precaching HTML entry points used to
// fire several credentialed requests at once during install, which raced the
// remember-me token rotation and logged the user out.
const STATIC_ASSETS = [
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

// Session-independent responses only. icon.php is a static PNG generator that
// serves `Cache-Control: public` and reads nothing but ?size, so it belongs here
// even though it ends in .php.
const CACHEABLE = /\.(?:css|js|woff2?|ttf|otf|png|jpg|jpeg|svg|webp|ico)$|\/manifest\.json$|\/icon\.php$/;

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

  // Documents and API responses always go straight to the network. Serving a
  // cached copy is what made a live session look signed out.
  if (!CACHEABLE.test(url.pathname)) {
    if (e.request.mode === 'navigate') {
      e.respondWith(
        fetch(e.request).catch(() => new Response(
          '<!doctype html><meta charset="utf-8">'
          + '<meta name="viewport" content="width=device-width,initial-scale=1">'
          + '<title>Cybokron — Offline</title>'
          + '<style>body{font-family:system-ui,sans-serif;margin:0;min-height:100vh;display:flex;'
          + 'align-items:center;justify-content:center;background:#0f172a;color:#e2e8f0;text-align:center}'
          + 'div{padding:2rem}h1{font-size:1.25rem;margin:0 0 .5rem}p{opacity:.7;margin:0 0 .25rem}</style>'
          // The worker has no access to the request locale, so keep this bilingual
          // rather than pinning the offline page to a single language.
          + '<div><h1>Bağlantı yok / Offline</h1>'
          + '<p>İnternet bağlantısı kurulduğunda sayfayı yenileyin.</p>'
          + '<p>Reload the page once you are back online.</p></div>',
          { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
        ))
      );
    }
    return;
  }

  // Static assets: network-first, fall back to cache when offline.
  e.respondWith(
    fetch(e.request).then((res) => {
      if (res.ok && res.type === 'basic') {
        const clone = res.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(e.request, clone));
      }
      return res;
    }).catch(() => caches.match(e.request).then(
      (cached) => cached || new Response('', { status: 503, statusText: 'Service Unavailable' })
    ))
  );
});
