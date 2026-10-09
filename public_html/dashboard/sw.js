// Subir esta versión en CADA cambio del shell (html/css/js), si no los
// clientes instalados siguen sirviendo la copia vieja desde caché.
const CACHE_NAME = 'antiparticula-dashboard-v5';

const SHELL_FILES = [
    '/dashboard/index.html',
    '/dashboard/dashboard.css',
    '/dashboard/dashboard.js',
    '/dashboard/manifest.json',
    '/dashboard/icon-192.png',
    '/dashboard/icon-512.png',
    '/dashboard/icon-maskable-512.png'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache =>
            // addAll aborta todo el install si un solo recurso falla; con
            // add() individual el shell se cachea aunque uno no responda.
            // cache:'reload' salta la caché HTTP del navegador: sin eso, la
            // versión nueva podía precachear los archivos viejos.
            Promise.all(SHELL_FILES.map(f => cache.add(new Request(f, { cache: 'reload' })).catch(err =>
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

    // Solo interceptar peticiones de nuestro origen (el panel ya no usa CDN:
    // las gráficas son propias)
    if (url.origin !== self.location.origin) return;

    // Las llamadas a la API siempre van a red (no cachear datos dinámicos)
    if (url.pathname.includes('get_dashboard_data.php') || url.pathname.includes('sync_pos.php')
        || url.pathname.includes('dashboard_auth.php')) {
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
