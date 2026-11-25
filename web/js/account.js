// Einstieg für index.html: Sitzung wiederherstellen, dann Anmeldeformular oder Konto zeigen.
import { logout, request, restore } from './api.js';
import { setupCalendar } from './calendar.js';
import { clearChildren, element } from './dom.js';
import { dropdown } from './dropdown.js';
import { setupGrants } from './grant.js';
import { setupLogin } from './login.js';
import { mountShell } from './shell.js';
import { registerWorker } from './share.js';
import { setMode, storedMode } from './theme.js';
import { setupTokens } from './token.js';

registerWorker();
mountShell({ active: 'konto' });

dropdown(document.getElementById('theme-select'), {
    label: 'Darstellung',
    options: [
        { value: 'system', label: 'Wie das Gerät' },
        { value: 'dark', label: 'Dunkel' },
        { value: 'light', label: 'Hell' },
    ],
    value: storedMode(),
    onChange: setMode,
});

const statusLine = document.getElementById('status');
const loginSection = document.getElementById('login');
const loginForm = document.getElementById('login-form');
const loginError = document.getElementById('login-error');
const accountSection = document.getElementById('account');
const userName = document.getElementById('user-name');
const garageList = document.getElementById('garage-list');
const garageEmpty = document.getElementById('garage-empty');
const logoutButton = document.getElementById('logout');
const tokens = setupTokens();
const grants = setupGrants();
const calendar = setupCalendar();
const HOME = '/garage/garage.html';

function showLogin(message) {
    accountSection.hidden = true;
    loginSection.hidden = false;
    statusLine.textContent = message === undefined ? '' : message;
}

function renderGarages(garages) {
    clearChildren(garageList);
    garageEmpty.hidden = garages.length > 0;
    for (const garage of garages) {
        const item = element('li');
        item.appendChild(element('strong', garage.name));
        item.appendChild(element('small', 'Rolle: ' + garage.role));
        garageList.appendChild(item);
    }
}

async function showAccount() {
    const me = await request('GET', 'me');
    userName.textContent = me.user.name;
    renderGarages(me.garages);
    loginSection.hidden = true;
    accountSection.hidden = false;
    statusLine.textContent = '';
    await Promise.all([calendar.load(me.garages), tokens.load(), grants.load()]);
}

// Nach der Anmeldung beginnt die Arbeit in der Garage; das Konto erreicht man über die Navigation.
setupLogin(loginForm, loginError, () => {
    location.assign(HOME);
});

logoutButton.addEventListener('click', async () => {
    try {
        await logout();
    } catch (error) {
        showLogin('Die Abmeldung auf dem Server ist fehlgeschlagen: ' + error.message);
        tokens.clear();
        grants.clear();
        calendar.clear();
        return;
    }
    tokens.clear();
    grants.clear();
    calendar.clear();
    showLogin('Abgemeldet.');
});

async function start() {
    let user = null;
    try {
        user = await restore();
        if (user !== null) {
            await showAccount();
            return;
        }
    } catch (error) {
        showLogin(error.message);
        return;
    }
    showLogin();
}

start();
