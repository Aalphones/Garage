// Service Worker für zwei Zwecke: das Teilen-Ziel (Android) fängt den POST von „Teilen → GARAGE“ ab, und die Hülle der
// Notfallkarte (notfall.html samt Skripten, Stilen, Hintergrund) liegt im Cache, damit sie ohne Netz öffnet (ADR-025).
// App-Daten hält er nicht: die Notfallkarten selbst schreibt die Seite in `garage-notfall`, das Abmelden löscht sie.
// Alle übrigen Anfragen gehen unberührt zum Server.
const SHARE_PATH = '/garage/teilen';
const SHARE_CACHE = 'garage-geteilt';
const SHARE_FIELD = 'dateien';
const INBOX_PAGE = '/garage/eingang.html';
const REDIRECT_AFTER_POST = 303;

// Neue Version der Hülle (andere Dateien in SHELL_FILES) → Zähler erhöhen; `activate` räumt die alte weg.
const SHELL_CACHE = 'garage-notfall-huelle-v1';
const SHELL_PREFIX = 'garage-notfall-huelle-';
const SHELL_FILES = [
    '/garage/notfall.html',
    '/garage/js/notfall.js',
    '/garage/js/emergency.js',
    '/garage/js/share.js',
    '/garage/js/dom.js',
    '/garage/js/theme.js',
    '/garage/css/tokens.css',
    '/garage/css/base.css',
    '/garage/img/hintergrund.jpg',
];

// Einzeln statt `addAll`: eine fehlende Datei darf den Worker nicht scheitern lassen, sonst fiele auch das Teilen-Ziel aus.
async function fillShell() {
    const cache = await caches.open(SHELL_CACHE);
    await Promise.allSettled(SHELL_FILES.map((path) => cache.add(path)));
}

async function dropOldShells() {
    const names = await caches.keys();
    await Promise.all(names
        .filter((name) => name.startsWith(SHELL_PREFIX) && name !== SHELL_CACHE)
        .map((name) => caches.delete(name)));
}

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(fillShell());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(dropOldShells().then(() => self.clients.claim()));
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

/** Erst Netz (und die Hülle damit auffrischen), bei Fehler die Hülle; Schlüssel ist der Pfad, Hash und Query zählen nicht. */
async function networkThenShell(request, path) {
    const cache = await caches.open(SHELL_CACHE);
    try {
        const response = await fetch(request);
        if (response.ok) {
            await cache.put(path, response.clone());
        }

        return response;
    } catch (error) {
        const cached = await cache.match(path);
        if (cached === undefined) {
            throw error;
        }

        return cached;
    }
}

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.method === 'POST' && url.pathname === SHARE_PATH) {
        event.respondWith((async () => {
            const count = await storeSharedFiles(event.request);

            return Response.redirect(INBOX_PAGE + '?geteilt=' + count, REDIRECT_AFTER_POST);
        })());

        return;
    }
    const isShellFile = event.request.method === 'GET' && url.origin === self.location.origin && SHELL_FILES.includes(url.pathname);
    if (isShellFile) {
        event.respondWith(networkThenShell(event.request, url.pathname));
    }
});
