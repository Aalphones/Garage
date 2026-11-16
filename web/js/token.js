// Feste Zugänge im Konto: auflisten, anlegen (Klartext einmal sichtbar), widerrufen.
import { request } from './api.js';
import { clearChildren, element, formatDate, hideError, showError } from './dom.js';

function describeToken(token) {
    const item = element('li');
    item.appendChild(element('strong', token.name));

    const expires = token.expires_at === null ? 'läuft nie ab' : 'läuft ab am ' + formatDate(token.expires_at);
    const lastUsed = token.last_used_at === null ? 'noch nie benutzt' : 'zuletzt benutzt am ' + formatDate(token.last_used_at);
    item.appendChild(element('small', token.scopes.join(', ')));
    item.appendChild(element('small', expires + ' · ' + lastUsed));

    return item;
}

export function setupTokens() {
    const list = document.getElementById('token-list');
    const empty = document.getElementById('token-empty');
    const listError = document.getElementById('token-list-error');
    const form = document.getElementById('token-form');
    const formError = document.getElementById('token-form-error');
    const reveal = document.getElementById('token-reveal');
    const revealSecret = document.getElementById('token-secret');
    const copyButton = document.getElementById('token-copy');
    const copyStatus = document.getElementById('token-copy-status');
    const submitButton = form.querySelector('button[type="submit"]');

    function hideReveal() {
        reveal.hidden = true;
        revealSecret.textContent = '';
        copyStatus.textContent = '';
    }

    async function revoke(token) {
        if (!window.confirm('Token „' + token.name + '“ widerrufen? Programme, die es nutzen, verlieren sofort den Zugriff.')) {
            return;
        }
        hideError(listError);
        try {
            await request('DELETE', 'tokens/' + token.id);
        } catch (error) {
            showError(listError, error.message);
            return;
        }
        await load();
    }

    function render(tokens) {
        clearChildren(list);
        empty.hidden = tokens.length > 0;
        for (const token of tokens) {
            const item = describeToken(token);
            const button = element('button', 'Widerrufen');
            button.type = 'button';
            button.setAttribute('aria-label', 'Token ' + token.name + ' widerrufen');
            button.addEventListener('click', () => revoke(token));
            item.appendChild(button);
            list.appendChild(item);
        }
    }

    async function load() {
        hideError(listError);
        try {
            render((await request('GET', 'tokens')).tokens);
        } catch (error) {
            showError(listError, error.message);
        }
    }

    function selectedScopes() {
        const scopes = [];
        for (const box of form.querySelectorAll('input[name="scope"]:checked')) {
            scopes.push(box.value);
        }

        return scopes;
    }

    // Leer heißt „läuft nie ab“; sonst muss es eine ganze Zahl sein.
    function readDays() {
        const raw = form.elements.expires_days.value.trim();
        if (raw === '') {
            return null;
        }

        return Number(raw);
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        hideError(formError);
        hideReveal();

        const scopes = selectedScopes();
        if (scopes.length === 0) {
            showError(formError, 'Wähle mindestens eine Berechtigung.');
            return;
        }
        const days = readDays();
        if (days !== null && !Number.isInteger(days)) {
            showError(formError, 'Die Laufzeit muss eine ganze Zahl in Tagen sein oder leer bleiben.');
            return;
        }

        submitButton.disabled = true;
        try {
            const created = await request('POST', 'tokens', { name: form.elements.name.value.trim(), scopes, expires_days: days });
            revealSecret.textContent = created.token;
            reveal.hidden = false;
            form.reset();
            reveal.focus();
        } catch (error) {
            showError(formError, error.message);
            submitButton.disabled = false;
            return;
        }
        submitButton.disabled = false;
        await load();
    });

    copyButton.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(revealSecret.textContent);
            copyStatus.textContent = 'Kopiert.';
        } catch {
            copyStatus.textContent = 'Kopieren nicht möglich – den Text markieren und selbst kopieren.';
        }
    });

    return {
        load,
        clear() {
            hideReveal();
            hideError(listError);
            hideError(formError);
            clearChildren(list);
            form.reset();
        },
    };
}
