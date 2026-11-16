// Einstieg für zustimmung.html: Anmeldung verlangen, dann die Anfrage einer App zeigen und Erlauben oder Ablehnen an die API geben.
import { logout, request, restore } from './api.js';
import { clearChildren, element, hideError, showError } from './dom.js';
import { setupLogin } from './login.js';

const REDIRECT_DELAY_MS = 1200;
const SECONDS_PER_MINUTE = 60;
const REQUEST_ID_PATTERN = /^[a-z0-9]{32}$/;

const SCOPE_TEXTS = {
    'garage:read': ['Lesen', 'Garagen, Fahrzeuge und ihre Daten ansehen.'],
    'garage:write': ['Schreiben', 'Fahrzeuge anlegen und Daten mit Herkunft ergänzen. Löschen kann eine App nie.'],
    'garage:files': ['Dateien', 'Belege und Fotos hochladen. In dieser Version noch ohne Wirkung.'],
};
const DEFAULT_SCOPES = ['garage:read', 'garage:write'];

const REJECTIONS = {
    unbekannte_app: 'Die App hat sich nicht gültig ausgewiesen. Starte die Verbindung in der App neu. Nichts wurde gespeichert.',
    app_nicht_erreichbar: 'Die App konnte nicht geprüft werden. Versuche es in ein paar Minuten noch einmal.',
    falsche_rueckadresse: 'Die Anfrage wollte die Antwort an eine Adresse schicken, die die App nicht angemeldet hat. Nichts wurde gespeichert.',
};
const GENERIC_REJECTION = 'Die Anfrage ist nicht lesbar. Starte die Verbindung in der App neu.';
const EXPIRED_TEXT = 'Diese Anfrage ist abgelaufen oder schon beantwortet. Starte die Verbindung in der App neu.';
const SEND_FAILED_TEXT = 'Die Antwort kam nicht an. Prüfe die Verbindung und versuche es noch einmal.';

const statusLine = document.getElementById('status');
const loginSection = document.getElementById('login');
const loginForm = document.getElementById('login-form');
const loginError = document.getElementById('login-error');
const consentSection = document.getElementById('consent');
const consentForm = document.getElementById('consent-form');
const consentError = document.getElementById('consent-error');
const allowButton = document.getElementById('consent-allow');
const denyButton = document.getElementById('consent-deny');

const params = new URLSearchParams(location.search);
const requestId = params.get('anfrage');
let pendingRequest = null;

function showStatus(message) {
    loginSection.hidden = true;
    consentSection.hidden = true;
    statusLine.textContent = message;
}

function minutesText(seconds) {
    const minutes = Math.ceil(seconds / SECONDS_PER_MINUTE);
    if (minutes <= 1) {
        return 'Die Anfrage gilt noch weniger als eine Minute.';
    }

    return 'Die Anfrage gilt noch ' + minutes + ' Minuten.';
}

function renderScopes(scopes) {
    const container = document.getElementById('consent-scopes');
    clearChildren(container);
    for (const scope of scopes) {
        const [title, hint] = SCOPE_TEXTS[scope];
        const box = element('input');
        box.type = 'checkbox';
        box.name = 'scope';
        box.value = scope;
        box.checked = DEFAULT_SCOPES.includes(scope);

        const label = element('label', undefined, 'check');
        label.appendChild(box);
        const text = element('span', title);
        text.appendChild(element('small', hint));
        label.appendChild(text);
        container.appendChild(label);
    }
}

function renderRequest(details, user) {
    document.getElementById('consent-user').textContent = user.name;
    document.getElementById('consent-app').textContent = details.client.name;
    document.getElementById('consent-host').textContent = details.client.host;
    document.getElementById('consent-redirect').textContent = details.redirect_host;
    document.getElementById('consent-loopback').hidden = !details.loopback;
    document.getElementById('consent-offline').hidden = !details.offline;
    document.getElementById('consent-expiry').textContent = minutesText(details.expires_in);
    renderScopes(details.scopes.filter((scope) => scope in SCOPE_TEXTS));
    hideError(consentError);
    allowButton.disabled = false;
    denyButton.disabled = false;
    statusLine.textContent = '';
    loginSection.hidden = true;
    consentSection.hidden = false;
}

async function showRequest(user) {
    try {
        pendingRequest = await request('GET', 'oauth/requests/' + requestId);
    } catch (error) {
        showStatus(error.status === 404 || error.status === 410 ? EXPIRED_TEXT : GENERIC_REJECTION);
        return;
    }
    renderRequest(pendingRequest, user);
}

function showLogin() {
    consentSection.hidden = true;
    statusLine.textContent = '';
    loginSection.hidden = false;
}

async function decide(isAllowed, scopes) {
    hideError(consentError);
    allowButton.disabled = true;
    denyButton.disabled = true;
    try {
        const { redirect } = await request('POST', 'oauth/consent', { request_id: requestId, decision: isAllowed ? 'allow' : 'deny', scopes });
        showStatus((isAllowed ? 'Erlaubt. Weiter zu ' : 'Abgelehnt. Zurück zu ') + pendingRequest.redirect_host + ' …');
        setTimeout(() => location.assign(redirect), REDIRECT_DELAY_MS);
    } catch (error) {
        if (error.status === 404 || error.status === 410) {
            showStatus(EXPIRED_TEXT);
            return;
        }
        showError(consentError, error.status === 0 ? SEND_FAILED_TEXT : error.message);
        allowButton.disabled = false;
        denyButton.disabled = false;
    }
}

consentForm.addEventListener('submit', (event) => {
    event.preventDefault();
    const scopes = [...consentForm.querySelectorAll('input[name="scope"]:checked')].map((box) => box.value);
    if (scopes.length === 0) {
        showError(consentError, 'Wähle mindestens ein Recht – oder lehne ab.');
        return;
    }
    decide(true, scopes);
});

denyButton.addEventListener('click', () => decide(false, []));

document.getElementById('switch-account').addEventListener('click', async () => {
    try {
        await logout();
    } catch (error) {
        showError(consentError, 'Die Abmeldung ist fehlgeschlagen: ' + error.message);
        return;
    }
    showLogin();
});

setupLogin(loginForm, loginError, showRequest);

async function start() {
    const rejection = params.get('fehler');
    if (rejection !== null) {
        showStatus(REJECTIONS[rejection] ?? GENERIC_REJECTION);
        return;
    }
    if (requestId === null || !REQUEST_ID_PATTERN.test(requestId)) {
        showStatus(GENERIC_REJECTION);
        return;
    }

    let user = null;
    try {
        user = await restore();
    } catch (error) {
        showStatus(error.message);
        return;
    }
    if (user === null) {
        showLogin();
        return;
    }
    await showRequest(user);
}

start();
