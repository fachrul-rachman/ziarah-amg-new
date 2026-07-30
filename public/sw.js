const CACHE_NAME = 'ziarah-amg-static-v1';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE_NAME)
            .then((cache) => cache.add(OFFLINE_URL))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((names) =>
                Promise.all(
                    names
                        .filter((name) => name !== CACHE_NAME)
                        .map((name) => caches.delete(name)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL)),
        );

        return;
    }

    if (!url.pathname.startsWith('/build/assets/')) {
        return;
    }

    event.respondWith(
        caches.match(request).then(
            (cached) =>
                cached
                ?? fetch(request).then((response) => {
                    if (!response.ok) {
                        return response;
                    }

                    const copy = response.clone();
                    event.waitUntil(
                        caches
                            .open(CACHE_NAME)
                            .then((cache) => cache.put(request, copy)),
                    );

                    return response;
                }),
        ),
    );
});
