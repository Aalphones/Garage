// Aufrufe der Web-API. Das Access-Token lebt nur in dieser Variable, nie in localStorage; die Sitzung hält der Refresh-Cookie.
const API_BASE = '/garage/api/';
const RENEW_BEFORE_MS = 60_000;

let session = null;
let pendingRefresh = null;

export class ApiError extends Error {
    constructor(status, code, message) {
        super(message);
        this.status = status;
        this.code = code;
    }
}

async function send(method, path, body, headers) {
    const init = { method, credentials: 'same-origin', headers: { ...headers } };
    if (body !== undefined) {
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
    }

    let response;
    try {
        response = await fetch(API_BASE + path, init);
    } catch {
        throw new ApiError(0, 'network_error', 'Keine Verbindung zum Server.');
    }
    if (response.status === 204) {
        return null;
    }

    let data = null;
    try {
        data = await response.json();
    } catch {
        data = null;
    }
    if (!response.ok) {
        const code = data && data.error ? data.error : 'http_error';
        const message = data && data.message ? data.message : 'Der Aufruf ist fehlgeschlagen.';
        throw new ApiError(response.status, code, message);
    }

    return data;
}

function adopt(result) {
    session = {
        accessToken: result.access_token,
        expiresAt: Date.now() + result.expires_in * 1000,
        user: result.user,
    };
}

function isFresh() {
    return session !== null && session.expiresAt - Date.now() > RENEW_BEFORE_MS;
}

// Mehrere gleichzeitige Aufrufe teilen sich einen Refresh: der Server rotiert den Cookie, ein zweiter Refresh gälte sonst als Wiederverwendung.
function refresh() {
    if (pendingRefresh === null) {
        pendingRefresh = send('POST', 'auth/refresh', undefined, { 'X-Garage': '1' })
            .then((result) => {
                adopt(result);
                return session.accessToken;
            })
            .catch((error) => {
                session = null;
                throw error;
            })
            .finally(() => {
                pendingRefresh = null;
            });
    }

    return pendingRefresh;
}

async function accessToken(shouldRenew) {
    if (!shouldRenew && isFresh()) {
        return session.accessToken;
    }

    return refresh();
}

/** Aufruf ohne Anmeldung (Einladung ansehen und annehmen). */
export function post(path, body) {
    return send('POST', path, body, {});
}

/** Aufruf mit Anmeldung; bei 401 einmal mit erneuertem Token wiederholt. */
export async function request(method, path, body) {
    const token = await accessToken(false);
    try {
        return await send(method, path, body, { Authorization: 'Bearer ' + token });
    } catch (error) {
        if (!(error instanceof ApiError) || error.status !== 401) {
            throw error;
        }
    }

    return send(method, path, body, { Authorization: 'Bearer ' + (await accessToken(true)) });
}

// XMLHttpRequest statt fetch, weil nur er den Fortschritt des Hochladens meldet.
function sendForm(path, formData, token, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', API_BASE + path);
        xhr.setRequestHeader('Authorization', 'Bearer ' + token);
        xhr.responseType = 'json';
        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                onProgress(event.loaded / event.total);
            }
        });
        xhr.addEventListener('error', () => reject(new ApiError(0, 'network_error', 'Keine Verbindung zum Server.')));
        xhr.addEventListener('load', () => {
            const data = xhr.response;
            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(data);
                return;
            }
            const code = data && data.error ? data.error : 'http_error';
            const message = data && data.message ? data.message : 'Der Aufruf ist fehlgeschlagen.';
            reject(new ApiError(xhr.status, code, message));
        });
        xhr.send(formData);
    });
}

/** Multipart-Upload mit Anmeldung und Fortschritt (`onProgress` bekommt 0 bis 1); bei 401 einmal mit erneuertem Token wiederholt. */
export async function upload(path, formData, onProgress) {
    const token = await accessToken(false);
    try {
        return await sendForm(path, formData, token, onProgress);
    } catch (error) {
        if (!(error instanceof ApiError) || error.status !== 401) {
            throw error;
        }
    }

    return sendForm(path, formData, await accessToken(true), onProgress);
}

export async function login(email, password) {
    adopt(await post('auth/login', { email, password }));

    return session.user;
}

/** Fragt beim Laden der Seite nach, ob der Refresh-Cookie noch eine Sitzung hält. Gibt den Nutzer oder null zurück. */
export async function restore() {
    try {
        await refresh();
    } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
            return null;
        }
        throw error;
    }

    return session.user;
}

export async function logout() {
    try {
        await send('POST', 'auth/logout', undefined, { 'X-Garage': '1' });
    } finally {
        session = null;
    }
}
