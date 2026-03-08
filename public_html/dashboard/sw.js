const CACHE_NAME = 'antiparticula-dashboard-v2';
const SHELL_FILES = [
    './',
    './index.html',
    './dashboard.css',
    './dashboard.js',
    './manifest.json',
    // MEDIO-04 fix: cachear Chart.js para que la PWA funcione offline
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(SHELL_FILES))
    );
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Las llamadas a la API siempre van a red (no cachear datos dinámicos)
    if (url.pathname.includes('get_dashboard_data.php') || url.pathname.includes('sync_pos.php')) {
        event.respondWith(fetch(event.request));
        return;
    }

    // Para el shell, primero cache, luego red
    event.respondWith(
        caches.match(event.request).then(cached => cached || fetch(event.request))
    );
});
