const CACHE_NAME = 'brigade-attendance-v33';
// Note: Static assets are cached dynamically on first request
// to support deployment in any subdirectory
const CACHEABLE_EXTENSIONS = ['.css', '.js', '.png', '.jpg', '.jpeg', '.svg', '.woff', '.woff2'];

// Install - skip waiting to activate immediately
self.addEventListener('install', event => {
    self.skipWaiting();
});

// Activate - clean up old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(key => key.startsWith('brigade-attendance') && key !== CACHE_NAME)
                    .map(key => caches.delete(key))
            );
        }).catch(err => {
            console.error('Cache cleanup error:', err);
        })
    );
    self.clients.claim();
});

// Fetch - network first, fallback to cache
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Skip SSE connections
    if (url.pathname.includes('/api/sse/')) {
        return;
    }

    // API requests - network only with offline queue
    if (url.pathname.includes('/api/')) {
        event.respondWith(
            fetch(event.request.clone()).catch(async () => {
                // Queue for later if it's a mutation
                if (event.request.method !== 'GET') {
                    await queueRequest(event.request.clone());
                    return new Response(JSON.stringify({
                        queued: true,
                        message: 'Request queued for when you\'re back online'
                    }), {
                        headers: { 'Content-Type': 'application/json' }
                    });
                }
                return new Response(JSON.stringify({ error: 'Offline' }), {
                    status: 503,
                    headers: { 'Content-Type': 'application/json' }
                });
            })
        );
        return;
    }

    // Static assets - network first, fallback to cache (only cacheable file types)
    if (event.request.method === 'GET') {
        const isCacheable = CACHEABLE_EXTENSIONS.some(ext => url.pathname.endsWith(ext));

        if (isCacheable) {
            event.respondWith(
                fetch(event.request).then(response => {
                    // Cache successful responses
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then(cache => {
                            cache.put(event.request, clone);
                        });
                    }
                    return response;
                }).catch(() => {
                    // Network failed, try cache
                    return caches.match(event.request);
                })
            );
        }
    }
});

// Queue offline requests
async function queueRequest(request) {
    const db = await openDB();
    const tx = db.transaction('queue', 'readwrite');
    const store = tx.objectStore('queue');

    const body = await request.text();

    await store.add({
        url: request.url,
        method: request.method,
        headers: Object.fromEntries(request.headers.entries()),
        body: body,
        timestamp: Date.now()
    });
}

// Open IndexedDB
function openDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('brigade-attendance', 1);

        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve(request.result);

        request.onupgradeneeded = event => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains('queue')) {
                db.createObjectStore('queue', { keyPath: 'id', autoIncrement: true });
            }
        };
    });
}

// Sync queued requests when back online
self.addEventListener('sync', event => {
    if (event.tag === 'sync-queue') {
        event.waitUntil(syncQueue());
    }
});

async function syncQueue() {
    const db = await openDB();
    const tx = db.transaction('queue', 'readwrite');
    const store = tx.objectStore('queue');
    const requests = await store.getAll();

    for (const req of requests) {
        try {
            await fetch(req.url, {
                method: req.method,
                headers: req.headers,
                body: req.body
            });
            await store.delete(req.id);
        } catch (error) {
            console.error('Failed to sync request:', error);
        }
    }
}

// Listen for online event to trigger sync
self.addEventListener('message', event => {
    if (event.data === 'sync') {
        syncQueue();
    }
});
