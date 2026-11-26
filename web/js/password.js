// Einstieg für passwort.html: Rücksetz-Link ansehen, neues Passwort setzen, angemeldet weiter ins Konto.
import { post } from './api.js';
import { hideError, showError } from './dom.js';

const ACCOUNT_PAGE = '/garage/';
// Mit diesen Antworten ist der Link erledigt: kein Formular hilft mehr.
const DEAD_LINK_STATUSES = [404, 410];

const statusLine = document.getElementById('status');
const exit = document.getElementById('exit');
const reset = document.getElementById('reset');
const heading = document.getElementById('reset-heading');
const form = document.getElementById('reset-form');
const formError = document.getElementById('reset-error');
const submitButton = form.querySelector('button[type="submit"]');

// Der Token steht im Fragment, damit er nie an einen Server oder in ein Log geht; danach verschwindet er aus der Adresszeile.
const token = location.hash.slice(1);
history.replaceState(null, '', location.pathname + location.search);

function showDeadEnd(message) {
    reset.hidden = true;
    statusLine.textContent = message;
    exit.hidden = false;
}

async function start() {
    if (token === '') {
        showDeadEnd('Dieser Link ist unvollständig. Bitte den vollständigen Rücksetz-Link öffnen.');
        return;
    }

    try {
        const preview = await post('password-reset/preview', { token });
        heading.textContent = 'Neues Passwort für ' + preview.name;
    } catch (error) {
        // Auch 429 und Netzfehler landen hier; der Satz des Servers sagt, was los ist.
        showDeadEnd(error.message);
        return;
    }
    statusLine.textContent = '';
    reset.hidden = false;
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    hideError(formError);
    const password = form.elements.password.value;
    if (password !== form.elements.repeat.value) {
        showError(formError, 'Die beiden Passwörter stimmen nicht überein.');
        return;
    }
    submitButton.disabled = true;
    try {
        await post('password-reset/accept', { token, password });
    } catch (error) {
        submitButton.disabled = false;
        if (DEAD_LINK_STATUSES.includes(error.status)) {
            showDeadEnd(error.message);
        } else {
            showError(formError, error.message);
        }
        return;
    }
    location.assign(ACCOUNT_PAGE);
});

start();
