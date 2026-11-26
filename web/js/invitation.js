// Einstieg für einladung.html: Einladung ansehen, dann ein Konto anlegen oder mit einem bestehenden Konto beitreten.
import { post, request, restore } from './api.js';
import { hideError, showError } from './dom.js';
import { setupLogin } from './login.js';

const ACCOUNT_PAGE = '/garage/';
const GARAGE_PAGE = '/garage/garage.html';
// Mit diesen Antworten ist die Einladung für diese Seite erledigt: kein Formular hilft mehr.
const DEAD_LINK_STATUSES = [404, 410];

const statusLine = document.getElementById('status');
const invitation = document.getElementById('invitation');
const summary = document.getElementById('invitation-summary');
const exit = document.getElementById('exit');
const exitLink = document.getElementById('exit-link');
const joinSection = document.getElementById('join');
const joinButton = document.getElementById('join-button');
const joinError = document.getElementById('join-error');
const ways = document.getElementById('ways');
const loginForm = document.getElementById('login-form');
const loginError = document.getElementById('login-error');
const accountForm = document.getElementById('invitation-form');
const accountError = document.getElementById('invitation-error');
const accountButton = accountForm.querySelector('button[type="submit"]');
const newAccountWay = { button: document.getElementById('way-new'), form: accountForm };
const existingAccountWay = { button: document.getElementById('way-existing'), form: loginForm };
const choices = [newAccountWay, existingAccountWay];

// Der Token steht im Fragment, damit er nie an einen Server oder in ein Log geht; danach verschwindet er aus der Adresszeile.
const token = location.hash.slice(1);
history.replaceState(null, '', location.pathname + location.search);

function showDeadEnd(message, linkText = 'Zur Anmeldung', href = ACCOUNT_PAGE) {
    invitation.hidden = true;
    statusLine.textContent = message;
    exitLink.textContent = linkText;
    exitLink.href = href;
    exit.hidden = false;
}

function showWays() {
    joinSection.hidden = true;
    ways.hidden = false;
}

function choose(chosen) {
    for (const choice of choices) {
        const isChosen = choice === chosen;
        choice.form.hidden = !isChosen;
        choice.button.setAttribute('aria-expanded', String(isChosen));
    }
    chosen.form.querySelector('input').focus();
}

function showJoin(user) {
    ways.hidden = true;
    for (const choice of choices) {
        choice.form.hidden = true;
    }
    joinButton.textContent = 'Beitreten als ' + user.name;
    joinSection.hidden = false;
}

async function join() {
    hideError(joinError);
    joinButton.disabled = true;
    try {
        await request('POST', 'invitations/join', { token });
    } catch (error) {
        joinButton.disabled = false;
        if (error.code === 'already_member') {
            showDeadEnd(error.message, 'Zur Garage', GARAGE_PAGE);
        } else if (DEAD_LINK_STATUSES.includes(error.status)) {
            showDeadEnd(error.message);
        } else if (error.status === 401) {
            // Die Sitzung ist inzwischen beendet: zurück zur Wahl, die Einladung gilt weiter.
            showWays();
            choose(existingAccountWay);
            showError(loginError, error.message);
        } else {
            showError(joinError, error.message);
        }
        return;
    }
    location.assign(GARAGE_PAGE);
}

async function start() {
    if (token === '') {
        showDeadEnd('Dieser Link enthält keine Einladung. Bitte den vollständigen Link aus der Einladung öffnen.');
        return;
    }

    try {
        const preview = await post('invitations/preview', { token });
        summary.textContent = 'Du wurdest in die Garage „' + preview.garage_name + '“ eingeladen, Rolle: ' + preview.role + '.';
    } catch (error) {
        showDeadEnd(error.message);
        return;
    }

    let user = null;
    let restoreFailure = '';
    try {
        user = await restore();
    } catch (error) {
        // Ob eine Sitzung besteht, ist offen: beide Wege bleiben, die Meldung sagt, warum.
        restoreFailure = error.message;
    }
    statusLine.textContent = restoreFailure;
    invitation.hidden = false;
    if (user === null) {
        showWays();
    } else {
        showJoin(user);
    }
}

for (const choice of choices) {
    choice.button.addEventListener('click', () => choose(choice));
}

joinButton.addEventListener('click', join);

// Nach der Anmeldung tritt die Seite gleich bei; scheitert das vorübergehend, bleibt „Beitreten als …“ zum Wiederholen.
setupLogin(loginForm, loginError, async (user) => {
    showJoin(user);
    await join();
});

accountForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    hideError(accountError);
    accountButton.disabled = true;
    try {
        await post('invitations/accept', {
            token,
            name: accountForm.elements.name.value.trim(),
            email: accountForm.elements.email.value.trim(),
            password: accountForm.elements.password.value,
        });
    } catch (error) {
        accountButton.disabled = false;
        if (DEAD_LINK_STATUSES.includes(error.status)) {
            showDeadEnd(error.message);
        } else {
            showError(accountError, error.message);
        }
        return;
    }
    location.assign(ACCOUNT_PAGE);
});

start();
