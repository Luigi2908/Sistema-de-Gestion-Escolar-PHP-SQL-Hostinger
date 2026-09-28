/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

// Rameez Scripts service worker — web push + installable PWA (offline fallback only).
// Auth-gated app: pages are ALWAYS fetched fresh from the network; nothing per-user is
// cached. Only offline.html is precached, shown when a navigation fails.

const OFFLINE_URL = 'offline.html';
const OFFLINE_CACHE = 'rs-offline-v1';
const PUSH_ICON = 'icon-192.png'; // same-origin app icon

self.addEventListener('install', function (e) {
    self.skipWaiting();
    e.waitUntil(caches.open(OFFLINE_CACHE).then(function (c) { return c.add(OFFLINE_URL); }).catch(function () {}));
});

self.addEventListener('activate', function (e) {
    e.waitUntil((async function () {
        const keys = await caches.keys();
        await Promise.all(keys.filter(function (k) { return k.startsWith('rs-offline-') && k !== OFFLINE_CACHE; })
            .map(function (k) { return caches.delete(k); }));
        await self.clients.claim();
    })());
});

// only intercept navigations: network-first (fresh, auth-safe) -> offline.html on failure.
// all other requests pass straight through (never cached).
self.addEventListener('fetch', function (event) {
    const req = event.request;
    if (req.method !== 'GET') return;
    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(function () { return caches.match(OFFLINE_URL); }));
    }
});

// web push — show the notification. payload = {title, body, url}
self.addEventListener('push', function (e) {
    let data = {};
    try { data = e.data ? e.data.json() : {}; } catch (err) { data = {}; }
    const title = data.title || 'Notification';
    const body = data.body || '';
    const url = data.url || 'dashboard.php';
    e.waitUntil(
        self.registration.showNotification(title, {
            body: body, icon: PUSH_ICON, badge: PUSH_ICON, data: { url: url }
        })
    );
});

// tap notification -> focus an open tab on that url, else open it
self.addEventListener('notificationclick', function (e) {
    e.notification.close();
    const target = (e.notification.data && e.notification.data.url) || 'dashboard.php';
    e.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            for (const c of list) {
                if (c.url.includes(target) && 'focus' in c) return c.focus();
            }
            return self.clients.openWindow ? self.clients.openWindow(target) : null;
        })
    );
});
