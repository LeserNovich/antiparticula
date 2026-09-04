// Subir esta versión en CADA cambio del shell (html/css/js), si no los
// clientes instalados siguen sirviendo la copia vieja desde caché.
const CACHE_NAME = 'antiparticula-dashboard-v3';

const SHELL_FILES = [
    '/dashboard/index.html',
    '/dashboard/dashboard.css',
    '/dashboard/dashboard.js',
    '/dashboard/manifest.json',
    '/dashboard/icon-192.png',
    '/dashboard/icon-512.png',
    '/dashboard/icon-maskable-512.png',
    // MEDIO-04 fix: cachear Chart.js para que la PWA funcione offline
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache =>
            // addAll aborta todo el install si un solo recurso falla; con
            // add() individual el shell se cachea aunque el CDN no responda.
            Promise.all(SHELL_FILES.map(f => cache.add(f).catch(err =>
                console.warn('SW: no se pudo cachear', f, err)
            )))
        )
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

    // Solo interceptar peticiones de nuestro origen o CDN conocido
    const isOurOrigin = url.origin === self.location.origin;
    const isKnownCDN  = url.href.startsWith('https://cdn.jsdelivr.net/');
    if (!isOurOrigin && !isKnownCDN) return;

    // Las llamadas a la API siempre van a red (no cachear datos dinámicos)
    if (url.pathname.includes('get_dashboard_data.php') || url.pathname.includes('sync_pos.php')) {
        event.respondWith(fetch(event.request));
        return;
    }

    // Navegación a /dashboard/<hardware_id>: esa URL nunca está en caché
    // porque el id cambia por instalación. Se sirve el shell cacheado y
    // dashboard.js vuelve a leer el id desde location.pathname.
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() =>
                caches.match('/dashboard/index.html', { cacheName: CACHE_NAME })
            )
        );
        return;
    }

    // Para el shell, primero cache, luego red
    event.respondWith(
        caches.match(event.request).then(cached => cached || fetch(event.request))
    );
});
