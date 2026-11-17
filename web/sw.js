// Service Worker nur fürs Teilen-Ziel (Android): fängt den POST von „Teilen → GARAGE“ ab.
// Er hält keinen Offline-Cache der Oberfläche; alle anderen Anfragen gehen unberührt zum Server.
const SHARE_PATH = '/garage/teilen';
const SHARE_CACHE = 'garage-geteilt';
const SHARE_FIELD = 'dateien';
const INBOX_PAGE = '/garage/eingang.html';
const REDIRECT_AFTER_POST = 303;

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

async function storeSharedFiles(request) {
    const formData = await request.formData();
    const files = formData.getAll(SHARE_FIELD).filter((entry) => entry instanceof File);
    // Vorherige, nie abgeholte Dateien verwerfen: pro Teilen-Vorgang genau ein Satz.
    await caches.delete(SHARE_CACHE);
    const cache = await caches.open(SHARE_CACHE);
    await Promise.all(files.map((file, index) => cache.put('/garage/geteilt/' + index, new Response(file, {
        headers: { 'Content-Type': file.type, 'X-Name': encodeURIComponent(file.name) },
    }))));

    return files.length;
}

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'POST' || url.pathname !== SHARE_PATH) {
        return;
    }
    event.respondWith((async () => {
        const count = await storeSharedFiles(event.request);

        return Response.redirect(INBOX_PAGE + '?geteilt=' + count, REDIRECT_AFTER_POST);
    })());
});
