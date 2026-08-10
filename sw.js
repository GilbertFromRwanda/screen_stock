// GilStock service worker: caches the static app shell (css/js/fonts/icons)
// and shows offline.html when navigation fails with no network. Page data
// (PHP/AJAX) is always fetched live — it's per-session and DB-backed, so
// caching it would show stale or wrong-company data.
const CACHE_VERSION = 'screen-stock-shell-v1';

const SHELL_ASSETS = [
    'offline.html',
    'manifest.json',
    'css/all.min.css',
    'css/dashboard.css',
    'css/loans.css',
    'css/revenue.css',
    'css/sales.css',
    'css/style.css',
    'css/user.css',
    'fonts/inter.css',
    'script.js',
    'chart.js',
    'js/cart-drafts.js',
    'js/data-cache.js',
    'js/order-history.js',
    'js/order-notify.js',
    'js/order-status-watch.js',
    'js/qrcode.min.js',
    'js/sale-queue.js',
    'icons/icon-192.png',
    'icons/icon-512.png',
    'icons/apple-touch-icon.png',
    'icons/favicon-32.png'
];

// Extensions safe to cache-first once fetched (fonts, images, etc. beyond the
// precache list above — e.g. the individual inter-*.woff2 files).
const CACHEABLE_EXT = /\.(?:css|js|woff2?|ttf|png|jpe?g|svg|ico)$/i;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_VERSION)
            .then((cache) => cache.addAll(SHELL_ASSETS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key !== CACHE_VERSION).map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // Page navigations: network-first, fall back to the offline page.
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(() => caches.match('offline.html'))
        );
        return;
    }

    // Static assets: cache-first, refreshing the cache in the background.
    if (CACHEABLE_EXT.test(url.pathname)) {
        event.respondWith(
            caches.match(req).then((cached) => {
                const fetchPromise = fetch(req).then((res) => {
                    if (res.ok) {
                        const copy = res.clone();
                        caches.open(CACHE_VERSION).then((cache) => cache.put(req, copy));
                    }
                    return res;
                }).catch(() => cached);
                return cached || fetchPromise;
            })
        );
    }
    // Everything else (ajax_*.php, *_poll.php, api endpoints, etc.) is left
    // to the network untouched.
});
