// public/sw.js – Cafe Side service worker
// ==========================================
// Strategy: network-first for every request.
// Cache static assets (CSS, JS, images, fonts) on success.
// NEVER cache PHP pages or ?api= requests — always go to the network.
// On network failure, serve from cache if available.
//
// This means users always see fresh content when online.
// The cache only kicks in when the network is unreachable.

const CACHE_NAME = 'cafe-side-v1';

// Extensions we're allowed to cache. Anything else hits the network only.
const STATIC_EXT = /\.(css|js|mjs|png|jpg|jpeg|gif|svg|webp|ico|woff|woff2|ttf|otf|eot)$/i;

// ------------------------------------------------------------------
// INSTALL — activate immediately, don't wait for old SW to release.
// ------------------------------------------------------------------
self.addEventListener('install', (event) => {
    self.skipWaiting();
});

// ------------------------------------------------------------------
// ACTIVATE — take control of open pages right away.
// Also clean up old caches if we ever bump the version.
// ------------------------------------------------------------------
self.addEventListener('activate', (event) => {
    event.waitUntil(
        Promise.all([
            self.clients.claim(),
            caches.keys().then((keys) =>
                Promise.all(
                    keys
                        .filter((k) => k !== CACHE_NAME)
                        .map((k) => caches.delete(k))
                )
            ),
        ])
    );
});

// ------------------------------------------------------------------
// FETCH — network-first with cache fallback, only for static assets.
// ------------------------------------------------------------------
self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Only handle GET. POST/PUT/etc. pass through untouched.
    if (req.method !== 'GET') return;

    const url = new URL(req.url);

    // Skip PHP pages and API calls entirely — always live.
    if (url.pathname.toLowerCase().endsWith('.php')) return;
    if (url.search.indexOf('api=') !== -1) return;

    // Only cache files with static extensions.
    if (!STATIC_EXT.test(url.pathname)) return;

    event.respondWith(
        fetch(req)
            .then((res) => {
                // Cache successful responses (same-origin or CORS-enabled).
                if (res && res.status === 200) {
                    const clone = res.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(req, clone).catch(() => { /* quota exceeded — ignore */ });
                    });
                }
                return res;
            })
            .catch(() => {
                // Network failed — try the cache.
                return caches.match(req).then((cached) => {
                    if (cached) return cached;
                    // No cache either — nothing we can do, return a network error.
                    return new Response('Offline — resource not cached.', {
                        status: 503,
                        headers: { 'Content-Type': 'text/plain' },
                    });
                });
            })
    );
});