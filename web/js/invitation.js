// Einstieg für einladung.html: Einladung ansehen, Konto anlegen, weiter ins Konto.
import { post } from './api.js';
import { hideError, showError } from './dom.js';

const statusLine = document.getElementById('status');
const invitation = document.getElementById('invitation');
const summary = document.getElementById('invitation-summary');
const form = document.getElementById('invitation-form');
const formError = document.getElementById('invitation-error');
const submitButton = form.querySelector('button[type="submit"]');

// Der Token steht im Fragment, damit er nie an einen Server oder in ein Log geht; danach verschwindet er aus der Adresszeile.
const token = location.hash.slice(1);
history.replaceState(null, '', location.pathname + location.search);

async function start() {
    if (token === '') {
        statusLine.textContent = 'Dieser Link enthält keine Einladung. Bitte den vollständigen Link aus der Einladung öffnen.';
        return;
    }

    try {
        const preview = await post('invitations/preview', { token });
        summary.textContent = 'Du wurdest in die Garage „' + preview.garage_name + '“ eingeladen, Rolle: ' + preview.role + '.';
    } catch (error) {
        statusLine.textContent = error.message;
        return;
    }
    statusLine.textContent = '';
    invitation.hidden = false;
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    hideError(formError);
    submitButton.disabled = true;
    try {
        await post('invitations/accept', {
            token,
            name: form.elements.name.value.trim(),
            email: form.elements.email.value.trim(),
            password: form.elements.password.value,
        });
    } catch (error) {
        showError(formError, error.message);
        submitButton.disabled = false;
        return;
    }
    location.assign('/garage/');
});

start();
