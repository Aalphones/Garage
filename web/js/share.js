// Service Worker anmelden (Teilen-Ziel, Hülle der Notfallkarte) und geteilte Dateien aus dem Cache holen.
// Gehört zur Hülle der Notfallkarte — darf nichts importieren, sonst lädt notfall.html offline nicht.
const WORKER_URL = '/garage/sw.js';
const WORKER_SCOPE = '/garage/';
const SHARE_CACHE = 'garage-geteilt';

/** Fehler bleiben still: ohne Service Worker fehlen nur Teilen-Ziel und Notfallkarte ohne Netz. */
export function registerWorker() {
    if (navigator.serviceWorker === undefined) {
        return;
    }
    navigator.serviceWorker.register(WORKER_URL, { scope: WORKER_SCOPE }).catch(() => {});
}

/** Liest die geteilten Dateien als `File` und leert den Cache; leere Liste, wenn nichts geteilt wurde. */
export async function takeSharedFiles() {
    if (window.caches === undefined || !(await caches.has(SHARE_CACHE))) {
        return [];
    }
    const cache = await caches.open(SHARE_CACHE);
    const requests = await cache.keys();
    const files = [];
    for (const request of requests) {
        const response = await cache.match(request);
        const blob = await response.blob();
        const name = decodeURIComponent(response.headers.get('X-Name') ?? 'geteilt');
        files.push(new File([blob], name, { type: response.headers.get('Content-Type') ?? blob.type }));
    }
    await caches.delete(SHARE_CACHE);

    return files;
}
